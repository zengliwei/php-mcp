<?php

namespace McpServer;

use Mcp\Capability\Attribute\McpTool;

class Pdo
{
    /**
     * Fetch data from database with specified DSN, user, password and SQL
     */
    #[McpTool(name: 'pdo_fetch')]
    public function fetch(string $dsn, string $user, string $pass, string $sql): false|array
    {
        return (new \PDO($dsn, $user, $pass))->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }
}