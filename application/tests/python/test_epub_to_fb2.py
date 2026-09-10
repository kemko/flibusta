import importlib.util
import io
import json
import pathlib
import tempfile
import types
import unittest
import xml.etree.ElementTree as ET
import zipfile
from unittest import mock


SCRIPT = pathlib.Path(__file__).parents[2] / 'tools' / 'epub_to_fb2.py'
SPEC = importlib.util.spec_from_file_location('epub_to_fb2', SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class EpubToFb2Test(unittest.TestCase):
    def make_epub(self, directory, extra=''):
        source = pathlib.Path(directory) / 'source.epub'
        with zipfile.ZipFile(source, 'w') as archive:
            archive.writestr('META-INF/container.xml', '<container><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>')
            archive.writestr('OEBPS/content.opf', '<package><metadata/><manifest><item id="chapter" href="chapter.xhtml" media-type="application/xhtml+xml"/><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/></manifest><spine><itemref idref="chapter"/></spine></package>')
            archive.writestr('OEBPS/nav.xhtml', '<html><body><nav type="toc"><ol><li><a href="chapter.xhtml#one">One</a><ol><li><a href="chapter.xhtml#note">Note</a></li></ol></li></ol></nav></body></html>')
            archive.writestr('OEBPS/chapter.xhtml', '<html><body><p id="one">One anchor <a href="#note">jump</a></p><p id="note">Note anchor</p><p>%s</p></body></html>' % extra)
        return source

    def test_resolves_only_safe_relative_paths(self):
        self.assertEqual('OEBPS/text/chapter.xhtml', MODULE.resolve_path('OEBPS/text', 'chapter.xhtml#one'))
        with self.assertRaises(MODULE.ConversionError):
            MODULE.resolve_path('OEBPS', '../../secret.xhtml')

    def test_navigation_keeps_duplicate_targets_and_nesting(self):
        nav = MODULE.xml_document(b'<nav><ol><li><a href="chapter.xhtml#one">One</a><ol><li><a href="chapter.xhtml#one">Again</a></li></ol></li></ol></nav>', 'nav')
        toc = MODULE.nav_tree(nav, 'OEBPS')
        self.assertEqual('OEBPS/chapter.xhtml', toc[0]['target'])
        self.assertEqual('Again', toc[0]['children'][0]['title'])

    def test_calibre_command_is_an_argument_list(self):
        command = MODULE.calibre_command('/tmp/source.epub', '/tmp/result.fb2')
        self.assertEqual('ebook-convert', command[0])
        self.assertNotIn(' ', command[0])
        self.assertIn('/tmp/source.epub', command)

    def test_adapter_restores_anchors_links_and_original_navigation(self):
        with tempfile.TemporaryDirectory() as directory:
            source = pathlib.Path(directory) / 'source.epub'
            result = pathlib.Path(directory) / 'result.fb2'
            with zipfile.ZipFile(source, 'w') as archive:
                archive.writestr('OEBPS/chapter.xhtml', '<html><body><section id="one"><p id="note">A note points <a href="#one">back</a>.</p></section></body></html>')
            result.write_text('<?xml version="1.0"?><FictionBook xmlns="http://www.gribuser.ru/xml/fictionbook/2.0"><body><section><p>A note points back.</p></section></body></FictionBook>')
            inspection = {
                'spine': ['OEBPS/chapter.xhtml'],
                'toc': [{'title': 'First', 'href': 'chapter.xhtml#one', 'target': 'OEBPS/chapter.xhtml', 'children': [
                    {'title': 'Again', 'href': 'chapter.xhtml#one', 'target': 'OEBPS/chapter.xhtml', 'children': []},
                    {'title': 'Note', 'href': 'chapter.xhtml#note', 'target': 'OEBPS/chapter.xhtml', 'children': []},
                ]}],
            }
            MODULE.adapt_fb2(result, source, inspection)
            root = ET.parse(result).getroot()
            identifiers = MODULE.fb2_ids(root)
            hrefs = [MODULE.attribute(node, 'href') for node in root.iter() if MODULE.attribute(node, 'href')]
            self.assertTrue({'one', 'note', 'toc-0', 'toc-0-0', 'toc-0-1'} <= identifiers)
            self.assertEqual(3, hrefs.count('#one'))
            self.assertIn('#note', hrefs)

    def test_no_navigation_does_not_add_a_body(self):
        root = ET.fromstring('<FictionBook xmlns="http://www.gribuser.ru/xml/fictionbook/2.0"><body/></FictionBook>')
        MODULE.add_navigation(root, [])
        self.assertEqual(1, len([node for node in root if MODULE.local_name(node.tag) == 'body']))

    def test_inspects_epub_and_reads_words_and_references(self):
        with tempfile.TemporaryDirectory() as directory:
            source = self.make_epub(directory)
            inspection = MODULE.inspect_epub(source)
            self.assertEqual(['OEBPS/chapter.xhtml'], inspection['spine'])
            self.assertEqual('One', inspection['toc'][0]['title'])
            self.assertEqual({'one', 'note'}, set(MODULE.source_references(source, inspection)[0]))
            self.assertIn('anchor', MODULE.source_words(source, inspection))

    def test_validates_links_and_rejects_dangling_ones(self):
        with tempfile.TemporaryDirectory() as directory:
            result = pathlib.Path(directory) / 'result.fb2'
            result.write_text('<FictionBook><body><section id="one"><p>text</p></section></body></FictionBook>')
            MODULE.validate_fb2(result)
            result.write_text('<FictionBook><body><p><a href="#missing">broken</a></p></body></FictionBook>')
            with self.assertRaises(MODULE.ConversionError):
                MODULE.validate_fb2(result)

    def test_convert_uses_calibre_result_and_publishes_atomically(self):
        with tempfile.TemporaryDirectory() as directory:
            source = self.make_epub(directory)
            output = pathlib.Path(directory) / 'nested' / 'result.fb2'

            def calibre(command, **_):
                pathlib.Path(command[2]).write_text('<FictionBook xmlns="http://www.gribuser.ru/xml/fictionbook/2.0"><body><p>One anchor jump</p><p>Note anchor</p></body></FictionBook>')
                return types.SimpleNamespace(returncode=0, stderr='', stdout='')

            with mock.patch.object(MODULE.subprocess, 'run', side_effect=calibre):
                MODULE.convert(source, output, 1, 64, directory, None)
            root = ET.parse(output).getroot()
            self.assertTrue({'one', 'note'} <= MODULE.fb2_ids(root))

    def test_main_handles_inspection_and_validation(self):
        with tempfile.TemporaryDirectory() as directory:
            source = self.make_epub(directory)
            result = pathlib.Path(directory) / 'result.fb2'
            result.write_text('<FictionBook><body/></FictionBook>')
            stdout = io.StringIO()
            with mock.patch('sys.stdout', stdout):
                MODULE.main(['--inspect', str(source)])
            self.assertEqual('OEBPS/content.opf', json.loads(stdout.getvalue())['opf'])
            MODULE.main(['--validate', str(result)])

    def test_verifies_content_converts_webp_and_checks_limits(self):
        with tempfile.TemporaryDirectory() as directory:
            words = ' '.join('word%d' % index for index in range(24))
            source = self.make_epub(directory, words)
            inspection = MODULE.inspect_epub(source)
            result = pathlib.Path(directory) / 'result.fb2'
            result.write_text('<FictionBook><body><p>%s</p></body></FictionBook>' % words)
            MODULE.verify_content(source, inspection, result)
            result.write_text('<FictionBook><binary content-type="image/webp">d2VicA==</binary><body/></FictionBook>')
            with mock.patch.object(MODULE, 'convert_webp', return_value=b'png'):
                MODULE.validate_fb2(result)
            self.assertIn('cG5n', result.read_text())
            with mock.patch.object(MODULE.resource, 'setrlimit') as limit:
                MODULE.limit_resources(64)
            limit.assert_called_once()
            with self.assertRaises(MODULE.ConversionError):
                MODULE.convert(source, source, 1, 64, directory, None)


if __name__ == '__main__':
    unittest.main()
