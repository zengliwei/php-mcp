<?php

namespace McpClient;

use PhpMcp\Client\ClientConfig;
use PhpMcp\Client\Contracts\TransportInterface;
use PhpMcp\Client\Enum\TransportType;
use PhpMcp\Client\ServerConfig;
use PhpMcp\Client\Transport\Http\HttpClientTransport;
use Psr\Log\LoggerAwareInterface;

class TransportFactory extends \PhpMcp\Client\Factory\TransportFactory
{
    public function __construct(protected readonly ClientConfig $clientConfig)
    {
        parent::__construct($clientConfig);
    }

    public function create(ServerConfig $config): TransportInterface
    {
        $transport = match ($config->transport) {
            TransportType::Stdio => $this->createStdioTransport($config),
            TransportType::Http => $this->createHttpTransport($config)
        };
        if ($transport instanceof LoggerAwareInterface) {
            $transport->setLogger($this->clientConfig->logger);
        }
        return $transport;
    }

    private function createStdioTransport(ServerConfig $config): TransportInterface
    {
        return new StdioTransport($config, $this->clientConfig);
    }

    private function createHttpTransport(ServerConfig $config): TransportInterface
    {
        return new HttpClientTransport(
            $config->url,
            $this->clientConfig->loop,
            $config->headers,
            $config->sessionId
        );
    }
}