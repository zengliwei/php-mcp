<?php

require_once __DIR__ . '/vendor/autoload.php';

use Lib\Logger;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

/**
 * @see https://github.com/modelcontextprotocol/php-sdk
 */
$response = Server::builder()
    ->setServerInfo('MCP Server', '1.0.0')
    ->setLogger(new Logger('server.log'))
    ->setDiscovery(__DIR__, ['mcp-server'])
    ->build()
    ->run(new StdioTransport());