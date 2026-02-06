<?php

define('ROOT', dirname(__DIR__));

$server = filter_input_array(INPUT_SERVER);

if ($server['REQUEST_URI'] == '/server/sse') {
    require ROOT . '/server-http.php';
} elseif (str_starts_with($server['REQUEST_URI'], '/client/')) {
    require ROOT . '/client-http.php';
}