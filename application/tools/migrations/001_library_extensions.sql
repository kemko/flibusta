CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE TABLE IF NOT EXISTS book_archives (
    archive_id bigserial PRIMARY KEY,
    filename varchar(255) NOT NULL UNIQUE,
    size_bytes bigint NOT NULL DEFAULT 0,
    modified_at timestamptz,
    fingerprint varchar(128) NOT NULL DEFAULT '',
    scan_state varchar(16) NOT NULL DEFAULT 'pending',
    scan_error text,
    scanned_at timestamptz
);

CREATE TABLE IF NOT EXISTS book_archive_entries (
    entry_id bigserial PRIMARY KEY,
    archive_id bigint NOT NULL,
    entry_name text NOT NULL,
    bookid bigint,
    format varchar(8) NOT NULL DEFAULT '',
    size_bytes bigint NOT NULL DEFAULT 0,
    content_hash varchar(128) NOT NULL DEFAULT '',
    scan_state varchar(16) NOT NULL DEFAULT 'pending',
    scan_error text,
    scanned_at timestamptz,
    UNIQUE (archive_id, entry_name)
);

CREATE INDEX IF NOT EXISTS book_archive_entries_bookid_idx ON book_archive_entries (bookid);

CREATE TABLE IF NOT EXISTS book_extracted_metadata (
    entry_id bigint PRIMARY KEY,
    illustration_count integer,
    translators jsonb NOT NULL DEFAULT '[]'::jsonb,
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    extracted_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    extraction_error text
);

CREATE TABLE IF NOT EXISTS author_search_index (
    author_id bigint NOT NULL,
    canonical_author_id bigint NOT NULL,
    normalized_name text NOT NULL,
    source varchar(16) NOT NULL,
    PRIMARY KEY (author_id, normalized_name, source)
);

CREATE INDEX IF NOT EXISTS author_search_index_name_trgm_idx ON author_search_index USING gin (normalized_name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS author_search_index_canonical_idx ON author_search_index (canonical_author_id);

CREATE TABLE IF NOT EXISTS compilation_jobs (
    job_id uuid PRIMARY KEY,
    owner_hash varchar(128) NOT NULL,
    title text NOT NULL,
    source_snapshot jsonb NOT NULL,
    state varchar(16) NOT NULL DEFAULT 'queued',
    error text,
    result_path text,
	request_token varchar(64),
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at timestamptz,
    completed_at timestamptz,
    expires_at timestamptz
);

CREATE INDEX IF NOT EXISTS compilation_jobs_owner_idx ON compilation_jobs (owner_hash, created_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS compilation_jobs_owner_request_idx ON compilation_jobs (owner_hash, request_token) WHERE request_token IS NOT NULL;

CREATE TABLE IF NOT EXISTS opds_keys (
    key_id varchar(64) PRIMARY KEY,
    secret_hash varchar(255) NOT NULL,
    owner_hash varchar(128) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at timestamptz,
    last_used_at timestamptz
);

CREATE INDEX IF NOT EXISTS opds_keys_owner_idx ON opds_keys (owner_hash, created_at DESC);
