<?php

require_once __DIR__ . '/vendor/autoload.php';

use Lib\Client\Client;
use Lib\Client\TransportFactory;
use Lib\Logger;
use PhpMcp\Client\ClientConfig;
use PhpMcp\Client\Enum\TransportType;
use PhpMcp\Client\Model\Capabilities;
use PhpMcp\Client\ServerConfig;
use React\EventLoop\StreamSelectLoop;

function debug($content): void
{
    $logFile = __DIR__ . '/logs/debug.log';
    is_dir(($dir = dirname($logFile))) || mkdir($dir, 0755, true);
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . print_r($content, true) . "\n", FILE_APPEND);
}

try {
    $clientCfg = new ClientConfig(
        name: 'MCP Client',
        version: '1.0',
        capabilities: Capabilities::forClient(),
        logger: new Logger('client.log'),
        cache: null,
        eventDispatcher: null,
        loop: $loop = new StreamSelectLoop()
    );

    $client = (new Client(
        new ServerConfig(
            name: 'local_stdio_server',
            transport: TransportType::Stdio,
            timeout: 10,
            command: 'php8',
            args: [__DIR__ . '/server.php']
        ),
        $clientCfg,
        new TransportFactory($clientCfg)
    ))->initialize();

    $tools = $client->listToolsAsync()->then(function ($tools) {
        echo "\n";
        if (empty($tools)) {
            echo "   No tools found on the server.\n";
        } else {
            echo "   Available Tools:\n";
            foreach ($tools as $tool) {
                echo "   - $tool->name" . ($tool->description ? " ($tool->description)" : '') . "\n";
            }
        }
    })->catch(function ($e) {
        echo $e->getMessage();
    });

    $resources = $client->listResourcesAsync()->then(function ($resources) {
        echo "\n";
        if (empty($resources)) {
            echo "   No resources found on the server.\n";
        } else {
            echo "   Available Resources:\n";
            foreach ($resources as $resource) {
                echo "   - $resource->uri" . ($resource->name ? " (Name: $resource->name)" : '') . "\n";
            }
        }
    })->catch(function ($e) {
        echo $e->getMessage();
    });

    $loop->run();
} catch (Throwable $e) {
    debug("{$e->getMessage()}\n{$e->getTraceAsString()}");
}