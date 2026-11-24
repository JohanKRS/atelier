"""Atelier's GPU worker.

Runs in a notebook on a GPU (Google Colab), takes the video and picture requests of one Atelier account and sends the
videos and pictures back to its library. It only ever calls the Atelier server (gpu.php) with short requests: nothing on
the GPU is exposed to the internet, no tunnel or proxy is used, and the models run in ComfyUI on 127.0.0.1 inside the
runtime.

    import atelier_worker
    atelier_worker.run('https://atelier.example.com', token)

The runtime stays on while requests come, so the models are downloaded and loaded once per session, and it turns itself
off (google.colab.runtime.unassign) after the account's idle minutes without a request, or when someone presses "Turn
off now" in Atelier, so the GPU's credits are not spent on waiting.

The notebook (atelier_gpu.ipynb) loads this file from the Atelier server, so it always matches the server's version.
"""
from __future__ import annotations

import base64
import hashlib
import io
import json
import math
import os
import random
import re
import shutil
import subprocess
import sys
import threading
import time
import traceback
import urllib.error
import urllib.parse
import urllib.request
import uuid

VERSION = '1.2'
USER_AGENT = 'AtelierGpuWorker/' + VERSION
# The ComfyUI release the workflows below were written and checked against (its MiniMax H3 nodes).
COMFY_REPO = 'https://github.com/comfyanonymous/ComfyUI'
COMFY_REF = 'v0.46.0'
# The longest a single video or picture may take before the worker gives up on it.
JOB_TIMEOUT = 75 * 60
# The least GPU memory the models work with (MB): an A100 has 40 or 80 GB, a G4 (RTX PRO 6000 Blackwell) 96 GB. An L4
# (22 GB) or a T4 (15 GB) can't hold them.
MIN_GPU_MB = 38000


def log(message: str) -> None:
    print(time.strftime('%H:%M:%S'), message, flush=True)


class Revoked(Exception):
    """The token was revoked or replaced in Atelier: nothing more can be done with it."""


class Gone(Exception):
    """The request is no longer running on Atelier (it was cancelled or ended there): drop it."""


class Cancelled(Exception):
    """The owner cancelled the request while it was being made."""


class WrongGpu(Exception):
    """The runtime's GPU is too small for the models (Colab gave another GPU than the A100)."""


def gpu_problem(name: str, memory: int) -> str:
    """What is wrong with the runtime's GPU for the models, or '' when it will do."""
    if memory >= MIN_GPU_MB:
        return ''
    what = f'an {name} with {round(memory / 1024)} GB of GPU memory' if name else 'no GPU'
    return (f'This runtime has {what}, and MiniMax H3 and H3-Image need an A100 or a G4 (RTX PRO 6000, 96 GB). In Colab '
            'choose Runtime › Change runtime type › A100 or G4, then Runtime › Run all. If Colab still gives another GPU, '
            'those are busy: try the other one, or again later.')


class ServerError(Exception):
    def __init__(self, status: int, message: str):
        super().__init__(message)
        self.status = status


# ---------------------------------------------------------------------------------------------------------------------
# Atelier
# ---------------------------------------------------------------------------------------------------------------------

class State:
    """How the worker is, sent with every call so the page shows it: phase, GPU, a line about what it does."""

    def __init__(self):
        self.lock = threading.Lock()
        self.data = {'phase': 'setup', 'message': 'Starting', 'started': int(time.time()), 'version': VERSION}

    def set(self, **values) -> None:
        with self.lock:
            for name, value in values.items():
                if value is None:
                    self.data.pop(name, None)
                else:
                    self.data[name] = value

    def snapshot(self) -> dict:
        with self.lock:
            return dict(self.data)


class Atelier:
    """The account's endpoint for workers: gpu.php, authenticated by the worker token."""

    def __init__(self, url: str, token: str, state: State):
        url = url.strip().rstrip('/')
        if not re.match(r'^https?://', url):
            url = 'https://' + url
        url = re.sub(r'/(index|gpu|mcp)\.php$', '', url)
        self.endpoint = url + '/gpu.php'
        self.token = token.strip()
        self.state = state
        self.last_call = 0.0
        self.last_contact = time.time()
        self.lock = threading.Lock()
        self.directives = {'stop': False, 'idle_minutes': 15}

    def _post(self, query: dict, body: bytes, content_type: str, timeout: float):
        request = urllib.request.Request(self.endpoint + '?' + urllib.parse.urlencode(query), data=body, method='POST', headers={
            'Authorization': 'Bearer ' + self.token, 'Content-Type': content_type, 'Accept': 'application/json', 'User-Agent': USER_AGENT})
        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                return response.status, response.read()
        except urllib.error.HTTPError as error:
            return error.code, error.read()

    def call(self, action: str, body: dict | None = None, *, attempts: int = 6, timeout: float = 60, raw: bytes | None = None,
             query: dict | None = None) -> dict:
        """One call, retried with a growing pause when the network or the server fails. 4xx answers are not retried."""
        if raw is None:
            payload = dict(body or {})
            payload['state'] = self.state.snapshot()
            data, content_type = json.dumps(payload).encode(), 'application/json'
        else:
            data, content_type = raw, 'application/octet-stream'
        error: Exception = RuntimeError('No attempt was made.')
        for attempt in range(attempts):
            self.last_call = time.time()
            try:
                status, answer = self._post({'action': action, **(query or {})}, data, content_type, timeout)
            except (urllib.error.URLError, TimeoutError, ConnectionError, OSError) as failure:
                error = failure
            else:
                try:
                    result = json.loads(answer)
                except ValueError:
                    result = None
                message = result.get('error') if isinstance(result, dict) and isinstance(result.get('error'), str) else f'HTTP {status}'
                if status == 401:
                    raise Revoked(message)
                if 200 <= status < 300 and isinstance(result, dict):
                    self.last_contact = time.time()
                    with self.lock:
                        for name in ('stop', 'idle_minutes'):
                            if name in result:
                                self.directives[name] = result[name]
                    return result
                if status == 409:
                    raise Gone(message)
                error = ServerError(status, message)
                if 400 <= status < 500 and status not in (408, 425, 429):
                    raise error
            if attempt < attempts - 1:
                time.sleep(min(30, 2 ** attempt) + random.random())
        raise error

    def fetch(self, action: str, query: dict, *, attempts: int = 6, timeout: float = 120) -> bytes:
        """Bytes from Atelier (a piece of a video a request starts from), retried as call() does."""
        error: Exception = RuntimeError('No attempt was made.')
        for attempt in range(attempts):
            self.last_call = time.time()
            try:
                status, answer = self._post({'action': action, **query}, b'{}', 'application/json', timeout)
            except (urllib.error.URLError, TimeoutError, ConnectionError, OSError) as failure:
                error = failure
            else:
                if 200 <= status < 300:
                    self.last_contact = time.time()
                    return answer
                try:
                    message = json.loads(answer).get('error') or f'HTTP {status}'
                except (ValueError, AttributeError):
                    message = f'HTTP {status}'
                if status == 401:
                    raise Revoked(message)
                if status == 409:
                    raise Gone(message)
                error = ServerError(status, message)
                if 400 <= status < 500 and status not in (408, 425, 429):
                    raise error
            if attempt < attempts - 1:
                time.sleep(min(30, 2 ** attempt) + random.random())
        raise error

    def stop_wanted(self) -> bool:
        with self.lock:
            return bool(self.directives.get('stop'))

    def idle_minutes(self) -> int:
        with self.lock:
            return int(self.directives.get('idle_minutes') or 15)


# ---------------------------------------------------------------------------------------------------------------------
# The runtime
# ---------------------------------------------------------------------------------------------------------------------

