<?php

declare(strict_types=1);

require dirname(__DIR__) . '/public/config/config.php';
require_once dirname(__DIR__) . '/monitoring/distributed.php';

$nodeKey = trim((string)($argv[1] ?? ''));
if ($nodeKey === '') {
    fwrite(STDERR, "Usage: php scripts/agent-key.php <node-key>\n");
    exit(1);
}

try {
    echo insight_dist_derive_node_secret($nodeKey) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
