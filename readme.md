# PHP MCP

PHP MCP server + client through STDIO for Windows.

## Features

- Runnable on Windows
- One click for both server and client

## Requirements

- PHP >= 8.1
- Composer
- Ability to execute the server command.

## Installation

```bash
composer install
```

## Configuration

- Edit the `client.php` file and set the `commend` variable of `ServerConfig` as your path.
- Define your own MCP elements through appending class files under `mcp-server` directory (
  see [Define Your MCP Elements](https://github.com/modelcontextprotocol/php-sdk?tab=readme-ov-file#1-define-your-mcp-elements)).

## Usage

Double click `run.cmd` to start the server.

## Libraries

- MCP PHP SDK (https://github.com/modelcontextprotocol/php-sdk)
- PHP MCP Client (https://github.com/php-mcp/client)
