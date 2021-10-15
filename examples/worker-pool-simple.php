#!/usr/bin/env php
<?php

require \dirname(__DIR__) . '/vendor/autoload.php';

use Amp\Future;
use Amp\Parallel\Worker;

$urls = [
    'https://secure.php.net',
    'https://amphp.org',
    'https://github.com',
];

$futures = [];
foreach ($urls as $url) {
    $futures[$url] = Future\coroutine(fn () => Worker\enqueueCallable('file_get_contents', $url));
}

$responses = Future\all($futures);

foreach ($responses as $url => $response) {
    \printf("Read %d bytes from %s\n", \strlen($response), $url);
}
