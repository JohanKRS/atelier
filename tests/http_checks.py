"""HTTP integration checks. Run against an isolated test instance, never production."""
import base64, json, os, re, sys, urllib.request, urllib.error, http.cookiejar, sqlite3
base, data_dir, token = sys.argv[1:]
# The checks read the Portuguese interface (assets/i18n/pt-BR.json): every request asks for it.
_build_opener=urllib.request.build_opener
def _portuguese(*handlers):
    opener=_build_opener(*handlers); opener.addheaders.append(('Accept-Language','pt-BR')); return opener
urllib.request.build_opener=_portuguese
urllib.request.install_opener(_portuguese())
class Session:
    def __init__(self):
        self.client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())); self.csrf=''
    def request(self, action, data=None, authenticated=True, csrf_override=None):
        headers = {} if data is None else {'Content-Type':'application/json','X-CSRF-Token': self.csrf if csrf_override is None else csrf_override}
        req=urllib.request.Request(base+'/index.php?action='+action, data=None if data is None else json.dumps(data).encode(), headers=headers)
        try:
            response=(self.client if authenticated else urllib.request.build_opener()).open(req)
            raw=response.read()
            result=json.loads(raw) if 'application/json' in response.headers.get('Content-Type','') else raw
            if isinstance(result,dict) and result.get('csrf'): self.csrf=result['csrf']
            return response.status,result,response.headers
        except urllib.error.HTTPError as e: return e.code,json.loads(e.read()),e.headers
owner=Session()
request=owner.request
def check(ok,msg):
    assert ok,msg
    print('PASS',msg)
code, s, _=request('state')
check(code==200 and not s['initialized'],'fresh instance is uninitialized')
check(request('setup',{'name':'dono','password':'test-password-long-enough','token':'incorrect'})[0]==403,'installation token protects first setup')
check(request('setup',{'name':'','password':'test-password-long-enough','token':token})[0]==422,'the first account needs a name')
check(request('setup',{'name':'dono','password':'test-password-long-enough','token':token})[0]==200,'setup succeeds with installation token')
check(request('state')[1]['user']['name']=='dono' and request('state')[1]['user']['admin'],'the first account is the administrator')
check(request('gallery',authenticated=False)[0]==401,'gallery requires authentication')
check(request('image&id='+'a'*32,authenticated=False)[0]==401,'direct image URL requires authentication')
check(request('comic-sketch&story='+'a'*32+'&page='+'b'*32,authenticated=False)[0]==401,'a comic page\'s storyboard requires authentication')
check(request('settings',{'api_key':'sk-test-placeholder-for-unit-tests'},csrf_override='bad')[0]==403,'CSRF blocks unauthorized mutation')
check(request('settings',{'api_key':'sk-test-placeholder-for-unit-tests'})[0]==200,'API key saves in unlocked vault')
check(request('story-comic',{'story':'a'*32,'mode':'settings','settings':{'kind':'manga'}},csrf_override='bad')[0]==403,'the comic adaptation changes only with the CSRF token')
check(request('story-comic',{'story':'a'*32,'mode':'settings','settings':{'kind':'manga'}})[0]==404 and request('comic-sketch&story='+'a'*32+'&page='+'b'*32)[0]==404,'a comic of a story that does not exist is not found')
check(request('story-comic')[0]==405,'the comic adaptation changes only by POST')
raw=sqlite3.connect(data_dir+'/vault.sqlite').execute("SELECT api_key FROM users WHERE name='dono'").fetchone()[0]
check(b'sk-test' not in bytes(raw),'API key encrypted at rest')
# Each key is taken away the same way (Remover); an empty field alone never takes the OpenAI key away. Testar needs a
# saved key and the CSRF token (with a key it calls the provider, so it is not run here).
check(request('settings',{'api_key':''})[0]==422 and request('state')[1]['has_key'],'an empty OpenAI key is refused, not taken as a removal')
check(request('openai-test',{},csrf_override='bad')[0]==403 and request('claude-test',{},csrf_override='bad')[0]==403,'the key tests need the CSRF token')
code,removed,_=request('settings',{'api_key':'','remove':True})
check(code==200 and removed['has_key'] is False and not request('state')[1]['has_key'],'Remover takes the OpenAI key away')
check(sqlite3.connect(data_dir+'/vault.sqlite').execute("SELECT api_key FROM users WHERE name='dono'").fetchone()[0] is None,'nothing of the key stays in the database')
code,body,_=request('openai-test',{})
check(code==422 and 'Salve a chave da OpenAI' in body['error'],'without a key there is nothing to test')
check(request('claude-test',{})[0]==422,'nor without the Claude key')
check(request('settings',{'api_key':'sk-test-placeholder-for-unit-tests'})[1]['has_key'] is True,'and the key saves again')
check(request('setup',{'name':'outro','password':'test-password-long-enough','token':token})[0]==409,'setup cannot overwrite existing vault')
check(request('password',{'current':'test-password-long-enough','password':'new-test-password-long-enough'})[0]==200,'password change rewraps vault')
check(request('logout',{})[0]==200,'logout succeeds')
check(request('gallery')[0]==401,'logout revokes access')
request('state')
check(request('login',{'name':'dono','password':'test-password-long-enough'})[0]==401,'old password rejected')
check(request('login',{'name':'','password':'new-test-password-long-enough','website':'http://spam.example'})[0]==401,'the hidden field only bots fill refuses even the right password')
code, _, headers=request('login',{'name':'','password':'new-test-password-long-enough','website':''})
check(code==200,'with a single account the password alone unlocks it')
check(any(c.startswith('igs_device=') and 'httponly' in c.lower() for c in headers.get_all('Set-Cookie') or []),'signing in marks the browser as a known device')
robots=urllib.request.urlopen(base+'/robots.txt').read().decode()
check('User-agent: *' in robots and 'Disallow: /' in robots,'robots.txt asks every crawler to stay out')
check('noindex' in urllib.request.urlopen(base+'/index.php').read().decode(),'the page asks not to be indexed')
check(request('state')[1]['has_key'],'encrypted API key survives password change')
check(request('preview&id='+'a'*32,authenticated=False)[0]==401,'generation previews require authentication')
check(request('lineage&id='+'a'*32,authenticated=False)[0]==401,'version trees require authentication')
code, jobs, _=request('jobs&scope=feed')
check(code==200 and jobs['jobs']==[] and isinstance(jobs['now'],int),'request feed answers with the server clock')
check(request('cancel',{'id':'a'*32})[0]==404,'cancelling an unknown request is refused')
check(request('gallery')[2].get('Cache-Control','').startswith('no-store'),'private responses disable caching')
check('no-transform' in request('gallery')[2].get('Cache-Control',''),'proxies may not alter responses')
check(request('gallery')[2].get('X-Robots-Tag','').find('noindex')>=0,'private responses disable indexing')

