#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

/**
 * @see https://github.com/modelcontextprotocol/php-sdk
 */
Server::builder()
    ->setServerInfo('MCP Server', '1.0.0')
    ->setDiscovery(__DIR__, ['mcp-server'])
    ->build()
    ->run(new StdioTransport());