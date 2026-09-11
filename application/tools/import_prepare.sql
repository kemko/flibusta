SET LOCAL synchronous_commit = off;
SET LOCAL standard_conforming_strings = on;
TRUNCATE :"import_table";

-- Keep definitions in psql memory; the surrounding transaction also protects DDL.
-- Preserve uniqueness, constraints, clustering and replica identity during loading.
SELECT coalesce(string_agg(
           pg_get_indexdef(i.indexrelid) || ';' ||
           format('COMMENT ON INDEX %s IS %L;', i.indexrelid::regclass,
                  obj_description(i.indexrelid, 'pg_class')) ||
           coalesce((SELECT string_agg(format('ALTER INDEX %s ALTER COLUMN %s SET STATISTICS %s;',
                       i.indexrelid::regclass, a.attnum, a.attstattarget), '')
                     FROM pg_attribute a
                     WHERE a.attrelid = i.indexrelid AND a.attstattarget >= 0), ''), E'\n'), '') AS restore_indexes,
       coalesce(string_agg(format('DROP INDEX %s;', i.indexrelid::regclass), E'\n'), '') AS drop_indexes
FROM pg_index i
WHERE i.indrelid = :'import_table'::regclass
  AND NOT i.indisunique AND NOT i.indisprimary AND NOT i.indisexclusion
  AND NOT i.indisclustered AND NOT i.indisreplident
  AND NOT EXISTS (SELECT 1 FROM pg_constraint c WHERE c.conindid = i.indexrelid)
\gset

:drop_indexes
ALTER TABLE :"import_table" SET UNLOGGED;
