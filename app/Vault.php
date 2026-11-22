<?php
declare(strict_types=1);
namespace Studio;

final class Vault
{
    /** Plaintext bytes per encrypted piece of a video. Each piece is sealed apart, so any byte range can be read alone. */
    public const VIDEO_CHUNK = 1048576;
    private const SEALED = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
    /** Video uploads not continued for this long are removed with their partial file. */
    private const UPLOAD_TTL = 86400;
    public readonly \PDO $db;
    /** The account whose library this request reads and writes; set once the session or token is verified. */
    private ?string $owner = null;
    public function __construct(public readonly string $dir)
    {
        umask(0077);
        foreach ([$dir, "$dir/blobs", "$dir/sessions"] as $path) {
            if (!@is_dir($path) && !@mkdir($path, 0700, true) && !@is_dir($path)) {
                throw new HttpError(503,t('[DATA-CREATE] Could not create the private folder. Create a folder outside public_html, let PHP write to it and give its absolute path in data_dir in config.php. Also check the host\'s open_basedir limit.'));
            }
            if (!is_writable($path)) throw new HttpError(503,t('[DATA-WRITE] The private folder exists, but PHP cannot write to it. Check the owner and permissions of the folder and its subfolders.'));
        }
        try {
            $this->db = new \PDO('sqlite:' . $dir . '/vault.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
            $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON; PRAGMA secure_delete=ON;');
            $this->db->exec('CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value BLOB NOT NULL);
              CREATE TABLE IF NOT EXISTS images (id TEXT PRIMARY KEY, meta BLOB NOT NULL, created INTEGER NOT NULL, favorite INTEGER NOT NULL DEFAULT 0, deleted INTEGER);
              CREATE TABLE IF NOT EXISTS jobs (id TEXT PRIMARY KEY, payload BLOB NOT NULL, status TEXT NOT NULL, created INTEGER NOT NULL, updated INTEGER NOT NULL);
              CREATE TABLE IF NOT EXISTS attempts (bucket TEXT PRIMARY KEY, count INTEGER NOT NULL, until_time INTEGER NOT NULL);
              CREATE TABLE IF NOT EXISTS plans (id TEXT PRIMARY KEY, status TEXT NOT NULL, payload BLOB, updated INTEGER NOT NULL);
              CREATE TABLE IF NOT EXISTS users (id TEXT PRIMARY KEY, name TEXT NOT NULL UNIQUE COLLATE NOCASE, salt TEXT NOT NULL, wrapped_key BLOB NOT NULL,
                epoch TEXT NOT NULL, admin INTEGER NOT NULL DEFAULT 0, api_key BLOB, created INTEGER NOT NULL,
                mcp_id TEXT UNIQUE, mcp_key BLOB, mcp_created INTEGER, mcp_used INTEGER);
              CREATE TABLE IF NOT EXISTS links (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, image_id TEXT NOT NULL, sealed BLOB NOT NULL, expires INTEGER NOT NULL);
              CREATE TABLE IF NOT EXISTS uploads (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, size INTEGER NOT NULL, created INTEGER NOT NULL, updated INTEGER NOT NULL);
              CREATE TABLE IF NOT EXISTS albums (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, meta BLOB NOT NULL, created INTEGER NOT NULL);
              CREATE TABLE IF NOT EXISTS album_items (album_id TEXT NOT NULL REFERENCES albums(id) ON DELETE CASCADE, image_id TEXT NOT NULL REFERENCES images(id) ON DELETE CASCADE,
                added INTEGER NOT NULL, PRIMARY KEY (album_id, image_id));
              CREATE TABLE IF NOT EXISTS spend (job_id TEXT PRIMARY KEY, user_id TEXT NOT NULL, created INTEGER NOT NULL, data BLOB NOT NULL);
              CREATE TABLE IF NOT EXISTS stories (id TEXT PRIMARY KEY, user_id TEXT NOT NULL, meta BLOB NOT NULL, notes BLOB, plan BLOB, seen BLOB,
                created INTEGER NOT NULL, updated INTEGER NOT NULL, favorite INTEGER NOT NULL DEFAULT 0, conversation INTEGER NOT NULL DEFAULT 1);
              CREATE TABLE IF NOT EXISTS chapters (id TEXT PRIMARY KEY, story_id TEXT NOT NULL REFERENCES stories(id) ON DELETE CASCADE, user_id TEXT NOT NULL,
                position REAL NOT NULL, body BLOB NOT NULL, rev INTEGER NOT NULL DEFAULT 1, created INTEGER NOT NULL, updated INTEGER NOT NULL);
              CREATE TABLE IF NOT EXISTS story_messages (seq INTEGER PRIMARY KEY AUTOINCREMENT, id TEXT NOT NULL UNIQUE, story_id TEXT NOT NULL REFERENCES stories(id) ON DELETE CASCADE,
                user_id TEXT NOT NULL, conversation INTEGER NOT NULL, turn_id TEXT, role TEXT NOT NULL, content BLOB NOT NULL, created INTEGER NOT NULL);
              CREATE TABLE IF NOT EXISTS story_turns (id TEXT PRIMARY KEY, story_id TEXT NOT NULL REFERENCES stories(id) ON DELETE CASCADE, user_id TEXT NOT NULL,
                conversation INTEGER NOT NULL, status TEXT NOT NULL, created INTEGER NOT NULL, updated INTEGER NOT NULL, started INTEGER, phase TEXT, worker_lock INTEGER NOT NULL DEFAULT 0,
                data BLOB, live BLOB);
              CREATE TABLE IF NOT EXISTS comic_pages (id TEXT PRIMARY KEY, story_id TEXT NOT NULL REFERENCES stories(id) ON DELETE CASCADE, user_id TEXT NOT NULL,
                position REAL NOT NULL, body BLOB NOT NULL, rev INTEGER NOT NULL DEFAULT 1, created INTEGER NOT NULL, updated INTEGER NOT NULL);
              CREATE TABLE IF NOT EXISTS vn_scenes (id TEXT PRIMARY KEY, story_id TEXT NOT NULL REFERENCES stories(id) ON DELETE CASCADE, user_id TEXT NOT NULL,
                position REAL NOT NULL, body BLOB NOT NULL, rev INTEGER NOT NULL DEFAULT 1, created INTEGER NOT NULL, updated INTEGER NOT NULL);
              CREATE TABLE IF NOT EXISTS story_inbox (id TEXT PRIMARY KEY, story_id TEXT NOT NULL REFERENCES stories(id) ON DELETE CASCADE, user_id TEXT NOT NULL,
                created INTEGER NOT NULL, content BLOB NOT NULL);
              CREATE INDEX IF NOT EXISTS story_inbox_story ON story_inbox(story_id, created);
              CREATE INDEX IF NOT EXISTS stories_user ON stories(user_id, updated DESC);
              CREATE INDEX IF NOT EXISTS chapters_story ON chapters(story_id, position);
              CREATE INDEX IF NOT EXISTS comic_pages_story ON comic_pages(story_id, position);
              CREATE INDEX IF NOT EXISTS vn_scenes_story ON vn_scenes(story_id, position);
              CREATE INDEX IF NOT EXISTS story_messages_story ON story_messages(story_id, conversation, seq);
              CREATE INDEX IF NOT EXISTS story_turns_story ON story_turns(story_id, created);
              CREATE INDEX IF NOT EXISTS spend_user ON spend(user_id, created);
              CREATE INDEX IF NOT EXISTS images_created ON images(created DESC);
              CREATE INDEX IF NOT EXISTS albums_user ON albums(user_id);
              CREATE INDEX IF NOT EXISTS album_items_image ON album_items(image_id);');
            // Columns added after the first release. Phase, preview counters, owners, when a request started and whether its
            // process holds a lock, the custom order, the chosen cover of each image's versions, whether an item is hidden from
            // the library and the stack it was grouped into (a random id) hold no user content; the
            // balance settings and the account's preferences (the reader's settings) are sealed like the API key, and a story's
            // character sheets, its comic adaptation (with the adaptation's own bible, plan and sheets) and a visual novel's
            // settings and art like its bible.
            foreach (['jobs'=>['phase'=>'TEXT', 'preview'=>'INTEGER NOT NULL DEFAULT 0', 'user_id'=>'TEXT', 'started'=>'INTEGER', 'worker_lock'=>'INTEGER NOT NULL DEFAULT 0'], 'images'=>['user_id'=>'TEXT', 'position'=>'REAL', 'cover'=>'INTEGER NOT NULL DEFAULT 0', 'hidden'=>'INTEGER NOT NULL DEFAULT 0', 'stack'=>'TEXT'],
                'users'=>['billing'=>'BLOB', 'claude_key'=>'BLOB', 'prefs'=>'BLOB', 'gemini_key'=>'BLOB'], 'stories'=>['cast'=>'BLOB', 'comic'=>'BLOB', 'vn'=>'BLOB', 'vn_art'=>'BLOB', 'comic_notes'=>'BLOB', 'comic_plan'=>'BLOB', 'comic_cast'=>'BLOB']] as $table => $added) {
                $columns = array_column($this->db->query("PRAGMA table_info($table)")->fetchAll(), 'name');
                foreach ($added as $column => $type) {
                    if (in_array($column, $columns, true)) continue;
                    try { $this->db->exec("ALTER TABLE $table ADD COLUMN $column $type"); }
                    catch (\PDOException $e) { if (!str_contains($e->getMessage(), 'duplicate column')) throw $e; } // concurrent first request
                }
            }
            $this->db->exec('CREATE INDEX IF NOT EXISTS jobs_status ON jobs(status);
              CREATE INDEX IF NOT EXISTS images_user ON images(user_id, created DESC);
              CREATE INDEX IF NOT EXISTS jobs_user ON jobs(user_id, created DESC);
              CREATE INDEX IF NOT EXISTS story_messages_user ON story_messages(user_id, role, created DESC);');
            $this->adoptLegacyOwner();
        } catch (\PDOException $e) {
            logFailure($e);
            throw new HttpError(503,t('[DATA-SQLITE] Could not open or prepare SQLite. Check the disk space, the permissions of the vault.sqlite file and that the private folder is writable for the WAL/SHM files. Do not delete the existing database.'));
        }
    }

    /**
     * Single-owner vaults kept their key in settings and their images without an owner. The first request after the
     * upgrade turns that owner into the first account, an administrator named "admin", with the same password, key and
     * API key, so nothing is re-encrypted. Rows written without an owner later (by a request of the previous version
     * still running during the upload) are given to the first administrator too.
     */
    private function adoptLegacyOwner(): void
    {
        $legacy = $this->get('wrapped_key') !== null;
        if (!$legacy && !$this->db->query('SELECT 1 FROM images WHERE user_id IS NULL UNION ALL SELECT 1 FROM jobs WHERE user_id IS NULL LIMIT 1')->fetchColumn()) return;
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $first = $this->db->query('SELECT id FROM users WHERE admin=1 ORDER BY created, rowid LIMIT 1')->fetchColumn();
            $wrapped = $this->get('wrapped_key');
            if ($first === false && $wrapped !== null) {
                $first = bin2hex(random_bytes(16));
                $q = $this->db->prepare('INSERT INTO users(id,name,salt,wrapped_key,epoch,admin,api_key,created) VALUES(?,?,?,CAST(? AS BLOB),?,1,CAST(? AS BLOB),?)');
                $q->execute([$first, 'admin', (string)$this->get('salt'), $wrapped, $this->get('epoch') ?? bin2hex(random_bytes(16)), $this->get('api_key'), time()]);
            }
            if ($first !== false) {
                foreach (['images','jobs'] as $table) $this->db->prepare("UPDATE $table SET user_id=? WHERE user_id IS NULL")->execute([$first]);
                // The password-wrapped key now lives only in the account, so a later password change can't leave an old copy behind.
                $this->db->exec("DELETE FROM settings WHERE name IN ('salt','wrapped_key','epoch','api_key')");
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }

    public function useOwner(string $id): void { $this->owner = $id; }

    /**
     * One row read on a connection of its own, which sees the latest commit: a statement left open on this request's
     * connection keeps it on an older snapshot, where work another process took still looks untaken.
     */
    public function fresh(string $sql, array $params): ?array
    {
        $db = new \PDO('sqlite:'.$this->dir.'/vault.sqlite', null, null, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC, \PDO::SQLITE_ATTR_OPEN_FLAGS=>\PDO::SQLITE_OPEN_READONLY]);
        $db->exec('PRAGMA busy_timeout=2000');
        $q = $db->prepare($sql); $q->execute($params); $row = $q->fetch(); $q->closeCursor();
        return $row === false ? null : $row;
    }
    public function owner(): string
    {
        if ($this->owner === null) throw new \LogicException('No account selected.');
        return $this->owner;
    }

    public function get(string $name): ?string
    {
        $q = $this->db->prepare('SELECT value FROM settings WHERE name=?'); $q->execute([$name]);
        $v = $q->fetchColumn(); return $v === false ? null : (string)$v;
    }
    public function set(string $name, string $value): void
    {
        $q = $this->db->prepare('INSERT INTO settings(name,value) VALUES(?,CAST(? AS BLOB)) ON CONFLICT(name) DO UPDATE SET value=excluded.value');
        $q->execute([$name, $value]);
    }
    public function initialized(): bool { return (bool)$this->db->query('SELECT 1 FROM users LIMIT 1')->fetchColumn(); }

    /** The account's own OpenAI key, encrypted with the account's key. */
    public function apiKey(string $key): string
    {
        $q = $this->db->prepare('SELECT api_key FROM users WHERE id=?'); $q->execute([$this->owner()]);
        $v = $q->fetchColumn(); return $v === false || $v === null ? '' : Crypto::open((string)$v, $key, 'api_key');
    }
    /** null removes it. */
    public function setApiKey(?string $value, string $key): void
    {
        $q = $this->db->prepare('UPDATE users SET api_key=CAST(? AS BLOB) WHERE id=?');
        $q->execute([$value === null ? null : Crypto::seal($value, $key, 'api_key'), $this->owner()]);
    }

    /** The account's own Anthropic (Claude) key, encrypted with the account's key like the OpenAI one. */
    public function claudeKey(string $key): string
    {
        $q = $this->db->prepare('SELECT claude_key FROM users WHERE id=?'); $q->execute([$this->owner()]);
        $v = $q->fetchColumn(); return $v === false || $v === null ? '' : Crypto::open((string)$v, $key, 'claude_key');
    }
    public function setClaudeKey(?string $value, string $key): void
    {
        $q = $this->db->prepare('UPDATE users SET claude_key=CAST(? AS BLOB) WHERE id=?');
        $q->execute([$value === null ? null : Crypto::seal($value, $key, 'claude_key'), $this->owner()]);
    }

    /** The account's own Google Gemini key, for writing and for the Nano Banana images, encrypted like the others. */
    public function geminiKey(string $key): string
    {
        $q = $this->db->prepare('SELECT gemini_key FROM users WHERE id=?'); $q->execute([$this->owner()]);
        $v = $q->fetchColumn(); return $v === false || $v === null ? '' : Crypto::open((string)$v, $key, 'gemini_key');
    }
    public function setGeminiKey(?string $value, string $key): void
    {
        $q = $this->db->prepare('UPDATE users SET gemini_key=CAST(? AS BLOB) WHERE id=?');
        $q->execute([$value === null ? null : Crypto::seal($value, $key, 'gemini_key'), $this->owner()]);
    }

    /**
     * A picture of a library image small enough to show a model: up to $max px on its longest side, JPEG, or PNG when
     * the image is transparent. Used by MCP and by Claude in the stories.
     * @return array{mime:string,data:string} data is base64
     */
    public function modelPreview(array $item, string $key, int $max = 1024): array
    {
        $bytes = $this->bytes($item['id'], 'full', $key, true);
        $src = @imagecreatefromstring($bytes);
        if (!$src) throw new HttpError(500, 'Could not read the image.');
        $w = imagesx($src); $h = imagesy($src); $scale = min(1, $max / max($w, $h));
        $out = imagecreatetruecolor(max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)));
        $alpha = ($item['options']['background'] ?? '') === 'transparent';
        if ($alpha) { imagealphablending($out, false); imagesavealpha($out, true); }
        else imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $src, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        ob_start(); $alpha ? imagepng($out, null, 6) : imagejpeg($out, null, 85); $data = (string)ob_get_clean();
        imagedestroy($src); imagedestroy($out);
        return ['mime'=>$alpha ? 'image/png' : 'image/jpeg', 'data'=>base64_encode($data)];
    }

    public function saveImage(string $bytes, array $meta, string $key): array
    {
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'], ['image/png','image/jpeg','image/webp'], true)) throw new \RuntimeException(t('The API returned an invalid image.'));
        $id = bin2hex(random_bytes(16));
        $this->store($id, ['full'=>$bytes, 'thumb'=>$this->thumbnail($bytes)], array_merge($meta, ['width'=>$info[0], 'height'=>$info[1], 'mime'=>$info['mime']]), $key);
        return $this->image($id, $key);
    }

    /** Writes the encrypted files of a library item, then its row. Nothing is left behind when a step fails. */
    private function store(string $id, array $variants, array $meta, string $key): void
    {
        $paths = [];
        try {
            foreach ($variants as $variant=>$content) {
                $path = "$this->dir/blobs/$id-$variant.enc";
                if (file_put_contents($path, Crypto::seal($content, $key, "$id:$variant"), LOCK_EX) === false) throw new \RuntimeException(t('Could not save the image.'));
                $paths[] = $path;
            }
            // A new version of a hidden image is hidden too, and one of an image in a stack joins that stack.
            $parent = is_string($meta['parent_id'] ?? null) ? $meta['parent_id'] : '';
            $q = $this->db->prepare('INSERT INTO images(id,meta,created,user_id,hidden,stack) VALUES(?,CAST(? AS BLOB),?,?,
                COALESCE((SELECT hidden FROM images WHERE id=? AND user_id=?),0),(SELECT stack FROM images WHERE id=? AND user_id=?))');
            $q->execute([$id, Crypto::seal(json_encode($meta, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE), $key, "$id:meta"), time(), $this->owner(),
                $parent, $this->owner(), $parent, $this->owner()]);
        } catch (\Throwable $e) { foreach ($paths as $path) @unlink($path); throw $e; }
    }

    private static function cleanTitle(string $name, string $fallback): string
    {
        $title = trim(preg_replace('/\.[A-Za-z0-9]{1,5}\z/', '', preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '') ?? '');
        return mb_substr($title === '' ? $fallback : $title, 0, 160);
    }

    /**
     * Adds an image from the owner's device as the start of a new lineage. With $source it is a frame saved from a video
     * (['video_id'=>…, 'frame'=>…, 'time'=>…]), which the video's viewer lists and links back to.
     */
    public function importImage(string $bytes, string $name, string $key, ?array $source = null): array
    {
        $meta = ['title'=>self::cleanTitle($name, $source === null ? t('Imported image') : t('Video frame')),'prompt'=>'','original_prompt'=>'','options'=>null,'parent_ids'=>[],'parent_id'=>null,
            'operation'=>$source === null ? 'import' : 'frame','label'=>null];
        if ($source !== null) $meta['source'] = $this->frameSource($source);
        return $this->saveImage($bytes, $meta, $key);
    }

    /** Limpar: how strongly the owner's device cleaned an image. */
    public const CLEAN_LEVELS = ['leve'=>true,'media'=>true,'forte'=>true];
    /** The name of the version a cleaned image becomes. */
    private static function cleanLabel(string $level): string
    { return match ($level) { 'leve' => t('Light cleanup'), 'forte' => t('Strong cleanup'), default => t('Medium cleanup') }; }

    /**
     * A copy of an image with its dots and fine lines removed on the owner's device (Limpar), kept as a version of that
     * image. Nothing is sent to the image API. It has the size of the image it came from (sides may be swapped: the
     * browser applies the rotation a camera recorded).
     */
    public function cleanedImage(string $bytes, string $parent, string $level, string $key): array
    {
        $from = $this->still($parent, $key);
        if (!isset(self::CLEAN_LEVELS[$level])) throw new HttpError(422, t('Unknown cleaning level.'));
        $info = @getimagesizefromstring($bytes);
        $same = $info && (($info[0] === (int)$from['width'] && $info[1] === (int)$from['height']) || ($info[0] === (int)$from['height'] && $info[1] === (int)$from['width']));
        if (!$same) throw new HttpError(422, t('The cleaned image must be the size of the original image.'));
        $label = self::cleanLabel($level);
        return $this->saveImage($bytes, ['title'=>$label,'prompt'=>'','original_prompt'=>'','options'=>null,'parent_ids'=>[$from['id']],'parent_id'=>$from['id'],
            'operation'=>'clean','label'=>$label,'clean'=>$level], $key);
    }

    /**
     * A part of an image cut out on the owner's device (the frame of Expandir kept inside the image), kept as a version
     * of that image. Nothing is sent to the image API, and the image it came from stays as it is. It is never larger
     * than that image (sides may be swapped: the browser applies the rotation a camera recorded) and never the same size.
     */
    public function croppedImage(string $bytes, string $parent, string $key): array
    {
        $from = $this->still($parent, $key);
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] < 16 || $info[1] < 16) throw new HttpError(422, t('Invalid crop.'));
        [$w, $h, $fw, $fh] = [$info[0], $info[1], (int)$from['width'], (int)$from['height']];
        $fits = ($w <= $fw && $h <= $fh && $w * $h < $fw * $fh) || ($w <= $fh && $h <= $fw && $w * $h < $fw * $fh);
        if (!$fits) throw new HttpError(422, t('The crop must be a part of the original image.'));
        $label = t('Crop to {width} × {height}', ['width'=>$w, 'height'=>$h]);
        return $this->saveImage($bytes, ['title'=>$label,'prompt'=>'','original_prompt'=>'','options'=>null,'parent_ids'=>[$from['id']],'parent_id'=>$from['id'],
            'operation'=>'crop','label'=>$label], $key);
    }

    /** Where a saved frame came from. A video that isn't in the library (or still uploading) is dropped, the position kept. */
    private function frameSource(array $source): array
    {
        $frame = $source['frame'] ?? null; $time = $source['time'] ?? null;
        if (!is_int($frame) || $frame < 0 || $frame > 100000000 || !(is_int($time) || is_float($time)) || $time < 0 || $time > 10000000) throw new HttpError(422, t('Invalid frame position.'));
        $video = $source['video_id'] ?? null;
        if (!is_string($video) || !preg_match('/\A[a-f0-9]{32}\z/D', $video)) $video = null;
        else {
            $q = $this->db->prepare('SELECT 1 FROM images WHERE id=? AND user_id=? UNION ALL SELECT 1 FROM uploads WHERE id=? AND user_id=?');
            $q->execute([$video, $this->owner(), $video, $this->owner()]);
            if (!$q->fetchColumn()) $video = null;
        }
        return ['video_id'=>$video, 'frame'=>$frame, 'time'=>round((float)$time, 6)];
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Videos. They arrive in pieces of a few megabytes, so no PHP or proxy upload limit caps their size, and are kept
    // encrypted in 1 MiB chunks sealed one by one: playback and seeking decrypt only the chunks a byte range needs.
    // The poster (a frame picked by the browser) is the item's "full" image and its thumbnail the gallery tile.
    // -----------------------------------------------------------------------------------------------------------------
    private function videoPath(string $id): string { self::validId($id); return "$this->dir/blobs/$id-video.enc"; }
    private function partPath(string $id): string { self::validId($id); return "$this->dir/blobs/$id-video.part"; }
    private static function chunkCount(int $size): int { return intdiv($size + self::VIDEO_CHUNK - 1, self::VIDEO_CHUNK); }
    /** The chunk's position and the total are authenticated, so chunks can't be reordered, dropped or mixed between videos. */
    private static function chunkContext(string $id, int $index, int $count): string { return "$id:video:$index/$count"; }
    private static function sealedSize(int $plain): int
    {
        $rest = $plain % self::VIDEO_CHUNK;
        return intdiv($plain, self::VIDEO_CHUNK) * (self::VIDEO_CHUNK + self::SEALED) + ($rest ? $rest + self::SEALED : 0);
    }
    /** Plaintext bytes an upload file holds: whole chunks only, unless the file is complete. A torn last write doesn't count. */
    private static function receivedBytes(int $fileSize, int $size): int
    { return $fileSize === self::sealedSize($size) ? $size : intdiv($fileSize, self::VIDEO_CHUNK + self::SEALED) * self::VIDEO_CHUNK; }

    /** Container of a video from its first bytes: MP4/MOV (ISO BMFF) or WebM/Matroska (EBML). */
    public static function videoType(string $head): ?string
    {
        if (str_starts_with($head, "\x1A\x45\xDF\xA3")) return 'video/webm';
        if (strlen($head) >= 8 && in_array(substr($head, 4, 4), ['ftyp','moov','mdat','free','skip','wide','pnot'], true)) return 'video/mp4';
        return null;
    }
    private static function megabytes(int $bytes): string { return I18n::number($bytes / 1048576).' MB'; }

    /** Starts the upload of a video of $size bytes. The id is also the one the video will have in the library. */
    public function startUpload(int $size, int $maxBytes): string
    {
        if ($maxBytes < 1) throw new HttpError(403, t('Video uploads are turned off on this server (max_video_mb in config.php).'));
        if ($size < 1) throw new HttpError(422, t('The video is empty.'));
        if ($size > $maxBytes) throw new HttpError(413, t('The video is over this server\'s limit of {maxBytes} (max_video_mb in config.php).', ['maxBytes'=>self::megabytes($maxBytes)]));
        $this->expireUploads();
        $free = function_exists('disk_free_space') ? @disk_free_space($this->dir) : false;
        if (is_float($free) && $free < self::sealedSize($size) + 64 * 1048576) throw new HttpError(507, t('The server is out of disk space for this video.'));
        $id = bin2hex(random_bytes(16));
        if (@file_put_contents($this->partPath($id), '', LOCK_EX) === false) throw new HttpError(503, t('[DATA-WRITE] Could not write the video to the private folder.'));
        $q = $this->db->prepare('INSERT INTO uploads(id,user_id,size,created,updated) VALUES(?,?,?,?,?)');
        $q->execute([$id, $this->owner(), $size, time(), time()]);
        return $id;
    }

    private function upload(string $id): array
    {
        self::validId($id);
        $q = $this->db->prepare('SELECT * FROM uploads WHERE id=? AND user_id=?'); $q->execute([$id, $this->owner()]);
        $r = $q->fetch(); if (!$r) throw new HttpError(404, t('Upload not found. It may have expired: upload the video again.'));
        return $r;
    }

    /**
     * Stores the piece of an upload that starts at $offset and returns how many bytes the server now holds; the client
     * always continues from there. A piece already stored (a retry after a lost answer) is not written again, and one
     * that would leave a gap is ignored. Pieces are whole chunks, except the last one of the file.
     */
    public function appendUpload(string $id, int $offset, string $data, string $key): int
    {
        $size = (int)$this->upload($id)['size']; $count = self::chunkCount($size); $length = strlen($data);
        if ($offset < 0 || $offset % self::VIDEO_CHUNK || $length < 1 || $offset + $length > $size || ($offset + $length < $size && $length % self::VIDEO_CHUNK)) throw new HttpError(422, t('Invalid video piece.'));
        if ($offset === 0 && self::videoType(substr($data, 0, 16)) === null) throw new HttpError(415, t('Video format not supported. Use MP4, MOV or WebM.'));
        $handle = @fopen($this->partPath($id), 'c+b');
        if (!$handle) throw new HttpError(404, t('Upload not found.'));
        try {
            flock($handle, LOCK_EX);
            $received = self::receivedBytes((int)fstat($handle)['size'], $size);
            if ($offset > $received || $offset + $length <= $received) return $received;
            $skip = $received - $offset;
            ftruncate($handle, self::sealedSize($received)); fseek($handle, 0, SEEK_END);
            for ($at = $skip; $at < $length; $at += self::VIDEO_CHUNK) {
                $index = intdiv($offset + $at, self::VIDEO_CHUNK);
                $sealed = Crypto::seal(substr($data, $at, self::VIDEO_CHUNK), $key, self::chunkContext($id, $index, $count));
                if (fwrite($handle, $sealed) !== strlen($sealed)) { ftruncate($handle, self::sealedSize($received)); throw new HttpError(507, t('Could not write the video. The server\'s disk may be full.')); }
            }
            fflush($handle);
            $this->db->prepare('UPDATE uploads SET updated=? WHERE id=?')->execute([time(), $id]);
            return $offset + $length;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    /**
     * Turns a complete upload into a library item. $info carries what the browser read from the video (name, duration,
     * dimensions, frame rate); $poster is a frame of it as a data URL. Repeating the call after a lost answer returns the item.
     */
    public function finishUpload(string $id, array $info, string $poster, string $key): array
    {
        self::validId($id);
        $q = $this->db->prepare('SELECT 1 FROM images WHERE id=? AND user_id=?'); $q->execute([$id, $this->owner()]);
        if ($q->fetchColumn()) return $this->video($id, $key);
        $size = (int)$this->upload($id)['size']; $part = $this->partPath($id);
        clearstatcache(true, $part);
        if (self::receivedBytes((int)@filesize($part), $size) !== $size) throw new HttpError(409, t('The video has not fully reached the server yet.'));
        $handle = @fopen($part, 'rb');
        if (!$handle) throw new HttpError(404, t('Upload not found.'));
        try { $type = self::videoType(substr($this->readChunk($handle, $id, 0, $size, $key), 0, 16)); } finally { fclose($handle); }
        if ($type === null) throw new HttpError(415, t('Video format not supported. Use MP4, MOV or WebM.'));
        $image = OpenAI::imageInput($poster);
        $posterBytes = (string)base64_decode(substr($image['url'], strpos($image['url'], ',') + 1), true);
        $number = static fn(string $name, float $max) => is_numeric($info[$name] ?? null) && (float)$info[$name] > 0 && (float)$info[$name] <= $max ? (float)$info[$name] : null;
        $width = $number('width', 16384); $height = $number('height', 16384); $duration = $number('duration', 864000);
        if ($width === null || $height === null || $duration === null) throw new HttpError(422, t('The browser did not report the video\'s dimensions and duration.'));
        $fps = $number('fps', 1000); $frames = $number('frames', 100000000);
        $meta = ['title'=>self::cleanTitle((string)($info['name'] ?? ''), t('Video')),'prompt'=>'','original_prompt'=>'','options'=>null,'parent_ids'=>[],'parent_id'=>null,
            'operation'=>'video','label'=>null,'kind'=>'video','mime'=>$type,'size'=>$size,'width'=>(int)$width,'height'=>(int)$height,
            'duration'=>round($duration, 6),'fps'=>$fps === null ? null : round($fps, 6),'frames'=>$frames === null ? null : (int)$frames,
            'poster_mime'=>(string)getimagesizefromstring($posterBytes)['mime']];
        if (!@rename($part, $this->videoPath($id))) throw new HttpError(503, t('[DATA-WRITE] Could not finish the video in the private folder.'));
        try { $this->store($id, ['full'=>$posterBytes, 'thumb'=>$this->thumbnail($posterBytes)], $meta, $key); }
        catch (\Throwable $e) { @rename($this->videoPath($id), $part); throw $e; }
        $this->db->prepare('DELETE FROM uploads WHERE id=?')->execute([$id]);
        return $this->video($id, $key);
    }

    public function abortUpload(string $id): void
    {
        $this->upload($id);
        @unlink($this->partPath($id));
        $this->db->prepare('DELETE FROM uploads WHERE id=? AND user_id=?')->execute([$id, $this->owner()]);
    }

    private function expireUploads(): void
    {
        $q = $this->db->prepare('SELECT id FROM uploads WHERE updated<?'); $q->execute([time() - self::UPLOAD_TTL]);
        foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            @unlink($this->partPath((string)$id));
            $this->db->prepare('DELETE FROM uploads WHERE id=?')->execute([$id]);
        }
    }

    /** A video of the account, trashed ones included (the trash can still play them). */
    public function video(string $id, string $key): array
    {
        $item = $this->image($id, $key, true);
        if (($item['kind'] ?? 'image') !== 'video' || !is_file($this->videoPath($id))) throw new HttpError(404, t('Video not found.'));
        return $item;
    }

    /** Sends bytes $first to $last of a video, decrypting only the chunks that hold them. $write returns false to stop early. */
    public function streamVideo(array $item, int $first, int $last, string $key, callable $write): void
    {
        $size = (int)$item['size'];
        $handle = @fopen($this->videoPath((string)$item['id']), 'rb');
        if (!$handle) throw new HttpError(404, t('Video not found.'));
        try {
            for ($index = intdiv($first, self::VIDEO_CHUNK), $end = intdiv($last, self::VIDEO_CHUNK); $index <= $end; $index++) {
                $plain = $this->readChunk($handle, (string)$item['id'], $index, $size, $key);
                $start = $index * self::VIDEO_CHUNK;
                $from = max($first, $start) - $start; $to = min($last, $start + strlen($plain) - 1) - $start;
                if ($write(substr($plain, $from, $to - $from + 1)) === false) return;
            }
        } finally { fclose($handle); }
    }

    /** @param resource $handle */
    private function readChunk($handle, string $id, int $index, int $size, string $key): string
    {
        $count = self::chunkCount($size);
        $length = ($index === $count - 1 ? $size - $index * self::VIDEO_CHUNK : self::VIDEO_CHUNK) + self::SEALED;
        if ($index < 0 || $index >= $count || fseek($handle, $index * (self::VIDEO_CHUNK + self::SEALED)) !== 0) throw new \RuntimeException(t('Invalid video range.'));
        $sealed = stream_get_contents($handle, $length);
        if (!is_string($sealed) || strlen($sealed) !== $length) throw new \RuntimeException(t('Incomplete video file.'));
        return Crypto::open($sealed, $key, self::chunkContext($id, $index, $count));
    }

    /** The frames saved from a video, in the order they appear in it. */
    public function frames(string $videoId, string $key): array
    {
        self::validId($videoId);
        $all = $this->catalog($key); $versions = [];
        foreach ($all as $it) if ($it['deleted'] === null) $versions[$it['root']] = ($versions[$it['root']] ?? 0) + 1;
        $frames = array_values(array_filter($all, fn($it) => $it['deleted'] === null && ($it['source']['video_id'] ?? null) === $videoId));
        usort($frames, fn($a, $b) => [$a['source']['time'] ?? 0, $a['created']] <=> [$b['source']['time'] ?? 0, $b['created']]);
        return array_map(fn($it) => $this->summary($it, $versions[$it['root']] ?? 0), $frames);
    }

    /** An image the image API can take. Videos are refused: a frame of one can be saved as an image instead. */
    public function still(string $id, string $key): array
    {
        $item = $this->image($id, $key);
        if (($item['kind'] ?? 'image') === 'video') throw new HttpError(422, t('This item is a video. Save one of its frames as an image to edit it, upscale it or use it as a reference.'));
        return $item;
    }

    private function thumbnail(string $bytes): string
    {
        $src = @imagecreatefromstring($bytes);
        if (!$src) throw new \RuntimeException(t('Could not create the thumbnail.'));
        $w = imagesx($src); $h = imagesy($src); $scale = min(1, 480 / max($w,$h));
        $thumb = imagecreatetruecolor(max(1,(int)round($w*$scale)),max(1,(int)round($h*$scale)));
        imagealphablending($thumb,false); imagesavealpha($thumb,true);
        imagecopyresampled($thumb,$src,0,0,0,0,imagesx($thumb),imagesy($thumb),$w,$h);
        ob_start(); imagewebp($thumb,null,78); $data = ob_get_clean();
        imagedestroy($src); imagedestroy($thumb);
        if (!is_string($data) || $data==='') throw new \RuntimeException(t('Could not create the WebP thumbnail.'));
        return $data;
    }

    private function decode(array $r, string $key): array
    {
        $id = (string)$r['id'];
        return array_merge(json_decode(Crypto::open($r['meta'],$key,"$id:meta"),true,512,JSON_THROW_ON_ERROR), ['id'=>$id,'created'=>(int)$r['created'],'favorite'=>(bool)$r['favorite'],'deleted'=>$r['deleted']===null?null:(int)$r['deleted'],
            'position'=>isset($r['position']) ? (float)$r['position'] : null, 'cover'=>!empty($r['cover']), 'hidden'=>!empty($r['hidden']),
            'stack'=>isset($r['stack']) && is_string($r['stack']) && $r['stack'] !== '' ? $r['stack'] : null]);
    }

    public function image(string $id, string $key, bool $includeDeleted=false): array
    {
        self::validId($id);
        $q = $this->db->prepare('SELECT * FROM images WHERE id=? AND user_id=?' . ($includeDeleted?'':' AND deleted IS NULL')); $q->execute([$id, $this->owner()]);
        $r = $q->fetch(); if (!$r) throw new HttpError(404,t('Image not found.'));
        return $this->decode($r, $key);
    }

    /** An item for the viewer. A frame whose video is in the trash or was deleted no longer links back to it. */
    public function detail(string $id, string $key): array
    {
        $item = $this->image($id, $key);
        if (is_string($item['source']['video_id'] ?? null)) {
            $q = $this->db->prepare('SELECT 1 FROM images WHERE id=? AND user_id=? AND deleted IS NULL'); $q->execute([$item['source']['video_id'], $this->owner()]);
            if (!$q->fetchColumn()) $item['source']['video_id'] = null;
        }
        return $item;
    }

    public function bytes(string $id, string $variant, string $key, bool $includeDeleted=false): string
    {
        $this->image($id,$key,$includeDeleted);
        if (!in_array($variant,['full','thumb'],true)) throw new HttpError(400,t('Invalid variant.'));
        $data = @file_get_contents("$this->dir/blobs/$id-$variant.enc");
        if ($data===false) throw new HttpError(404,t('File not found.'));
        return Crypto::open($data,$key,"$id:$variant");
    }

    /** The image an edit was made from. Older edits only recorded their references; the first one was the edited image. */
    public static function lineageParent(array $meta): ?string
    {
        if (array_key_exists('parent_id', $meta)) return is_string($meta['parent_id']) ? $meta['parent_id'] : null;
        $first = $meta['parent_ids'][0] ?? null;
        return is_string($first) ? $first : null;
    }

    public static function operation(array $meta): string
    {
        if (isset($meta['operation']) && is_string($meta['operation'])) return $meta['operation'];
        // Expansions from before the operation was saved began with the expansion prompt, then in Portuguese.
        if (str_starts_with((string)($meta['prompt'] ?? ''), 'Expanda a imagem preenchendo')) return 'expand';
        return self::lineageParent($meta) === null ? 'generate' : 'edit';
    }

    /**
     * Every image, newest first, with its lineage parent and root resolved, and its pile: what shows as one tile when the
     * library groups versions. That is the stack the owner grouped it into (a whole lineage joins a stack, so a version
     * made later is in it too), else its lineage ("s:<stack id>" or the root's id).
     * Metadata is encrypted, so the structure is only computed inside an unlocked session.
     */
    private function catalog(string $key): array
    {
        $items = [];
        $q = $this->db->prepare('SELECT * FROM images WHERE user_id=? ORDER BY created DESC, rowid DESC'); $q->execute([$this->owner()]);
        foreach ($q as $r) $items[(string)$r['id']] = $this->decode($r, $key);
        $parent = [];
        foreach ($items as $id => $it) { $p = self::lineageParent($it); $parent[$id] = ($p !== null && $p !== $id && isset($items[$p])) ? $p : null; }
        $root = [];
        foreach ($items as $id => $_) {
            $path = []; $cur = $id;
            while (!isset($root[$cur]) && $parent[$cur] !== null && !isset($path[$cur])) { $path[$cur] = true; $cur = $parent[$cur]; }
            $r = $root[$cur] ?? $cur;
            foreach ($path as $node => $_) $root[$node] = $r;
            $root[$cur] ??= $r;
        }
        $stackOf = [];
        foreach ($items as $id => $it) if ($it['stack'] !== null) $stackOf[$root[$id]] ??= $it['stack'];
        foreach ($items as $id => &$it) {
            $it['parent'] = $parent[$id]; $it['root'] = $root[$id];
            $it['stack'] = $stackOf[$root[$id]] ?? null;
            $it['pile'] = $it['stack'] !== null ? 's:'.$it['stack'] : $root[$id];
        }
        unset($it);
        return $items;
    }

    /** Compact listing entry: the viewer loads the full metadata separately. */
    private function summary(array $it, int $versions): array
    {
        $video = ($it['kind'] ?? 'image') === 'video';
        return ['id'=>$it['id'],'title'=>(string)($it['title'] ?? ''),'prompt'=>mb_substr((string)($it['prompt'] ?? ''),0,300),'label'=>$it['label'] ?? null,
            'width'=>(int)$it['width'],'height'=>(int)$it['height'],'created'=>$it['created'],'favorite'=>$it['favorite'],'deleted'=>$it['deleted'],
            'operation'=>self::operation($it),'parent'=>$it['parent'],'root'=>$it['root'],'versions'=>$versions,'model'=>$it['options']['model'] ?? null,
            'kind'=>$video ? 'video' : 'image','hidden'=>$it['hidden'],'stack'=>$it['stack']]
            + ($video ? ['duration'=>(float)($it['duration'] ?? 0)] : [])
            + (isset($it['source']) ? ['source'=>$it['source']] : []);
    }

    /** Orders a library view can have. "custom" is the order the owner arranged by hand; new items start at its top. */
    public const SORTS = ['newest','oldest','custom','largest','smallest','wide','tall','versions'];
    /** Where items came from: made by the image API, brought from the device (images and saved video frames), or videos. */
    public const ORIGINS = ['generated','imported','video'];

    public static function origin(array $meta): string
    {
        if (($meta['kind'] ?? 'image') === 'video') return 'video';
        return in_array(self::operation($meta), ['import','frame'], true) ? 'imported' : 'generated';
    }

    /**
     * The items of a library view in display order, and how many items each pile (a lineage, or a stack the owner made)
     * has. With `group`, each pile shows once, as its newest matching item (or its chosen cover); the sort then places
     * that item. Favorites and albums are items picked one by one, so there every one of them is listed.
     * `hidden`: false leaves hidden items out (the library), true lists only them (the Ocultas filter), null both (the
     * reference picker and the MCP see every item). The trash lists what is in it, hidden or not.
     * @param array{search?:string,favorites?:bool,trash?:bool,kind?:?string,origin?:?string,album?:?string,group?:bool,sort?:string,hidden?:?bool} $view
     * @return array{0:list<array>,1:array<string,int>}
     */
    private function view(string $key, array $view): array
    {
        $members = isset($view['album']) ? $this->albumMembers((string)$view['album']) : null;
        $all = $this->catalog($key);
        $versions = [];
        foreach ($all as $it) if ($it['deleted'] === null) $versions[$it['pile']] = ($versions[$it['pile']] ?? 0) + 1;
        $search = (string)($view['search'] ?? ''); $trash = !empty($view['trash']); $favorites = !empty($view['favorites']);
        $kind = $view['kind'] ?? null; $origin = $view['origin'] ?? null; $hidden = $trash ? null : ($view['hidden'] ?? null);
        $group = !empty($view['group']) && !$trash && !$favorites && $members === null;
        $keep = static function (array $it) use ($trash, $favorites, $kind, $origin, $members, $search, $hidden): bool {
            if (($it['deleted'] !== null) !== $trash) return false;
            if ($hidden !== null && $it['hidden'] !== $hidden) return false;
            if ($favorites && !$it['favorite']) return false;
            if ($kind !== null && (($it['kind'] ?? 'image') === 'video' ? 'video' : 'image') !== $kind) return false;
            if ($origin !== null && self::origin($it) !== $origin) return false;
            if ($members !== null && !isset($members[$it['id']])) return false;
            // The search looks at what was asked for (the prompt, as typed and as sent) and at labels such as
            // "Fotorrealista"; titles are numbers the generator gave and are left out.
            return $search === '' || mb_stripos(($it['prompt'] ?? '')."\n".($it['original_prompt'] ?? '')."\n".($it['label'] ?? ''), $search) !== false;
        };
        // Grouped, each pile shows its newest item unless one of its items was chosen as the cover. The cover takes the
        // newest item's date, so the pile keeps its place in the date orders.
        $covers = [];
        if ($group) foreach ($all as $it) if ($it['cover'] && $it['deleted'] === null) $covers[$it['pile']] = $it['id'];
        $matched = []; $seen = [];
        foreach ($all as $it) {
            if (!$keep($it)) continue;
            if ($group) {
                if (isset($seen[$it['pile']])) continue;
                $seen[$it['pile']] = true;
                $cover = $covers[$it['pile']] ?? null;
                if ($cover !== null && $cover !== $it['id'] && $keep($all[$cover])) $it = ['created' => $it['created']] + $all[$cover];
            }
            $matched[] = $it;
        }
        // The catalog comes newest first; PHP's sort is stable, so equal keys keep that order.
        $area = static fn(array $it) => (int)$it['width'] * (int)$it['height'];
        $order = static fn(array $it) => $it['position'] ?? (float)$it['created'];
        switch ($view['sort'] ?? 'newest') {
            case 'oldest': $matched = array_reverse($matched); break;
            case 'custom': usort($matched, fn($a, $b) => $order($b) <=> $order($a)); break;
            case 'largest': usort($matched, fn($a, $b) => $area($b) <=> $area($a)); break;
            case 'smallest': usort($matched, fn($a, $b) => $area($a) <=> $area($b)); break;
            // Width over height compared without division: wider first, or taller first.
            case 'wide': usort($matched, fn($a, $b) => (int)$b['width'] * (int)$a['height'] <=> (int)$a['width'] * (int)$b['height']); break;
            case 'tall': usort($matched, fn($a, $b) => (int)$a['width'] * (int)$b['height'] <=> (int)$b['width'] * (int)$a['height']); break;
            case 'versions': usort($matched, fn($a, $b) => ($versions[$b['pile']] ?? 0) <=> ($versions[$a['pile']] ?? 0)); break;
        }
        return [$matched, $versions];
    }

    /** Comparable form of a name (albums): case, accents and leading punctuation don't count; numbers compare by value where possible. */
    private static function sortKey(string $title): string
    {
        static $collator = false;
        $title = self::stripLeading($title);
        if ($collator === false) {
            $collator = class_exists(\Collator::class) ? new \Collator('pt_BR') : null;
            $collator?->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
            $collator?->setStrength(\Collator::SECONDARY);
        }
        if ($collator instanceof \Collator) {
            $key = $collator->getSortKey($title);
            if (is_string($key)) return $title === '' ? '' : $key;
        }
        return self::fold($title);
    }

    /** Quotes, brackets and punctuation at the start of a name don't count for its place in the alphabet. */
    private static function stripLeading(string $title): string
    { return preg_replace('/\A[\s"\'“”‘’«»()\[\]{}\-–—_.,;:!?¿¡#*]+/u', '', $title) ?? $title; }

    /** Lowercase without accents, numbers padded to compare by value: the sort key where the intl extension is missing. */
    private static function fold(string $text): string
    {
        $text = mb_strtolower($text);
        $text = class_exists(\Normalizer::class) ? (preg_replace('/\p{Mn}+/u', '', (string)\Normalizer::normalize($text, \Normalizer::FORM_D)) ?? $text)
            : strtr($text, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
                'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n']);
        return preg_replace_callback('/\d+/', fn($m) => str_pad(ltrim($m[0], '0'), 24, '0', STR_PAD_LEFT), $text) ?? $text;
    }

    /**
     * @param bool $group show one entry per lineage (its newest matching version), placed by its latest activity.
     * @param ?string $kind 'image' or 'video' to list only those; null lists both.
     */
    public function gallery(string $key, string $search, bool $favorites, bool $trash, int $offset, int $limit=30, bool $group=false, ?string $kind=null): array
    {
        [$matched, $versions] = $this->view($key, ['search'=>$search,'favorites'=>$favorites,'trash'=>$trash,'group'=>$group,'kind'=>$kind]);
        $limit = max(1, min(300, $limit));
        $items = array_map(fn($it) => $this->summary($it, $versions[$it['pile']] ?? 0), array_slice($matched, $offset, $limit));
        return ['items'=>$items,'total'=>count($matched),'has_more'=>$offset + count($items) < count($matched)];
    }

    /**
     * The whole of a library view as compact rows, enough to lay out and scroll through every tile at once:
     * [id, created, width, height, flags, versions, duration]. Flags: 1 favorite, 2 video, 4 imported
     * (from the device or a video frame), 8 video frame, 16 in a stack, 32 hidden. Versions: how many items its pile
     * has. Prompts stay out: tiles ask for theirs as they come into view (captions()). Hidden items stay out of the
     * library unless the view asks for them (`hidden` true: only those).
     */
    public function library(string $key, array $view): array
    {
        [$matched, $versions] = $this->view($key, $view + ['hidden'=>false]);
        $rows = [];
        foreach ($matched as $it) {
            $video = ($it['kind'] ?? 'image') === 'video'; $operation = self::operation($it);
            $rows[] = [$it['id'], $it['created'], (int)$it['width'], (int)$it['height'],
                ($it['favorite'] ? 1 : 0) | ($video ? 2 : 0) | (in_array($operation, ['import','frame'], true) ? 4 : 0) | ($operation === 'frame' ? 8 : 0)
                    | ($it['stack'] !== null ? 16 : 0) | ($it['hidden'] ? 32 : 0),
                $versions[$it['pile']] ?? 0, $video ? round((float)($it['duration'] ?? 0), 3) : 0];
        }
        return ['items'=>$rows, 'total'=>count($rows)];
    }

    /** Prompt, label and kind of operation of some items, for the tiles on screen. Unknown ids are left out. */
    public function captions(array $ids, string $key): array
    {
        $out = [];
        foreach (array_chunk(self::idList($ids, 200), 200) as $chunk) {
            $q = $this->db->prepare('SELECT * FROM images WHERE user_id=? AND id IN ('.implode(',', array_fill(0, count($chunk), '?')).')');
            $q->execute([$this->owner(), ...$chunk]);
            foreach ($q as $r) {
                $it = $this->decode($r, $key);
                $out[] = ['id'=>$it['id'],'prompt'=>mb_substr((string)($it['prompt'] ?? ''), 0, 300),'label'=>$it['label'] ?? null,
                    'operation'=>self::operation($it),'model'=>$it['options']['model'] ?? null];
            }
        }
        return $out;
    }

    /**
     * A list of item ids from a request: each one valid, no repeats, order kept.
     * @return list<string>
     */
    private static function idList(mixed $ids, int $max = 10000): array
    {
        if (!is_array($ids) || count($ids) > $max) throw new HttpError(422, t('Invalid list of items.'));
        $out = [];
        foreach ($ids as $id) {
            if (!is_string($id)) throw new HttpError(422, t('Invalid list of items.'));
            self::validId($id); $out[$id] = true;
        }
        return array_keys($out);
    }

    /** Runs $sql once per slice of $ids, with the ids in place of %s after the leading parameters. Returns the rows changed. */
    private function eachSlice(string $sql, array $ids, array $leading = []): int
    {
        $changed = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $q = $this->db->prepare(sprintf($sql, implode(',', array_fill(0, count($chunk), '?'))));
            $q->execute([...$leading, ...$chunk]); $changed += $q->rowCount();
        }
        return $changed;
    }

    /**
     * One action on many items of the account: favorite, unfavorite, trash, restore, purge (trashed ones only),
     * album-add or album-remove (with $album). Items of other accounts are ignored. Returns how many items changed.
     */
    public function bulk(string $op, mixed $ids, ?string $album = null): int
    {
        $ids = self::idList($ids);
        if (!$ids) return 0;
        if (in_array($op, ['album-add','album-remove'], true)) $this->album((string)$album);
        $owner = $this->owner();
        if ($op === 'purge') return $this->purgeMany($ids);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $changed = match ($op) {
                'favorite' => $this->eachSlice('UPDATE images SET favorite=1 WHERE user_id=? AND favorite=0 AND id IN (%s)', $ids, [$owner]),
                'unfavorite' => $this->eachSlice('UPDATE images SET favorite=0 WHERE user_id=? AND favorite=1 AND id IN (%s)', $ids, [$owner]),
                'trash' => $this->eachSlice('UPDATE images SET deleted=? WHERE user_id=? AND deleted IS NULL AND id IN (%s)', $ids, [time(), $owner]),
                'restore' => $this->eachSlice('UPDATE images SET deleted=NULL WHERE user_id=? AND deleted IS NOT NULL AND id IN (%s)', $ids, [$owner]),
                'album-add' => $this->eachSlice('INSERT OR IGNORE INTO album_items(album_id,image_id,added) SELECT ?, id, ? FROM images WHERE user_id=? AND id IN (%s)', $ids, [$album, time(), $owner]),
                'album-remove' => $this->eachSlice('DELETE FROM album_items WHERE album_id=? AND image_id IN (%s)', $ids, [$album]),
                default => throw new HttpError(422, t('Invalid action.')),
            };
            $this->db->exec('COMMIT');
            return $changed;
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }

    /**
     * Moves items in the custom order: right before $before (null: to the end), in the order given. Items are then
     * numbered from the top; new items get no number, so they come first (their creation time is above any number).
     */
    public function reorder(mixed $ids, ?string $before): void
    {
        $ids = self::idList($ids);
        if ($before !== null) self::validId($before);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q = $this->db->prepare('SELECT id, position, created FROM images WHERE user_id=? ORDER BY created DESC, rowid DESC'); $q->execute([$this->owner()]);
            $rows = $q->fetchAll();
            usort($rows, fn($a, $b) => ($b['position'] ?? (float)$b['created']) <=> ($a['position'] ?? (float)$a['created']));
            $current = array_column($rows, 'position', 'id');
            $moving = array_values(array_filter($ids, fn($id) => array_key_exists($id, $current)));
            $movingSet = array_flip($moving);
            $order = array_values(array_filter(array_keys($current), fn($id) => !isset($movingSet[$id])));
            // A moved item can't be its own anchor: the block goes before the next item that stays.
            if ($before !== null && isset($movingSet[$before])) {
                $all = array_keys($current); $at = array_search($before, $all, true);
                $before = null;
                for ($i = $at + 1; $i < count($all); $i++) if (!isset($movingSet[$all[$i]])) { $before = $all[$i]; break; }
            }
            $at = $before === null ? false : array_search($before, $order, true);
            if ($before !== null && $at === false) throw new HttpError(404, t('Target item not found.'));
            array_splice($order, $at === false ? count($order) : $at, 0, $moving);
            $update = $this->db->prepare('UPDATE images SET position=? WHERE id=? AND user_id=?');
            $n = count($order);
            foreach ($order as $i => $id) {
                $position = (float)($n - $i);
                if ($current[$id] === null || (float)$current[$id] !== $position) $update->execute([$position, $id, $this->owner()]);
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Albums. An album holds items of one account; its name is encrypted like every other text. Removing an item from
    // an album, or the album itself, leaves the item in the library. Purging an item takes it out of its albums.
    // -----------------------------------------------------------------------------------------------------------------
    private function album(string $id): array
    {
        self::validId($id);
        $q = $this->db->prepare('SELECT * FROM albums WHERE id=? AND user_id=?'); $q->execute([$id, $this->owner()]);
        $r = $q->fetch(); if (!$r) throw new HttpError(404, t('Album not found.'));
        return $r;
    }

    /** @return array<string,true> */
    private function albumMembers(string $id): array
    {
        $this->album($id);
        $q = $this->db->prepare('SELECT image_id FROM album_items WHERE album_id=?'); $q->execute([$id]);
        return array_fill_keys($q->fetchAll(\PDO::FETCH_COLUMN), true);
    }

    private static function albumName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '') ?? '');
        if ($name === '' || mb_strlen($name) > 80) throw new HttpError(422, t('Use an album name of 1 to 80 characters.'));
        return $name;
    }

    /** The account's albums by name, each with how many items it shows (not trashed or hidden) and its newest one as cover. */
    public function albums(string $key): array
    {
        $q = $this->db->prepare('SELECT a.id, a.meta, a.created,
            (SELECT COUNT(*) FROM album_items ai JOIN images i ON i.id=ai.image_id WHERE ai.album_id=a.id AND i.deleted IS NULL AND i.hidden=0) AS count,
            (SELECT i.id FROM album_items ai JOIN images i ON i.id=ai.image_id WHERE ai.album_id=a.id AND i.deleted IS NULL AND i.hidden=0 ORDER BY i.created DESC, i.rowid DESC LIMIT 1) AS cover
            FROM albums a WHERE a.user_id=?');
        $q->execute([$this->owner()]);
        $albums = [];
        foreach ($q as $r) {
            $meta = json_decode(Crypto::open((string)$r['meta'], $key, 'album:'.$r['id']), true, 16, JSON_THROW_ON_ERROR);
            $albums[] = ['id'=>(string)$r['id'],'name'=>(string)$meta['name'],'count'=>(int)$r['count'],'cover'=>$r['cover'],'created'=>(int)$r['created']];
        }
        $keys = array_combine(array_column($albums, 'id'), array_map(fn($a) => self::sortKey($a['name']), $albums));
        usort($albums, fn($a, $b) => $keys[$a['id']] <=> $keys[$b['id']]);
        return $albums;
    }

    private function sealAlbum(string $id, string $name, string $key): string
    { return Crypto::seal(json_encode(['name'=>$name], JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE), $key, "album:$id"); }

    public function createAlbum(string $name, string $key): array
    {
        $name = self::albumName($name); $id = bin2hex(random_bytes(16));
        $q = $this->db->prepare('INSERT INTO albums(id,user_id,meta,created) VALUES(?,?,CAST(? AS BLOB),?)');
        $q->execute([$id, $this->owner(), $this->sealAlbum($id, $name, $key), time()]);
        return ['id'=>$id,'name'=>$name,'count'=>0,'cover'=>null,'created'=>time()];
    }

    public function hasAlbum(string $id): bool
    {
        try { $this->album($id); return true; } catch (HttpError) { return false; }
    }

    public function renameAlbum(string $id, string $name, string $key): void
    {
        $this->album($id); $name = self::albumName($name);
        $this->db->prepare('UPDATE albums SET meta=CAST(? AS BLOB) WHERE id=? AND user_id=?')->execute([$this->sealAlbum($id, $name, $key), $id, $this->owner()]);
    }

    public function deleteAlbum(string $id): void
    {
        $this->album($id);
        $this->db->prepare('DELETE FROM albums WHERE id=? AND user_id=?')->execute([$id, $this->owner()]);
    }

    /**
     * All versions that share the image's root, oldest first, or, for an item in a stack, every item of the stack (each
     * lineage in it is a tree of its own). Trashed versions stay only to connect visible ones; hidden ones are listed.
     */
    public function lineage(string $id, string $key): array
    {
        self::validId($id);
        $all = $this->catalog($key);
        if (!isset($all[$id]) || $all[$id]['deleted'] !== null) throw new HttpError(404, t('Image not found.'));
        $root = $all[$id]['root']; $pile = $all[$id]['pile'];
        $members = array_filter($all, fn($it) => $it['pile'] === $pile);
        $children = [];
        foreach ($members as $m) if ($m['parent'] !== null) $children[$m['parent']][] = $m['id'];
        // Newest first: children are always processed before their parents.
        $keep = [];
        foreach ($members as $m) {
            $k = $m['deleted'] === null;
            foreach ($children[$m['id']] ?? [] as $c) $k = $k || !empty($keep[$c]);
            $keep[$m['id']] = $k;
        }
        $nodes = [];
        foreach (array_reverse($members, true) as $m) if ($keep[$m['id']]) $nodes[] = $this->summary($m, 0) + ['cover'=>$m['cover'] && $m['deleted'] === null];
        return ['root'=>$root,'stack'=>$all[$id]['stack'],'nodes'=>$nodes];
    }

    /** Every item (trashed ones too) of the piles of $ids that are in the account. With $whole false, just $ids. */
    private function pileMembers(array $ids, string $key, bool $whole = true): array
    {
        if (!$whole) return $ids;
        $all = $this->catalog($key); $piles = [];
        foreach ($ids as $id) if (isset($all[$id])) $piles[$all[$id]['pile']] = true;
        return array_keys(array_filter($all, fn($it) => isset($piles[$it['pile']])));
    }

    /**
     * Hides items from the library (or shows them again): they leave every view but the Ocultas filter, the trash and
     * the reference picker. With $whole, a pile goes together: hiding the tile of an image with versions, or of a stack,
     * hides every item in it. Returns how many items changed.
     */
    public function hide(mixed $ids, bool $hidden, bool $whole, string $key): int
    {
        $ids = self::idList($ids);
        if (!$ids) return 0;
        $ids = $this->pileMembers($ids, $key, $whole);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $changed = $this->eachSlice('UPDATE images SET hidden=? WHERE user_id=? AND hidden<>? AND id IN (%s)', $ids, [(int)$hidden, $this->owner(), (int)$hidden]);
            $this->db->exec('COMMIT');
            return $changed;
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }

    /**
     * Groups the piles of $ids (images with their versions, videos, stacks made before) into one stack, which the library
     * shows as one tile, like the versions of an image. A stack among them keeps its id and takes the others in; the
     * first chosen pile that has a cover keeps it. Returns the stack and how many items it holds.
     * @return array{stack:string,count:int}
     */
    public function stack(mixed $ids, string $key): array
    {
        $ids = self::idList($ids);
        $all = $this->catalog($key); $piles = [];
        foreach ($ids as $id) if (isset($all[$id]) && $all[$id]['deleted'] === null) $piles[$all[$id]['pile']] = true;
        if (count($piles) < 2) throw new HttpError(422, t('Choose at least two items that are not already in the same stack.'));
        $stack = null;
        foreach (array_keys($piles) as $pile) if (str_starts_with($pile, 's:')) { $stack = substr($pile, 2); break; }
        $stack ??= bin2hex(random_bytes(16));
        $members = array_filter($all, fn($it) => isset($piles[$it['pile']]));
        $covers = [];
        foreach ($members as $it) if ($it['cover'] && $it['deleted'] === null) $covers[$it['pile']] ??= $it['id'];
        $cover = null;
        foreach (array_keys($piles) as $pile) if (isset($covers[$pile])) { $cover = $covers[$pile]; break; }
        $owner = $this->owner();
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->eachSlice('UPDATE images SET stack=? WHERE user_id=? AND id IN (%s)', array_keys($members), [$stack, $owner]);
            $others = array_values(array_diff(array_keys($covers), [$cover]));
            if ($others) $this->eachSlice('UPDATE images SET cover=0 WHERE user_id=? AND cover<>0 AND id IN (%s)', $others, [$owner]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
        return ['stack'=>$stack, 'count'=>count(array_filter($members, fn($it) => $it['deleted'] === null))];
    }

    /**
     * Takes items out of their stacks. $whole undoes the stacks of $ids altogether; otherwise only the image of each id
     * (with all its versions) leaves, and a stack left with one image is undone too. Returns how many items left a stack.
     */
    public function unstack(mixed $ids, bool $whole, string $key): int
    {
        $ids = self::idList($ids);
        $all = $this->catalog($key);
        $stacks = []; $roots = [];
        foreach ($ids as $id) if (isset($all[$id]) && $all[$id]['stack'] !== null) { $stacks[$all[$id]['stack']] = true; $roots[$all[$id]['root']] = true; }
        if (!$stacks) return 0;
        $leaving = [];
        foreach ($all as $it) {
            if ($it['stack'] === null || !isset($stacks[$it['stack']])) continue;
            if ($whole || isset($roots[$it['root']])) $leaving[$it['id']] = true;
        }
        // A stack holds two images at least: the one left behind leaves with them.
        if (!$whole) {
            $left = [];
            foreach ($all as $it) if ($it['stack'] !== null && isset($stacks[$it['stack']]) && !isset($leaving[$it['id']])) $left[$it['stack']][$it['root']] = true;
            foreach ($all as $it) if ($it['stack'] !== null && isset($stacks[$it['stack']]) && count($left[$it['stack']] ?? []) < 2) $leaving[$it['id']] = true;
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->eachSlice('UPDATE images SET stack=NULL WHERE user_id=? AND id IN (%s)', array_keys($leaving), [$this->owner()]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
        return count(array_filter(array_keys($leaving), fn($id) => $all[$id]['deleted'] === null));
    }

    /**
     * Keeps what a request paid for, sealed and apart from its images, so the spending since the balance was entered
     * stays right when images are deleted: the tokens of the image request and of the planning calls made for it
     * (OpenAI::paid), or a step of the writer of a story (with what it cost, priced by Writer). A request that runs
     * again adds to what it already paid.
     * @param list<array{model:string,usage:mixed,kind?:string,effort?:?string,cost?:float}> $entries
     */
    public function recordSpend(string $jobId, array $entries, string $key): void
    {
        $now = time(); $new = [];
        foreach ($entries as $e) {
            if (!is_array($e['usage'] ?? null) || (string)($e['model'] ?? '') === '') continue;
            $new[] = ['model'=>(string)$e['model'], 'usage'=>$e['usage'], 'kind'=>(string)($e['kind'] ?? 'image'), 'effort'=>is_string($e['effort'] ?? null) ? $e['effort'] : null, 'at'=>$now]
                + (self::price($e['cost'] ?? null) !== null ? ['cost'=>self::price($e['cost'])] : []);
        }
        if (!$new) return;
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q = $this->db->prepare('SELECT created, data FROM spend WHERE job_id=? AND user_id=?'); $q->execute([$jobId, $this->owner()]);
            $row = $q->fetch(); $q->closeCursor();
            $old = $row ? self::spendEntries(json_decode(Crypto::open((string)$row['data'], $key, "spend:$jobId"), true), (int)$row['created']) : [];
            $this->db->prepare('INSERT OR REPLACE INTO spend(job_id,user_id,created,data) VALUES(?,?,?,CAST(? AS BLOB))')
                ->execute([$jobId, $this->owner(), $now, Crypto::seal((string)json_encode(['entries'=>array_merge($old, $new)]), $key, "spend:$jobId")]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }

    /** A cost in dollars as kept, or null for anything else. */
    private static function price(mixed $v): ?float
    { return (is_int($v) || is_float($v)) && is_finite((float)$v) && $v >= 0 && $v < 1e5 ? round((float)$v, 6) : null; }

    /** The entries of a spend row. Rows written before planning was counted hold one image request. */
    private static function spendEntries(mixed $data, int $created): array
    {
        if (!is_array($data)) return [];
        if (is_array($data['entries'] ?? null)) {
            $list = [];
            foreach ($data['entries'] as $e) {
                if (!is_array($e) || !is_array($e['usage'] ?? null)) continue;
                $list[] = ['model'=>(string)($e['model'] ?? ''), 'usage'=>$e['usage'], 'kind'=>(string)($e['kind'] ?? 'image'), 'effort'=>is_string($e['effort'] ?? null) ? $e['effort'] : null, 'at'=>(int)($e['at'] ?? $created)]
                    + (self::price($e['cost'] ?? null) !== null ? ['cost'=>self::price($e['cost'])] : []);
            }
            return $list;
        }
        return is_array($data['usage'] ?? null) ? [['model'=>(string)($data['model'] ?? ''), 'usage'=>$data['usage'], 'kind'=>'image', 'effort'=>null, 'at'=>$created]] : [];
    }

    /**
     * What the rows that match paid for, one entry per API call. A row written before planning was counted gets the
     * planning of its request from the images it made, which kept it, and is saved in the new shape.
     * @return list<array{model:string,usage:array,kind:string,effort:?string,at:int}>
     */
    private function spendWhere(string $key, string $where, array $args): array
    {
        $q = $this->db->prepare("SELECT job_id, created, data FROM spend WHERE user_id=? AND $where"); $q->execute([$this->owner(), ...$args]);
        $rows = $q->fetchAll(); $q->closeCursor();
        $entries = []; $planned = null;
        foreach ($rows as $r) {
            $id = (string)$r['job_id']; $created = (int)$r['created'];
            $data = json_decode(Crypto::open((string)$r['data'], $key, "spend:$id"), true);
            $list = self::spendEntries($data, $created);
            if (is_array($data) && !isset($data['entries'])) {
                $planned ??= $this->planningByRequest($key);
                foreach ($planned[$id] ?? [] as $e) $list[] = $e + ['at'=>$created];
                $this->db->prepare('UPDATE spend SET data=CAST(? AS BLOB) WHERE job_id=? AND user_id=?')
                    ->execute([Crypto::seal((string)json_encode(['entries'=>$list]), $key, "spend:$id"), $id, $this->owner()]);
            }
            array_push($entries, ...$list);
        }
        return $entries;
    }

    /** The planning calls each request paid for, as kept in the images it made (the bin included). */
    private function planningByRequest(string $key): array
    {
        $q = $this->db->prepare('SELECT * FROM images WHERE user_id=?'); $q->execute([$this->owner()]);
        $rows = $q->fetchAll(); $q->closeCursor();
        $map = [];
        foreach ($rows as $r) {
            $it = $this->decode($r, $key); $job = $it['job_id'] ?? null;
            if (!is_string($job) || isset($map[$job])) continue;
            $map[$job] = [];
            $effort = is_string($it['options']['thinking'] ?? null) ? $it['options']['thinking'] : null;
            if (is_array($it['planning_usage'] ?? null)) $map[$job][] = ['model'=>OpenAI::PLANNER, 'usage'=>$it['planning_usage'], 'kind'=>'planning', 'effort'=>$effort];
            if (is_array($it['variation_usage'] ?? null)) $map[$job][] = ['model'=>OpenAI::PLANNER, 'usage'=>$it['variation_usage'], 'kind'=>'variation', 'effort'=>'low'];
        }
        return $map;
    }

    /**
     * The accounts whose balance the app can follow, one per key: who is paid for each request is told by its model
     * (payer). OpenAI: GPT Image, the planning of its prompts, the GPT writer. Claude: the Claude writer. Gemini: Nano
     * Banana, the planning of its prompts, the Gemini writer.
     */
    public const ACCOUNTS = ['openai', 'claude', 'gemini'];

    public static function payer(string $model): string
    {
        if (str_starts_with($model, 'claude')) return 'claude';
        return str_starts_with($model, 'gemini') || Images::provider($model) === 'gemini' ? 'gemini' : 'openai';
    }

    /**
     * The balance the owner entered for an account (US$), when (since), and the monthly top-up (US$ on a day of the
     * month), or null. OpenAI's has a column of its own (it came first); the others are kept with the preferences.
     */
    public function billing(string $key, string $account = 'openai'): ?array
    {
        if ($account !== 'openai') { $b = $this->prefs($key)['billing'][$account] ?? null; return is_array($b) ? $b : null; }
        $q = $this->db->prepare('SELECT billing FROM users WHERE id=?'); $q->execute([$this->owner()]);
        $v = $q->fetchColumn(); if ($v === false || $v === null) return null;
        $billing = json_decode(Crypto::open((string)$v, $key, 'billing'), true);
        return is_array($billing) ? $billing : null;
    }

    /** Saves an account's balance as of now and the monthly top-up; null turns the balance off. */
    public function setBilling(?array $input, string $key, string $account = 'openai'): ?array
    {
        if (!in_array($account, self::ACCOUNTS, true)) throw new HttpError(422, t('Unknown service.'));
        $others = function (?array $billing) use ($account, $key): void {
            $all = $this->prefs($key)['billing'] ?? []; $all = is_array($all) ? $all : [];
            if ($billing === null) unset($all[$account]); else $all[$account] = $billing;
            $this->savePrefs('billing', $all, $key);
        };
        if ($input === null) {
            if ($account === 'openai') $this->db->prepare('UPDATE users SET billing=NULL WHERE id=?')->execute([$this->owner()]); else $others(null);
            return null;
        }
        $amount = static fn(mixed $v, float $min) => (is_int($v) || is_float($v)) && is_finite((float)$v) && $v >= $min && $v <= 1000000 ? round((float)$v, 2) : null;
        $balance = $amount($input['balance'] ?? null, -10000); $topup = $amount($input['topup'] ?? 0, 0); $day = $input['day'] ?? null;
        if ($balance === null) throw new HttpError(422, t('Enter the balance in dollars.'));
        if ($topup === null) throw new HttpError(422, t('Enter the top-up in dollars, or 0.'));
        if (!is_int($day) || $day < 1 || $day > 31) throw new HttpError(422, t('The top-up day goes from 1 to 31.'));
        // The factor on the spending that the browser learned from the last correction (assets/pricing.js), and what
        // the app showed when the amount was entered.
        $scale = $input['scale'] ?? 1; $shown = $input['shown'] ?? null;
        if (!(is_int($scale) || is_float($scale)) || $scale < 0.5 || $scale > 2) throw new HttpError(422, t('Invalid balance correction.'));
        if ($shown !== null && $amount($shown, -10000) === null) throw new HttpError(422, t('Invalid previous balance.'));
        $billing = ['balance'=>$balance, 'since'=>time(), 'topup'=>$topup, 'day'=>$day, 'scale'=>round((float)$scale, 4), 'shown'=>$shown === null ? null : $amount($shown, -10000)];
        if ($account !== 'openai') { $others($billing); return $billing; }
        $this->db->prepare('UPDATE users SET billing=CAST(? AS BLOB) WHERE id=?')->execute([Crypto::seal((string)json_encode($billing), $key, 'billing'), $this->owner()]);
        return $billing;
    }

    /**
     * The settings of the stories' reader (assets/reader.js): text size (percent), font, line spacing, width of the text,
     * alignment, theme, pages or scrolling, two pages side by side, the device's full screen, whether stories open
     * in the reader, and what the line at the foot of the text shows (the page or the part read, the time left). Kept
     * with the account, sealed with its key, so they follow it to any device.
     */
    public const READER = ['font'=>['serif','sans'], 'spacing'=>['tight','normal','wide'], 'width'=>['narrow','medium','wide','full'],
        'align'=>['left','justify'], 'theme'=>['dark','black','sepia','light'], 'mode'=>['pages','scroll']];
    public const READER_FLAGS = ['spread','device','open','progress','remaining'];

    /** The account's preferences, as one sealed document with a section per part of the app. */
    /** The model lists of the account's keys (Catalog::refresh), or null before the first. */
    public function modelLists(string $key): ?array
    { $m = $this->prefs($key)['models'] ?? null; return is_array($m) ? $m : null; }

    public function setModelLists(array $lists, string $key): void { $this->savePrefs('models', $lists, $key); }

    private function prefs(string $key): array
    {
        $q = $this->db->prepare('SELECT prefs FROM users WHERE id=?'); $q->execute([$this->owner()]);
        $v = $q->fetchColumn(); $q->closeCursor();
        if ($v === false || $v === null) return [];
        try { $prefs = json_decode(Crypto::open((string)$v, $key, 'prefs'), true); } catch (\Throwable) { return []; }
        return is_array($prefs) ? $prefs : [];
    }

    /** The reader's settings, or null when they were never changed (the reader then uses its defaults). */
    public function readerPrefs(string $key): ?array
    {
        $reader = $this->prefs($key)['reader'] ?? null;
        $reader = is_array($reader) ? self::readerSettings($reader) : [];
        return $reader ?: null;
    }

    /** Saves the reader's settings; a value the reader doesn't know is left out (the reader's default takes its place). */
    public function setReaderPrefs(mixed $input, string $key): array
    {
        if (!is_array($input)) throw new HttpError(422, t('Invalid reading preferences.'));
        $reader = self::readerSettings($input);
        $this->savePrefs('reader', $reader, $key);
        return $reader;
    }

    /** One section of the account's preferences replaced, the others as they are (read and written in one transaction). */
    private function savePrefs(string $section, array $value, string $key): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $prefs = $this->prefs($key); $prefs[$section] = $value;
            $this->db->prepare('UPDATE users SET prefs=CAST(? AS BLOB) WHERE id=?')->execute([Crypto::seal((string)json_encode($prefs), $key, 'prefs'), $this->owner()]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }

    /**
     * How the stories' chat shows the writer: "replies" true shows what it wrote there (its answers and its notes), which
     * can give the story away; hidden (the default), the chat keeps only its steps and whether it is done.
     */
    public const STORY_FLAGS = ['replies'];

    public function storyPrefs(string $key): array
    {
        $stories = $this->prefs($key)['stories'] ?? null;
        return is_array($stories) ? self::storySettings($stories) : [];
    }

    public function setStoryPrefs(mixed $input, string $key): array
    {
        if (!is_array($input)) throw new HttpError(422, t('Invalid story preferences.'));
        $stories = self::storySettings($input);
        $this->savePrefs('stories', $stories, $key);
        return $stories;
    }

    /**
     * What the person wrote about themselves as a reader (Configurações, Sobre mim como leitor), in their own words: the
     * writer reads it in every story, in the app and over MCP. Kept in the sealed preferences like the rest.
     */
    public const ABOUT_MAX = 2000;
    /** What an administrator's starts with until they change it: a few common wishes, as an example of what to write. */
    public const ABOUT_START = "- I like subtlety and can't stand formula.\n- I want your honest opinion.\n"
        ."- I read the chapters as they come out: no spoilers.\n- When you edit something, tell me which chapters changed.";

    public function readerAbout(string $key): string
    {
        $about = $this->prefs($key)['about'] ?? null;
        if (is_array($about) && is_string($about['text'] ?? null)) return $about['text'];
        $q = $this->db->prepare('SELECT admin FROM users WHERE id=?'); $q->execute([$this->owner()]);
        $admin = (bool)$q->fetchColumn(); $q->closeCursor();
        return $admin ? self::ABOUT_START : '';
    }

    /**
     * Saves it ('' clears it, and then nothing goes to the writer). With $notes, the writer's notes too, as the person
     * edited them in the same window.
     */
    public function setReaderAbout(mixed $text, string $key, mixed $notes = null): string
    {
        $text = self::aboutText($text);
        $notes = $notes !== null ? self::aboutText($notes) : null;
        $this->updatePrefs('about', fn(array $about) => ['text'=>$text]
            + ($notes !== null && $notes !== ($about['notes'] ?? '') ? ['notes'=>$notes, 'notes_by'=>'person', 'notes_story'=>null] : []) + $about, $key);
        return $text;
    }

    /**
     * The writer's own notes on the person as a reader (update_reader_notes), kept beside their words, which it can't
     * change: what it learned of their taste across their stories. by: who changed them last (agent, mcp or person), and
     * story: in which story the writer did.
     * @return array{text:string,by:?string,story:?string}
     */
    public function readerNotes(string $key): array
    {
        $about = $this->prefs($key)['about'] ?? null;
        return ['text'=>is_string($about['notes'] ?? null) ? $about['notes'] : '', 'by'=>$about['notes_by'] ?? null, 'story'=>$about['notes_story'] ?? null];
    }

    /** The writer changes its notes: the whole text, text added at the end, or exact replacements. */
    public function changeReaderNotes(array $change, string $by, ?string $story, string $key): string
    {
        $saved = '';
        $this->updatePrefs('about', function (array $about) use ($change, $by, $story, &$saved) {
            $notes = is_string($about['notes'] ?? null) ? $about['notes'] : '';
            if (isset($change['text'])) $notes = self::aboutText($change['text']);
            if (!empty($change['edits'])) $notes = Stories::applyEdits($notes, $change['edits'], t('The notes'));
            if (isset($change['append']) && trim((string)$change['append']) !== '') $notes = rtrim($notes)."\n".self::aboutText($change['append']);
            $saved = self::aboutText($notes);
            return ['notes'=>$saved, 'notes_by'=>$by, 'notes_story'=>$story] + $about;
        }, $key);
        return $saved;
    }

    private static function aboutText(mixed $text): string
    {
        if (!is_string($text)) throw new HttpError(422, t('Invalid text.'));
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $text = preg_replace('/[^\P{Cc}\n\t]/u', '', $text) ?? '';
        if (mb_strlen($text) > self::ABOUT_MAX) throw new HttpError(422, t('The text is over {max} characters.', ['max'=>I18n::number(self::ABOUT_MAX)]));
        return $text;
    }

    /** One section of the preferences changed from what it holds now, in one transaction with the reading. */
    private function updatePrefs(string $section, \Closure $change, string $key): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $prefs = $this->prefs($key);
            $prefs[$section] = $change(is_array($prefs[$section] ?? null) ? $prefs[$section] : []);
            $this->db->prepare('UPDATE users SET prefs=CAST(? AS BLOB) WHERE id=?')->execute([Crypto::seal((string)json_encode($prefs), $key, 'prefs'), $this->owner()]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }

    public static function storySettings(array $in): array
    {
        $out = [];
        foreach (self::STORY_FLAGS as $name) if (is_bool($in[$name] ?? null)) $out[$name] = $in[$name];
        return $out;
    }

    public static function readerSettings(array $in): array
    {
        $out = [];
        $size = $in['size'] ?? null;
        if ((is_int($size) || is_float($size)) && is_finite((float)$size)) $out['size'] = max(70, min(200, (int)round((float)$size)));
        foreach (self::READER as $name => $values) if (in_array($in[$name] ?? null, $values, true)) $out[$name] = $in[$name];
        foreach (self::READER_FLAGS as $name) if (is_bool($in[$name] ?? null)) $out[$name] = $in[$name];
        return $out;
    }

    /**
     * The balance settings and the tokens paid for since the balance was entered: one entry per model in the shape of
     * the API's usage, the planning model apart (planning: true). The browser prices them (assets/pricing.js) and adds
     * the top-ups. "planning" holds the tokens of the latest planning calls by effort ("variation" for the directions
     * of a varied batch), whatever the balance, for the estimate shown before a request is sent. "accounts" holds the
     * same for Claude and Gemini, whose spending is priced here: one entry per model and kind of call, with its cost.
     * @return array{billing:?array,spent:list<array>,planning:array<string,array>,accounts:array<string,array{billing:?array,spent:list<array>}>}
     */
    public function balance(string $key): array
    {
        $billing = $this->billing($key);
        $accounts = [];
        foreach (array_diff(self::ACCOUNTS, ['openai']) as $name) $accounts[$name] = ['billing'=>$this->billing($key, $name), 'spent'=>[]];
        $tokens = static function (array $usage): array {
            $details = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
            $image = (int)($details['image_tokens'] ?? 0);
            $text = isset($details['text_tokens']) ? (int)$details['text_tokens'] : max(0, (int)($usage['input_tokens'] ?? 0) - $image);
            return [$text, $image, (int)($details['cached_tokens'] ?? 0), (int)($usage['output_tokens'] ?? 0)];
        };
        // A planning call without the count of what went in and out can't be priced: it is left out.
        $counted = static fn(array $e): bool => $e['kind'] === 'image' || (isset($e['usage']['input_tokens']) && isset($e['usage']['output_tokens']));
        $spent = [];
        // Each account counts from the moment its balance was entered.
        $since = array_filter(['openai'=>$billing['since'] ?? null] + array_map(fn($a) => $a['billing']['since'] ?? null, $accounts), fn($v) => $v !== null);
        if ($since) foreach ($this->spendWhere($key, 'created>=?', [(int)min($since)]) as $e) {
            $payer = self::payer($e['model']);
            if (!isset($since[$payer]) || (int)$e['at'] < (int)$since[$payer]) continue;
            if ($payer !== 'openai') {
                $cost = self::spendCost($e); if ($cost === null) continue;
                $slot = $e['model'].':'.$e['kind'];
                $accounts[$payer]['spent'][$slot] ??= ['model'=>$e['model'], 'kind'=>$e['kind'], 'planning'=>!in_array($e['kind'], ['image', 'writing'], true), 'requests'=>0, 'cost'=>0.0];
                $accounts[$payer]['spent'][$slot]['requests']++;
                $accounts[$payer]['spent'][$slot]['cost'] += $cost;
                continue;
            }
            if (!$counted($e)) continue;
            // By model and kind of call: the image, its planning (the directions of a varied batch with it), the writer.
            $model = $e['model']; $planning = $e['kind'] !== 'image'; $kind = $e['kind'] === 'variation' ? 'planning' : $e['kind']; $slot = $model.':'.$kind;
            [$text, $image, $cached, $output] = $tokens($e['usage']);
            $spent[$slot] ??= ['model'=>$model, 'kind'=>$kind, 'planning'=>$planning, 'requests'=>0, 'input_tokens'=>0, 'output_tokens'=>0, 'input_tokens_details'=>['text_tokens'=>0, 'image_tokens'=>0, 'cached_tokens'=>0]];
            $spent[$slot]['requests']++;
            $spent[$slot]['input_tokens'] += $text + $image;
            $spent[$slot]['output_tokens'] += $output;
            $spent[$slot]['input_tokens_details']['text_tokens'] += $text;
            $spent[$slot]['input_tokens_details']['image_tokens'] += $image;
            $spent[$slot]['input_tokens_details']['cached_tokens'] += $cached;
        }
        $planning = [];
        foreach ($this->spendWhere($key, '1=1 ORDER BY created DESC LIMIT 300', []) as $e) {
            if (!in_array($e['kind'], ['planning', 'variation'], true) || !$counted($e) || str_starts_with($e['model'], 'gemini')) continue;
            $slot = $e['kind'] === 'variation' ? 'variation' : (string)($e['effort'] ?? '');
            if ($slot === '') continue;
            [$text, $image, $cached, $output] = $tokens($e['usage']);
            $planning[$slot] ??= ['model'=>$e['model'], 'calls'=>0, 'input_tokens'=>0, 'cached_tokens'=>0, 'output_tokens'=>0];
            $planning[$slot]['calls']++;
            $planning[$slot]['input_tokens'] += $text + $image;
            $planning[$slot]['cached_tokens'] += $cached;
            $planning[$slot]['output_tokens'] += $output;
        }
        foreach ($accounts as &$a) $a['spent'] = array_values($a['spent']);
        unset($a);
        return ['billing'=>$billing, 'spent'=>array_values($spent), 'planning'=>$planning, 'accounts'=>$accounts];
    }

    /**
     * What a call paid to Claude or Google cost: kept with it (the writer's steps), or priced from its tokens (Nano
     * Banana and the planning of its prompts). Null when it can't be.
     */
    private static function spendCost(array $e): ?float
    {
        if (isset($e['cost'])) return (float)$e['cost'];
        $u = $e['usage'];
        if ($e['kind'] === 'image') return GeminiImages::meta($e['model']) !== null ? GeminiImages::cost($e['model'], $u, (string)($u['tier'] ?? '1K')) : null;
        return is_array($u['gemini'] ?? null) ? Gemini::cost($e['model'], $u['gemini'], (int)$e['at']) : null;
    }

    /**
     * Chooses the version the gallery shows for an image and its edits (or the item it shows for a stack), or (off) goes
     * back to the newest one. The cover takes the place of the version shown until now in the custom order, so the image
     * doesn't move.
     */
    public function setCover(string $id, string $key, bool $on = true): void
    {
        self::validId($id);
        $all = $this->catalog($key);
        if (!isset($all[$id]) || $all[$id]['deleted'] !== null) throw new HttpError(404, t('Image not found.'));
        $pile = $all[$id]['pile'];
        $members = array_filter($all, fn($it) => $it['pile'] === $pile);
        $live = array_filter($members, fn($it) => $it['deleted'] === null);
        $place = max(array_map(fn($it) => $it['position'] ?? (float)$it['created'], $live));
        $owner = $this->owner();
        $this->db->beginTransaction();
        try {
            $this->eachSlice('UPDATE images SET cover=0 WHERE user_id=? AND cover<>0 AND id IN (%s)', array_keys($members), [$owner]);
            if ($on) $this->db->prepare('UPDATE images SET cover=1, position=? WHERE id=? AND user_id=?')->execute([$place, $id, $owner]);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function editMeta(string $id, string $title, string $prompt, string $key): void
    {
        $m=$this->image($id,$key); unset($m['id'],$m['created'],$m['favorite'],$m['deleted'],$m['position'],$m['cover'],$m['hidden'],$m['stack']);
        $m['title']=$title; $m['prompt']=$prompt;
        $q=$this->db->prepare('UPDATE images SET meta=CAST(? AS BLOB) WHERE id=? AND user_id=?');
        $q->execute([Crypto::seal(json_encode($m,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$key,"$id:meta"),$id,$this->owner()]);
    }

    /** Favorite, trash or restore one of the account's images. */
    public function mark(string $id, string $action): void
    {
        self::validId($id);
        $sql = match($action) {'favorite'=>'favorite=1-favorite','trash'=>'deleted='.time(),'restore'=>'deleted=NULL'};
        $q = $this->db->prepare('UPDATE images SET '.$sql.' WHERE id=? AND user_id=?'); $q->execute([$id, $this->owner()]);
        if (!$q->rowCount()) throw new HttpError(404, t('Image not found.'));
    }

    /** Permanently removes a trashed image and its files. Later versions become the start of their own lineage. */
    public function purge(string $id): void
    {
        self::validId($id);
        $q = $this->db->prepare('SELECT deleted FROM images WHERE id=? AND user_id=?'); $q->execute([$id, $this->owner()]); $r = $q->fetch();
        if (!$r) throw new HttpError(404, t('Image not found.'));
        if ($r['deleted'] === null) throw new HttpError(409, t('Move the image to the trash before deleting it.'));
        $this->purgeMany([$id]);
    }

    /** Permanently removes those of $ids that are in the account's trash. Returns how many were removed. */
    private function purgeMany(array $ids): int
    {
        $gone = [];
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                $q = $this->db->prepare("SELECT id FROM images WHERE user_id=? AND deleted IS NOT NULL AND id IN ($marks)"); $q->execute([$this->owner(), ...$chunk]);
                $found = $q->fetchAll(\PDO::FETCH_COLUMN);
                if (!$found) continue;
                $marks = implode(',', array_fill(0, count($found), '?'));
                $this->db->prepare("DELETE FROM images WHERE user_id=? AND id IN ($marks)")->execute([$this->owner(), ...$found]);
                $this->db->prepare("DELETE FROM links WHERE image_id IN ($marks)")->execute($found);
                array_push($gone, ...$found);
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
        foreach ($gone as $id) foreach (['full','thumb','video'] as $variant) @unlink("$this->dir/blobs/$id-$variant.enc");
        return count($gone);
    }

    private function previewPath(string $jobId): string { self::validId($jobId); return "$this->dir/blobs/job-$jobId-preview.enc"; }

    /** Streamed partial image of a running request, encrypted like every other image and removed when the request ends. */
    public function savePreview(string $jobId, string $bytes, int $index, string $key): void
    {
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'], ['image/png','image/jpeg','image/webp'], true)) return;
        $path = $this->previewPath($jobId); $tmp = $path.'.'.bin2hex(random_bytes(4));
        if (file_put_contents($tmp, Crypto::seal($bytes, $key, "job:$jobId:preview"), LOCK_EX) === false || !rename($tmp, $path)) { @unlink($tmp); return; }
        $q = $this->db->prepare('UPDATE jobs SET preview=?,updated=? WHERE id=?'); $q->execute([$index + 1, time(), $jobId]);
    }

    public function preview(string $jobId, string $key): string
    {
        self::validId($jobId);
        $q = $this->db->prepare('SELECT 1 FROM jobs WHERE id=? AND user_id=?'); $q->execute([$jobId, $this->owner()]);
        if (!$q->fetchColumn()) throw new HttpError(404, t('Preview unavailable.'));
        $data = @file_get_contents($this->previewPath($jobId));
        if ($data === false) throw new HttpError(404, t('Preview unavailable.'));
        return Crypto::open($data, $key, "job:$jobId:preview");
    }

    public function deletePreview(string $jobId): void { @unlink($this->previewPath($jobId)); }

    /**
     * A link that downloads one full image without a session, for MCP clients. The link carries a random secret that
     * unlocks a copy of the account key sealed for this link only; the server keeps neither the secret nor a usable key.
     */
    public function createLink(string $imageId, string $key, int $seconds = 86400): string
    {
        $this->image($imageId, $key);
        $this->db->prepare('DELETE FROM links WHERE expires<?')->execute([time()]);
        $id = bin2hex(random_bytes(16)); $secret = random_bytes(32);
        $q = $this->db->prepare('INSERT INTO links(id,user_id,image_id,sealed,expires) VALUES(?,?,?,CAST(? AS BLOB),?)');
        $q->execute([$id, $this->owner(), $imageId, Crypto::seal($key, $secret, "link:$id:$imageId"), time() + $seconds]);
        return $id.'.'.sodium_bin2base64($secret, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /** @return array{0:array,1:string} the image and its full bytes */
    public function openLink(string $token): array
    {
        if (!preg_match('/\A([a-f0-9]{32})\.([A-Za-z0-9_-]{43})\z/D', $token, $m)) throw new HttpError(404, t('Invalid or expired link.'));
        $q = $this->db->prepare('SELECT * FROM links WHERE id=? AND expires>?'); $q->execute([$m[1], time()]); $link = $q->fetch();
        if (!$link) throw new HttpError(404, t('Invalid or expired link.'));
        try { $key = Crypto::open((string)$link['sealed'], sodium_base642bin($m[2], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), "link:{$m[1]}:{$link['image_id']}"); }
        catch (\Throwable) { throw new HttpError(404, t('Invalid or expired link.')); }
        $this->useOwner((string)$link['user_id']);
        try { return [$this->image((string)$link['image_id'], $key), $this->bytes((string)$link['image_id'], 'full', $key)]; }
        finally { sodium_memzero($key); }
    }

    public static function validId(string $id): void
    { if (!preg_match('/\A[a-f0-9]{32}\z/D',$id)) throw new HttpError(400,t('Invalid identifier.')); }

    /** Counts one attempt in the named bucket and returns how many it holds now; refuses once the limit is reached. */
    public function throttle(string $name, int $limit, int $seconds, string $message='Muitas tentativas. Aguarde alguns minutos.'): int
    {
        $bucket=hash('sha256',$name);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->db->prepare('SELECT * FROM attempts WHERE bucket=?'); $q->execute([$bucket]); $r=$q->fetch();
            if (!$r || $r['until_time']<=time()) {
                $q=$this->db->prepare('INSERT OR REPLACE INTO attempts VALUES(?,1,?)'); $q->execute([$bucket,time()+$seconds]); $count=1;
            } elseif ($r['count'] >= $limit) { throw new HttpError(429,$message); }
            else { $q=$this->db->prepare('UPDATE attempts SET count=count+1 WHERE bucket=?'); $q->execute([$bucket]); $count=(int)$r['count']+1; }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
        // Expired buckets are cleared now and then, so the table doesn't keep every address that ever knocked.
        if (random_int(1,50)===1) $this->db->prepare('DELETE FROM attempts WHERE until_time<?')->execute([time()]);
        return $count;
    }
    public function forget(string $name): void { $this->db->prepare('DELETE FROM attempts WHERE bucket=?')->execute([hash('sha256',$name)]); }
    /** A marker that lives in the attempts table until it expires; used for devices that signed in before. */
    public function remember(string $name, int $seconds): void
    { $this->db->prepare('INSERT OR REPLACE INTO attempts VALUES(?,0,?)')->execute([hash('sha256',$name),time()+$seconds]); }
    public function remembered(string $name): bool
    { $q=$this->db->prepare('SELECT 1 FROM attempts WHERE bucket=? AND count=0 AND until_time>?'); $q->execute([hash('sha256',$name),time()]); return (bool)$q->fetchColumn(); }
}
