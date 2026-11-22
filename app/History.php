<?php
declare(strict_types=1);
namespace Studio;

/**
 * The history of prompts: everything the account asked for, newest first. Image requests (from the image page, the MCP
 * connector and the stories: illustrations, covers, character sheets, comic pages) and the messages to the writer of each
 * story. Two lists merged by date and read a page at a time. A search, a kind or a story narrows them: the server reads
 * on until the page is full or its time for the page is up, and the cursor goes on from there.
 */
final class History
{
    /** Seconds of reading for one page: a search that finds little returns what it has, and the next page goes on. */
    private const BUDGET = 4.0;
    private const CHUNK = 40;
    public const KINDS = ['all', 'images', 'writer'];

    public function __construct(private Vault $vault, private Jobs $jobs, private Stories $stories) {}

    /**
     * @param array{q?:string,kind?:string,story?:string,cursor?:string,limit?:int|string} $query
     * @return array{items:list<array>,next:?string,stories:array<string,string>}
     */
    public function page(string $key, array $query): array
    {
        $started = microtime(true);
        $limit = max(5, min(100, (int)($query['limit'] ?? 30)));
        $kind = in_array($query['kind'] ?? 'all', self::KINDS, true) ? (string)($query['kind'] ?? 'all') : 'all';
        $search = self::fold(trim((string)($query['q'] ?? '')));
        $titles = $this->stories->titles($key);
        $story = (string)($query['story'] ?? '');
        if ($story !== '') { Vault::validId($story); if (!isset($titles[$story])) throw new HttpError(404, t('Story not found.')); }
        $albums = [];
        foreach ($titles as $id => $t) if ($t['album'] !== null) $albums[$t['album']] = $id;
        [$jobsAt, $messagesAt] = self::cursor((string)($query['cursor'] ?? ''));
        if ($kind === 'writer') $jobsAt = false;
        if ($kind === 'images') $messagesAt = false;
        if (($query['cursor'] ?? '') === '') $this->jobs->refresh();

        $jobs = []; $messages = [];
        $items = [];
        while (count($items) < $limit && microtime(true) - $started < self::BUDGET) {
            if (!$jobs && $jobsAt !== false) { $jobs = $this->jobs->before($jobsAt, self::CHUNK); if (!$jobs) $jobsAt = false; }
            if (!$messages && $messagesAt !== false) { $messages = $this->messagesBefore($messagesAt, $story, self::CHUNK); if (!$messages) $messagesAt = false; }
            if (!$jobs && !$messages) break;
            // The newer of the two next ones.
            $fromJobs = $jobs && (!$messages || $jobs[0]['at'][0] >= $messages[0]['at'][0]);
            if ($fromJobs) {
                $next = array_shift($jobs); $jobsAt = $next['at'];
                $job = $this->jobs->entry($next['id'], $key); if ($job === null) continue;
                $of = $job['album'] !== null ? ($albums[$job['album']] ?? null) : null; unset($job['album']);
                if ($story !== '' && $of !== $story) continue;
                $item = ['type'=>'image'] + $job + ['story'=>$of !== null ? ['id'=>$of, 'title'=>$titles[$of]['title']] : null];
                if ($search !== '' && !self::matches($search, [$job['prompt'], (string)($job['label'] ?? ''), $of !== null ? $titles[$of]['title'] : ''])) continue;
            } else {
                $next = array_shift($messages); $messagesAt = $next['at'];
                $item = $this->message($next, $key, $titles); if ($item === null) continue;
                if ($search !== '' && !self::matches($search, [$item['text'], $item['story']['title']])) continue;
            }
            $items[] = $item;
        }
        // A list read to its last one is over only if nothing comes after it.
        if (!$jobs && $jobsAt !== false && !$this->jobs->before($jobsAt, 1)) $jobsAt = false;
        if (!$messages && $messagesAt !== false && !$this->messagesBefore($messagesAt, $story, 1)) $messagesAt = false;
        $more = $jobsAt !== false || $messagesAt !== false;
        $stories = [];
        foreach ($titles as $id => $t) $stories[$id] = $t['title'];
        return ['items'=>$items, 'next'=>$more ? self::encode($jobsAt, $messagesAt) : null, 'stories'=>$stories];
    }

