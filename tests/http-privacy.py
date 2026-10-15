"""Run against the disposable local HTTP fixture; never point at a real customer site.
TLS state is simulated by the test router. This does not validate SiteGround/TLS/cache.
"""
from pathlib import Path
from urllib.request import Request, build_opener, HTTPRedirectHandler
from urllib.error import HTTPError

class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

opener = build_opener(NoRedirect)
root = Path('/private/tmp/emsf-integration')
checks = 0

def request(path, role=None):
    headers = {'Host': 'online.example.test'}
    if role:
        headers['Cookie'] = (root / (role + '.cookie')).read_text()
    try:
        response = opener.open(Request('http://127.0.0.1:18765' + path, headers=headers))
    except HTTPError as error:
        response = error
    return response.code, response.headers, response.read()

for path in ['/privacy/', '/privacy/feed/', '/privacy/wp-json/wp/v2/pages', '/privacy/?rest_route=/wp/v2/pages', '/privacy/emsf-media/private.txt']:
    for role in [None, 'outsider']:
        status, headers, body = request(path, role)
        assert status in (302, 401, 403), (path, role, status)
        assert b'PRIVATE SENTINEL' not in body and b'PRIVATE MEDIA SENTINEL' not in body
        checks += 1
        print('PASS:', path, role or 'anonymous', status)

status, headers, body = request('/privacy/emsf-media/private.txt', 'customer')
assert status == 200 and body == b'PRIVATE MEDIA SENTINEL', (status, body[:100])
assert 'no-store' in headers['Cache-Control'] or 'private' in headers['Cache-Control']
checks += 1
print('PASS: own-site member can read media with private cache headers')
status, headers, body = request('/privacy/emsf-media/..%2F..%2Fcustomer.cookie', 'customer')
assert status == 404
checks += 1
print('PASS: media traversal rejected')
status, _, body = request('/wp-json/emsf/v1/status?source=http-fixture', 'customer')
assert status in (401, 403)
checks += 1
print('PASS: customer cookie cannot access receiver')
print('TOTAL:', checks, 'HTTP assertions passed')
