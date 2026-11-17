# Integration staging recovery walkthrough

Use isolated staging Supabase projects and a staging Central HTTPS endpoint for all four systems. Never point a staging sender at production credentials. The current workstation has no staging `.env` files or staging environment variables, so execute this runbook after staging projects and tokens are provisioned.

## Prepare

1. Apply each system's integration migrations to its staging database in the order documented by that repository.
2. Configure staging-only Central source tokens, inbox tokens, HTTPS URL, and each subsystem's own server key.
3. Register normal worker tasks and verify `/api/v1/health` returns healthy.
4. Create one test lifecycle asset, one bill, one anomaly, one maintenance request, and one maintenance outcome. Confirm Central records events and destination delivery logs.

## Recovery cases

1. **Central outage:** temporarily block the staging Central endpoint; create one source record; confirm it remains pending in that source's outbox. Restore Central and run its publisher. Confirm one accepted event and a processed destination delivery.
2. **Duplicate delivery:** deliver the same event twice. Confirm one replica keyed by `(source_system, record_type, source_id)` and one applied inbox event; the second delivery is acknowledged as duplicate.
3. **Out-of-order delivery:** deliver a newer snapshot, then an older snapshot. Confirm the replica retains the newer `source_updated_at` and the older event is marked stale.
4. **Destination outage:** stop one inbox worker while another destination remains available. Confirm the available destination processes independently. Restore the stopped worker and confirm its own queue drains without replaying the successful destination.
5. **Interrupted bootstrap:** stop a backfill after a batch, restart it without `--reconcile`, and confirm its checkpoint resumes without duplicate event IDs. Then run `--reconcile` and confirm a missing replica is recreated while newer destination data is preserved.
6. **Dead letter and replay:** force a staging destination failure through the retry limit, confirm Central shows a dead letter, correct the failure, replay it from `/admin.php`, and confirm `processed` status.

## Acceptance

- Every configured route reaches `processed` or an expected `stale` result; there are no unexplained queued, retrying, or dead-lettered records.
- Authoritative source rows are unchanged by receiver processing.
- Outbox and inbox checkpoints, Central delivery logs, health, and reconciliation status agree with the test fixtures.
- Save the staging endpoint version, migration order, walkthrough results, and rollback procedure before production rollout.
