<?php
declare(strict_types=1);
namespace Studio;

/**
 * A story adapted as a comic or a manga: its settings (kind, reading direction, color, the page's shape and size, a
 * look of its own), and its pages in order. Each page is a script, panel by panel (framing, setting, who is there and
 * what they do, the balloons, captions and sound effects with their exact words), a rough storyboard of it (an SVG the
 * writer draws, or one the app draws from the script), and the finished page: one image made in a single request,
 * never put together from separate panels, so the line and the lettering are the same all over the page.
 *
 * Everything is sealed with the account's key, like the story: the settings in the story's row (comic), each page in
 * a row of its own (comic_pages).
 */
final class Comics
{
    /** manga; comic: a Western comic book, told like a periodical issue; graphic_novel: a Western book-length work. */
    public const KINDS = ['manga', 'comic', 'graphic_novel'];
    /** What each kind is called in English, for the writer and the image model. */
    public const KIND_NAMES = ['manga'=>'manga', 'comic'=>'comic book', 'graphic_novel'=>'graphic novel'];
    public const READINGS = ['rtl', 'ltr'];
    public const COLORS = ['bw', 'color'];
    public const ASPECTS = ['2:3', '3:4', '4:5', '9:16', '1:1'];
    public const SCALES = ['1', '1.5', '2'];
    public const DEFAULTS = ['kind'=>'manga', 'reading'=>'rtl', 'color'=>'bw', 'aspect'=>'2:3', 'scale'=>'2'];
    public const TEXT_TYPES = ['speech', 'thought', 'whisper', 'shout', 'caption', 'sfx', 'sign'];
    public const MAX_PAGES = 600;
    public const MAX_PANELS = 12;
    public const MAX_STYLE_IMAGES = 4;

    public function __construct(private Vault $vault, private Stories $stories) {}

    private function owner(): string { return $this->vault->owner(); }

