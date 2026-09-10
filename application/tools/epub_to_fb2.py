#!/usr/bin/env python3
"""Calibre-isolated EPUB to FB2 conversion adapter.

Run this file through calibre-debug.  It rejects malformed EPUBs before
Calibre sees them and verifies the generated FB2 before publishing it.
"""

import argparse
import base64
import json
import os
import posixpath
import re
import resource
import shutil
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
import zipfile
from pathlib import Path


class ConversionError(RuntimeError):
    pass


MAX_MEM_DEFAULT = 768
XML_SUFFIXES = {'.xml', '.xhtml', '.html', '.htm', '.opf', '.ncx'}
IMAGE_TYPES = {'image/jpeg', 'image/png', 'image/webp'}
FB2_NS = 'http://www.gribuser.ru/xml/fictionbook/2.0'
XLINK_NS = 'http://www.w3.org/1999/xlink'

ET.register_namespace('', FB2_NS)
ET.register_namespace('l', XLINK_NS)


def local_name(tag):
    return tag.rsplit('}', 1)[-1]


def attribute(element, name):
    for key, value in element.attrib.items():
        if local_name(key) == name:
            return value
    return None


def xml_document(data, label):
    if b'<!DOCTYPE' in data.upper() or b'<!ENTITY' in data.upper():
        raise ConversionError('%s contains a forbidden XML declaration' % label)
    try:
        return ET.fromstring(data)
    except ET.ParseError as error:
        raise ConversionError('Invalid XML in %s: %s' % (label, error)) from error


def safe_member(name):
    return bool(name) and '\\' not in name and not name.startswith('/') and '\x00' not in name and all(part not in ('', '.', '..') for part in name.split('/'))


def resolve_path(base, href):
    path = href.split('#', 1)[0]
    if not path:
        return None
    if re.match(r'^[a-z][a-z0-9+.-]*:', path, re.I) or path.startswith('/'):
        return None
    result = posixpath.normpath(posixpath.join(base, path))
    if result == '.' or result.startswith('../') or not safe_member(result):
        raise ConversionError('Unsafe EPUB link: %s' % href)
    return result


def text(element):
    value = ' '.join(part.strip() for part in element.itertext() if part.strip())
    return re.sub(r'\s+([,.;:!?])', r'\1', value)


def element_children(element, name):
    return [child for child in element if local_name(child.tag) == name]


def nav_tree(nav, base):
    roots = element_children(nav, 'ol')
    if not roots:
        return []

    def items(ol):
        result = []
        for li in element_children(ol, 'li'):
            link = next((node for node in li.iter() if local_name(node.tag) == 'a' and attribute(node, 'href')), None)
            if link is None:
                continue
            href = attribute(link, 'href')
            target = resolve_path(base, href)
            result.append({'title': text(link), 'href': href, 'target': target, 'children': items(next(iter(element_children(li, 'ol')), ET.Element('ol')))})
        return result

    return items(roots[0])


def ncx_tree(nav_map, base):
    def items(parent):
        result = []
        for point in element_children(parent, 'navPoint'):
            content = next((node for node in point.iter() if local_name(node.tag) == 'content' and attribute(node, 'src')), None)
            title = next((node for node in point.iter() if local_name(node.tag) == 'text'), None)
            if content is None:
                continue
            href = attribute(content, 'src')
            result.append({'title': text(title) if title is not None else '', 'href': href, 'target': resolve_path(base, href), 'children': items(point)})
        return result

    return items(nav_map)


def flattened_toc(toc):
    for item in toc:
        yield item
        yield from flattened_toc(item['children'])


