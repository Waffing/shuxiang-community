"""Run only against the separately provisioned test server, never the live site."""
import concurrent.futures
import http.cookiejar
import json
import os
import subprocess
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

ORIGIN = os.environ.get('TEST_ORIGIN', 'http://127.0.0.1:18790')
assert urllib.parse.urlsplit(ORIGIN).hostname in ('127.0.0.1', 'localhost')
PASSWORD = 'Integration_test_20260917!'

def call(action, body=None, token=None, cookie=None, origin=None, params=None):
    query = urllib.parse.urlencode({'action': action, **(params or {})}, doseq=True)
    headers = {'Content-Type': 'application/json'}
    if token: headers['Authorization'] = 'Bearer ' + token
    if cookie: headers['Cookie'] = cookie
    if origin: headers['Origin'] = origin
    req = urllib.request.Request(ORIGIN+'/api.php?'+query, data=json.dumps(body).encode() if body is not None else None, headers=headers)
    try:
        response = urllib.request.urlopen(req, timeout=20)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, json.loads(response.read()), response.headers

def require(action, expected=200, **kwargs):
    code, payload, headers = call(action, **kwargs)
    assert code == expected, (action, code, payload)
    return payload, headers

for action in ('publish', 'update_resource', 'reply', 'report', 'favorite_toggle', 'download_token', 'admin_resolve', 'change_password', 'upload_icon'):
    require(action, 401, body={})
tokens = {}
cookies = {}
for role in ('author', 'other', 'admin'):
    payload, headers = require('login', body={'username': 'release_test_'+role, 'password': PASSWORD})
    tokens[role] = payload['token']
    cookies[role] = headers['Set-Cookie'].split(';')[0]
    assert 'HttpOnly' in headers['Set-Cookie'] and 'SameSite=Strict' in headers['Set-Cookie']
require('checkin', 403, body={}, cookie=cookies['author'])
require('checkin', 403, body={}, cookie=cookies['author'], origin='https://invalid.test')
require('checkin', body={}, cookie=cookies['author'], origin=ORIGIN)
require('admin_queue', 403, token=tokens['author'])
require('admin_queue', token=tokens['admin'], params={'queue':'software'})

data = {'name':'测试环境·中文笔记','version':'1.0.0','summary':'仅用于隔离环境验证中文检索与真实分页。',
        'description':'这是隔离环境的自动化测试资源，验证发布、审核、权限和下载配额，不进入正式站点。',
        'category':'办公工具','platforms':['windows'],'download_sources':[{'label':'测试下载源','url':'https://example.org/test'}]}
draft, _ = require('publish', 201, body=data, token=tokens['author'])
sid = draft['id']
assert draft['status'] == 'draft' and draft['points_awarded'] == 0
require('detail', 404, params={'id':sid})
require('detail', params={'id':sid}, token=tokens['author'])
require('update_resource', 403, body={**data,'id':sid}, token=tokens['other'])
require('admin_resolve', 403, body={'queue':'software','id':sid,'status':'published'}, token=tokens['author'])
approved, _ = require('admin_resolve', body={'queue':'software','id':sid,'status':'published'}, cookie=cookies['admin'], origin=ORIGIN)
assert approved['points_awarded'] == 20
repeated, _ = require('admin_resolve', body={'queue':'software','id':sid,'status':'published'}, token=tokens['admin'])
assert repeated['points_awarded'] == 0
result, _ = require('list', params={'q':'笔记','category':'办公工具','limit':1})
assert result['total'] == 1 and result['total_pages'] == 1 and not result['has_more']
resource, _ = require('detail', params={'id':sid})
assert resource['trust_status'] == 'unverified' and resource['views'] is None
assert resource['download_sources'][0]['url'] is None

with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    replies = list(pool.map(lambda _: call('reply', {'software_id':sid,'content':'隔离测试：并发回复反馈'}, tokens['other']), range(6)))
assert all(code == 201 for code, _, _ in replies), replies
assert sum(body['points_awarded'] for _, body, _ in replies) == 1
resource, _ = require('detail', params={'id':sid})
assert resource['reply_count'] == 6 and len(resource['replies']) == 6
require('favorite_toggle', body={'software_id':sid}, token=tokens['other'])
favs, _ = require('favorites', token=tokens['other'], params={'limit':1})
assert favs['total'] == 1 and not favs['has_more']
source = resource['download_sources'][0]['id']
for _ in range(5): require('download_token', body={'source_id':source}, token=tokens['other'])
require('download_token', 429, body={'source_id':source}, token=tokens['other'])
resource, _ = require('detail', params={'id':sid})
assert resource['download_count'] == 5
html = urllib.request.urlopen(ORIGIN+'/software/'+draft['slug']).read().decode()
assert '测试环境·中文笔记' in html and 'application/ld+json' in html and 'rel="canonical"' in html
site = ET.fromstring(urllib.request.urlopen(ORIGIN+'/sitemap.xml').read())
assert any('/software/'+draft['slug'] in node.text for node in site.iter() if node.tag.endswith('loc'))
require('change_password', 403, body={'current_password':'wrong','new_password':'Changed_test_20260917!'}, token=tokens['other'])
require('change_password', body={'current_password':PASSWORD,'new_password':'Changed_test_20260917!'}, cookie=cookies['other'], origin=ORIGIN)
require('me', 401, token=tokens['other'])
require('login', body={'username':'release_test_other','password':'Changed_test_20260917!'})
print('HTTP integration PASS: 401/403, Cookie/Bearer, moderation, search, concurrent replies, quota, SEO, password rotation')
