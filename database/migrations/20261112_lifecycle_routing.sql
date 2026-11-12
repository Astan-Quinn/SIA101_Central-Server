-- LifecycleTrack -> Central -> MaintainTrack routing and inbox API RPCs.
-- Apply after 20261111_integration_foundation.sql.

DO $delivery_status$
DECLARE c RECORD;
BEGIN
    FOR c IN
        SELECT conname FROM pg_constraint
        WHERE conrelid = 'public.integration_delivery_logs'::regclass
          AND contype = 'c' AND pg_get_constraintdef(oid) ILIKE '%status%'
    LOOP
        EXECUTE format('ALTER TABLE public.integration_delivery_logs DROP CONSTRAINT %I', c.conname);
    END LOOP;
    ALTER TABLE public.integration_delivery_logs
        ADD CONSTRAINT integration_delivery_logs_status_integration_check
        CHECK (status IN ('queued','delivering','processed','failed','retrying','dead_lettered')) NOT VALID;
    ALTER TABLE public.integration_delivery_logs
        VALIDATE CONSTRAINT integration_delivery_logs_status_integration_check;
END
$delivery_status$;

ALTER TABLE public.integration_delivery_logs
    ADD COLUMN IF NOT EXISTS queue_message_id BIGINT;

INSERT INTO public.event_registry (event_type, version, source_system, record_type)
VALUES
    ('lifecycle.asset.upserted', '1.0', 'LifeCycleTrack', 'lifecycle_asset'),
    ('lifecycle.asset.deleted', '1.0', 'LifeCycleTrack', 'lifecycle_asset')
ON CONFLICT (event_type, version, source_system) DO NOTHING;

INSERT INTO public.event_subscriptions (source_system, event_type, destination_system)
VALUES
    ('LifeCycleTrack', 'lifecycle.asset.upserted', 'MaintainTrack'),
    ('LifeCycleTrack', 'lifecycle.asset.deleted', 'MaintainTrack')
ON CONFLICT (source_system, event_type, destination_system) DO NOTHING;

CREATE OR REPLACE FUNCTION public.integration_accept_event(p_event JSONB, p_authenticated_source TEXT)
RETURNS JSONB
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = public, pgmq, pg_temp
AS $$
DECLARE
    v_event_id UUID;
    v_event_type TEXT := p_event->>'event_type';
    v_source TEXT := p_event->>'source';
    v_version TEXT := p_event->>'version';
    v_source_id TEXT := p_event->>'source_id';
    v_record_type TEXT := p_event->>'record_type';
    v_correlation UUID;
    v_occurred_at TIMESTAMPTZ;
    v_payload JSONB := p_event->'payload';
    v_deleted_at TIMESTAMPTZ;