def inspect_epub(path):
    try:
        archive = zipfile.ZipFile(path)
    except (OSError, zipfile.BadZipFile) as error:
        raise ConversionError('Invalid EPUB archive: %s' % error) from error
    with archive:
        names = archive.namelist()
        if len(names) != len(set(names)):
            raise ConversionError('EPUB has duplicate entries')
        if not names or any(not safe_member(name) for name in names):
            raise ConversionError('EPUB has an unsafe entry path')
        encrypted = [info.filename for info in archive.infolist() if info.flag_bits & 1]
        if encrypted:
            raise ConversionError('DRM or encrypted EPUB entry: %s' % encrypted[0])
        for info in archive.infolist():
            if info.file_size > 100 * 1024 * 1024 or (info.compress_size and info.file_size > info.compress_size * 100):
                raise ConversionError('EPUB entry exceeds resource limits: %s' % info.filename)
        container = xml_document(archive.read('META-INF/container.xml'), 'META-INF/container.xml')
        rootfile = next((node for node in container.iter() if local_name(node.tag) == 'rootfile' and attribute(node, 'media-type') == 'application/oebps-package+xml'), None)
        if rootfile is None or not attribute(rootfile, 'full-path'):
            raise ConversionError('EPUB package document is missing')
        opf_name = attribute(rootfile, 'full-path')
        if not safe_member(opf_name) or opf_name not in names:
            raise ConversionError('EPUB package document is unavailable')
        opf = xml_document(archive.read(opf_name), opf_name)
        opf_dir = posixpath.dirname(opf_name)
        manifest = {}
        for item in (node for node in opf.iter() if local_name(node.tag) == 'item'):
            item_id, href = attribute(item, 'id'), attribute(item, 'href')
            if not item_id or not href:
                continue
            member = resolve_path(opf_dir, href)
            if member is None or member not in names:
                raise ConversionError('Missing EPUB manifest resource: %s' % href)
            manifest[item_id] = {'path': member, 'media_type': attribute(item, 'media-type') or '', 'properties': attribute(item, 'properties') or ''}
        spine = []
        for itemref in (node for node in opf.iter() if local_name(node.tag) == 'itemref'):
            item = manifest.get(attribute(itemref, 'idref'))
            if item is not None:
                spine.append(item['path'])
        if not spine:
            raise ConversionError('EPUB has no readable spine')
        nav_item = next((item for item in manifest.values() if 'nav' in item['properties'].split()), None)
        ncx_id = next((attribute(node, 'toc') for node in opf.iter() if local_name(node.tag) == 'spine' and attribute(node, 'toc')), None)
        toc = []
        if nav_item is not None:
            nav = xml_document(archive.read(nav_item['path']), nav_item['path'])
            node = next((item for item in nav.iter() if local_name(item.tag) == 'nav' and ('toc' in (attribute(item, 'type') or '').split() or 'toc' in (attribute(item, 'type') or '').lower())), None)
            if node is not None:
                toc = nav_tree(node, posixpath.dirname(nav_item['path']))
        if not toc and ncx_id in manifest:
            ncx_path = manifest[ncx_id]['path']
            ncx = xml_document(archive.read(ncx_path), ncx_path)
            nav_map = next((node for node in ncx.iter() if local_name(node.tag) == 'navMap'), None)
            if nav_map is not None:
                toc = ncx_tree(nav_map, posixpath.dirname(ncx_path))
        for item in flattened_toc(toc):
            if item['target'] is None or item['target'] not in names:
                raise ConversionError('Broken table of contents link: %s' % item['href'])
        for member in spine:
            document = xml_document(archive.read(member), member)
            for node in document.iter():
                href = attribute(node, 'href') or attribute(node, 'src')
                if href:
                    target = resolve_path(posixpath.dirname(member), href)
                    if target is not None and target not in names:
                        raise ConversionError('Broken EPUB link in %s: %s' % (member, href))
        return {'opf': opf_name, 'spine': spine, 'toc': toc, 'images': [item['path'] for item in manifest.values() if item['media_type'] in IMAGE_TYPES]}


def source_words(path, inspection):
    words = []
    with zipfile.ZipFile(path) as archive:
        for member in inspection['spine']:
            words.extend(re.findall(r'\w+', text(xml_document(archive.read(member), member)).lower(), re.UNICODE))
    return set(words)


