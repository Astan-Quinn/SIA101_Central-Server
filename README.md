# SIA101 Central Server

PHP 8.1+ REST API for receiving records from UtilityTrack and LifeCycleTrack. The first version is an authenticated, idempotent ingestion API backed by PostgreSQL. Each subsystem keeps its own database; this service stores a sanitized central copy of records sent to it.

## Local setup (XAMPP)

1. Copy `.env.example` to `.env` and set PostgreSQL connection details and two different long random API tokens.
2. Create the PostgreSQL database named in `DB_DATABASE` and run `database/schema.sql`.
3. Enable PHP `pdo_pgsql` and `pgsql` in `php.ini`, then restart Apache.
4. Point Apache at this project, or run PHP's built-in server with `public` as its document root. For XAMPP, the URL will usually be `http://localhost/SIA101_Central-Server/public/`.
5. Send `Authorization: Bearer <source token>` and a JSON body to one of the ingestion routes below. Use HTTPS when the API is reachable outside the local machine.

## Routes

- `GET /api/v1/health` — service health check; no database details are returned.
- `POST /api/v1/ingest/utility_bill` — UtilityTrack bill upsert.
- `DELETE /api/v1/ingest/utility_bill/{id}` — UtilityTrack bill tombstone.
- `POST /api/v1/ingest/utility_anomaly` — UtilityTrack anomaly upsert.
- `DELETE /api/v1/ingest/utility_anomaly/{id}` — UtilityTrack anomaly tombstone.
- `POST /api/v1/ingest/lifecycle_asset` — LifeCycleTrack equipment or facility upsert.
- `DELETE /api/v1/ingest/lifecycle_asset/{id}` — LifeCycleTrack asset tombstone.

The full field allowlists, response shape, and examples are in `docs/API_CONTRACT.md`.

## Security and data handling

- The bearer token identifies the sending subsystem; UtilityTrack cannot write LifeCycleTrack records, or vice versa.
- Keep `.env` outside source control. Use separate random tokens and rotate them if exposed.
- The API accepts only allowlisted fields, limits JSON requests to 1 MiB by default, and never returns database errors to callers.
- Upserts use `(source_system, record_type, source_id)` as the idempotency key. Deletes are soft deletes, preserving the last payload for audit.
- UtilityTrack payloads deliberately omit user IDs, account-holder names, payment references, bill images, and notes.

This is an initial integration backend. Add TLS at the web server or reverse proxy before connecting machines over a network.
