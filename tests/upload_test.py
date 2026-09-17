"""Real multipart upload checks against the isolated local integration server."""
import json
import struct
import urllib.request
import urllib.error
import zlib
from pathlib import Path

origin = 'http://127.0.0.1:18790'
def api(action, data=None, token=None):
    headers = {'Content-Type': 'application/json'}
    if token: headers['Authorization'] = 'Bearer '+token
    req = urllib.request.Request(origin+'/api.php?action='+action, data=json.dumps(data).encode() if data is not None else None, headers=headers)
    with urllib.request.urlopen(req) as response: return json.load(response)

token = api('login', {'username':'release_test_author','password':'Integration_test_20260917!'})['token']
item = api('my_resources', token=token)['items'][0]
def chunk(kind, value):
    return struct.pack('>I', len(value))+kind+value+struct.pack('>I', zlib.crc32(kind+value))
def png(width, height):
    pixels = zlib.compress((b'\0'+b'\xff\x80\x40'*64)*64)
    return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',width,height,8,2,0,0,0))+chunk(b'IDAT',pixels)+chunk(b'IEND',b'')
def upload(content, mime='image/png'):
    boundary = 'shuxiang-upload-test-boundary'
    body=(f'--{boundary}\r\nContent-Disposition: form-data; name="software_id"\r\n\r\n{item["id"]}\r\n'
          f'--{boundary}\r\nContent-Disposition: form-data; name="icon"; filename="test.png"\r\nContent-Type: {mime}\r\n\r\n').encode()+content+f'\r\n--{boundary}--\r\n'.encode()
    req=urllib.request.Request(origin+'/api.php?action=upload_icon',data=body,headers={'Authorization':'Bearer '+token,'Content-Type':'multipart/form-data; boundary='+boundary})
    try: response=urllib.request.urlopen(req)
    except urllib.error.HTTPError as error: response=error
    return response.status,json.load(response)

assert upload(png(8000,8000))[0] == 422
assert upload(b'<?php echo "not-an-image"; ?>')[0] == 422
code,result=upload(png(64,64))
assert code == 200 and result['format'] == 'webp', (code,result)
assert api('my_resources', token=token)['items'][0]['status'] == 'draft'
path=Path(__file__).resolve().parents[1]/'app/public'/result['icon_path'].lstrip('/')
assert path.read_bytes().startswith(b'RIFF')
path.unlink()
print('Multipart upload PASS: oversized pixels rejected, forged image rejected, valid WebP generated, public edit re-enters review')
