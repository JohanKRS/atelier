"""Video on a GPU worker (Gpu, gpu.php, colab/atelier_worker.py), end to end against an isolated test instance, never
production. The worker runs here with its test pattern engine (ffmpeg) instead of ComfyUI, so no GPU is needed.

    python3 tests/gpu_checks.py http://127.0.0.1:8099 <data dir> <setup token>

Runs on a fresh instance (it makes the first account) or after tests/http_checks.py (it signs in with TEST_PASSWORD).
"""
import base64, hashlib, http.cookiejar, json, os, sqlite3, struct, sys, tempfile, threading, time, urllib.error, urllib.request, zlib

base, data_dir, setup_token = sys.argv[1:]
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'colab'))
import atelier_worker as w  # noqa: E402


class Session:
    def __init__(self):
        self.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.client.addheaders = [('Accept-Language', 'pt-BR')]
        self.csrf = ''

    def request(self, action, data=None):
        headers = {} if data is None else {'Content-Type': 'application/json', 'X-CSRF-Token': self.csrf}
        req = urllib.request.Request(base + '/index.php?action=' + action, data=None if data is None else json.dumps(data).encode(), headers=headers)
        try:
            response = self.client.open(req)
            raw = response.read()
            result = json.loads(raw) if 'application/json' in response.headers.get('Content-Type', '') else raw
            if isinstance(result, dict) and result.get('csrf'):
                self.csrf = result['csrf']
            return response.status, result
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read())


def check(ok, message):
    assert ok, message
    print('PASS', message)


def gpu(action, body=None, token=None, method='POST', raw=None, query=''):
    headers = {'Content-Type': 'application/json'}
    if token is not None:
        headers['Authorization'] = 'Bearer ' + token
    data = raw if raw is not None else (None if method == 'GET' else json.dumps(body or {}).encode())
    req = urllib.request.Request(f'{base}/gpu.php?action={action}{query}', data=data, method=method, headers=headers)
    try:
        response = urllib.request.urlopen(req)
        text = response.read()
        return response.status, (json.loads(text) if 'json' in response.headers.get('Content-Type', '') else text)
    except urllib.error.HTTPError as e:
        text = e.read()
        try:
            return e.code, json.loads(text)
        except ValueError:
            return e.code, text


def png(width, height, rgb=(200, 80, 40)):
    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)
    rows = b''.join(b'\x00' + bytes(rgb) * width for _ in range(height))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(rows)) + chunk(b'IEND', b'')


def data_url(width, height):
    return 'data:image/png;base64,' + base64.b64encode(png(width, height)).decode()


def new_id():
    return os.urandom(16).hex()


def until(condition, seconds=30, message='condition'):
    end = time.time() + seconds
    while time.time() < end:
        value = condition()
        if value:
            return value
        time.sleep(0.3)
    raise AssertionError('timed out waiting for ' + message)


def job(session, id):
    return next((j for j in session.request('jobs&scope=history')[1]['jobs'] if j['id'] == id), None)


class Run:
    """A worker in a thread, as the notebook runs it."""

    def __init__(self, token, engine=None, max_jobs=None, idle=None):
        self.state = w.State()
        self.api = w.Atelier(base, token, self.state)
        if idle is not None:
            self.api.idle_minutes = lambda: idle
        self.worker = w.Worker(self.api, [engine or w.Fake(steps=3, pause=0.2)], comfy=None, downloads=None,
                               workdir=tempfile.mkdtemp(prefix='atelier-gpu-'), shutdown=False, max_jobs=max_jobs, preload=False)
        self.why = None
        self.thread = threading.Thread(target=self._go, daemon=True)
        self.thread.start()

    def _go(self):
        try:
            self.why = self.worker.run()
        except w.Revoked:
            self.why = 'revoked'
        self.state.set(phase='off')
        try:
            self.api.call('bye', {'reason': self.why}, attempts=1)
        except Exception:
            pass

    def wait(self, seconds=60):
        self.thread.join(seconds)
        assert not self.thread.is_alive(), 'the worker did not stop'
        return self.why


owner = Session()
request = owner.request
state = request('state')[1]
if not state['initialized']:
    check(request('setup', {'name': 'dono', 'password': 'test-password-long-enough', 'token': setup_token})[0] == 200, 'a fresh instance gets its first account')
else:
    password = os.environ.get('TEST_PASSWORD', 'new-test-password-long-enough')
    check(request('login', {'name': 'dono', 'password': password})[0] == 200, 'the owner signs in')

