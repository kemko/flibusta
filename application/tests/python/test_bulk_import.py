import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import uuid


@unittest.skipUnless(os.environ.get('FLIBUSTA_DBHOST'), 'Requires the PostgreSQL test stack')
class BulkImportTest(unittest.TestCase):
    def setUp(self):
        self.schema = 'import_test_' + uuid.uuid4().hex
        self.env = {
            **os.environ,
            'PGHOST': os.environ['FLIBUSTA_DBHOST'],
            'PGDATABASE': os.environ['FLIBUSTA_DBNAME'],
            'PGUSER': os.environ['FLIBUSTA_DBUSER'],
            'PGPASSWORD': os.environ['FLIBUSTA_DBPASSWORD'],
            'PGOPTIONS': '-c search_path=' + self.schema,
            'SQL_CMD': 'psql',
        }
        self.sql('CREATE SCHEMA ' + self.schema)
        self.addCleanup(self.sql, 'DROP SCHEMA ' + self.schema + ' CASCADE')
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'sql' / 'psql').mkdir(parents=True)
        source = Path(__file__).resolve().parents[2] / 'tools'
        tools = self.root / 'tools'
        tools.mkdir()
        for name in ('app_db_converter.py', 'import_prepare.sql', 'import_finish.sql'):
            shutil.copyfile(source / name, tools / name)
        (tools / 'app_topg').write_text(
            (source / 'app_topg').read_text().replace('/application', str(self.root)))
        self.sql(r'''
            CREATE TABLE books (id integer PRIMARY KEY, title text UNIQUE);
            INSERT INTO books VALUES (1, 'old');
            CREATE INDEX "title expression" ON books (regexp_replace(lower(title), '\s+', '', 'g'))
                INCLUDE (id) WITH (fillfactor=80) WHERE id > 0;
            COMMENT ON INDEX "title expression" IS 'Reader''s index\metadata';
            ALTER INDEX "title expression" ALTER COLUMN 1 SET STATISTICS 123;
            CREATE FUNCTION check_import() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT relpersistence FROM pg_class WHERE oid = TG_RELID) <> 'u'
                   OR current_setting('synchronous_commit') <> 'off'
                   OR EXISTS (SELECT 1 FROM pg_index WHERE indrelid = TG_RELID AND NOT indisunique)
                   OR (SELECT count(*) FROM pg_index WHERE indrelid = TG_RELID AND indisunique) <> 2
                THEN RAISE EXCEPTION 'Import optimizations not applied'; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER check_import BEFORE INSERT ON books
                FOR EACH ROW EXECUTE FUNCTION check_import();
        ''')
        self.original_indexes = self.indexes()

    def sql(self, query):
        result = subprocess.run(['psql', '-X', '-At', '-v', 'ON_ERROR_STOP=1', '-c', query],
                                env=self.env, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stdout.strip()

    def indexes(self):
        return self.sql('''
            SELECT pg_get_indexdef(indexrelid), obj_description(indexrelid, 'pg_class'),
                   (SELECT string_agg(attnum || ':' || attstattarget, ',' ORDER BY attnum)
                    FROM pg_attribute WHERE attrelid = indexrelid AND attnum > 0)
            FROM pg_index WHERE indrelid = 'books'::regclass ORDER BY indexrelid::regclass::text
        ''')

    def run_import(self, inserts):
        (self.root / 'sql' / 'books.sql').write_text(
            'CREATE TABLE `books` (\n' + inserts)
        return subprocess.run(['sh', str(self.root / 'tools' / 'app_topg'), 'books.sql'],
                              env=self.env, capture_output=True, text=True)

    def assert_restored(self, rows):
        self.assertEqual(self.sql('SELECT id, title FROM books ORDER BY id'), rows)
        self.assertEqual(self.indexes(), self.original_indexes)
        self.assertEqual(self.sql("SELECT relpersistence FROM pg_class WHERE oid = 'books'::regclass"), 'p')

    def test_success_and_repeat_restore_index_definitions_and_metadata(self):
        for value in (2, 3):
            result = self.run_import(f"INSERT INTO `books` VALUES ({value}, 'new');\n")
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assert_restored(f'{value}|new')

    def test_empty_dump_preserves_existing_table(self):
        result = self.run_import('')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assert_restored('1|old')

    def test_insert_error_rolls_back_data_indexes_and_persistence(self):
        result = self.run_import("INSERT INTO `books` VALUES (2, 'new'), (2, 'duplicate');\n")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('duplicate key', result.stderr)
        self.assert_restored('1|old')

    def test_index_rebuild_error_rolls_back_everything(self):
        self.sql('CREATE INDEX division_index ON books ((10 / id))')
        self.original_indexes = self.indexes()
        result = self.run_import("INSERT INTO `books` VALUES (0, 'new');\n")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('division by zero', result.stderr)
        self.assert_restored('1|old')

    def test_connection_loss_rolls_back_everything(self):
        self.sql('''CREATE OR REPLACE FUNCTION check_import() RETURNS trigger
                    LANGUAGE plpgsql AS $$ BEGIN
                    PERFORM pg_terminate_backend(pg_backend_pid()); RETURN NEW;
                    END $$''')
        result = self.run_import("INSERT INTO `books` VALUES (2, 'new');\n")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('terminating connection', result.stderr)
        self.assert_restored('1|old')


class ImportFailureTest(unittest.TestCase):
    def test_table_failure_stops_parent_import_and_keeps_error_status(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'sql').mkdir()
            tools = root / 'tools'
            tools.mkdir()
            source = Path(__file__).resolve().parents[2] / 'tools'
            script = tools / 'app_import_sql.sh'
            script.write_text((source / script.name).read_text().replace('/application', str(root)))
            (tools / 'dbinit.sh').write_text('export SQL_CMD=true\n')
            (tools / 'php').write_text('#!/bin/sh\nexit 0\n')
            (tools / 'php').chmod(0o755)
            (tools / 'app_topg').write_text(
                '#!/bin/sh\necho "$1" >> "' + str(root / 'imports') + '"\nexit 1\n')
            (tools / 'app_topg').chmod(0o755)
            result = subprocess.run(['sh', str(script)], capture_output=True, text=True,
                                    env={**os.environ, 'PATH': str(tools) + os.pathsep + os.environ['PATH']})
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual((root / 'imports').read_text().splitlines(), ['lib.a.annotations_pics.sql'])
            self.assertIn('Импорт остановлен', (root / 'sql' / 'status').read_text())
