-- Restore persistence before rebuilding indexes to avoid rewriting them twice.
ALTER TABLE :"import_table" SET LOGGED;
SET LOCAL standard_conforming_strings = on;
:restore_indexes
