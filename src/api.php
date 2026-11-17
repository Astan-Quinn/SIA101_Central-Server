<?php
declare(strict_types=1);

function central_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false ? (string) ($_ENV[$name] ?? $default) : (string) $value;
}

function central_load_env(): void
{
    $path = dirname(__DIR__) . '/.env';
    if (!is_readable($path)) return;
    $values = parse_ini_file($path, false, INI_SCANNER_RAW);
    if (!is_array($values)) return;
    foreach ($values as $key => $value) {
        if (getenv((string) $key) === false) $_ENV[(string) $key] = (string) $value;
    }
}

function central_json(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function central_supabase_config(): array
{
    $url = rtrim(trim(central_env('SUPABASE_URL')), '/');
    $key = trim(central_env('SUPABASE_SECRET_KEY'));
    if ($url === '' || $key === '') throw new RuntimeException('Central Supabase is not configured');
    $parts = parse_url($url);
    $localHttp = strtolower(central_env('LOCAL_STAGING_ALLOW_HTTP', 'false')) === 'true'
        && is_array($parts)
        && strtolower((string) ($parts['scheme'] ?? '')) === 'http'
        && in_array(strtolower((string) ($parts['host'] ?? '')), ['127.0.0.1', 'localhost', '::1'], true);
    if (!filter_var($url, FILTER_VALIDATE_URL) || (!str_starts_with($url, 'https://') && !$localHttp)) {
        throw new RuntimeException('SUPABASE_URL must be an HTTPS URL');
    }
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required');
    return [$url, $key];
}

function central_supabase_request(string $method, array $query, ?array $body = null, string $resource = 'central_records'): array
{
    [$baseUrl, $key] = central_supabase_config();
    $url = $baseUrl . '/rest/v1/' . ltrim($resource, '/');
    if ($query !== []) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    // New sb_secret keys must be sent only as apikey; they are not JWTs.
    // The legacy service_role key is a JWT and can also populate Authorization.
    $headers = ['apikey: ' . $key, 'Accept: application/json', 'Content-Profile: public'];
    if (!str_starts_with($key, 'sb_secret_')) $headers[] = 'Authorization: Bearer ' . $key;
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $headers[] = str_starts_with($resource, 'rpc/')
            ? 'Prefer: return=representation'
            : 'Prefer: resolution=merge-duplicates,return=minimal';
    }

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => max(5, (int) central_env('SUPABASE_TIMEOUT', '12')),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) {
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            curl_close($handle);
            throw new RuntimeException('Could not encode Supabase request');
        }
        curl_setopt($handle, CURLOPT_POSTFIELDS, $encoded);
    }

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);
    if ($response === false) throw new RuntimeException('Supabase connection failed: ' . $curlError);
    if ($status < 200 || $status >= 300) {
        error_log('Central Supabase returned HTTP ' . $status . ': ' . substr((string) $response, 0, 500));
        throw new RuntimeException('Supabase write failed with HTTP ' . $status);
    }
    return [$status, $response];
}

function central_route_source(string $recordType): ?string
{
    return match ($recordType) {
        'utility_bill', 'utility_anomaly' => 'UtilityTrack',
        'lifecycle_asset' => 'LifeCycleTrack',
        'maintenance_request', 'maintenance_update' => 'MaintainTrack',
        default => null,
    };
}

function central_source_event_types(string $source, string $recordType): array
{
    return match ([$source, $recordType]) {
        ['LifeCycleTrack', 'lifecycle_asset'] => ['lifecycle.asset.upserted', 'lifecycle.asset.deleted'],
        ['UtilityTrack', 'utility_bill'] => ['utility.bill.upserted', 'utility.bill.deleted'],
        ['UtilityTrack', 'utility_anomaly'] => ['utility.anomaly.upserted', 'utility.anomaly.deleted'],
        ['MaintainTrack', 'maintenance_request'] => ['maintenance.work_order.upserted', 'maintenance.work_order.deleted', 'maintenance.result.recorded'],
        ['MaintainTrack', 'maintenance_update'] => ['maintenance.result.recorded'],
        default => [],
    };
}

