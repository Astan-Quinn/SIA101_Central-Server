<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/worker.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
}

$base = [
    'operation' => 'upsert',
    'source_system' => 'UtilityTrack',
    'record_type' => 'utility_bill',
    'source_id' => 'bill-001',
    'source_updated_at' => '2026-11-08T10:00:00.123456Z',
    'payload' => ['id' => 'bill-001', 'utility' => 'electricity'],
];
central_worker_validate_event($base);
check(true, 'valid utility event accepted');

$delete = $base;
$delete['operation'] = 'delete';
$delete['payload'] = null;
central_worker_validate_event($delete);
check(true, 'delete event accepted without a payload');

foreach ([
    array_merge($base, ['source_system' => 'LifeCycleTrack']),
    array_merge($base, ['operation' => 'replace']),
    array_merge($base, ['source_id' => '']),
    array_merge($base, ['source_updated_at' => 'not-a-date']),
    array_merge($base, ['payload' => null]),
] as $invalid) {
    try {
        central_worker_validate_event($invalid);
        check(false, 'malformed event rejected');
    } catch (RuntimeException $error) {
        check($error->getMessage() === 'Malformed queue event', 'malformed event has expected rejection');
    }
}

echo "All Central Server queue event checks passed.\n";
