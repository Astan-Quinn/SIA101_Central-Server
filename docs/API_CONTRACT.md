# Central REST API contract (v1)

UtilityTrack and LifeCycleTrack remain the owners of their records and send sanitized copies to the Central Server. The PHP API authenticates subsystem bearer tokens and writes accepted records to the Central Server Supabase project's public.central_records table.

## Authentication and responses

Send Authorization: Bearer <subsystem token> and Content-Type: application/json. UtilityTrack and LifeCycleTrack use different tokens; each is restricted to its assigned record types.

Upserts return HTTP 200 with {"ok":true,"record_type":"...","source_id":"..."}. Repeating an upsert replaces the payload for the same source identity. Invalid JSON returns 400; invalid fields return 422; missing or invalid caller credentials return 401; missing server configuration or Supabase failures return 503. Requests are limited to 1 MiB by default.

## Routes

| Method | Path | Source | Purpose |
|---|---|---|---|
| GET | /api/v1/health | Public | Confirms API configuration is present |
| POST | /api/v1/ingest/utility_bill | UtilityTrack | Create or update an electricity or water bill |
| DELETE | /api/v1/ingest/utility_bill/{id} | UtilityTrack | Soft-delete a bill copy |
| POST | /api/v1/ingest/utility_anomaly | UtilityTrack | Create or update an anomaly |
| DELETE | /api/v1/ingest/utility_anomaly/{id} | UtilityTrack | Soft-delete an anomaly copy |
| POST | /api/v1/ingest/lifecycle_asset | LifeCycleTrack | Create or update equipment or facility |
| DELETE | /api/v1/ingest/lifecycle_asset/{id} | LifeCycleTrack | Soft-delete an asset/facility copy |

The sender's id is its stable source identifier. Central identity is (source_system, record_type, source_id). A later upsert restores a soft-deleted row.

## Accepted payload fields

- utility_bill: id, utility, created_at, billing date/period fields, account number, source, location, readings, consumption, rate, subtotal, VAT, other charges, fee items, total, due date, and status.
- utility_anomaly: id, created_at, utility, current/previous periods, consumption and cost values, percentage changes, cause, status, and result.
- lifecycle_asset: id, entity_type (equipment or facility), asset tag, name, brand/model/category, location, acquisition information, condition/lifecycle status, quantity, cost/schedule fields, remarks, and timestamps.

Unknown properties are dropped. User IDs, account holder names, payment references, image paths, and free-form notes should not be sent from UtilityTrack. LifeCycleTrack should omit personal data and images.

## UtilityTrack example

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

The API stores accepted JSON payload, source system/type/ID, and timestamps in the Central Server Supabase project. This version has no historical backfill, central read/report API, or cross-system matching.
