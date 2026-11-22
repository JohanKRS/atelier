<?php
declare(strict_types=1);
namespace Studio;

/**
 * Stories written with a writing agent (Claude or GPT): the text in chapters, the story bible (notes), the author's
 * private plan, and what the agent has already seen of them.
 *
 * Everything with content is sealed with the account's key, one blob per concern so the agent and the person can
 * change different parts at the same time: the story's details (meta), the bible (notes), the plan, the character
 * sheets (cast) and each chapter.
 * A chapter's text is Markdown; an illustration is a line of its own, ![caption](img:ID) when the image is ready or
 * ![caption](job:ID) while it is being made.
 */
final class Stories
{
    public const MAX_TEXT = 200000;      // characters in one chapter, in the notes or in the plan
    public const MAX_SUMMARY = 4000;
    public const MAX_CHAPTERS = 400;
    public const MAX_CHARACTERS = 80;   // characters with a sheet in one story
    public const MAX_SHEETS = 24;       // reference pictures of one character
    public const MAX_STYLE_IMAGES = 4;  // pictures of the story's visual style
    /** What a sheet is of: a character, or a place that comes back. */
    public const SHEET_KINDS = ['character', 'place'];
    /** The kinds of cover: a novel's, an illustrated novel's, a Japanese light novel volume's, a manga volume's, a comic book issue's. */
    public const COVER_FORMATS = ['novel', 'illustrated', 'light_novel', 'manga', 'comic', 'graphic_novel'];
    /** Words that say a story is a light novel (or a web novel, which is dressed the same way). */
    private const LIGHT_NOVEL = '/\\blight[\\s-]?novels?\\b|\\branobe\\b|\\bisekai\\b|\\bweb[\\s-]?novels?\\b|ライトノベル|ラノベ/iu';
    /** The story's bible and plan, and the comic adaptation's own (its workspace, apart from the book's). */
    private const DOCUMENTS = ['notes', 'plan', 'comic_notes', 'comic_plan'];
    private const DOCUMENT_LABELS = ['notes'=>'The story\'s bible', 'plan'=>'The plan', 'comic_notes'=>'The adaptation\'s bible', 'comic_plan'=>'The adaptation\'s plan'];
    /** Whose sheets: the story's (the book's) or the comic adaptation's own. */
    public const SPACES = ['story', 'comic'];
    /** An illustration line: the caption and where the picture is (a library image, or a request still running). */
    public const MARKER = '/^!\[([^\]\n]*)\]\((img|job):([a-f0-9]{32})\)[ \t]*$/m';

    public function __construct(private Vault $vault) {}

    private ?Comics $comics = null;
    /** The story's adaptation as a comic or a manga. */
    public function comics(): Comics { return $this->comics ??= new Comics($this->vault, $this); }

    private ?Vn $vn = null;
    /** A visual novel project's scenes, choices, settings and art (a story whose kind is "vn"). */
    public function vn(): Vn { return $this->vn ??= new Vn($this->vault, $this); }

    /** What a story is: a story to read ("story") or a visual novel project ("vn"). */
    public const KINDS = ['story', 'vn'];

    private function owner(): string { return $this->vault->owner(); }

    private static function newId(): string { return bin2hex(random_bytes(16)); }