def sh(command: list[str], *, cwd: str | None = None, timeout: float | None = None, check: bool = True) -> str:
    result = subprocess.run(command, cwd=cwd, timeout=timeout, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    if check and result.returncode != 0:
        raise RuntimeError(f'{" ".join(command[:3])} failed: {result.stdout.strip()[-800:]}')
    return result.stdout


def gpu_info() -> tuple[str, int, tuple[int, int] | None]:
    """The GPU's name, its memory in MB and the CUDA version its driver supports (nvidia-smi), or ('', 0, None)."""
    if not shutil.which('nvidia-smi'):
        return '', 0, None
    try:
        line = sh(['nvidia-smi', '--query-gpu=name,memory.total', '--format=csv,noheader,nounits'], timeout=30).strip().splitlines()[0]
        name, memory = [part.strip() for part in line.split(',')[:2]]
        header = sh(['nvidia-smi'], timeout=30, check=False)
        match = re.search(r'CUDA Version:\s*(\d+)\.(\d+)', header)
        return name, int(float(memory)), (int(match[1]), int(match[2])) if match else None
    except Exception:
        return '', 0, None


def runs_on(arch: str, archs: list[str]) -> bool:
    """Whether a PyTorch build with these architectures (torch.cuda.get_arch_list()) runs on a GPU of this one (sm_86…):
    its own kernels, or PTX of an older one that the driver compiles for it."""
    if not arch:
        return True
    number = lambda name: int(re.sub(r'\D', '', name) or 0)
    return arch in archs or any(a.startswith('compute_') and number(a) <= number(arch) for a in archs)


def in_colab() -> bool:
    return 'google.colab' in sys.modules or os.path.isdir('/content') and os.environ.get('COLAB_RELEASE_TAG') is not None


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Downloads:
    """The models' files, downloaded once per runtime (aria2c, several connections per file) into ComfyUI's models folder.
    Hugging Face gets the HF_TOKEN secret and Civitai the CIVITAI_TOKEN one, for files that need an account."""

    def __init__(self, models: str, hf_token: str | None = None, civitai_token: str | None = None):
        self.models = models
        self.hf_token = hf_token or None
        self.civitai_token = civitai_token or None
        self.sizes: dict[str, int] = {}

    @staticmethod
    def host(url: str) -> str:
        return (urllib.parse.urlparse(url).hostname or '').lower()

    def headers(self, url: str) -> dict:
        headers = {'User-Agent': USER_AGENT}
        if self.hf_token and self.host(url) in ('huggingface.co', 'hf.co'):
            headers['Authorization'] = 'Bearer ' + self.hf_token
        return headers

    def source(self, url: str) -> str:
        """The address to download from: Civitai takes its token in the address (its files redirect to storage that
        refuses other credentials)."""
        if self.civitai_token and self.host(url) in ('civitai.com', 'www.civitai.com', 'civitai.green'):
            return url + ('&' if '?' in url else '?') + 'token=' + urllib.parse.quote(self.civitai_token)
        return url

    def hide(self, text: str) -> str:
        for secret in (self.hf_token, self.civitai_token):
            if secret:
                text = text.replace(secret, '***').replace(urllib.parse.quote(secret), '***')
        return text

    def path(self, folder: str, name: str) -> str:
        return os.path.join(self.models, folder, name)

    def complete(self, folder: str, name: str) -> bool:
        path = self.path(folder, name)
        return os.path.isfile(path) and not os.path.exists(path + '.aria2') and os.path.getsize(path) > 0

    def size(self, url: str, guess: float) -> int:
        if url in self.sizes:
            return self.sizes[url]
        # Civitai answers with a redirect to signed storage, which may refuse a HEAD: its answer is enough.
        opener = urllib.request.build_opener(_NoRedirect) if 'civitai' in self.host(url) else urllib.request.build_opener()
        try:
            with opener.open(urllib.request.Request(self.source(url), method='HEAD', headers=self.headers(url)), timeout=30) as response:
                size = int(response.headers.get('Content-Length') or 0)
                if 'text/html' in (response.headers.get('Content-Type') or ''):
                    raise RuntimeError(f'{url} is a web page, not a file. Use the link of the file itself.')
        except urllib.error.HTTPError as error:
            if error.code in (301, 302, 303, 307, 308):
                size = 0
            elif error.code in (401, 403):
                if 'civitai' in self.host(url):
                    raise RuntimeError(f'Civitai refused {url} (HTTP {error.code}). This file needs a Civitai account: add a CIVITAI_TOKEN '
                                       'secret in Colab with an API key from civitai.com (Account settings › API keys).') from None
                if 'huggingface' in self.host(url) or self.host(url) == 'hf.co':
                    raise RuntimeError(f'Hugging Face refused {url.rsplit("/", 1)[-1]} (HTTP {error.code}). This model asks you to accept its '
                                       'license on its Hugging Face page and an HF_TOKEN secret in Colab.') from None
                raise RuntimeError(f'{self.host(url)} refused {url.rsplit("/", 1)[-1]} (HTTP {error.code}).') from None
            elif error.code == 404:
                raise RuntimeError(f'{url} was not found (HTTP 404). Check the link.') from None
            else:
                size = 0
        except RuntimeError:
            raise
        except Exception:
            size = 0
        self.sizes[url] = size or int(guess * 1e9)
        return self.sizes[url]

    def ensure(self, files: list[tuple[str, str, str, float]], say) -> None:
        """files: (folder, name, url, approximate GB). say(message, done_bytes, total_bytes) reports the progress."""
        missing = [f for f in files if not self.complete(f[0], f[1])]
        if not missing:
            return
        total = sum(self.size(url, guess) for _, _, url, guess in missing)
        free = shutil.disk_usage(self.models).free
        have = sum(os.path.getsize(self.path(f, n)) for f, n, _, _ in missing if os.path.isfile(self.path(f, n)))
        if total - have > free - 2e9:
            raise RuntimeError(f'Not enough disk in the runtime for the models: {(total - have) / 1e9:.1f} GB needed, {free / 1e9:.1f} GB free.')
        names = ', '.join(n for _, n, _, _ in missing)
        log(f'Downloading {total / 1e9:.1f} GB: {names}')
        for folder, _, _, _ in missing:
            os.makedirs(os.path.join(self.models, folder), exist_ok=True)
        if not shutil.which('aria2c') and shutil.which('apt-get'):
            sh(['apt-get', 'install', '-y', '-qq', 'aria2'], timeout=300, check=False)
        if shutil.which('aria2c'):
            self._aria2(missing, total, say)
        else:
            for folder, name, url, _ in missing:
                self._urllib(folder, name, url, total, missing, say)
        for folder, name, _, _ in missing:
            if not self.complete(folder, name):
                raise RuntimeError(f'The download of {name} did not finish.')

    def _done(self, missing) -> int:
        return sum(os.path.getsize(self.path(f, n)) for f, n, _, _ in missing if os.path.isfile(self.path(f, n)))

    def _aria2(self, missing, total: int, say) -> None:
        listing = os.path.join(self.models, '.atelier-downloads.txt')
        with open(listing, 'w') as out:
            for folder, name, url, _ in missing:
                out.write(f'{self.source(url)}\n  dir={os.path.join(self.models, folder)}\n  out={name}\n')
                if 'Authorization' in self.headers(url):
                    out.write(f'  header=Authorization: {self.headers(url)["Authorization"]}\n')
        process = subprocess.Popen(['aria2c', '-i', listing, '-j', '4', '-x', '16', '-s', '16', '-k', '4M', '--file-allocation=none',
                                    '--continue=true', '--auto-file-renaming=false', '--allow-overwrite=true', '--max-tries=8',
                                    '--retry-wait=5', '--console-log-level=error', '--summary-interval=0', '--download-result=hide'],
                                   stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        output: list[str] = []
        reader = threading.Thread(target=lambda: output.extend(process.stdout), daemon=True)
        reader.start()
        while process.poll() is None:
            say('download', self._done(missing), total)
            time.sleep(3)
        reader.join(5)
        os.remove(listing)
        if process.returncode != 0:
            raise RuntimeError('The download of the models failed: ' + self.hide(''.join(output).strip())[-600:])
        say('download', total, total)

    def _urllib(self, folder: str, name: str, url: str, total: int, missing, say) -> None:
        target = self.path(folder, name)
        with urllib.request.urlopen(urllib.request.Request(self.source(url), headers=self.headers(url)), timeout=60) as response, \
                open(target + '.part', 'wb') as out:
            last = 0.0
            while chunk := response.read(8 << 20):
                out.write(chunk)
                if time.time() - last > 3:
                    last = time.time()
                    say('download', self._done(missing) + out.tell(), total)
        os.replace(target + '.part', target)


class Comfy:
    """ComfyUI, installed in the runtime and listening on 127.0.0.1 only."""

    PHASES = {'UNETLoader': 'loading', 'CLIPLoader': 'loading', 'VAELoader': 'loading', 'LoraLoaderModelOnly': 'loading',
              'LoadImage': 'loading', 'MiniMaxH3ImageToVideo': 'encoding', 'MiniMaxH3ReferenceToVideo': 'encoding',
              'SamplerCustomAdvanced': 'generating', 'VAEDecode': 'decoding', 'VAEDecodeAudio': 'decoding',
              'CreateVideo': 'saving', 'SaveVideo': 'saving', 'MiniMaxH3ImageConditioning': 'encoding', 'KSampler': 'generating',
              'SaveImage': 'saving', 'TextGenerateV2': 'planning'}
    # The nodes whose steps are the progress of a request.
    SAMPLERS = ('SamplerCustomAdvanced', 'KSampler', 'TextGenerateV2')

    def __init__(self, root: str, port: int = 8188, ref: str = COMFY_REF):
        self.root = root
        self.port = port
        self.ref = ref
        self.process: subprocess.Popen | None = None
        self.log_path = os.path.join(os.path.dirname(root.rstrip('/')) or '.', 'comfyui.log')

    @property
    def models(self) -> str:
        return os.path.join(self.root, 'models')

    def install(self, say) -> None:
        if not os.path.isfile(os.path.join(self.root, 'main.py')):
            say('Installing ComfyUI ' + self.ref)
            sh(['git', 'clone', '--quiet', '--depth', '1', '--branch', self.ref, COMFY_REPO, self.root], timeout=600)
        say('Installing ComfyUI\'s packages')
        sh([sys.executable, '-m', 'pip', 'install', '-q', '-r', os.path.join(self.root, 'requirements.txt'), 'websocket-client'], timeout=1800)
        # The int8 and nvfp4 kernels of comfy-kitchen need a PyTorch built for CUDA 13; with an older one they run slower.
        # A newer GPU than the PyTorch build knows (a G4's Blackwell, sm_120) needs at least a CUDA 12.8 build to run at all.
        _, _, driver = gpu_info()
        probe = ('import json,torch;c=torch.cuda.get_device_capability() if torch.cuda.is_available() else None;'
                 'print(json.dumps([torch.__version__,torch.version.cuda,"sm_%d%d"%c if c else "",torch.cuda.get_arch_list() if c else []]))')
        version, built, arch, archs = json.loads(sh([sys.executable, '-c', probe], timeout=300).strip().splitlines()[-1])
        cuda = tuple(int(x) for x in (built or '0.0').split('.')[:2])
        if cuda < (13, 0) and driver is not None and driver >= (13, 0):
            say(f'Updating PyTorch {version} (CUDA {built}) to a CUDA 13 build')
            sh([sys.executable, '-m', 'pip', 'install', '-q', '--upgrade', 'torch', 'torchvision', 'torchaudio', '--index-url',
                'https://download.pytorch.org/whl/cu130'], timeout=1800)
        elif not runs_on(arch, archs) and cuda < (12, 8):
            say(f'PyTorch {version} (CUDA {built}) has no kernels for this GPU ({arch}): installing a CUDA 12.8 build')
            sh([sys.executable, '-m', 'pip', 'install', '-q', '--upgrade', 'torch', 'torchvision', 'torchaudio', '--index-url',
                'https://download.pytorch.org/whl/cu128'], timeout=1800)
        elif cuda < (13, 0):
            log(f'PyTorch {version} uses CUDA {built} and the driver does not support CUDA 13: the quantized models run slower.')

    def start(self, say) -> None:
        if self.alive():
            return
        say('Starting ComfyUI')
        logfile = open(self.log_path, 'ab')
        self.process = subprocess.Popen([sys.executable, 'main.py', '--listen', '127.0.0.1', '--port', str(self.port),
                                         '--disable-auto-launch', '--preview-method', 'none', '--disable-metadata'],
                                        cwd=self.root, stdout=logfile, stderr=subprocess.STDOUT)
        until = time.time() + 900
        while time.time() < until:
            if self.process.poll() is not None:
                raise RuntimeError('ComfyUI stopped while starting: ' + self.log_tail())
            try:
                self.get('/system_stats', timeout=5)
                return
            except Exception:
                time.sleep(2)
        raise RuntimeError('ComfyUI did not start in 15 minutes: ' + self.log_tail())

    def alive(self) -> bool:
        return self.process is not None and self.process.poll() is None

    def stop(self) -> None:
        if self.alive():
            self.process.terminate()
            try:
                self.process.wait(20)
            except subprocess.TimeoutExpired:
                self.process.kill()

    def log_tail(self, lines: int = 12) -> str:
        try:
            with open(self.log_path, 'rb') as f:
                f.seek(max(0, os.path.getsize(self.log_path) - 20000))
                text = f.read().decode('utf-8', 'replace')
            return ' | '.join(line.strip() for line in text.strip().splitlines()[-lines:])[-900:]
        except OSError:
            return ''

    def url(self, path: str) -> str:
        return f'http://127.0.0.1:{self.port}{path}'

    def get(self, path: str, timeout: float = 30):
        with urllib.request.urlopen(self.url(path), timeout=timeout) as response:
            return json.loads(response.read())

    def post(self, path: str, body: dict, timeout: float = 60):
        request = urllib.request.Request(self.url(path), data=json.dumps(body).encode(), headers={'Content-Type': 'application/json'})
        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                text = response.read()
                return json.loads(text) if text.strip() else {}
        except urllib.error.HTTPError as error:
            text = error.read().decode('utf-8', 'replace')
            try:
                detail = json.loads(text)
            except ValueError:
                detail = text
            raise ComfyRefused(detail) from None

    def input_dir(self) -> str:
        path = os.path.join(self.root, 'input')
        os.makedirs(path, exist_ok=True)
        return path

    def interrupt(self, prompt_id: str) -> None:
        for path, body in (('/interrupt', {'prompt_id': prompt_id}), ('/queue', {'delete': [prompt_id]})):
            try:
                self.post(path, body, timeout=10)
            except Exception:
                pass

    def run(self, graph: dict, output: str, report, cancelled, timeout: float = JOB_TIMEOUT, what: str = 'video') -> str:
        """Runs a workflow (API format) and returns the path of the file its output node saved, or the text it shows
        (PreviewAny). what names the result in the messages: video, picture or prompt."""
        if not self.alive():
            raise RuntimeError('ComfyUI is not running: ' + self.log_tail())
        client = uuid.uuid4().hex
        socket = None
        try:
            import websocket  # websocket-client, installed with ComfyUI's packages
            socket = websocket.create_connection(f'ws://127.0.0.1:{self.port}/ws?clientId={client}', timeout=3)
        except Exception as error:
            log(f'No live progress from ComfyUI ({error}); checking every few seconds instead.')
        try:
            answer = self.post('/prompt', {'prompt': graph, 'client_id': client})
            prompt_id = answer['prompt_id']
            started = time.time()
            sampler = next((k for k, v in graph.items() if v['class_type'] in self.SAMPLERS), None)
            report('loading')
            while True:
                if cancelled():
                    self.interrupt(prompt_id)
                    raise Cancelled()
                if time.time() - started > timeout:
                    self.interrupt(prompt_id)
                    raise RuntimeError(f'The {what} took more than {int(timeout // 60)} minutes.')
                if not self.alive():
                    raise RuntimeError(f'ComfyUI stopped while making the {what} (often out of memory): ' + self.log_tail())
                if socket is None:
                    time.sleep(3)
                    report(None)
                    if self._finished(prompt_id):
                        break
                    continue
                try:
                    message = socket.recv()
                except Exception as error:
                    if type(error).__name__ == 'WebSocketTimeoutException':
                        report(None)
                        if time.time() - started > 30 and int(time.time()) % 15 == 0 and self._finished(prompt_id):
                            break
                        continue
                    log(f'Lost the live progress from ComfyUI ({error}).')
                    socket = None
                    continue
                if not isinstance(message, str):
                    continue
                event = json.loads(message)
                kind, data = event.get('type'), event.get('data') or {}
                if data.get('prompt_id') not in (None, prompt_id):
                    continue
                if kind == 'executing':
                    node = data.get('node')
                    if node is None:
                        break
                    report(self.PHASES.get(graph.get(str(node), {}).get('class_type', ''), None))
                elif kind == 'progress' and str(data.get('node')) == sampler:
                    report(self.PHASES.get(graph[sampler]['class_type'], 'generating'), int(data.get('value', 0)), int(data.get('max', 0)))
                elif kind == 'execution_error':
                    raise RuntimeError(self._error(data, what))
                elif kind == 'execution_interrupted':
                    raise Cancelled()
                elif kind == 'execution_success':
                    break
            history = self.get(f'/history/{prompt_id}').get(prompt_id) or {}
            status = history.get('status') or {}
            if status.get('status_str') == 'error':
                for name, data in status.get('messages') or []:
                    if name == 'execution_error':
                        raise RuntimeError(self._error(data, what))
                raise RuntimeError(f'ComfyUI could not make the {what}: ' + self.log_tail())
            result = (history.get('outputs') or {}).get(output, {})
            if what == 'prompt':
                text = result.get('text') or []
                if not text or not isinstance(text[0], str):
                    raise RuntimeError('ComfyUI finished without the prompt: ' + self.log_tail())
                return text[0]
            files = result.get('images') or []
            if not files:
                raise RuntimeError(f'ComfyUI finished without a {what}: ' + self.log_tail())
            saved = files[0]
            return os.path.join(self.root, saved.get('type') or 'output', saved.get('subfolder') or '', saved['filename'])
        finally:
            if socket is not None:
                try:
                    socket.close()
                except Exception:
                    pass

    def _finished(self, prompt_id: str) -> bool:
        try:
            return prompt_id in self.get(f'/history/{prompt_id}', timeout=10)
        except Exception:
            return False

    @staticmethod
    def _error(data: dict, what: str = 'video') -> str:
        message = str(data.get('exception_message') or 'unknown error').strip()
        node = data.get('node_type') or ''
        if 'out of memory' in message.lower() or 'OutOfMemory' in str(data.get('exception_type')):
            if what == 'video':
                return f'The GPU ran out of memory ({node}). Try 480p, a shorter video or a GPU with more memory (A100 80 GB).'
            return f'The GPU ran out of memory ({node}). Try a lower quality, fewer pictures or a GPU with more memory (A100 80 GB).'
        return f'{node}: {message}'[:500]


class ComfyRefused(RuntimeError):
    """ComfyUI refused the workflow (an invalid input, a missing model file)."""

    def __init__(self, detail):
        if isinstance(detail, dict):
            parts = [str((detail.get('error') or {}).get('message') or '')]
            for node, info in (detail.get('node_errors') or {}).items():
                for error in info.get('errors', []):
                    parts.append(f'{info.get("class_type", node)}: {error.get("message")} {error.get("details", "")}'.strip())
            detail = '; '.join(p for p in parts if p)
        super().__init__('ComfyUI refused the workflow: ' + str(detail)[:600])


# ---------------------------------------------------------------------------------------------------------------------
# The models
# ---------------------------------------------------------------------------------------------------------------------

def image_size(data: bytes) -> tuple[int, int]:
    """Width and height of a PNG, JPEG, WebP or GIF, read from its header."""
    if data[:8] == b'\x89PNG\r\n\x1a\n':
        return int.from_bytes(data[16:20], 'big'), int.from_bytes(data[20:24], 'big')
    if data[:6] in (b'GIF87a', b'GIF89a'):
        return int.from_bytes(data[6:8], 'little'), int.from_bytes(data[8:10], 'little')
    if data[:4] == b'RIFF' and data[8:12] == b'WEBP':
        chunk = data[12:16]
        if chunk == b'VP8X':
            return 1 + int.from_bytes(data[24:27], 'little'), 1 + int.from_bytes(data[27:30], 'little')
        if chunk == b'VP8L':
            bits = int.from_bytes(data[21:25], 'little')
            return 1 + (bits & 0x3FFF), 1 + ((bits >> 14) & 0x3FFF)
        return int.from_bytes(data[26:28], 'little') & 0x3FFF, int.from_bytes(data[28:30], 'little') & 0x3FFF
    if data[:2] == b'\xff\xd8':
        at = 2
        while at + 9 < len(data):
            if data[at] != 0xFF:
                at += 1
                continue
            marker = data[at + 1]
            if marker in (0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF):
                return int.from_bytes(data[at + 7:at + 9], 'big'), int.from_bytes(data[at + 5:at + 7], 'big')
            if marker in (0xD8, 0x01) or 0xD0 <= marker <= 0xD7:
                at += 2
                continue
            at += 2 + int.from_bytes(data[at + 2:at + 4], 'big')
    raise ValueError('Unknown picture format.')


AUDIO_EXTENSIONS = {'mpeg': 'mp3', 'mp3': 'mp3', 'wav': 'wav', 'x-wav': 'wav', 'wave': 'wav', 'vnd.wave': 'wav', 'ogg': 'ogg', 'mp4': 'm4a',
                    'x-m4a': 'm4a', 'm4a': 'm4a', 'aac': 'aac', 'flac': 'flac', 'x-flac': 'flac', 'webm': 'webm'}


def decode_data_url(url: str, kind: str = 'image') -> tuple[bytes, str]:
    """The bytes of a picture (or a sound) sent as a data URL, and the file extension that fits them."""
    if kind == 'audio':
        match = re.match(r'^data:audio/([\w.+-]+);base64,(.*)$', url, re.S)
        if not match:
            raise ValueError('A sound of the request is not a data URL.')
        return base64.b64decode(match[2]), AUDIO_EXTENSIONS.get(match[1], 'bin')
    match = re.match(r'^data:image/(png|jpeg|webp|gif);base64,(.*)$', url, re.S)
    if not match:
        raise ValueError('A picture of the request is not a data URL.')
    return base64.b64decode(match[2]), {'jpeg': 'jpg'}.get(match[1], match[1])


def canvas(width: float, height: float, megapixels: float) -> tuple[int, int]:
    """A width and height of this shape with about this many pixels, multiples of 32 (as ComfyUI's ResolutionSelector)."""
    scale = math.sqrt(megapixels * 1024 * 1024 / (width * height))
    return max(32, round(width * scale / 32) * 32), max(32, round(height * scale / 32) * 32)


class Engine:
    """A model. id matches the one in Atelier (Gpu::engines for videos, Gpu::imageEngines for pictures)."""
    id = ''
    label = ''
    kind = 'video'

    def files(self, job: dict) -> list[tuple[str, str, str, float]]:
        return []

    def preload(self) -> list[tuple[str, str, str, float]]:
        """The files to download when the worker starts, so the first request does not wait for them."""
        return []

    def make(self, worker: 'Worker', job: dict, report, cancelled) -> tuple[str, dict]:
        raise NotImplementedError


# Videos and sounds -----------------------------------------------------------------------------------------------------

FPS = 24
# The clip of a video's end that a continuation starts from: H3 anchors clips of 5, 22, 39... frames (17k + 5).
GUIDE_FRAMES = 22


def ffmpeg(args: list[str], timeout: float = 900) -> None:
    sh(['ffmpeg', '-y', '-v', 'error', *args], timeout=timeout)


def has_audio(path: str) -> bool:
    data = json.loads(sh(['ffprobe', '-v', 'error', '-print_format', 'json', '-show_streams', path], timeout=120))
    return any(s.get('codec_type') == 'audio' for s in data.get('streams', []))


def fill(width: int, height: int) -> str:
    """An ffmpeg filter that scales a video to cover width x height and crops the rest from the middle."""
    return f'scale={width}:{height}:force_original_aspect_ratio=increase,crop={width}:{height},setsar=1'


def clip(source: str, target: str, *, start: float = 0.0, duration: float | None = None, size: tuple[int, int] | None = None,
         sound: bool = True, silence: bool = False) -> bool:
    """A piece of a video at 24 fps (covering size, when given) as H.264 with AAC sound. silence: a silent track when the
    video has none, so every reference video has one. Returns whether the piece has sound."""
    audio = sound and has_audio(source)
    vf = f'fps={FPS}' + (',' + fill(*size) if size else '')
    args = ['-ss', f'{max(0.0, start):.3f}', '-i', source]
    if sound and not audio and silence:
        args += ['-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo']
    if duration is not None:
        args += ['-t', f'{duration:.3f}']
    args += ['-map', '0:v:0', '-vf', vf, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '14', '-pix_fmt', 'yuv420p']
    if audio:
        args += ['-map', '0:a:0', '-c:a', 'aac', '-b:a', '192k', '-ar', '48000', '-ac', '2']
    elif sound and silence:
        args += ['-map', '1:a:0', '-c:a', 'aac', '-b:a', '64k', '-shortest']
    ffmpeg(args + [target])
    return audio or (sound and silence)


def tail(source: str, target: str, size: tuple[int, int]) -> bool:
    """The last GUIDE_FRAMES frames of a video at 24 fps, with the sound under them: where a continuation starts."""
    piece = target + '.end.mp4'
    duration = probe(source)['duration']
    clip(source, piece, start=max(0.0, duration - GUIDE_FRAMES / FPS - 1.0), size=size)
    frames = probe(piece)['frames']
    first = max(0, frames - GUIDE_FRAMES)
    audio = has_audio(piece)
    graph = f'[0:v]trim=start_frame={first},setpts=PTS-STARTPTS[v]' + (f';[0:a]atrim=start={first / FPS:.4f},asetpts=PTS-STARTPTS[a]' if audio else '')
    ffmpeg(['-i', piece, '-filter_complex', graph, '-map', '[v]', *(['-map', '[a]'] if audio else []), '-c:v', 'libx264', '-preset', 'veryfast',
            '-crf', '12', '-pix_fmt', 'yuv420p', *(['-c:a', 'aac', '-b:a', '192k'] if audio else []), target])
    os.remove(piece)
    return audio


def audio_file(source: str, target: str, *, duration: float | None = None) -> None:
    """A sound as 48 kHz stereo WAV, cut to duration when given."""
    ffmpeg(['-i', source, *(['-t', f'{duration:.3f}'] if duration else []), '-vn', '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le', target])


def join(first: str, second: str, target: str, overlap: int = GUIDE_FRAMES) -> None:
    """The video that was continued and its continuation as one: the continuation begins on the last frames of the first
    one (the guide), so those frames are taken from the continuation only. Sizes follow the continuation's."""
    info = probe(second)
    keep = max(0.0, probe(first)['duration'] - overlap / FPS)
    inputs = ['-i', first, '-i', second]
    a0 = '0:a' if has_audio(first) else None
    a1 = '1:a' if has_audio(second) else None
    if a0 is None:
        inputs += ['-f', 'lavfi', '-t', f'{keep:.3f}', '-i', 'anullsrc=r=48000:cl=stereo']
        a0 = f'{inputs.count("-i") - 1}:a'
    if a1 is None:
        inputs += ['-f', 'lavfi', '-t', f'{info["duration"]:.3f}', '-i', 'anullsrc=r=48000:cl=stereo']
        a1 = f'{inputs.count("-i") - 1}:a'
    graph = (f'[0:v]fps={FPS},{fill(info["width"], info["height"])},trim=end={keep:.4f},setpts=PTS-STARTPTS[v0];'
             f'[{a0}]aresample=48000,aformat=channel_layouts=stereo,atrim=end={keep:.4f},asetpts=PTS-STARTPTS[a0];'
             f'[1:v]fps={FPS},setsar=1,setpts=PTS-STARTPTS[v1];[{a1}]aresample=48000,aformat=channel_layouts=stereo,asetpts=PTS-STARTPTS[a1];'
             '[v0][a0][v1][a1]concat=n=2:v=1:a=1[v][a]')
    ffmpeg([*inputs, '-filter_complex', graph, '-map', '[v]', '-map', '[a]', '-c:v', 'libx264', '-preset', 'medium', '-crf', '16',
            '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart', target])


def with_sound_of(video: str, source: str, target: str) -> bool:
    """The video with the sound of source (from its start, padded with silence, cut to the video's length). False when
    source has no sound: then the video keeps its own."""
    if not has_audio(source):
        return False
    duration = probe(video)['duration']
    ffmpeg(['-i', video, '-i', source, '-map', '0:v:0', '-map', '1:a:0', '-c:v', 'copy', '-af', 'apad', '-t', f'{duration + 0.5 / FPS:.3f}',
            '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart', target])
    return True


# What a request brings -------------------------------------------------------------------------------------------------

def moment(at) -> float:
    return math.inf if at == 'end' else float(at or 0)


class Inputs:
    """The pictures, videos and sounds of a request, saved as files. pictures: role, at, path, width, height; videos:
    role, path, info (probe); sounds: role, at, path. Labels follow Atelier's VideoPrompt::labels: reference pictures
    first, then the pinned ones by moment (<Picture n>); videos and sounds in order."""

    def __init__(self, job: dict):
        self.options = job.get('options') or {}
        self.pictures: list[dict] = []
        self.videos: list[dict] = []
        self.sounds: list[dict] = []
        self.files: list[str] = []

    @classmethod
    def describe(cls, job: dict) -> 'Inputs':
        """The inputs as listed in the request, without their files: enough to plan the workflow and its downloads."""
        inputs = cls(job)
        for entry in job.get('inputs') or []:
            item = {'role': entry.get('role'), 'at': entry.get('at'), 'path': '', 'width': 0, 'height': 0, 'info': {}}
            {'image': inputs.pictures, 'video': inputs.videos, 'audio': inputs.sounds}.get(entry.get('kind'), []).append(item)
        return inputs

    @classmethod
    def fetch(cls, worker: 'Worker', job: dict, folder: str, report) -> 'Inputs':
        """Saves the request's inputs in folder: pictures and sounds from the request, videos downloaded from Atelier."""
        inputs = cls(job)
        os.makedirs(folder, exist_ok=True)
        entries = job.get('inputs') or []
        total = sum(int(e.get('size') or 0) for e in entries if e.get('kind') == 'video')
        done = 0
        for n, entry in enumerate(entries):
            kind, base = entry.get('kind'), os.path.join(folder, f'atelier-{job["id"]}-{n}')
            if kind == 'image':
                data, extension = decode_data_url(entry.get('data', ''))
                path = f'{base}.{extension}'
                with open(path, 'wb') as out:
                    out.write(data)
                width, height = image_size(data)
                inputs.pictures.append({'role': entry.get('role'), 'at': entry.get('at'), 'path': path, 'width': width, 'height': height})
            elif kind == 'audio':
                data, extension = decode_data_url(entry.get('data', ''), 'audio')
                path = f'{base}.{extension}'
                with open(path, 'wb') as out:
                    out.write(data)
                inputs.sounds.append({'role': entry.get('role'), 'at': entry.get('at'), 'path': path})
            elif kind == 'video':
                path = f'{base}.{"webm" if entry.get("mime") == "video/webm" else "mp4"}'
                size = int(entry.get('size') or 0)
                with open(path, 'wb') as out:
                    offset = 0
                    while offset < size:
                        data = worker.api.fetch('media', {'job': job['id'], 'index': entry['index'], 'offset': offset})
                        if not data:
                            raise RuntimeError('Atelier sent an empty piece of a video.')
                        out.write(data)
                        offset += len(data)
                        done += len(data)
                        report('preparing', done, total)
                inputs.videos.append({'role': entry.get('role'), 'path': path, 'info': probe(path)})
            else:
                continue
            inputs.files.append(path)
        return inputs

    def clean(self) -> None:
        for path in self.files:
            try:
                os.remove(path)
            except OSError:
                pass

    @property
    def mode(self) -> str:
        return self.options.get('mode', 'new')

    @property
    def op(self) -> str:
        return self.options.get('op', '') if self.mode == 'video' else ''

    @property
    def source(self) -> dict | None:
        return next((v for v in self.videos if v['role'] == 'source'), None)

    def ordered_pictures(self) -> list[dict]:
        refs = [p for p in self.pictures if p['role'] == 'ref']
        keys = sorted((p for p in self.pictures if p['role'] == 'key'), key=lambda p: moment(p['at']))
        return refs + keys

    def base(self) -> bool:
        """Whether the request fits the first/last frame model (FL2VA): a new video with no inputs but pictures pinned at
        its start or its end."""
        return self.mode == 'new' and not self.videos and not self.sounds and all(
            p['role'] == 'key' and (p['at'] == 'end' or float(p['at'] or 0) == 0) for p in self.pictures)


class H3(Engine):
    """MiniMax H3 (33B, video with stereo sound) in ComfyUI, from the official templates (comfyui-workflow-templates):
    fast = Pika H3 Flex LoRA, 6 steps of lcm on its own sigmas; balanced = the Turbo LoRA (8 steps; 4 for references);
    max = the full model, 20 steps. Text and first/last frames use the FL2VA transformer; everything else (references,
    pictures pinned anywhere, videos and sounds) the REF2VA one, with MiniMaxH3AddGuide for what is pinned at a moment.
    From a video: continue it (its last frames and sound anchored at the start), follow its movement with the Fun
    ControlNet (pose, depth or edges), or replace a part of it (SAM 3 finds it, the ControlNet repaints only there)."""
    id = 'h3'
    label = 'MiniMax H3'
    HF = 'https://huggingface.co/Comfy-Org/MiniMax-H3/resolve/main/'
    FILES = {
        'fl2va': ('diffusion_models', 'minimax_h3_fl2va_pruned_int8_convrot.safetensors', HF + 'diffusion_models/', 21.0),
        'ref2va': ('diffusion_models', 'minimax_h3_ref2va_pruned_int8_convrot.safetensors', HF + 'diffusion_models/', 21.0),
        'clip': ('text_encoders', 'qwen3vl_32b_minimax_h3_nvfp4_awq.safetensors', HF + 'text_encoders/', 15.7),
        'vae': ('vae', 'minimax_h3_video_vae_int8_convrot.safetensors', HF + 'vae/', 2.8),
        'audio_vae': ('vae', 'minimax_h3_audio_vae_fp32.safetensors', HF + 'vae/', 0.6),
        'flex_fl2va': ('loras', 'pika_h3_flex_fl2va_v1_bf16.safetensors', HF + 'loras/', 2.0),
        'flex_ref2va': ('loras', 'pika_h3_flex_ref2va_v1_bf16.safetensors', HF + 'loras/', 2.0),
        'turbo_fl2va': ('loras', 'minimax_h3_fl2v_turbo_8step_v1.0_comfyui_bf16.safetensors', 'https://huggingface.co/lightx2v/Minimax-h3-Turbo/resolve/main/', 2.0),
        'turbo_ref2va': ('loras', 'minimax_h3_ref2v_turbo_4step_v0.1_comfyui_bf16.safetensors', HF + 'loras/', 2.0),
        'control': ('model_patches', 'minimax_h3_fun_controlnet_union_pruned_int8_convrot.safetensors', HF + 'model_patches/', 2.3),
        'sdpose': ('checkpoints', 'sdpose_wholebody_fp16.safetensors', 'https://huggingface.co/Comfy-Org/SDPose/resolve/main/checkpoints/', 1.9),
        'detector': ('diffusion_models', 'rt_detr_v4-x-hgnet_fp16.safetensors', 'https://huggingface.co/Comfy-Org/SDPose/resolve/main/diffusion_models/', 0.13),
        'depth': ('geometry_estimation', 'depth_anything_3_mono_large.safetensors', 'https://huggingface.co/Comfy-Org/Depth-Anything-3/resolve/main/geometry_estimation/', 1.35),
        'sam': ('checkpoints', 'sam3.1_multiplex_fp16.safetensors', 'https://huggingface.co/Comfy-Org/sam3.1/resolve/main/checkpoints/', 1.75),
    }
    OP_FILES = {'pose': ['control', 'sdpose', 'detector'], 'depth': ['control', 'depth'], 'edges': ['control'], 'replace': ['control', 'sam']}
    MEGAPIXELS = {'480p': 0.4, '768p': 0.98}
    ASPECTS = {'16:9': (16, 9), '9:16': (9, 16), '1:1': (1, 1), '4:3': (4, 3), '3:4': (3, 4), '3:2': (3, 2), '2:3': (2, 3), '21:9': (21, 9)}
    FPS = FPS
    FLEX_SIGMAS = '1.0, 0.972973, 0.923077, 0.8, 0.571429, 0.333333, 0.0'
    # The reference videos together, and the reference sounds together, last at most this long (MiniMax's limits).
    REFERENCE_SECONDS = 15.0

    def _file(self, key: str) -> tuple[str, str, str, float]:
        folder, name, base, size = self.FILES[key]
        return folder, name, base + name, size

    def _keys(self, base: bool, quality: str, op: str = '') -> list[str]:
        kind = 'fl2va' if base else 'ref2va'
        keys = [kind, 'clip', 'vae', 'audio_vae']
        if quality in ('fast', 'balanced'):
            keys.append(('flex_' if quality == 'fast' else 'turbo_') + kind)
        return keys + self.OP_FILES.get(op, [])

    def files(self, job: dict) -> list[tuple[str, str, str, float]]:
        inputs = Inputs.describe(job)
        return [self._file(k) for k in self._keys(inputs.base(), inputs.options.get('quality', 'fast'), inputs.op)]

    def preload(self) -> list[tuple[str, str, str, float]]:
        return [self._file(k) for k in self._keys(True, 'fast')]

    @classmethod
    def length(cls, seconds: float) -> int:
        """Frames at 24 fps on the model's 17k+5 grid (124 = about 5 s), as the templates compute it."""
        frames = max(5, round(seconds * cls.FPS))
        return frames + (5 - frames % 17) % 17

    @staticmethod
    def mentions(text: str) -> str:
        """@1, @v1, @s1 as Atelier writes them, as H3 reads them: <Picture 1>, <Video 1>, <Audio 1>."""
        text = re.sub(r'(?<![\w<])@v([1-9])\b', r'<Video \1>', text.strip())
        text = re.sub(r'(?<![\w<])@s([1-9])\b', r'<Audio \1>', text)
        return re.sub(r'(?<![\w<])@([1-9])\b', r'<Picture \1>', text)

    @classmethod
    def prompt(cls, text: str, inputs: Inputs, duration: float) -> str:
        """The person's prompt as H3 expects it. A prompt already in one of the official formats (the prompt helper
        writes them) goes as it is; otherwise the alignment line (first and last frames) or the reference fields are
        added around it, so every input is named."""
        text = cls.mentions(text)
        mark = f'{duration:.2f}'
        if inputs.base():
            if not inputs.pictures or re.search(r'aligns with the|is fully referenced|integrated_multimodal_description', text):
                return text
            ats = sorted(moment(p['at']) for p in inputs.pictures)
            if ats == [0.0]:
                line = 'For the target video, at 0.00 seconds into the target video, <Picture 1> (from [Shot 1]) is fully referenced.'
            elif ats == [math.inf]:
                line = f'How the reference pictures align with the target video — <Picture 1> (from [Shot 1]) aligns with the {mark}-second mark of the target video.'
            else:
                line = ('How the reference pictures align with the target video — Picture 1 (from Shot 1) aligns with the 0.00-second mark of the '
                        f'target video; Picture 2 (from Shot 1) aligns with the {mark}-second mark of the target video.')
            return f'{line}\n\n{text}'
        if 'subject_definitions' in text:
            return text
        lines, kinds = [], []
        for n, p in enumerate(inputs.ordered_pictures(), 1):
            if p['role'] == 'ref':
                lines.append(f'<Picture {n}> is a reference picture: its people, places and things appear in the target video as they are.')
                kinds.append('reference generation')
            elif p['at'] == 'end':
                lines.append(f'<Picture {n}> is the last frame of the target video, at the {mark}-second mark.')
            elif float(p['at'] or 0) == 0:
                lines.append(f'<Picture {n}> is the first frame of [Shot 1].')
            else:
                lines.append(f'<Picture {n}> is the keyframe at the {float(p["at"]):.2f}-second mark of the target video.')
            if p['role'] == 'key':
                kinds.append('keyframe completion')
        number = 0
        for v in inputs.videos:
            if v['role'] == 'source' and inputs.op not in ('continue', 'replace'):
                continue
            number += 1
            if v['role'] == 'ref':
                lines.append(f'<Video {number}> is a reference video: its movement, camera and style guide the target video.')
                kinds.append('reference generation')
            elif inputs.op == 'continue':
                lines.append(f'<Video {number}> is the video that the target video continues from its last frame.')
                kinds.append('video continuation')
            else:
                lines.append(f'<Video {number}> is the video being edited: {inputs.options.get("target", "")} is replaced, the rest stays as it is.')
                kinds.append('video editing')
        for n, a in enumerate(inputs.sounds, 1):
            if a['role'] == 'ref':
                lines.append(f'<Audio {n}> is a reference sound: the voices and sounds of the target video follow its timbre and style.')
                kinds.append('audio reference')
            else:
                lines.append(f'<Audio {n}> is the soundtrack of the target video from the {moment(a["at"]):.2f}-second mark, reused as it is.')
                kinds.append('audio reuse')
        if inputs.op in ('pose', 'depth', 'edges'):
            kinds.append('reference generation')
        if inputs.options.get('keep_audio'):
            kinds.append('audio reuse')
        order = ['video editing', 'video continuation', 'keyframe completion', 'reference generation', 'audio reuse', 'audio reference']
        kinds = sorted(set(kinds), key=order.index) or ['reference generation']
        first = re.split(r'(?<=[.!?])\s', text, maxsplit=1)[0]
        return (f'subject_definitions:\n' + '\n'.join(lines) + f'\n\nsummary:\n[{" + ".join(kinds)}] {first}\n\n'
                f'detailed_description:\n{text}')

    def stage(self, job: dict, inputs: Inputs, folder: str) -> dict:
        """The files the workflow reads, made in ComfyUI's input folder: the reference videos (24 fps, together up to
        15 s, with a sound track each), the sounds as WAV, the end of a video to continue, and the video to follow or edit
        at the request's size. Returns what graph() needs."""
        o = inputs.options
        megapixels = self.MEGAPIXELS.get(o.get('resolution', '768p'), 0.98)
        pictures = inputs.ordered_pictures()
        source = inputs.source
        keys = [p for p in pictures if p['role'] == 'key']
        if source:
            width, height = canvas(source['info']['width'], source['info']['height'], megapixels)
        elif keys:
            # The pinned pictures fill the whole frame, so the video takes the shape of the first of them.
            width, height = canvas(keys[0]['width'], keys[0]['height'], megapixels)
        else:
            width, height = canvas(*self.ASPECTS.get(o.get('aspect', '16:9'), (16, 9)), megapixels)
        length = self.length(float(o.get('seconds', 5)))
        if source and inputs.op != 'continue':
            # A video that follows or edits another is no longer than it: the frames it has, down to the model's grid.
            frames = int(source['info']['duration'] * self.FPS + 0.01)
            if frames < length:
                length = max(22, (frames - 5) // 17 * 17 + 5)
        duration = length / self.FPS
        prefix = f'atelier-{job["id"]}'
        staged: dict = {'width': width, 'height': height, 'length': length, 'base': inputs.base(), 'op': inputs.op,
                        'pictures': [(os.path.basename(p['path']), p['role'], p['at']) for p in pictures],
                        'videos': [], 'sounds': [], 'guide': None, 'control': None, 'target': o.get('target', '')}
        made: list[str] = []
        refs = [v for v in inputs.videos if v['role'] == 'ref']
        if source and inputs.op in ('continue', 'replace'):
            refs = [source] + refs
        for n, v in enumerate(refs):
            share = self.REFERENCE_SECONDS / len(refs)
            name = f'{prefix}-video{n}.mp4'
            start = max(0.0, v['info']['duration'] - share) if v is source and inputs.op == 'continue' else 0.0
            clip(v['path'], os.path.join(folder, name), start=start, duration=share, size=canvas(v['info']['width'], v['info']['height'], 0.4), silence=True)
            staged['videos'].append(name)
            made.append(name)
        share = self.REFERENCE_SECONDS / max(1, sum(1 for a in inputs.sounds if a['role'] == 'ref'))
        for n, a in enumerate(inputs.sounds):
            name = f'{prefix}-sound{n}.wav'
            audio_file(a['path'], os.path.join(folder, name), duration=share if a['role'] == 'ref' else max(0.5, duration - min(moment(a['at']), duration)))
            staged['sounds'].append((name, a['role'], a['at']))
            made.append(name)
        if source and inputs.op == 'continue':
            name = f'{prefix}-end.mp4'
            staged['guide'] = (name, tail(source['path'], os.path.join(folder, name), (width, height)))
            made.append(name)
        elif source:
            name = f'{prefix}-source.mp4'
            clip(source['path'], os.path.join(folder, name), duration=min(source['info']['duration'], duration), size=(width, height), sound=False)
            staged['control'] = name
            made.append(name)
        staged['made'] = made
        return staged

    def graph(self, job: dict, staged: dict, seed: int, prefix: str, inputs: Inputs) -> tuple[dict, str, dict]:
        """The ComfyUI workflow (API format) for a request whose files stage() made."""
        o = job['options']
        quality, op = o.get('quality', 'fast'), staged['op']
        base = staged['base']
        keys = self._keys(base, quality)
        width, height, length = staged['width'], staged['height'], staged['length']
        g: dict[str, dict] = {
            'unet': {'class_type': 'UNETLoader', 'inputs': {'unet_name': self.FILES[keys[0]][1], 'weight_dtype': 'default'}},
            'clip': {'class_type': 'CLIPLoader', 'inputs': {'clip_name': self.FILES['clip'][1], 'type': 'minimax', 'device': 'default'}},
            'vae': {'class_type': 'VAELoader', 'inputs': {'vae_name': self.FILES['vae'][1]}},
            'audio_vae': {'class_type': 'VAELoader', 'inputs': {'vae_name': self.FILES['audio_vae'][1]}},
        }
        model = ['unet', 0]
        if len(keys) > 4:
            g['lora'] = {'class_type': 'LoraLoaderModelOnly', 'inputs': {'model': model, 'lora_name': self.FILES[keys[4]][1], 'strength_model': 1.0}}
            model = ['lora', 0]
        for i, (name, _, _) in enumerate(staged['pictures']):
            g[f'picture{i}'] = {'class_type': 'LoadImage', 'inputs': {'image': name}}
        text = self.prompt(job['prompt'], inputs, length / self.FPS)

        if staged['control']:
            # The video to follow or edit, and the Fun ControlNet on the model: the movement as pose skeletons, depth or
            # edges, or the part to replace as a mask (SAM 3 finds it from its name, in every frame).
            g['source'] = {'class_type': 'LoadVideo', 'inputs': {'file': staged['control']}}
            g['source_parts'] = {'class_type': 'GetVideoComponents', 'inputs': {'video': ['source', 0]}}
            frames = ['source_parts', 0]
            control: dict = {'model': model, 'model_patch': ['patch', 0], 'vae': ['vae', 0], 'strength': 1.0, 'start_percent': 0.0, 'end_percent': 1.0}
            if op == 'edges':
                g['edges'] = {'class_type': 'Canny', 'inputs': {'image': frames, 'low_threshold': 0.4, 'high_threshold': 0.8}}
                control['control_video'] = ['edges', 0]
            elif op == 'depth':
                g['depth_model'] = {'class_type': 'LoadDA3Model', 'inputs': {'model_name': self.FILES['depth'][1], 'weight_dtype': 'default'}}
                g['depth_geometry'] = {'class_type': 'DA3Inference', 'inputs': {'da3_model': ['depth_model', 0], 'image': frames, 'resolution': 504,
                                                                                 'resize_method': 'lower_bound_resize', 'mode': 'mono'}}
                g['depth'] = {'class_type': 'DA3Render', 'inputs': {'da3_geometry': ['depth_geometry', 0], 'output': 'depth',
                                                                     'output.normalization': 'v2_style', 'output.apply_sky_clip': False}}
                control['control_video'] = ['depth', 0]
            elif op == 'pose':
                g['pose_model'] = {'class_type': 'CheckpointLoaderSimple', 'inputs': {'ckpt_name': self.FILES['sdpose'][1]}}
                g['detector'] = {'class_type': 'UNETLoader', 'inputs': {'unet_name': self.FILES['detector'][1], 'weight_dtype': 'default'}}
                g['pose_frames'] = {'class_type': 'ResizeImageMaskNode', 'inputs': {'input': frames, 'resize_type': 'scale longer dimension',
                                                                                     'resize_type.longer_size': 1024, 'scale_method': 'lanczos'}}
                g['people'] = {'class_type': 'RTDETR_detect', 'inputs': {'model': ['detector', 0], 'image': ['pose_frames', 0], 'threshold': 0.5,
                                                                         'class_name': 'person', 'max_detections': 4}}
                g['keypoints'] = {'class_type': 'SDPoseKeypointExtractor', 'inputs': {'model': ['pose_model', 0], 'vae': ['pose_model', 2],
                                                                                      'image': ['pose_frames', 0], 'batch_size': 16, 'bboxes': ['people', 0]}}
                g['pose'] = {'class_type': 'SDPoseDrawKeypoints', 'inputs': {'keypoints': ['keypoints', 0], 'draw_body': True, 'draw_hands': True,
                                                                             'draw_face': True, 'draw_feet': True, 'stick_width': 4, 'face_point_size': 2,
                                                                             'score_threshold': 0.5, 'draw_head': True}}
                control['control_video'] = ['pose', 0]
            else:
                g['sam'] = {'class_type': 'CheckpointLoaderSimple', 'inputs': {'ckpt_name': self.FILES['sam'][1]}}
                g['sam_text'] = {'class_type': 'CLIPTextEncode', 'inputs': {'text': staged['target'], 'clip': ['sam', 1]}}
                g['found'] = {'class_type': 'SAM3_Detect', 'inputs': {'model': ['sam', 0], 'image': frames, 'conditioning': ['sam_text', 0],
                                                                       'threshold': 0.5, 'refine_iterations': 2, 'individual_masks': False}}
                g['mask'] = {'class_type': 'GrowMask', 'inputs': {'mask': ['found', 0], 'expand': 12, 'tapered_corners': True}}
                control.update(mask=['mask', 0], source_video=frames)
            g['patch'] = {'class_type': 'ModelPatchLoader', 'inputs': {'name': self.FILES['control'][1]}}
            g['control'] = {'class_type': 'MiniMaxH3FunControlNetApply', 'inputs': control}
            model = ['control', 0]

        if base:
            inputs_ = {'clip': ['clip', 0], 'vae': ['vae', 0], 'prompt': text, 'width': width, 'height': height, 'length': length}
            for i, (_, _, at) in enumerate(staged['pictures']):
                inputs_['last_frame' if at == 'end' else 'first_frame'] = [f'picture{i}', 0]
            g['condition'] = {'class_type': 'MiniMaxH3ImageToVideo', 'inputs': inputs_}
        else:
            inputs_ = {'clip': ['clip', 0], 'vae': ['vae', 0], 'audio_vae': ['audio_vae', 0], 'prompt': text, 'width': width,
                       'height': height, 'length': length, 'ref_image_size': 'match'}
            for i in range(len(staged['pictures'])):
                inputs_[f'ref_images.ref_image_{i}'] = [f'picture{i}', 0]
            for i, name in enumerate(staged['videos']):
                g[f'video{i}'] = {'class_type': 'LoadVideo', 'inputs': {'file': name}}
                g[f'video{i}_parts'] = {'class_type': 'GetVideoComponents', 'inputs': {'video': [f'video{i}', 0]}}
                inputs_[f'ref_videos.ref_video_{i}'] = [f'video{i}_parts', 0]
                inputs_[f'ref_video_audios.ref_video_audio_{i}'] = [f'video{i}_parts', 1]
            for i, (name, _, _) in enumerate(staged['sounds']):
                g[f'sound{i}'] = {'class_type': 'LoadAudio', 'inputs': {'audio': name}}
                inputs_[f'ref_audios.ref_audio_{i}'] = [f'sound{i}', 0]
            g['condition'] = {'class_type': 'MiniMaxH3ReferenceToVideo', 'inputs': inputs_}

        # What is pinned at a moment: the end of the video to continue (with its sound), pictures, soundtracks.
        positive = ['condition', 0]
        guides: list[tuple[str, dict]] = []
        if staged['guide']:
            name, audio = staged['guide']
            g['guide_clip'] = {'class_type': 'LoadVideo', 'inputs': {'file': name}}
            g['guide_parts'] = {'class_type': 'GetVideoComponents', 'inputs': {'video': ['guide_clip', 0]}}
            guides.append(('guide', {'image': ['guide_parts', 0], **({'audio': ['guide_parts', 1]} if audio else {}), 'frame_idx': 0}))
        if not base:
            for i, (_, role, at) in enumerate(staged['pictures']):
                if role == 'key':
                    guides.append((f'pin{i}', {'image': [f'picture{i}', 0], 'frame_idx': self.frame(at, length)}))
            for i, (_, role, at) in enumerate(staged['sounds']):
                if role == 'key':
                    guides.append((f'pin_sound{i}', {'audio': [f'sound{i}', 0], 'frame_idx': self.frame(at, length)}))
        for name, guide in guides:
            g[name] = {'class_type': 'MiniMaxH3AddGuide', 'inputs': {'positive': positive, 'latent': ['condition', 1], 'vae': ['vae', 0],
                                                                    'audio_vae': ['audio_vae', 0], **guide}}
            positive = [name, 0]

        g['guider'] = {'class_type': 'BasicGuider', 'inputs': {'model': model, 'conditioning': positive}}
        g['noise'] = {'class_type': 'RandomNoise', 'inputs': {'noise_seed': seed}}
        if quality == 'fast':
            g['sampler'] = {'class_type': 'KSamplerSelect', 'inputs': {'sampler_name': 'lcm'}}
            g['sigmas'] = {'class_type': 'ManualSigmas', 'inputs': {'sigmas': self.FLEX_SIGMAS}}
        else:
            steps = 20 if quality == 'max' else (8 if base else 4)
            g['sampler'] = {'class_type': 'KSamplerSelect', 'inputs': {'sampler_name': 'res_multistep'}}
            g['sigmas'] = {'class_type': 'BasicScheduler', 'inputs': {'model': model, 'scheduler': 'simple', 'steps': steps, 'denoise': 1.0}}
        g['sample'] = {'class_type': 'SamplerCustomAdvanced', 'inputs': {'noise': ['noise', 0], 'guider': ['guider', 0], 'sampler': ['sampler', 0],
                                                                       'sigmas': ['sigmas', 0], 'latent_image': ['condition', 1]}}
        g['decode'] = {'class_type': 'VAEDecode', 'inputs': {'samples': ['sample', 0], 'vae': ['vae', 0]}}
        g['decode_audio'] = {'class_type': 'VAEDecodeAudio', 'inputs': {'samples': ['sample', 0], 'vae': ['audio_vae', 0]}}
        g['video'] = {'class_type': 'CreateVideo', 'inputs': {'images': ['decode', 0], 'audio': ['decode_audio', 0], 'fps': float(self.FPS)}}
        g['save'] = {'class_type': 'SaveVideo', 'inputs': {'video': ['video', 0], 'filename_prefix': prefix, 'format': 'auto', 'format.codec': 'auto'}}
        return g, 'save', {'width': width, 'height': height, 'length': length}

    @classmethod
    def frame(cls, at, length: int) -> int:
        """The frame of a moment (seconds, or 'end': the last frame)."""
        return -1 if at == 'end' else min(length - 1, max(0, round(float(at or 0) * cls.FPS)))

    def make(self, worker: 'Worker', job: dict, report, cancelled) -> tuple[str, dict]:
        comfy = worker.comfy
        folder = comfy.input_dir()
        inputs = Inputs.fetch(worker, job, folder, report)
        staged = None
        try:
            if inputs.videos:
                report('preparing', message='Preparing the videos')
            staged = self.stage(job, inputs, folder)
            seed = job['options'].get('seed')
            seed = seed if isinstance(seed, int) else random.randint(0, 2 ** 53)
            graph, output, _ = self.graph(job, staged, seed, f'atelier/{job["id"]}', inputs)
            path = comfy.run(graph, output, report, cancelled)
            return finish(worker, inputs, path, job), {'seed': seed}
        finally:
            inputs.clean()
            for name in (staged or {}).get('made', []):
                try:
                    os.remove(os.path.join(folder, name))
                except OSError:
                    pass


def finish(worker: 'Worker', inputs: Inputs, path: str, job: dict) -> str:
    """What comes after the model: a continuation joined to the video it continues, or the sound of the source kept."""
    source = inputs.source
    o = inputs.options
    if source is None:
        return path
    target = os.path.join(worker.workdir, f'final-{job["id"]}.mp4')
    if inputs.op == 'continue' and o.get('join', True):
        join(source['path'], path, target)
    elif inputs.op != 'continue' and o.get('keep_audio') and with_sound_of(path, source['path'], target):
        pass
    else:
        return path
    os.remove(path)
    return target


class Fake(Engine):
    """A stand-in that makes a test pattern with ffmpeg, for checking the worker and Atelier without a GPU. It fetches
    the request's inputs and joins or keeps sound as H3 would, so those paths are checked too."""
    id = 'h3'
    label = 'Test pattern'

    def __init__(self, steps: int = 4, pause: float = 0.4):
        self.steps = steps
        self.pause = pause

    def make(self, worker: 'Worker', job: dict, report, cancelled) -> tuple[str, dict]:
        o = job['options']
        if 'fail' in job['prompt']:
            raise RuntimeError('The test asked this request to fail.')
        inputs = Inputs.fetch(worker, job, os.path.join(worker.workdir, 'inputs'), report)
        try:
            report('loading')
            for step in range(1, self.steps + 1):
                time.sleep(self.pause)
                if cancelled():
                    raise Cancelled()
                report('generating', step, self.steps)
            keys = [p for p in inputs.ordered_pictures() if p['role'] == 'key']
            shape = ((inputs.source['info']['width'], inputs.source['info']['height']) if inputs.source
                     else (keys[0]['width'], keys[0]['height']) if keys else H3.ASPECTS.get(o.get('aspect', '16:9'), (16, 9)))
            width, height = canvas(*shape, 0.05)
            seconds = H3.length(float(o.get('seconds', 5))) / H3.FPS
            path = os.path.join(worker.workdir, f'fake-{job["id"]}.mp4')
            sh(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', f'testsrc2=size={width}x{height}:rate=24', '-f', 'lavfi', '-i',
                'sine=frequency=440:sample_rate=48000', '-t', f'{seconds:.3f}', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac',
                '-shortest', path], timeout=120)
            path = finish(worker, inputs, path, job)
        finally:
            inputs.clean()
        seed = o.get('seed') if isinstance(o.get('seed'), int) else 1234
        return path, {'seed': seed}


# Pictures --------------------------------------------------------------------------------------------------------------

def pillow():
    """Pillow, which comes with Colab and with ComfyUI's packages."""
    from PIL import Image, ImageFilter, ImageOps
    Image.MAX_IMAGE_PIXELS = 400_000_000
    return Image, ImageFilter, ImageOps


def ratio(width: int, height: int) -> str:
    """A width and height as their lowest whole ratio, W:H."""
    g = math.gcd(int(width), int(height)) or 1
    return f'{int(width) // g}:{int(height) // g}'


def parse_ratio(value) -> tuple[int, int] | None:
    """W:H (or WxH) as two numbers, when it is a sensible shape for a picture."""
    match = re.match(r'^\s*(\d{1,5})\s*[:x×]\s*(\d{1,5})\s*$', str(value or ''))
    if not match or not int(match[1]) or not int(match[2]) or not 0.2 <= int(match[1]) / int(match[2]) <= 5:
        return None
    return int(match[1]), int(match[2])


def expanded(text: str) -> tuple[str, tuple[int, int] | None]:
    """The caption and the shape in the prompt expander's answer: one JSON object, after its thinking."""
    text = re.sub(r'(?s)<think>.*?</think>', '', text or '').strip()
    start, end = text.find('{'), text.rfind('}')
    data = None
    if 0 <= start < end:
        try:
            data = json.loads(text[start:end + 1])
        except ValueError:
            data = None
    if isinstance(data, dict):
        caption = data.get('expanded_caption')
        shape = data.get('target_aspect_ratio') or data.get('aspect_ratio')
    else:
        # A caption with a quotation mark left unescaped: read it up to the key that follows it.
        match = re.search(r'"expanded_caption"\s*:\s*"(.*)"\s*,\s*"(?:target_)?aspect_ratio"\s*:\s*"([^"]*)"', text, re.S)
        if not match:
            match = re.search(r'"(?:target_)?aspect_ratio"\s*:\s*"([^"]*)"\s*,\s*"expanded_caption"\s*:\s*"(.*)"\s*}', text, re.S)
            caption, shape = (match[2], match[1]) if match else (None, None)
        else:
            caption, shape = match[1], match[2]
        if isinstance(caption, str):
            caption = caption.replace('\\"', '"').replace('\\n', ' ').replace('\\\\', '\\')
    if not isinstance(caption, str) or not caption.strip():
        raise ValueError('the prompt expander did not answer with a caption')
    return re.sub(r'\s+', ' ', caption).strip(), parse_ratio(shape)


def safetensors_ok(path: str) -> bool:
    """Whether a file is a .safetensors one (and not, say, a sign-in page saved in its place)."""
    try:
        with open(path, 'rb') as f:
            length = int.from_bytes(f.read(8), 'little')
            if not 2 <= length <= 100_000_000 or length + 8 > os.path.getsize(path):
                return False
            return isinstance(json.loads(f.read(length)), dict)
    except (OSError, ValueError):
        return False


class Pictures:
    """The pictures of a picture request, as ComfyUI gets them: the ones it sends, in order (<Picture 1>… ; the first is the
    one being changed), and for a marked area the copy that marks it, last. original and change keep the first picture and
    the area (255 where it changes) at its own size, to paste the result back only there."""

    MAGENTA = (255, 0, 255)

    def __init__(self, folder: str, job: str):
        self.folder = folder
        self.job = job
        self.names: list[str] = []
        self.sizes: list[tuple[int, int]] = []
        self.planner: list[str] = []
        self.original = None
        self.change = None
        self.files: list[str] = []

    def _save(self, image, name: str) -> str:
        path = os.path.join(self.folder, name)
        image.save(path, 'PNG', compress_level=1)
        self.files.append(path)
        return name

    @staticmethod
    def flat(image, color: tuple[int, int, int]):
        """The picture without transparency: what was transparent becomes color."""
        Image, _, _ = pillow()
        if image.mode in ('RGBA', 'LA', 'PA') or (image.mode == 'P' and 'transparency' in image.info):
            rgba = image.convert('RGBA')
            out = Image.new('RGB', rgba.size, color)
            out.paste(rgba, mask=rgba.getchannel('A'))
            return out
        return image.convert('RGB')

    @staticmethod
    def fit(image, megapixels: float):
        """The picture at most this many megapixels (the model reads its pictures at 1 to 2K anyway)."""
        Image, _, _ = pillow()
        area = image.width * image.height
        if area <= megapixels * 1048576:
            return image
        scale = math.sqrt(megapixels * 1048576 / area)
        return image.resize((max(1, round(image.width * scale)), max(1, round(image.height * scale))), Image.LANCZOS)

    @classmethod
    def fetch(cls, worker: 'Worker', job: dict, folder: str, report, planner: bool) -> 'Pictures':
        Image, _, ImageOps = pillow()
        pictures = cls(folder, job['id'])
        os.makedirs(folder, exist_ok=True)
        count = int(job.get('images') or 0)
        marked = bool(job.get('mask')) and count > 0
        total = count + (1 if marked else 0)
        for n in range(count):
            report('preparing', n, total)
            data = worker.api.fetch('input', {'job': job['id'], 'index': n})
            image = Image.open(io.BytesIO(data))
            image.load()
            image = ImageOps.exif_transpose(image)
            # The picture being changed, where an area is marked: what is transparent (the empty canvas of Expandir) is gray.
            image = cls.flat(image, (128, 128, 128) if marked and n == 0 else (255, 255, 255))
            if n == 0 and marked:
                pictures.original = image
            sent = cls.fit(image, H3Image.MAX_INPUT_MEGAPIXELS)
            pictures.names.append(pictures._save(sent, f'atelier-{job["id"]}-{n}.png'))
            pictures.sizes.append(image.size)
        if marked:
            report('preparing', count, total)
            mask = Image.open(io.BytesIO(worker.api.fetch('input', {'job': job['id'], 'index': 'mask'})))
            mask.load()
            alpha = mask.convert('RGBA').getchannel('A').resize(pictures.original.size, Image.BILINEAR)
            # The mask is transparent where the picture changes (as for the image APIs).
            pictures.change = alpha.point(lambda a: 255 if a < 128 else 0)
            if pictures.change.getbbox() is None:
                raise ValueError('The marked area is empty: paint the area to change.')
            original = cls.fit(pictures.original, 4)
            change = pictures.change.resize(original.size, Image.NEAREST)
            painted = Image.blend(original, Image.new('RGB', original.size, cls.MAGENTA), 0.8)
            pictures.names.append(pictures._save(Image.composite(painted, original, change), f'atelier-{job["id"]}-mark.png'))
            pictures.sizes.append(pictures.original.size)
        if planner:
            # The prompt expander sees the same pictures, smaller.
            for n, name in enumerate(pictures.names):
                with Image.open(os.path.join(folder, name)) as image:
                    pictures.planner.append(pictures._save(cls.fit(image.convert('RGB'), 1), f'atelier-{job["id"]}-plan-{n}.png'))
        return pictures

    def clean(self) -> None:
        for path in self.files:
            try:
                os.remove(path)
            except OSError:
                pass


class H3Image(Engine):
    """MiniMax H3-Image in ComfyUI, from the official templates (image_minimax_h3_image_t2i and _edit): the int8 model, 6
    steps of euler at CFG 1 with the negative zeroed out, the pictures as <Picture 1>, <Picture 2>… (the first is the one
    being changed). Planejar is the templates' prompt expander (Qwen3.5 9B), run as a step of its own before the picture.
    An area (the brush, Expandir) goes as a copy with the area painted magenta, and the result is pasted into the original
    only there. The account's LoRAs load on the model at their strengths, their trigger words added to the prompt."""
    id = 'h3-image'
    label = 'MiniMax H3-Image'
    kind = 'image'
    HF = 'https://huggingface.co/Comfy-Org/'
    FILES = {
        'model': ('diffusion_models', 'minimax_h3_image_pruned_int8_convrot.safetensors', HF + 'MiniMax-H3-Image/resolve/main/diffusion_models/', 21.0),
        'clip': ('text_encoders', 'qwen3vl_32b_minimax_h3_int8_convrot.safetensors', HF + 'MiniMax-H3/resolve/main/text_encoders/', 25.3),
        'vae': ('vae', 'minimax_h3_image_vae_fp16.safetensors', HF + 'MiniMax-H3-Image/resolve/main/vae/', 1.2),
        'planner': ('text_encoders', 'qwen3.5_9b_w4a8_gptq.safetensors', HF + 'Qwen3.5/resolve/main/text_encoders/', 6.9),
    }
    STEPS = 6
    # Pictures above this are made smaller before they go to the model, which reads them at 1 to 2K anyway.
    MAX_INPUT_MEGAPIXELS = 16
    MAX_MEGAPIXELS = 20
    # The prompt expander's instructions, as ComfyUI's official templates have them (comfyui-workflow-templates-json).
    TEMPLATES = {'t2i': 'image_minimax_h3_image_t2i.json', 'edit': 'image_minimax_h3_image_edit.json'}
    _instructions: dict[str, tuple[str, str]] = {}

    def _file(self, key: str) -> tuple[str, str, str, float]:
        folder, name, base, size = self.FILES[key]
        return folder, name, base + name, size

    @staticmethod
    def lora_file(lora: dict) -> tuple[str, str, str, float]:
        """Where a LoRA of the account's list goes: named by its id and link, so a link changed in Atelier downloads again."""
        url = str(lora.get('url') or '')
        tag = hashlib.sha1(url.encode()).hexdigest()[:8]
        return 'loras', f'atelier_{re.sub(r"[^a-f0-9]", "", str(lora.get("id") or ""))[:16]}_{tag}.safetensors', url, 0.3

    @staticmethod
    def plans(job: dict) -> bool:
        return (job.get('options') or {}).get('thinking', 'off') != 'off'

    def files(self, job: dict) -> list[tuple[str, str, str, float]]:
        keys = ['model', 'clip', 'vae'] + (['planner'] if self.plans(job) else [])
        return [self._file(k) for k in keys] + [self.lora_file(l) for l in (job.get('options') or {}).get('loras') or []]

    def preload(self) -> list[tuple[str, str, str, float]]:
        return [self._file(k) for k in ('model', 'clip', 'vae')]

    # The prompt ---------------------------------------------------------------------------------------------------------

    @classmethod
    def instructions(cls, kind: str) -> tuple[str, str]:
        """The expander's system prompt and task (with {input text} where the request goes), read from the templates."""
        if kind not in cls._instructions:
            import importlib.resources
            import comfyui_workflow_templates_json
            path = importlib.resources.files(comfyui_workflow_templates_json) / 'templates' / cls.TEMPLATES[kind]
            data = json.loads(path.read_text(encoding='utf-8'))
            nodes = list(data.get('nodes') or [])
            for graph in (data.get('definitions') or {}).get('subgraphs') or []:
                nodes += graph.get('nodes') or []
            texts = {n.get('title'): (n.get('widgets_values') or [''])[0] for n in nodes if n.get('type') == 'PrimitiveStringMultiline'}
            system, task = texts.get('Text (system_prompt)'), texts.get('Text (task_prompt)')
            if not isinstance(system, str) or not isinstance(task, str) or '{input text}' not in task:
                raise RuntimeError(f'The prompt expander\'s instructions are not in {cls.TEMPLATES[kind]}.')
            cls._instructions[kind] = (system, task)
        return cls._instructions[kind]

    @staticmethod
    def wanted_size(job: dict) -> tuple[int, int] | None:
        match = re.match(r'^(\d+)x(\d+)$', str((job.get('options') or {}).get('size') or ''))
        return (int(match[1]), int(match[2])) if match and int(match[1]) and int(match[2]) else None

    def plan_input(self, job: dict, pictures: Pictures) -> tuple[str, str]:
        """Which expander (t2i or edit) and what it reads: the request as typed, the pictures' shapes and the one asked for."""
        typed = (job.get('plan') or {}).get('typed') or job['prompt']
        size = self.wanted_size(job)
        if not pictures.names:
            return 't2i', typed + (f'\n\nThe final image is {size[0]}x{size[1]} pixels.' if size else '')
        lines = [f'Reference Media: <Picture {n + 1}> aspect_ratio {ratio(*s)}' for n, s in enumerate(pictures.sizes)]
        lines.append('User Editing Instruction: ' + json.dumps(typed, ensure_ascii=False))
        lines.append('User Aspect Ratio Selection: ' + (ratio(*size) if size else 'adaptive'))
        return 'edit', '\n'.join(lines)

    def plan(self, comfy: 'Comfy', job: dict, pictures: Pictures, report, cancelled) -> tuple[str, tuple[int, int] | None]:
        """The expander's caption, and the shape it chose for the picture."""
        kind, text = self.plan_input(job, pictures)
        system, task = self.instructions(kind)
        seed = random.randint(0, 2 ** 53)
        g: dict[str, dict] = {'planner': {'class_type': 'CLIPLoader', 'inputs': {'clip_name': self.FILES['planner'][1], 'type': 'minimax', 'device': 'default'}}}
        generate = {'clip': ['planner', 0], 'prompt': task.replace('{input text}', text), 'system_prompt': system, 'max_length': 8192,
                    'sampling_mode': 'on', 'sampling_mode.temperature': 1.0, 'sampling_mode.top_k': 20, 'sampling_mode.top_p': 0.95,
                    'sampling_mode.min_p': 0.0, 'sampling_mode.repetition_penalty': 1.0, 'sampling_mode.seed': seed,
                    'sampling_mode.presence_penalty': 0.0, 'thinking': True, 'use_default_template': True, 'mtp': 'auto'}
        for n, name in enumerate(pictures.planner):
            g[f'plan_picture{n}'] = {'class_type': 'LoadImage', 'inputs': {'image': name}}
            generate[f'images.image{n + 1}'] = [f'plan_picture{n}', 0]
        g['plan'] = {'class_type': 'TextGenerateV2', 'inputs': generate}
        g['plan_text'] = {'class_type': 'PreviewAny', 'inputs': {'source': ['plan', 0]}}
        return expanded(comfy.run(g, 'plan_text', report, cancelled, what='prompt', timeout=15 * 60))

    @staticmethod
    def final_prompt(job: dict, caption: str | None) -> str:
        """What the model reads: the expander's caption between what Atelier adds (or the prompt as Atelier sent it), with
        the trigger words of the LoRAs that are not in it yet."""
        plan = job.get('plan') or {}
        prompt = (plan.get('before', '') + caption + plan.get('after', '')) if caption else job['prompt']
        words = []
        for lora in (job.get('options') or {}).get('loras') or []:
            for word in re.split(r'\s*,\s*', str(lora.get('trigger') or '').strip()):
                if word and word.lower() not in prompt.lower() and word not in words:
                    words.append(word)
        return (', '.join(words) + '\n\n' + prompt) if words else prompt

    # The picture --------------------------------------------------------------------------------------------------------

    def canvas(self, job: dict, pictures: Pictures, shape: tuple[int, int] | None) -> tuple[int, int]:
        """The size the model draws at: the megapixels of the quality (or of the size asked for, when it is bigger), in the
        shape of the size asked for, of the picture being changed, or the one the expander chose."""
        o = job.get('options') or {}
        megapixels = min(self.MAX_MEGAPIXELS, max(1.0, float(o.get('megapixels') or 16)))
        size = self.wanted_size(job)
        operation = job.get('operation') or 'generate'
        if pictures.change is not None:
            # The result goes back at the original's size: more than that (or than the 4 MP the model needs) is not seen.
            form = pictures.original.size
            megapixels = min(megapixels, max(4.0, form[0] * form[1] / 1048576))
        elif size:
            form = size
        elif shape:
            form = shape
        elif pictures.sizes and operation != 'generate':
            form = pictures.sizes[0]
        else:
            form = (1, 1)
        return canvas(*form, megapixels)

    def graph(self, job: dict, pictures: Pictures, prompt: str, width: int, height: int, seed: int) -> tuple[dict, str]:
        g: dict[str, dict] = {
            'unet': {'class_type': 'UNETLoader', 'inputs': {'unet_name': self.FILES['model'][1], 'weight_dtype': 'default'}},
            'clip': {'class_type': 'CLIPLoader', 'inputs': {'clip_name': self.FILES['clip'][1], 'type': 'minimax', 'device': 'default'}},
            'vae': {'class_type': 'VAELoader', 'inputs': {'vae_name': self.FILES['vae'][1]}},
        }
        model = ['unet', 0]
        for n, lora in enumerate((job.get('options') or {}).get('loras') or []):
            g[f'lora{n}'] = {'class_type': 'LoraLoaderModelOnly', 'inputs': {'model': model, 'lora_name': self.lora_file(lora)[1],
                                                                             'strength_model': float(lora.get('strength', 1.0))}}
            model = [f'lora{n}', 0]
        condition = {'clip': ['clip', 0], 'vae': ['vae', 0], 'prompt': prompt, 'width': width, 'height': height}
        for n, name in enumerate(pictures.names):
            g[f'picture{n}'] = {'class_type': 'LoadImage', 'inputs': {'image': name}}
            condition[f'images.image_{n}'] = [f'picture{n}', 0]
        g['condition'] = {'class_type': 'MiniMaxH3ImageConditioning', 'inputs': condition}
        g['negative'] = {'class_type': 'ConditioningZeroOut', 'inputs': {'conditioning': ['condition', 0]}}
        g['sampler'] = {'class_type': 'KSampler', 'inputs': {'model': model, 'seed': seed, 'steps': self.STEPS, 'cfg': 1.0, 'sampler_name': 'euler',
                                                             'scheduler': 'simple', 'positive': ['condition', 0], 'negative': ['negative', 0],
                                                             'latent_image': ['condition', 1], 'denoise': 1.0}}
        g['decode'] = {'class_type': 'VAEDecode', 'inputs': {'samples': ['sampler', 0], 'vae': ['vae', 0]}}
        g['save'] = {'class_type': 'SaveImage', 'inputs': {'images': ['decode', 0], 'filename_prefix': f'atelier/{job["id"]}'}}
        return g, 'save'

    @staticmethod
    def finish(worker: 'Worker', job: dict, pictures: Pictures, made) -> str:
        """The picture as Atelier gets it: pasted into the original only inside the marked area, at the size asked for an
        upscale, in the format of the request."""
        Image, ImageFilter, _ = pillow()
        made = made.convert('RGB')
        if pictures.change is not None:
            original = pictures.original
            made = made.resize(original.size, Image.LANCZOS)
            # A soft edge a little outside the area, so the seam does not show.
            edge = max(2, round(min(original.size) * 0.004))
            change = pictures.change.filter(ImageFilter.MaxFilter(2 * min(edge, 7) + 1)).filter(ImageFilter.GaussianBlur(edge))
            made = Image.composite(made, original, change)
        elif (job.get('operation') == 'upscale') and (size := H3Image.wanted_size(job)) and made.size != size:
            made = made.resize(size, Image.LANCZOS)
        o = job.get('options') or {}
        form = o.get('format') if o.get('format') in ('png', 'jpeg', 'webp') else 'png'
        quality = max(1, min(100, int(o.get('compression') or 90)))
        path = os.path.join(worker.workdir, f'picture-{job["id"]}.{"jpg" if form == "jpeg" else form}')
        if form == 'png':
            made.save(path, 'PNG', compress_level=6)
        elif form == 'jpeg':
            made.save(path, 'JPEG', quality=quality, subsampling=0 if quality >= 90 else 2, optimize=True)
        else:
            made.save(path, 'WEBP', quality=quality, method=4)
        return path

    def draw(self, worker: 'Worker', job: dict, pictures: Pictures, prompt: str, width: int, height: int, seed: int, report, cancelled):
        """The picture from the model, as Pillow opens it."""
        Image, _, _ = pillow()
        graph, output = self.graph(job, pictures, prompt, width, height, seed)
        path = worker.comfy.run(graph, output, report, cancelled, what='picture')
        try:
            with Image.open(path) as image:
                image.load()
                return image.copy()
        finally:
            try:
                os.remove(path)
            except OSError:
                pass

    def expand(self, worker: 'Worker', job: dict, pictures: Pictures, report, cancelled) -> tuple[str, tuple[int, int] | None]:
        return self.plan(worker.comfy, job, pictures, report, cancelled)

    def folder(self, worker: 'Worker') -> str:
        return worker.comfy.input_dir()

    def make(self, worker: 'Worker', job: dict, report, cancelled) -> tuple[str, dict]:
        for lora in (job.get('options') or {}).get('loras') or []:
            folder, name, _, _ = self.lora_file(lora)
            path = os.path.join(worker.downloads.models, folder, name) if worker.downloads else ''
            if path and os.path.isfile(path) and not safetensors_ok(path):
                os.remove(path)
                raise RuntimeError(f'The link of the LoRA "{lora.get("name") or name}" did not give a .safetensors file. Check it in '
                                   'Settings › GPU; on Civitai, a LoRA that asks you to sign in needs a CIVITAI_TOKEN secret in Colab.')
        planner = self.plans(job)
        pictures = Pictures.fetch(worker, job, self.folder(worker), report, planner)
        try:
            caption, shape = None, None
            if planner:
                report('planning')
                try:
                    caption, shape = self.expand(worker, job, pictures, report, cancelled)
                except Cancelled:
                    raise
                except Exception as error:
                    log(f'The prompt expander failed ({error}); making the picture from the prompt as written.')
            width, height = self.canvas(job, pictures, shape)
            seed = (job.get('options') or {}).get('seed')
            seed = seed if isinstance(seed, int) and seed >= 0 else random.randint(0, 2 ** 53)
            made = self.draw(worker, job, pictures, self.final_prompt(job, caption), width, height, seed, report, cancelled)
            if cancelled():
                raise Cancelled()
            report('finishing')
            path = self.finish(worker, job, pictures, made)
            with pillow()[0].open(path) as image:
                size = image.size
            return path, {'seed': seed, 'planned_prompt': caption or '', 'width': size[0], 'height': size[1]}
        finally:
            pictures.clean()


class FakeImage(H3Image):
    """A stand-in for H3-Image that paints a test pattern with Pillow, for checking the worker and Atelier without a GPU.
    Everything else is as H3-Image does it: the pictures fetched, the expander's caption (a fake one), the size, the area
    pasted into the original and the format."""

    def __init__(self, steps: int = 3, pause: float = 0.3):
        self.steps = steps
        self.pause = pause
        self.prompts: list[str] = []

    def files(self, job: dict) -> list[tuple[str, str, str, float]]:
        return []

    def preload(self) -> list[tuple[str, str, str, float]]:
        return []

    def folder(self, worker: 'Worker') -> str:
        return os.path.join(worker.workdir, 'inputs')

    def expand(self, worker: 'Worker', job: dict, pictures: Pictures, report, cancelled) -> tuple[str, tuple[int, int] | None]:
        kind, text = self.plan_input(job, pictures)
        if 'plan-fail' in job['prompt']:
            raise RuntimeError('The test asked the expander to fail.')
        return f'Expanded ({kind}): ' + text.replace('\n', ' '), (16, 9) if kind == 't2i' else None

    def draw(self, worker: 'Worker', job: dict, pictures: Pictures, prompt: str, width: int, height: int, seed: int, report, cancelled):
        Image, _, _ = pillow()
        if 'fail' in job['prompt'] and 'plan-fail' not in job['prompt']:
            raise RuntimeError('The test asked this request to fail.')
        report('loading')
        for step in range(1, self.steps + 1):
            time.sleep(self.pause)
            if cancelled():
                raise Cancelled()
            report('generating', step, self.steps)
        self.prompts.append(prompt)
        # Small, in the shape the model would draw: the size comes from the shape.
        width, height = canvas(width, height, 0.25)
        image = Image.linear_gradient('L').resize((width, height)).convert('RGB')
        tint = Image.new('RGB', (width, height), ((seed * 37) % 256, (seed * 91) % 256, (seed * 53) % 256))
        return Image.blend(image, tint, 0.5)


ENGINES = {'h3': H3, 'h3-image': H3Image}


# ---------------------------------------------------------------------------------------------------------------------
# The worker
# ---------------------------------------------------------------------------------------------------------------------

def probe(path: str) -> dict:
    """What Atelier keeps about a video: width, height, duration, fps, frames and whether it has sound (ffprobe)."""
    data = json.loads(sh(['ffprobe', '-v', 'error', '-print_format', 'json', '-show_streams', '-show_format', path], timeout=120))
    video = next((s for s in data.get('streams', []) if s.get('codec_type') == 'video'), None)
    if video is None:
        raise RuntimeError('The video has no picture.')
    num, _, den = str(video.get('avg_frame_rate') or video.get('r_frame_rate') or '0/1').partition('/')
    fps = float(num) / float(den or 1) if float(den or 1) else 0.0
    duration = float(video.get('duration') or data.get('format', {}).get('duration') or 0)
    frames = int(video['nb_frames']) if str(video.get('nb_frames', '')).isdigit() else round(duration * fps)
    return {'width': int(video['width']), 'height': int(video['height']), 'duration': round(duration, 3), 'fps': round(fps, 3),
            'frames': frames, 'audio': any(s.get('codec_type') == 'audio' for s in data.get('streams', []))}


def poster(path: str, workdir: str) -> str:
    """The video's first frame as a JPEG data URL, for the library's tile."""
    target = os.path.join(workdir, 'poster.jpg')
    sh(['ffmpeg', '-y', '-v', 'error', '-i', path, '-frames:v', '1', '-vf', "scale='min(1536,iw)':-2", '-q:v', '3', target], timeout=120)
    with open(target, 'rb') as f:
        data = f.read()
    os.remove(target)
    return 'data:image/jpeg;base64,' + base64.b64encode(data).decode()


class Worker:
    def __init__(self, api: Atelier, engines: list[Engine], *, comfy: Comfy | None, downloads: Downloads | None, workdir: str,
                 shutdown: bool = True, max_jobs: int | None = None, preload: bool = True):
        self.api = api
        self.state = api.state
        self.engines = {e.id: e for e in engines}
        self.comfy = comfy
        self.downloads = downloads
        self.workdir = workdir
        self.shutdown_runtime = shutdown
        self.max_jobs = max_jobs
        self.preload = preload
        self.piece = 8 << 20
        self.max_bytes = 0
        self.gpu = ''
        self.done = 0
        self.ended = threading.Event()
        self.revoked = False
        os.makedirs(workdir, exist_ok=True)

    # The state the page shows ------------------------------------------------------------------------------------------

    def say(self, message: str, phase: str | None = None) -> None:
        log(message)
        self.state.set(message=message[:300], **({'phase': phase} if phase else {}))

    def download_progress(self, _phase: str, done: int, total: int) -> None:
        self.state.set(phase='download', message=f'Downloading the models: {done / 1e9:.1f} of {total / 1e9:.1f} GB',
                       progress={'done': int(done // 1048576), 'total': int(total // 1048576)})

    def pulse(self) -> None:
        """A sign of life every 15 s while nothing else calls Atelier (downloads, long steps)."""
        while not self.ended.wait(5):
            if time.time() - self.api.last_call < 15:
                continue
            try:
                self.api.call('pulse', attempts=1, timeout=20)
            except Revoked:
                self.revoked = True
            except Exception:
                pass

    # The session ------------------------------------------------------------------------------------------------------

    def run(self) -> str:
        """Works until the runtime should turn off; returns why."""
        name, memory, _ = gpu_info()
        self.gpu = name or 'CPU'
        self.state.set(gpu=self.gpu, vram=memory or None, engines=sorted(self.engines))
        # The real models only: the test patterns run anywhere. Checked before installing or downloading anything.
        problem = gpu_problem(name, memory) if self.comfy is not None else ''
        if problem:
            self.state.set(phase='setup', message=problem[:300])
        hello = self.api.call('hello')
        log(f'Connected to Atelier as {hello.get("account")} (worker {VERSION}, {self.gpu}{f", {memory // 1024} GB" if memory else ""}).')
        if problem:
            raise WrongGpu(problem)
        self.piece = int(hello.get('piece') or self.piece)
        self.max_bytes = int(hello.get('max_video_bytes') or 0)
        known = set(hello.get('engines') or [])
        self.engines = {k: v for k, v in self.engines.items() if not known or k in known}
        if not self.engines:
            raise RuntimeError('This Atelier server knows none of the worker\'s models. Update Atelier.')
        self.state.set(engines=sorted(self.engines))
        threading.Thread(target=self.pulse, daemon=True).start()
        try:
            if self.comfy is not None:
                self.comfy.install(lambda m: self.say(m, 'setup'))
                self.comfy.start(lambda m: self.say(m, 'setup'))
            if self.preload and self.downloads is not None:
                # The models of the requests waiting, or of the latest one: the others download when a request needs them.
                wanted = [e for e in hello.get('queued') or [] if e in self.engines]
                if not wanted and hello.get('recent') in self.engines:
                    wanted = [hello['recent']]
                elif not wanted and 'queued' not in hello:
                    wanted = list(self.engines)[:1]
                for engine in wanted:
                    self.downloads.ensure(self.engines[engine].preload(), self.download_progress)
            return self.loop()
        finally:
            self.ended.set()

    def loop(self) -> str:
        idle_since = time.time()
        self.state.set(phase='ready', message='Waiting for requests', idle_since=int(idle_since), progress=None)
        log(f'Ready. The runtime turns off after {self.api.idle_minutes()} minutes without requests, or from Atelier.')
        while True:
            if self.revoked:
                return 'revoked'
            if self.api.stop_wanted():
                return 'asked'
            idle = time.time() - idle_since
            limit = self.api.idle_minutes() * 60
            if idle >= limit:
                return 'idle'
            if time.time() - self.api.last_contact > max(limit, 600):
                return 'unreachable'
            try:
                answer = self.api.call('claim', {'engines': sorted(self.engines), 'wait': int(max(1, min(20, limit - idle)))}, timeout=60, attempts=3)
            except Revoked:
                return 'revoked'
            except Exception as error:
                log(f'Atelier did not answer ({error}). Trying again.')
                time.sleep(10)
                continue
            job = answer.get('job')
            if not job:
                continue
            self.process(job)
            self.done += 1
            idle_since = time.time()
            self.state.set(phase='ready', message='Waiting for requests', idle_since=int(idle_since), progress=None, making=None)
            if self.max_jobs is not None and self.done >= self.max_jobs:
                return 'done'

    # A request --------------------------------------------------------------------------------------------------------

    def process(self, job: dict) -> None:
        o = job.get('options') or {}
        engine = self.engines.get(o.get('engine', ''))
        picture = job.get('kind') == 'image'
        started = time.time()
        if picture:
            log(f'Request {job["id"][:8]}: {engine.label if engine else o.get("engine")} {job.get("operation", "generate")}, '
                f'{o.get("quality")} (about {o.get("megapixels")} MP), {job.get("images", 0)} picture(s), {len(o.get("loras") or [])} LoRA(s).')
        else:
            log(f'Request {job["id"][:8]}: {engine.label if engine else o.get("engine")} {o.get("mode")}, {o.get("seconds")} s, '
                f'{o.get("resolution")}, {o.get("quality")}.')
        self.state.set(phase='busy', message='Making a picture' if picture else 'Making a video', making='picture' if picture else 'video', progress=None)
        cancel = {'wanted': False}
        last = {'at': 0.0, 'phase': None}

        def report(phase: str | None, done: int | None = None, total: int | None = None, message: str = '') -> None:
            now = time.time()
            if phase is None:
                if now - last['at'] < 10:
                    return
                phase = last['phase'] or 'generating'
            elif phase == last['phase'] and done is None and now - last['at'] < 10:
                return
            elif phase == last['phase'] and now - last['at'] < 3 and (done is None or done != total):
                return
            last.update(at=now, phase=phase)
            body = {'job': job['id'], 'phase': phase, 'message': message}
            if done is not None and total:
                body.update(done=int(done), total=int(total))
                elapsed = now - started
                if phase == 'generating' and done > 0:
                    body['eta'] = int(elapsed / done * (total - done))
            self.state.set(progress={'done': int(done), 'total': int(total)} if done is not None and total else None)
            try:
                answer = self.api.call('progress', body, attempts=2, timeout=30)
                cancel['wanted'] = cancel['wanted'] or bool(answer.get('cancel'))
            except Gone:
                cancel['wanted'] = True
            except Revoked:
                self.revoked = True
                cancel['wanted'] = True
            except Exception as error:
                log(f'Could not report the progress ({error}).')

        try:
            if engine is None:
                raise RuntimeError(f'This worker does not run the model {o.get("engine")}.')
            if self.downloads is not None:
                files = engine.files(job)
                if any(not self.downloads.complete(f, n) for f, n, _, _ in files):
                    report('download', 0, 1)
                    self.downloads.ensure(files, lambda _p, done, total: report('download', int(done // 1048576), int(total // 1048576)))
            if picture != (getattr(engine, 'kind', 'video') == 'image'):
                raise RuntimeError(f'{engine.label} does not make {"pictures" if picture else "videos"}.')
            path, extra = engine.make(self, job, report, lambda: cancel['wanted'])
            if cancel['wanted']:
                raise Cancelled()
            info = {} if picture else probe(path)
            report('uploading', 0, 1)
            upload = self.upload(job['id'], path, report, picture)
            took = int(time.time() - started)
            result = self.api.call('finish', {'job': job['id'], 'upload': upload, 'took': took, 'gpu': self.gpu, **info, **extra,
                                              **({} if picture else {'poster': poster(path, self.workdir)})})
            self.state.set(loaded=engine.id)
            if picture:
                log(f'Request {job["id"][:8]} is in the library ({extra["width"]}x{extra["height"]}, made in {took} s).')
            else:
                log(f'Request {job["id"][:8]} is in the library ({info["width"]}x{info["height"]}, {info["duration"]:.1f} s, made in {took} s).')
            try:
                os.remove(path)
            except OSError:
                pass
            return result
        except Cancelled:
            log(f'Request {job["id"][:8]} was cancelled.')
            self._fail(job['id'], 'Cancelled.', True)
        except (Gone, Revoked) as error:
            log(f'Request {job["id"][:8]} ended in Atelier ({error}).')
        except Exception as error:
            log(f'Request {job["id"][:8]} failed: {error}')
            if not isinstance(error, (RuntimeError, ValueError, ServerError)):
                traceback.print_exc()
            self._fail(job['id'], str(error), False)
            if self.comfy is not None and not self.comfy.alive():
                try:
                    self.comfy.start(lambda m: self.say(m, 'busy'))
                except Exception as restart:
                    log(f'ComfyUI did not start again: {restart}')

    def _fail(self, job: str, error: str, cancelled: bool) -> None:
        try:
            self.api.call('fail', {'job': job, 'error': error, 'cancelled': cancelled}, attempts=4)
        except Exception as failure:
            log(f'Could not tell Atelier: {failure}')

    def upload(self, job: str, path: str, report, picture: bool = False) -> str:
        """Sends the video or picture in pieces (whole MB, as Atelier stores them); a lost piece is sent again from where
        the server is."""
        size = os.path.getsize(path)
        if self.max_bytes and size > self.max_bytes and not picture:
            raise RuntimeError(f'The video has {size / 1048576:.0f} MB, over this server\'s limit of {self.max_bytes / 1048576:.0f} MB (max_video_mb).')
        answer = self.api.call('upload', {'job': job, 'size': size})
        upload, piece = answer['upload'], int(answer.get('piece') or self.piece)
        piece = max(1 << 20, piece - piece % (1 << 20))
        offset = 0
        with open(path, 'rb') as f:
            while offset < size:
                f.seek(offset)
                data = f.read(piece)
                received = int(self.api.call('chunk', raw=data, query={'job': job, 'upload': upload, 'offset': offset}, timeout=120)['received'])
                if received <= offset:
                    raise RuntimeError('The server did not take the piece of the file.')
                offset = received
                report('uploading', offset, size)
        return upload


def run(url: str, token: str, *, comfy_dir: str | None = None, hf_token: str | None = None, civitai_token: str | None = None,
        shutdown: bool = True, engine: str = 'all', max_jobs: int | None = None, preload: bool = True) -> str:
    """Connects to Atelier and makes its video and picture requests until the runtime should turn off. Returns why it
    stopped. engine: 'all' (every model), one model's id, or 'fake' (test patterns, with no GPU)."""
    if not url or not token:
        raise SystemExit('Give the Atelier address and the worker token (Colab: the ATELIER_URL and ATELIER_TOKEN secrets).')
    state = State()
    api = Atelier(url, token, state)
    colab = in_colab()
    base = '/content' if colab else os.path.abspath('.')
    if engine == 'fake':
        worker = Worker(api, [Fake(), FakeImage()], comfy=None, downloads=None, workdir=os.path.join(base, 'atelier-work'),
                        shutdown=shutdown, max_jobs=max_jobs, preload=False)
    else:
        comfy = Comfy(comfy_dir or os.path.join(base, 'ComfyUI'))
        engines = [make() for key, make in ENGINES.items() if engine in ('all', key)]
        if not engines:
            raise SystemExit(f'Unknown model {engine}: use all, {", ".join(ENGINES)} or fake.')
        worker = Worker(api, engines, comfy=comfy, downloads=Downloads(comfy.models, hf_token, civitai_token),
                        workdir=os.path.join(base, 'atelier-work'), shutdown=shutdown, max_jobs=max_jobs, preload=preload)
    why = 'error'
    message = ''
    try:
        why = worker.run()
    except Revoked as error:
        why, message = 'revoked', str(error)
    except WrongGpu as error:
        why, message = 'gpu', str(error)
    except KeyboardInterrupt:
        why = 'stopped'
    except Exception as error:
        why, message = 'error', str(error)
        traceback.print_exc()
    reasons = {'idle': f'No requests for {api.idle_minutes()} minutes.', 'asked': 'Turned off from Atelier.', 'done': 'Done.',
               'revoked': 'The worker token was revoked.', 'unreachable': 'Atelier did not answer for too long.',
               'stopped': 'Stopped in the notebook.', 'gpu': message, 'error': 'Stopped by an error: ' + message}
    log(reasons.get(why, why))
    if why != 'revoked':
        state.set(phase='off', message=reasons.get(why, why)[:300])
        try:
            api.call('bye', {'reason': reasons.get(why, why), 'why': why}, attempts=2, timeout=20)
        except Exception:
            pass
    if worker.comfy is not None:
        worker.comfy.stop()
    if shutdown and colab and why != 'stopped':
        log('Turning the runtime off, so it spends no more credits.')
        try:
            from google.colab import runtime
            runtime.unassign()
        except Exception as error:
            log(f'Could not turn the runtime off ({error}): use Runtime › Disconnect and delete runtime.')
    return why


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser(description='Atelier\'s GPU worker (videos and pictures).')
    parser.add_argument('--url', default=os.environ.get('ATELIER_URL', ''))
    parser.add_argument('--token', default=os.environ.get('ATELIER_TOKEN', ''))
    parser.add_argument('--comfy', default=None, help='where ComfyUI is (or goes)')
    parser.add_argument('--engine', default='all', choices=['all', *ENGINES, 'fake'])
    parser.add_argument('--max-jobs', type=int, default=None)
    parser.add_argument('--no-preload', action='store_true')
    parser.add_argument('--keep-runtime', action='store_true', help='do not turn the Colab runtime off at the end')
    a = parser.parse_args()
    print(run(a.url, a.token, comfy_dir=a.comfy, hf_token=os.environ.get('HF_TOKEN'), civitai_token=os.environ.get('CIVITAI_TOKEN'),
              shutdown=not a.keep_runtime, engine=a.engine, max_jobs=a.max_jobs, preload=not a.no_preload))
