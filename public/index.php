<?php

define('ROOT', dirname(__DIR__));

require_once ROOT . '/vendor/autoload.php';

$server = filter_input_array(INPUT_SERVER);

if ($server['REQUEST_URI'] == '/server/sse') {
    require ROOT . '/server-http.php';
}