# SIA101 Central Server

PHP 8.1+ API that authenticates subsystem senders, persists sanitized events, routes them through Supabase Queues, and exposes inbox access through destination-scoped HTTPS credentials.

## Configure Supabase

1. In the Central Server Supabase project, apply the base `database/schema.sql`, then `database/migrations/20261111_integration_foundation.sql` and `database/migrations/20261112_lifecycle_routing.sql`.
2. Copy `.env.example` to `.env`. Set `SUPABASE_URL`, `SUPABASE_SECRET_KEY`, source API tokens, and a distinct inbox token per subsystem. Never put the Central Supabase secret key in a subsystem or browser.
3. Keep `QUEUE_VISIBILITY_SECONDS` above the maximum time spent handling a batch. The worker defaults to 120 seconds and moves a message to the dead-letter queue after eight failed reads.

## Run the API and worker

Host `public` as the web root or place this repository under XAMPP `htdocs`. Enable Apache rewrite and PHP cURL. Subsystems should use the Central Server computer's LAN address, not their own `localhost`. Use HTTPS when sending bearer tokens over a network.

The ingestion routes return `202 Accepted` only after Supabase Queues accepts the event. Run `php src/worker.php 10` from the repository root to process up to ten events. On Windows, run `powershell -ExecutionPolicy Bypass -File .\scripts\register-worker-task.ps1` from the repository root to register a one-minute Task Scheduler trigger. The task runs while the registering user is signed in. The worker is safe to restart; duplicate and stale events are idempotently ignored.

## API routes

- `GET /api/v1/health`
- `POST /api/v1/events` for canonical LifeCycleTrack events
- `POST /api/v1/inbox/{subsystem}/read` and `/ack` for inbox workers, each using its own bearer token
- `POST /api/v1/ingest/utility_bill` and `DELETE /api/v1/ingest/utility_bill/{id}`
- `POST /api/v1/ingest/utility_anomaly` and `DELETE /api/v1/ingest/utility_anomaly/{id}`
- `POST /api/v1/ingest/lifecycle_asset` and `DELETE /api/v1/ingest/lifecycle_asset/{id}`

Send `Authorization: Bearer <subsystem token>` and JSON for `POST`. UtilityTrack's token can write bills and anomalies; LifeCycleTrack's token can write lifecycle assets. See `docs/API_CONTRACT.md` for fields and event shape. The Central Server stores each record type in its own table and soft-deletes records.

## Recovery

Messages that fail processing are retried after the visibility timeout. After eight failed reads they are copied to `central_sync_dead_letter` and archived from the active queue. Inspect dead-letter messages in the Supabase Dashboard, correct the underlying issue, then replay through the queue RPC or a repair script. Queue depth and message age are available in the Supabase Queues Dashboard.

## Run to Powershell as Administrator
Set-ExecutionPolicy -Scope Process Bypass
cd E:\xampp\htdocs\SIA103_Central-Server
.\scripts\register-worker-task.ps1

# To Disable
Disable-ScheduledTask -TaskName "SIA101-Central-Supabase-Queue-Worker"

# To Enable 
Enable-ScheduledTask -TaskName "SIA101-Central-Supabase-Queue-Worker"