# Accounts: separate libraries; only the administrator manages them.
check(request('user-create',{'name':'claude','password':'claude-password-long-enough','copy_api_key':True,'mcp':True})[0]==201,'the administrator creates an account')
code, created, _=request('users')
check(code==200 and [u['name'] for u in created['users']]==['dono','claude'] and created['users'][1]['has_key'] and created['users'][1]['mcp'] is not None,'the account list shows the copied API key and MCP access, never the keys')
check(request('user-create',{'name':'Claude','password':'claude-password-long-enough'})[0]==409,'account names are unique')
other=Session(); other.request('state')
check(other.request('login',{'name':'','password':'claude-password-long-enough'})[0]==422,'with several accounts the name is required')
check(other.request('login',{'name':'claude','password':'claude-password-long-enough'})[0]==200,'the new account signs in with its own password')
check(other.request('state')[1]['user']=={'id':created['users'][1]['id'],'name':'claude','admin':False,'created':created['users'][1]['created'],'has_key':True,'mcp':created['users'][1]['mcp']},'the session belongs to the new account')
check(other.request('users')[0]==403 and other.request('user-create',{'name':'x','password':'another-password-long'})[0]==403,'other accounts cannot manage accounts')
check(other.request('gallery')[1]['total']==0,'a new account starts with an empty library')
check(other.request('host-test-status')[0]==403 and other.request('host-test-start',{'seconds':5})[0]==403 and request('host-test-status')[0]==200,'only the administrator runs the hosting test')