BEGIN
    BEGIN
        v_event_id := (p_event->>'event_id')::UUID;
        v_correlation := NULLIF(p_event->>'correlation_id','')::UUID;
        v_occurred_at := (p_event->>'occurred_at')::TIMESTAMPTZ;
    EXCEPTION WHEN OTHERS THEN
        RAISE EXCEPTION 'Malformed event identifiers or timestamp' USING ERRCODE = '22023';
    END;

    IF jsonb_typeof(p_event) <> 'object'
       OR v_source IS DISTINCT FROM p_authenticated_source
       OR v_source <> 'LifeCycleTrack'
       OR v_record_type <> 'lifecycle_asset'
       OR v_event_type NOT IN ('lifecycle.asset.upserted','lifecycle.asset.deleted')
       OR v_version <> '1.0'
       OR v_source_id IS NULL OR v_source_id = '' OR length(v_source_id) > 100
       OR v_occurred_at IS NULL
       OR jsonb_typeof(v_payload) <> 'object'
       OR v_payload->>'entity_type' NOT IN ('facility','equipment')
       OR (v_payload->>'id') IS DISTINCT FROM v_source_id THEN
        RAISE EXCEPTION 'Event does not match the authenticated LifecycleTrack contract' USING ERRCODE = '22023';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM public.event_registry r
        WHERE r.event_type = v_event_type AND r.version = v_version
          AND r.source_system = v_source AND r.record_type = v_record_type AND r.enabled
    ) THEN
        RAISE EXCEPTION 'Event type is not registered' USING ERRCODE = '22023';
    END IF;

    INSERT INTO public.integration_events
        (event_id,event_type,source_system,destination_system,version,correlation_id,occurred_at,source_id,record_type,payload)
    VALUES
        (v_event_id,v_event_type,v_source,NULL,v_version,v_correlation,v_occurred_at,v_source_id,v_record_type,v_payload)
    ON CONFLICT (event_id) DO NOTHING;
    IF NOT FOUND THEN
        RETURN jsonb_build_object('accepted',true,'duplicate',true,'event_id',v_event_id,'router_queued',false);
    END IF;

    -- Keep the existing central lifecycle projection current without changing
    -- its source identifier or the source-owned record.
    v_deleted_at := CASE WHEN v_event_type = 'lifecycle.asset.deleted' THEN v_occurred_at ELSE NULL END;
    INSERT INTO public.central_lifecycle_assets AS current_asset
        (source_system,source_id,payload,source_updated_at,received_at,updated_at,deleted_at)
    VALUES ('LifeCycleTrack',v_source_id,v_payload,v_occurred_at,now(),now(),v_deleted_at)
    ON CONFLICT (source_system,source_id) DO UPDATE SET
        payload = CASE WHEN v_deleted_at IS NULL THEN EXCLUDED.payload ELSE current_asset.payload END,
        source_updated_at = EXCLUDED.source_updated_at,
        received_at = now(), updated_at = now(), deleted_at = EXCLUDED.deleted_at
    WHERE current_asset.source_updated_at < EXCLUDED.source_updated_at;

    PERFORM pgmq.send('events_inbox',p_event);
    RETURN jsonb_build_object('accepted',true,'duplicate',false,'event_id',v_event_id,'router_queued',true);
END;
$$;

-- Central router consumes events_inbox and fans out atomically according to
-- active subscriptions. A failed transaction leaves the event for redelivery.
CREATE OR REPLACE FUNCTION public.integration_route_events(
    p_visibility_seconds INTEGER DEFAULT 120,
    p_count INTEGER DEFAULT 10,
    p_max_reads INTEGER DEFAULT 8
)
RETURNS INTEGER LANGUAGE plpgsql SECURITY DEFINER
SET search_path = public, pgmq, pg_temp
AS $$
DECLARE
    m RECORD;
    r RECORD;
    v_event_id UUID;
    v_event JSONB;
    v_queue_id BIGINT;
    v_delivery_id BIGINT;
    v_routed INTEGER := 0;
    v_handled INTEGER := 0;