function central_token_for(string $source): string
{
    return match ($source) {
        'UtilityTrack' => trim(central_env('UTILITYTRACK_API_TOKEN')),
        'LifeCycleTrack' => trim(central_env('LIFECYCLETRACK_API_TOKEN')),
        'MaintainTrack' => trim(central_env('MAINTAINTRACK_API_TOKEN')),
        default => '',
    };
}

function central_inbox_token_for(string $destination): string
{
    return match ($destination) {
        'UtilityTrack' => trim(central_env('UTILITYTRACK_INBOX_TOKEN')),
        'LifeCycleTrack' => trim(central_env('LIFECYCLETRACK_INBOX_TOKEN')),
        'MaintainTrack' => trim(central_env('MAINTAINTRACK_INBOX_TOKEN')),
        default => '',
    };
}

function central_authorize(string $source): void
{
    $expected = central_token_for($source);
    if ($expected === '') central_json(503, ['ok' => false, 'error' => 'source_auth_not_configured']);

    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($authorization === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                $authorization = (string) $value;
                break;
            }
        }
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', trim((string) $authorization), $matches)
        || !hash_equals($expected, trim($matches[1]))) {
        central_json(401, ['ok' => false, 'error' => 'unauthorized']);
    }
}

function central_allowed_fields(string $recordType): array
{
    return match ($recordType) {
        'utility_bill' => [
            'id', 'utility', 'created_at', 'updated_at', 'source_updated_at', 'billing_date', 'billing_period', 'billing_period_from',
            'billing_period_to', 'source', 'location', 'previous_reading',
            'current_reading', 'consumption', 'rate', 'subtotal', 'vat_amount', 'other_charges',
            'other_fees', 'charge_items', 'total_amount', 'due_date', 'status',
        ],
        'utility_anomaly' => [
            'id', 'created_at', 'updated_at', 'source_updated_at', 'utility', 'current_period', 'previous_period', 'cur_cons', 'prev_cons',
            'cur_cost', 'prev_cost', 'pct_cons', 'pct_cost', 'pct_rate', 'cause', 'status', 'result',
        ],
        'lifecycle_asset' => [
            'id', 'entity_type', 'asset_tag', 'name', 'brand', 'model', 'category', 'location', 'facility_id',
            'date_acquired', 'acquisition_year', 'is_estimated_year', 'condition', 'lifecycle_status',
            'quantity', 'basis', 'cost', 'schedule', 'hours_per_day', 'created_at', 'updated_at', 'source_updated_at',
        ],
        'maintenance_request' => [
            'id', 'display_id', 'facility_id', 'external_facility_id', 'external_facility_name', 'external_equipment_id', 'external_equipment_name', 'specific_area',
            'equipment_id', 'category', 'equipment_category', 'status',
            'approval_status', 'priority', 'date_reported', 'date_assigned', 'date_started', 'date_completed',
            'updated_at', 'source_updated_at', 'maintenance_type', 'completion_outcome',
        ],
        'maintenance_update' => [
            'id', 'request_id', 'status', 'title', 'created_at', 'updated_at', 'source_updated_at',
            'completion_outcome', 'labor_total', 'parts_total',
        ],
        default => [],
    };
}

function central_validate_integration_event(array $event, string $authenticatedSource): array
{
    $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    $eventId = (string) ($event['event_id'] ?? '');
    $source = (string) ($event['source'] ?? '');
    $recordType = (string) ($event['record_type'] ?? '');
    $eventType = (string) ($event['event_type'] ?? '');
    $version = (string) ($event['version'] ?? '');
    $sourceId = trim((string) ($event['source_id'] ?? ''));
    $occurredAt = (string) ($event['occurred_at'] ?? '');
    $correlation = $event['correlation_id'] ?? null;
    $payload = $event['payload'] ?? null;

    if (!preg_match($uuidPattern, $eventId)
        || ($correlation !== null && !preg_match($uuidPattern, (string) $correlation))
        || $source !== $authenticatedSource
        || !in_array($source, ['LifeCycleTrack', 'UtilityTrack', 'MaintainTrack'], true)
        || central_route_source($recordType) !== $source
        || !in_array($eventType, central_source_event_types($source, $recordType), true)
        || $version !== '1.0'
        || $sourceId === '' || strlen($sourceId) > 100
        || strtotime($occurredAt) === false
        || !is_array($payload) || array_is_list($payload)
        || (string) ($payload['id'] ?? '') !== $sourceId
        || ($recordType === 'lifecycle_asset' && !in_array($payload['entity_type'] ?? null, ['equipment', 'facility'], true))) {
        throw new InvalidArgumentException('Invalid event envelope');
    }

    $allowed = central_allowed_fields($recordType);
    $safePayload = array_intersect_key($payload, array_flip($allowed));
    $safePayload['id'] = $sourceId;
    if ($recordType === 'lifecycle_asset') $safePayload['entity_type'] = $payload['entity_type'];
    $clean = [
        'event_id' => strtolower($eventId),
        'event_type' => $eventType,
        'source' => $source,
        // The central router resolves destination exclusively from subscriptions.
        'destination' => null,
        'version' => $version,
        'correlation_id' => $correlation === null ? null : strtolower((string) $correlation),
        'occurred_at' => (new DateTimeImmutable($occurredAt))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        'source_id' => $sourceId,
        'record_type' => $recordType,
        'payload' => $safePayload,
    ];
    return $clean;
}

