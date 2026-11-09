-- Run in the Central Server Supabase project's SQL Editor.
-- Queue operations are exposed through narrowly scoped public RPC wrappers below.
CREATE EXTENSION IF NOT EXISTS pgmq;

DO $$ BEGIN
    IF to_regclass('pgmq.q_central_sync_events') IS NULL THEN
        PERFORM pgmq.create('central_sync_events');
    END IF;
    IF to_regclass('pgmq.q_central_sync_dead_letter') IS NULL THEN
        PERFORM pgmq.create('central_sync_dead_letter');
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS public.central_utility_bills (
    source_system TEXT NOT NULL DEFAULT 'UtilityTrack' CHECK (source_system = 'UtilityTrack'),
    source_id TEXT NOT NULL,
    payload JSONB NOT NULL,
    source_updated_at TIMESTAMPTZ NOT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at TIMESTAMPTZ NULL,
    PRIMARY KEY (source_system, source_id)
);
CREATE TABLE IF NOT EXISTS public.central_utility_anomalies (
    source_system TEXT NOT NULL DEFAULT 'UtilityTrack' CHECK (source_system = 'UtilityTrack'),
    source_id TEXT NOT NULL,
    payload JSONB NOT NULL,
    source_updated_at TIMESTAMPTZ NOT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at TIMESTAMPTZ NULL,
    PRIMARY KEY (source_system, source_id)
);
CREATE TABLE IF NOT EXISTS public.central_lifecycle_assets (
    source_system TEXT NOT NULL DEFAULT 'LifeCycleTrack' CHECK (source_system = 'LifeCycleTrack'),
    source_id TEXT NOT NULL,
    payload JSONB NOT NULL,
    source_updated_at TIMESTAMPTZ NOT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at TIMESTAMPTZ NULL,
    PRIMARY KEY (source_system, source_id)
);

-- Preserve rows from the prior JSONB store when this schema is deployed over an existing project.
DO $$
BEGIN
    IF to_regclass('public.central_records') IS NOT NULL THEN
        INSERT INTO public.central_utility_bills (source_system, source_id, payload, source_updated_at, received_at, updated_at, deleted_at)
        SELECT source_system, source_id, payload, COALESCE(updated_at, received_at, now()), received_at, updated_at, deleted_at
        FROM public.central_records WHERE source_system='UtilityTrack' AND record_type='utility_bill'
        ON CONFLICT (source_system, source_id) DO NOTHING;
        INSERT INTO public.central_utility_anomalies (source_system, source_id, payload, source_updated_at, received_at, updated_at, deleted_at)
        SELECT source_system, source_id, payload, COALESCE(updated_at, received_at, now()), received_at, updated_at, deleted_at
        FROM public.central_records WHERE source_system='UtilityTrack' AND record_type='utility_anomaly'
        ON CONFLICT (source_system, source_id) DO NOTHING;
        INSERT INTO public.central_lifecycle_assets (source_system, source_id, payload, source_updated_at, received_at, updated_at, deleted_at)
        SELECT source_system, source_id, payload, COALESCE(updated_at, received_at, now()), received_at, updated_at, deleted_at
        FROM public.central_records WHERE source_system='LifeCycleTrack' AND record_type='lifecycle_asset'
        ON CONFLICT (source_system, source_id) DO NOTHING;
    END IF;
END $$;

-- Atomic source-version check prevents concurrent workers or replayed events from restoring stale state.
CREATE OR REPLACE FUNCTION public.central_apply_event(p_event JSONB)
RETURNS BOOLEAN LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
AS $$
DECLARE
    v_source TEXT := p_event->>'source_system';
    v_type TEXT := p_event->>'record_type';
    v_id TEXT := p_event->>'source_id';
    v_operation TEXT := p_event->>'operation';
    v_version TIMESTAMPTZ := (p_event->>'source_updated_at')::timestamptz;
    v_payload JSONB := COALESCE(p_event->'payload', '{}'::jsonb);
    v_deleted_at TIMESTAMPTZ := CASE WHEN p_event->>'operation' = 'delete' THEN now() ELSE NULL END;
    v_changed INTEGER;
