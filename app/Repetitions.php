<?php
declare(strict_types=1);
namespace Studio;

/**
 * The phrases a book repeats, counted by the app: what the writer can't see on its own once the earlier chapters are no
 * longer in its conversation, and what costs nothing to count. A phrase is a run of 4 to 10 words inside one sentence
 * with at least two words that aren't grammar (so "she looked up at him" doesn't count, "the ghost of a smile" does),
 * reported at its longest: "the ghost of a smile" is not listed again as "ghost of a smile".
 */
final class Repetitions
{
    public const MIN_WORDS = 4;
    public const MAX_WORDS = 16;

    /** Words that alone don't make a phrase worth noticing, in English and Portuguese (the languages of the stories). */
    private const GRAMMAR = ['a','an','the','and','or','but','if','then','so','of','to','in','on','at','by','for','with','from','into','onto','over',
        'under','about','as','than','that','this','these','those','it','its',"it's",'is','was','were','be','been','being','am','are','has','have','had',
        'do','does','did','not','no','nor','he','she','they','them','his','her','hers','their','him','i','me','my','you','your','we','us','our',
        'who','whom','whose','which','what','when','where','why','how','there','here','all','any','some','one','just','very','too','also','only',
        'up','down','out','off','again','still','even','back','would','could','should','will','can','may','might','must',"didn't","don't","doesn't",
        "wasn't","weren't","couldn't","wouldn't","isn't","aren't","hadn't","hasn't","haven't","won't","can't","i'm","i'd","i'll","he'd","she'd",
        "they'd","he's","she's","they're","you're","we're","that's",'got','get','like','now','more','own','each','other','through','around','away',
        'o','os','as','um','uma','uns','umas','e','ou','mas','se','de','do','da','dos','das','em','no','na','nos','nas','por','pelo','pela',
        'pelos','pelas','para','pra','com','sem','que','quem','qual','como','quando','onde','porque','já','não','sim','ele','ela','eles','elas',
        'lhe','lhes','te','vos','meu','minha','seu','sua','seus','suas','dele','dela','deles','delas','isso','isto','aquilo','esse','essa','este',
        'esta','aquele','aquela','foi','era','é','ser','estar','estava','está','tinha','tem','ter','havia','há','mais','muito','pouco','também',
        'só','ainda','então','ao','à','aos','às','num','numa','eu','você','nós','mesmo','mesma','tão','até','lá','aqui'];

    /**
     * @param list<string> $texts the chapters' text in order (the first is chapter 1)
     * @param int|null $chapter only the phrases that this chapter uses and the book uses more than once
     * @return list<array{phrase:string,count:int,chapters:array<int,int>}> most repeated first; chapters: number => times
     */
    public static function find(array $texts, ?int $chapter = null, int $limit = 60): array
    {
        $grammar = array_flip(self::GRAMMAR);
        // Each sentence as a list of words, and which chapter it is in.
        $runs = []; $where = [];
        foreach ($texts as $i => $text) {
            $plain = mb_strtolower(str_replace('’', "'", preg_replace(Stories::MARKER, '', $text) ?? $text));
            foreach (preg_split('/[.!?;:…()\[\]"“”«»—–\n]+/u', $plain) ?: [] as $sentence) {
                if (!preg_match_all('/[\p{L}\p{N}]+(?:[\'\-][\p{L}\p{N}]+)*/u', $sentence, $m) || count($m[0]) < self::MIN_WORDS) continue;
                $runs[] = $m[0]; $where[] = $i + 1;
            }
        }
        // Counted shortest first; a longer phrase can only repeat where both of its shorter halves do.
        $found = []; $alive = [];
        foreach ($runs as $r => $words) $alive[$r] = range(0, count($words) - self::MIN_WORDS);
        for ($n = self::MIN_WORDS; $n <= self::MAX_WORDS && $alive; $n++) {
            $seen = [];
            foreach ($alive as $r => $starts) foreach ($starts as $p) {
                $phrase = implode(' ', array_slice($runs[$r], $p, $n));
                $seen[$phrase][] = $where[$r];
            }
            $repeated = array_filter($seen, fn($at) => count($at) > 1);
            $next = [];
            foreach ($alive as $r => $starts) foreach ($starts as $p) {
                if ($p + $n + 1 > count($runs[$r])) continue;
                if (isset($repeated[implode(' ', array_slice($runs[$r], $p, $n))], $repeated[implode(' ', array_slice($runs[$r], $p + 1, $n))])) $next[$r][] = $p;
            }
            foreach ($repeated as $phrase => $at) {
                $content = 0;
                foreach (explode(' ', (string)$phrase) as $w) if (!isset($grammar[$w]) && !is_numeric($w)) $content++;
                if ($content >= 2) $found[(string)$phrase] = $at;
            }
            $alive = $next;
        }
        // At its longest: a phrase inside a longer one that repeats as often is the same repetition.
        uksort($found, fn($a, $b) => substr_count($b, ' ') <=> substr_count($a, ' ') ?: strcmp($a, $b));
        $kept = []; $inside = [];
        foreach ($found as $phrase => $at) {
            if (($inside[$phrase] ?? 0) >= count($at)) continue;
            $kept[$phrase] = count($at);
            // Every shorter phrase inside this one, with the most times a kept phrase around it repeats.
            $words = explode(' ', (string)$phrase); $len = count($words);
            for ($n = self::MIN_WORDS; $n < $len; $n++) for ($p = 0; $p + $n <= $len; $p++) {
                $sub = implode(' ', array_slice($words, $p, $n));
                $inside[$sub] = max($inside[$sub] ?? 0, count($at));
            }
        }
        $out = [];
        foreach ($kept as $phrase => $count) {
            $chapters = array_count_values($found[$phrase]); ksort($chapters);
            if ($chapter !== null && !isset($chapters[$chapter])) continue;
            $out[] = ['phrase'=>(string)$phrase, 'count'=>$count, 'chapters'=>$chapters];
        }
        usort($out, fn($a, $b) => $b['count'] <=> $a['count'] ?: substr_count($b['phrase'], ' ') <=> substr_count($a['phrase'], ' ') ?: strcmp($a['phrase'], $b['phrase']));
        return array_slice($out, 0, $limit);
    }

    /** The list as the writer reads it. */
    public static function text(array $found, ?int $chapter = null): string
    {
        if (!$found) return $chapter !== null ? 'No phrase of chapter '.$chapter.' (4 words or more) comes back elsewhere in the book or twice in it.'
            : 'The book repeats no phrase of 4 words or more.';
        $s = $chapter !== null ? 'Phrases of chapter '.$chapter.' that the book uses more than once (counted by the app, most repeated first):'
            : 'Phrases of 4 words or more that the book uses more than once (counted by the app, most repeated first):';
        foreach ($found as $f) {
            $at = [];
            foreach ($f['chapters'] as $n => $times) $at[] = $n.($times > 1 ? ' (×'.$times.')' : '');
            $s .= "\n- \"".$f['phrase'].'" ×'.$f['count'].': chapter'.(count($f['chapters']) > 1 ? 's ' : ' ').implode(', ', $at);
        }
        return $s."\nNames, set phrases and refrains you mean to repeat are fine; the rest are tics: vary them.";
    }
}
