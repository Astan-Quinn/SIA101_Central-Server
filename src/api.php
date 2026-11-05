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
    if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
        throw new RuntimeException('SUPABASE_URL must be an HTTPS URL');
    }
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required');
    return [$url, $key];
}

function central_supabase_request(string $method, array $query, ?array $body = null): array
{
    [$baseUrl, $key] = central_supabase_config();
    $url = $baseUrl . '/rest/v1/central_records';
    if ($query !== []) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $headers = ['apikey: ' . $key, 'Authorization: Bearer ' . $key, 'Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Prefer: resolution=merge-duplicates,return=minimal';
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
        default => null,
    };
}

function central_token_for(string $source): string
{
    return match ($source) {
        'UtilityTrack' => trim(central_env('UTILITYTRACK_API_TOKEN')),
        'LifeCycleTrack' => trim(central_env('LIFECYCLETRACK_API_TOKEN')),
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
            'id', 'utility', 'created_at', 'billing_date', 'billing_period', 'billing_period_from',
            'billing_period_to', 'account_number', 'source', 'location', 'previous_reading',
            'current_reading', 'consumption', 'rate', 'subtotal', 'vat_amount', 'other_charges',
            'other_fees', 'charge_items', 'total_amount', 'due_date', 'status',
        ],
        'utility_anomaly' => [
            'id', 'created_at', 'utility', 'current_period', 'previous_period', 'cur_cons', 'prev_cons',
            'cur_cost', 'prev_cost', 'pct_cons', 'pct_cost', 'pct_rate', 'cause', 'status', 'result',
        ],
        'lifecycle_asset' => [
            'id', 'entity_type', 'asset_tag', 'name', 'brand', 'model', 'category', 'location',
            'date_acquired', 'acquisition_year', 'is_estimated_year', 'condition', 'lifecycle_status',
            'quantity', 'basis', 'cost', 'schedule', 'hours_per_day', 'remarks', 'created_at', 'updated_at',
        ],
        default => [],
    };
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

function central_upsert_record(string $source, string $recordType, string $sourceId, array $payload): void
{
    $now = gmdate('c');
    central_supabase_request('POST', [
        'on_conflict' => 'source_system,record_type,source_id',
    ], [[
        'source_system' => $source,
        'record_type' => $recordType,
        'source_id' => $sourceId,
        'payload' => $payload,
        'received_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ]]);
}

function central_delete_record(string $source, string $recordType, string $sourceId): void
{
    central_supabase_request('PATCH', [
        'source_system' => 'eq.' . $source,
        'record_type' => 'eq.' . $recordType,
        'source_id' => 'eq.' . $sourceId,
    ], ['deleted_at' => gmdate('c'), 'updated_at' => gmdate('c')]);
}

function handle_api_request(): never
{
    central_load_env();
    header('X-Content-Type-Options: nosniff');

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $path = '/' . trim(preg_replace('#/+#', '/', $path), '/');

    if ($method === 'GET' && preg_match('#/api/v1/health$#', $path)) {
        try {
            central_supabase_request('GET', ['select' => 'id', 'limit' => '0']);
            central_json(200, ['ok' => true, 'service' => 'SIA101 Central Server', 'api_version' => 'v1']);
        } catch (Throwable $error) {
            error_log('Central API configuration error: ' . $error->getMessage());
            central_json(503, ['ok' => false, 'error' => 'service_not_configured']);
        }
    }

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
            central_delete_record($source, $recordType, $sourceId);
            central_json(200, ['ok' => true, 'record_type' => $recordType, 'source_id' => $sourceId, 'deleted' => true]);
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
        central_upsert_record($source, $recordType, $sourceId, $payload);
        central_json(200, ['ok' => true, 'record_type' => $recordType, 'source_id' => $sourceId]);
    } catch (Throwable $error) {
        error_log('Central API upsert failed: ' . $error->getMessage());
        central_json(503, ['ok' => false, 'error' => 'storage_unavailable']);
    }
}
