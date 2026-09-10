CREATE TABLE IF NOT EXISTS compilation_mail_requests (
    request_id uuid PRIMARY KEY,
    job_id uuid NOT NULL,
    owner_hash varchar(128) NOT NULL,
    request_token varchar(64) NOT NULL,
    state varchar(16) NOT NULL DEFAULT 'queued',
    error text,
    accepted_at timestamptz,
    started_at timestamptz,
    completed_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (owner_hash, request_token)
);

CREATE INDEX IF NOT EXISTS compilation_mail_requests_worker_idx ON compilation_mail_requests (state, created_at);
CREATE INDEX IF NOT EXISTS compilation_mail_requests_job_idx ON compilation_mail_requests (job_id, created_at DESC);