# The token: made in Settings, shown once, kept only as a sealed key.
code, status = request('gpu')
check(code == 200 and status['token'] is None and status['worker'] is None and status['engines']['h3']['audio'] is True, 'without a token there is no worker')
check(request('gpu-token', {})[0] == 200, 'a worker token is made')
code, made = request('gpu-token', {})
token = made['token']
check(code == 200 and token.startswith('igv_') and made['url'].startswith('http') and made['token'] is not None, 'a new token replaces the earlier one and comes with the address')
raw = b''.join(bytes(r[0]) for r in sqlite3.connect(data_dir + '/vault.sqlite').execute('SELECT token_key FROM gpu_workers'))
check(token.split('_')[2].encode() not in raw, 'the token\'s secret is not kept on the server')
check(gpu('hello', {})[0] == 401, 'the worker endpoint needs a token')
check(gpu('hello', {}, token='igv_' + 'a' * 24 + '_' + 'b' * 43)[0] == 401, 'an unknown token is refused')
check(gpu('hello', {}, token=token, method='GET')[0] == 405, 'the worker endpoint answers only POST')
code, hello = gpu('hello', {'state': {'phase': 'setup', 'gpu': 'Test GPU', 'started': int(time.time()), 'engines': ['h3', 'nope']}}, token=token)
check(code == 200 and hello['account'] == 'dono' and 'h3' in hello['engines'] and hello['piece'] >= 1048576 and hello['stop'] is False, 'hello names the account, the models and the piece size')
worker_state = request('gpu')[1]['worker']
check(worker_state and worker_state['gpu'] == 'Test GPU' and worker_state['phase'] == 'setup' and worker_state['engines'] == ['h3'], 'the page sees the worker that is on, with only the models it knows')
code, source = gpu('worker', {}, token=token)
check(code == 200 and b'class Worker' in source and b'def run(' in source, 'the notebook loads the worker from the server')
check(gpu('nothing', {}, token=token)[0] == 404, 'an unknown action is not found')

# Requests are checked against the model before they wait for the GPU.
check(request('video-create', {'id': new_id(), 'prompt': ''})[0] == 422, 'a video needs a prompt')
check(request('video-create', {'id': new_id(), 'prompt': 'x', 'options': {'seconds': 30}})[0] == 422, 'the length stays within the model\'s range')
check(request('video-create', {'id': new_id(), 'prompt': 'x', 'options': {'seconds': 5}, 'inputs': [{'kind': 'image', 'role': 'key', 'at': 7, 'data': data_url(64, 64)}]})[0] == 422, 'a picture is pinned inside the video')
check(request('video-create', {'id': new_id(), 'prompt': 'x', 'options': {'mode': 'video', 'op': 'continue'}, 'inputs': [{'kind': 'image', 'role': 'ref', 'data': data_url(64, 64)}]})[0] == 422, 'a video from a video needs the video')
check(request('video-create', {'id': new_id(), 'prompt': 'x', 'options': {'engine': 'nope'}})[0] == 422, 'an unknown model is refused')

# A video from a first frame: queued, taken by the worker, made, uploaded and kept in the library with its prompt.
first = new_id()
code, created = request('video-create', {'id': first, 'prompt': 'A paper boat sails across a puddle', 'label': 'Barco',
                                         'options': {'mode': 'new', 'seconds': 5, 'aspect': '16:9', 'quality': 'fast', 'resolution': '480p', 'seed': 77},
                                         'inputs': [{'kind': 'image', 'role': 'key', 'at': 0, 'data': data_url(96, 64)}]})
check(code == 201 and created['job']['status'] == 'queued' and created['job']['kind'] == 'video' and created['job']['runner'] == 'gpu', 'a video request waits for the GPU')
check(request('video-create', {'id': first, 'prompt': 'again'})[1]['job']['id'] == first, 'sending the same request again does not make a second one')
check(request('run', {'id': first})[1]['job']['status'] == 'queued', 'the page\'s image runner leaves video requests alone')
check(request('gpu')[1]['queued'] == 1, 'the queue counts the waiting video')
run = Run(token, max_jobs=1)
check(run.wait() == 'done', 'the worker takes the request and finishes it')
done = job(owner, first)
check(done['status'] == 'completed' and len(done['image_ids']) == 1 and done.get('progress') is None, 'the request is completed with its video')
row = next((r for r in request('library')[1]['items'] if r[0] == done['image_ids'][0]), None)
check(row is not None and row[4] & 2, 'the video is in the library')
item = request('detail&id=' + row[0])[1]['image']
check(item['kind'] == 'video' and item['title'] == 'Barco' and item['parent_ids'] == [] and item.get('job_id') == first, 'with the request\'s label')
check(item['prompt'] == 'A paper boat sails across a puddle' and item.get('generated') is True and item.get('audio') is True and item.get('seed') == 77,
      'the video keeps its prompt, its seed and that it has sound')
