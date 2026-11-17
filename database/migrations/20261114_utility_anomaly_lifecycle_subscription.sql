-- Utility anomaly snapshots are read-only references in LifeCycleTrack.
-- UtilityTrack remains the sole authority for anomaly records.
INSERT INTO public.event_subscriptions(source_system,event_type,destination_system)
VALUES ('UtilityTrack','utility.anomaly.upserted','LifeCycleTrack'),
       ('UtilityTrack','utility.anomaly.deleted','LifeCycleTrack')
ON CONFLICT(source_system,event_type,destination_system) DO UPDATE SET enabled=TRUE;

CREATE OR REPLACE FUNCTION public.integration_replay_accepted_event(p_event_id UUID,p_destination TEXT)
RETURNS BOOLEAN LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pgmq,pg_temp AS $$
DECLARE e RECORD; v_queue_id BIGINT;
BEGIN
    IF p_destination NOT IN ('LifeCycleTrack','MaintainTrack','UtilityTrack') THEN
        RAISE EXCEPTION 'Invalid integration replay destination' USING ERRCODE='22023';
    END IF;
    SELECT * INTO e FROM public.integration_events WHERE event_id=p_event_id;
    IF NOT FOUND OR NOT EXISTS (
        SELECT 1 FROM public.event_subscriptions s
        WHERE s.source_system=e.source_system AND s.event_type=e.event_type
          AND s.destination_system=p_destination AND s.enabled
    ) THEN RETURN FALSE; END IF;

    INSERT INTO public.integration_delivery_logs(event_id,source_system,destination_system,event_type,status,attempts,correlation_id)
    VALUES(e.event_id,e.source_system,p_destination,e.event_type,'queued',0,e.correlation_id)
    ON CONFLICT(event_id,destination_system) DO NOTHING;
    IF NOT FOUND THEN RETURN FALSE; END IF;

    IF p_destination='MaintainTrack' THEN
        v_queue_id:=pgmq.send('maintain_inbox',jsonb_build_object('event_id',e.event_id,'event_type',e.event_type,'source',e.source_system,'destination',p_destination,'version',e.version,'correlation_id',e.correlation_id,'occurred_at',e.occurred_at,'source_id',e.source_id,'record_type',e.record_type,'payload',e.payload));
    ELSIF p_destination='LifeCycleTrack' THEN
        v_queue_id:=pgmq.send('lifecycle_inbox',jsonb_build_object('event_id',e.event_id,'event_type',e.event_type,'source',e.source_system,'destination',p_destination,'version',e.version,'correlation_id',e.correlation_id,'occurred_at',e.occurred_at,'source_id',e.source_id,'record_type',e.record_type,'payload',e.payload));
    ELSE
        v_queue_id:=pgmq.send('utility_inbox',jsonb_build_object('event_id',e.event_id,'event_type',e.event_type,'source',e.source_system,'destination',p_destination,'version',e.version,'correlation_id',e.correlation_id,'occurred_at',e.occurred_at,'source_id',e.source_id,'record_type',e.record_type,'payload',e.payload));
    END IF;
    UPDATE public.integration_delivery_logs SET queue_message_id=v_queue_id,updated_at=now()
    WHERE event_id=e.event_id AND destination_system=p_destination AND queue_message_id IS NULL;
    RETURN TRUE;
END $$;

REVOKE ALL ON FUNCTION public.integration_replay_accepted_event(UUID,TEXT) FROM PUBLIC,anon,authenticated;
GRANT EXECUTE ON FUNCTION public.integration_replay_accepted_event(UUID,TEXT) TO service_role;
