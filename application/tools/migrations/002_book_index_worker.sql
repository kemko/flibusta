CREATE INDEX IF NOT EXISTS book_archive_entries_worker_idx ON book_archive_entries (scan_state, scanned_at, entry_id);
CREATE INDEX IF NOT EXISTS book_archive_entries_metadata_idx ON book_archive_entries (bookid, scan_state, entry_id);