# MCP over HTTP, with a token issued to the signed-in account.
code, issued, _=other.request('mcp-token',{})
mcp_token, mcp_url=issued['token'], issued['url']
check(code==200 and mcp_url==base+'/mcp.php' and mcp_token.startswith('igm_'),'an account issues its own MCP token')
def mcp(body, auth=mcp_token, query='', accept='application/json, text/event-stream'):
    headers={'Content-Type':'application/json','Accept':accept,'MCP-Protocol-Version':'2025-06-18'}
    if auth: headers['Authorization']='Bearer '+auth
    req=urllib.request.Request(mcp_url+query, data=json.dumps(body).encode(), headers=headers)
    try:
        response=urllib.request.urlopen(req); raw=response.read().decode()
        return response.status, raw, response.headers
    except urllib.error.HTTPError as e: return e.code, e.read().decode(), e.headers
def rpc(method, params=None, id=1, **kw):
    code, raw, headers=mcp({'jsonrpc':'2.0','id':id,'method':method,'params':params or {}}, **kw)
    if 'text/event-stream' in headers.get('Content-Type',''):
        raw=[line[5:].strip() for line in raw.splitlines() if line.startswith('data:')][-1]
    return code, json.loads(raw), headers
code, body, headers=rpc('initialize',{'protocolVersion':'2025-06-18','capabilities':{},'clientInfo':{'name':'checks','version':'1'}})
check(code==200 and body['result']['serverInfo']['name']=='atelier' and 'application/json' in headers['Content-Type'],'MCP initialize over HTTP')
check(mcp({'jsonrpc':'2.0','method':'notifications/initialized'})[0]==202,'MCP notifications are accepted without a body')
code, body, headers=rpc('tools/list', auth=None)
check(code==401 and 'Bearer' in headers.get('WWW-Authenticate',''),'MCP requires a token')
check(rpc('tools/list', auth=mcp_token[:-1]+('A' if mcp_token[-1]!='A' else 'B'))[0]==401,'a wrong MCP token is refused')
code, body, _=rpc('tools/list', auth=None, query='?token='+mcp_token)
check(code==200 and 'generate_image' in [t['name'] for t in body['result']['tools']],'the token also works in the URL for clients without headers')
try: urllib.request.urlopen(mcp_url); get_code=200
except urllib.error.HTTPError as e: get_code=e.code
check(get_code==405,'MCP offers no GET stream')
code, raw, _=mcp([{'jsonrpc':'2.0','id':1,'method':'ping'},{'jsonrpc':'2.0','id':2,'method':'tools/list'}])
check(code==200 and [r['id'] for r in json.loads(raw)]==[1,2],'MCP answers batches')
png=open(os.path.join(os.path.dirname(__file__),'..','assets','icon-192.png'),'rb').read()
code, body, _=rpc('tools/call',{'name':'import_image','arguments':{'data':base64.b64encode(png).decode(),'name':'icone.png'}})
imported=json.loads(body['result']['content'][0]['text'])
check(code==200 and imported['title']=='icone' and imported['download_url'].startswith(base+'/mcp.php?file='),'MCP imports an image to the token account')
download=urllib.request.urlopen(imported['download_url'])
check(download.read()==png and download.headers['Content-Type']=='image/png' and 'no-store' in download.headers['Cache-Control'],'MCP download links return the full image')
try: urllib.request.urlopen(imported['download_url'][:-3]+'AAA'); bad=200
except urllib.error.HTTPError as e: bad=e.code
check(bad==404,'a tampered download link is refused')
check(other.request('gallery')[1]['total']==1 and request('gallery')[1]['total']==0,'MCP images go only to the token account library')
# Stories over MCP: the writer's tools, on the token account's stories.
code, body, _=rpc('tools/call',{'name':'create_story','arguments':{'title':'Pelo MCP'}})
story_id=json.loads(body['result']['content'][0]['text'])['story_id']
code, body, _=rpc('tools/call',{'name':'write_chapter','arguments':{'story_id':story_id,'title':'Começo','text':'Era uma vez.'}})
check(code==200 and body['result'].get('isError') and 'Call open_story for this story first' in body['result']['content'][0]['text'],'MCP story tools refuse until the story is opened')
code, body, _=rpc('tools/call',{'name':'open_story','arguments':{'story_id':story_id}})
opened=re.search(r'^opened: (\S+)',body['result']['content'][0]['text'],re.M).group(1)
check(code==200 and 'You are now this story' in body['result']['content'][0]['text'] and '<story_state>' in body['result']['content'][0]['text'],'open_story over HTTP gives the instructions and the story')
code, body, _=rpc('tools/call',{'name':'write_chapter','arguments':{'story_id':story_id,'opened':opened,'title':'Começo','text':'Era uma vez.'}})
check(code==200 and not body['result'].get('isError') and 'saved' in body['result']['content'][0]['text'],'MCP writes a chapter of a story')
code, body, headers=rpc('tools/call',{'name':'set_style','arguments':{'story_id':story_id,'opened':opened,'text':'Soft watercolor.'}})
check(code==200 and 'text/event-stream' in headers['Content-Type'] and 'Visual style saved' in body['result']['content'][0]['text'],'story tools that may wait for a picture answer as an event stream')
check([s['title'] for s in other.request('stories')[1]['stories']]==['Pelo MCP'] and request('stories')[1]['stories']==[],'MCP stories belong only to the token account')
# Videos: sent in pieces with the session's CSRF token, played back by byte range, kept out of edits and MCP.
clip=open(os.path.join(os.path.dirname(__file__),'fixtures','frames.mp4'),'rb').read()
def send(session, query, body=None, headers=None, csrf=True):
    headers=dict(headers or {})
    if csrf and body is not None: headers['X-CSRF-Token']=session.csrf
    req=urllib.request.Request(base+'/index.php?action='+query, data=body, headers=headers)
    try:
        response=session.client.open(req); return response.status, response.read(), response.headers
    except urllib.error.HTTPError as e: return e.code, e.read(), e.headers