BEGIN
    IF p_visibility_seconds < 10 OR p_visibility_seconds > 3600
       OR p_count < 1 OR p_count > 50 OR p_max_reads < 1 THEN
        RAISE EXCEPTION 'Invalid router parameters' USING ERRCODE='22023';
    END IF;
    FOR m IN SELECT * FROM pgmq.read('events_inbox',p_visibility_seconds,p_count) LOOP
        v_event := m.message;
        BEGIN
            v_event_id := (v_event->>'event_id')::UUID;
        EXCEPTION WHEN OTHERS THEN
            v_event_id := NULL;
        END;
        IF v_event_id IS NULL THEN
            PERFORM pgmq.send('central_sync_dead_letter',jsonb_build_object('reason','router event has invalid event_id','failed_at',now(),'event',v_event));
            PERFORM pgmq.archive('events_inbox',m.msg_id);
            v_handled := v_handled + 1;
            CONTINUE;
        END IF;
        IF m.read_ct >= p_max_reads THEN
            PERFORM pgmq.send('central_sync_dead_letter',jsonb_build_object('reason','router retry limit exceeded','failed_at',now(),'event',v_event));
            INSERT INTO public.failed_events(event_id,destination_system,attempts,failure_reason)
            VALUES(v_event_id,NULL,m.read_ct,'router retry limit exceeded');
            PERFORM pgmq.archive('events_inbox',m.msg_id);
            v_handled := v_handled + 1;
            CONTINUE;
        END IF;

        v_routed := 0;
        FOR r IN
            SELECT s.destination_system FROM public.event_subscriptions s
            JOIN public.integration_events e ON e.event_id=v_event_id
            WHERE s.source_system=e.source_system AND s.event_type=e.event_type AND s.enabled
            ORDER BY s.destination_system
        LOOP
            INSERT INTO public.integration_delivery_logs
                (event_id,source_system,destination_system,event_type,status,attempts,correlation_id)
            SELECT e.event_id,e.source_system,r.destination_system,e.event_type,'queued',0,e.correlation_id
            FROM public.integration_events e WHERE e.event_id=v_event_id
            ON CONFLICT (event_id,destination_system) DO NOTHING
            RETURNING id INTO v_delivery_id;
            IF v_delivery_id IS NOT NULL THEN
                IF r.destination_system='MaintainTrack' THEN
                    v_queue_id:=pgmq.send('maintain_inbox',jsonb_set(v_event,'{destination}',to_jsonb(r.destination_system),true));
                ELSIF r.destination_system='LifeCycleTrack' THEN
                    v_queue_id:=pgmq.send('lifecycle_inbox',jsonb_set(v_event,'{destination}',to_jsonb(r.destination_system),true));
                ELSIF r.destination_system='UtilityTrack' THEN
                    v_queue_id:=pgmq.send('utility_inbox',jsonb_set(v_event,'{destination}',to_jsonb(r.destination_system),true));
                ELSE
                    RAISE EXCEPTION 'Unsupported integration destination';
                END IF;
                UPDATE public.integration_delivery_logs SET queue_message_id=v_queue_id,updated_at=now() WHERE id=v_delivery_id;
                v_routed:=v_routed+1;
            END IF;
            v_delivery_id:=NULL;
        END LOOP;
        PERFORM pgmq.archive('events_inbox',m.msg_id);
        v_handled:=v_handled+1;
    END LOOP;
    RETURN v_handled;
END;
$$;