def source_references(path, inspection):
    anchors, links = {}, []
    with zipfile.ZipFile(path) as archive:
        for member in inspection['spine']:
            document = xml_document(archive.read(member), member)
            for node in document.iter():
                identifier = attribute(node, 'id')
                if identifier:
                    if identifier in anchors:
                        raise ConversionError('Duplicate EPUB anchor: %s' % identifier)
                    anchors[identifier] = text(node)
        for member in inspection['spine']:
            document = xml_document(archive.read(member), member)
            for node in document.iter():
                if local_name(node.tag) != 'a' or not attribute(node, 'href'):
                    continue
                href = attribute(node, 'href')
                if '#' not in href:
                    continue
                target_member, target = href.split('#', 1)
                if not target:
                    continue
                resolved = resolve_path(posixpath.dirname(member), target_member) if target_member else member
                if resolved not in inspection['spine'] or target not in anchors:
                    raise ConversionError('Broken EPUB anchor link in %s: %s' % (member, href))
                links.append((text(node), target))
    return anchors, links


def limit_resources(memory_mib):
    limit = memory_mib * 1024 * 1024
    resource.setrlimit(resource.RLIMIT_AS, (limit, limit))


def calibre_command(source, destination):
    return [
        'ebook-convert', str(source), str(destination), '--disable-font-rescaling',
        '--chapter=//h:never', '--level1-toc=//h:never', '--level2-toc=//h:never', '--level3-toc=//h:never',
    ]


def convert_webp(data):
    try:
        from calibre.utils.img import image_from_data, image_to_data
        return image_to_data(image_from_data(data), fmt='png')
    except Exception as error:
        raise ConversionError('Cannot convert WebP image to PNG') from error


def fb2_ids(root):
    ids = set()
    for node in root.iter():
        value = attribute(node, 'id')
        if value:
            if value in ids:
                raise ConversionError('Generated FB2 has duplicate id: %s' % value)
            ids.add(value)
    return ids


def add_source_id(root, identifier, needle):
    for node in root.iter():
        if local_name(node.tag) not in {'FictionBook', 'description'} and needle and needle == text(node) and not attribute(node, 'id'):
            node.set('id', identifier)
            return
    raise ConversionError('Calibre conversion lost EPUB anchor: %s' % identifier)


def add_source_link(root, label, target):
    for node in root.iter():
        if local_name(node.tag) != 'p' or list(node) or not node.text or label not in node.text:
            continue
        before, after = node.text.split(label, 1)
        node.text = before
        link = ET.SubElement(node, '{%s}a' % FB2_NS, {'{%s}href' % XLINK_NS: '#' + target})
        link.text = label
        link.tail = after
        return
    raise ConversionError('Calibre conversion lost EPUB internal link: %s' % label)


def add_navigation(root, toc):
    if not toc:
        return
    navigation = ET.Element('{%s}body' % FB2_NS, {'name': 'toc'})

    def append(parent, item, number):
        section = ET.SubElement(parent, '{%s}section' % FB2_NS, {'id': 'toc-%s' % number})
        title = ET.SubElement(section, '{%s}title' % FB2_NS)
        paragraph = ET.SubElement(title, '{%s}p' % FB2_NS)
        paragraph.text = item['title'] or 'Untitled'
        target = item['href'].split('#', 1)[1] if '#' in item['href'] else None
        if target:
            reference = ET.SubElement(section, '{%s}p' % FB2_NS)
            link = ET.SubElement(reference, '{%s}a' % FB2_NS, {'{%s}href' % XLINK_NS: '#' + target})
            link.text = item['title'] or 'Untitled'
        for child_index, child in enumerate(item['children']):
            append(section, child, '%s-%s' % (number, child_index))

    for index, item in enumerate(toc):
        append(navigation, item, str(index))
    description = next((node for node in root if local_name(node.tag) == 'description'), None)
    root.insert(list(root).index(description) + 1 if description is not None else 0, navigation)


def adapt_fb2(path, source, inspection):
    root = ET.parse(path).getroot()
    anchors, links = source_references(source, inspection)
    for identifier, needle in anchors.items():
        add_source_id(root, identifier, needle)
    for label, target in links:
        add_source_link(root, label, target)
    add_navigation(root, inspection['toc'])
    ET.ElementTree(root).write(path, encoding='utf-8', xml_declaration=True)


