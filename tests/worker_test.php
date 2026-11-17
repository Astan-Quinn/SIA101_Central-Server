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

$canonical = [
    'event_id' => 'f0b3d22c-5e10-4a27-8c03-3a57cd7819fd',
    'event_type' => 'lifecycle.asset.upserted',
    'source' => 'LifeCycleTrack',
    'destination' => 'UtilityTrack',
    'version' => '1.0',
    'correlation_id' => 'bb18f3c0-5df9-42d6-a881-2c6e92d2e599',
    'occurred_at' => '2026-11-11T10:12:13.123456+08:00',
    'source_id' => '42',
    'record_type' => 'lifecycle_asset',
    'payload' => [
        'id' => '42', 'entity_type' => 'equipment', 'asset_tag' => 'EQ-42',
        'name' => 'Generator', 'remarks' => 'private free-form text',
    ],
];
$clean = central_validate_integration_event($canonical, 'LifeCycleTrack');
check($clean['destination'] === null, 'sender destination is not trusted for routing');
check(!isset($clean['payload']['remarks']), 'free-form notes are removed');
check($clean['payload']['id'] === '42', 'source id remains stable in sanitized payload');
foreach ([
    array_merge($canonical, ['source' => 'MaintainTrack']),
    array_merge($canonical, ['record_type' => 'maintenance_request']),
    array_merge($canonical, ['event_id' => 'invalid']),
    array_merge($canonical, ['payload' => ['id' => 'other', 'entity_type' => 'equipment']]),
] as $invalid) {
    try {
        central_validate_integration_event($invalid, 'LifeCycleTrack');
        check(false, 'invalid canonical event rejected');
    } catch (InvalidArgumentException) {
        check(true, 'invalid canonical event rejected');
    }
}
echo "All Central Server canonical event validation checks passed.\n";

$utilityBill = [
    'event_id' => '7f6a6380-ae65-4ba1-a083-5f8947b360b0',
    'event_type' => 'utility.bill.upserted', 'source' => 'UtilityTrack', 'destination' => 'MaintainTrack',
    'version' => '1.0', 'correlation_id' => null, 'occurred_at' => '2026-11-13T10:00:00Z',
    'source_id' => 'bill-40', 'record_type' => 'utility_bill',
    'payload' => ['id' => 'bill-40', 'utility' => 'electricity', 'account_holder_name' => 'private'],
];
$utilityClean = central_validate_integration_event($utilityBill, 'UtilityTrack');
check(!isset($utilityClean['payload']['account_holder_name']), 'UtilityTrack private payload fields are removed');
$maintenance = [
    'event_id' => '2aa108cb-ad69-45ea-a227-5ba7834d2929',
    'event_type' => 'maintenance.work_order.upserted', 'source' => 'MaintainTrack', 'destination' => null,
    'version' => '1.0', 'correlation_id' => null, 'occurred_at' => '2026-11-13T10:00:00Z',
    'source_id' => 'request-7', 'record_type' => 'maintenance_request',
    'payload' => ['id' => 'request-7', 'status' => 'Completed', 'description' => 'private free-form text'],
];
$maintenanceClean = central_validate_integration_event($maintenance, 'MaintainTrack');
check(!isset($maintenanceClean['payload']['description']), 'maintenance free-form descriptions are removed');
try {
    central_validate_integration_event($utilityBill, 'MaintainTrack');
    check(false, 'cross-source token use is rejected');
} catch (InvalidArgumentException) {
    check(true, 'cross-source token use is rejected');
}
echo "All three-subsystem canonical contract checks passed.\n";
