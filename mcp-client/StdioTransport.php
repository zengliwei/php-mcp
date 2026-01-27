<?php

namespace McpClient;

use Evenement\EventEmitterTrait;
use Exception;
use PhpMcp\Client\ClientConfig;
use PhpMcp\Client\Contracts\TransportInterface;
use PhpMcp\Client\Exception\TransportException;
use PhpMcp\Client\JsonRpc\Message;
use PhpMcp\Client\JsonRpc\Notification;
use PhpMcp\Client\JsonRpc\Request;
use PhpMcp\Client\JsonRpc\Response;
use PhpMcp\Client\ServerConfig;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

class StdioTransport implements TransportInterface, LoggerAwareInterface
{
    use EventEmitterTrait;

    private ?PromiseInterface $connectPromise;
    private LoggerInterface $logger;
    private LoopInterface $loop;

    private string $buffer;

    private bool $connected = false;

    /**
     * @var false|resource
     */
    private $process;

    /**
     * @var false|resource
     */
    private $stdin;

    /**
     * @var false|resource
     */
    private $stdout;

    public function __construct(
        private readonly ServerConfig $serverConfig,
        private readonly ClientConfig $clientConfig
    ) {
        $this->logger = $clientConfig->logger;
        $this->loop = $clientConfig->loop;
    }

    private function parseMessageData(array $data): ?Message
    {
        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            return null;
        }
        if (isset($data['method'])) {
            if (isset($data['id'])) {
                return Request::fromArray($data);
            } else {
                return Notification::fromArray($data);
            }
        } elseif (isset($data['id'])) {
            return Response::fromArray($data);
        }
        return null;
    }

    private function handleMessage(string $content): void
    {
        if (($data = json_decode($content, true))
            && ($message = $this->parseMessageData($data))
        ) {
            $this->emit('message', [$message]);
        } else {
            $this->emit('error', [new TransportException("Unrecognized message structure: $content")]);
        }
    }

    public function connect(): PromiseInterface
    {
        if (isset($this->connectPromise)) {
            return $this->connectPromise;
        }
        $deferred = new Deferred(function ($_, $reject) {
            $this->close();
            $reject(new TransportException('Connection attempt cancelled.'));
        });
        try {
            $cmd = [];
            $cmd[] = escapeshellarg($this->serverConfig->command);
            foreach ($this->serverConfig->args as $arg) {
                $cmd[] = escapeshellarg($arg);
            }
            $cmd = implode(' ', $cmd);
            $this->process = proc_open( // 必须将返回的资源作为类的属性驻留内存，否则执行 send 方法时会因丢失资源导致写入失败
                $cmd,
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
                $this->serverConfig->workingDir,
                $this->serverConfig->env
            );
            [$this->stdin, $this->stdout] = $pipes;
            $this->buffer = '';
            $this->loop->addReadStream($this->stdout, function () {
                while (!feof($this->stdout)) {
                    $chunk = fread($this->stdout, 1024);
                    while (false !== ($pos = strpos($chunk, "\n"))) {
                        $this->buffer .= substr($chunk, 0, $pos);
                        $this->handleMessage($this->buffer);
                        $this->buffer = '';
                        $chunk = substr($chunk, $pos + 1);
                    }
                    $this->buffer .= $chunk;
                }
            });
            $deferred->resolve(null);
        } catch (\Throwable $e) {
            $deferred->reject($e);
        }
        return $this->connectPromise = $deferred->promise();
    }

    /**
     * @throws Exception
     */
    public function send(Message $message): PromiseInterface
    {
        $deferred = new Deferred();
        if ($this->process && $this->stdin && fwrite($this->stdin, json_encode($message->toArray()) . "\n")) {
            $deferred->resolve(null);
            if (!$this->connected) {
                $loop = new StreamSelectLoop();
                $loop->addReadStream($this->stdout, function () use ($loop) {
                    $chunk = fread($this->stdout, 1024);
                    while (false !== ($pos = strpos($chunk, "\n"))) {
                        $this->buffer .= substr($chunk, 0, $pos);
                        $this->handleMessage($this->buffer);
                        $this->connected = true;
                        $loop->stop();
                        $this->buffer = '';
                        $chunk = substr($chunk, $pos + 1);
                    }
                    $this->buffer .= $chunk;
                });
                $loop->run();
            }
        } else {
            $deferred->reject(new TransportException('Failed to write to stdin'));
        }
        return $deferred->promise();
    }

    public function close(): void
    {
        if (!$this->process) {
            return;
        }
        fclose($this->stdin);
        fclose($this->stdout);
        proc_close($this->process);
        $this->buffer = '';
        $this->connected = false;
        $this->stdin = null;
        $this->stdout = null;
        $this->process = null;
        $this->connectPromise = null;
        $this->emit('close');
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}