def validate_fb2(path, xsd=None):
    try:
        root = ET.parse(path).getroot()
    except (OSError, ET.ParseError) as error:
        raise ConversionError('Invalid generated FB2: %s' % error) from error
    if local_name(root.tag) != 'FictionBook':
        raise ConversionError('Generated document is not FB2')
    ids = fb2_ids(root)
    for node in root.iter():
        href = attribute(node, 'href')
        if href and href.startswith('#') and href[1:] not in ids:
            raise ConversionError('Generated FB2 has a broken internal link: %s' % href)
        if local_name(node.tag) == 'binary' and (attribute(node, 'content-type') or '').lower() == 'image/webp':
            raw = base64.b64decode((node.text or '').encode(), validate=True)
            node.text = base64.b64encode(convert_webp(raw)).decode('ascii')
            for key in list(node.attrib):
                if local_name(key) == 'content-type':
                    node.set(key, 'image/png')
    if xsd:
        result = subprocess.run(['xmllint', '--noout', '--nonet', '--schema', str(xsd), str(path)], text=True, capture_output=True)
        if result.returncode:
            raise ConversionError('Generated FB2 fails XSD validation: %s' % result.stderr.strip())
    ET.ElementTree(root).write(path, encoding='utf-8', xml_declaration=True)


def verify_content(source, inspection, result):
    before = source_words(source, inspection)
    if len(before) < 20:
        return
    root = ET.parse(result).getroot()
    after = set(re.findall(r'\w+', text(root).lower(), re.UNICODE))
    if len(before & after) / len(before) < 0.60:
        raise ConversionError('Calibre conversion lost source text')


def convert(source, output, timeout, memory_mib, work_dir, xsd):
    source = Path(source).resolve(strict=True)
    output = Path(output).resolve()
    if source == output:
        raise ConversionError('Input and output must differ')
    inspection = inspect_epub(source)
    work_root = Path(work_dir).resolve() if work_dir else None
    if work_root:
        work_root.mkdir(parents=True, exist_ok=True)
    temporary = Path(tempfile.mkdtemp(prefix='flibusta-epub-', dir=work_root))
    try:
        raw = temporary / 'converted.fb2'
        completed = subprocess.run(calibre_command(source, raw), cwd=temporary, timeout=timeout, text=True, capture_output=True, preexec_fn=lambda: limit_resources(memory_mib))
        if completed.returncode:
            raise ConversionError('Calibre conversion failed: %s' % (completed.stderr.strip() or completed.stdout.strip()))
        if not raw.is_file() or raw.stat().st_size == 0:
            raise ConversionError('Calibre produced no FB2')
        verify_content(source, inspection, raw)
        adapt_fb2(raw, source, inspection)
        validate_fb2(raw, xsd)
        output.parent.mkdir(parents=True, exist_ok=True)
        descriptor, pending = tempfile.mkstemp(prefix='.flibusta-fb2-', dir=output.parent)
        os.close(descriptor)
        try:
            shutil.copyfile(raw, pending)
            os.replace(pending, output)
        finally:
            if os.path.exists(pending):
                os.unlink(pending)
    except subprocess.TimeoutExpired as error:
        raise ConversionError('Calibre conversion timed out') from error
    finally:
        shutil.rmtree(temporary, ignore_errors=True)


def main(argv):
    parser = argparse.ArgumentParser()
    parser.add_argument('--inspect', metavar='EPUB')
    parser.add_argument('--validate', metavar='FB2')
    parser.add_argument('--xsd')
    parser.add_argument('--timeout', type=int, default=900)
    parser.add_argument('--memory-mib', type=int, default=MAX_MEM_DEFAULT)
    parser.add_argument('--work-dir')
    parser.add_argument('source', nargs='?')
    parser.add_argument('output', nargs='?')
    arguments = parser.parse_args(argv)
    if arguments.inspect:
        print(json.dumps(inspect_epub(arguments.inspect), ensure_ascii=False))
        return
    if arguments.validate:
        validate_fb2(arguments.validate, arguments.xsd)
        return
    if not arguments.source or not arguments.output or arguments.timeout < 1 or arguments.memory_mib < 64:
        parser.error('source, output, positive timeout and at least 64 MiB are required')
    convert(arguments.source, arguments.output, arguments.timeout, arguments.memory_mib, arguments.work_dir, arguments.xsd)


if __name__ == '__main__':
    try:
        main(sys.argv[1:])
    except ConversionError as error:
        print('EPUB conversion error: %s' % error, file=sys.stderr)
        sys.exit(1)
