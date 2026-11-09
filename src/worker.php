<?php
declare(strict_types=1);

require_once __DIR__ . '/api.php';

function central_worker_rpc(string $name, array $arguments): array
{
    return central_supabase_request('POST', [], $arguments, 'rpc/' . $name);
}

function central_worker_validate_event(array $event): void
{
    $operation = (string) ($event['operation'] ?? '');
    $source = (string) ($event['source_system'] ?? '');
    $recordType = (string) ($event['record_type'] ?? '');
    $sourceId = (string) ($event['source_id'] ?? '');
    $sourceUpdatedAt = (string) ($event['source_updated_at'] ?? '');
    if (!in_array($operation, ['upsert', 'delete'], true) || central_route_source($recordType) !== $source
        || $sourceId === '' || strlen($sourceId) > 100 || $sourceUpdatedAt === '' || strtotime($sourceUpdatedAt) === false
        || ($operation === 'upsert' && !is_array($event['payload'] ?? null))) {
        throw new RuntimeException('Malformed queue event');
    }
}

function central_worker_apply(array $event): void
{
    central_worker_validate_event($event);
    central_worker_rpc('central_apply_event', ['p_event' => $event]);
}

function central_worker_run(int $limit = 10): int
{
    central_load_env();
    $visibility = max(30, (int) central_env('QUEUE_VISIBILITY_SECONDS', '120'));
    $limit = max(1, min(50, $limit));
    [, $body] = central_worker_rpc('central_queue_read', [
        'p_visibility_seconds' => $visibility,
        'p_count' => $limit,
    ]);
    $messages = json_decode($body, true);
    if (!is_array($messages)) throw new RuntimeException('Queue read returned an invalid response');
    $handled = 0;
    foreach ($messages as $message) {
        $id = (int) ($message['msg_id'] ?? 0);
        $event = $message['message'] ?? null;
        if ($id < 1 || !is_array($event)) continue;
        try {
            central_worker_apply($event);
            central_worker_rpc('central_queue_archive', ['p_msg_id' => $id]);
        } catch (Throwable $error) {
            error_log('Central queue message ' . $id . ' failed (read ' . (int) ($message['read_ct'] ?? 0) . '): ' . $error->getMessage());
            if ((int) ($message['read_ct'] ?? 0) >= max(1, (int) central_env('QUEUE_MAX_READS', '8'))) {
                $event['_queue_failure'] = [
                    'failed_at' => gmdate('c'),
                    'read_count' => (int) ($message['read_ct'] ?? 0),
                    'reason' => substr($error->getMessage(), 0, 300),
                ];
                central_worker_rpc('central_queue_dead_letter', ['p_message' => $event]);
                central_worker_rpc('central_queue_archive', ['p_msg_id' => $id]);
            }
            continue; // visibility timeout makes the message available for retry
        }
        $handled++;
    }
    return $handled;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $limit = isset($argv[1]) ? (int) $argv[1] : 10;
        $processed = central_worker_run($limit);
        fwrite(STDOUT, 'Processed ' . $processed . " central sync event(s).\n");
        exit(0);
    } catch (Throwable $error) {
        error_log('Central queue worker failed: ' . $error->getMessage());
        fwrite(STDERR, "Central queue worker failed. Check the PHP error log.\n");
        exit(1);
    }
}
