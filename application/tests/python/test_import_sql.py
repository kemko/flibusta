import gzip
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import zipfile


class ImportSqlTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.sql = self.root / 'sql'
        self.sql.mkdir()
        tools = self.root / 'tools'
        tools.mkdir()
        source = Path(__file__).resolve().parents[2] / 'tools'
        (tools / 'app_import_sql.sh').write_text(
            (source / 'app_import_sql.sh').read_text().replace('/application', str(self.root)))
        (tools / 'dbinit.sh').write_text('export SQL_CMD=true\n')
        (tools / 'app_topg').write_text('#!/bin/sh\necho "$1" >> "' + str(self.root / 'imports') + '"\n')
        (tools / 'app_topg').chmod(0o755)
        (tools / 'php').write_text('#!/bin/sh\nexit 0\n')
        (tools / 'php').chmod(0o755)
        self.env = {**os.environ, 'PATH': str(tools) + os.pathsep + os.environ['PATH']}
        self.script = tools / 'app_import_sql.sh'

    def run_import(self):
        return subprocess.run(['sh', str(self.script)], env=self.env, capture_output=True, text=True)

    def test_plain_sql_without_gzip(self):
        dump = self.sql / 'lib.libbook.sql'
        dump.write_text('INSERT plain;\n')
        result = self.run_import()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(dump.read_text(), 'INSERT plain;\n')
        self.assertIn('lib.libbook.sql', (self.root / 'imports').read_text())

    def test_gzip_replaces_old_sql_and_unrelated_gzip_is_untouched(self):
        dump = self.sql / 'lib.libbook.sql'
        dump.write_text('old')
        compressed = self.sql / 'lib.libbook.sql.gz'
        compressed.write_bytes(gzip.compress(b'INSERT new;\n'))
        unrelated = self.sql / 'other.gz'
        unrelated.write_bytes(b'not a SQL dump')
        result = self.run_import()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(dump.read_bytes(), b'INSERT new;\n')
        self.assertFalse(compressed.exists())
        self.assertTrue(unrelated.exists())

    def test_corrupt_gzip_stops_before_table_import(self):
        (self.sql / 'lib.libbook.sql.gz').write_bytes(b'broken gzip')
        result = self.run_import()
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.root / 'imports').exists())
        self.assertIn('Импорт остановлен', (self.sql / 'status').read_text())

    def test_attached_archives_are_copied_and_replaced(self):
        cache = self.root / 'cache'
        cache.mkdir()
        for name in ('lib.a.attached.zip', 'lib.b.attached.zip'):
            (cache / name).write_bytes(b'old archive')
            with zipfile.ZipFile(self.sql / name, 'w') as archive:
                archive.writestr('nested/photo.jpg', b'image')
        (self.sql / 'books.zip').write_bytes(b'unrelated')
        result = self.run_import()
        self.assertEqual(result.returncode, 0, result.stderr)
        for name in ('lib.a.attached.zip', 'lib.b.attached.zip'):
            self.assertEqual((cache / name).read_bytes(), (self.sql / name).read_bytes())
        self.assertFalse((cache / 'books.zip').exists())
        self.assertEqual(list(cache.glob('.*.zip.*')), [])

    def test_missing_attached_archive_preserves_existing_copy(self):
        cache = self.root / 'cache'
        cache.mkdir()
        archive = cache / 'lib.a.attached.zip'
        archive.write_bytes(b'existing')
        result = self.run_import()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(archive.read_bytes(), b'existing')
        self.assertFalse((cache / 'lib.b.attached.zip').exists())

    def test_failed_copy_preserves_old_archive_and_stops_import(self):
        cache = self.root / 'cache'
        cache.mkdir()
        name = 'lib.a.attached.zip'
        (cache / name).write_bytes(b'old archive')
        (self.sql / name).write_bytes(b'new archive')
        copy = self.root / 'tools' / 'cp'
        copy.write_text('#!/bin/sh\necho partial > "$2"\nexit 1\n')
        copy.chmod(0o755)
        result = self.run_import()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((cache / name).read_bytes(), b'old archive')
        self.assertEqual((self.sql / name).read_bytes(), b'new archive')
        self.assertFalse((self.root / 'imports').exists())
        self.assertIn('Ошибка копирования ' + name, (self.sql / 'status').read_text())
        self.assertEqual(list(cache.glob('.*.zip.*')), [])
