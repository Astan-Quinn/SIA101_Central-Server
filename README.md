# SIA101 Central Server

PHP 8.1+ API that authenticates subsystem senders, persists sanitized events, routes them through Supabase Queues, and exposes inbox access through destination-scoped HTTPS credentials.

## Configure Supabase

1. In the Central Server Supabase project, apply the base `database/schema.sql`, then migrations `20261111_integration_foundation.sql`, `20261112_lifecycle_routing.sql`, `20261113_complete_integration.sql`, and `20261114_utility_anomaly_lifecycle_subscription.sql` in order.
2. Copy `.env.example` to `.env`. Set `SUPABASE_URL`, `SUPABASE_SECRET_KEY`, source API tokens, distinct inbox tokens, and `CENTRAL_OPERATOR_TOKEN`. Never put the Central Supabase secret key in a subsystem or browser.
3. Keep `QUEUE_VISIBILITY_SECONDS` above the maximum time spent handling a batch. The worker defaults to 120 seconds and moves a message to the dead-letter queue after eight failed reads.

## Run the API and worker

Host `public` as the web root or place this repository under XAMPP `htdocs`. Enable Apache rewrite and PHP cURL. Subsystems should use the Central Server computer's LAN address, not their own `localhost`. Use HTTPS when sending bearer tokens over a network.

Open `/admin.php` under the Central Server web root for the operator dashboard. The dashboard requires `CENTRAL_OPERATOR_TOKEN`; it displays subsystem health, delivery attempts, reconciliation runs, and open dead letters with replay controls.

The ingestion routes return `202 Accepted` only after Supabase Queues accepts the event. Run `php src/worker.php 10` from the repository root to process up to ten events. On Windows, run `powershell -ExecutionPolicy Bypass -File .\scripts\register-worker-task.ps1` from the repository root to register a one-minute Task Scheduler trigger. The task runs while the registering user is signed in. The worker is safe to restart; duplicate and stale events are idempotently ignored.

For weekly snapshot reconciliation, run `powershell -ExecutionPolicy Bypass -File .\scripts\register-reconciliation-tasks.ps1`. It registers one Sunday 03:30 task per subsystem; each source re-publishes allowlisted snapshots with fresh event IDs so Central can repair missing or stale replicas. The normal outbox and inbox workers deliver them.

Before promoting future changes, provision isolated staging projects and complete [`docs/STAGING_RECOVERY_RUNBOOK.md`](docs/STAGING_RECOVERY_RUNBOOK.md).

## API routes

- `GET /api/v1/health`
- `POST /api/v1/events` for canonical LifeCycleTrack events
- `GET /api/v1/sync/status/{event_id}` for source-scoped delivery status
- `GET /api/v1/admin/summary` and `POST /api/v1/admin/dead-letters/{id}/replay` for operators
- `POST /api/v1/inbox/{subsystem}/read` and `/ack` for inbox workers, each using its own bearer token
- `POST /api/v1/ingest/utility_bill` and `DELETE /api/v1/ingest/utility_bill/{id}`
- `POST /api/v1/ingest/utility_anomaly` and `DELETE /api/v1/ingest/utility_anomaly/{id}`
- `POST /api/v1/ingest/lifecycle_asset` and `DELETE /api/v1/ingest/lifecycle_asset/{id}`

Send `Authorization: Bearer <subsystem token>` and JSON for `POST`. Each subsystem token can write only its registered record types. See `docs/API_CONTRACT.md` for the event shape. Existing legacy ingest routes remain available during migration.

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
