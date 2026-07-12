<?php

declare(strict_types=1);

$output = getenv('INSIGHT_TEST_WEBHOOK_OUTPUT');
$body = file_get_contents('php://input');
if (is_string($output) && $output !== '' && is_string($body)) {
    file_put_contents($output, $body, LOCK_EX);
}
http_response_code(204);