    private static function seal(mixed $value, string $key, string $context): string
    { return Crypto::seal(json_encode($value, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $key, $context); }

    private static function open(mixed $blob, string $key, string $context): mixed
    { return $blob === null ? null : json_decode(Crypto::open((string)$blob, $key, $context), true, 512, JSON_THROW_ON_ERROR); }

    /** Text from a person or a model: valid UTF-8, no control characters but tab and newline, Unix line ends. */
    public static function cleanText(mixed $value, int $max, string $what): string
    {
        if (!is_string($value)) throw new HttpError(422, t('{what} must be text.', ['what'=>$what]));
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        if ($value === null) throw new HttpError(422, t('{what} is not valid UTF-8 text.', ['what'=>$what]));
        if (mb_strlen($value) > $max) throw new HttpError(422, t('{what} is over {max} characters.', ['what'=>$what, 'max'=>I18n::number($max)]));
        return $value;
    }

    public static function cleanLine(mixed $value, int $max, string $what): string
    {
        $line = trim(preg_replace('/\s+/u', ' ', self::cleanText($value, $max * 4, $what)) ?? '');
        return mb_strlen($line) > $max ? mb_substr($line, 0, $max) : $line;
    }

    public static function words(string $text): int
    {
        $plain = preg_replace(self::MARKER, '', $text) ?? $text;
        return preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $plain);
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Stories
    // -----------------------------------------------------------------------------------------------------------------

    private function row(string $id): array
    {
        Vault::validId($id);
        $q = $this->vault->db->prepare('SELECT * FROM stories WHERE id=? AND user_id=?'); $q->execute([$id, $this->owner()]);
        $r = $q->fetch(); $q->closeCursor();
        if (!$r) throw new HttpError(404, t('Story not found.'));
        return $r;
    }

    /** The story's row, for the comic adaptation (404 when it isn't the account's). */
    public function rowOf(string $id): array { return $this->row($id); }

    public function exists(string $id): bool
    { try { $this->row($id); return true; } catch (HttpError) { return false; } }

    private function meta(array $row, string $key): array
    {
        $m = self::open($row['meta'], $key, 'story:'.$row['id']);
        return (is_array($m) ? $m : []) + ['title'=>'', 'synopsis'=>'', 'series'=>'', 'cover'=>null, 'writer'=>null];
    }

    /** A document of the story (the bible or the plan): its text, who changed it last, when, and its revision. */
    private function document(array $row, string $name, string $key): array
    {
        $d = self::open($row[$name] ?? null, $key, "story-$name:".$row['id']);
        return (is_array($d) ? $d : []) + ['text'=>'', 'by'=>null, 'at'=>null, 'rev'=>0];
    }

    /** A new story, or a new visual novel project ($kind "vn"). */
    public function create(string $title, string $key, string $kind = 'story'): array
    {
        if (!in_array($kind, self::KINDS, true)) throw new HttpError(422, t('Invalid story kind.'));
        $id = self::newId(); $now = time();
        $title = self::cleanLine($title, 200, t('The title'));
        $this->vault->db->prepare('INSERT INTO stories(id,user_id,meta,created,updated) VALUES(?,?,CAST(? AS BLOB),?,?)')
            ->execute([$id, $this->owner(), self::seal(['title'=>$title, 'synopsis'=>'', 'series'=>'', 'cover'=>null] + ($kind === 'vn' ? ['kind'=>'vn'] : []), $key, "story:$id"), $now, $now]);
        return $this->summary($this->row($id), $key);
    }

    /** Whether the story is a visual novel project. */
    public function isVn(array $row, string $key): bool { return ($this->meta($row, $key)['kind'] ?? 'story') === 'vn'; }

    /** The story's kind, by its id. */
    public function kind(string $id, string $key): string { return $this->isVn($this->row($id), $key) ? 'vn' : 'story'; }

    /** What the list of stories shows about each one. */
    private function summary(array $row, string $key, ?array $chapters = null): array
    {
        $m = $this->meta($row, $key);
        $chapters ??= $this->chapterRows($row['id'], $key);
        $words = 0; $cover = $m['cover']; $images = 0;
        foreach ($chapters as $c) {
            $words += self::words($c['text']);
            if (preg_match_all(self::MARKER, $c['text'], $found, PREG_SET_ORDER)) foreach ($found as $f) if ($f[2] === 'img') { $images++; $cover ??= $f[3]; }
        }
        // The main picture of each character with a sheet: what a cover made from the page can take as references.
        $faces = []; $cast = $this->castDocument($row, $key);
        foreach ($cast['characters'] as $c) if ($c['kind'] === 'character' && is_string($c['images'][0]['image'] ?? null)) $faces[] = ['name'=>$c['name'], 'image'=>$c['images'][0]['image']];
        // A visual novel project counts its scenes too.
        $kind = ($m['kind'] ?? 'story') === 'vn' ? 'vn' : 'story'; $scenes = 0;
        if ($kind === 'vn') { $q = $this->vault->db->prepare('SELECT COUNT(*) FROM vn_scenes WHERE story_id=?'); $q->execute([$row['id']]); $scenes = (int)$q->fetchColumn(); $q->closeCursor(); }
        // The cover's size in pixels, [width, height]: the list shows the whole cover at one height for all, so each card
        // is as wide as its cover's shape, known before the picture loads. null when there is none or it is gone.
        $size = null;
        if (is_string($cover)) {
            try { $it = $this->vault->image($cover, $key, true); if ((int)$it['width'] > 0 && (int)$it['height'] > 0) $size = [(int)$it['width'], (int)$it['height']]; }
            catch (HttpError) {}
        }
        // cover is what the list shows (the chosen one, else the first illustration); cover_chosen whether one was chosen.
        return ['id'=>$row['id'], 'title'=>$m['title'], 'series'=>$m['series'], 'synopsis'=>$m['synopsis'], 'cover'=>$cover, 'cover_size'=>$size, 'cover_chosen'=>is_string($m['cover']), 'cover_choices'=>$this->coverChoices($m, $key), 'faces'=>$faces,
            'cover_design'=>$this->coverDesign($row, $m, $images, $cast, $key), 'cover_by'=>$m['cover_by'] ?? null, 'kind'=>$kind, 'scenes'=>$scenes, 'chapters'=>count($chapters), 'words'=>$words, 'images'=>$images, 'favorite'=>(bool)$row['favorite'],
            'created'=>(int)$row['created'], 'updated'=>(int)$row['updated'], 'writer'=>$m['writer'], 'cover_job'=>$m['cover_job'] ?? null];
    }

    /** How many covers a story keeps to choose from, the newest first. */
    private const COVER_CHOICES = 12;

    /**
     * The covers to choose from, the newest first: each made in a design direction (style; 'before' for the cover the
     * story had when they were asked for), with its request (job, and batch: the set it was asked with) and its image
     * when it is ready (with its size). A picture that was deleted is left out.
     * @return list<array{job:?string,batch:?string,style:string,image:?string,size:?array}>
     */
    private function coverChoices(array $m, string $key): array
    {
        $out = [];
        foreach (is_array($m['cover_choices'] ?? null) ? $m['cover_choices'] : [] as $c) {
            $size = null;
            if (is_string($c['image'] ?? null)) {
                try { $it = $this->vault->image($c['image'], $key); $size = [(int)$it['width'], (int)$it['height']]; }
                catch (HttpError) { continue; }
            }
            $out[] = ['job'=>$c['job'] ?? null, 'batch'=>$c['batch'] ?? null, 'style'=>(string)($c['style'] ?? ''), 'image'=>$c['image'] ?? null, 'size'=>$size];
        }
        return $out;
    }

    /**
     * How a cover made for the story is designed: its format (the one the person chose, else one read from the story:
     * the comic adaptation's kind, a light novel the story speaks of, its anime or manga look, illustrated or text only)
     * and the words lettered on it besides the title (the author's name and the volume, '' for none). why: what the
     * automatic format was read from (comic, light_novel, style, illustrated, text).
     */
    private function coverDesign(array $row, array $m, int $images, array $cast, string $key): array
    {
        $saved = is_array($m['cover_design'] ?? null) ? $m['cover_design'] : [];
        $comic = $row['comic'] !== null ? self::open($row['comic'], $key, 'story-comic:'.$row['id']) : null;
        $style = $cast['style']['text'];
        if (is_array($comic)) [$auto, $why] = [in_array($comic['kind'] ?? null, Comics::KINDS, true) ? $comic['kind'] : 'manga', 'comic'];
        elseif (preg_match(self::LIGHT_NOVEL, implode("\n", [$m['title'], $m['series'], $m['synopsis'], $style,
            $this->document($row, 'notes', $key)['text'], $this->document($row, 'plan', $key)['text']]))) [$auto, $why] = ['light_novel', 'light_novel'];
        elseif (preg_match('/\b(anime|mang[aá]|cel[\s-]?shad)/iu', $style)) [$auto, $why] = ['light_novel', 'style'];
        elseif ($images > 0 || ($m['writer']['illustrate'] ?? 'ask') === 'each' || $style !== '' || $cast['style']['images']) [$auto, $why] = ['illustrated', 'illustrated'];
        else [$auto, $why] = ['novel', 'text'];
        $chosen = in_array($saved['format'] ?? null, self::COVER_FORMATS, true) ? $saved['format'] : null;
        $format = $chosen ?? $auto;
        // No volume unless the person typed one: a story is one book, a light novel, a manga or a comic too. Designs saved
        // before that (without v) hold the "1" those were given on their own, which goes.
        $volume = is_string($saved['volume'] ?? null) ? $saved['volume'] : '';
        if (!isset($saved['v']) && $volume === '1') $volume = '';
        return ['format'=>$format, 'chosen'=>$chosen, 'auto'=>$auto, 'why'=>$why, 'author'=>is_string($saved['author'] ?? null) ? $saved['author'] : '', 'volume'=>$volume];
    }

    /** The cover's design as the person sets it: a format (null: read from the story), the author's name, the volume. */
    public static function cleanCoverDesign(mixed $d): array
    {
        $d = is_array($d) ? $d : [];
        $format = $d['format'] ?? null;
        if ($format === '') $format = null;
        if ($format !== null && !in_array($format, self::COVER_FORMATS, true)) throw new HttpError(422, t('Invalid cover kind.'));
        $author = self::cleanLine($d['author'] ?? '', 80, t('The author\'s name'));
        $volume = trim(preg_replace('/^(volume\b|vol\b\.?|n\.?[º°]|no\.|#|edição\b|edicao\b|issue\b)\s*/iu', '', self::cleanLine($d['volume'] ?? '', 40, t('The volume'))) ?? '');
        if (mb_strlen($volume) > 12) throw new HttpError(422, t('The volume is over 12 characters.'));
        return ['format'=>$format, 'author'=>$author, 'volume'=>$volume, 'v'=>2];
    }

    /**
     * @return list<array> every story of the account, newest change first. With $jobs, a cover being made that is ready
     * becomes the cover first, and one still being made says how it goes (cover_making: queued, running or failed).
     */
    public function all(string $key, ?Jobs $jobs = null): array
    {
        $q = $this->vault->db->prepare('SELECT * FROM stories WHERE user_id=? ORDER BY updated DESC'); $q->execute([$this->owner()]);
        $rows = $q->fetchAll(); $q->closeCursor();
        $list = array_map(fn($r) => $this->summary($r, $key), $rows);
        if ($jobs === null) return $list;
        foreach ($list as &$s) {
            $waiting = array_filter($s['cover_choices'], fn($c) => $c['image'] === null && $c['job'] !== null);
            if ($s['cover_job'] === null && !$waiting && !$this->edits($s['id'], $key)) continue;
            $found = $this->resolveIllustrations($s['id'], $key, $jobs);
            $s = $this->summary($this->row($s['id']), $key);
            foreach ($s['cover_choices'] as &$c) if ($c['image'] === null && $c['job'] !== null) {
                $job = $found[$c['job']] ?? null;
                $c += ['status'=>$job !== null && in_array($job['status'], ['queued', 'running', 'cancelling'], true) ? $job['status'] : 'failed', 'error'=>$job['error'] ?? ''];
            }
            unset($c);
            // A set of covers is being made while one of them is; it failed when all of them did.
            $set = array_values(array_filter($s['cover_choices'], fn($c) => $s['cover_job'] !== null && $c['batch'] === $s['cover_job'] && $c['image'] === null));
            if ($set) {
                $status = array_column($set, 'status');
                $s += ['cover_making'=>in_array('running', $status, true) || in_array('cancelling', $status, true) ? 'running' : (in_array('queued', $status, true) ? 'queued' : 'failed'),
                    'cover_error'=>(string)($set[0]['error'] ?? '')];
                continue;
            }
            $making = $s['cover_job'] ?? ($s['cover_chosen'] ? $this->coverEdit($s['id'], $s['cover'], $key) : null);
            $job = $making !== null ? ($found[$making] ?? null) : null;
            if ($job !== null) $s += ['cover_making'=>in_array($job['status'], ['queued', 'running', 'cancelling'], true) ? $job['status'] : 'failed', 'cover_error'=>$job['error']];
        }
        unset($s);
        return $list;
    }

    /**
     * The whole story for the page: details, bible, chapters with their text, and the plan only when asked for (it
     * holds the spoilers). Illustrations whose image is ready are written into the text first.
     */
    public function get(string $id, string $key, ?Jobs $jobs = null, bool $withPlan = false): array
    {
        if ($jobs !== null) $this->resolveIllustrations($id, $key, $jobs);
        $row = $this->row($id); $chapters = $this->chapterRows($id, $key);
        $notes = $this->document($row, 'notes', $key); $plan = $this->document($row, 'plan', $key);
        $story = array_merge($this->summary($row, $key, $chapters), [
            'notes'=>['text'=>$notes['text'], 'by'=>$notes['by'], 'at'=>$notes['at'], 'rev'=>$notes['rev']],
            'plan'=>['words'=>self::words($plan['text']), 'by'=>$plan['by'], 'at'=>$plan['at'], 'rev'=>$plan['rev']] + ($withPlan ? ['text'=>$plan['text']] : []),
            'conversation'=>(int)$row['conversation'], 'reading'=>$this->meta($row, $key)['reading'] ?? null, 'cast'=>$this->castDocument($row, $key),
            'albums'=>$this->albums($id, $key),
            'editing'=>array_map(fn($e) => ['image'=>$e['image'], 'failed'=>!empty($e['failed'])], $this->edits($id, $key)),
            'chapters'=>array_map(fn($c) => ['id'=>$c['id'], 'title'=>$c['title'], 'text'=>$c['text'], 'summary'=>$c['summary'], 'rev'=>$c['rev'],
                'by'=>$c['by'], 'updated'=>$c['updated'], 'words'=>self::words($c['text'])], $chapters),
        ]);
        return $story;
    }

    /** When the story last changed, for the page to know whether to reload it. */
    public function stamp(string $id): int { return (int)$this->row($id)['updated']; }

    public function touch(string $id): void
    { $this->vault->db->prepare('UPDATE stories SET updated=MAX(updated+1,?) WHERE id=? AND user_id=?')->execute([time(), $id, $this->owner()]); }

    /**
     * Changes the story's details: title, synopsis, series, cover image (null removes it), how its covers are designed
     * (cover_design: format, author, volume) and the writer last chosen.
     */
    public function update(string $id, array $fields, string $key): array
    {
        $this->transaction(function () use ($id, $fields, $key) {
            $row = $this->row($id); $m = $this->meta($row, $key);
            if (array_key_exists('title', $fields)) $m['title'] = self::cleanLine($fields['title'], 200, t('The title'));
            if (array_key_exists('series', $fields)) $m['series'] = self::cleanLine($fields['series'], 120, t('The series'));
            if (array_key_exists('synopsis', $fields)) $m['synopsis'] = trim(self::cleanText($fields['synopsis'], 6000, t('The synopsis')));
            if (array_key_exists('cover', $fields)) {
                $cover = $fields['cover'];
                if ($cover !== null) { $cover = (string)$cover; $this->vault->still($cover, $key); }
                $m['cover'] = $cover; $m['cover_by'] = 'person'; unset($m['cover_job'], $m['cover_job_by']);
            }
            if (array_key_exists('writer', $fields) && is_array($fields['writer'])) $m['writer'] = $fields['writer'];
            if (array_key_exists('cover_design', $fields)) $m['cover_design'] = self::cleanCoverDesign($fields['cover_design']);
            $this->vault->db->prepare('UPDATE stories SET meta=CAST(? AS BLOB) WHERE id=?')->execute([self::seal($m, $key, "story:$id"), $id]);
            // The story's albums follow its title.
            if (array_key_exists('title', $fields)) $this->renameAlbums($id, $key);
            $this->touch($id);
        });
        return $this->summary($this->row($id), $key);
    }

    // -----------------------------------------------------------------------------------------------------------------
    // The story's albums in the library: one for its pictures (space book: the covers, the illustrations, the sheets,
    // the style and lettering pictures, a visual novel's sprites, backgrounds and CGs) and one for its comic adaptation
    // (space comic: the pages and the adaptation's own sheets and lettering). Every picture made for the story goes into
    // one of them as it is saved, and they keep their pictures out of the library: the library shows the person's own
    // pictures, and each album opens from it with everything of its story. A library picture the story only uses (a
    // cover chosen from the gallery) stays in the library.
    // -----------------------------------------------------------------------------------------------------------------
    public const ALBUM_SPACES = ['book'=>'album', 'comic'=>'comic_album'];

    /** The album's name: the story's title, and for the comic's the kind of comic. */
    private function albumName(string $id, array $m, string $space, string $key): string
    {
        $title = (string)($m['title'] ?? '') !== '' ? (string)$m['title'] : t('Untitled story');
        if ($space !== 'comic') return mb_substr($title, 0, 80);
        $title = mb_substr($title, 0, 60);
        return mb_substr(match ($this->comics()->settings($id, $key)['kind']) {
            'comic' => t('{title} (comic book)', ['title'=>$title]),
            'graphic_novel' => t('{title} (graphic novel)', ['title'=>$title]),
            default => t('{title} (manga)', ['title'=>$title]) }, 0, 80);
    }

    /** The story's albums take its title again (and the comic's its kind). */
    public function renameAlbums(string $id, string $key): void
    {
        $m = $this->meta($this->row($id), $key);
        foreach (self::ALBUM_SPACES as $space => $field)
            if (is_string($m[$field] ?? null) && $this->vault->hasAlbum($m[$field])) $this->vault->renameAlbum($m[$field], $this->albumName($id, $m, $space, $key), $key);
    }

    /** The setting (with the account's id) that says the account's albums were adopted, or when that started. */
    public const ADOPTED = 'albums-apart:';

    /**
     * Once per account, the albums the stories made before their albums kept their pictures out of the library: each
     * one now says which story made it and keeps its pictures out of the library, and a comic adaptation gets an album of
     * its own with its pages and sheets (moved out of the story's; a sheet the book uses too stays in both). A library
     * picture the album was made with (a cover chosen from the gallery before the story had any picture of its own) goes
     * back to the library: it only stays when it was made for the story or came with a story brought from a file.
     * Runs in the first request of the account that needs it; a request at the same time doesn't wait for it.
     */
    public function adoptAlbums(string $key): void
    {
        $name = self::ADOPTED.$this->owner();
        if ($this->vault->get($name) === 'done') return;
        $claimed = $this->transaction(function () use ($name) {
            $state = $this->vault->get($name);
            if ($state === 'done' || ($state !== null && (int)$state > time() - 600)) return false;
            $this->vault->set($name, (string)time());
            return true;
        });
        if (!$claimed) return;
        $q = $this->vault->db->prepare('SELECT id FROM stories WHERE user_id=? ORDER BY created'); $q->execute([$this->owner()]);
        foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            try { $this->adoptStoryAlbums((string)$id, $key); }
            catch (\Throwable $e) { logFailure($e); }
        }
        $this->vault->set($name, 'done');
    }

    private const STORYBOARD = 'rough storyboard sketch of this page';
    private const ADAPTATION_SHEET = "A reference picture for the story's ";

    private function adoptStoryAlbums(string $id, string $key): void
    {
        $row = $this->row($id); $m = $this->meta($row, $key);
        $album = is_string($m['album'] ?? null) && $this->vault->hasAlbum($m['album']) ? $m['album'] : null;
        if ($album === null || $this->vault->albumOrigin($album, $key)['kind'] !== null) return;
        $this->vault->adoptAlbum($album, ['kind'=>($m['kind'] ?? 'story') === 'vn' ? 'vn' : 'story', 'story'=>$id], $key);
        // The pictures gathered when the album was made (the ones the story had then), apart from those made for it.
        $added = $this->vault->albumAdded($album); $created = (int)$this->vault->albumCreated($album);
        $gathered = array_keys(array_filter($added, fn($at) => $at <= $created + 5));
        $made = $gathered ? $this->vault->madeForAlbums($gathered, $key) : [];
        $back = [];
        foreach ($gathered as $image) {
            if (isset($made[$image])) continue;
            try { $it = $this->vault->image($image, $key, true); } catch (HttpError) { continue; }
            // Brought with the story from a file: imported right before the album was made.
            if (in_array(Vault::operation($it), ['import', 'frame'], true) && $it['created'] >= $created - 3600 && $it['created'] <= $created + 5) continue;
            $back[] = $image;
        }
        if ($back) $this->vault->bulk('album-remove', $back, $album);
        if (($row['comic'] ?? null) === null) return;
        // The comic's pictures: its pages and sheets as they are now, the pages and adaptation sheets the story's album
        // holds (older versions too), and every version made from one of them.
        $items = [];
        foreach (array_keys(array_diff_key($added, array_flip($back))) as $image) {
            try { $items[$image] = $this->vault->image($image, $key, true); } catch (HttpError) {}
        }
        $comic = array_fill_keys(array_intersect($this->pictures($id, 'comic', $key), array_keys($items)), true);
        foreach ($items as $image => $it) {
            $prompt = (string)($it['prompt'] ?? '');
            if (str_contains($prompt, self::STORYBOARD) || str_contains($prompt, self::ADAPTATION_SHEET)) $comic[$image] = true;
        }
        do {
            $more = false;
            foreach ($items as $image => $it) {
                $parent = Vault::lineageParent($it);
                if (!isset($comic[$image]) && $parent !== null && isset($comic[$parent])) { $comic[$image] = true; $more = true; }
            }
        } while ($more);
        if (!$comic) return;
        $comicAlbum = $this->album($id, $key, 'comic');
        $this->vault->bulk('album-add', array_keys($comic), $comicAlbum);
        $book = array_flip($this->pictures($id, 'book', $key));
        $moved = array_keys(array_diff_key($comic, $book));
        if ($moved) $this->vault->bulk('album-remove', $moved, $album);
    }

