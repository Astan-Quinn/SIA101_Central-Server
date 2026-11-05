<?php
declare(strict_types=1);

function central_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false ? ($_ENV[$name] ?? $default) : (string) $value;
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

function central_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host = central_env('DB_HOST', '127.0.0.1');
    $port = central_env('DB_PORT', '5432');
    $database = central_env('DB_DATABASE', 'central_server');
    $sslmode = central_env('DB_SSLMODE', 'prefer');
    $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';sslmode=' . $sslmode;
    $pdo = new PDO($dsn, central_env('DB_USERNAME'), central_env('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
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
    $allowed = central_allowed_fields($recordType);
    $payload = array_intersect_key($input, array_flip($allowed));
    $idValue = $payload['id'] ?? null;
    if (!is_string($idValue) && !is_int($idValue)) central_json(422, ['ok' => false, 'error' => 'id_must_be_string_or_integer']);
    $id = trim((string) $idValue);
    if ($id === '' || strlen($id) > 100) central_json(422, ['ok' => false, 'error' => 'id_required']);
    $payload['id'] = $id;

    if ($recordType === 'utility_bill' && !in_array($payload['utility'] ?? null, ['electricity', 'water'], true)) {
        central_json(422, ['ok' => false, 'error' => 'utility_must_be_electricity_or_water']);
    }
    if ($recordType === 'utility_anomaly' && !in_array($payload['utility'] ?? null, ['electricity', 'water'], true)) {
        central_json(422, ['ok' => false, 'error' => 'utility_must_be_electricity_or_water']);
    }
    if ($recordType === 'lifecycle_asset'
        && !in_array($payload['entity_type'] ?? null, ['equipment', 'facility'], true)) {
        central_json(422, ['ok' => false, 'error' => 'entity_type_must_be_equipment_or_facility']);
    }

    return [$id, $payload];
}

function central_store_record(string $source, string $recordType, string $sourceId, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) central_json(422, ['ok' => false, 'error' => 'invalid_json_payload']);

    $stmt = central_db()->prepare(
        'INSERT INTO public.central_records (source_system, record_type, source_id, payload, received_at, updated_at, deleted_at)
         VALUES (:source, :record_type, :source_id, CAST(:payload AS jsonb), now(), now(), NULL)
         ON CONFLICT (source_system, record_type, source_id)
         DO UPDATE SET payload = EXCLUDED.payload, updated_at = now(), deleted_at = NULL'
    );
    $stmt->execute([
        'source' => $source,
        'record_type' => $recordType,
        'source_id' => $sourceId,
        'payload' => $json,
    ]);
}

function central_delete_record(string $source, string $recordType, string $sourceId): void
{
    $stmt = central_db()->prepare(
        'UPDATE public.central_records SET deleted_at = now(), updated_at = now()
         WHERE source_system = :source AND record_type = :record_type AND source_id = :source_id'
    );
    $stmt->execute(['source' => $source, 'record_type' => $recordType, 'source_id' => $sourceId]);
}

function handle_api_request(): never
{
    central_load_env();
    header('X-Content-Type-Options: nosniff');

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $path = '/' . trim(preg_replace('#/+#', '/', $path), '/');

    if ($method === 'GET' && preg_match('#/api/v1/health$#', $path)) {
        central_json(200, ['ok' => true, 'service' => 'SIA101 Central Server', 'api_version' => 'v1']);
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
    if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) central_json(400, ['ok' => false, 'error' => 'json_object_required']);
    $input = json_decode($raw, true);
    if (!is_array($input) || array_is_list($input)) central_json(400, ['ok' => false, 'error' => 'json_object_required']);

    [$sourceId, $payload] = central_validate_payload($recordType, $input);
    try {
        central_store_record($source, $recordType, $sourceId, $payload);
        central_json(200, ['ok' => true, 'record_type' => $recordType, 'source_id' => $sourceId]);
    } catch (Throwable $error) {
        error_log('Central API upsert failed: ' . $error->getMessage());
        central_json(503, ['ok' => false, 'error' => 'storage_unavailable']);
    }
}
