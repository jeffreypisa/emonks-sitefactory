"""Local synthetic HTTP fixture only. No real TLS/cache/hosting claim."""
import json,re
from pathlib import Path
from urllib.request import Request,build_opener,HTTPRedirectHandler
from urllib.error import HTTPError
from urllib.parse import urlencode,urlsplit
class NoRedirect(HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):return None
opener=build_opener(NoRedirect);root=Path('/private/tmp/emsf-integration');fixture=json.loads((root/'preview-http.json').read_text());checks=0
cookies={}
def events():
 path=root/'state'/('activity-'+str(fixture['site'])+'.json')
 return json.loads(path.read_text()).get('events',[]) if path.exists() else []
def request(path,method='GET',data=None,jar=None):
 headers={'Host':'online.example.test'}
 if jar:headers['Cookie']='; '.join(k+'='+v for k,v in jar.items())
 if data is not None: data=urlencode(data).encode();headers['Content-Type']='application/x-www-form-urlencoded'
 try:r=opener.open(Request('http://127.0.0.1:18765'+path,data=data,headers=headers,method=method))
 except HTTPError as e:r=e
 body=r.read().decode();return r.code,r.headers,body
def check(value,name):
 global checks
 assert value,name
 checks+=1;print('PASS:',name)
def capture(headers,jar):
 for cookie in headers.get_all('Set-Cookie',[]):
  name,value=cookie.split(';',1)[0].split('=',1);jar[name]=value
share=urlsplit(fixture['share_url'])
status,h,body=request(share.path+'?'+share.query);capture(h,cookies)
check(status==303 and 'emsf_share' not in h['Location'] and not any('emsf_session' in k for k in cookies),'Share link strips token and never authenticates or skips code')
status,h,body=request('/preview-http/',jar=cookies);capture(h,cookies)
check(status==403 and 'Toegangscode' in body and 'PREVIEW CONTENT SENTINEL' not in body,'Anonymous visitor sees only branded code screen')
check('no-store' in h['Cache-Control'] and h['Referrer-Policy']=='no-referrer' and 'noindex' in h['X-Robots-Tag'],'Gate is private no-store no-referrer noindex')
check(events()==[],'Share landing and code screen do not count as a visit')
challenge=re.search(r'name="emsf_challenge" value="([^"]+)"',body).group(1)
status,_,_=request('/preview-http/',method='POST',data={'emsf_preview_code':fixture['code'],'emsf_challenge':challenge})
check(status==403,'Code POST without matching challenge cookie rejected')
status,h,body=request('/preview-http/',method='POST',data={'emsf_preview_code':'!!!!','emsf_challenge':challenge},jar=cookies);capture(h,cookies)
check(status==403 and 'Deze code klopt niet' in body,'Wrong code stays on accessible error screen')
challenge=re.search(r'name="emsf_challenge" value="([^"]+)"',body).group(1)
status,h,body=request('/preview-http/',method='POST',data={'emsf_preview_code':fixture['code'].lower(),'emsf_challenge':challenge},jar=cookies);capture(h,cookies)
check(status==303 and h['Location'].endswith('/preview-http/'),'Lowercase code unlocks with POST-redirect-GET')
session=[v for v in h.get_all('Set-Cookie',[]) if '__Secure-emsf_session_' in v][0]
check('secure' in session.lower() and 'httponly' in session.lower() and 'samesite=lax' in session.lower() and 'path=/preview-http/' in session,'Session cookie is Secure HttpOnly SameSite and site-scoped')
check('wordpress_logged_in' not in session,'No WordPress login cookie issued')
status,h,body=request('/preview-http/',jar=cookies)
check(status==200 and 'PREVIEW CONTENT SENTINEL' in body and 'no-store' in h['Cache-Control'],'Preview can view front-end content without cache leakage')
check(len(events())==1 and events()[0]['source']=='share_link','Rendered front page after correct code records one anonymous share visit')
request('/preview-http/',jar=cookies)
check(len(events())==1,'Front-page reload remains one session')
for name in ['photo.png','photo-150x150.png','font.woff2']:
 status,h,body=request('/preview-http/emsf-media/'+name,jar=cookies);check(status==200 and body=='PRIVATE PREVIEW MEDIA' and 'no-store' in h['Cache-Control'],'Preview can read protected '+name)
for path in ['/preview-http/wp-admin/','/preview-http/wp-admin/admin-ajax.php','/preview-http/wp-json/wp/v2/pages','/preview-http/?rest_route=/wp/v2/pages','/preview-http/xmlrpc.php','/preview-http/feed/','/preview-other/','/preview-other/emsf-media/photo.png']:
 status,_,body=request(path,jar=cookies);check(status in (401,403) and 'PREVIEW CONTENT SENTINEL' not in body and 'PRIVATE PREVIEW MEDIA' not in body,'Preview denied '+path)
for path in ['/preview-http/','/preview-http/wp-json/wp/v2/pages']:
 status,_,body=request(path,method='POST',data={'title':'Not allowed'},jar=cookies);check(status==403,'Preview cannot mutate '+path)
status,_,body=request('/preview-http/emsf-media/..%2F..%2Fpreview-http.json',jar=cookies);check(status==404,'Preview media traversal rejected')
status,_,body=request('/preview-http/wp-login.php',jar=cookies);check(status==200 and 'user_login' in body,'Personal editor login remains available')
check(len(events())==1,'Media, rejected routes and login page add no visits')
(root/'preview-session.cookie').write_text('; '.join(k+'='+v for k,v in cookies.items()));(root/'preview-session.cookie').chmod(0o600)
print('TOTAL:',checks,'preview HTTP assertions passed')
