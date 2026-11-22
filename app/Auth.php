<?php
declare(strict_types=1);
namespace Studio;

final class Auth
{
    private ?array $user = null;

    public function __construct(private Vault $vault, private Users $users, private array $config, private bool $secure)
    {
        session_name('igs_session');
        ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
        ini_set('session.gc_maxlifetime',(string)($config['session_minutes']*60));
        session_save_path($vault->dir.'/sessions');
        // Keep the app's own Cache-Control (no-store, no-transform): PHP's default limiter would replace it.
        session_cache_limiter('');
        session_set_cookie_params(['lifetime'=>0,'path'=>$this->cookiePath(),'secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
        if (!@session_start()) throw new HttpError(503,t('[SESSION-START] Could not start the session. Check the disk space, the permissions of the sessions subfolder and PHP\'s session settings.'));
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
    private function cookiePath(): string { return rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/index.php')),'/').'/'; }
    public function csrf(): string { return $_SESSION['csrf']; }
    public function checkCsrf(): void
    { if (!hash_equals($this->csrf(),$_SERVER['HTTP_X_CSRF_TOKEN']??'')) throw new HttpError(403,t('Session expired. Reload the page.')); }

    /** Creates the first account, an administrator, with the installation code from config.php. */
    public function setup(string $name, string $password, string $token): void
    {
        $this->vault->throttle('setup:'.($_SERVER['REMOTE_ADDR']??''),8,900);
        $expected=$this->config['setup_token'];
        if (strlen($expected)<24 || !hash_equals($expected,$token)) throw new HttpError(403,t('Invalid installation code. Check config.php.'));
        Users::checkPassword($password); Users::cleanName($name);
        $key=random_bytes(32);
        $this->vault->db->exec('BEGIN IMMEDIATE');
        try {
            if ($this->vault->initialized()) throw new HttpError(409,t('The studio has already been set up.'));
            $user=$this->users->create($name,$password,$key,true);
            $this->vault->db->exec('COMMIT');
        } catch (\Throwable $e) { $this->vault->db->exec('ROLLBACK'); throw $e; }
        $this->unlock($user,$key);
    }

    /**
     * Protection against password guessing: each address gets 8 tries per 15 minutes and 30 per day (a sign-in
     * clears both), every failure answers a little later than the one before, and a hidden field that only bots
     * fill counts as a failure. All addresses together get 60 tries per 15 minutes, except from a browser that
     * signed in here before, so a flood of bots can't lock the owner out.
     */
    public function login(string $name, string $password, string $trap=''): void
    {
        $ip=(string)($_SERVER['REMOTE_ADDR']??'');
        $this->vault->throttle('login:'.$ip,8,900);
        $tries=$this->vault->throttle('login-day:'.$ip,30,86400,t('Too many attempts from this address. Try again tomorrow.'));
        $device=(string)($_COOKIE['igs_device']??'');
        if (!(strlen($device)===43 && $this->vault->remembered('device:'.$device))) $this->vault->throttle('login-global',60,900);
        try {
            if ($trap!=='') throw new HttpError(401,t('Wrong user name or password.'));
            if (trim($name)==='') {
                $user=$this->users->only();
                if (!$user && $this->users->count()>1) throw new HttpError(422,t('Enter the user name.'));
            } else $user=$this->users->findByName($name);
            if (!$user) {
                // Same work as a wrong password, so the answer time doesn't tell which names exist.
                Crypto::derive($password,random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES));
                throw new HttpError(401,t('Wrong user name or password.'));
            }
            $key=$this->users->unwrap($user,$password);
        } catch (HttpError $e) {
            // Half a second more for each failure from this address today, up to 3 seconds.
            if ($e->status===401) usleep(min(3000000,500000*($tries-1)));
            throw $e;
        }
        $this->vault->forget('login:'.$ip); $this->vault->forget('login-day:'.$ip);
        $this->rememberDevice($device);
        $this->unlock($user,$key);
    }
    /** Marks this browser as one that signed in here (a random token, renewed on each sign-in, valid for 180 days). */
    private function rememberDevice(string $current): void
    {
        $days=180;
        $token=strlen($current)===43 && $this->vault->remembered('device:'.$current) ? $current : rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $this->vault->remember('device:'.$token,$days*86400);
        setcookie('igs_device',$token,['expires'=>time()+$days*86400,'path'=>$this->cookiePath(),'secure'=>$this->secure,'httponly'=>true,'samesite'=>'Strict']);
    }

    private function unlock(array $user, string $key): void
    {
        session_regenerate_id(true);
        $share=random_bytes(32);
        $_SESSION=['csrf'=>bin2hex(random_bytes(32)),'key'=>base64_encode(Crypto::seal($key,$share,'session-key')),'last'=>time(),'user'=>$user['id'],'epoch'=>$user['epoch']];
        $cookie=base64_encode($share);
        setcookie('igs_unlock',$cookie,['expires'=>0,'path'=>$this->cookiePath(),'secure'=>$this->secure,'httponly'=>true,'samesite'=>'Strict']);
        $_COOKIE['igs_unlock']=$cookie;
        $this->user=$user; $this->vault->useOwner($user['id']);
    }

    /** The account key of this session. Also selects the account's library for the rest of the request. */
    public function key(bool $touch=true): string
    {
        $share=base64_decode($_COOKIE['igs_unlock']??'',true);
        $user=is_string($_SESSION['user']??null) ? $this->users->find($_SESSION['user']) : null;
        if (!$share || strlen($share)!==32 || !$user || empty($_SESSION['key']) || ($_SESSION['last']??0)<time()-$this->config['session_minutes']*60 || !hash_equals((string)$user['epoch'],(string)($_SESSION['epoch']??'-'))) throw new HttpError(401,t('Unlock your studio.'));
        try { $key=Crypto::open(base64_decode($_SESSION['key'],true),$share,'session-key'); }
        catch (\Throwable) { throw new HttpError(401,t('Unlock your studio.')); }
        if ($touch) $_SESSION['last']=time();
        $this->user=$user; $this->vault->useOwner($user['id']);
        return $key;
    }
    public function isUnlocked(): bool { try { $this->key(false); return true; } catch (\Throwable) { return false; } }
    /** The signed-in account. Call key() first. */
    public function user(): array { if ($this->user===null) throw new HttpError(401,t('Unlock your studio.')); return $this->user; }
    public function requireAdmin(?string $message=null): void { if (!$this->user()['admin']) throw new HttpError(403,$message??t('Only the administrator can manage users.')); }

    public function logout(): void
    {
        $_SESSION=[]; session_destroy();
        foreach (['igs_session','igs_unlock'] as $name) setcookie($name,'',['expires'=>time()-3600,'path'=>$this->cookiePath(),'secure'=>$this->secure,'httponly'=>true,'samesite'=>'Strict']);
    }

    /** Checks the signed-in account's password again before a sensitive change. */
    public function confirmPassword(string $password): string
    {
        $this->vault->throttle('confirm-password:'.$this->user()['id'],8,900);
        try { return $this->users->unwrap($this->user(),$password); }
        catch (HttpError $e) { throw new HttpError($e->status===401 ? 403 : $e->status,t('Incorrect password.')); }
    }

    public function changePassword(string $current, string $new): void
    {
        Users::checkPassword($new);
        $key=$this->confirmPassword($current);
        $this->users->setPassword($this->user()['id'],$key,$new);
        $this->unlock($this->users->find($this->user()['id']),$key);
    }
}
