import importlib.util
import pathlib
import tempfile
import unittest
import xml.etree.ElementTree as ET
import zipfile


SCRIPT = pathlib.Path(__file__).parents[2] / 'tools' / 'epub_to_fb2.py'
SPEC = importlib.util.spec_from_file_location('epub_to_fb2', SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class EpubToFb2Test(unittest.TestCase):
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


if __name__ == '__main__':
    unittest.main()
