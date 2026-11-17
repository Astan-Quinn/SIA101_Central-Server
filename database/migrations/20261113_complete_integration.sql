-- Complete the shared event registry and Central operator APIs.
-- Apply after 20261111_integration_foundation.sql and 20261112_lifecycle_routing.sql.

ALTER TABLE public.integration_events
    ADD COLUMN IF NOT EXISTS operation TEXT NOT NULL DEFAULT 'upsert';
ALTER TABLE public.integration_delivery_logs
    ADD COLUMN IF NOT EXISTS last_error TEXT;

INSERT INTO public.subsystem_registry(subsystem_code,display_name)
VALUES ('CentralServer','Central Integration Server'),('LifeCycleTrack','LifeCycleTrack'),
       ('UtilityTrack','UtilityTrack'),('MaintainTrack','MaintainTrack')
ON CONFLICT(subsystem_code) DO UPDATE SET display_name=EXCLUDED.display_name,updated_at=now();

INSERT INTO public.event_registry (event_type,version,source_system,record_type) VALUES
    ('utility.bill.upserted','1.0','UtilityTrack','utility_bill'),
    ('utility.bill.deleted','1.0','UtilityTrack','utility_bill'),
    ('utility.anomaly.upserted','1.0','UtilityTrack','utility_anomaly'),
    ('utility.anomaly.deleted','1.0','UtilityTrack','utility_anomaly'),
    ('maintenance.work_order.upserted','1.0','MaintainTrack','maintenance_request'),
    ('maintenance.work_order.deleted','1.0','MaintainTrack','maintenance_request'),
    ('maintenance.result.recorded','1.0','MaintainTrack','maintenance_update')
ON CONFLICT (event_type,version,source_system) DO UPDATE SET enabled=TRUE;

INSERT INTO public.event_subscriptions (source_system,event_type,destination_system) VALUES
    ('LifeCycleTrack','lifecycle.asset.upserted','UtilityTrack'),
    ('LifeCycleTrack','lifecycle.asset.deleted','UtilityTrack'),
    ('UtilityTrack','utility.bill.upserted','LifeCycleTrack'),
    ('UtilityTrack','utility.bill.deleted','LifeCycleTrack'),
    ('MaintainTrack','maintenance.work_order.upserted','LifeCycleTrack'),
    ('MaintainTrack','maintenance.work_order.deleted','LifeCycleTrack'),
    ('MaintainTrack','maintenance.result.recorded','LifeCycleTrack')
ON CONFLICT (source_system,event_type,destination_system) DO UPDATE SET enabled=TRUE;

CREATE TABLE IF NOT EXISTS public.sync_reconciliation_runs (
    run_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    source_system TEXT NOT NULL CHECK (source_system IN ('LifeCycleTrack','UtilityTrack','MaintainTrack')),
    record_type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'running' CHECK (status IN ('running','completed','failed')),
    checkpoint TEXT,
    scanned_count BIGINT NOT NULL DEFAULT 0,
    enqueued_count BIGINT NOT NULL DEFAULT 0,
    error_summary TEXT,
    started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    finished_at TIMESTAMPTZ
);

CREATE OR REPLACE FUNCTION public.integration_accept_event(p_event JSONB,p_authenticated_source TEXT)
RETURNS JSONB LANGUAGE plpgsql SECURITY DEFINER
SET search_path=public,pgmq,pg_temp AS $$
DECLARE
    v_event_id UUID;
    v_correlation UUID;
    v_occurred_at TIMESTAMPTZ;
    v_source TEXT:=p_event->>'source';
    v_event_type TEXT:=p_event->>'event_type';
    v_version TEXT:=p_event->>'version';
    v_record_type TEXT:=p_event->>'record_type';
    v_source_id TEXT:=p_event->>'source_id';
    v_payload JSONB:=p_event->'payload';
    v_operation TEXT;
    v_destinations INTEGER:=0;
    r RECORD;
    v_msg BIGINT;
