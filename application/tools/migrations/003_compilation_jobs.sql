ALTER TABLE compilation_jobs ADD COLUMN IF NOT EXISTS request_token varchar(64);
CREATE UNIQUE INDEX IF NOT EXISTS compilation_jobs_owner_request_idx ON compilation_jobs (owner_hash, request_token) WHERE request_token IS NOT NULL;
CREATE INDEX IF NOT EXISTS compilation_jobs_worker_idx ON compilation_jobs (state, started_at, created_at);
