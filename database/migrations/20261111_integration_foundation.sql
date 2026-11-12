-- Additive integration foundation for the current Central Server schema.
-- Run only after reviewing against the live project. This migration is not
-- applied by the repository workflow.

-- Keep the existing central_sync_events and central_sync_dead_letter queues
-- and their RPCs intact for compatibility. Add the four destination queues.
CREATE EXTENSION IF NOT EXISTS pgmq;
DO $queues$
BEGIN
    IF to_regclass('pgmq.q_events_inbox') IS NULL THEN PERFORM pgmq.create('events_inbox'); END IF;
    IF to_regclass('pgmq.q_lifecycle_inbox') IS NULL THEN PERFORM pgmq.create('lifecycle_inbox'); END IF;
    IF to_regclass('pgmq.q_utility_inbox') IS NULL THEN PERFORM pgmq.create('utility_inbox'); END IF;
    IF to_regclass('pgmq.q_maintain_inbox') IS NULL THEN PERFORM pgmq.create('maintain_inbox'); END IF;
END
$queues$;

-- central_records already accepts the three source systems and the current
-- utility/lifecycle/maintenance_request/maintenance_update record types. Keep
-- those checks and use the existing central_maintenance_requests projection.

CREATE TABLE IF NOT EXISTS public.subsystem_registry (
    subsystem_code TEXT PRIMARY KEY CHECK (subsystem_code IN ('CentralServer','LifeCycleTrack','UtilityTrack','MaintainTrack')),
    display_name TEXT NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    health_status TEXT NOT NULL DEFAULT 'unknown' CHECK (health_status IN ('unknown','healthy','degraded','unavailable')),
    last_seen_at TIMESTAMPTZ,
    credential_reference TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.event_registry (
    event_type TEXT NOT NULL,
    version TEXT NOT NULL,
    source_system TEXT NOT NULL CHECK (source_system IN ('LifeCycleTrack','UtilityTrack','MaintainTrack')),
    record_type TEXT NOT NULL CHECK (record_type IN ('utility_bill','utility_anomaly','lifecycle_asset','maintenance_request','maintenance_update')),
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (event_type, version, source_system)
);

CREATE TABLE IF NOT EXISTS public.event_subscriptions (
    subscription_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_system TEXT NOT NULL CHECK (source_system IN ('LifeCycleTrack','UtilityTrack','MaintainTrack')),
    event_type TEXT NOT NULL,
    destination_system TEXT NOT NULL CHECK (destination_system IN ('LifeCycleTrack','UtilityTrack','MaintainTrack')),
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (source_system, event_type, destination_system),
    CHECK (source_system <> destination_system)
);
CREATE INDEX IF NOT EXISTS event_subscriptions_route_idx
    ON public.event_subscriptions (source_system, event_type) WHERE enabled;

CREATE TABLE IF NOT EXISTS public.integration_events (
    event_id UUID PRIMARY KEY,
    event_type TEXT NOT NULL,
    source_system TEXT NOT NULL CHECK (source_system IN ('LifeCycleTrack','UtilityTrack','MaintainTrack')),
    destination_system TEXT CHECK (destination_system IS NULL OR destination_system IN ('LifeCycleTrack','UtilityTrack','MaintainTrack')),
    version TEXT NOT NULL,
    correlation_id UUID,
    occurred_at TIMESTAMPTZ NOT NULL,
    source_id TEXT NOT NULL,
    record_type TEXT NOT NULL CHECK (record_type IN ('utility_bill','utility_anomaly','lifecycle_asset','maintenance_request','maintenance_update')),
    payload JSONB NOT NULL CHECK (jsonb_typeof(payload) = 'object'),
    received_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS integration_events_source_time_idx
    ON public.integration_events (source_system, occurred_at DESC);
CREATE INDEX IF NOT EXISTS integration_events_correlation_idx
    ON public.integration_events (correlation_id) WHERE correlation_id IS NOT NULL;

-- Upgrade the existing delivery ledger in place. Keep its id, event_id,
-- source_system, destination_system, event_type, attempts, and timestamps.
ALTER TABLE public.integration_delivery_logs
    ADD COLUMN IF NOT EXISTS next_attempt_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS processed_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS dead_lettered_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS correlation_id UUID;

DO $delivery_status$
DECLARE c RECORD;
BEGIN
    FOR c IN
        SELECT conname
        FROM pg_constraint
        WHERE conrelid = 'public.integration_delivery_logs'::regclass
          AND contype = 'c'
          AND pg_get_constraintdef(oid) ILIKE '%status%'
    LOOP
        EXECUTE format('ALTER TABLE public.integration_delivery_logs DROP CONSTRAINT %I', c.conname);
    END LOOP;

    UPDATE public.integration_delivery_logs
       SET status = 'queued'
     WHERE status IN ('pending','published');

    ALTER TABLE public.integration_delivery_logs
        ADD CONSTRAINT integration_delivery_logs_status_integration_check
        CHECK (status IN ('queued','processed','failed','retrying','dead_lettered')) NOT VALID;
    ALTER TABLE public.integration_delivery_logs
        VALIDATE CONSTRAINT integration_delivery_logs_status_integration_check;
END
$delivery_status$;

DO $delivery_uniqueness$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.integration_delivery_logs
        GROUP BY event_id, destination_system HAVING count(*) > 1
    ) THEN
        RAISE EXCEPTION 'integration_delivery_logs has duplicate (event_id, destination_system) rows; resolve duplicates before adding the idempotency index';
    END IF;
END
$delivery_uniqueness$;
CREATE UNIQUE INDEX IF NOT EXISTS integration_delivery_logs_event_destination_uidx
    ON public.integration_delivery_logs (event_id, destination_system);
CREATE INDEX IF NOT EXISTS integration_delivery_logs_due_idx
    ON public.integration_delivery_logs (next_attempt_at, created_at)
    WHERE status IN ('queued','retrying','failed');

CREATE TABLE IF NOT EXISTS public.failed_events (
    failed_event_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    event_id UUID NOT NULL,
    destination_system TEXT CHECK (destination_system IS NULL OR destination_system IN ('LifeCycleTrack','UtilityTrack','MaintainTrack')),
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    failure_reason TEXT NOT NULL,
    failed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    replayed_at TIMESTAMPTZ,
    replayed_by TEXT
);
CREATE INDEX IF NOT EXISTS failed_events_open_idx
    ON public.failed_events (failed_at DESC) WHERE replayed_at IS NULL;

-- New integration tables are server-only. Preserve the existing delivery
-- ledger's current client policies/privileges; grant server-role access.
DO $security$
DECLARE t TEXT;
        s TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['subsystem_registry','event_registry','event_subscriptions','integration_events','failed_events'] LOOP
        EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('REVOKE ALL ON TABLE public.%I FROM anon, authenticated', t);
        EXECUTE format('GRANT ALL ON TABLE public.%I TO service_role', t);
    END LOOP;
    EXECUTE 'GRANT ALL ON TABLE public.integration_delivery_logs TO service_role';

    FOREACH t IN ARRAY ARRAY['event_subscriptions','failed_events'] LOOP
        s := pg_get_serial_sequence(format('public.%I', t), CASE t
            WHEN 'event_subscriptions' THEN 'subscription_id'
            ELSE 'failed_event_id' END);
        IF s IS NOT NULL THEN EXECUTE format('GRANT USAGE, SELECT ON SEQUENCE %s TO service_role', s); END IF;
    END LOOP;
    s := pg_get_serial_sequence('public.integration_delivery_logs', 'id');
    IF s IS NOT NULL THEN EXECUTE format('GRANT USAGE, SELECT ON SEQUENCE %s TO service_role', s); END IF;
END
$security$;
