<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/monitoring/distributed.php';

function insight_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function insight_test_observation(string $status, float $response = 0): array
{
    return [
        'status' => $status,
        'response_time_ms' => $response,
        'observed_at' => '2026-07-11 12:00:00',
    ];
}

$singleOnline = insight_dist_consensus_from_observations([insight_test_observation('online', 25)], 1);
insight_test_assert($singleOnline['status'] === 'online', 'One online node must produce an online consensus.');
insight_test_assert($singleOnline['confidence'] === 1.0, 'A one-node consensus must have 100% confidence.');

$singleOffline = insight_dist_consensus_from_observations([insight_test_observation('offline')], 1);
insight_test_assert($singleOffline['status'] === 'offline', 'One offline node must produce an offline consensus.');

$splitPair = insight_dist_consensus_from_observations([
    insight_test_observation('online', 20),
    insight_test_observation('offline'),
], 2);
insight_test_assert($splitPair['status'] === 'degraded', 'Disagreement between two nodes must remain visible.');

$healthyPair = insight_dist_consensus_from_observations([
    insight_test_observation('online', 20),
    insight_test_observation('online', 30),
], 2);
insight_test_assert($healthyPair['status'] === 'online', 'Two positive responses must produce an online consensus.');

$regionalDisagreement = insight_dist_consensus_from_observations([
    insight_test_observation('online', 10),
    insight_test_observation('online', 20),
    insight_test_observation('offline'),
], 3);
insight_test_assert($regionalDisagreement['status'] === 'degraded', 'A minority regional failure must not be hidden.');

$majorityFailure = insight_dist_consensus_from_observations([
    insight_test_observation('online', 10),
    insight_test_observation('offline'),
    insight_test_observation('offline'),
], 3);
insight_test_assert($majorityFailure['status'] === 'offline', 'Two failures out of three must confirm an outage.');

$insufficient = insight_dist_consensus_from_observations([
    insight_test_observation('online', 10),
], 3);
insight_test_assert($insufficient['status'] === 'unknown', 'One response out of three must not invent a state.');
insight_test_assert($insufficient['nodes_missing'] === 2, 'Missing responses must be counted.');

$latency = insight_dist_consensus_from_observations([
    insight_test_observation('online', 10),
    insight_test_observation('online', 20),
    insight_test_observation('online', 100),
], 3);
insight_test_assert($latency['response_median_ms'] === 20.0, 'The median must be calculated from healthy responses.');
insight_test_assert($latency['response_p95_ms'] === 100.0, 'The 95th percentile must preserve the high value.');

$nodes = [
    ['node_key' => 'paris-1'],
    ['node_key' => 'frankfurt-1'],
    ['node_key' => 'montreal-1'],
    ['node_key' => 'singapore-1'],
];
$firstAssignment = insight_dist_rendezvous_nodes(42, $nodes, 3);
$secondAssignment = insight_dist_rendezvous_nodes(42, array_reverse($nodes), 3);
insight_test_assert(count($firstAssignment) === 3, 'The replication factor must be respected.');
insight_test_assert(
    array_column($firstAssignment, 'node_key') === array_column($secondAssignment, 'node_key'),
    'Rendezvous assignment must remain stable regardless of node order.'
);

$master = str_repeat('a', 64);
$derived = insight_dist_derive_node_secret('paris-1', $master);
insight_test_assert(
    $derived === hash_hmac('sha256', 'insight-agent-v1:paris-1', $master),
    'Agent key derivation must remain deterministic.'
);
$payload = insight_dist_signature_payload('paris-1', '1234567890', '1234567890abcdef', '{"ok":true}');
insight_test_assert(
    $payload === "v1\nparis-1\n1234567890\n1234567890abcdef\n" . hash('sha256', '{"ok":true}'),
    'The signature format must remain stable between the hub and agent.'
);
$formattedTimestamp = insight_dist_format_unix_milliseconds(1710000000.123456);
insight_test_assert(
    str_ends_with($formattedTimestamp, '.123'),
    'The freshness window must accept a Unix timestamp with milliseconds.'
);

echo "Distributed consensus: 13 scenarios validated.\n";