BEGIN
    BEGIN
        v_event_id:=(p_event->>'event_id')::UUID;
        v_correlation:=NULLIF(p_event->>'correlation_id','')::UUID;
        v_occurred_at:=(p_event->>'occurred_at')::TIMESTAMPTZ;
    EXCEPTION WHEN OTHERS THEN
        RAISE EXCEPTION 'Malformed event identifiers or timestamp' USING ERRCODE='22023';
    END;
    IF jsonb_typeof(p_event)<>'object' OR v_source IS DISTINCT FROM p_authenticated_source
       OR v_source NOT IN ('LifeCycleTrack','UtilityTrack','MaintainTrack')
       OR v_source_id IS NULL OR v_source_id='' OR length(v_source_id)>100
       OR v_version IS DISTINCT FROM '1.0' OR v_occurred_at IS NULL
       OR jsonb_typeof(v_payload)<>'object'
       OR NOT EXISTS (SELECT 1 FROM public.event_registry e WHERE e.event_type=v_event_type
            AND e.version=v_version AND e.source_system=v_source AND e.record_type=v_record_type AND e.enabled)
       OR (v_record_type='lifecycle_asset' AND (v_source<>'LifeCycleTrack' OR v_event_type NOT IN ('lifecycle.asset.upserted','lifecycle.asset.deleted') OR v_payload->>'id' IS DISTINCT FROM v_source_id OR v_payload->>'entity_type' NOT IN ('equipment','facility')))
       OR (v_record_type='utility_bill' AND (v_source<>'UtilityTrack' OR v_event_type NOT IN ('utility.bill.upserted','utility.bill.deleted') OR v_payload->>'id' IS DISTINCT FROM v_source_id))
       OR (v_record_type='utility_anomaly' AND (v_source<>'UtilityTrack' OR v_event_type NOT IN ('utility.anomaly.upserted','utility.anomaly.deleted') OR v_payload->>'id' IS DISTINCT FROM v_source_id))
       OR (v_record_type='maintenance_request' AND (v_source<>'MaintainTrack' OR v_event_type NOT IN ('maintenance.work_order.upserted','maintenance.work_order.deleted') OR v_payload->>'id' IS DISTINCT FROM v_source_id))
       OR (v_record_type='maintenance_update' AND (v_source<>'MaintainTrack' OR v_event_type<>'maintenance.result.recorded' OR v_payload->>'id' IS DISTINCT FROM v_source_id)) THEN
        RAISE EXCEPTION 'Event does not match an enabled source contract' USING ERRCODE='22023';
    END IF;

    v_operation:=CASE WHEN v_event_type LIKE '%.deleted' THEN 'delete' ELSE 'upsert' END;
    INSERT INTO public.integration_events(event_id,event_type,source_system,destination_system,version,correlation_id,occurred_at,source_id,record_type,payload,operation)
    VALUES(v_event_id,v_event_type,v_source,NULL,v_version,v_correlation,v_occurred_at,v_source_id,v_record_type,v_payload,v_operation)
    ON CONFLICT(event_id) DO NOTHING;
    IF NOT FOUND THEN
        RETURN jsonb_build_object('accepted',true,'duplicate',true,'event_id',v_event_id,'routed',0);
    END IF;

    IF v_record_type='lifecycle_asset' THEN
        INSERT INTO public.central_lifecycle_assets AS a(source_system,source_id,payload,source_updated_at,received_at,updated_at,deleted_at)
        VALUES(v_source,v_source_id,v_payload,v_occurred_at,now(),now(),CASE WHEN v_operation='delete' THEN v_occurred_at ELSE NULL END)
        ON CONFLICT(source_system,source_id) DO UPDATE SET
            payload=CASE WHEN EXCLUDED.deleted_at IS NULL THEN EXCLUDED.payload ELSE a.payload END,
            source_updated_at=EXCLUDED.source_updated_at,received_at=now(),updated_at=now(),deleted_at=EXCLUDED.deleted_at
        WHERE a.source_updated_at<EXCLUDED.source_updated_at;
    END IF;

    FOR r IN SELECT destination_system FROM public.event_subscriptions
        WHERE source_system=v_source AND event_type=v_event_type AND enabled ORDER BY destination_system LOOP
        INSERT INTO public.integration_delivery_logs(event_id,source_system,destination_system,event_type,status,attempts,correlation_id)
        VALUES(v_event_id,v_source,r.destination_system,v_event_type,'queued',0,v_correlation)
        ON CONFLICT(event_id,destination_system) DO NOTHING;
        IF r.destination_system='MaintainTrack' THEN
            v_msg:=pgmq.send('maintain_inbox',p_event||jsonb_build_object('destination',r.destination_system));
        ELSIF r.destination_system='LifeCycleTrack' THEN
            v_msg:=pgmq.send('lifecycle_inbox',p_event||jsonb_build_object('destination',r.destination_system));
        ELSE
            v_msg:=pgmq.send('utility_inbox',p_event||jsonb_build_object('destination',r.destination_system));
        END IF;
        UPDATE public.integration_delivery_logs SET queue_message_id=v_msg,updated_at=now()
        WHERE event_id=v_event_id AND destination_system=r.destination_system AND queue_message_id IS NULL;
        v_destinations:=v_destinations+1;
    END LOOP;
    RETURN jsonb_build_object('accepted',true,'duplicate',false,'event_id',v_event_id,'routed',v_destinations);