stranger=Session(); stranger.request('state')
check(stranger.request('video-start',{'size':len(clip)})[0]==401,'video uploads require authentication')
check(other.request('video-start',{'size':4096*1048576})[0]==413,'a video over the server limit is refused before it is sent')
code, started, _=other.request('video-start',{'size':len(clip)})
upload=started['id']
check(code==201 and started['piece']%1048576==0 and 1048576<=started['piece']<=8*1048576,'an upload starts with a piece size that fits post_max_size')
check(send(other,'video-chunk&id=%s&offset=0'%upload,clip,csrf=False)[0]==403,'video pieces need the CSRF token')
check(send(other,'video-chunk&id=%s&offset=0'%upload,b'x'*len(clip))[0]==415,'a file that is not a video is refused')
check(send(owner,'video-chunk&id=%s&offset=0'%upload,clip)[0]==404,'another account cannot send pieces to the upload')
code, raw, _=send(other,'video-chunk&id=%s&offset=0'%upload,clip)
check(code==200 and json.loads(raw)['received']==len(clip) and json.loads(send(other,'video-chunk&id=%s&offset=0'%upload,clip)[1])['received']==len(clip),'pieces are stored once, even when sent again')
check(not [f for f in os.listdir(data_dir+'/blobs') if f.startswith(upload) and clip[:64] in open(data_dir+'/blobs/'+f,'rb').read()],'the video is encrypted on disk')
code, finished, _=other.request('video-finish',{'id':upload,'name':'frames.mp4','duration':2,'width':320,'height':180,'fps':12,'frames':24,'poster':'data:image/png;base64,'+base64.b64encode(png).decode()})
check(code==201 and finished['image']['kind']=='video' and finished['image']['id']==upload and finished['image']['title']=='frames','a finished upload is a video in the library')
code, raw, headers=send(other,'video&id='+upload,headers={'Range':'bytes=0-99'})
check(code==206 and raw==clip[:100] and headers['Content-Range']=='bytes 0-99/%d'%len(clip) and headers['Content-Type']=='video/mp4' and headers['Accept-Ranges']=='bytes','playback reads byte ranges')
code, raw, _=send(other,'video&id='+upload,headers={'Range':'bytes=-10'})
check(code==206 and raw==clip[-10:],'suffix ranges read the end of the video')
code, raw, headers=send(other,'video&id='+upload,headers={'Range':'bytes=%d-'%len(clip)})
check(code==416 and headers['Content-Range']=='bytes */%d'%len(clip),'a range past the end is refused')
code, raw, headers=send(other,'video&id=%s&download=1'%upload)
check(code==200 and raw==clip and headers['Content-Disposition'].startswith('attachment') and 'no-store' in headers.get('Cache-Control',''),'the video downloads whole and is never cached')
check(request('video&id='+upload,authenticated=False)[0]==401 and send(owner,'video&id='+upload)[0]==404,'videos need the session of their account')
code, raw, headers=send(other,'image&id='+upload)
check(code==200 and raw==png and headers['Content-Type']=='image/png','the poster is the video\'s image')
check([i['id'] for i in other.request('gallery&kind=video')[1]['items']]==[upload] and upload not in [i['id'] for i in other.request('gallery&kind=image')[1]['items']],'the gallery filters videos')
code, saved, _=other.request('import',{'data':'data:image/png;base64,'+base64.b64encode(png).decode(),'name':'frames · frame 3','source':{'video_id':upload,'frame':2,'time':0.208333}})
check(code==201 and saved['image']['operation']=='frame' and [f['id'] for f in other.request('frames&id='+upload)[1]['frames']]==[saved['image']['id']],'a saved frame is an image listed with its video')
# Limpar: the image cleaned in the browser is saved as a version of the image it came from; a video has none.
code, cleaned, _=other.request('import',{'data':'data:image/png;base64,'+base64.b64encode(png).decode(),'parent_id':saved['image']['id'],'clean':'media'})
check(code==201 and cleaned['image']['operation']=='clean' and cleaned['image']['label']=='Limpeza média' and cleaned['image']['parent_id']==saved['image']['id'],'a cleaned copy is saved as a version of its image')
check(other.request('import',{'data':'data:image/png;base64,'+base64.b64encode(png).decode(),'parent_id':saved['image']['id'],'clean':'max'})[0]==422
  and other.request('import',{'data':'data:image/png;base64,'+base64.b64encode(png).decode(),'parent_id':upload,'clean':'media'})[0]==422
  and owner.request('import',{'data':'data:image/png;base64,'+base64.b64encode(png).decode(),'parent_id':saved['image']['id'],'clean':'media'})[0]==404,'a cleaned copy needs a valid level and an image of the same account')
