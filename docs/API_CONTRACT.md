# Central REST API contract (v1 draft)

The Central Server is the receiving hub. UtilityTrack and LifeCycleTrack remain owners of their records and send sanitized copies to it. Requests are JSON over HTTPS, authenticated with a separate bearer token for each subsystem.

## Authentication and responses

Send `Authorization: Bearer <subsystem token>` and `Content-Type: application/json`. UtilityTrack and LifeCycleTrack have separate credentials. A token may write only the record types assigned to its subsystem.

Successful upserts return HTTP 200 with `{"ok":true,"record_type":"...","source_id":"..."}`. Repeating a request replaces the stored payload for that same source record, so clients can retry safely. Invalid payloads return 400 or 422; missing/invalid credentials return 401; missing server credentials return 503; storage failures return 503. Payloads are limited to 1 MiB by default.

## Routes

| Method | Path | Source | Purpose |
|---|---|---|---|
| GET | `/api/v1/health` | Public | Service liveness only |
| POST | `/api/v1/ingest/utility_bill` | UtilityTrack | Create or update one electricity or water bill |
| DELETE | `/api/v1/ingest/utility_bill/{id}` | UtilityTrack | Soft-delete a bill copy |
| POST | `/api/v1/ingest/utility_anomaly` | UtilityTrack | Create or update one anomaly |
| DELETE | `/api/v1/ingest/utility_anomaly/{id}` | UtilityTrack | Soft-delete an anomaly copy |
| POST | `/api/v1/ingest/lifecycle_asset` | LifeCycleTrack | Create or update one equipment or facility record |
| DELETE | `/api/v1/ingest/lifecycle_asset/{id}` | LifeCycleTrack | Soft-delete an asset/facility copy |

The source record's `id` is its stable identifier within that source application. Central identity is `(source_system, record_type, source_id)`. Deletion is a soft delete; the last payload remains available for audit. A later upsert restores the record.

## Payload fields

`utility_bill` accepts `id`, `utility`, `created_at`, billing date/period fields, account number, source, location, readings, consumption, rate, subtotal, VAT, other charges, itemized fee fields, total, due date, and status. It does not accept user IDs, account-holder names, payment references, image paths, or notes.

`utility_anomaly` accepts `id`, `created_at`, utility, current/previous periods, consumption and cost values, percentage changes, cause, status, and result. It does not accept user IDs or investigation history.

`lifecycle_asset` accepts `id`, `entity_type` (`equipment` or `facility`), asset tag, name, brand/model/category, location, acquisition information, condition/lifecycle status, quantity, cost/schedule fields, remarks, and timestamps. The sender should omit images and personal data.

Unknown properties are ignored. `id` is required for every record; UtilityTrack records also require `utility`, while LifeCycleTrack records require `entity_type`.

## Examples

UtilityTrack bill:

```json
{
  "id": "bill-uuid",
  "utility": "electricity",
  "billing_date": "2026-10-01",
  "billing_period": "September 2026",
  "account_number": "ELEC-001245",
  "location": "New Barangay Hall",
  "consumption": 850,
  "total_amount": 8500,
  "status": "Pending"
}
```

LifeCycleTrack equipment:

```json
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
```

## Storage and ownership

The central service stores the accepted JSON payload in PostgreSQL and tracks its source and receipt/update times. It does not write back into either subsystem database. This first slice has no historical backfill, central read/report API, field-level ownership rules, or shared asset/account matching keys; agree those before adding them.