function central_authorize_inbox(string $destination): void
{
    $expected = central_inbox_token_for($destination);
    if ($expected === '') central_json(503, ['ok' => false, 'error' => 'inbox_auth_not_configured']);
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($authorization === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower((string) $name) === 'authorization') { $authorization = (string) $value; break; }
        }
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', trim((string) $authorization), $matches)
        || !hash_equals($expected, trim($matches[1]))) {
        central_json(401, ['ok' => false, 'error' => 'unauthorized']);
    }
}

function central_authorize_operator(): void
{
    $expected = trim(central_env('CENTRAL_OPERATOR_TOKEN'));
    if ($expected === '') central_json(503, ['ok' => false, 'error' => 'operator_auth_not_configured']);
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $provided = '';
    if (preg_match('/^Bearer\s+(.+)$/i', trim((string) $authorization), $matches)) {
        $provided = trim($matches[1]);
    } else {
        // Some Apache/PHP setups omit Authorization from PHP's request vars.
        // The same-origin operator page sends this dedicated header as a fallback.
        $provided = trim((string) ($_SERVER['HTTP_X_CENTRAL_OPERATOR_TOKEN'] ?? ''));
    }
    if ($provided === '' || !hash_equals($expected, $provided)) {
        central_json(401, ['ok' => false, 'error' => 'unauthorized']);
    }
}

function central_read_request_object(int $maxBytes): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > $maxBytes) central_json(413, ['ok' => false, 'error' => 'request_too_large']);
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) central_json(413, ['ok' => false, 'error' => 'request_too_large']);
    $value = json_decode($raw, true);
    if (!is_array($value) || array_is_list($value)) central_json(400, ['ok' => false, 'error' => 'json_object_required']);
    return $value;
}

