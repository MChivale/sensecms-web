"""Read-only acceptance of the official website's managed package catalogue."""
import json
from pathlib import Path
import shutil
import subprocess
import sys
import urllib.request

base = sys.argv[1].rstrip('/')
if base not in ('http://127.0.0.1:8873', 'https://www.sensecms.com'):
    raise SystemExit('Use the established QA or production website.')
root = Path(__file__).resolve().parents[1]
php = shutil.which('php8.5') or shutil.which('php')
assert php, 'PHP CLI unavailable'
products = json.loads(subprocess.check_output([
    php, '-r', 'echo json_encode(require $argv[1]);',
    str(root / '.src/package-catalog.php'),
]))
assert len(products) == 22 and sum(p['usd_year'] == 0 for p in products) == 8
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
assert entry.count('data-market-item ') == 22 and 'marketplace-hero' in entry
assert 'data-market-category="plugin"' in entry
assert 'Browse packages →' not in entry
assert index.count('data-market-item ') == 22
assert 'data-market-filters hidden' in index and 'data-market-count' in index
assert 'data-market-empty hidden' in index
assert index.count('data-pricing="free"') == 8
for product in products:
    path = '/extensions/catalog/' + product['type'] + '/' + product['slug']
    assert 'href="' + path + '"' in index, path
    body = bodies[path]
    price = 'Free' if product['usd_year'] == 0 else 'USD ' + str(product['usd_year']) + ' / year'
    assert price in body, path
    if product['status'] == 'adaptation':
        assert 'not available' in body, path
    elif product['slug'] in ('facebook-publisher', 'x-publisher', 'linkedin-publisher'):
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
x = bodies['/extensions/catalog/plugin/x-publisher']
assert 'Development Preview' in x and 'Version 0.1.2 is installed' in x and 'OAuth 2.0' in x and 'PKCE' in x
assert 'Public package download is not open' in x
linkedin = bodies['/extensions/catalog/plugin/linkedin-publisher']
assert 'Version 0.1.2 is installed' in linkedin and 'member profiles' in linkedin
assert 'Company Page publishing' in linkedin and 'Community Management API' in linkedin
assert 'Public package download is not open' in linkedin
bluesky = bodies['/extensions/catalog/plugin/bluesky-publisher']
assert 'USD 15 / year' in bluesky and 'Development Preview 0.1.4' in bluesky
assert 'Sense CMS Bluesky Publisher Plugin' in bluesky and 'Bluesky Publisher Plugin' in bluesky
assert 'start date and expiry date' in bluesky and 'Multiple accounts' in bluesky
assert 'create <code>app.bsky.feed.post</code>' in bluesky
mastodon = bodies['/extensions/catalog/plugin/mastodon-publisher']
assert 'USD 15 / year' in mastodon and 'Development Preview 0.1.1' in mastodon
assert 'Sense CMS Mastodon Publisher Plugin' in mastodon and 'Mastodon Publisher Plugin' in mastodon
assert 'start date and expiry date' in mastodon and 'Multiple accounts and servers' in mastodon
assert '<code>read:accounts</code>' in mastodon and '<code>write:statuses</code>' in mastodon
telegram = bodies['/extensions/catalog/plugin/telegram-channels-publisher']
assert 'USD 15 / year' in telegram and 'Development Preview 0.1.1' in telegram
assert 'Sense CMS Telegram Channels Plugin' in telegram and 'Telegram Channels Plugin' in telegram
assert 'start date and expiry date' in telegram and 'Multiple channels' in telegram
assert '@SenseCMSBot' in telegram and '<code>post_messages</code>' in telegram
pinterest = bodies['/extensions/catalog/plugin/pinterest-publisher']
assert 'USD 15 / year' in pinterest and 'Development Preview 0.1.1' in pinterest
assert 'Sense CMS Pinterest Publisher Plugin' in pinterest and 'Pinterest Publisher Plugin' in pinterest
assert 'start date and expiry date' in pinterest and 'Multiple boards' in pinterest
assert '<code>pins:write</code>' in pinterest and 'Image Pins' in pinterest
assert 'Trial access is pending' in pinterest and 'Public download and account connection are not open' in pinterest
tiktok = bodies['/extensions/catalog/plugin/tiktok-publisher']
assert 'USD 15 / year' in tiktok and 'Development Preview 0.1.3' in tiktok
assert 'Sense CMS TikTok Publisher Plugin' in tiktok and 'TikTok Publisher Plugin' in tiktok
assert 'start date and expiry date' in tiktok and 'Multiple accounts' in tiktok
assert '<code>video.publish</code>' in tiktok and 'Direct Post' in tiktok
assert 'TikTok Sandbox' in tiktok and 'public download is not open' in tiktok.lower()
youtube = bodies['/extensions/catalog/plugin/youtube-publisher']
assert 'USD 15 / year' in youtube and 'Development Preview 0.1.1' in youtube
assert 'Sense CMS YouTube Publisher Plugin' in youtube and 'YouTube Publisher Plugin' in youtube
assert 'start date and expiry date' in youtube and 'Multiple channels' in youtube
assert '<code>youtube.upload</code>' in youtube and '<code>youtube.readonly</code>' in youtube and 'private uploads' in youtube
for path in ('/extensions', '/extensions/themes', '/extensions/plugins', '/extensions/addons', '/extensions/modules', '/download'):
    with urllib.request.urlopen(base + path, timeout=20) as res:
        assert 'href="/extensions/catalog' in res.read().decode(), path
print('Passed: managed marketplace routes, direct /extensions catalogue, 22 product prices/licence policies and entry links.')
