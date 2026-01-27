<?php

namespace Lib;

use Psr\Log\AbstractLogger;

class Logger extends AbstractLogger
{
    public function __construct(private readonly string $file)
    {
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $date = date('Y-m-d');
        $logFile = __DIR__ . '/../var/logs/' . substr($date, 0, 7) . "/$date/$this->file";
        is_dir(($dir = dirname($logFile))) || mkdir($dir, 0755, true);
        file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
    }
}