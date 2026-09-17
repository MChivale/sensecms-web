"""Read-only acceptance of the official website's managed package catalogue."""
import json
from pathlib import Path
import subprocess
import sys
import urllib.request

base = sys.argv[1].rstrip('/')
if base not in ('http://127.0.0.1:8873', 'https://www.sensecms.com'):
    raise SystemExit('Use the established QA or production website.')
root = Path(__file__).resolve().parents[1]
products = json.loads(subprocess.check_output([
    'php8.5', '-r', 'echo json_encode(require $argv[1]);',
    str(root / '.src/package-catalog.php'),
]))
assert len(products) == 14 and sum(p['usd_year'] == 0 for p in products) == 6
paths = ['/extensions/catalog']
paths += ['/extensions/catalog/' + p['type'] + '/' + p['slug'] for p in products]
paths += ['/extensions/catalog/' + t for t in ('theme', 'plugin', 'addon', 'module')]
paths += ['/extensions']
bodies = {}
for path in paths:
    with urllib.request.urlopen(base + path, timeout=20) as res:
        body = res.read().decode()
        assert res.status == 200 and '<h1>' in body, path
        assert 'rel="canonical" href="https://www.sensecms.com' + path + '"' in body, path
        assert 'href="/extensions/catalog' in body, path
        bodies[path] = body
    with urllib.request.urlopen(urllib.request.Request(base + path, method='HEAD'), timeout=20) as res:
        assert res.status == 200 and not res.read(), path
index = bodies[paths[0]]
entry = bodies['/extensions']
assert entry.count('data-market-item ') == 14 and 'marketplace-hero' in entry
assert 'data-market-category="plugin"' in entry
assert 'Browse packages →' not in entry
assert index.count('data-market-item ') == 14
assert 'data-market-filters hidden' in index and 'data-market-count' in index
assert 'data-market-empty hidden' in index
assert index.count('data-pricing="free"') == 6
for product in products:
    path = '/extensions/catalog/' + product['type'] + '/' + product['slug']
    assert 'href="' + path + '"' in index, path
    body = bodies[path]
    price = 'Free' if product['usd_year'] == 0 else 'USD ' + str(product['usd_year']) + ' / year'
    assert price in body, path
    if product['status'] == 'adaptation':
        assert 'not available' in body, path
    elif product['slug'] == 'facebook-publisher':
        assert 'not open' in body, path
    else:
        assert 'available' in body or 'not open' in body, path
    if product['usd_year'] == 0:
        assert 'valid Sense CMS system licence' in body, path
    elif product['type'] != 'system':
        assert 'its own product licence' in body, path
facebook = bodies['/extensions/catalog/plugin/facebook-publisher']
assert 'Development Preview' in facebook
assert 'Meta Business Verification' in facebook and 'In review' in facebook
assert 'Meta App Review' in facebook and 'not complete' in facebook
assert 'Public package download is not open' in facebook
for path in ('/extensions', '/extensions/themes', '/extensions/plugins', '/extensions/addons', '/extensions/modules', '/download'):
    with urllib.request.urlopen(base + path, timeout=20) as res:
        assert 'href="/extensions/catalog' in res.read().decode(), path
print('Passed: 20 marketplace GET/HEAD routes, direct /extensions catalogue, 14 product prices/licence policies and 6 entry links.')