function central_handle_integration_request(string $method, string $path): bool
{
    if ($method === 'POST' && $path === '/api/v1/events') {
        $input = central_read_request_object(max(1024, (int) central_env('MAX_REQUEST_BYTES', '1048576')));
        $source = (string) ($input['source'] ?? '');
        if (!in_array($source, ['LifeCycleTrack', 'UtilityTrack', 'MaintainTrack'], true)) central_json(422, ['ok' => false, 'error' => 'unsupported_event_source']);
        central_authorize($source);
        try {
            $event = central_validate_integration_event($input, $source);
        } catch (InvalidArgumentException) {
            central_json(422, ['ok' => false, 'error' => 'invalid_event']);
        }
        try {
            [, $body] = central_supabase_request('POST', [], ['p_event' => $event, 'p_authenticated_source' => $source], 'rpc/integration_accept_event');
            $result = json_decode($body, true);
            if (!is_array($result)) throw new RuntimeException('Integration RPC returned an invalid response');
            central_json(202, ['ok' => true] + $result);
        } catch (Throwable $error) {
            error_log('Central integration event intake failed: ' . $error->getMessage());
            central_json(503, ['ok' => false, 'error' => 'event_storage_unavailable']);
        }
    }

    if ($method === 'GET' && preg_match('#^/api/v1/sync/status/([0-9a-f-]{36})$#i', $path, $matches)) {
        $eventId = strtolower($matches[1]);
        $source = '';
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', trim((string) $authorization), $tokenMatch)) {
            foreach (['LifeCycleTrack', 'UtilityTrack', 'MaintainTrack'] as $candidate) {
                $expected = central_token_for($candidate);
                if ($expected !== '' && hash_equals($expected, trim($tokenMatch[1]))) { $source = $candidate; break; }
            }
        }
        if ($source === '') central_json(401, ['ok' => false, 'error' => 'unauthorized']);
        try {
            [, $eventBody] = central_supabase_request('GET', [
                'event_id' => 'eq.' . $eventId, 'source_system' => 'eq.' . $source,
                'select' => 'event_id,event_type,source_system,source_id,record_type,occurred_at,received_at', 'limit' => '1',
            ], null, 'integration_events');
            $events = json_decode($eventBody, true);
            if (!is_array($events) || $events === []) central_json(404, ['ok' => false, 'error' => 'event_not_found']);
            [, $deliveryBody] = central_supabase_request('GET', [
                'event_id' => 'eq.' . $eventId,
                'select' => 'destination_system,status,attempts,next_attempt_at,processed_at,dead_lettered_at,last_error,created_at,updated_at',
                'order' => 'destination_system.asc',
            ], null, 'integration_delivery_logs');
            central_json(200, ['ok' => true, 'event' => $events[0], 'deliveries' => json_decode($deliveryBody, true) ?: []]);
        } catch (Throwable $error) {
            error_log('Central sync status lookup failed: ' . $error->getMessage());
            central_json(503, ['ok' => false, 'error' => 'status_unavailable']);
        }
    }

    if ($method === 'GET' && $path === '/api/v1/admin/summary') {
        central_authorize_operator();
        try {
            [, $deliveryBody] = central_supabase_request('GET', [
                'select' => 'event_id,source_system,event_type,destination_system,status,attempts,next_attempt_at,dead_lettered_at,last_error,updated_at',
                'order' => 'updated_at.desc', 'limit' => (string) max(1, min(500, (int) ($_GET['limit'] ?? 100))),
            ], null, 'integration_delivery_logs');
            [, $deadBody] = central_supabase_request('GET', [
                'select' => 'failed_event_id,event_id,destination_system,attempts,failure_reason,failed_at,replayed_at',
                'replayed_at' => 'is.null', 'order' => 'failed_at.desc', 'limit' => '100',
            ], null, 'failed_events');
            [, $healthBody] = central_supabase_request('GET', [
                'select' => 'subsystem_code,health_status,last_seen_at,updated_at', 'order' => 'subsystem_code.asc',
            ], null, 'subsystem_registry');
            [, $reconciliationBody] = central_supabase_request('GET', [
                'select' => 'run_id,source_system,record_type,status,checkpoint,scanned_count,enqueued_count,error_summary,started_at,finished_at',
                'order' => 'started_at.desc', 'limit' => '50',
            ], null, 'sync_reconciliation_runs');
            central_json(200, [
                'ok' => true,
                'deliveries' => json_decode($deliveryBody, true) ?: [],
                'open_dead_letters' => json_decode($deadBody, true) ?: [],
                'subsystem_health' => json_decode($healthBody, true) ?: [],
                'reconciliation_runs' => json_decode($reconciliationBody, true) ?: [],
            ]);
        } catch (Throwable $error) {
            error_log('Central operator summary failed: ' . $error->getMessage());
            central_json(503, ['ok' => false, 'error' => 'operator_status_unavailable']);
        }
    }

    if ($method === 'POST' && preg_match('#^/api/v1/admin/dead-letters/(\d+)/replay$#', $path, $matches)) {
        central_authorize_operator();
        try {
            [, $body] = central_supabase_request('POST', [], [
                'p_failed_event_id' => (int) $matches[1],
                'p_actor' => 'central_operator',
            ], 'rpc/integration_replay_dead_letter');
            $replayed = json_decode($body, true) === true;
            central_json($replayed ? 202 : 404, ['ok' => $replayed, 'replayed' => $replayed]);
        } catch (Throwable $error) {
            error_log('Central dead-letter replay failed: ' . $error->getMessage());
            central_json(503, ['ok' => false, 'error' => 'replay_unavailable']);
        }
    }

    if (preg_match('#^/api/v1/inbox/(LifeCycleTrack|UtilityTrack|MaintainTrack)/(read|ack)$#', $path, $matches)) {
        $destination = $matches[1];
        $action = $matches[2];
        if ($method !== 'POST') { header('Allow: POST'); central_json(405, ['ok' => false, 'error' => 'method_not_allowed']); }
        central_authorize_inbox($destination);
        $input = central_read_request_object(8192);
        try {
            if ($action === 'read') {
                $count = max(1, min(50, (int) ($input['count'] ?? 10)));
                $visibility = max(10, min(3600, (int) ($input['visibility_seconds'] ?? central_env('QUEUE_VISIBILITY_SECONDS', '120'))));
                [, $body] = central_supabase_request('POST', [], [
                    'p_destination' => $destination,
                    'p_visibility_seconds' => $visibility,
                    'p_count' => $count,
                ], 'rpc/integration_read_inbox');
                $rows = json_decode($body, true);
                if (!is_array($rows)) throw new RuntimeException('Inbox read returned an invalid response');
                $maxReads = max(1, (int) central_env('QUEUE_MAX_READS', '8'));
                $messages = [];
                foreach ($rows as $row) {
                    if ((int) ($row['read_ct'] ?? 0) >= $maxReads) {
                        central_supabase_request('POST', [], [
                            'p_destination' => $destination,
                            'p_msg_id' => (int) ($row['msg_id'] ?? 0),
                            'p_message' => $row['message'] ?? new stdClass(),
                            'p_reason' => 'maximum delivery reads exceeded',
                        ], 'rpc/integration_deadletter_inbox');
                        continue;
                    }
                    $messages[] = [
                        'msg_id' => (int) ($row['msg_id'] ?? 0),
                        'read_ct' => (int) ($row['read_ct'] ?? 0),
                        'visibility_until' => $row['vt'] ?? null,
                        'event' => $row['message'] ?? null,
                    ];
                }
                central_json(200, ['ok' => true, 'messages' => $messages]);
            }
            $messageId = (int) ($input['msg_id'] ?? 0);
            if ($messageId < 1) central_json(422, ['ok' => false, 'error' => 'invalid_message_id']);
            [, $body] = central_supabase_request('POST', [], ['p_destination' => $destination, 'p_msg_id' => $messageId], 'rpc/integration_ack_inbox');
            central_json(200, ['ok' => true, 'acknowledged' => json_decode($body, true) === true]);
        } catch (Throwable $error) {
            error_log('Central inbox operation failed for ' . $destination . ': ' . $error->getMessage());
            central_json(503, ['ok' => false, 'error' => 'inbox_unavailable']);
        }
    }
    return false;
}

