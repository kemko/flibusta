"""Run the production mixed-book converter with real Calibre, without network."""
import base64
import json
from pathlib import Path
import subprocess
import tempfile
import xml.etree.ElementTree as ET
import zipfile

APP = Path(__file__).resolve().parents[1]
NS = {'f': 'http://www.gribuser.ru/xml/fictionbook/2.0'}
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aRZkAAAAASUVORK5CYII=')


def make_epub(path, navigation=True):
    with zipfile.ZipFile(path, 'w') as archive:
        archive.writestr('mimetype', 'application/epub+zip')
        archive.writestr('META-INF/', '')
        archive.writestr('OEBPS/', '')
        archive.writestr('META-INF/container.xml', '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>')
        archive.writestr('OEBPS/content.opf', '<package xmlns="http://www.idpf.org/2007/opf" xmlns:dc="http://purl.org/dc/elements/1.1/" version="3.0" unique-identifier="uid"><metadata><dc:identifier id="uid">urn:uuid:caabbabc-aabb-4000-8000-001122334455</dc:identifier><dc:title>EPUB work</dc:title><dc:creator>Writer</dc:creator><dc:language>en</dc:language><dc:contributor id="translator">EPUB Translator</dc:contributor><meta refines="#translator" property="role">trl</meta></metadata><manifest><item id="one" href="one.xhtml" media-type="application/xhtml+xml"/><item id="two" href="two.xhtml" media-type="application/xhtml+xml"/><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/><item id="img" href="image.png" media-type="image/png"/></manifest><spine><itemref idref="one"/><itemref idref="two"/></spine></package>')
        toc = '<ol><li><a href="one.xhtml">Part one</a><ol><li><a href="one.xhtml#chapter">Chapter one</a></li><li><a href="one.xhtml#note">First note</a></li></ol></li><li><a href="two.xhtml#chapter">Part two</a></li></ol>' if navigation else '<ol/>'
        archive.writestr('OEBPS/nav.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><title>Contents</title></head><body><nav epub:type="toc">' + toc + '</nav></body></html>')
        archive.writestr('OEBPS/one.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>One</title></head><body><p id="chapter">Unique first chapter text. <a href="#note">note</a> then <a href="two.xhtml#note"><em>note</em></a>.</p><p id="note">Unique first note text.</p><p><img src="image.png" alt="image"/></p></body></html>')
        archive.writestr('OEBPS/two.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>Two</title></head><body><p id="chapter">Unique second chapter text.</p><p id="note">Unique second note text. <a href="one.xhtml">back</a></p></body></html>')
        archive.writestr('OEBPS/image.png', PNG)


def main():
    for navigation in (True, False):
        with tempfile.TemporaryDirectory() as directory:
            job = Path(directory)
            make_epub(job / 'source-1.epub', navigation)
            (job / 'source-0.fb2').write_bytes((APP / 'tests/fixtures/books/compilation-one.fb2').read_bytes())
            (job / 'manifest.json').write_text(json.dumps({'title': 'Mixed compilation', 'sources': [
                {'file': 'source-0.fb2', 'format': 'fb2', 'bookid': 10, 'title': 'FB2 work', 'authors': ['Writer']},
                {'file': 'source-1.epub', 'format': 'epub', 'bookid': 20, 'title': 'EPUB work', 'authors': ['Writer'], 'extracted_translators': ['EPUB Translator'], 'url': 'https://library.example/mylib/book/view/20'},
            ]}))
            subprocess.run(['php', str(APP / 'tools/app_compilation_process.php'), str(job)], check=True)
            assert not (job / 'error.txt').exists(), (job / 'error.txt').read_text() if (job / 'error.txt').exists() else ''
            root = ET.parse(job / 'result.fb2').getroot()
            text = ''.join(root.itertext())
            for sentence in ['Unique first chapter text.', 'Unique first note text.', 'Unique second chapter text.', 'Unique second note text.']:
                assert text.count(sentence) == 1, sentence
            work = root.findall('f:body/f:section', NS)[1]
            titles = [''.join(title.itertext()).strip() for title in work.findall('.//f:title', NS)]
            if navigation:
                assert titles == ['EPUB work', 'Part one', 'Chapter one', 'First note', 'Part two'], titles
                assert work.find('f:section/f:section/f:title', NS) is not None
            else:
                assert titles == ['EPUB work'], titles
            links = work.findall('.//f:a', NS)
            assert len(links) == 3
            assert links[0].get('{http://www.w3.org/1999/xlink}href') != links[1].get('{http://www.w3.org/1999/xlink}href')
            assert len(root.findall('f:binary', NS)) >= 2
            assert 'EPUB Translator' in text
            assert 'https://library.example/mylib/book/view/20' in (job / 'result.fb2').read_text()
    print('Real Calibre mixed compilation checks passed (with and without navigation).')


if __name__ == '__main__':
    main()