    /**
     * The person's messages to the writer before $at ([created, seq]), newest first, of one story or of all: where each
     * one is, read one at a time by message().
     * @return list<array{at:array{0:int,1:int},id:string,story:string}>
     */
    private function messagesBefore(?array $at, string $story, int $limit): array
    {
        $sql = "SELECT seq,id,story_id,created FROM story_messages WHERE user_id=? AND role='user'".($story !== '' ? ' AND story_id=?' : '')
            .($at !== null ? ' AND (created<? OR (created=? AND seq<?))' : '').' ORDER BY created DESC, seq DESC LIMIT ?';
        $args = [$this->vault->owner()]; if ($story !== '') $args[] = $story;
        if ($at !== null) array_push($args, (int)$at[0], (int)$at[0], (int)$at[1]);
        $args[] = $limit;
        $q = $this->vault->db->prepare($sql);
        foreach ($args as $n => $v) $q->bindValue($n + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $q->execute();
        return array_map(fn($r) => ['at'=>[(int)$r['created'], (int)$r['seq']], 'id'=>(string)$r['id'], 'story'=>(string)$r['story_id']], $q->fetchAll());
    }

    /** One message to the writer as the history shows it: what the person wrote and the gallery images attached. */
    private function message(array $at, string $key, array $titles): ?array
    {
        if (!isset($titles[$at['story']])) return null;
        $q = $this->vault->db->prepare('SELECT content FROM story_messages WHERE seq=?'); $q->execute([$at['at'][1]]);
        $content = $q->fetchColumn(); $q->closeCursor(); if ($content === false) return null;
        try { $c = json_decode(Crypto::open((string)$content, $key, 'story-message:'.$at['id']), true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { logFailure($e); return null; }
        $view = is_array($c['view'] ?? null) ? $c['view'] : [];
        return ['type'=>'writer', 'id'=>$at['id'], 'created'=>$at['at'][0], 'story'=>['id'=>$at['story'], 'title'=>$titles[$at['story']]['title']],
            'text'=>is_string($view['text'] ?? null) ? $view['text'] : '', 'images'=>array_values(array_filter(is_array($view['images'] ?? null) ? $view['images'] : [], 'is_string')),
            'during'=>!empty($view['during'])];
    }

    private static function matches(string $search, array $texts): bool
    {
        $folded = self::fold(implode("\n", $texts));
        foreach (preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) if (!str_contains($folded, $word)) return false;
        return true;
    }

    /** Lowercase, without accents: a search finds "coração" by "coracao". */
    private static function fold(string $text): string
    {
        $text = mb_strtolower($text);
        if (class_exists(\Normalizer::class)) return preg_replace('/\p{Mn}+/u', '', (string)\Normalizer::normalize($text, \Normalizer::FORM_D)) ?? $text;
        return strtr($text, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n']);
    }

    /**
     * Where a page ended in each list: [created, id] of the last one read, null for the newest, false when it's over.
     * @return array{0:array|null|false,1:array|null|false}
     */
    private static function cursor(string $cursor): array
    {
        if ($cursor === '') return [null, null];
        $c = json_decode((string)base64_decode(strtr($cursor, '-_', '+/'), true), true);
        $one = fn($v) => $v === false ? false : (is_array($v) && count($v) === 2 && is_int($v[0]) && is_int($v[1]) ? [$v[0], $v[1]] : null);
        if (!is_array($c)) throw new HttpError(422, t('Invalid history position.'));
        return [$one($c['j'] ?? null), $one($c['m'] ?? null)];
    }

    private static function encode(array|false|null $jobs, array|false|null $messages): string
    { return rtrim(strtr(base64_encode(json_encode(['j'=>$jobs, 'm'=>$messages], JSON_THROW_ON_ERROR)), '+/', '-_'), '='); }
}