function central_validate_payload(string $recordType, array $input): array
{
    $payload = array_intersect_key($input, array_flip(central_allowed_fields($recordType)));
    $idValue = $payload['id'] ?? null;
    if (!is_string($idValue) && !is_int($idValue)) central_json(422, ['ok' => false, 'error' => 'id_must_be_string_or_integer']);
    $id = trim((string) $idValue);
    if ($id === '' || strlen($id) > 100) central_json(422, ['ok' => false, 'error' => 'id_required']);
    $payload['id'] = $id;

    if (in_array($recordType, ['utility_bill', 'utility_anomaly'], true)
        && !in_array($payload['utility'] ?? null, ['electricity', 'water'], true)) {
        central_json(422, ['ok' => false, 'error' => 'utility_must_be_electricity_or_water']);
    }
    if ($recordType === 'lifecycle_asset'
        && !in_array($payload['entity_type'] ?? null, ['equipment', 'facility'], true)) {
        central_json(422, ['ok' => false, 'error' => 'entity_type_must_be_equipment_or_facility']);
    }
    return [$id, $payload];
}

function central_queue_event(string $source, string $recordType, string $sourceId, string $operation, ?array $payload, ?string $sourceVersion = null): string
{
    $utc = new DateTimeZone('UTC');
    $receivedAt = (new DateTimeImmutable('now', $utc))->format('Y-m-d\TH:i:s.u\Z');
    $sourceUpdatedAt = trim((string) ($sourceVersion ?? $payload['source_updated_at'] ?? $payload['updated_at'] ?? ''));
    if ($sourceUpdatedAt === '' || strtotime($sourceUpdatedAt) === false) $sourceUpdatedAt = $receivedAt;
    else $sourceUpdatedAt = (new DateTimeImmutable($sourceUpdatedAt))->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z');
    $message = [
        'operation' => $operation,
        'source_system' => $source,
        'record_type' => $recordType,
        'source_id' => $sourceId,
        'source_updated_at' => $sourceUpdatedAt,
        'received_at' => $receivedAt,
        'payload' => $payload,
    ];
    central_supabase_request('POST', [], ['p_message' => $message], 'rpc/central_queue_send');
    return $receivedAt;
}