check(item['width'] > 0 and item['height'] > 0 and abs(item['duration'] - 124 / 24) < 0.2, 'its size and duration come from the file')

# A bigger video goes up in several pieces and comes back byte for byte.
class Big(w.Fake):
    def make(self, worker, job, report, cancelled):
        path = os.path.join(worker.workdir, 'big.mp4')
        w.sh(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=24', '-f', 'lavfi', '-i', 'sine=frequency=330',
              '-t', '6', '-vf', 'noise=alls=90:allf=t+u', '-c:v', 'libx264', '-b:v', '30M', '-minrate', '30M', '-maxrate', '30M', '-bufsize', '4M',
              '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', path], timeout=180)
        self.digest = hashlib.sha256(open(path, 'rb').read()).hexdigest()
        self.size = os.path.getsize(path)
        return path, {'seed': 5}

big = Big()
second = new_id()
request('video-create', {'id': second, 'prompt': 'Rain on a window', 'options': {'seconds': 6}})
run = Run(token, engine=big, max_jobs=1)
check(run.wait(120) == 'done', 'the worker sends a bigger video')
done = job(owner, second)
check(big.size > 2 * hello['piece'] and done['status'] == 'completed', 'a video of several pieces arrives whole')
response = owner.client.open(base + '/index.php?action=video&download=1&id=' + done['image_ids'][0])
check(hashlib.sha256(response.read()).hexdigest() == big.digest, 'the library gives back exactly the video the GPU made')

# Continuing that video: the worker downloads it in pieces, byte for byte, and the continuation comes joined to it.
class Keeps(w.Fake):
    def make(self, worker, job, report, cancelled):
        fetch = w.Inputs.fetch
        def keep(worker_, job_, folder, report_):
            inputs = fetch(worker_, job_, folder, report_)
            self.digests = [hashlib.sha256(open(v['path'], 'rb').read()).hexdigest() for v in inputs.videos]
            return inputs
        w.Inputs.fetch = keep
        try:
            return super().make(worker, job, report, cancelled)
        finally:
            w.Inputs.fetch = fetch

keeps = Keeps(steps=2, pause=0.1)
source = done['image_ids'][0]
following = new_id()
code, created = request('video-create', {'id': following, 'prompt': 'The rain stops and the sun comes out', 'options': {'mode': 'video', 'op': 'continue', 'seconds': 5},
                                         'inputs': [{'kind': 'video', 'role': 'source', 'id': source}]})
check(code == 201 and created['job']['inputs'][0]['id'] == source and created['job']['parent_ids'] == [source], 'a video can be continued')
run = Run(token, engine=keeps, max_jobs=1)
check(run.wait(180) == 'done', 'the worker continues the video')
check(keeps.digests == [big.digest], 'the worker downloads exactly the video it continues')
continued = job(owner, following)
item = request('detail&id=' + continued['image_ids'][0])[1]['image']
check(continued['status'] == 'completed' and abs(item['duration'] - (6 - 22 / 24 + 124 / 24)) < 0.3 and item['parent_ids'] == [source],
      'the continuation comes joined to the video, without repeating its last frames')

# Failing and cancelling.
failing = new_id()
request('video-create', {'id': failing, 'prompt': 'please fail'})
run = Run(token, max_jobs=1)
run.wait()
failed = job(owner, failing)
check(failed['status'] == 'failed' and 'The test asked this request to fail' in failed['error'], 'a video the GPU cannot make fails with its reason')
cancelled = new_id()
request('video-create', {'id': cancelled, 'prompt': 'A slow sunrise'})
run = Run(token, engine=w.Fake(steps=30, pause=0.3), max_jobs=1)
until(lambda: (job(owner, cancelled) or {}).get('status') == 'running', 30, 'the request to start')
check(request('cancel', {'id': cancelled})[1]['job']['status'] == 'cancelling', 'a running video can be cancelled')
run.wait()
check(job(owner, cancelled)['status'] == 'cancelled', 'the worker stops the video and Atelier marks it cancelled')
queued = new_id()
request('video-create', {'id': queued, 'prompt': 'Never made'})
check(request('cancel', {'id': queued})[1]['job']['status'] == 'cancelled', 'a waiting video is cancelled at once')