check(other.request('create',{'id':'f'*32,'prompt':'edit the video','parent_id':upload,'options':{}})[0]==422 and other.request('create',{'id':'e'*32,'prompt':'x','references':[{'id':upload}],'options':{}})[0]==422,'a video is neither edited nor used as a reference')
listed=json.loads(rpc('tools/call',{'name':'list_images','arguments':{}})[1]['result']['content'][0]['text'])
check(upload not in [i['id'] for i in listed['images']] and saved['image']['id'] in [i['id'] for i in listed['images']],'MCP lists frames, not videos')
check(rpc('tools/call',{'name':'get_image','arguments':{'image_id':upload}})[1]['result']['isError'],'MCP does not open videos')
pending=other.request('video-start',{'size':len(clip)})[1]['id']
code, body, headers=rpc('tools/call',{'name':'generate_image','arguments':{'prompt':'x','size':'1080x1920'},'_meta':{'progressToken':'p1'}})
check(code==200 and 'text/event-stream' in headers['Content-Type'] and body['id']==1 and body['result']['isError'],'generation calls answer as an event stream with tool errors in the result')
code, body, headers=rpc('tools/call',{'name':'generate_image','arguments':{'prompt':'x','size':'1080x1920'}}, accept='application/json')
check(code==200 and 'application/json' in headers['Content-Type'] and body['result']['isError'],'clients without event streams get JSON')
check(other.request('mcp-revoke',{})[0]==200 and rpc('tools/list')[0]==401,'a revoked MCP token stops working')

