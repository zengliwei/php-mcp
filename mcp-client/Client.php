<?php

namespace McpClient;

use PhpMcp\Client\Enum\ConnectionStatus;
use PhpMcp\Client\Event;
use PhpMcp\Client\Exception\ConfigurationException;
use PhpMcp\Client\Exception\ConnectionException;
use PhpMcp\Client\Exception\McpClientException;
use PhpMcp\Client\Exception\RequestException;
use PhpMcp\Client\JsonRpc\Message;
use PhpMcp\Client\JsonRpc\Notification;
use PhpMcp\Client\JsonRpc\Params\InitializeParams;
use PhpMcp\Client\JsonRpc\Request;
use PhpMcp\Client\JsonRpc\Response;
use PhpMcp\Client\JsonRpc\Results\InitializeResult;
use PhpMcp\Client\Transport\Stdio\StdioClientTransport;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;

class Client extends \PhpMcp\Client\Client
{
    protected string $preferredProtocolVersion = '2025-06-18';

    private function handleTransportMessage(Message $message): void
    {
        if ($message instanceof Response) {
            $this->handleResponseMessage($message);
        } elseif ($message instanceof Notification) {
            $this->handleNotificationMessage($message);
        } else {
            $this->logger->warning('Received unknown message type', ['type' => get_class($message)]);
        }
    }

    private function handleResponseMessage(Response $response): void
    {
        $id = $response->id;
        if ($id === null) {
            $this->logger->warning(
                'Received Response message with null ID, ignoring.',
                ['response' => $response->toArray()]
            );
            return;
        }

        if (!isset($this->pendingRequests[$id])) {
            // This is common if a request timed out before the response arrived
            $this->logger->debug('Received response for unknown or timed out request ID', ['id' => $id]);
            return;
        }

        $deferred = $this->pendingRequests[$id];
        unset($this->pendingRequests[$id]);

        // Resolve/reject the deferred directly
        if ($response->isError()) {
            $this->logger->warning(
                "Received error response for request ID {$id}",
                ['error' => $response->error->toArray()]
            );
            $exception = new RequestException(
                $response->error->message,
                $response->error,
                $response->error->code
            );
            $deferred->reject($exception);
        } else {
            $this->logger->debug("Received successful response for request ID {$id}");
            $deferred->resolve($response);
        }
    }

    private function handleNotificationMessage(Notification $notification): void
    {
        if (!$this->clientConfig->eventDispatcher) {
            $this->logger->debug(
                'Received notification but no event dispatcher configured.',
                ['method' => $notification->method]
            );
            return;
        }

        $event = match ($notification->method) {
            'notifications/tools/listChanged' => new Event\ToolsListChanged($this->getServerName()),
            'notifications/resources/listChanged' => new Event\ResourcesListChanged($this->getServerName()),
            'notifications/prompts/listChanged' => new Event\PromptsListChanged($this->getServerName()),
            'notifications/resources/didChange' => isset($notification->params['uri'])
            && is_string($notification->params['uri'])
                ? new Event\ResourceChanged($this->getServerName(), $notification->params['uri'])
                : null,
            'notifications/logging/log' => is_array($notification->params)
                ? new Event\LogReceived($this->getServerName(), $notification->params)
                : null,
            'sampling/createMessage' => is_array($notification->params)
                ? new Event\SamplingRequestReceived($this->getServerName(), $notification->params)
                : null,
            default => null
        };

        if ($event) {
            $this->logger->debug(
                'Dispatching event', ['event' => get_class($event), 'server' => $this->getServerName()]
            );

            try {
                $this->clientConfig->eventDispatcher->dispatch($event);
            } catch (Throwable $e) {
                $this->logger->error(
                    'Error during application event dispatch',
                    ['exception' => $e, 'event' => get_class($event)]
                );
            }
        } else {
            // Log only if the method wasn't matched AND params were invalid for matched cases
            if ($notification->method === 'notifications/resources/didChange' && !isset($notification->params['uri'])) {
                $this->logger->warning(
                    "Received 'resource/didChange' notification with missing/invalid 'uri' param.",
                    ['params' => $notification->params]
                );
            } elseif ($notification->method === 'notifications/logging/log' && !is_array($notification->params)) {
                $this->logger->warning(
                    "Received 'logging/log' notification with invalid 'params'.",
                    ['params' => $notification->params]
                );
            } elseif ($notification->method === 'sampling/createMessage' && !is_array($notification->params)) {
                $this->logger->warning(
                    "Received 'sampling/createMessage' notification with invalid 'params'.",
                    ['params' => $notification->params]
                );
            } elseif (!in_array(
                $notification->method,
                [
                    'notifications/tools/listChanged', 'notifications/resources/listChanged',
                    'notifications/prompts/listChanged'
                ]
            )) {
                // Avoid logging warnings for valid but unmapped notifications if no dispatcher exists
                $this->logger->warning(
                    'Received unhandled MCP notification method',
                    ['method' => $notification->method, 'server' => $this->getServerName()]
                );
            }
        }
    }

    private function handleTransportError(Throwable $error): void
    {
        if ($this->status === ConnectionStatus::Closing
            || $this->status === ConnectionStatus::Closed
            || $this->status === ConnectionStatus::Error
        ) {
            $this->logger->debug(
                'Ignoring transport error in terminal state.',
                ['status' => $this->status->value, 'error' => $error->getMessage()]
            );
            return;
        }

        $this->logger->error(
            "Transport error for '{$this->getServerName()}': {$error->getMessage()}",
            ['exception' => $error]
        );

        $exceptionToPropagate = $error instanceof McpClientException ? $error : new ConnectionException(
            "Transport layer error: {$error->getMessage()}", 0, $error
        );

        $this->handleConnectionFailure($exceptionToPropagate);
    }