END $$;

CREATE OR REPLACE FUNCTION public.integration_replay_dead_letter(p_failed_event_id BIGINT,p_actor TEXT)
RETURNS BOOLEAN LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pgmq,pg_temp AS $$
DECLARE f RECORD; e RECORD; q TEXT; v_msg BIGINT;
BEGIN
    SELECT * INTO f FROM public.failed_events WHERE failed_event_id=p_failed_event_id AND replayed_at IS NULL FOR UPDATE;
    IF NOT FOUND OR f.destination_system IS NULL THEN RETURN FALSE; END IF;
    SELECT * INTO e FROM public.integration_events WHERE event_id=f.event_id;
    IF NOT FOUND THEN RETURN FALSE; END IF;
    q:=CASE f.destination_system WHEN 'MaintainTrack' THEN 'maintain_inbox' WHEN 'LifeCycleTrack' THEN 'lifecycle_inbox' WHEN 'UtilityTrack' THEN 'utility_inbox' ELSE NULL END;
    IF q IS NULL THEN RETURN FALSE; END IF;
    v_msg:=pgmq.send(q,jsonb_build_object('event_id',e.event_id,'event_type',e.event_type,'source',e.source_system,'destination',f.destination_system,'version',e.version,'correlation_id',e.correlation_id,'occurred_at',e.occurred_at,'source_id',e.source_id,'record_type',e.record_type,'payload',e.payload));
    UPDATE public.integration_delivery_logs SET status='queued',attempts=0,next_attempt_at=NULL,dead_lettered_at=NULL,last_error=NULL,queue_message_id=v_msg,updated_at=now()
      WHERE event_id=f.event_id AND destination_system=f.destination_system;
    UPDATE public.failed_events SET replayed_at=now(),replayed_by=left(p_actor,120) WHERE failed_event_id=f.failed_event_id;
    RETURN TRUE;
END $$;

ALTER TABLE public.sync_reconciliation_runs ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public.sync_reconciliation_runs FROM anon,authenticated;
GRANT ALL ON public.sync_reconciliation_runs TO service_role;
REVOKE ALL ON FUNCTION public.integration_accept_event(JSONB,TEXT) FROM PUBLIC,anon,authenticated;
GRANT EXECUTE ON FUNCTION public.integration_accept_event(JSONB,TEXT) TO service_role;
REVOKE ALL ON FUNCTION public.integration_replay_dead_letter(BIGINT,TEXT) FROM PUBLIC,anon,authenticated;
GRANT EXECUTE ON FUNCTION public.integration_replay_dead_letter(BIGINT,TEXT) TO service_role;