    /** The ids of the story's albums that exist: ['book'=>?id, 'comic'=>?id]. */
    public function albums(string $id, string $key): array
    {
        $m = $this->meta($this->row($id), $key); $out = [];
        foreach (self::ALBUM_SPACES as $space => $field) $out[$space] = is_string($m[$field] ?? null) && $this->vault->hasAlbum($m[$field]) ? $m[$field] : null;
        return $out;
    }

    /**
     * The pictures the story uses in a space, as they are now: for the book the cover and the covers to choose from,
     * the illustrations, the sheets, the style and the lettering, and a visual novel's art; for the comic its pages and
     * the adaptation's sheets, style and lettering.
     * @return list<string>
     */
    public function pictures(string $id, string $space, string $key): array
    {
        $row = $this->row($id); $m = $this->meta($row, $key); $images = [];
        $castImages = function (array $cast) use (&$images) {
            foreach ([...$cast['characters'], $cast['style']] as $c) foreach ($c['images'] as $im) if (is_string($im['image'] ?? null)) $images[] = $im['image'];
            if (is_string($cast['style']['lettering']['image'] ?? null)) $images[] = $cast['style']['lettering']['image'];
        };
        if ($space === 'comic') {
            if (($row['comic'] ?? null) === null) return [];
            foreach ($this->comics()->pages($id, $key) as $p) if (is_string($p['image'])) $images[] = $p['image'];
            $castImages($this->castDocument($row, $key, 'comic'));
            foreach ($this->comics()->settings($id, $key)['style']['images'] as $im) if (is_string($im['image'] ?? null)) $images[] = $im['image'];
        } else {
            if (is_string($m['cover'])) $images[] = $m['cover'];
            foreach (is_array($m['cover_choices'] ?? null) ? $m['cover_choices'] : [] as $c) if (is_string($c['image'] ?? null)) $images[] = $c['image'];
            foreach ($this->chapterRows($id, $key) as $c) if (preg_match_all(self::MARKER, $c['text'], $found, PREG_SET_ORDER)) foreach ($found as $f) if ($f[2] === 'img') $images[] = $f[3];
            $castImages($this->castDocument($row, $key));
            if (($m['kind'] ?? 'story') === 'vn') foreach ($this->vn()->art($id, $key) as $list) if (is_array($list) && array_is_list($list)) foreach ($list as $a) if (is_string($a['image'] ?? null)) $images[] = $a['image'];
        }
        return array_values(array_unique(array_filter($images, fn($im) => (bool)preg_match('/\A[a-f0-9]{32}\z/D', $im))));
    }

    /**
     * The story's album of a space (book or comic), where its pictures go. It is made the first time it is needed (and
     * again if the person deleted it), kept out of the library, and made with the pictures the story already has that
     * were made for it, and those of $brought (the pictures a story brought from a file sent to the gallery). A picture
     * of the library the story only uses stays there. A story's copy makes its own the first time.
     * @param list<string> $brought
     */
    public function album(string $id, string $key, string $space = 'book', array $brought = []): string
    {
        $field = self::ALBUM_SPACES[$space] ?? throw new \LogicException('Unknown album space.');
        [$album, $images] = $this->transaction(function () use ($id, $key, $space, $field) {
            $m = $this->meta($this->row($id), $key);
            if (isset($m[$field]) && $this->vault->hasAlbum($m[$field])) return [$m[$field], []];
            $first = !isset($m[$field]);
            $kind = $space === 'comic' ? 'comic' : (($m['kind'] ?? 'story') === 'vn' ? 'vn' : 'story');
            $album = $this->vault->createAlbum($this->albumName($id, $m, $space, $key), $key, ['kind'=>$kind, 'story'=>$id])['id'];
            $m[$field] = $album;
            $this->vault->db->prepare('UPDATE stories SET meta=CAST(? AS BLOB) WHERE id=?')->execute([self::seal($m, $key, "story:$id"), $id]);
            // Made again after the person deleted it: only what comes next goes into it, the rest stays in the library.
            return [$album, $first ? $this->pictures($id, $space, $key) : []];
        });
        if ($images) { $made = $this->vault->madeForAlbums($images, $key); $brought = array_flip($brought); $images = array_values(array_filter($images, fn($im) => isset($made[$im]) || isset($brought[$im]))); }
        // Adding is a transaction of its own in the vault.
        if ($images) $this->vault->bulk('album-add', $images, $album);
        return $album;
    }

    /**
     * Where the person stopped reading, to open the story there next time, on any device: the chapter, the block of its
     * text at the top of the screen and how far into that block. It doesn't count as a change to the story (the list
     * keeps its order and an open page doesn't reload).
     */
    public function setReading(string $id, mixed $place, string $key): void
    {
        if (!is_array($place) || !is_string($place['chapter'] ?? null)) throw new HttpError(422, t('Invalid reading point.'));
        Vault::validId($place['chapter']);
        $reading = ['chapter'=>$place['chapter'], 'block'=>max(0, min(100000, (int)($place['block'] ?? 0))),
            'offset'=>round(max(0.0, min(1.0, (float)($place['offset'] ?? 0))), 3), 'at'=>time()];
        $this->transaction(function () use ($id, $reading, $key) {
            $m = $this->meta($this->row($id), $key); $m['reading'] = $reading;
            $this->vault->db->prepare('UPDATE stories SET meta=CAST(? AS BLOB) WHERE id=?')->execute([self::seal($m, $key, "story:$id"), $id]);
        });
    }

    public function favorite(string $id, bool $on): void
    { $this->row($id); $this->vault->db->prepare('UPDATE stories SET favorite=? WHERE id=?')->execute([$on ? 1 : 0, $id]); }

    /** Removes the story, its chapters and its conversation. Its pictures stay in its albums, which stay in the library. */
    public function delete(string $id): void
    {
        $this->row($id);
        $this->vault->db->prepare('DELETE FROM stories WHERE id=? AND user_id=?')->execute([$id, $this->owner()]);
    }

    /**
     * A blob sealed for one row, opened and sealed again for another, with the ids of the copied rows changed to their
     * copies' ($map: old id => new id). Ids are 32 random hex characters, so a replacement never lands on anything else.
     */
    private static function reseal(mixed $blob, string $key, string $from, string $to, array $map): ?string
    { return $blob === null ? null : Crypto::seal(strtr(Crypto::open((string)$blob, $key, $from), $map), $key, $to); }