    private function handleTransportClose(mixed $reason = null): void
    {
        if ($this->status === ConnectionStatus::Closing || $this->status === ConnectionStatus::Closed) {
            $this->logger->debug('Ignoring transport close in terminal state.', ['status' => $this->status->value]);
            return;
        }

        $message = "Transport closed unexpectedly for '{$this->getServerName()}'."
            . (is_string($reason) && $reason !== '' ? ' Reason: ' . $reason : '');

        $this->logger->warning($message);

        $this->handleConnectionFailure(new ConnectionException($message));
    }

    private function performHandshake(): PromiseInterface
    {
        $initParams = new InitializeParams(
            clientName: $this->clientConfig->name,
            clientVersion: $this->clientConfig->version,
            protocolVersion: $this->preferredProtocolVersion,
            capabilities: $this->clientConfig->capabilities,
        );

        $request = new Request(
            id: $this->clientConfig->idGenerator->generate(),
            method: 'initialize',
            params: $initParams->toArray()
        );

        return $this->sendAsyncInternal($request)->then(
            function (Response $response) {
                if ($response->isError()) {
                    throw RequestException::fromError('Initialize failed', $response->error);
                }

                if (!is_array($response->result)) {
                    throw new ConnectionException('Invalid initialize result format.');
                }

                $initResult = InitializeResult::fromArray($response->result);

                // Version Negotiation
                $serverVersion = $initResult->protocolVersion;
                if ($serverVersion !== $this->preferredProtocolVersion) {
                    $this->logger->warning(
                        "Version mismatch: Server returned {$serverVersion}, expected {$this->preferredProtocolVersion}."
                    );

                    if (!is_string($serverVersion) || empty($serverVersion)) {
                        throw new ConnectionException('Server returned invalid protocol version.');
                    }
                }
                $this->negotiatedProtocolVersion = $serverVersion;
                $this->serverName = $initResult->serverName;
                $this->serverVersion = $initResult->serverVersion;
                $this->serverCapabilities = $initResult->capabilities;

                $this->logger->debug("Sending 'initialized' notification to '{$this->getServerName()}'.");

                return $this->transport->send(new Notification('notifications/initialized'));
            }
        );
    }

    public function initializeAsync(): PromiseInterface
    {
        if ($this->connectPromise !== null) {
            return $this->connectPromise;
        }

        if ($this->status !== ConnectionStatus::Disconnected
            && $this->status !== ConnectionStatus::Closed
            && $this->status !== ConnectionStatus::Error
        ) {
            if ($this->status === ConnectionStatus::Ready) {
                return resolve($this);
            }
            return reject(
                new ConnectionException("Cannot initialize, client is in unexpected status: {$this->status->value}")
            );
        }

        $this->logger->info(
            "Initializing connection to server '{$this->getServerName()}'...",
            ['transport' => $this->serverConfig->transport->value]
        );

        $this->connectRequestDeferred = new Deferred(function ($_, $reject) {
            $this->logger->info("Initialization attempt for '{$this->getServerName()}' cancelled.");
            $this->handleConnectionFailure(new ConnectionException('Initialization attempt cancelled.'), false);
            if (isset($this->transport) && ($this->status === ConnectionStatus::Connecting || $this->status === ConnectionStatus::Handshaking)) {
                $this->transport->close();
            }
        });

        $this->status = ConnectionStatus::Connecting;

        try {
            $this->transport = $this->transportFactory->create($this->serverConfig);
        } catch (Throwable $e) {
            $this->handleConnectionFailure(
                new ConfigurationException("Failed to create transport: {$e->getMessage()}", 0, $e)
            );

            return reject($e);
        }

        $this->transport->on('message', $this->handleTransportMessage(...));
        $this->transport->on('error', $this->handleTransportError(...));
        $this->transport->on('close', $this->handleTransportClose(...));
        if ($this->transport instanceof StdioClientTransport) {
            $this->transport->on('stderr', function (string $data) {
                $this->logger->warning("Server '{$this->getServerName()}' STDERR: " . trim($data));
            });
        }

        // --- Define the connection and handshake sequence ---
        $this->transport->connect()->then(
            function () {
                if ($this->status !== ConnectionStatus::Connecting) {
                    throw new ConnectionException(
                        "Internal state error: Status was {$this->status->value} after transport connect resolved."
                    );
                }

                $this->logger->info("Transport connected for '{$this->getServerName()}', initiating handshake...");
                $this->status = ConnectionStatus::Handshaking;

                return $this->performHandshake();
            }
        )->then(
            function () {
                // Check status again in case of rapid failure during handshake
                if ($this->status !== ConnectionStatus::Handshaking) {
                    throw new ConnectionException(
                        "Connection status changed unexpectedly ({$this->status->value}) during handshake."
                    );
                }

                $this->status = ConnectionStatus::Ready;
                $this->logger->info("Server '{$this->getServerName()}' connection ready.", [
                    'protocol' => $this->negotiatedProtocolVersion,
                    'server'   => $this->serverName,
                    'version'  => $this->serverVersion,
                ]);

                return $this;
            }
        )->catch(
            function (Throwable $error) {
                $this->logger->error(
                    "Connection/Handshake failed for '{$this->getServerName()}': {$error->getMessage()}",
                    ['exception' => $error]
                );
                $this->handleConnectionFailure($error);
            }
        )->then(
            fn ($connection) => $this->connectRequestDeferred?->resolve($connection),
            fn (Throwable $error) => $this->connectRequestDeferred?->reject($error)
        )->finally(function () {
            $this->connectPromise = $this->connectRequestDeferred->promise();
            $this->connectRequestDeferred = null;
        });

        return $this->connectPromise;
    }
}