# Progress the page shows, and a worker that disappears in the middle of a video.
lost = new_id()
request('video-create', {'id': lost, 'prompt': 'Lost in the middle'})
code, claimed = gpu('claim', {'engines': ['h3'], 'wait': 0}, token=token)
check(code == 200 and claimed['job']['id'] == lost and claimed['job']['prompt'] == 'Lost in the middle' and claimed['job']['options']['engine'] == 'h3', 'the worker gets the prompt and the options')
code, answer = gpu('progress', {'job': lost, 'phase': 'generating', 'done': 2, 'total': 6, 'eta': 40, 'message': 'step'}, token=token)
check(code == 200 and answer['cancel'] is False, 'the worker reports its progress')
running = job(owner, lost)
check(running['phase'] == 'generating' and running['progress']['done'] == 2 and running['progress']['total'] == 6, 'the page sees the steps done')
db = sqlite3.connect(data_dir + '/vault.sqlite')
db.execute('UPDATE jobs SET updated=updated-400 WHERE id=?', (lost,))
db.commit()
uncertain = job(owner, lost)
check(uncertain['status'] == 'uncertain' and 'GPU' in uncertain['error'], 'a video whose GPU went silent is marked as interrupted')
check(gpu('progress', {'job': new_id(), 'phase': 'generating'}, token=token)[0] == 404, 'progress for an unknown request is refused')
code, ended = gpu('fail', {'job': lost, 'error': 'gone'}, token=token)
check(code == 200 and ended['job']['status'] == 'failed', 'the worker can still close it')
check(gpu('progress', {'job': lost, 'phase': 'generating'}, token=token)[0] == 409, 'a request that ended tells the worker to drop it')

# Turning off: from the page, when idle, and when the token is revoked.
run = Run(token)
until(lambda: (request('gpu')[1]['worker'] or {}).get('phase') == 'ready', 30, 'the worker to be ready')
status = request('gpu')[1]
check(status['worker']['off_at'] is not None and status['worker']['stopping'] is False, 'the page knows when the GPU turns itself off')
check(request('gpu-stop', {})[1]['worker']['stopping'] is True, 'Turn off now reaches the worker')
check(run.wait() == 'asked', 'the worker turns off when asked')
check(request('gpu')[1]['worker'] is None, 'and the page sees it off')
run = Run(token)
until(lambda: (request('gpu')[1]['worker'] or {}).get('phase') == 'ready', 30, 'a new session')
check(request('gpu')[1]['worker']['stopping'] is False, 'an earlier Turn off now does not stop the next session')
request('gpu-stop', {})
run.wait()
run = Run(token, idle=0.05)
check(run.wait(30) == 'idle', 'the worker turns off by itself without requests')
check(request('gpu-idle', {'minutes': 7})[0] == 422 and request('gpu-idle', {'minutes': 30})[1]['idle_minutes'] == 30, 'the idle minutes come from a short list')
run = Run(token)
until(lambda: (request('gpu')[1]['worker'] or {}).get('phase') == 'ready', 30, 'the worker to be ready')
check(request('gpu-revoke', {})[1]['token'] is None, 'the token can be revoked')
orphan = new_id()
request('video-create', {'id': orphan, 'prompt': 'Asked while the token is revoked'})
check(run.wait() == 'revoked', 'a revoked token stops the worker')
check(job(owner, orphan)['status'] == 'queued', 'a worker waiting for requests with a revoked token takes none')
request('cancel', {'id': orphan})
check(gpu('hello', {}, token=token)[0] == 401, 'and it opens nothing anymore')

# The library and the feed as they were, for the browser checks that run on this instance next.
for ended in request('jobs&scope=history')[1]['jobs']:
    if ended.get('kind') != 'video':
        continue
    for made_id in ended.get('image_ids') or []:
        request('trash', {'id': made_id})
        request('purge', {'id': made_id})
    request('dismiss', {'id': ended['id']})
check(not any(r[4] & 2 for r in request('library')[1]['items']), 'the videos made here leave the library')
print('All GPU checks passed.')
