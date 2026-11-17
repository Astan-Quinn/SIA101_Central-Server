# Central REST API contract (v1)

Each subsystem remains the owner of its records. The PHP API authenticates source bearer tokens, persists accepted canonical events, and routes them according to `event_subscriptions`. The Central Supabase service key remains on the Central Server.

## Authentication and responses

Send Authorization: Bearer <subsystem token> and Content-Type: application/json. LifeCycleTrack, UtilityTrack, and MaintainTrack have separate source tokens restricted to their registered record types. Destination inbox tokens are separate credentials.

Accepted upserts and deletes return HTTP 202 with `queued: true`, record type, source ID, and queue timestamp. This confirms durable enqueueing, not completion by the worker. Invalid JSON returns 400; invalid fields return 422; missing or invalid caller credentials return 401; missing server configuration or Supabase failures return 503. Requests are limited to 1 MiB by default.

## Routes

| Method | Path | Source | Purpose |
|---|---|---|---|
| GET | /api/v1/health | Public | Confirms API configuration is present |
| POST | /api/v1/events | LifeCycleTrack, UtilityTrack, MaintainTrack | Accepts the versioned event envelope and routes registered subscriptions |
| POST | /api/v1/inbox/{subsystem}/read | Destination worker token | Reads only that subsystem's inbox with a visibility timeout |
| POST | /api/v1/inbox/{subsystem}/ack | Destination worker token | Archives a message after the destination has committed processing |
| GET | /api/v1/sync/status/{event_id} | Source token | Reads the caller's event and its per-destination delivery status |
| GET | /api/v1/admin/summary | Central operator token | Lists recent deliveries and unresolved dead letters |
| POST | /api/v1/admin/dead-letters/{id}/replay | Central operator token | Requeues a failed destination delivery with the original event ID |
| POST | /api/v1/ingest/utility_bill | UtilityTrack | Create or update an electricity or water bill |
| DELETE | /api/v1/ingest/utility_bill/{id} | UtilityTrack | Soft-delete a bill copy |
| POST | /api/v1/ingest/utility_anomaly | UtilityTrack | Create or update an anomaly |
| DELETE | /api/v1/ingest/utility_anomaly/{id} | UtilityTrack | Soft-delete an anomaly copy |
| POST | /api/v1/ingest/lifecycle_asset | LifeCycleTrack | Create or update equipment or facility |
| DELETE | /api/v1/ingest/lifecycle_asset/{id} | LifeCycleTrack | Soft-delete an asset/facility copy |

Canonical messages use `event_id`, `event_type`, `source`, `destination`, `version`, `correlation_id`, `occurred_at`, `source_id`, `record_type`, and `payload`. `version` is the event schema version (`1.0`); `occurred_at` is the record ordering timestamp currently used by replicas. The authenticated token determines source identity; the sender-provided destination is ignored for routing. Central resolves destinations from enabled subscriptions. Queue reads never acknowledge messages; a destination acknowledges only after its own database transaction commits.

Registered contracts are LifecycleTrack lifecycle assets, UtilityTrack utility bills and anomalies, and MaintainTrack work orders and maintenance results. Utility bills and anomaly references route to LifeCycleTrack; LifecycleTrack asset references route to MaintainTrack and UtilityTrack; MaintainTrack work-order/result events route to LifeCycleTrack. UtilityTrack remains authoritative for bills and anomalies; receiving records in LifeCycleTrack are read-only snapshots. Optional UtilityTrack-to-MaintainTrack routing stays disabled.

## Accepted payload fields

- utility_bill: id, utility, created_at, billing date/period fields, source, location, readings, consumption, rate, subtotal, VAT, other charges, fee items, total, due date, and status. Account numbers are excluded.
- utility_anomaly: id, created_at, utility, current/previous periods, consumption and cost values, percentage changes, cause, status, and result.
- lifecycle_asset: id, entity_type (equipment or facility), asset tag, name, brand/model/category, location, acquisition information, condition/lifecycle status, quantity, cost/schedule fields, and timestamps. Images and free-form remarks are excluded.

Unknown properties are dropped. User IDs, account holder names, payment references, image paths, and free-form notes should not be sent from UtilityTrack. LifeCycleTrack should omit personal data and images.

## UtilityTrack example

    {
      "id": "bill-uuid",
      "utility": "electricity",
      "billing_date": "2026-10-01",
      "billing_period": "September 2026",
      "location": "New Barangay Hall",
      "consumption": 850,
      "total_amount": 8500,
      "status": "Pending"
    }

## LifeCycleTrack example

    {
      "id": "equipment-row-id",
      "entity_type": "equipment",
      "asset_tag": "EQ-0123456789",
      "name": "Generator",
      "category": "Office, facility or utility equipment",
      "location": "New Barangay Hall",
      "condition": "Good",
      "lifecycle_status": "Serviceable"
    }

Legacy `/api/v1/ingest/...` routes remain compatibility adapters for older clients and continue using the legacy queue. New publishers use `/api/v1/events`. The `20261113_complete_integration.sql` migration adds canonical route registrations, operator status/replay, and a reconciliation run ledger; historical backfill and automated drift detection still require a source worker/checkpoint rollout.
