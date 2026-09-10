import importlib.util
import io
import json
import pathlib
import subprocess
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

    def test_accepts_doctypes_without_loading_dtds_or_expanding_entities(self):
        for doctype in ['<!DOCTYPE html>', '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://127.0.0.1:1/xhtml11.dtd">']:
            self.assertEqual('html', MODULE.xml_document((doctype + '<html/>').encode(), 'chapter').tag)
        for declaration in ['<!ENTITY example "expanded">', '<!ENTITY example SYSTEM "file:///etc/passwd">', '<!ENTITY % example SYSTEM "http://127.0.0.1:1/entity">%example;']:
            for encoding in ('utf-8', 'utf-16'):
                with self.subTest(declaration=declaration, encoding=encoding), self.assertRaises(MODULE.ConversionError):
                    MODULE.xml_document(('<!DOCTYPE html [' + declaration + ']><html/>').encode(encoding), 'chapter')

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

    def test_navigation_keeps_unlinked_group_labels(self):
        nav = MODULE.xml_document(b'<nav><ol><li><span>Volume</span><ol><li><span>Part One</span><ol><li><a href="chapter.xhtml#one">Chapter A</a></li></ol></li></ol></li></ol></nav>', 'nav')
        toc = MODULE.nav_tree(nav, 'OEBPS')
        self.assertEqual(['Volume', 'Part One', 'Chapter A'], [item['title'] for item in MODULE.flattened_toc(toc)])
        root = ET.fromstring('<FictionBook xmlns="%s"><body><section><p id="one">Chapter text</p></section></body></FictionBook>' % MODULE.FB2_NS)
        MODULE.add_navigation(root, toc, {('OEBPS/chapter.xhtml', 'one'): 'one'}, {'one': 'one'})
        self.assertEqual(['Volume', 'Part One', 'Chapter A'], [MODULE.text(node) for node in root.iter() if MODULE.local_name(node.tag) == 'title'])
        self.assertEqual('Chapter text', root.find('.//{*}section/{*}section/{*}section/{*}p').text)
        for invalid in ['<li><ol/></li>', '<li><span>Empty group</span></li>']:
            with self.assertRaises(MODULE.ConversionError):
                MODULE.nav_tree(ET.fromstring('<nav><ol>' + invalid + '</ol></nav>'), 'OEBPS')

    def test_adapter_preserves_reference_positions_and_navigation(self):
        with tempfile.TemporaryDirectory() as directory:
            source = self.make_epub(directory)
            prepared = pathlib.Path(directory) / 'prepared.epub'
            result = pathlib.Path(directory) / 'result.fb2'
            inspection = MODULE.inspect_epub(source)
            anchors, prefix = MODULE.prepare_epub(source, prepared, inspection)
            body_id, one, note = (anchors[('OEBPS/chapter.xhtml', key)] for key in ('', 'one', 'note'))
            result.write_text('<FictionBook xmlns="%s" xmlns:l="%s"><body><section><p>%s%sEND%s%sENDOne anchor <a l:href="https://flibusta.invalid/%s/%s">jump</a><a l:href="https://flibusta.invalid/%s/%s">jump</a></p><p>%s%sENDNote anchor</p></section></body></FictionBook>' % (MODULE.FB2_NS, MODULE.XLINK_NS, prefix, body_id, prefix, one, prefix, note, prefix, one, prefix, note))
            MODULE.adapt_fb2(result, inspection, anchors, prefix)
            root = ET.parse(result).getroot()
            self.assertNotIn(prefix, result.read_text())
            self.assertEqual(['#' + note, '#' + body_id], [MODULE.attribute(node, 'href') for node in root.iter() if MODULE.local_name(node.tag) == 'a'])
            self.assertEqual(['One', 'Note'], [MODULE.text(node) for node in root.iter() if MODULE.local_name(node.tag) == 'title'])
            MODULE.validate_fb2(result)

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
            self.assertEqual({('OEBPS/chapter.xhtml', key) for key in ('', 'one', 'note')}, set(MODULE.source_references(source, inspection)[0]))
            self.assertIn('anchor', MODULE.source_words(source, inspection))

    def test_validates_links_and_rejects_dangling_ones(self):
        with tempfile.TemporaryDirectory() as directory:
            result = pathlib.Path(directory) / 'result.fb2'
            result.write_text('<FictionBook><body><section id="one"><p>text</p></section></body></FictionBook>')
            MODULE.validate_fb2(result)
            result.write_text('<FictionBook><body><p><a href="#missing">broken</a></p></body></FictionBook>')
            with self.assertRaises(MODULE.ConversionError):
                MODULE.validate_fb2(result)

    def test_directory_entries_and_document_scoped_anchors(self):
        with tempfile.TemporaryDirectory() as directory:
            source = self.make_epub(directory)
            with zipfile.ZipFile(source, 'a') as archive:
                archive.writestr('OEBPS/', '')
                archive.writestr('OEBPS/second.xhtml', '<html><body><p id="one">Other chapter</p></body></html>')
            inspection = MODULE.inspect_epub(source)
            inspection['spine'].append('OEBPS/second.xhtml')
            anchors, _ = MODULE.source_references(source, inspection)
            self.assertNotEqual(anchors[('OEBPS/chapter.xhtml', 'one')], anchors[('OEBPS/second.xhtml', 'one')])

    def test_failed_and_timed_out_conversion_preserve_previous_output(self):
        with tempfile.TemporaryDirectory() as directory:
            source = self.make_epub(directory)
            output = pathlib.Path(directory) / 'result.fb2'
            output.write_text('previous result')
            for result in [types.SimpleNamespace(returncode=1, stderr='failure', stdout=''), types.SimpleNamespace(returncode=0, stderr='', stdout=''), subprocess.TimeoutExpired('ebook-convert', 1)]:
                with self.subTest(result=result):
                    arguments = {'side_effect': result} if isinstance(result, Exception) else {'return_value': result}
                    with mock.patch.object(MODULE.subprocess, 'run', **arguments), self.assertRaises(MODULE.ConversionError):
                        MODULE.convert(source, output, 1, 64, directory, None)
                    self.assertEqual('previous result', output.read_text())
                    self.assertEqual([], list(pathlib.Path(directory).glob('flibusta-epub-*')))

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
