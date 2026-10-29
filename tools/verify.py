#!/usr/bin/env python3
"""Check public inputs, tagged SDK provenance, PHP syntax and reproducible ZIPs."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1]
metadata = json.loads((root/'composer.json').read_text())
namespace = next(iter(metadata['autoload']['psr-4'])).rstrip('\\')
sdk = root/'vendor/whollycrypto'
upstream = json.loads((sdk/'UPSTREAM.json').read_text())
assert upstream['version'] == '2.7.0'
assert upstream['repository'] == 'https://github.com/whollycrypto-com/whollycrypto-php-sdk'
for name, expected in upstream['upstream_sha256'].items():
    text = (sdk/name).read_text()
    original = text.replace(namespace+'\\Sdk\\', 'WhollyCrypto\\').replace('namespace '+namespace+'\\Sdk;', 'namespace WhollyCrypto;')
    assert hashlib.sha256(original.encode()).hexdigest() == expected, name
assert {p.relative_to(sdk).as_posix() for p in sdk.rglob('*') if p.is_file()} == set(upstream['upstream_sha256']) | {'UPSTREAM.json'}
for file in root.rglob('*.php'):
    if 'dist' in file.relative_to(root).parts: continue
    subprocess.run([os.environ.get('PHP_BINARY', 'php'), '-l', str(file)], check=True, stdout=subprocess.DEVNULL)
for file in root.rglob('*.xml'): ET.parse(file)
spec = importlib.util.spec_from_file_location('build', root/'tools/build.py')
build = importlib.util.module_from_spec(spec); spec.loader.exec_module(build)
assert metadata['version'] == build.VERSION
build.selected()
subprocess.run(['python3', str(root/'tools/build.py')], check=True, stdout=subprocess.DEVNULL)
archive = root/'dist'/('whollycrypto-'+build.KIND+'-'+build.VERSION+'.zip')
before = archive.read_bytes()
subprocess.run(['python3', str(root/'tools/build.py')], check=True, stdout=subprocess.DEVNULL)
assert before == archive.read_bytes(), 'ZIP is not reproducible'
print('PHP syntax, XML, public allowlist, complete isolated SDK provenance and reproducible ZIP: passed.')
