#!/usr/bin/env python3
"""Build a WordPress ZIP from an explicit runtime-file allowlist, offline."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import zipfile

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output-directory', type=Path, default=root / 'dist')
parser.add_argument('--tag', help='Require an exact v<version> release tag.')
arguments = parser.parse_args()
main = root / 'hsp-calendar-connection.php'
main_source = main.read_text(encoding='utf-8')
match = re.search(r'^\s*\* Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$', main_source, re.MULTILINE)
if not match:
    raise SystemExit('A numeric plugin version is required.')
version = match.group(1)
runtime = re.search(r"define\(\s*['\"]HSPCC_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)", main_source)
if not runtime or runtime.group(1) != version:
    raise SystemExit('The runtime version constant must match the plugin header.')
if arguments.tag is not None and arguments.tag != 'v' + version:
    raise SystemExit('Release tag must exactly match v' + version + '.')
readme = (root / 'readme.txt').read_text(encoding='utf-8')
stable = re.search(r'^Stable tag:\s*([^\r\n]+)', readme, re.MULTILINE)
if not stable or stable.group(1).strip() != version:
    raise SystemExit('The readme stable tag must match the plugin version.')
files = [root / name for name in ('hsp-calendar-connection.php', 'README.md', 'readme.txt', 'LICENSE', 'CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md')]
files.extend(sorted((root / 'includes').glob('*.php')))
files.extend(sorted((root / 'assets').glob('*.js')))
files.extend(sorted((root / 'assets').glob('*.css')))
files.extend(sorted((root / 'languages').glob('*.po')))
files.extend(sorted((root / 'languages').glob('*.mo')))
files.extend(sorted((root / 'languages').glob('*.pot')))
if any(not item.is_file() or item.is_symlink() for item in files):
    raise SystemExit('Release files must be regular owned files.')
output = arguments.output_directory.resolve()
output.mkdir(parents=True, exist_ok=True)
archive = output / ('hsp-calendar-connection-' + version + '.zip')
manifest = {'name': 'HSP Calendar Connection', 'version': version, 'folder': 'hsp-calendar-connection', 'files': {}}
with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as package:
    for item in files:
        relative = item.relative_to(root).as_posix()
        content = item.read_bytes()
        manifest['files'][relative] = hashlib.sha256(content).hexdigest()
        entry = zipfile.ZipInfo('hsp-calendar-connection/' + relative, date_time=(2026, 1, 1, 0, 0, 0))
        entry.compress_type = zipfile.ZIP_DEFLATED
        entry.external_attr = 0o100644 << 16
        package.writestr(entry, content)
manifest['archiveSha256'] = hashlib.sha256(archive.read_bytes()).hexdigest()
manifest_path = archive.with_suffix('.manifest.json')
manifest_path.write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
print(json.dumps({'archive': str(archive), 'runtimeFileCount': len(files), 'sha256': manifest['archiveSha256']}))
