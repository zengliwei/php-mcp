<?php

require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Psr7\Response;
use Http\Discovery\Psr17Factory;
use Lib\Logger;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;

/**
 * @var Response $response
 * @see https://github.com/modelcontextprotocol/php-sdk
 */
$response = Server::builder()
    ->setServerInfo('MCP Server', '1.0.0')
    ->setLogger(new Logger('server.log'))
    ->setDiscovery(__DIR__, ['mcp-server'])
    ->setSession(new FileSessionStore(__DIR__ . '/var/sessions'))
    ->build()
    ->run(new StreamableHttpTransport((new Psr17Factory())->createServerRequestFromGlobals()));

foreach ($response->getHeaders() as $k => $v) {
    header("$k: {$v[0]}");
}
echo $response->getBody()->getContents();