<?php

define('ROOT', dirname(__DIR__));

require_once ROOT . '/vendor/autoload.php';

use Http\Discovery\Psr17Factory;
use Lib\Logger;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;

header('Content-Type: application/json');

/**
 * @see https://github.com/modelcontextprotocol/php-sdk
 */
echo Server::builder()
    ->setServerInfo('MCP Server', '1.0.0')
    ->setLogger(new Logger('server.log'))
    ->setDiscovery(ROOT, ['mcp-server'])
    ->build()
    ->run(new StreamableHttpTransport((new Psr17Factory())->createServerRequestFromGlobals()))
    ->getBody()
    ->getContents();