    /**
     * A copy of the story the person can change on its own: its details (with the new title), the bible, the plan, the
     * sheets and style, the chapters, the comic adaptation and a visual novel's scenes and art, each row new and sealed for itself. The pictures are the
     * same library images, which never change (a new version of one is a new image, put in place only in the story
     * that asked for it), so nothing is copied in the gallery. Requests still making pictures go to both. The albums,
     * where the reader stopped and the favorite mark stay with the original: the copy makes its albums when it needs them.
     * Runs inside the caller's transaction, which copies the conversation with the writer with the returned map.
     * @return array{id:string,map:array<string,string>,title:string} the copy's id, the ids changed (story, chapters,
     *         pages) and the original's title
     */
    public function copy(string $id, mixed $title, string $key): array
    {
        return $this->transaction(function () use ($id, $title, $key) {
            $row = $this->row($id); $new = self::newId(); $now = time();
            $map = [$id=>$new];
            $q = $this->vault->db->prepare('SELECT * FROM chapters WHERE story_id=? AND user_id=? ORDER BY position, created'); $q->execute([$id, $this->owner()]);
            $chapters = $q->fetchAll(); $q->closeCursor();
            $q = $this->vault->db->prepare('SELECT * FROM comic_pages WHERE story_id=? AND user_id=? ORDER BY position, created'); $q->execute([$id, $this->owner()]);
            $pages = $q->fetchAll(); $q->closeCursor();
            $q = $this->vault->db->prepare('SELECT * FROM vn_scenes WHERE story_id=? AND user_id=? ORDER BY position, created'); $q->execute([$id, $this->owner()]);
            $scenes = $q->fetchAll(); $q->closeCursor();
            foreach ([...$chapters, ...$pages, ...$scenes] as $r) $map[$r['id']] = self::newId();
            $m = self::open($row['meta'], $key, "story:$id"); $m = is_array($m) ? $m : [];
            $original = (string)($m['title'] ?? '');
            $title = self::cleanLine($title ?? '', 200, t('The title'));
            $m['title'] = $title !== '' ? $title : mb_substr(t('{title} (copy)', ['title'=>$original !== '' ? $original : t('Untitled story')]), 0, 200);
            unset($m['album'], $m['comic_album'], $m['reading']);
            $meta = Crypto::seal(strtr(json_encode($m, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $map), $key, "story:$new");
            $comic = null;
            if ($row['comic'] !== null) {
                $s = self::open($row['comic'], $key, "story-comic:$id");
                if (is_array($s)) $s['reading_page'] = null;
                $comic = Crypto::seal(strtr(json_encode($s, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $map), $key, "story-comic:$new");
            }
            $this->vault->db->prepare('INSERT INTO stories(id,user_id,meta,notes,plan,cast,comic,vn,vn_art,comic_notes,comic_plan,comic_cast,created,updated,favorite,conversation) VALUES(?,?,CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),?,?,0,?)')
                ->execute([$new, $this->owner(), $meta, self::reseal($row['notes'], $key, "story-notes:$id", "story-notes:$new", $map),
                    self::reseal($row['plan'], $key, "story-plan:$id", "story-plan:$new", $map), self::reseal($row['cast'] ?? null, $key, "story-cast:$id", "story-cast:$new", $map),
                    $comic, self::reseal($row['vn'] ?? null, $key, "story-vn:$id", "story-vn:$new", $map), self::reseal($row['vn_art'] ?? null, $key, "story-vn-art:$id", "story-vn-art:$new", $map),
                    self::reseal($row['comic_notes'] ?? null, $key, "story-comic_notes:$id", "story-comic_notes:$new", $map), self::reseal($row['comic_plan'] ?? null, $key, "story-comic_plan:$id", "story-comic_plan:$new", $map),
                    self::reseal($row['comic_cast'] ?? null, $key, "story-comic-cast:$id", "story-comic-cast:$new", $map),
                    $now, $now, (int)$row['conversation']]);
            foreach (['chapters'=>[$chapters, 'chapter:'], 'comic_pages'=>[$pages, 'comic-page:'], 'vn_scenes'=>[$scenes, 'vn-scene:']] as $table => [$rows, $context])
                foreach ($rows as $r) $this->vault->db->prepare("INSERT INTO $table(id,story_id,user_id,position,body,rev,created,updated) VALUES(?,?,?,?,CAST(? AS BLOB),?,?,?)")
                    ->execute([$map[$r['id']], $new, $this->owner(), $r['position'], self::reseal($r['body'], $key, $context.$r['id'], $context.$map[$r['id']], $map), $r['rev'], $r['created'], $r['updated']]);
            return ['id'=>$new, 'map'=>$map, 'title'=>$original];
        });
    }

    /** What the list shows of a story. */
    public function listed(string $id, string $key): array { return $this->summary($this->row($id), $key); }

    /** @return array<string,array{title:string,albums:list<string>}> the title and albums of every story of the account (for the history). */
    public function titles(string $key): array
    {
        $q = $this->vault->db->prepare('SELECT id,meta FROM stories WHERE user_id=?'); $q->execute([$this->owner()]);
        $out = [];
        foreach ($q->fetchAll() as $r) {
            $m = $this->meta($r, $key);
            $out[$r['id']] = ['title'=>(string)$m['title'], 'albums'=>array_values(array_filter([$m['album'] ?? null, $m['comic_album'] ?? null], 'is_string'))];
        }
        return $out;
    }

    /**
     * How the account's other stories are built, for the writer planning a new one, so that it builds it differently:
     * each one's title, series, kind, chapters and the Architecture section of its plan (else the start of its plan),
     * the latest changed first. Stories with no plan are left out, and so is a copy that reads as one already listed.
     * @return list<array{title:string,series:string,kind:string,chapters:int,architecture:string,found:bool}>
     */
    public function architectures(string $except, string $key, int $limit = 12): array
    {
        $q = $this->vault->db->prepare('SELECT s.*, (SELECT COUNT(*) FROM chapters c WHERE c.story_id=s.id) AS chapter_count FROM stories s WHERE s.user_id=? AND s.id<>? ORDER BY s.updated DESC');
        $q->execute([$this->owner(), $except]); $rows = $q->fetchAll(); $q->closeCursor();
        $out = []; $listed = [];
        foreach ($rows as $r) {
            $plan = $this->document($r, 'plan', $key)['text'];
            if (trim($plan) === '') continue;
            [$text, $found] = self::architecture($plan);
            if (isset($listed[$text])) continue;
            $listed[$text] = true; $m = $this->meta($r, $key);
            $out[] = ['title'=>(string)$m['title'], 'series'=>(string)$m['series'], 'kind'=>($m['kind'] ?? 'story') === 'vn' ? 'vn' : 'story',
                'chapters'=>(int)$r['chapter_count'], 'architecture'=>$text, 'found'=>$found];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /**
     * The Architecture section of a plan (how the story is built, which the writer keeps at the top of it) and whether
     * the plan has one; else the plan's start. At most $max characters, cut at a line.
     * @return array{0:string,1:bool}
     */
    public static function architecture(string $plan, int $max = 1500): array
    {
        $text = '';
        // A heading (## Architecture, **Architecture**) or the word alone on its line; Arquitetura in a plan in Portuguese.
        if (preg_match('/^[ \t]*(?:(#{1,6})[ \t]*|\*\*)?(?:architecture|arquitetura)\b(?(1)[^\n]*|(?:\*\*)?[ \t]*:?(?:\*\*)?[ \t]*)$/imu', $plan, $m, PREG_OFFSET_CAPTURE)) {
            $rest = substr($plan, $m[0][1] + strlen($m[0][0]));
            // To the next heading of the same level or above (any heading when this one isn't one).
            $level = strlen($m[1][0] ?? '');
            if (preg_match('/^[ \t]*#{1,'.($level ?: 6).'}[ \t]/m', $rest, $n, PREG_OFFSET_CAPTURE)) $rest = substr($rest, 0, $n[0][1]);
            $text = trim($rest);
        }
        $found = $text !== '';
        if (!$found) $text = trim($plan);
        if (mb_strlen($text) > $max) {
            $cut = mb_substr($text, 0, $max); $line = mb_strrpos($cut, "\n");
            $text = rtrim($line !== false && $line > $max / 2 ? mb_substr($cut, 0, $line) : $cut).' …';
        }
        return [$text, $found];
    }

    // -----------------------------------------------------------------------------------------------------------------
    // The whole story in a file (the page makes the zip) and back
    // -----------------------------------------------------------------------------------------------------------------

    /** The version of the file's shape. */
    public const PACK_VERSION = 1; // a visual novel project's part (vn) came later, as an optional part of the same shape
    private const BY = ['agent', 'person', 'system', 'mcp'];

    /**
     * Everything of the story for a file the person keeps or takes to another app: its details, the bible, the plan, the
     * chapters with their illustrations, the sheets and the style, and the comic. Pictures are library image ids (the
     * page puts the images themselves in the file); requests still making one are left out. A page's chapter is its
     * number. The writer's conversation is Writer::exportConversation.
     */
    public function export(string $id, string $key, ?Jobs $jobs = null): array
    {
        if ($jobs !== null) $this->resolveIllustrations($id, $key, $jobs);
        $row = $this->row($id); $m = $this->meta($row, $key);
        $chapters = $this->chapterRows($id, $key); $numbers = [];
        foreach ($chapters as $n => $c) $numbers[$c['id']] = $n + 1;
        $doc = fn(string $name) => array_intersect_key($this->document($row, $name, $key), array_flip(['text', 'rev', 'by']));
        // The sheets as kept, without the requests still making pictures.
        $ready = function (array $cast): array {
            foreach ($cast['characters'] as &$c) $c['images'] = array_values(array_filter($c['images'], fn($im) => is_string($im['image'] ?? null)));
            unset($c);
            $cast['style']['lettering']['job'] = null;
            return $cast;
        };
        $cast = $ready($this->castDocument($row, $key));
        $comic = $this->comics()->settings($id, $key);
        $withoutJobs = fn(string $text) => trim(preg_replace('/^!\[[^\]\n]*\]\(job:[a-f0-9]{32}\)[ \t]*$/m', '', $text) ?? $text);
        $pack = ['format'=>'atelier-story', 'version'=>self::PACK_VERSION, 'exported_at'=>gmdate('c'),
            'story'=>['id'=>$id, 'title'=>$m['title'], 'series'=>$m['series'], 'synopsis'=>$m['synopsis'], 'cover'=>is_string($m['cover']) ? $m['cover'] : null,
                'cover_by'=>$m['cover_by'] ?? null, 'cover_design'=>$m['cover_design'] ?? null, 'writer'=>$m['writer'], 'created'=>(int)$row['created'], 'kind'=>$this->isVn($row, $key) ? 'vn' : 'story'],
            'notes'=>$doc('notes'), 'plan'=>$doc('plan'),
            'chapters'=>array_map(fn($c) => ['id'=>$c['id'], 'title'=>$c['title'], 'summary'=>$c['summary'], 'text'=>preg_replace('/\n{3,}/', "\n\n", $withoutJobs($c['text'])),
                'rev'=>$c['rev'], 'by'=>$c['by']], $chapters),
            'cast'=>$cast,
            // The adaptation with its own workspace: its bible, its plan and its sheets (with its lettering).
            'comic'=>$comic['started'] ? ['settings'=>array_intersect_key($comic, array_flip(['kind', 'reading', 'color', 'aspect', 'scale', 'style', 'rev', 'by'])),
                'notes'=>$doc('comic_notes'), 'plan'=>$doc('comic_plan'), 'cast'=>$ready($this->castDocument($this->row($id), $key, 'comic')),
                'pages'=>array_map(fn($p) => ['id'=>$p['id'], 'chapter'=>$numbers[$p['chapter']] ?? null] + array_intersect_key($p, array_flip(['summary', 'panels', 'rows', 'sketch', 'notes', 'image', 'drawn', 'rev', 'by'])),
                    $this->comics()->pages($id, $key))] : null,
            'vn'=>$this->isVn($row, $key) ? $this->vn()->exportData($id, $key) : null];
        // The pictures the file takes along: every library image of the account the story names (an id can be of a
        // chapter or a page too: those aren't images), with its title for the gallery it goes into.
        $pack['images'] = [];
        preg_match_all('/[a-f0-9]{32}/', json_encode($pack, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE), $found);
        foreach (array_unique($found[0]) as $image) {
            try { $item = $this->vault->still($image, $key); } catch (HttpError) { continue; }
            $pack['images'][$image] = ['title'=>(string)($item['title'] ?? ''), 'prompt'=>(string)($item['prompt'] ?? '')];
        }
        return $pack;
    }

    /**
     * A new story from a file made by export(), or by hand in its shape: every part is optional but a title or a
     * chapter. The page has put the file's pictures in the library first, so they are library image ids here; one that
     * isn't the account's picture is left out (and counted). Everything is checked as if typed in the app.
     * @return array{id:string,map:array<string,string>,dropped:int} the new story, the ids changed (old => new: the
     *         story, its chapters and pages, for the conversation) and how many pictures were left out
     */
    public function import(array $pack, array $writer, string $key): array
    {
        $dropped = 0;
        $picture = function (mixed $id) use ($key, &$dropped): ?string {
            if ($id === null || $id === '') return null;
            if (is_string($id) && preg_match('/\A[a-f0-9]{32}\z/D', $id)) { try { $this->vault->still($id, $key); return $id; } catch (HttpError) {} }
            $dropped++; return null;
        };
        $list = fn(mixed $v) => is_array($v) && array_is_list($v) ? $v : [];
        $part = fn(mixed $v) => is_array($v) && !array_is_list($v) ? $v : [];
        $by = fn(mixed $v) => in_array($v, self::BY, true) ? $v : 'person';
        $int = fn(mixed $v, int $min = 0) => is_int($v) ? max($min, $v) : $min;
        $oldId = fn(mixed $v) => is_string($v) && preg_match('/\A[a-f0-9]{32}\z/D', $v) ? $v : null;
        $text = fn(mixed $v, int $max, string $what) => $v === null ? '' : trim(self::cleanText($v, $max, $what));
        $line = fn(mixed $v, int $max, string $what) => $v === null ? '' : self::cleanLine($v, $max, $what);

        $s = $part($pack['story'] ?? null);
        $title = $line($s['title'] ?? null, 200, t('The title'));
        $chapters = [];
        foreach ($list($pack['chapters'] ?? null) as $n => $c) {
            if (!is_array($c)) throw new HttpError(422, t('Chapter {n}: invalid format.', ['n'=>($n + 1)]));
            $body = $text($c['text'] ?? null, self::MAX_TEXT, t('Chapter {n}', ['ctx'=>'subject', 'n'=>($n + 1)]));
            // Illustration lines: a library image of the account stays; one that isn't, or a request, goes.
            $body = preg_replace_callback(self::MARKER, function ($m) use ($picture) {
                return $m[2] === 'img' && $picture($m[3]) !== null ? $m[0] : '';
            }, $body) ?? $body;
            $chapters[] = ['old'=>$oldId($c['id'] ?? null), 'new'=>self::newId(), 'title'=>$line($c['title'] ?? null, 200, t('The title of chapter {n}', ['n'=>($n + 1)])),
                'text'=>trim(preg_replace('/\n{3,}/', "\n\n", $body) ?? $body), 'summary'=>$text($c['summary'] ?? null, self::MAX_SUMMARY, t('The summary of chapter {n}', ['n'=>($n + 1)])),
                'rev'=>$int($c['rev'] ?? null, 1), 'by'=>$by($c['by'] ?? null)];
        }
        if (count($chapters) > self::MAX_CHAPTERS) throw new HttpError(422, t('A story can have up to {max} chapters.', ['max'=>self::MAX_CHAPTERS]));
        if ($title === '' && !$chapters) throw new HttpError(422, t('The file has no story: the title is missing and there are no chapters.'));
        $document = function (mixed $d, string $label) use ($part, $text, $int, $by): array {
            $d = $part($d); $body = $text($d['text'] ?? null, self::MAX_TEXT, $label);
            return ['text'=>$body, 'by'=>$body === '' ? null : $by($d['by'] ?? null), 'at'=>time(), 'rev'=>$int($d['rev'] ?? null, $body === '' ? 0 : 1)];
        };
        $notes = $document($pack['notes'] ?? null, t('The story\'s bible', ['ctx'=>'subject'])); $plan = $document($pack['plan'] ?? null, t('The plan', ['ctx'=>'subject']));

        // The sheets and the style, as updateCast and updateStyle would leave them.
        $pictures = function (mixed $images, int $max) use ($list, $picture, $line, $by, $int): array {
            $out = [];
            foreach ($list($images) as $im) {
                $image = is_array($im) ? $picture($im['image'] ?? null) : null;
                if ($image === null || in_array($image, array_column($out, 'image'), true) || count($out) >= $max) continue;
                $out[] = ['image'=>$image, 'job'=>null, 'caption'=>self::caption($line($im['caption'] ?? null, 200, t('The caption'))), 'by'=>$by($im['by'] ?? null), 'at'=>$int($im['at'] ?? null) ?: time()];
            }
            return $out;
        };
        $castOf = function (mixed $in) use ($part, $list, $line, $text, $int, $by, $oldId, $picture, $pictures): array {
            $castIn = $part($in); $styleIn = $part($castIn['style'] ?? null); $letters = $part($styleIn['lettering'] ?? null);
            $characters = []; $ids = [];
            foreach ($list($castIn['characters'] ?? null) as $c) {
                if (!is_array($c)) continue;
                $name = mb_substr($line($c['name'] ?? null, 120, t('The name')), 0, 80);
                if ($name === '' || self::findCharacter($characters, $name) !== null) continue;
                if (count($characters) >= self::MAX_CHARACTERS) throw new HttpError(422, t('A story can have up to {max} sheets.', ['max'=>self::MAX_CHARACTERS]));
                $id = $oldId($c['id'] ?? null); $id = $id !== null && !isset($ids[$id]) ? $id : self::newId(); $ids[$id] = true;
                $characters[] = ['id'=>$id, 'kind'=>in_array($c['kind'] ?? null, self::SHEET_KINDS, true) ? $c['kind'] : 'character', 'name'=>$name,
                    'note'=>$line($c['note'] ?? null, 300, t('The note')), 'images'=>$pictures($c['images'] ?? null, self::MAX_SHEETS)];
            }
            return ['rev'=>$int($castIn['rev'] ?? null), 'by'=>isset($castIn['by']) ? $by($castIn['by']) : null, 'at'=>time(),
                'style'=>['text'=>$text($styleIn['text'] ?? null, 4000, t('The visual style')), 'images'=>$pictures($styleIn['images'] ?? null, self::MAX_STYLE_IMAGES),
                    'lettering'=>['text'=>$text($letters['text'] ?? null, 1000, t('The description of the lettering')), 'image'=>$picture($letters['image'] ?? null), 'job'=>null]],
                'characters'=>$characters];
        };
        $cast = $castOf($pack['cast'] ?? null); $characters = $cast['characters'];
        $hasCast = $characters || $cast['style']['text'] !== '' || $cast['style']['images'] || $cast['style']['lettering']['text'] !== '' || $cast['style']['lettering']['image'] !== null;

        // The comic: its settings as setSettings checks them, each page's script as the app's own, and its workspace (a
        // file made before the adaptation had one has none: its sheets are then a copy of the book's, as openComicSpace makes).
        $comic = null; $pages = []; $comicSpace = null;
        if (is_array($pack['comic'] ?? null)) {
            if (array_key_exists('cast', $pack['comic'])) $comicSpace = ['notes'=>$document($pack['comic']['notes'] ?? null, t('The adaptation\'s bible', ['ctx'=>'subject'])),
                'plan'=>$document($pack['comic']['plan'] ?? null, t('The adaptation\'s plan', ['ctx'=>'subject'])), 'cast'=>$castOf($pack['comic']['cast'])];
            $set = $part($pack['comic']['settings'] ?? null); $comic = Comics::DEFAULTS;
            foreach (['kind'=>Comics::KINDS, 'reading'=>Comics::READINGS, 'color'=>Comics::COLORS, 'aspect'=>Comics::ASPECTS, 'scale'=>Comics::SCALES] as $field => $allowed) {
                $v = $set[$field] ?? null; $v = is_int($v) || is_float($v) ? (string)$v : $v;
                if (in_array($v, $allowed, true)) $comic[$field] = $v;
            }
            $style = $part($set['style'] ?? null);
            $comic += ['rev'=>$int($set['rev'] ?? null, 1), 'by'=>$by($set['by'] ?? null), 'at'=>time(), 'reading_page'=>null,
                'style'=>['text'=>$text($style['text'] ?? null, 4000, t('The comic\'s style')), 'images'=>array_map(fn($im) => array_diff_key($im, ['job'=>1]), $pictures($style['images'] ?? null, Comics::MAX_STYLE_IMAGES))]];
            foreach ($list($pack['comic']['pages'] ?? null) as $n => $p) {
                if (!is_array($p)) throw new HttpError(422, t('Comic page {n}: invalid format.', ['n'=>($n + 1)]));
                try {
                    $rows = $p['rows'] ?? null;
                    $panels = Comics::panels($p['panels'] ?? null);
                    foreach ($panels as &$q) foreach ($q['characters'] as &$who) $who['sheet'] = $who['sheet'] !== '' ? ($picture($who['sheet']) ?? '') : '';
                    unset($q, $who);
                    if (!is_array($rows) || !array_is_list($rows) || !$rows || array_filter($rows, fn($r) => !is_int($r) || $r < 1 || $r > 5) || array_sum($rows) !== count($panels)) $rows = null;
                    $sketch = is_string($p['sketch'] ?? null) && trim($p['sketch']) !== '' ? Sketch::check($p['sketch']) : '';
                } catch (HttpError $e) { throw new HttpError(422, t('Comic page {n}: {error}', ['n'=>($n + 1), 'error'=>$e->getMessage()])); }
                $chapter = $p['chapter'] ?? null;
                $pages[] = ['old'=>$oldId($p['id'] ?? null), 'chapter'=>is_int($chapter) && $chapter >= 1 && $chapter <= count($chapters) ? $chapter : null,
                    'summary'=>$line($p['summary'] ?? null, 1000, t('The page\'s summary')), 'panels'=>$panels, 'rows'=>$rows, 'sketch'=>$sketch,
                    'notes'=>$text($p['notes'] ?? null, 3000, t('The page\'s notes')), 'image'=>$picture($p['image'] ?? null), 'job'=>null,
                    'drawn'=>is_int($p['drawn'] ?? null) ? $p['drawn'] : null, 'rev'=>$int($p['rev'] ?? null, 1), 'by'=>$by($p['by'] ?? null)];
            }
            if (count($pages) > Comics::MAX_PAGES) throw new HttpError(422, t('A comic can have up to {max} pages.', ['max'=>Comics::MAX_PAGES]));
        }

        // A visual novel project: its settings, scenes and art, as the app checks them.
        $vn = null;
        if (($s['kind'] ?? null) === 'vn' || is_array($pack['vn'] ?? null)) {
            $vn = Vn::importData($part($pack['vn'] ?? null), $picture, fn(int $n) => $chapters[$n - 1]['new'] ?? null, array_flip(array_column($characters, 'id')));
        }

        $meta = ['title'=>$title !== '' ? $title : t('Imported story'), 'series'=>$line($s['series'] ?? null, 120, t('The series')),
            'synopsis'=>$text($s['synopsis'] ?? null, 6000, t('The synopsis')), 'cover'=>$picture($s['cover'] ?? null), 'writer'=>$writer] + ($vn !== null ? ['kind'=>'vn'] : []);
        if ($meta['cover'] !== null) $meta['cover_by'] = $by($s['cover_by'] ?? null);
        if (is_array($s['cover_design'] ?? null)) { try { $meta['cover_design'] = self::cleanCoverDesign($s['cover_design']); } catch (HttpError) {} }
        return $this->transaction(function () use ($meta, $notes, $plan, $chapters, $cast, $hasCast, $comic, $comicSpace, $pages, $vn, $key, $dropped, $pack, $oldId) {
            $id = self::newId(); $now = time(); $map = [];
            if (($old = $oldId($pack['story']['id'] ?? null)) !== null) $map[$old] = $id;
            $this->vault->db->prepare('INSERT INTO stories(id,user_id,meta,notes,plan,cast,comic,vn,vn_art,comic_notes,comic_plan,comic_cast,created,updated) VALUES(?,?,CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),CAST(? AS BLOB),?,?)')
                ->execute([$id, $this->owner(), self::seal($meta, $key, "story:$id"), self::seal($notes, $key, "story-notes:$id"), self::seal($plan, $key, "story-plan:$id"),
                    $hasCast ? self::seal($cast, $key, "story-cast:$id") : null, $comic !== null ? self::seal($comic, $key, "story-comic:$id") : null,
                    $vn !== null ? self::seal($vn['vn'], $key, "story-vn:$id") : null, $vn !== null ? self::seal($vn['art'], $key, "story-vn-art:$id") : null,
                    $comicSpace !== null ? self::seal($comicSpace['notes'], $key, "story-comic_notes:$id") : null, $comicSpace !== null ? self::seal($comicSpace['plan'], $key, "story-comic_plan:$id") : null,
                    $comicSpace !== null ? self::seal($comicSpace['cast'], $key, "story-comic-cast:$id") : null, $now, $now]);
            $chapterIds = [];
            foreach ($chapters as $n => $c) {
                $cid = $c['new']; $chapterIds[$n + 1] = $cid; if ($c['old'] !== null) $map[$c['old']] = $cid;
                $this->vault->db->prepare('INSERT INTO chapters(id,story_id,user_id,position,body,rev,created,updated) VALUES(?,?,?,?,CAST(? AS BLOB),?,?,?)')
                    ->execute([$cid, $id, $this->owner(), $n + 1, self::seal(['title'=>$c['title'], 'text'=>$c['text'], 'summary'=>$c['summary'], 'by'=>$c['by']], $key, "chapter:$cid"), $c['rev'], $now, $now]);
            }
            foreach ($pages as $n => $p) {
                $pid = self::newId(); if ($p['old'] !== null) $map[$p['old']] = $pid;
                $body = ['chapter'=>$p['chapter'] !== null ? $chapterIds[$p['chapter']] : null] + array_intersect_key($p, array_flip(['summary', 'panels', 'rows', 'sketch', 'notes', 'image', 'job', 'drawn', 'by']));
                $this->vault->db->prepare('INSERT INTO comic_pages(id,story_id,user_id,position,body,rev,created,updated) VALUES(?,?,?,?,CAST(? AS BLOB),?,?,?)')
                    ->execute([$pid, $id, $this->owner(), $n + 1, self::seal($body, $key, "comic-page:$pid"), $p['rev'], $now, $now]);
            }
            foreach ($vn['scenes'] ?? [] as $n => $sc) {
                $sid = self::newId(); $rev = $sc['rev']; if ($sc['old'] !== null) $map[$sc['old']] = $sid; unset($sc['rev'], $sc['old']);
                $this->vault->db->prepare('INSERT INTO vn_scenes(id,story_id,user_id,position,body,rev,created,updated) VALUES(?,?,?,?,CAST(? AS BLOB),?,?,?)')
                    ->execute([$sid, $id, $this->owner(), $n + 1, self::seal($sc, $key, "vn-scene:$sid"), $rev, $now, $now]);
            }
            return ['id'=>$id, 'map'=>$map, 'dropped'=>$dropped];
        });
    }

    // -----------------------------------------------------------------------------------------------------------------
    // The bible (notes) and the plan
    // -----------------------------------------------------------------------------------------------------------------

    public function readDocument(string $id, string $name, string $key): array
    {
        if (!in_array($name, self::DOCUMENTS, true)) throw new \LogicException('Unknown document');
        return $this->document($this->row($id), $name, $key);
    }

    /**
     * Changes the bible or the plan: the whole text, text added at the end, or exact replacements (each old text must
     * appear exactly once). $expect is the revision the person started from: a change made meanwhile is never overwritten.
     */
    public function writeDocument(string $id, string $name, array $change, string $by, string $key, ?int $expect = null): array
    {
        if (!in_array($name, self::DOCUMENTS, true)) throw new \LogicException('Unknown document');
        $label = t(self::DOCUMENT_LABELS[$name], ['ctx'=>'subject']);
        return $this->transaction(function () use ($id, $name, $change, $by, $key, $expect, $label) {
            $row = $this->row($id); $doc = $this->document($row, $name, $key);
            if ($expect !== null && $expect !== (int)$doc['rev']) throw new HttpError(409, t('{what} changed while you were editing (the agent may have updated it). Reload to see the new version.', ['what'=>$label]));
            $text = $doc['text'];
            if (array_key_exists('text', $change) && $change['text'] !== null) $text = self::cleanText($change['text'], self::MAX_TEXT, $label);
            if (!empty($change['edits'])) $text = self::applyEdits($text, $change['edits'], $label);
            if (isset($change['append']) && $change['append'] !== '') $text = rtrim($text)."\n\n".trim(self::cleanText($change['append'], self::MAX_TEXT, $label));
            $text = trim($text);
            if (mb_strlen($text) > self::MAX_TEXT) throw new HttpError(422, t('{what} is over {max} characters.', ['what'=>$label, 'max'=>I18n::number(self::MAX_TEXT)]));
            $doc = ['text'=>$text, 'by'=>$by, 'at'=>time(), 'rev'=>(int)$doc['rev'] + 1];
            $this->vault->db->prepare("UPDATE stories SET $name=CAST(? AS BLOB) WHERE id=?")->execute([self::seal($doc, $key, "story-$name:$id"), $id]);
            $this->touch($id);
            return $doc;
        });
    }

    /**
     * Exact replacements, applied in order. Each old text must appear exactly once in the text as it is when its turn
     * comes, so a replacement never lands in the wrong place.
     * @param mixed $edits list of {old_text, new_text}
     */
    public static function applyEdits(string $text, mixed $edits, string $label = ''): string
    {
        if ($label === '') $label = t('The text');
        if (!is_array($edits) || !array_is_list($edits) || count($edits) > 100) throw new HttpError(422, 'edits must be a list of up to 100 {old_text, new_text} objects.');
        foreach ($edits as $n => $edit) {
            if (!is_array($edit) || !is_string($edit['old_text'] ?? null) || !is_string($edit['new_text'] ?? null)) throw new HttpError(422, 'Each edit needs old_text and new_text as strings.');
            $old = str_replace(["\r\n", "\r"], "\n", $edit['old_text']); $new = self::cleanText($edit['new_text'], self::MAX_TEXT, $label);
            if ($old === '') throw new HttpError(422, 'Edit '.($n + 1).': old_text is empty.');
            $count = substr_count($text, $old);
            if ($count === 0) throw new HttpError(422, 'Edit '.($n + 1).': old_text was not found. It must match the current text exactly (read it again first). Nothing was changed.');
            if ($count > 1) throw new HttpError(422, 'Edit '.($n + 1).": old_text appears $count times. Include more surrounding text so it appears once. Nothing was changed.");
            $at = strpos($text, $old);
            $text = substr($text, 0, $at).$new.substr($text, $at + strlen($old));
        }
        return $text;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // The character sheets (the cast)
    // -----------------------------------------------------------------------------------------------------------------

    /**
     * The sheets and the visual style. Each sheet is of a character (or a place that comes back) with its reference
     * pictures, the first one its main picture; a picture is a library image, or the request still making it (job). The
     * writer makes them and passes them as references, so the characters look the same in every illustration; a
     * character can have several (ages, outfits, changes). The style is a description and pictures the app adds to
     * every new picture of the story, so its look holds from one to the next. The person sees and changes them all;
     * rev counts the changes of content, as the bible's does.
     * @return array{rev:int,by:?string,at:?int,style:array{text:string,images:list<array>},characters:list<array{id:string,kind:string,name:string,note:string,images:list<array{image:?string,job:?string,caption:string,by:?string,at:int}>}>}
     */
    private function castDocument(array $row, string $key, string $space = 'story'): array
    {
        if ($space === 'comic') {
            // A comic started before the adaptation had sheets of its own drew with the book's: it gets a copy of them,
            // once, so its pages keep drawing as they did.
            if (($row['comic_cast'] ?? null) === null && ($row['comic'] ?? null) !== null) return $this->openComicSpace($row, $key);
            $d = self::open($row['comic_cast'] ?? null, $key, 'story-comic-cast:'.$row['id']);
        } else $d = self::open($row['cast'] ?? null, $key, 'story-cast:'.$row['id']);
        $d = (is_array($d) ? $d : []) + ['rev'=>0, 'by'=>null, 'at'=>null, 'style'=>[], 'characters'=>[]];
        $d['style'] = (is_array($d['style']) ? $d['style'] : []) + ['text'=>'', 'images'=>[]];
        // The lettering: how any text drawn in a picture looks (titles, speech balloons, sound effects), and a sample.
        $d['style']['lettering'] = (is_array($d['style']['lettering'] ?? null) ? $d['style']['lettering'] : []) + ['text'=>'', 'image'=>null, 'job'=>null];
        foreach ($d['characters'] as &$c) $c += ['kind'=>'character'];
        unset($c);
        return $d;
    }

    /** The sheets, the style and the lettering: the book's ($space story) or the comic adaptation's own ($space comic). */
    public function cast(string $id, string $key, string $space = 'story'): array { return $this->castDocument($this->row($id), $key, self::space($space)); }

    private static function space(mixed $space): string
    {
        if (!in_array($space, self::SPACES, true)) throw new HttpError(422, t('The sheets are the book\'s (story) or the adaptation\'s (comic).'));
        return $space;
    }

    private function saveCast(string $id, array $cast, string $key, string $space = 'story'): void
    {
        [$column, $context] = $space === 'comic' ? ['comic_cast', "story-comic-cast:$id"] : ['cast', "story-cast:$id"];
        $this->vault->db->prepare("UPDATE stories SET $column=CAST(? AS BLOB) WHERE id=?")->execute([self::seal($cast, $key, $context), $id]);
    }

    /**
     * The comic adaptation's own sheets, kept apart from the book's from the moment it starts: empty for an adaptation
     * starting now (Comics calls this before it is saved for the first time); a copy of the book's sheets and
     * lettering for one that started before it had its own (its pages were drawn with those), not counted as a change.
     */
    private function openComicSpace(array $row, string $key): array
    {
        $book = $this->castDocument($row, $key);
        $cast = $row['comic'] !== null
            ? ['rev'=>0, 'by'=>null, 'at'=>time(), 'style'=>['text'=>'', 'images'=>[], 'lettering'=>$book['style']['lettering']], 'characters'=>$book['characters']]
            : ['rev'=>0, 'by'=>null, 'at'=>time(), 'style'=>['text'=>'', 'images'=>[], 'lettering'=>['text'=>'', 'image'=>null, 'job'=>null]], 'characters'=>[]];
        $this->saveCast($row['id'], $cast, $key, 'comic');
        return $this->castDocument(['comic_cast'=>self::seal($cast, $key, 'story-comic-cast:'.$row['id'])] + $row, $key, 'comic');
    }

    /** Called by Comics before the adaptation is saved: its own sheets exist from then on (see openComicSpace). */
    public function startComicSpace(string $id, string $key): void
    {
        $row = $this->row($id);
        if (($row['comic_cast'] ?? null) === null) $this->openComicSpace($row, $key);
    }

    /**
     * A sheet of the book brought into the adaptation's sheets (free): the same pictures, kind and note, under the same
     * name; when the adaptation already has a sheet of that name, the book's pictures it lacks are added to it.
     */
    public function sheetFromBook(string $id, string $which, string $by, string $key): array
    {
        $book = $this->cast($id, $key)['characters'];
        $n = self::findCharacter($book, $which);
        if ($n === null) throw new HttpError(404, t('There is no “{name}” in the book\'s sheets.', ['name'=>trim($which)]));
        $c = $book[$n];
        $comic = $this->cast($id, $key, 'comic')['characters'];
        $mine = self::findCharacter($comic, $c['name']);
        $have = $mine !== null ? array_map(fn($im) => $im['image'] ?? $im['job'], $comic[$mine]['images']) : [];
        $add = [];
        foreach ($c['images'] as $im) if (!in_array($im['image'] ?? $im['job'], $have, true)) $add[] = (is_string($im['image'] ?? null) ? ['image'=>$im['image']] : ['job'=>$im['job']]) + ['caption'=>$im['caption']];
        $room = Stories::MAX_SHEETS - count($have);
        return $this->updateCast($id, ['character'=>$mine !== null ? $comic[$mine]['id'] : $c['name'], 'kind'=>$c['kind'], 'note'=>$mine !== null && $comic[$mine]['note'] !== '' ? null : $c['note'],
            'add'=>array_slice($add, 0, max(0, $room))], $by, $key, 'comic');
    }

    /** The place in the list of the character with this id, or this name (case and extra spaces aside); null if none. */
    public static function findCharacter(array $characters, string $which): ?int
    {
        $which = trim($which); $name = mb_strtolower(preg_replace('/\s+/u', ' ', $which) ?? $which);
        foreach ($characters as $n => $c) if ($c['id'] === $which || mb_strtolower($c['name']) === $name) return $n;
        return null;
    }

    /** The place of a picture in a character's sheet, by its image id or the id of the request making it. */
    private static function findSheet(array $images, string $id): ?int
    {
        foreach ($images as $n => $im) if (($im['image'] ?? null) === $id || ($im['job'] ?? null) === $id) return $n;
        return null;
    }

    /**
     * Changes the character sheets, one character at a time. $change['character'] names it (or gives its id); a name
     * not in the cast adds the character. Then, in this order: new_name, note, add (pictures: {image} a library image
     * or {job} a request making one, each with a caption), captions (id => caption), remove (image or request ids),
     * main (the picture that goes first) and delete. Returns the character as it is now (null once deleted) and the cast.
     */
    public function updateCast(string $id, array $change, string $by, string $key, string $space = 'story'): array
    {
        $space = self::space($space);
        return $this->transaction(function () use ($id, $change, $by, $key, $space) {
            $cast = $this->castDocument($this->row($id), $key, $space); $chars = $cast['characters'];
            $which = self::cleanLine($change['character'] ?? '', 120, t('The character'));
            if ($which === '') throw new HttpError(422, t('Say which character (the name).'));
            $n = self::findCharacter($chars, $which);
            $delete = ($change['delete'] ?? false) === true;
            $kind = $change['kind'] ?? null;
            if ($kind !== null && !in_array($kind, self::SHEET_KINDS, true)) throw new HttpError(422, t('A sheet is of a character (character) or of a place (place).'));
            if ($n === null) {
                if ($delete) throw new HttpError(404, $space === 'comic' ? t('There is no “{name}” in the adaptation\'s sheets.', ['name'=>$which]) : t('There is no “{name}” in the sheets.', ['name'=>$which]));
                if (count($chars) >= self::MAX_CHARACTERS) throw new HttpError(422, $space === 'comic' ? t('The adaptation can have up to {max} sheets.', ['max'=>self::MAX_CHARACTERS]) : t('A story can have up to {max} sheets.', ['max'=>self::MAX_CHARACTERS]));
                $chars[] = ['id'=>self::newId(), 'kind'=>$kind ?? 'character', 'name'=>mb_substr($which, 0, 80), 'note'=>'', 'images'=>[]];
                $n = array_key_last($chars);
            }
            $c = $chars[$n];
            if ($kind !== null) $c['kind'] = $kind;
            if (isset($change['new_name']) && $change['new_name'] !== null) {
                $name = mb_substr(self::cleanLine($change['new_name'], 120, t('The name')), 0, 80);
                if ($name === '') throw new HttpError(422, t('The character\'s name is empty.'));
                $other = self::findCharacter($chars, $name);
                if ($other !== null && $other !== $n) throw new HttpError(422, t('There is already a character called “{name}”.', ['name'=>$chars[$other]['name']]));
                $c['name'] = $name;
            }
            if (isset($change['note']) && $change['note'] !== null) $c['note'] = self::cleanLine($change['note'], 300, t('The note'));
            foreach (is_array($change['add'] ?? null) ? $change['add'] : [] as $add) {
                if (!is_array($add)) throw new HttpError(422, t('Invalid image for the sheet.'));
                $caption = self::caption(self::cleanLine($add['caption'] ?? '', 200, t('The caption')));
                if (isset($add['job'])) { Vault::validId((string)$add['job']); $entry = ['image'=>null, 'job'=>(string)$add['job']]; }
                else { $image = (string)($add['image'] ?? ''); Vault::validId($image); $this->vault->still($image, $key); $entry = ['image'=>$image, 'job'=>null]; }
                if (self::findSheet($c['images'], (string)($entry['image'] ?? $entry['job'])) !== null) continue;
                if (count($c['images']) >= self::MAX_SHEETS) throw new HttpError(422, t('{name}\'s sheet already has {max} images: remove one first.', ['name'=>$c['name'], 'max'=>self::MAX_SHEETS]));
                $c['images'][] = $entry + ['caption'=>$caption, 'by'=>$by, 'at'=>time()];
            }
            foreach (is_array($change['captions'] ?? null) ? $change['captions'] : [] as $ref => $caption) {
                $at = self::findSheet($c['images'], (string)$ref);
                if ($at === null) throw new HttpError(422, t('That image is not on {name}\'s sheet.', ['name'=>$c['name']]));
                $c['images'][$at]['caption'] = self::caption(self::cleanLine($caption, 200, t('The caption')));
            }
            foreach (is_array($change['remove'] ?? null) ? $change['remove'] : [] as $ref) {
                $at = self::findSheet($c['images'], (string)$ref);
                if ($at === null) throw new HttpError(422, t('Image {ref} is not on {name}\'s sheet.', ['ref'=>$ref, 'name'=>$c['name']]));
                array_splice($c['images'], $at, 1);
            }
            if (isset($change['main']) && $change['main'] !== null) {
                $at = self::findSheet($c['images'], (string)$change['main']);
                if ($at === null) throw new HttpError(422, t('That image is not on {name}\'s sheet.', ['name'=>$c['name']]));
                $main = array_splice($c['images'], $at, 1); array_unshift($c['images'], $main[0]);
            }
            if ($delete) { array_splice($chars, $n, 1); $c = null; }
            else $chars[$n] = $c;
            $cast = ['rev'=>(int)$cast['rev'] + 1, 'by'=>$by, 'at'=>time(), 'style'=>$cast['style'], 'characters'=>array_values($chars)];
            $this->saveCast($id, $cast, $key, $space);
            $this->touch($id);
            return ['character'=>$c, 'cast'=>$cast];
        });
    }

    /**
     * Changes the story's visual style: its description (text; null leaves it), pictures added (library images, each
     * with a caption) and removed (by image id). Returns the sheets with the style.
     */
    public function updateStyle(string $id, array $change, string $by, string $key, string $space = 'story'): array
    {
        $space = self::space($space);
        return $this->transaction(function () use ($id, $change, $by, $key, $space) {
            $cast = $this->castDocument($this->row($id), $key, $space); $style = $cast['style'];
            if (isset($change['text']) && $change['text'] !== null) $style['text'] = trim(self::cleanText($change['text'], 4000, t('The visual style')));
            if (isset($change['lettering']) && $change['lettering'] !== null) $style['lettering']['text'] = trim(self::cleanText($change['lettering'], 1000, t('The description of the lettering')));
            // The sample of the lettering: an image (null or '' takes it off), or a request making one (the old one stays meanwhile).
            if (array_key_exists('lettering_image', $change)) {
                $image = $change['lettering_image'];
                if ($image === null || $image === '') $style['lettering'] = ['image'=>null, 'job'=>null] + $style['lettering'];
                else { $image = (string)$image; Vault::validId($image); $this->vault->still($image, $key); $style['lettering'] = ['image'=>$image, 'job'=>null] + $style['lettering']; }
            }
            if (isset($change['lettering_job'])) { Vault::validId((string)$change['lettering_job']); $style['lettering']['job'] = (string)$change['lettering_job']; }
            foreach (is_array($change['add'] ?? null) ? $change['add'] : [] as $add) {
                if (!is_array($add)) throw new HttpError(422, t('Invalid image for the style.'));
                $image = (string)($add['image'] ?? ''); Vault::validId($image); $this->vault->still($image, $key);
                if (self::findSheet($style['images'], $image) !== null) continue;
                if (count($style['images']) >= self::MAX_STYLE_IMAGES) throw new HttpError(422, t('The style can have up to {max} images: remove one first.', ['max'=>self::MAX_STYLE_IMAGES]));
                $style['images'][] = ['image'=>$image, 'job'=>null, 'caption'=>self::caption(self::cleanLine($add['caption'] ?? '', 200, t('The caption'))), 'by'=>$by, 'at'=>time()];
            }
            foreach (is_array($change['remove'] ?? null) ? $change['remove'] : [] as $ref) {
                $at = self::findSheet($style['images'], (string)$ref);
                if ($at === null) throw new HttpError(422, t('Image {ref} is not in the style.', ['ref'=>$ref]));
                array_splice($style['images'], $at, 1);
            }
            $cast = ['rev'=>(int)$cast['rev'] + 1, 'by'=>$by, 'at'=>time(), 'style'=>$style, 'characters'=>$cast['characters']];
            $this->saveCast($id, $cast, $key, $space);
            $this->touch($id);
            return $cast;
        });
    }

    /**
     * A picture of the sheets whose request ended or was made again: the request $from becomes the image $image (it
     * finished) or the request $job (tried again). Not a change of content: the revision stays.
     */
    private function castPicture(string $storyId, string $from, ?string $image, ?string $job, string $key): bool
    {
        return $this->transaction(function () use ($storyId, $from, $image, $job, $key) {
            $row = $this->row($storyId); $any = false;
            // The same request can be in the book's sheets and the adaptation's (a sheet brought from the book).
            foreach (self::SPACES as $space) {
                if ($space === 'comic' && ($row['comic_cast'] ?? null) === null) continue;
                $cast = $this->castDocument($row, $key, $space); $changed = false;
                foreach ($cast['characters'] as &$c) foreach ($c['images'] as &$im) {
                    if (($im['job'] ?? null) !== $from && ($im['image'] ?? null) !== $from) continue;
                    $im['image'] = $image ?? $im['image']; $im['job'] = $image !== null ? null : $job; $changed = true;
                }
                unset($c, $im);
                // A style picture that got a new version follows it.
                if ($image !== null) foreach ($cast['style']['images'] as &$im) if (($im['image'] ?? null) === $from) { $im['image'] = $image; $changed = true; }
                unset($im);
                $l = $cast['style']['lettering'];
                if ($l['job'] === $from) { $cast['style']['lettering'] = ($image !== null ? ['image'=>$image, 'job'=>null] : ['job'=>$job]) + $l; $changed = true; }
                elseif ($image !== null && $l['image'] === $from) { $cast['style']['lettering']['image'] = $image; $changed = true; }
                if (!$changed) continue;
                $this->saveCast($storyId, $cast, $key, $space);
                $any = true;
            }
            if ($any) $this->touch($storyId);
            return $any;
        });
    }

    /** A picture of the sheets that failed, made again: its place in the sheet now waits for the new request. */
    public function retryCastPicture(string $storyId, string $from, string $to, string $key): void
    {
        Vault::validId($from); Vault::validId($to);
        if (!$this->castPicture($storyId, $from, null, $to, $key)) throw new HttpError(404, t('That image is not in the sheets.'));
    }

    /** The requests still making pictures of the sheets: request id => character name (or "lettering", the sample of the letters). */
    private function castJobs(array $cast): array
    {
        $ids = [];
        foreach ($cast['characters'] as $c) foreach ($c['images'] as $im) if (is_string($im['job'] ?? null)) $ids[$im['job']] = $c['name'];
        if (is_string($cast['style']['lettering']['job'])) $ids[$cast['style']['lettering']['job']] = 'lettering';
        return $ids;
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Chapters
    // -----------------------------------------------------------------------------------------------------------------

    /** @return list<array{id:string,title:string,text:string,summary:string,by:?string,rev:int,position:float,updated:int}> in order */
    private function chapterRows(string $storyId, string $key): array
    {
        $q = $this->vault->db->prepare('SELECT * FROM chapters WHERE story_id=? AND user_id=? ORDER BY position, created'); $q->execute([$storyId, $this->owner()]);
        $rows = $q->fetchAll(); $q->closeCursor();
        return array_map(function (array $r) use ($key) {
            $b = self::open($r['body'], $key, 'chapter:'.$r['id']);
            $b = (is_array($b) ? $b : []) + ['title'=>'', 'text'=>'', 'summary'=>'', 'by'=>null];
            return ['id'=>$r['id'], 'title'=>(string)$b['title'], 'text'=>(string)$b['text'], 'summary'=>(string)$b['summary'], 'by'=>$b['by'],
                'rev'=>(int)$r['rev'], 'position'=>(float)$r['position'], 'updated'=>(int)$r['updated']];
        }, $rows);
    }

    /** @return list<array> the chapters in order, with their text */
    public function chapters(string $storyId, string $key): array { $this->row($storyId); return $this->chapterRows($storyId, $key); }

    public function chapter(string $storyId, string $chapterId, string $key): array
    {
        foreach ($this->chapters($storyId, $key) as $n => $c) if ($c['id'] === $chapterId) return $c + ['number'=>$n + 1];
        throw new HttpError(404, t('Chapter not found.'));
    }

    /**
     * A new chapter at $index (0 = first; null = last).
     */
    public function addChapter(string $storyId, string $title, string $text, string $summary, ?int $index, string $by, string $key): array
    {
        $title = self::cleanLine($title, 200, t('The chapter\'s title'));
        $text = trim(self::cleanText($text, self::MAX_TEXT, t('The chapter')));
        $summary = trim(self::cleanText($summary, self::MAX_SUMMARY, t('The summary')));
        $id = self::newId();
        $this->transaction(function () use ($storyId, $title, $text, $summary, $index, $by, $key, $id) {
            $rows = $this->chapters($storyId, $key);
            if (count($rows) >= self::MAX_CHAPTERS) throw new HttpError(422, t('A story can have up to {max} chapters.', ['max'=>self::MAX_CHAPTERS]));
            $index = $index === null ? count($rows) : max(0, min(count($rows), $index));
            $position = self::positionAt($rows, $index);
            $now = time();
            $this->vault->db->prepare('INSERT INTO chapters(id,story_id,user_id,position,body,rev,created,updated) VALUES(?,?,?,?,CAST(? AS BLOB),1,?,?)')
                ->execute([$id, $storyId, $this->owner(), $position, self::seal(['title'=>$title, 'text'=>$text, 'summary'=>$summary, 'by'=>$by], $key, "chapter:$id"), $now, $now]);
            $this->touch($storyId);
        });
        return $this->chapter($storyId, $id, $key);
    }

    /** A position between the chapters at $index - 1 and $index. */
    private static function positionAt(array $rows, int $index): float
    {
        $before = $index > 0 ? $rows[$index - 1]['position'] : null; $after = $rows[$index]['position'] ?? null;
        if ($before === null && $after === null) return 1.0;
        if ($before === null) return $after - 1;
        if ($after === null) return $before + 1;
        return ($before + $after) / 2;
    }

    /**
     * Changes a chapter: title, whole text, exact replacements in the text, summary. $expect is the revision the
     * person started from (409 when the chapter changed meanwhile). Returns the chapter as it is now.
     */
    public function updateChapter(string $storyId, string $chapterId, array $change, string $by, string $key, ?int $expect = null, bool $content = true): array
    {
        $this->transaction(function () use ($storyId, $chapterId, $change, $by, $key, $expect, $content) {
            $c = $this->chapter($storyId, $chapterId, $key);
            if ($expect !== null && $expect !== $c['rev']) throw new HttpError(409, t('This chapter changed while you were editing (the agent may have changed it). Reload to see the new version; your text is still in the box.'));
            $title = array_key_exists('title', $change) && $change['title'] !== null ? self::cleanLine($change['title'], 200, t('The chapter\'s title')) : $c['title'];
            $text = array_key_exists('text', $change) && $change['text'] !== null ? trim(self::cleanText($change['text'], self::MAX_TEXT, t('The chapter'))) : $c['text'];
            if (!empty($change['edits'])) $text = trim(self::applyEdits($text, $change['edits'], t('The chapter')));
            if (mb_strlen($text) > self::MAX_TEXT) throw new HttpError(422, t('{what} is over {max} characters.', ['what'=>t('The chapter'), 'max'=>I18n::number(self::MAX_TEXT)]));
            $summary = array_key_exists('summary', $change) && $change['summary'] !== null ? trim(self::cleanText($change['summary'], self::MAX_SUMMARY, t('The summary'))) : $c['summary'];
            // A change of the text alone by another hand (an illustration that became ready) is not a new revision.
            $rev = $content ? $c['rev'] + 1 : $c['rev'];
            $this->vault->db->prepare('UPDATE chapters SET body=CAST(? AS BLOB), rev=?, updated=? WHERE id=?')
                ->execute([self::seal(['title'=>$title, 'text'=>$text, 'summary'=>$summary, 'by'=>$content ? $by : $c['by']], $key, "chapter:$chapterId"), $rev, time(), $chapterId]);
            $this->touch($storyId);
        });
        return $this->chapter($storyId, $chapterId, $key);
    }

    public function deleteChapter(string $storyId, string $chapterId): void
    {
        Vault::validId($chapterId); $this->row($storyId);
        $q = $this->vault->db->prepare('DELETE FROM chapters WHERE id=? AND story_id=? AND user_id=?'); $q->execute([$chapterId, $storyId, $this->owner()]);
        if (!$q->rowCount()) throw new HttpError(404, t('Chapter not found.'));
        $this->touch($storyId);
    }

    /** Moves a chapter to $index (0 = first) among the others. */
    public function moveChapter(string $storyId, string $chapterId, int $index, string $key): void
    {
        $this->transaction(function () use ($storyId, $chapterId, $index, $key) {
            $rows = array_values(array_filter($this->chapters($storyId, $key), fn($c) => $c['id'] !== $chapterId));
            if (count($rows) === count($this->chapterRows($storyId, $key))) throw new HttpError(404, t('Chapter not found.'));
            $position = self::positionAt($rows, max(0, min(count($rows), $index)));
            $this->vault->db->prepare('UPDATE chapters SET position=? WHERE id=?')->execute([$position, $chapterId]);
            $this->touch($storyId);
        });
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Illustrations
    // -----------------------------------------------------------------------------------------------------------------

    /** A caption that fits in the brackets of an illustration line. */
    public static function caption(?string $caption): string
    { return trim(str_replace(['[', ']', "\n"], ['(', ')', ' '], (string)$caption)); }

    /**
     * Puts an illustration line into a chapter's text: after the paragraph that contains $after (exact text), at the
     * start, or at the end. Returns the new text.
     */
    public static function insertIllustration(string $text, string $line, ?string $after, bool $atStart = false): string
    {
        $text = trim($text);
        if ($atStart) return $text === '' ? $line : $line."\n\n".$text;
        if ($after === null || trim($after) === '') return $text === '' ? $line : $text."\n\n".$line;
        $after = str_replace(["\r\n", "\r"], "\n", trim($after));
        $count = substr_count($text, $after);
        if ($count === 0) throw new HttpError(422, 'after_text was not found in the chapter. Use an exact snippet of the paragraph the image should follow (read the chapter again), or leave it out to put the image at the end.');
        if ($count > 1) throw new HttpError(422, 'after_text appears more than once in the chapter. Use a longer snippet.');
        $at = strpos($text, $after) + strlen($after);
        $end = strpos($text, "\n\n", $at);
        if ($end === false) return $text."\n\n".$line;
        return substr($text, 0, $end)."\n\n".$line.substr($text, $end);
    }

    /** Replaces the illustration whose picture is $from (an image or a request id) by $to (img:ID or job:ID). */
    public static function replaceIllustration(string $text, string $from, string $to, ?string $caption = null): string
    {
        return preg_replace_callback(self::MARKER, fn($m) => $m[3] === $from ? '!['.($caption ?? $m[1]).']('.$to.')' : $m[0], $text) ?? $text;
    }

    /**
     * Requests whose image the story is waiting for, by id: where each goes ('chapter' with the chapter's id, 'cover',
     * 'cast' with the character's name, 'page' with the id of a page of the comic, or 'vn' with the sprite, background
     * or CG of a visual novel).
     * @return array<string,array{for:string,chapter?:string,character?:string,page?:string}>
     */
    public function pendingIllustrations(string $storyId, string $key): array
    {
        $ids = [];
        foreach ($this->chapters($storyId, $key) as $c) if (preg_match_all(self::MARKER, $c['text'], $found, PREG_SET_ORDER)) foreach ($found as $f) if ($f[2] === 'job') $ids[$f[3]] = ['for'=>'chapter', 'chapter'=>$c['id']];
        $row = $this->row($storyId);
        foreach ($this->castJobs($this->castDocument($row, $key)) as $jobId => $name) $ids[$jobId] = ['for'=>'cast', 'character'=>$name];
        if (($row['comic_cast'] ?? null) !== null) foreach ($this->castJobs($this->castDocument($row, $key, 'comic')) as $jobId => $name) $ids[$jobId] ??= ['for'=>'cast', 'character'=>$name];
        $m = $this->meta($row, $key); $cover = $m['cover_job'] ?? null; $sets = [];
        foreach (is_array($m['cover_choices'] ?? null) ? $m['cover_choices'] : [] as $c) {
            if (is_string($c['batch'] ?? null)) $sets[] = $c['batch'];
            if (is_string($c['job'] ?? null) && ($c['image'] ?? null) === null) $ids[$c['job']] = ['for'=>'cover'];
        }
        // A cover asked for before covers came in sets.
        if (is_string($cover) && !in_array($cover, $sets, true)) $ids[$cover] = ['for'=>'cover'];
        foreach ($this->comics()->jobs($storyId, $key) as $jobId => $pageId) $ids[$jobId] = ['for'=>'page', 'page'=>$pageId];
        if ($this->isVn($row, $key)) foreach ($this->vn()->jobs($storyId, $key) as $jobId => $art) $ids[$jobId] = ['for'=>'vn', 'kind'=>$art['kind'], 'art'=>$art['id']];
        return $ids;
    }

    /**
     * A cover being made: the request whose image becomes the story's cover when it is ready. $by: who asked for it
     * (null: the same as for the one it replaces, made again).
     */
    public function setCoverJob(string $id, string $jobId, string $key, ?string $by = 'agent'): void
    {
        $this->transaction(function () use ($id, $jobId, $key, $by) {
            $m = $this->meta($this->row($id), $key); $m['cover_job'] = $jobId; $m['cover_job_by'] = $by ?? $m['cover_job_by'] ?? 'agent';
            $this->vault->db->prepare('UPDATE stories SET meta=CAST(? AS BLOB) WHERE id=?')->execute([self::seal($m, $key, "story:$id"), $id]);
            $this->touch($id);
        });
    }

    /**
     * Covers being made to choose from ($made: each request with its design direction, ['job', 'style']), the first
     * request naming the set: the first of them ready becomes the cover, unless the person chooses one before. They join
     * the covers to choose from, with the cover the story has now (to go back to). $by: who asked for them.
     */
    public function setCoverJobs(string $id, array $made, string $key, ?string $by = 'agent'): void
    {
        $this->transaction(function () use ($id, $made, $key, $by) {
            $m = $this->meta($this->row($id), $key);
            $batch = (string)$made[0]['job']; $before = is_array($m['cover_choices'] ?? null) ? $m['cover_choices'] : [];
            $new = array_map(fn($c) => ['job'=>(string)$c['job'], 'batch'=>$batch, 'style'=>(string)$c['style'], 'image'=>null], $made);
            if (is_string($m['cover'] ?? null) && !in_array($m['cover'], array_column($before, 'image'), true)) $new[] = ['job'=>null, 'batch'=>null, 'style'=>'before', 'image'=>$m['cover']];
            $m['cover_choices'] = array_slice([...$new, ...$before], 0, self::COVER_CHOICES);
            $m['cover_job'] = $batch; $m['cover_job_by'] = $by ?? $m['cover_job_by'] ?? 'agent';
            $this->vault->db->prepare('UPDATE stories SET meta=CAST(? AS BLOB) WHERE id=?')->execute([self::seal($m, $key, "story:$id"), $id]);
            $this->touch($id);
        });
    }

    /** A cover of a set that failed ($from), being made again ($to): it keeps its place and its set. */
    public function retryCoverChoice(string $id, string $from, string $to, string $key): void
    {
        $this->transaction(function () use ($id, $from, $to, $key) {
            $m = $this->meta($this->row($id), $key);
            foreach ($m['cover_choices'] ?? [] as $i => $c) if (($c['job'] ?? null) === $from) $m['cover_choices'][$i]['job'] = $to;
            $this->vault->db->prepare('UPDATE stories SET meta=CAST(? AS BLOB) WHERE id=?')->execute([self::seal($m, $key, "story:$id"), $id]);
            $this->touch($id);
        });
    }

    /** The request making a new version of the cover ($cover, the image shown as the cover), the last one; null if none. */
    public function coverEdit(string $id, ?string $cover, string $key): ?string
    {
        $found = null;
        if ($cover !== null) foreach ($this->edits($id, $key) as $jobId => $e) if ($e['image'] === $cover) $found = (string)$jobId;
        return $found;
    }

    /**
     * A cover that didn't come out (a new one, the ones of a set, or a new version of it) set aside: the story keeps the
     * cover it has. $choice: only that cover of a set (its request).
     */
    public function dropCoverJob(string $id, string $key, ?string $choice = null): void
    {
        $this->transaction(function () use ($id, $key, $choice) {
            $m = $this->meta($this->row($id), $key);
            $drop = fn($c) => ($c['image'] ?? null) === null && ($choice !== null ? ($c['job'] ?? null) === $choice : ($c['batch'] ?? null) === ($m['cover_job'] ?? false));
            if (is_array($m['cover_choices'] ?? null)) $m['cover_choices'] = array_values(array_filter($m['cover_choices'], fn($c) => !$drop($c)));
            // The set waits no more when none of it is left to make.
            $left = array_filter($m['cover_choices'] ?? [], fn($c) => ($c['image'] ?? null) === null && ($c['batch'] ?? null) === ($m['cover_job'] ?? false));
            if ($choice === null || !$left) unset($m['cover_job'], $m['cover_job_by']);
            if ($choice === null && is_array($m['edits'] ?? null) && is_string($m['cover'] ?? null)) $m['edits'] = array_filter($m['edits'], fn($e) => empty($e['failed']) || $e['image'] !== $m['cover']);
            $this->vault->db->prepare('UPDATE stories SET meta=CAST(? AS BLOB) WHERE id=?')->execute([self::seal($m, $key, "story:$id"), $id]);
            $this->touch($id);
        });
    }

    /**
     * A new version of an illustration being made (the writer, or the person for the cover, edited it). The old picture
     * stays while the new one is made, then every place that shows it (the chapters, the cover, the character sheets,
     * the pages of the comic) shows the new one. A failed one is kept a week, so the writer hears about it.
     */
    public function startEdit(string $id, string $jobId, string $imageId, ?string $caption, string $key, string $by = 'agent', bool $coverOnly = false): void
    {
        $this->transaction(function () use ($id, $jobId, $imageId, $caption, $key, $by, $coverOnly) {
            $m = $this->meta($this->row($id), $key);
            $edits = array_filter(is_array($m['edits'] ?? null) ? $m['edits'] : [], fn($e) => empty($e['failed']) || $e['at'] > time() - 7 * 86400);
            $m['edits'] = $edits + [$jobId=>['image'=>$imageId, 'caption'=>$caption, 'at'=>time(), 'by'=>$by] + ($coverOnly ? ['only'=>'cover'] : [])];
            $this->vault->db->prepare('UPDATE stories SET meta=CAST(? AS BLOB) WHERE id=?')->execute([self::seal($m, $key, "story:$id"), $id]);
            $this->touch($id);
        });
    }

    /** A new version that failed, asked for again: the request $to takes the place of $from. */
    public function retryEdit(string $id, string $from, string $to, string $key): void
    {
        $this->transaction(function () use ($id, $from, $to, $key) {
            $m = $this->meta($this->row($id), $key); $e = $m['edits'][$from] ?? null;
            if (!is_array($e)) throw new HttpError(404, t('That new version is no longer in the story.'));
            unset($m['edits'][$from], $e['failed']); $m['edits'][$to] = ['at'=>time()] + $e;
            $this->vault->db->prepare('UPDATE stories SET meta=CAST(? AS BLOB) WHERE id=?')->execute([self::seal($m, $key, "story:$id"), $id]);
            $this->touch($id);
        });
    }

    /** @return array<string,array{image:string,caption:?string,at:int,failed?:bool}> the new versions being made, by request id */
    public function edits(string $id, string $key): array
    {
        $e = $this->meta($this->row($id), $key)['edits'] ?? [];
        return is_array($e) ? $e : [];
    }

    /** An edit finished: its image ($imageId) takes the old one's place, or it failed (false), or its request is gone (null). */
    private function finishEdit(string $storyId, string $jobId, string|false|null $imageId, string $key): void
    {
        $this->transaction(function () use ($storyId, $jobId, $imageId, $key) {
            $e = $this->edits($storyId, $key)[$jobId] ?? null;
            if ($e === null) return;
            // A new version of the cover made from the page changes the cover alone, not the same picture elsewhere.
            $everywhere = ($e['only'] ?? null) !== 'cover';
            if (is_string($imageId) && $everywhere) foreach ($this->chapters($storyId, $key) as $c) {
                $text = self::replaceIllustration($c['text'], $e['image'], 'img:'.$imageId, $e['caption'] ?? null);
                if ($text !== $c['text']) $this->updateChapter($storyId, $c['id'], ['text'=>$text], 'system', $key, null, false);
            }
            if (is_string($imageId) && $everywhere) $this->castPicture($storyId, $e['image'], $imageId, null, $key);
            if (is_string($imageId) && $everywhere) $this->comics()->replaceImage($storyId, $e['image'], $imageId, $key);
            if (is_string($imageId) && $everywhere && $this->isVn($this->row($storyId), $key)) $this->vn()->replaceImage($storyId, $e['image'], $imageId, $key);
            $m = $this->meta($this->row($storyId), $key);
            if ($imageId === false) $m['edits'][$jobId]['failed'] = true;
            else unset($m['edits'][$jobId]);
            if (is_string($imageId) && ($m['cover'] ?? null) === $e['image']) { $m['cover'] = $imageId; $m['cover_by'] = $e['by'] ?? 'agent'; }
            $this->vault->db->prepare('UPDATE stories SET meta=CAST(? AS BLOB) WHERE id=?')->execute([self::seal($m, $key, "story:$storyId"), $storyId]);
            $this->touch($storyId);
        });
    }

    /**
     * Illustrations whose request finished with an image become that image in the text (or the cover, or a picture of
     * the character sheets). Failed ones stay as they are, so the page can say so and offer to try again.
     * @return array<string,array> the requests still waited for, by id, as Jobs::list returns them, with where each goes
     *                             (for: chapter, cover or cast)
     */
    public function resolveIllustrations(string $storyId, string $key, Jobs $jobs): array
    {
        $pending = $this->pendingIllustrations($storyId, $key);
        $edits = $this->edits($storyId, $key);
        if (!$pending && !$edits) return [];
        $found = [];
        foreach ($jobs->list($key, 'active', array_merge(array_keys($pending), array_keys($edits))) as $job)
            if (array_key_exists($job['id'], $pending) || array_key_exists($job['id'], $edits)) $found[$job['id']] = $job;
        foreach ($edits as $jobId => $e) {
            $job = $found[$jobId] ?? null;
            if ($job === null) $this->finishEdit($storyId, $jobId, null, $key);
            elseif ($job['status'] === 'completed' && $job['image_ids']) { $this->finishEdit($storyId, $jobId, $job['image_ids'][0], $key); unset($found[$jobId]); }
            elseif (!in_array($job['status'], ['queued', 'running', 'cancelling'], true) && empty($e['failed'])) $this->finishEdit($storyId, $jobId, false, $key);
        }
        foreach ($found as $jobId => &$job) {
            if (array_key_exists($jobId, $edits)) continue;
            $job['for'] = $pending[$jobId]['for'];
            if ($job['status'] !== 'completed' || !$job['image_ids']) continue;
            if ($pending[$jobId]['for'] === 'cast') { $this->castPicture($storyId, $jobId, $job['image_ids'][0], null, $key); unset($found[$jobId]); continue; }
            if ($pending[$jobId]['for'] === 'page') { $this->comics()->finishJob($storyId, $jobId, $job['image_ids'][0], $key); unset($found[$jobId]); continue; }
            if ($pending[$jobId]['for'] === 'vn') { $this->vn()->finishJob($storyId, $jobId, $job['image_ids'][0], $key); unset($found[$jobId]); continue; }
            if ($pending[$jobId]['for'] === 'cover') {
                $this->transaction(function () use ($storyId, $jobId, $job, $key) {
                    $m = $this->meta($this->row($storyId), $key);
                    $at = null;
                    foreach ($m['cover_choices'] ?? [] as $i => $c) if (($c['job'] ?? null) === $jobId) $at = $i;
                    if ($at !== null) {
                        // A cover of a set: one more to choose from, and the cover if it is the first of its set and the
                        // person hasn't chosen one meanwhile.
                        if (($m['cover_choices'][$at]['image'] ?? null) !== null) return;
                        $m['cover_choices'][$at]['image'] = $job['image_ids'][0];
                        if (($m['cover_job'] ?? null) === $m['cover_choices'][$at]['batch']) { $m['cover'] = $job['image_ids'][0]; $m['cover_by'] = $m['cover_job_by'] ?? 'agent'; unset($m['cover_job'], $m['cover_job_by']); }
                    } else {
                        if (($m['cover_job'] ?? null) !== $jobId) return;
                        $m['cover'] = $job['image_ids'][0]; $m['cover_by'] = $m['cover_job_by'] ?? 'agent'; unset($m['cover_job'], $m['cover_job_by']);
                    }
                    $this->vault->db->prepare('UPDATE stories SET meta=CAST(? AS BLOB) WHERE id=?')->execute([self::seal($m, $key, "story:$storyId"), $storyId]);
                    $this->touch($storyId);
                });
                unset($found[$jobId]); continue;
            }
            $chapterId = $pending[$jobId]['chapter'];
            try {
                $this->transaction(function () use ($storyId, $chapterId, $jobId, $job, $key) {
                    $c = $this->chapter($storyId, $chapterId, $key);
                    $text = self::replaceIllustration($c['text'], $jobId, 'img:'.$job['image_ids'][0]);
                    if ($text !== $c['text']) $this->updateChapter($storyId, $chapterId, ['text'=>$text], 'system', $key, null, false);
                });
                unset($found[$jobId]);
            } catch (HttpError) { /* the chapter went away meanwhile */ }
        }
        unset($job);
        return $found;
    }

    // -----------------------------------------------------------------------------------------------------------------

    /** Runs $work in one write transaction (nested calls join the outer one, the comic's too). */
    private int $depth = 0;
    public function transaction(callable $work): mixed
    {
        if ($this->depth > 0) { $this->depth++; try { return $work(); } finally { $this->depth--; } }
        $this->vault->db->exec('BEGIN IMMEDIATE'); $this->depth = 1;
        try { $result = $work(); $this->vault->db->exec('COMMIT'); return $result; }
        catch (\Throwable $e) { $this->vault->db->exec('ROLLBACK'); throw $e; }
        finally { $this->depth = 0; }
    }
}
