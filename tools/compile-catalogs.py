#!/usr/bin/env python3
"""Compile this plugin's singular gettext PO catalogues without dependencies."""
import ast
import gettext
from pathlib import Path
import struct
import json
import re
import argparse

LOCALES = {'de_DE', 'es_ES', 'pt_BR', 'pt_PT', 'fr_FR', 'it_IT', 'nl_NL', 'pl_PL', 'ja', 'zh_CN', 'ar', 'hi_IN'}


def read_catalog(path):
    messages = {}
    entry = {}
    current = None

    def commit():
        if entry:
            if set(entry) != {'msgid', 'msgstr'}:
                raise ValueError('Each entry requires one msgid and msgstr.')
            if entry['msgid'] in messages:
                raise ValueError('Duplicate msgid.')
            messages[entry['msgid']] = entry['msgstr']
            entry.clear()

    for raw in path.read_text(encoding='utf-8').splitlines() + ['']:
        line = raw.strip()
        if not line:
            commit()
            current = None
        elif line.startswith('#'):
            if line.startswith('#,') and 'fuzzy' in line:
                raise ValueError('Review fuzzy translations before compiling.')
        elif line.startswith(('msgid ', 'msgstr ')):
            current, text = line.split(' ', 1)
            entry[current] = ast.literal_eval(text)
        elif line.startswith('"') and current:
            entry[current] += ast.literal_eval(line)
        else:
            raise ValueError('Unsupported PO syntax; use GNU msgfmt for plural/context entries.')
    if 'charset=UTF-8' not in messages.get('', ''):
        raise ValueError('A UTF-8 catalogue header is required.')
    return messages


def compile_catalog(path, check=False):
    messages = read_catalog(path)
    template = path.parent / 'hsp-calendar-connection.pot'
    if template.exists():
        expected = set(read_catalog(template))
        if set(messages) != expected:
            raise ValueError(f'{path.name}: missing or obsolete messages: {set(messages) ^ expected}')
    placeholders = re.compile(r'%(?:\d+\$)?[-+0 #]*(?:\d+)?(?:\.\d+)?[bcdeEfFgGosuxX]')
    for original, translated in messages.items():
        if not original:
            continue
        if not translated.strip():
            raise ValueError(f'{path.name}: empty translation: {original}')
        if sorted(placeholders.findall(original.replace('%%', ''))) != sorted(placeholders.findall(translated.replace('%%', ''))):
            raise ValueError(f'{path.name}: placeholder mismatch: {original}')
    keys = sorted(messages)
    originals = [key.encode('utf-8') for key in keys]
    translated = [messages[key].encode('utf-8') for key in keys]
    count = len(keys)
    start = 28 + count * 16
    original_table = []
    translation_table = []
    data = bytearray()
    for encoded in originals:
        original_table.extend((len(encoded), start + len(data)))
        data.extend(encoded + b'\0')
    for encoded in translated:
        translation_table.extend((len(encoded), start + len(data)))
        data.extend(encoded + b'\0')
    # WordPress POMO uses the hash-table offset to bound translation indexes,
    # even when the hash table is empty. GNU gettext alone accepts zero here.
    payload = struct.pack('<7I', 0x950412DE, 0, count, 28, 28 + count * 8, 0, start)
    payload += struct.pack('<' + 'I' * (count * 4), *(original_table + translation_table)) + data
    output = path.with_suffix('.mo')
    if check:
        if not output.exists() or output.read_bytes() != payload:
            raise ValueError(f'{output.name}: compiled catalogue is missing or stale')
    else:
        output.write_bytes(payload)
    with output.open('rb') as stream:
        loaded = gettext.GNUTranslations(stream)
    for key, value in messages.items():
        if key and loaded.gettext(key) != value:
            raise ValueError('Compiled catalogue readback differs.')
    print(f'{output.name}: {count - 1} translations verified')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Validate catalogues without writing generated files.')
    parser.add_argument('--messages', type=Path, help='Verify the POT against extracted PHP messages (JSON).')
    arguments = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    expected = {'hsp-calendar-connection-' + locale for locale in LOCALES}
    actual = {path.stem for path in (root / 'languages').glob('*.po')}
    if actual != expected:
        raise ValueError(f'Missing or unexpected locale catalogs: {actual ^ expected}')
    if arguments.check:
        compiled = {path.stem for path in (root / 'languages').glob('*.mo')}
        if compiled != expected:
            raise ValueError(f'Missing or unexpected compiled catalogs: {compiled ^ expected}')
    if arguments.messages:
        actual = set(json.loads(arguments.messages.read_text(encoding='utf-8')))
        template = set(read_catalog(root / 'languages/hsp-calendar-connection.pot')) - {''}
        if actual != template:
            raise ValueError(f'POT does not match source messages: {actual ^ template}')
    for source in sorted((root / 'languages').glob('*.po')):
        compile_catalog(source, arguments.check)