CREATE OR REPLACE FUNCTION public.integration_read_inbox(
    p_destination TEXT, p_visibility_seconds INTEGER DEFAULT 120, p_count INTEGER DEFAULT 10
)
RETURNS TABLE(msg_id BIGINT, read_ct BIGINT, enqueued_at TIMESTAMPTZ, vt TIMESTAMPTZ, message JSONB)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = public, pgmq, pg_temp
AS $$
DECLARE m RECORD;
BEGIN
    IF p_destination NOT IN ('LifeCycleTrack','UtilityTrack','MaintainTrack')
       OR p_visibility_seconds < 10 OR p_visibility_seconds > 3600
       OR p_count < 1 OR p_count > 50 THEN
        RAISE EXCEPTION 'Invalid inbox read parameters' USING ERRCODE = '22023';
    END IF;
    IF p_destination = 'MaintainTrack' THEN
        FOR m IN SELECT * FROM pgmq.read('maintain_inbox',p_visibility_seconds,p_count) LOOP
            UPDATE public.integration_delivery_logs d
               SET status = CASE WHEN m.read_ct > 1 THEN 'retrying' ELSE 'delivering' END,
                   attempts = GREATEST(d.attempts + 1, m.read_ct::INTEGER), updated_at = now()
             WHERE d.event_id = NULLIF(m.message->>'event_id','')::UUID
               AND d.destination_system = p_destination;
            msg_id:=m.msg_id; read_ct:=m.read_ct; enqueued_at:=m.enqueued_at; vt:=m.vt; message:=m.message; RETURN NEXT;
        END LOOP;
    ELSIF p_destination = 'LifeCycleTrack' THEN
        FOR m IN SELECT * FROM pgmq.read('lifecycle_inbox',p_visibility_seconds,p_count) LOOP
            UPDATE public.integration_delivery_logs d SET status=CASE WHEN m.read_ct>1 THEN 'retrying' ELSE 'delivering' END,
                attempts=GREATEST(d.attempts+1,m.read_ct::INTEGER),updated_at=now()
             WHERE d.event_id=NULLIF(m.message->>'event_id','')::UUID AND d.destination_system=p_destination;
            msg_id:=m.msg_id; read_ct:=m.read_ct; enqueued_at:=m.enqueued_at; vt:=m.vt; message:=m.message; RETURN NEXT;
        END LOOP;
    ELSE
        FOR m IN SELECT * FROM pgmq.read('utility_inbox',p_visibility_seconds,p_count) LOOP
            UPDATE public.integration_delivery_logs d SET status=CASE WHEN m.read_ct>1 THEN 'retrying' ELSE 'delivering' END,
                attempts=GREATEST(d.attempts+1,m.read_ct::INTEGER),updated_at=now()
             WHERE d.event_id=NULLIF(m.message->>'event_id','')::UUID AND d.destination_system=p_destination;
            msg_id:=m.msg_id; read_ct:=m.read_ct; enqueued_at:=m.enqueued_at; vt:=m.vt; message:=m.message; RETURN NEXT;
        END LOOP;
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION public.integration_ack_inbox(p_destination TEXT,p_msg_id BIGINT)
RETURNS BOOLEAN LANGUAGE plpgsql SECURITY DEFINER
SET search_path = public, pgmq, pg_temp
AS $$
DECLARE v_queue TEXT; v_message JSONB; v_event_id UUID; v_archived BOOLEAN; v_status TEXT;
BEGIN
    v_queue := CASE p_destination WHEN 'MaintainTrack' THEN 'maintain_inbox'
        WHEN 'LifeCycleTrack' THEN 'lifecycle_inbox' WHEN 'UtilityTrack' THEN 'utility_inbox' ELSE NULL END;
    IF v_queue IS NULL OR p_msg_id < 1 THEN RAISE EXCEPTION 'Invalid inbox acknowledgement' USING ERRCODE='22023'; END IF;
    SELECT d.event_id,d.status INTO v_event_id,v_status FROM public.integration_delivery_logs d
     WHERE d.destination_system=p_destination AND d.queue_message_id=p_msg_id;
    IF v_status='processed' THEN RETURN TRUE; END IF;
    IF p_destination='LifeCycleTrack' THEN SELECT q.message INTO v_message FROM pgmq.q_lifecycle_inbox q WHERE q.msg_id=p_msg_id; END IF;
    IF p_destination='MaintainTrack' THEN SELECT q.message INTO v_message FROM pgmq.q_maintain_inbox q WHERE q.msg_id=p_msg_id; END IF;
    IF p_destination='UtilityTrack' THEN SELECT q.message INTO v_message FROM pgmq.q_utility_inbox q WHERE q.msg_id=p_msg_id; END IF;
    v_event_id := COALESCE(v_event_id,NULLIF(v_message->>'event_id','')::UUID);
    IF v_event_id IS NULL THEN RAISE EXCEPTION 'Inbox message has no event identifier'; END IF;
    IF p_destination='MaintainTrack' THEN v_archived:=pgmq.archive('maintain_inbox',p_msg_id);
    ELSIF p_destination='LifeCycleTrack' THEN v_archived:=pgmq.archive('lifecycle_inbox',p_msg_id);
    ELSE v_archived:=pgmq.archive('utility_inbox',p_msg_id); END IF;
    IF v_archived THEN
        UPDATE public.integration_delivery_logs SET status='processed',processed_at=now(),updated_at=now(),next_attempt_at=NULL
         WHERE event_id=v_event_id AND destination_system=p_destination;
    END IF;
    RETURN v_archived;