BEGIN
    IF v_id IS NULL OR v_id = '' OR v_version IS NULL OR v_operation IS NULL OR v_operation NOT IN ('upsert','delete') THEN
        RAISE EXCEPTION 'Malformed central event';
    END IF;
    IF NOT ((v_type = 'utility_bill' AND v_source = 'UtilityTrack') OR
            (v_type = 'utility_anomaly' AND v_source = 'UtilityTrack') OR
            (v_type = 'lifecycle_asset' AND v_source = 'LifeCycleTrack')) THEN
        RAISE EXCEPTION 'Invalid source and record type';
    END IF;
    IF v_operation = 'upsert' AND jsonb_typeof(p_event->'payload') <> 'object' THEN
        RAISE EXCEPTION 'Upsert payload must be an object';
    END IF;

    IF v_type = 'utility_bill' THEN
        INSERT INTO public.central_utility_bills AS target (source_system, source_id, payload, source_updated_at, received_at, updated_at, deleted_at)
        VALUES (v_source, v_id, v_payload, v_version, now(), now(), v_deleted_at)
        ON CONFLICT (source_system, source_id) DO UPDATE SET payload=CASE WHEN v_operation = 'delete' THEN target.payload ELSE EXCLUDED.payload END, source_updated_at=EXCLUDED.source_updated_at,
            received_at=EXCLUDED.received_at, updated_at=EXCLUDED.updated_at, deleted_at=EXCLUDED.deleted_at
        WHERE target.source_updated_at < EXCLUDED.source_updated_at;
    ELSIF v_type = 'utility_anomaly' THEN
        INSERT INTO public.central_utility_anomalies AS target (source_system, source_id, payload, source_updated_at, received_at, updated_at, deleted_at)
        VALUES (v_source, v_id, v_payload, v_version, now(), now(), v_deleted_at)
        ON CONFLICT (source_system, source_id) DO UPDATE SET payload=CASE WHEN v_operation = 'delete' THEN target.payload ELSE EXCLUDED.payload END, source_updated_at=EXCLUDED.source_updated_at,
            received_at=EXCLUDED.received_at, updated_at=EXCLUDED.updated_at, deleted_at=EXCLUDED.deleted_at
        WHERE target.source_updated_at < EXCLUDED.source_updated_at;
    ELSE
        INSERT INTO public.central_lifecycle_assets AS target (source_system, source_id, payload, source_updated_at, received_at, updated_at, deleted_at)
        VALUES (v_source, v_id, v_payload, v_version, now(), now(), v_deleted_at)
        ON CONFLICT (source_system, source_id) DO UPDATE SET payload=CASE WHEN v_operation = 'delete' THEN target.payload ELSE EXCLUDED.payload END, source_updated_at=EXCLUDED.source_updated_at,
            received_at=EXCLUDED.received_at, updated_at=EXCLUDED.updated_at, deleted_at=EXCLUDED.deleted_at
        WHERE target.source_updated_at < EXCLUDED.source_updated_at;
    END IF;
    GET DIAGNOSTICS v_changed = ROW_COUNT;
    RETURN v_changed > 0;
END;
$$;

DO $$
DECLARE t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['central_utility_bills','central_utility_anomalies','central_lifecycle_assets'] LOOP
        EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('REVOKE ALL ON TABLE public.%I FROM anon, authenticated', t);
        EXECUTE format('GRANT ALL ON TABLE public.%I TO service_role', t);
    END LOOP;
END $$;

-- SECURITY DEFINER is constrained to fixed queue names and callable only by service_role.
CREATE OR REPLACE FUNCTION public.central_queue_send(p_message JSONB)
RETURNS BIGINT LANGUAGE SQL SECURITY DEFINER SET search_path = pgmq, public
AS $$ SELECT pgmq.send('central_sync_events', p_message) $$;
CREATE OR REPLACE FUNCTION public.central_queue_read(p_visibility_seconds INTEGER DEFAULT 60, p_count INTEGER DEFAULT 10)
RETURNS TABLE(msg_id BIGINT, read_ct BIGINT, enqueued_at TIMESTAMPTZ, vt TIMESTAMPTZ, message JSONB)
LANGUAGE SQL SECURITY DEFINER SET search_path = pgmq, public
AS $$ SELECT m.msg_id, m.read_ct, m.enqueued_at, m.vt, m.message
      FROM pgmq.read('central_sync_events', p_visibility_seconds, p_count) AS m $$;
CREATE OR REPLACE FUNCTION public.central_queue_archive(p_msg_id BIGINT)
RETURNS BOOLEAN LANGUAGE SQL SECURITY DEFINER SET search_path = pgmq, public
AS $$ SELECT pgmq.archive('central_sync_events', p_msg_id) $$;
CREATE OR REPLACE FUNCTION public.central_queue_dead_letter(p_message JSONB)
RETURNS BIGINT LANGUAGE SQL SECURITY DEFINER SET search_path = pgmq, public
AS $$ SELECT pgmq.send('central_sync_dead_letter', p_message) $$;
REVOKE ALL ON FUNCTION public.central_queue_send(JSONB) FROM PUBLIC, anon, authenticated;
REVOKE ALL ON FUNCTION public.central_queue_read(INTEGER, INTEGER) FROM PUBLIC, anon, authenticated;
REVOKE ALL ON FUNCTION public.central_queue_archive(BIGINT) FROM PUBLIC, anon, authenticated;
REVOKE ALL ON FUNCTION public.central_queue_dead_letter(JSONB) FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.central_queue_send(JSONB) TO service_role;
GRANT EXECUTE ON FUNCTION public.central_queue_read(INTEGER, INTEGER) TO service_role;
GRANT EXECUTE ON FUNCTION public.central_queue_archive(BIGINT) TO service_role;
GRANT EXECUTE ON FUNCTION public.central_queue_dead_letter(JSONB) TO service_role;
REVOKE ALL ON FUNCTION public.central_apply_event(JSONB) FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.central_apply_event(JSONB) TO service_role;