# Removing an account needs the administrator password and removes its library.
claude_id=created['users'][1]['id']
check(request('user-delete',{'id':claude_id,'password':'wrong-password-long'})[0]==403,'removing an account needs the administrator password')
check(request('user-delete',{'id':request('state')[1]['user']['id'],'password':'new-test-password-long-enough'})[0]==409,'the administrator cannot remove itself')
check(request('user-delete',{'id':claude_id,'password':'new-test-password-long-enough'})[0]==200,'the administrator removes an account')
check(other.request('gallery')[0]==401 and [u['name'] for u in request('users')[1]['users']]==['dono'],'a removed account loses its session')
check(not [f for f in os.listdir(data_dir+'/blobs') if f.startswith((imported['id'],upload,pending,saved['image']['id']))],'a removed account leaves no files, videos or unfinished uploads')
# The stories' reader keeps its settings with the account (sealed), only with the CSRF token, and only what it knows
check(request('state')[1]['reader'] is None,'the reader has no settings saved until they change')
check(request('reader-prefs',{'reader':{'theme':'light'}},csrf_override='bad')[0]==403,'the reader settings need the CSRF token')
code,saved_reader,_=request('reader-prefs',{'reader':{'theme':'sepia','size':120,'mode':'scroll','bogus':1,'width':'huge'}})
check(code==200 and saved_reader['reader']=={'size':120,'theme':'sepia','mode':'scroll'},'the reader settings save to the account, without what the reader does not know')
check(request('state')[1]['reader']=={'size':120,'theme':'sepia','mode':'scroll'},'the state brings the reader settings')
raw=sqlite3.connect(data_dir+'/vault.sqlite').execute("SELECT prefs FROM users WHERE name='dono'").fetchone()[0]
check(b'sepia' not in bytes(raw),'the reader settings are sealed at rest')
check(request('reader-prefs',{'reader':{'progress':False,'remaining':False}})[1]['reader']=={'progress':False,'remaining':False} and request('state')[1]['reader']=={'progress':False,'remaining':False},'the line at the foot of the reader can be turned off and comes back with the state')
check(request('reader-prefs',{'reader':{}})[1]['reader']=={} and request('state')[1]['reader'] is None,'emptied settings go back to the defaults')
check(request('reader-prefs',{'reader':'sepia'})[0]==422,'reader settings must be an object')
# The writer's replies in the stories' chat start hidden; showing them is kept with the account, sealed, beside the reader
check(request('state')[1]['story_prefs']=={},'the writer replies have no choice saved until it changes (hidden)')
check(request('story-prefs',{'stories':{'replies':True}},csrf_override='bad')[0]==403,'the stories settings need the CSRF token')
request('reader-prefs',{'reader':{'theme':'sepia'}})
code,saved_stories,_=request('story-prefs',{'stories':{'replies':True,'bogus':1}})
check(code==200 and saved_stories['stories']=={'replies':True},'showing the writer replies saves to the account, without what it does not know')
check(request('state')[1]['story_prefs']=={'replies':True} and request('state')[1]['reader']=={'theme':'sepia'},'the state brings both, and one section keeps the other')
check(request('story-prefs',{'stories':{'replies':'yes'}})[1]['stories']=={} and request('state')[1]['story_prefs']=={},'a value that is not true or false goes back to hidden')
check(request('story-prefs',{'stories':True})[0]==422,'stories settings must be an object')
request('reader-prefs',{'reader':{}})
# What the person writes about themselves as a reader, for the writer of every story: sealed with the preferences
code,about,_=request('reader-about')
check(code==200 and 'formula' in about['text'] and about['notes']=='' and about['max']==2000,'the administrator starts with a few example reader preferences, and the writer\'s notes empty')
check(request('reader-about-save',{'text':'Gosto de finais abertos.'},csrf_override='bad')[0]==403,'saving it needs the CSRF token')
check(request('reader-about-save',{'text':'  Gosto de finais abertos.  '})[1]['text']=='Gosto de finais abertos.' and request('reader-about')[1]['text']=='Gosto de finais abertos.','it is saved, trimmed')
raw=sqlite3.connect(data_dir+'/vault.sqlite').execute("SELECT prefs FROM users WHERE name='dono'").fetchone()[0]
check(b'finais abertos' not in bytes(raw) and request('state')[1]['story_prefs']=={},'it is sealed at rest, beside the other preferences')
check(request('reader-about-save',{'text':'Gosto de finais abertos.','notes':' - Gosta de diálogos secos. '})[1]['notes']=='- Gosta de diálogos secos.' and request('reader-about-save',{'text':'Gosto de finais abertos.'})[1]['notes']=='- Gosta de diálogos secos.','the person edits the writer\'s notes in the same window; saving without them keeps them')
check(request('reader-about-save',{'text':'ok','notes':'x'*2001})[0]==422 and request('reader-about')[1]['text']=='Gosto de finais abertos.','notes too long are refused, and nothing is saved')
check(request('reader-about-save',{'text':'x'*2001})[0]==422 and request('reader-about-save',{'text':5})[0]==422 and request('reader-about-save',{'text':''})[1]['text']=='' and request('reader-about')[1]['text']=='','too long or not text is refused; empty clears it')
check(request('reader-about',authenticated=False)[0]==401,'it needs the session')
print('HTTP checks passed. No real API calls.')