END;
$$;

CREATE OR REPLACE FUNCTION public.integration_deadletter_inbox(
    p_destination TEXT,p_msg_id BIGINT,p_message JSONB,p_reason TEXT
)
RETURNS BOOLEAN LANGUAGE plpgsql SECURITY DEFINER
SET search_path = public, pgmq, pg_temp
AS $$
DECLARE v_queue TEXT; v_archived BOOLEAN; v_event_id UUID; v_attempts INTEGER;
BEGIN
    v_queue:=CASE p_destination WHEN 'MaintainTrack' THEN 'maintain_inbox'
        WHEN 'LifeCycleTrack' THEN 'lifecycle_inbox' WHEN 'UtilityTrack' THEN 'utility_inbox' ELSE NULL END;
    IF v_queue IS NULL OR p_msg_id<1 OR jsonb_typeof(p_message)<>'object' THEN RAISE EXCEPTION 'Invalid dead-letter request' USING ERRCODE='22023'; END IF;
    v_event_id:=NULLIF(p_message->>'event_id','')::UUID;
    IF v_event_id IS NULL THEN RAISE EXCEPTION 'Dead-letter event has no event identifier'; END IF;
    IF p_destination='MaintainTrack' THEN v_archived:=pgmq.archive('maintain_inbox',p_msg_id);
    ELSIF p_destination='LifeCycleTrack' THEN v_archived:=pgmq.archive('lifecycle_inbox',p_msg_id);
    ELSE v_archived:=pgmq.archive('utility_inbox',p_msg_id); END IF;
    IF v_archived THEN
        PERFORM pgmq.send('central_sync_dead_letter', jsonb_build_object('destination',p_destination,'reason',left(COALESCE(p_reason,'delivery retries exhausted'),500),'failed_at',now(),'event',p_message));
        SELECT attempts INTO v_attempts FROM public.integration_delivery_logs WHERE event_id=v_event_id AND destination_system=p_destination;
        INSERT INTO public.failed_events(event_id,destination_system,attempts,failure_reason)
        VALUES(v_event_id,p_destination,COALESCE(v_attempts,0),left(COALESCE(p_reason,'delivery retries exhausted'),500));
        UPDATE public.integration_delivery_logs SET status='dead_lettered',dead_lettered_at=now(),last_error=left(COALESCE(p_reason,'delivery retries exhausted'),500),updated_at=now()
         WHERE event_id=v_event_id AND destination_system=p_destination;
    END IF;
    RETURN v_archived;
END;
$$;

REVOKE ALL ON FUNCTION public.integration_accept_event(JSONB,TEXT) FROM PUBLIC,anon,authenticated;
REVOKE ALL ON FUNCTION public.integration_route_events(INTEGER,INTEGER,INTEGER) FROM PUBLIC,anon,authenticated;
REVOKE ALL ON FUNCTION public.integration_read_inbox(TEXT,INTEGER,INTEGER) FROM PUBLIC,anon,authenticated;
REVOKE ALL ON FUNCTION public.integration_ack_inbox(TEXT,BIGINT) FROM PUBLIC,anon,authenticated;
REVOKE ALL ON FUNCTION public.integration_deadletter_inbox(TEXT,BIGINT,JSONB,TEXT) FROM PUBLIC,anon,authenticated;
GRANT EXECUTE ON FUNCTION public.integration_accept_event(JSONB,TEXT) TO service_role;
GRANT EXECUTE ON FUNCTION public.integration_route_events(INTEGER,INTEGER,INTEGER) TO service_role;
GRANT EXECUTE ON FUNCTION public.integration_read_inbox(TEXT,INTEGER,INTEGER) TO service_role;
GRANT EXECUTE ON FUNCTION public.integration_ack_inbox(TEXT,BIGINT) TO service_role;
GRANT EXECUTE ON FUNCTION public.integration_deadletter_inbox(TEXT,BIGINT,JSONB,TEXT) TO service_role;