    private static function seal(mixed $value, string $key, string $context): string
    { return Crypto::seal(json_encode($value, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $key, $context); }

    private static function open(mixed $blob, string $key, string $context): mixed
    { return $blob === null ? null : json_decode(Crypto::open((string)$blob, $key, $context), true, 512, JSON_THROW_ON_ERROR); }

    // -----------------------------------------------------------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------------------------------------------------------

    /**
     * The adaptation's settings, complete. started: whether the story has one (settings saved or pages written). rev
     * counts every change of the adaptation (settings or pages), by tells who made the last one, for the writer to
     * hear of the person's.
     */
    public function settings(string $storyId, string $key): array
    {
        $row = $this->stories->rowOf($storyId);
        $s = self::open($row['comic'] ?? null, $key, 'story-comic:'.$storyId);
        $started = is_array($s);
        $s = (is_array($s) ? $s : []) + self::DEFAULTS + ['rev'=>0, 'by'=>null, 'at'=>null, 'style'=>[], 'reading_page'=>null];
        $s['style'] = (is_array($s['style']) ? $s['style'] : []) + ['text'=>'', 'images'=>[]];
        $s['started'] = $started;
        return $s;
    }

    private function saveSettings(string $storyId, array $s, string $key): void
    {
        // An adaptation starting now gets its own sheets, empty, apart from the book's (Stories::startComicSpace).
        if (!$s['started']) $this->stories->startComicSpace($storyId, $key);
        unset($s['started']);
        $this->vault->db->prepare('UPDATE stories SET comic=CAST(? AS BLOB) WHERE id=? AND user_id=?')->execute([self::seal($s, $key, "story-comic:$storyId"), $storyId, $this->owner()]);
    }

    /** A change of the adaptation by $by: counted in the settings and the story, so the page and the writer see it. */
    private function changed(string $storyId, string $by, string $key): void
    {
        $s = $this->settings($storyId, $key);
        $s['rev'] = (int)$s['rev'] + 1; $s['by'] = $by; $s['at'] = time();
        $this->saveSettings($storyId, $s, $key);
        $this->stories->touch($storyId);
    }

    /**
     * Changes the settings: kind, reading, color, aspect, scale, the adaptation's own style (style_text; '' goes back
     * to the story's style) and its style pictures (style_add: library images with captions, style_remove: ids).
     */
    public function setSettings(string $storyId, array $change, string $by, string $key): array
    {
        return $this->stories->transaction(function () use ($storyId, $change, $by, $key) {
            $s = $this->settings($storyId, $key);
            foreach (['kind'=>self::KINDS, 'reading'=>self::READINGS, 'color'=>self::COLORS, 'aspect'=>self::ASPECTS, 'scale'=>self::SCALES] as $field => $allowed) {
                if (!array_key_exists($field, $change) || $change[$field] === null) continue;
                $value = is_int($change[$field]) || is_float($change[$field]) ? (string)$change[$field] : $change[$field];
                if (!in_array($value, $allowed, true)) throw new HttpError(422, t('Invalid value for {field}: use {values}.', ['field'=>$field, 'values'=>implode(', ', $allowed)]));
                $s[$field] = $value;
            }
            if (isset($change['style_text'])) $s['style']['text'] = trim(Stories::cleanText($change['style_text'], 4000, t('The comic\'s style')));
            foreach (is_array($change['style_add'] ?? null) ? $change['style_add'] : [] as $add) {
                $image = (string)(is_array($add) ? ($add['image'] ?? '') : $add); Vault::validId($image); $this->vault->still($image, $key);
                if (in_array($image, array_column($s['style']['images'], 'image'), true)) continue;
                if (count($s['style']['images']) >= self::MAX_STYLE_IMAGES) throw new HttpError(422, t('The comic\'s style can have up to {max} images: remove one first.', ['max'=>self::MAX_STYLE_IMAGES]));
                $s['style']['images'][] = ['image'=>$image, 'caption'=>Stories::caption(Stories::cleanLine(is_array($add) ? ($add['caption'] ?? '') : '', 200, t('The caption'))), 'by'=>$by, 'at'=>time()];
            }
            foreach (is_array($change['style_remove'] ?? null) ? $change['style_remove'] : [] as $ref) {
                $before = count($s['style']['images']);
                $s['style']['images'] = array_values(array_filter($s['style']['images'], fn($im) => $im['image'] !== (string)$ref));
                if (count($s['style']['images']) === $before) throw new HttpError(422, t('Image {ref} is not in the comic\'s style.', ['ref'=>$ref]));
            }
            $kind = $this->settings($storyId, $key)['kind'];
            $s['rev'] = (int)$s['rev'] + 1; $s['by'] = $by; $s['at'] = time();
            $this->saveSettings($storyId, $s, $key);
            // The adaptation's album says its kind (Mangá, HQ, graphic novel) in its name.
            if ($kind !== $s['kind']) $this->stories->renameAlbums($storyId, $key);
            $this->stories->touch($storyId);
            return $this->settings($storyId, $key);
        });
    }

    /**
     * The look the pages are drawn in: the adaptation's own style when it has one, else the story's; and always the
     * adaptation's own lettering (its sheets' lettering, apart from the book's).
     */
    public function style(string $storyId, string $key): array
    {
        $own = $this->settings($storyId, $key)['style'];
        $story = $this->stories->cast($storyId, $key)['style'];
        $mine = $own['text'] !== '' || $own['images'];
        return ['text'=>$mine ? $own['text'] : $story['text'], 'images'=>$mine ? $own['images'] : $story['images'], 'lettering'=>$this->stories->cast($storyId, $key, 'comic')['style']['lettering'], 'own'=>$mine];
    }

    /** Where the person stopped reading the comic (a page id), kept like the story's reading place: not a change. */
    public function setReading(string $storyId, string $pageId, string $key): void
    {
        Vault::validId($pageId);
        $this->stories->transaction(function () use ($storyId, $pageId, $key) {
            $s = $this->settings($storyId, $key); if (!$s['started']) return;
            $s['reading_page'] = $pageId;
            $this->saveSettings($storyId, $s, $key);
        });
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Pages
    // -----------------------------------------------------------------------------------------------------------------

    /** @return list<array> the pages in order, each with its number (1 is the first) */
    public function pages(string $storyId, string $key): array
    {
        $this->stories->rowOf($storyId);
        $q = $this->vault->db->prepare('SELECT * FROM comic_pages WHERE story_id=? AND user_id=? ORDER BY position, created'); $q->execute([$storyId, $this->owner()]);
        $rows = $q->fetchAll(); $q->closeCursor();
        $out = [];
        foreach ($rows as $n => $r) {
            $b = self::open($r['body'], $key, 'comic-page:'.$r['id']);
            $b = (is_array($b) ? $b : []) + ['chapter'=>null, 'summary'=>'', 'panels'=>[], 'rows'=>null, 'sketch'=>'', 'notes'=>'', 'image'=>null, 'job'=>null, 'drawn'=>null, 'by'=>null];
            $out[] = ['id'=>$r['id'], 'number'=>$n + 1, 'rev'=>(int)$r['rev'], 'position'=>(float)$r['position'], 'updated'=>(int)$r['updated']] + $b;
        }
        return $out;
    }

    public function page(string $storyId, string $pageId, string $key): array
    {
        foreach ($this->pages($storyId, $key) as $p) if ($p['id'] === $pageId) return $p;
        throw new HttpError(404, t('Page not found.'));
    }

    public function pageAt(string $storyId, int $number, string $key): array
    {
        $pages = $this->pages($storyId, $key);
        if ($number < 1 || $number > count($pages)) throw new HttpError(422, count($pages) === 1 ? t('There is no page {number} (the comic has 1 page).', ['number'=>$number]) : t('There is no page {number} (the comic has {n} pages).', ['number'=>$number, 'n'=>count($pages)]));
        return $pages[$number - 1];
    }

    private function savePage(array $p, string $key, bool $content): void
    {
        $body = array_intersect_key($p, array_flip(['chapter', 'summary', 'panels', 'rows', 'sketch', 'notes', 'image', 'job', 'drawn', 'by']));
        $this->vault->db->prepare('UPDATE comic_pages SET body=CAST(? AS BLOB), rev=?, updated=? WHERE id=? AND user_id=?')
            ->execute([self::seal($body, $key, 'comic-page:'.$p['id']), $p['rev'] + ($content ? 1 : 0), time(), $p['id'], $this->owner()]);
    }

    /**
     * A page's script as it is kept, from what the writer or the person gave: the chapter it adapts (an id, or null),
     * a summary, the panels in reading order, the rows of the layout (how many panels in each, for the app's own
     * sketch), the writer's sketch (SVG, '' for the app's) and notes for the artist.
     */
    public function script(string $storyId, array $in, string $key, ?array $base = null): array
    {
        $p = $base ?? ['chapter'=>null, 'summary'=>'', 'panels'=>[], 'rows'=>null, 'sketch'=>'', 'notes'=>''];
        if (array_key_exists('chapter', $in)) {
            $p['chapter'] = null;
            if ($in['chapter'] !== null && $in['chapter'] !== '') { $c = (string)$in['chapter']; Vault::validId($c); $this->stories->chapter($storyId, $c, $key); $p['chapter'] = $c; }
        }
        if (isset($in['summary'])) $p['summary'] = Stories::cleanLine($in['summary'], 1000, t('The page\'s summary'));
        if (isset($in['notes'])) $p['notes'] = trim(Stories::cleanText($in['notes'], 3000, t('The page\'s notes')));
        if (isset($in['panels'])) $p['panels'] = self::panels($in['panels']);
        if (array_key_exists('rows', $in)) $p['rows'] = $in['rows'] === null || $in['rows'] === [] ? null : $in['rows'];
        if ($p['rows'] !== null) {
            if (!is_array($p['rows']) || !array_is_list($p['rows']) || array_filter($p['rows'], fn($r) => !is_int($r) || $r < 1 || $r > 5)) throw new HttpError(422, t('rows: a list of 1 to 5 panels per row, from top to bottom.'));
            if (array_sum($p['rows']) !== count($p['panels'])) throw new HttpError(422, t('rows adds up to {sum} panels, but the page has {n}. The rows must add up to the number of panels (or leave rows out).', ['sum'=>array_sum($p['rows']), 'n'=>count($p['panels'])]));
        }
        if (array_key_exists('sketch', $in)) $p['sketch'] = $in['sketch'] === null || trim((string)$in['sketch']) === '' ? '' : Sketch::check((string)$in['sketch']);
        if (!$p['panels']) throw new HttpError(422, t('The page needs at least one panel.'));
        return $p;
    }

    /** The panels of a page, checked and trimmed to what is kept. */
    public static function panels(mixed $panels): array
    {
        if (!is_array($panels) || !array_is_list($panels) || !$panels) throw new HttpError(422, t('panels: a list of panels, in reading order.'));
        if (count($panels) > self::MAX_PANELS) throw new HttpError(422, t('A page can have up to {max} panels.', ['max'=>self::MAX_PANELS]));
        $line = fn(mixed $v, int $max, string $what) => $v === null ? '' : Stories::cleanLine($v, $max, $what);
        $out = [];
        foreach ($panels as $n => $panel) {
            if (!is_array($panel)) throw new HttpError(422, t('Panel {n}: must be an object.', ['n'=>($n + 1)]));
            $q = ['framing'=>$line($panel['framing'] ?? null, 300, t('The framing')), 'place'=>$line($panel['place'] ?? null, 120, t('The place')),
                'description'=>trim(Stories::cleanText((string)($panel['description'] ?? ''), 3000, t('The panel\'s description'))), 'characters'=>[], 'text'=>[]];
            $chars = $panel['characters'] ?? [];
            if (!is_array($chars) || count($chars) > 8) throw new HttpError(422, t('Panel {n}: up to 8 characters.', ['n'=>($n + 1)]));
            foreach ($chars as $c) {
                if (!is_array($c) || trim((string)($c['name'] ?? '')) === '') throw new HttpError(422, t('Panel {n}: each character needs a name.', ['n'=>($n + 1)]));
                $sheet = trim((string)($c['sheet'] ?? ''));
                if ($sheet !== '' && !preg_match('/\A[a-f0-9]{32}\z/D', $sheet)) throw new HttpError(422, t('Panel {n}: sheet must be the id of an image of the sheet (32 characters).', ['n'=>($n + 1)]));
                $q['characters'][] = ['name'=>$line($c['name'], 80, t('The name')), 'sheet'=>$sheet, 'where'=>$line($c['where'] ?? null, 200, t('The position')), 'doing'=>$line($c['doing'] ?? null, 600, t('The action'))];
            }
            $texts = $panel['text'] ?? [];
            if (!is_array($texts) || count($texts) > 12) throw new HttpError(422, t('Panel {n}: up to 12 texts.', ['n'=>($n + 1)]));
            foreach ($texts as $t) {
                if (!is_array($t)) throw new HttpError(422, t('Panel {n}: invalid text.', ['n'=>($n + 1)]));
                $type = (string)($t['type'] ?? 'speech');
                if (!in_array($type, self::TEXT_TYPES, true)) throw new HttpError(422, t('Panel {n}: type must be {types}.', ['n'=>($n + 1), 'types'=>implode(', ', self::TEXT_TYPES)]));
                $words = trim(Stories::cleanText((string)($t['text'] ?? ''), 600, t('The text')));
                if ($words === '') throw new HttpError(422, t('Panel {n}: a text is empty.', ['n'=>($n + 1)]));
                $q['text'][] = ['type'=>$type, 'by'=>$line($t['by'] ?? null, 80, 'Quem fala'), 'text'=>$words, 'where'=>$line($t['where'] ?? null, 200, t('Where the text goes'))];
            }
            if ($q['description'] === '' && !$q['characters'] && !$q['text']) throw new HttpError(422, t('Panel {n}: describe what it shows.', ['n'=>($n + 1)]));
            $out[] = $q;
        }
        return $out;
    }

    /** A new page at $index (0 = first; null = last). */
    public function addPage(string $storyId, array $in, ?int $index, string $by, string $key): array
    {
        $script = $this->script($storyId, $in, $key);
        $id = bin2hex(random_bytes(16));
        $this->stories->transaction(function () use ($storyId, $script, $index, $by, $key, $id) {
            $pages = $this->pages($storyId, $key);
            if (count($pages) >= self::MAX_PAGES) throw new HttpError(422, t('A comic can have up to {max} pages.', ['max'=>self::MAX_PAGES]));
            $index = $index === null ? count($pages) : max(0, min(count($pages), $index));
            $before = $index > 0 ? $pages[$index - 1]['position'] : null; $after = $pages[$index]['position'] ?? null;
            $position = $before === null && $after === null ? 1.0 : ($before === null ? $after - 1 : ($after === null ? $before + 1 : ($before + $after) / 2));
            $now = time();
            $body = $script + ['image'=>null, 'job'=>null, 'drawn'=>null, 'by'=>$by];
            $this->vault->db->prepare('INSERT INTO comic_pages(id,story_id,user_id,position,body,rev,created,updated) VALUES(?,?,?,?,CAST(? AS BLOB),1,?,?)')
                ->execute([$id, $storyId, $this->owner(), $position, self::seal($body, $key, "comic-page:$id"), $now, $now]);
            $this->changed($storyId, $by, $key);
        });
        return $this->page($storyId, $id, $key);
    }

    /** Changes a page's script (the fields given). $expect: the revision the person started from (409 if it changed). */
    public function updatePage(string $storyId, string $pageId, array $in, string $by, string $key, ?int $expect = null): array
    {
        $this->stories->transaction(function () use ($storyId, $pageId, $in, $by, $key, $expect) {
            $p = $this->page($storyId, $pageId, $key);
            if ($expect !== null && $expect !== $p['rev']) throw new HttpError(409, t('This page changed while you were editing (the writer may have changed it). Reload to see the new version.'));
            $script = $this->script($storyId, $in, $key, array_intersect_key($p, array_flip(['chapter', 'summary', 'panels', 'rows', 'sketch', 'notes'])));
            $this->savePage(array_merge($p, $script, ['by'=>$by]), $key, true);
            $this->changed($storyId, $by, $key);
        });
        return $this->page($storyId, $pageId, $key);
    }

    public function deletePage(string $storyId, string $pageId, string $by, string $key): void
    {
        Vault::validId($pageId);
        $this->stories->transaction(function () use ($storyId, $pageId, $by, $key) {
            $this->stories->rowOf($storyId);
            $q = $this->vault->db->prepare('DELETE FROM comic_pages WHERE id=? AND story_id=? AND user_id=?'); $q->execute([$pageId, $storyId, $this->owner()]);
            if (!$q->rowCount()) throw new HttpError(404, t('Page not found.'));
            $this->changed($storyId, $by, $key);
        });
    }

    /** Moves a page to $index (0 = first) among the others. */
    public function movePage(string $storyId, string $pageId, int $index, string $by, string $key): void
    {
        $this->stories->transaction(function () use ($storyId, $pageId, $index, $by, $key) {
            $all = $this->pages($storyId, $key);
            $rest = array_values(array_filter($all, fn($p) => $p['id'] !== $pageId));
            if (count($rest) === count($all)) throw new HttpError(404, t('Page not found.'));
            $index = max(0, min(count($rest), $index));
            $before = $index > 0 ? $rest[$index - 1]['position'] : null; $after = $rest[$index]['position'] ?? null;
            $position = $before === null && $after === null ? 1.0 : ($before === null ? $after - 1 : ($after === null ? $before + 1 : ($before + $after) / 2));
            $this->vault->db->prepare('UPDATE comic_pages SET position=? WHERE id=? AND user_id=?')->execute([$position, $pageId, $this->owner()]);
            $this->changed($storyId, $by, $key);
        });
    }

    // -----------------------------------------------------------------------------------------------------------------
    // The drawn page
    // -----------------------------------------------------------------------------------------------------------------

    /** The page is being drawn by request $jobId, from the script as it is now. */
    public function setJob(string $storyId, string $pageId, string $jobId, string $key): void
    {
        $this->stories->transaction(function () use ($storyId, $pageId, $jobId, $key) {
            $p = $this->page($storyId, $pageId, $key);
            $p['job'] = $jobId; $p['drawn'] = $p['rev'];
            $this->savePage($p, $key, false);
            $this->stories->touch($storyId);
        });
    }

    /** A drawing that failed, made again: the new request takes its place (from the same script as before). */
    public function retryJob(string $storyId, string $pageId, string $jobId, string $key): void
    {
        $this->stories->transaction(function () use ($storyId, $pageId, $jobId, $key) {
            $p = $this->page($storyId, $pageId, $key); $p['job'] = $jobId;
            $this->savePage($p, $key, false);
            $this->stories->touch($storyId);
        });
    }

    /** A drawing that failed, set aside: the page keeps the image it had (or none). */
    public function dropJob(string $storyId, string $pageId, string $key): void
    {
        $this->stories->transaction(function () use ($storyId, $pageId, $key) {
            $p = $this->page($storyId, $pageId, $key); $p['job'] = null;
            $this->savePage($p, $key, false);
            $this->stories->touch($storyId);
        });
    }

    /** @return array<string,string> the requests drawing pages: request id => page id */
    public function jobs(string $storyId, string $key): array
    {
        $out = [];
        foreach ($this->pages($storyId, $key) as $p) if (is_string($p['job'])) $out[$p['job']] = $p['id'];
        return $out;
    }

    /** A drawing that ended with an image: it becomes the page. */
    public function finishJob(string $storyId, string $jobId, string $imageId, string $key): void
    {
        $this->stories->transaction(function () use ($storyId, $jobId, $imageId, $key) {
            foreach ($this->pages($storyId, $key) as $p) if ($p['job'] === $jobId) {
                $p['image'] = $imageId; $p['job'] = null;
                $this->savePage($p, $key, false);
                $this->stories->touch($storyId);
            }
        });
    }

    /** A new version of a page's image (an edit, a larger one) takes the old one's place. */
    public function replaceImage(string $storyId, string $from, string $to, string $key): bool
    {
        return $this->stories->transaction(function () use ($storyId, $from, $to, $key) {
            $changed = false;
            foreach ($this->pages($storyId, $key) as $p) if ($p['image'] === $from) { $p['image'] = $to; $this->savePage($p, $key, false); $changed = true; }
            foreach (($s = $this->settings($storyId, $key))['style']['images'] as $n => $im) if ($im['image'] === $from) { $s['style']['images'][$n]['image'] = $to; $this->saveSettings($storyId, $s, $key); $changed = true; }
            if ($changed) $this->stories->touch($storyId);
            return $changed;
        });
    }

    /** @return list<int> the numbers of the pages whose image is $imageId */
    public function pagesWith(string $storyId, string $imageId, string $key): array
    {
        $out = [];
        foreach ($this->pages($storyId, $key) as $p) if ($p['image'] === $imageId) $out[] = $p['number'];
        return $out;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // The sketch
    // -----------------------------------------------------------------------------------------------------------------

    /** The page's storyboard as SVG: the one the writer drew, or one the app draws from the script. */
    public static function sketchSvg(array $page, array $settings): string
    {
        return $page['sketch'] !== '' ? $page['sketch'] : Sketch::auto($page, $settings['reading'] === 'rtl', $settings['aspect']);
    }

    /** The storyboard as a PNG, at the image API's usual size for the page's shape (what the image model gets). */
    public static function sketchPng(array $page, array $settings): string
    {
        [$w, $h] = array_map('intval', explode('x', OpenAI::SIZES[$settings['aspect']] ?? '1024x1536'));
        return Sketch::png(self::sketchSvg($page, $settings), $w, $h);
    }

    // -----------------------------------------------------------------------------------------------------------------
    // What the page and the writer see
    // -----------------------------------------------------------------------------------------------------------------

    /** The adaptation for the story's page: the settings and every page, without the sketches' SVG (they come as images). */
    public function view(string $storyId, string $key): array
    {
        $s = $this->settings($storyId, $key);
        $pages = array_map(fn($p) => array_diff_key($p, ['sketch'=>1, 'position'=>1]) + ['own_sketch'=>$p['sketch'] !== ''], $this->pages($storyId, $key));
        return ['settings'=>array_diff_key($s, ['reading_page'=>1]), 'reading_page'=>$s['reading_page'], 'pages'=>$pages];
    }

    /** The balloons and captions of a page, numbered in reading order (B1, B2…), as the sketch and the prompt name them. */
    public static function balloons(array $page): array
    {
        $n = 0; $out = [];
        foreach ($page['panels'] as $k => $panel) foreach ($panel['text'] as $t) $out[] = ['panel'=>$k + 1, 'label'=>$t['type'] === 'sfx' ? null : 'B'.(++$n)] + $t;
        return $out;
    }

    /** What a page's state is, in a line for the writer. */
    public static function stateLine(array $p, array $failed = []): string
    {
        if (is_string($p['job'])) return isset($failed[$p['job']]) ? 'the drawing failed (request '.$p['job'].($failed[$p['job']] !== '' ? ': '.$failed[$p['job']] : '').')' : 'being drawn (request '.$p['job'].')';
        if (is_string($p['image'])) return 'drawn: image '.$p['image'].((int)$p['drawn'] < $p['rev'] ? ' (the script changed after it was drawn)' : '');
        return 'not drawn yet';
    }

    /** The adaptation at a glance, for the writer. */
    public function outline(string $storyId, string $key, array $failed = []): string
    {
        $s = $this->settings($storyId, $key);
        if (!$s['started']) return "Comic adaptation: not started.\n";
        $chapters = [];
        foreach ($this->stories->chapters($storyId, $key) as $n => $c) $chapters[$c['id']] = $n + 1;
        $style = $this->style($storyId, $key);
        $out = 'Comic adaptation: '.self::settingsLine($s)."\nIts style: ".($style['own'] ? 'its own, "'.$s['style']['text'].'"'.($s['style']['images'] ? '; style images: '.implode(', ', array_column($s['style']['images'], 'image')) : '') : "the story's visual style")."\n";
        $pages = $this->pages($storyId, $key);
        if (!$pages) return $out."Pages: none yet.\n";
        $out .= 'Pages ('.count($pages)."):\n";
        foreach ($pages as $p) $out .= 'Page '.$p['number'].($p['chapter'] !== null && isset($chapters[$p['chapter']]) ? ' (chapter '.$chapters[$p['chapter']].')' : '').': '.$p['summary']
            .' — '.count($p['panels']).' panel'.(count($p['panels']) === 1 ? '' : 's').'; '.self::stateLine($p, $failed)."\n";
        return $out;
    }

    public static function settingsLine(array $s): string
    {
        return (self::KIND_NAMES[$s['kind']] ?? 'manga').', '.($s['color'] === 'bw' ? 'black and white' : 'full color').', read '.($s['reading'] === 'rtl' ? 'right to left' : 'left to right')
            .', pages '.$s['aspect'].' at '.($s['scale'] === '1' ? 'the usual size' : $s['scale'].'× the usual size').'.';
    }

    /** One page's script in full, for the writer. */
    public function scriptText(string $storyId, array $p, string $key, array $failed = []): string
    {
        $chapter = null;
        if ($p['chapter'] !== null) foreach ($this->stories->chapters($storyId, $key) as $n => $c) if ($c['id'] === $p['chapter']) $chapter = $n + 1;
        $out = 'Page '.$p['number'].($chapter !== null ? ' (adapts chapter '.$chapter.')' : '').', revision '.$p['rev'].': '.self::stateLine($p, $failed)."\nSummary: ".$p['summary']."\n";
        $out .= 'Layout: '.($p['sketch'] !== '' ? 'your own sketch (sketch_svg)' : 'the app\'s sketch'.($p['rows'] ? ', rows '.implode(', ', $p['rows']) : ', default rows '.implode(', ', Sketch::defaultRows(count($p['panels'])))))."\n";
        if ($p['notes'] !== '') $out .= 'Notes: '.$p['notes']."\n";
        $labels = []; foreach (self::balloons($p) as $b) $labels[] = $b;
        $at = 0;
        foreach ($p['panels'] as $k => $panel) {
            $out .= "\nPanel ".($k + 1).($panel['framing'] !== '' ? ' — '.$panel['framing'] : '').($panel['place'] !== '' ? ' — place: '.$panel['place'] : '')."\n";
            foreach ($panel['characters'] as $c) $out .= '  '.$c['name'].($c['sheet'] !== '' ? ' [sheet '.$c['sheet'].']' : '').($c['where'] !== '' ? ' ('.$c['where'].')' : '').($c['doing'] !== '' ? ': '.$c['doing'] : '')."\n";
            if ($panel['description'] !== '') $out .= '  '.$panel['description']."\n";
            foreach ($panel['text'] as $t) { $b = $labels[$at++]; $out .= '  '.($b['label'] ?? 'SFX').' '.$t['type'].($t['by'] !== '' ? ', '.$t['by'] : '').': "'.$t['text'].'"'.($t['where'] !== '' ? ' ('.$t['where'].')' : '')."\n"; }
        }
        return $out;
    }
}
