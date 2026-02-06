<?php

require_once __DIR__ . '/vendor/autoload.php';

use Lib\Logger;
use PhpMcp\Client\Client;
use PhpMcp\Client\Enum\TransportType;
use PhpMcp\Client\Model\Capabilities;
use PhpMcp\Client\ServerConfig;

$server = filter_input_array(INPUT_SERVER);
if (!preg_match('/^\/client\/(?P<path>[\da-z\-]+)$/', $server['REQUEST_URI'], $matches)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$srvCfg = new ServerConfig(
    name: 'MCP Server',
    transport: TransportType::Http,
    timeout: 30,
    url: 'http://127.0.0.1:3001/server/sse'
);

$client = Client::make()
    ->withClientInfo('MCP Client', '1.0.0')
    ->withCapabilities(Capabilities::forClient(false))
    ->withLogger(new Logger('client.log'))
    ->withServerConfig($srvCfg)
    ->build();

try {
    $client->initialize();
    $result = match ($matches['path']) {
        'list-tools' => $client->listTools()
    };
} catch (Throwable $e) {
    $result = ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
} finally {
    header('Content-Type: application/json');
    echo json_encode($result);
}