<?php
declare(strict_types=1);
namespace Studio;

/**
 * Accounts. Each account has its own random library key, wrapped with its password (Argon2id) and, when MCP access
 * is on, with a random token. Images, requests and the OpenAI key of an account are encrypted with its key, so one
 * account can neither list nor decrypt another's library, administrators included.
 */
final class Users
{
    public function __construct(private Vault $vault) {}

    public function count(): int { return (int)$this->vault->db->query('SELECT COUNT(*) FROM users')->fetchColumn(); }

    public function find(string $id): ?array
    {
        $q = $this->vault->db->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    public function findByName(string $name): ?array
    {
        $q = $this->vault->db->prepare('SELECT * FROM users WHERE name=?'); $q->execute([trim($name)]);
        return $q->fetch() ?: null;
    }

    /** The only account, so a single-user installation can still be unlocked with the password alone. */
    public function only(): ?array
    {
        $rows = $this->vault->db->query('SELECT * FROM users LIMIT 2')->fetchAll();
        return count($rows) === 1 ? $rows[0] : null;
    }

    /** @return list<array> what the administrator sees: no keys, no content */
    public function all(): array
    {
        $counts = array_column($this->vault->db->query('SELECT user_id, COUNT(*) AS n FROM images WHERE deleted IS NULL GROUP BY user_id')->fetchAll(), 'n', 'user_id');
        return array_map(fn(array $u) => self::summary($u) + ['images'=>(int)($counts[$u['id']] ?? 0)],
            $this->vault->db->query('SELECT * FROM users ORDER BY created, rowid')->fetchAll());
    }

    public static function summary(array $u): array
    {
        return ['id'=>$u['id'], 'name'=>$u['name'], 'admin'=>(bool)$u['admin'], 'created'=>(int)$u['created'], 'has_key'=>$u['api_key'] !== null,
            'mcp'=>$u['mcp_id'] === null ? null : ['created'=>(int)$u['mcp_created'], 'used'=>$u['mcp_used'] === null ? null : (int)$u['mcp_used']]];
    }

    public static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 40 || !preg_match('/\A[\p{L}\p{N}][\p{L}\p{N} ._@-]*\z/u', $name)) {
            throw new HttpError(422, t('Use a user name of up to 40 characters, with letters, numbers, spaces, periods, hyphens, @ or underscores.'));
        }
        return $name;
    }

    public static function checkPassword(string $p): void
    { if (strlen($p) < 12 || strlen($p) > 1024) throw new HttpError(422, t('Use a password between 12 and 1,024 characters.')); }

    /** Creates an account for an existing library key. The caller generates the key and runs the transaction if needed. */
    public function create(string $name, string $password, string $key, bool $admin = false): array
    {
        $name = self::cleanName($name); self::checkPassword($password);
        if ($this->findByName($name)) throw new HttpError(409, t('A user with that name already exists.'));
        $id = bin2hex(random_bytes(16));
        [$salt, $wrapped] = self::wrap($key, $password);
        $q = $this->vault->db->prepare('INSERT INTO users(id,name,salt,wrapped_key,epoch,admin,created) VALUES(?,?,?,CAST(? AS BLOB),?,?,?)');
        try { $q->execute([$id, $name, $salt, $wrapped, bin2hex(random_bytes(16)), $admin ? 1 : 0, time()]); }
        catch (\PDOException $e) { if (str_contains($e->getMessage(), 'UNIQUE')) throw new HttpError(409, t('A user with that name already exists.')); throw $e; }
        return $this->find($id);
    }

    /** @return array{0:string,1:string} base64 salt and the key sealed with the password */
    private static function wrap(string $key, string $password): array
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES); $derived = Crypto::derive($password, $salt);
        try { return [base64_encode($salt), Crypto::seal($key, $derived, 'vault-key:v1')]; }
        finally { sodium_memzero($derived); }
    }

    /** Wraps the key with a new password and signs out the account's other sessions. */
    public function setPassword(string $id, string $key, string $password): void
    {
        self::checkPassword($password);
        [$salt, $wrapped] = self::wrap($key, $password);
        $q = $this->vault->db->prepare('UPDATE users SET salt=?,wrapped_key=CAST(? AS BLOB),epoch=? WHERE id=?');
        $q->execute([$salt, $wrapped, bin2hex(random_bytes(16)), $id]);
    }

    public function unwrap(array $user, string $password): string
    {
        $salt = base64_decode((string)$user['salt'], true);
        if ($salt === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) throw new HttpError(401, t('Wrong user name or password.'));
        $derived = Crypto::derive($password, $salt);
        try { return Crypto::open((string)$user['wrapped_key'], $derived, 'vault-key:v1'); }
        catch (\Throwable) { throw new HttpError(401, t('Wrong user name or password.')); }
        finally { sodium_memzero($derived); }
    }

    public function rename(string $id, string $name): void
    {
        $name = self::cleanName($name);
        $other = $this->findByName($name);
        if ($other && $other['id'] !== $id) throw new HttpError(409, t('A user with that name already exists.'));
        try { $this->vault->db->prepare('UPDATE users SET name=? WHERE id=?')->execute([$name, $id]); }
        catch (\PDOException $e) { if (str_contains($e->getMessage(), 'UNIQUE')) throw new HttpError(409, t('A user with that name already exists.')); throw $e; }
    }

    /**
     * Removes the account and everything in its library: images, requests, previews, download links and stories (their
     * chapters, conversations, comic pages and visual novel scenes go with them, by foreign key).
     */
    public function delete(string $id): void
    {
        $db = $this->vault->db;
        $q = $db->prepare('SELECT id FROM images WHERE user_id=?'); $q->execute([$id]); $images = $q->fetchAll(\PDO::FETCH_COLUMN);
        $q = $db->prepare('SELECT id FROM jobs WHERE user_id=?'); $q->execute([$id]); $jobs = $q->fetchAll(\PDO::FETCH_COLUMN);
        $q = $db->prepare('SELECT id FROM uploads WHERE user_id=?'); $q->execute([$id]); $uploads = $q->fetchAll(\PDO::FETCH_COLUMN);
        $db->exec('BEGIN IMMEDIATE');
        try {
            foreach (['stories'=>'user_id','images'=>'user_id','albums'=>'user_id','jobs'=>'user_id','links'=>'user_id','uploads'=>'user_id','spend'=>'user_id','users'=>'id'] as $table => $column) $db->prepare("DELETE FROM $table WHERE $column=?")->execute([$id]);
            // What the account's upgrades noted (Stories::adoptAlbums).
            $db->prepare('DELETE FROM settings WHERE name=?')->execute([Stories::ADOPTED.$id]);
            $db->exec('COMMIT');
        } catch (\Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
        foreach ($images as $image) foreach (['full','thumb','video'] as $variant) @unlink($this->vault->dir."/blobs/$image-$variant.enc");
        foreach ($uploads as $upload) @unlink($this->vault->dir."/blobs/$upload-video.part");
        foreach ($jobs as $job) foreach (['preview','inputs'] as $part) @unlink($this->vault->dir."/blobs/job-$job-$part.enc");
    }

    /**
     * A new MCP token for the account, replacing any earlier one. The token is shown once: the server stores only a
     * lookup id and the account key sealed with the token's random secret.
     */
    public function issueToken(string $id, string $key): string
    {
        $lookup = bin2hex(random_bytes(12)); $secret = random_bytes(32);
        $q = $this->vault->db->prepare('UPDATE users SET mcp_id=?,mcp_key=CAST(? AS BLOB),mcp_created=?,mcp_used=NULL WHERE id=?');
        $q->execute([$lookup, Crypto::seal($key, $secret, "mcp:$lookup"), time(), $id]);
        return 'igm_'.$lookup.'_'.sodium_bin2base64($secret, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    public function revokeToken(string $id): void
    { $this->vault->db->prepare('UPDATE users SET mcp_id=NULL,mcp_key=NULL,mcp_created=NULL,mcp_used=NULL WHERE id=?')->execute([$id]); }

    /** @return ?array{0:array,1:string} the account and its key, or null for an unknown or revoked token */
    public function resolveToken(string $token): ?array
    {
        if (!preg_match('/\Aigm_([a-f0-9]{24})_([A-Za-z0-9_-]{43})\z/D', $token, $m)) return null;
        $q = $this->vault->db->prepare('SELECT * FROM users WHERE mcp_id=?'); $q->execute([$m[1]]); $user = $q->fetch(); $q->closeCursor();
        if (!$user) return null;
        try { $key = Crypto::open((string)$user['mcp_key'], sodium_base642bin($m[2], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), 'mcp:'.$m[1]); }
        catch (\Throwable) { return null; }
        if ((int)$user['mcp_used'] < time() - 60) {
            try { $this->vault->db->prepare('UPDATE users SET mcp_used=? WHERE id=?')->execute([time(), $user['id']]); } catch (\PDOException) { /* only a statistic */ }
        }
        return [$user, $key];
    }
}