function handle_api_request(): never
{
    central_load_env();
    header('X-Content-Type-Options: nosniff');

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $path = '/' . trim(preg_replace('#/+#', '/', $path), '/');
    $scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $scriptDirectory = rtrim($scriptDirectory, '/.');
    if ($scriptDirectory !== '' && $scriptDirectory !== '/' && str_starts_with($path, $scriptDirectory . '/')) {
        $path = substr($path, strlen($scriptDirectory));
    }

    if ($method === 'GET' && preg_match('#/api/v1/health$#', $path)) {
        try {
            central_supabase_config();
            central_supabase_request('GET', ['select'=>'subsystem_code','limit'=>'1'], null, 'subsystem_registry');
            central_supabase_request('PATCH', ['subsystem_code'=>'eq.CentralServer'], [
                'health_status'=>'healthy', 'last_seen_at'=>gmdate('c'), 'updated_at'=>gmdate('c'),
            ], 'subsystem_registry');
            central_json(200, ['ok' => true, 'service' => 'SIA101 Central Server', 'api_version' => 'v1', 'queue' => 'central_sync_events']);
        } catch (Throwable $error) {
            error_log('Central API configuration error: ' . $error->getMessage());
            central_json(503, ['ok' => false, 'error' => 'service_not_configured']);
        }
    }

    central_handle_integration_request($method, $path);

    if (!preg_match('#/api/v1/ingest/([a-z_]+)(?:/([^/]+))?$#', $path, $matches)) {
        central_json(404, ['ok' => false, 'error' => 'route_not_found']);
    }
    $recordType = $matches[1];
    $source = central_route_source($recordType);
    if ($source === null) central_json(404, ['ok' => false, 'error' => 'record_type_not_found']);
    central_authorize($source);

    if ($method === 'DELETE' && isset($matches[2])) {
        $sourceId = rawurldecode($matches[2]);
        if ($sourceId === '' || strlen($sourceId) > 100) central_json(422, ['ok' => false, 'error' => 'invalid_id']);
        try {
            $queuedAt = central_queue_event($source, $recordType, $sourceId, 'delete', null, (string) ($_GET['source_updated_at'] ?? ''));
            central_json(202, ['ok' => true, 'queued' => true, 'record_type' => $recordType, 'source_id' => $sourceId, 'queued_at' => $queuedAt]);
        } catch (Throwable $error) {
            error_log('Central API delete failed: ' . $error->getMessage());
            central_json(503, ['ok' => false, 'error' => 'storage_unavailable']);
        }
    }

    if ($method !== 'POST' || isset($matches[2])) {
        header('Allow: POST, DELETE');
        central_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }

    $limit = max(1024, (int) central_env('MAX_REQUEST_BYTES', '1048576'));
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > $limit) central_json(413, ['ok' => false, 'error' => 'request_too_large']);
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if ($raw === false || strlen($raw) > $limit) central_json(413, ['ok' => false, 'error' => 'request_too_large']);
    $trimmed = ltrim($raw);
    if ($trimmed === '' || $trimmed[0] !== '{') central_json(400, ['ok' => false, 'error' => 'json_object_required']);
    $input = json_decode($raw, true);
    if (!is_array($input) || array_is_list($input)) central_json(400, ['ok' => false, 'error' => 'json_object_required']);

    [$sourceId, $payload] = central_validate_payload($recordType, $input);
    try {
        $queuedAt = central_queue_event($source, $recordType, $sourceId, 'upsert', $payload);
        central_json(202, ['ok' => true, 'queued' => true, 'record_type' => $recordType, 'source_id' => $sourceId, 'queued_at' => $queuedAt]);
    } catch (Throwable $error) {
        error_log('Central API upsert failed: ' . $error->getMessage());
        central_json(503, ['ok' => false, 'error' => 'storage_unavailable']);
    }
}
