-- Run this in the Central Server Supabase project's SQL Editor.
CREATE TABLE IF NOT EXISTS public.central_records (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_system TEXT NOT NULL CHECK (source_system IN ('UtilityTrack', 'LifeCycleTrack')),
    record_type TEXT NOT NULL CHECK (record_type IN ('utility_bill', 'utility_anomaly', 'lifecycle_asset')),
    source_id TEXT NOT NULL,
    payload JSONB NOT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at TIMESTAMPTZ NULL,
    CONSTRAINT central_records_source_identity UNIQUE (source_system, record_type, source_id)
);
CREATE INDEX IF NOT EXISTS central_records_type_received_idx
    ON public.central_records (record_type, received_at DESC);
CREATE INDEX IF NOT EXISTS central_records_active_idx
    ON public.central_records (source_system, record_type, updated_at DESC)
    WHERE deleted_at IS NULL;
ALTER TABLE public.central_records ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE public.central_records FROM anon, authenticated;
GRANT ALL ON TABLE public.central_records TO service_role;
GRANT USAGE, SELECT ON SEQUENCE public.central_records_id_seq TO service_role;
