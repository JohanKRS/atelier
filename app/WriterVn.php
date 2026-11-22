<?php
declare(strict_types=1);
namespace Studio;

/**
 * The writer's part of a visual novel project (Vn): what it is told about the game, its tools for the scenes, the
 * choices, the settings, the gameplay notes and the checks, and the art (sprites, backgrounds and CGs), which the page
 * makes with the same functions. Used by Writer.
 */
trait VnWriter
{
    /** What the writer is told, after its instructions, when the story is a visual novel project. */
    public const VN_SYSTEM = <<<'TEXT'
## This project is a visual novel
This story is a visual novel project: the person is designing a game with you, and everything here serves it. Where these instructions differ from the ones above, these win.
- The person is the game's author and knows the whole story, so the Spoilers section above doesn't apply: don't use <no_spoilers> tags, and talk freely about plot, routes, secrets and endings. The bible can hold everything.
- The bible (notes) is the game's design document: premise, world, tone, the cast with each character's arc and route, the systems (what affinity and each flag mean, what the choices weigh), the endings and how each is reached, the style guide of the script. The plan is your working plan: the route map, the order you write the scenes in, open threads, next up. The person sees and edits both.
- Chapters hold the base text of each chapter: the prose or outline the person wrote or imported, from which the scenes are adapted. Read them before adapting, but don't write chapters unless the person asks: the game is written in scenes.
- Character sheets and place sheets keep the art consistent: every character who appears needs one (with their main picture: it is what their sprites are made from), and so does every place that is a background.
- What each character knows, the prose discipline, the clichés, the beat log (an entry per scene) and the revision above hold for scenes too (fix them with edit_scene script_edits). find_repetitions and review_book read the chapters, not the scenes. What a character knows can depend on the route and the choices taken: note it per route in the ledger, and in a scene that several paths reach, use only what every one of them has shown, or branch on a flag.

### Scenes and the choice tree
The game is a list of scenes. Each scene has a label (its id in the script: lowercase ASCII with underscores, like a Ren'Py label, e.g. cafe_noite, rota_ana_03), a title, optionally the chapter it adapts and the route it belongs to, a short summary, gameplay notes for the team, its script and its exit:
- jump: on to another scene;
- choice: a menu, with a prompt and options; each option has the text the player reads, the scene it leads to, optionally a condition (if) for it to show and changes to variables (set, like "affinity_ana += 1");
- branch: on to the first case whose condition holds (cases, each with if and to), else to `else`, without a menu;
- end: an ending, with its title and kind (good, normal, bad, true, secret);
- none: not decided yet (the checks flag it).
Exits name scenes by label. A label not written yet is allowed, so you can sketch the tree first; the checks list it until it exists.
Variables (vn_settings): flags (true/false), numbers (affinity, points) and text. Declare each one before using it, with its initial value and a note on what it means. Routes are named groups of scenes (the common route, each character's route), each with a color on the tree.

### The script
TEXT
        ."\n".Vn::SCRIPT_HELP."\n".<<<'TEXT'
Write it like a visual novel, not a novel: the player reads one line at a time in a text box, so keep each line to one to three sentences, with dialogue that gives each character a distinct voice and narration in short beats. Stage it with commands: @bg at each change of place or time, @show when a character enters or moves, @hide when they leave, the expression that follows the emotion of each line (Ana (surpresa): …), @music for the mood, @sound for effects, @cg for the special moments. Use the names exactly as in the sheets, and always the same names for places and their variants (day, night, rain, sunset), so each sprite and background is made once and reused. Choices go in the exit, never in the script. Write a long scene in parts: write_scene with the first part, then edit_scene with append_script.
The app checks each line as you save it: a line it can't read is refused with its number, and nothing of the scene is saved.

### Checks
check_vn runs the app's checks: scenes without an exit, exits to scenes that don't exist, scenes no path reaches, endings no combination of choices reaches (it plays every path with the variables), choices that leave the player stuck, variables used without being declared, and the art and audio the scripts use that doesn't exist yet. Run it after changing the tree, fix what is yours, and tell the person what needs their decision.

### The art
The art is made with the image model like the other pictures (it costs the same per image), always from the character and place sheets, so that it matches them:
- Sprites (make_sprite): a character standing, framed as the project's settings say, on a transparent background, one per outfit and expression. Make the base one first (neutral expression) from the character's sheet; the app makes each other expression, and each other outfit, as an edit of the base (same face, hair, body, pose and size: only what you ask changes), so the sprites line up when they swap on screen.
- Backgrounds (make_background): a place without people, at eye level, in the project's aspect, from its place sheet. Each variant (night, rain, sunset) is made as an edit of the place's base background: the same place and framing in another light and weather.
- CGs (make_cg): the illustration of a special moment (a confession, a fight, an ending), with the sheets of the characters in it, in the project's aspect. Use it in the script with @cg name.
The prompts follow the rules of illustrate (a complete visual description in English; @1, @2… name the references in order). Make art when the person asks or agrees, and before making many pictures at once say how many. The person can also make the missing art from the app. update_art adds an existing image to the art, or removes an entry, for free; edit_illustration also changes a sprite, a background or a CG (the new version takes its place).

### Working from the person's documents
The person may bring the documents they already have: the design document, chapter bases, character documents, notes. They come imported into the bible, chapters or sheets, pasted in a message, or as files. Organize them without losing anything: the design and the canon in the bible (keep their words; reorganize only when asked), each chapter base in a chapter, each character and recurring place in a sheet with a note, the variables and routes in vn_settings. Then propose the tree in the plan and, as the person asks, write the scenes.

### Replies
After using your tools, reply in a few sentences: what you did (the scenes written or changed, by label; choices, endings and variables added; what the checks found) and at most one question or suggestion for the next step.
TEXT;

    private const VN_EXIT = ['type'=>'object', 'description'=>'Where the scene leads when its script ends.', 'properties'=>[
        'type'=>['type'=>'string', 'enum'=>Vn::EXIT_TYPES, 'description'=>'jump {to}; choice {prompt, options}; branch {cases, else}; end {title, kind}; none (not decided yet).'],
        'to'=>['type'=>'string', 'description'=>'jump: the label of the next scene.'],
        'prompt'=>['type'=>'string', 'description'=>'choice: the question shown with the options (optional).'],
        'options'=>['type'=>'array', 'maxItems'=>Vn::MAX_OPTIONS, 'description'=>'choice: the options, in order.', 'items'=>['type'=>'object', 'properties'=>[
            'text'=>['type'=>'string', 'description'=>'What the player reads.'],
            'to'=>['type'=>'string', 'description'=>'The label of the scene it leads to.'],
            'if'=>['type'=>'string', 'description'=>'A condition for the option to show, e.g. "affinity_ana >= 3 and not fought". Leave out for one always shown.'],
            'set'=>['type'=>'array', 'maxItems'=>10, 'items'=>['type'=>'string'], 'description'=>'Changes when chosen, e.g. ["affinity_ana += 1", "saw_letter = true"].'],
            'note'=>['type'=>'string', 'description'=>'A gameplay note for the team (what this choice weighs). Optional.']], 'required'=>['text', 'to']]],
        'cases'=>['type'=>'array', 'maxItems'=>Vn::MAX_OPTIONS, 'description'=>'branch: checked in order; the first that holds wins.', 'items'=>['type'=>'object', 'properties'=>[
            'if'=>['type'=>'string', 'description'=>'The condition.'], 'to'=>['type'=>'string', 'description'=>'The label of the scene.']], 'required'=>['if', 'to']]],
        'else'=>['type'=>'string', 'description'=>'branch: the label of the scene when no case holds.'],
        'title'=>['type'=>'string', 'description'=>'end: the ending\'s name, as the player sees it.'],
        'kind'=>['type'=>'string', 'enum'=>Vn::ENDING_KINDS, 'description'=>'end: good, normal, bad, true or secret.']], 'required'=>['type']];

    private const VN_SCENE = [
        'title'=>['type'=>'string', 'description'=>'The scene\'s title, in the story\'s language.'],
        'chapter'=>['type'=>'integer', 'minimum'=>1, 'description'=>'The number of the chapter this scene adapts. Optional.'],
        'route'=>['type'=>'string', 'description'=>'The name of the route the scene belongs to (create it first with vn_settings); "" for none.'],
        'summary'=>['type'=>'string', 'description'=>'What happens in the scene, in one to three sentences.'],
        'notes'=>['type'=>'string', 'description'=>'Gameplay notes for the team: what the scene sets up, what the choices weigh, timing, direction.'],
        'script'=>['type'=>'string', 'description'=>'The script, one step per line, as your instructions describe (dialogue, narration, @bg, @show, @hide, @cg, @music, @sound, @set, @if, @pause, @effect, # notes).'],
        'exit'=>self::VN_EXIT];

    private const VN_LIST = ['type'=>'object', 'properties'=>[
        'set'=>['type'=>'array', 'maxItems'=>100, 'items'=>['type'=>'object', 'properties'=>['name'=>['type'=>'string'], 'new_name'=>['type'=>'string', 'description'=>'Renames it (a variable is renamed in every script and exit).'],
            'type'=>['type'=>'string', 'enum'=>Vn::VAR_TYPES, 'description'=>'Variables: flag (true/false), number or text.'],
            'initial'=>['type'=>'string', 'description'=>'Variables: the value it starts with (true/false, a number, or text).'],
            'kind'=>['type'=>'string', 'enum'=>Vn::AUDIO_KINDS, 'description'=>'Audio: music or sound.'],
            'color'=>['type'=>'string', 'description'=>'Routes: #rrggbb.'],
            'note'=>['type'=>'string', 'description'=>'What it means or is for.']], 'required'=>['name']], 'description'=>'Items added, or changed by name.'],
        'remove'=>['type'=>'array', 'maxItems'=>100, 'items'=>['type'=>'string'], 'description'=>'Names of items to remove.']]];

    private const VN_ART_REFERENCES = ['type'=>'array', 'maxItems'=>5, 'items'=>['type'=>'string'], 'description'=>'Extra images to use (a prop, a pose, a costume detail), named @1, @2… in your prompt; when the picture is made from a base sprite or background, @1 is that base and your references start at @2. A request id from this turn also works.'];

    /** The visual novel's tools: name => [description, input schema]. */
    public static function vnTools(): array
    {
        $doc = ['type'=>'object', 'properties'=>[
            'text'=>['type'=>'string', 'description'=>'The whole new text of the gameplay notes (replaces it).'],
            'append'=>['type'=>'string', 'description'=>'Text added at the end of the gameplay notes.'],
            'edits'=>self::EDITS]];
        $scene = ['type'=>'string', 'description'=>'The scene\'s label.'];
        return [
            'read_scenes'=>['The full scenes asked for: their script, exit, summary, gameplay notes, chapter and route. get_outline lists every scene with its exit.',
                ['type'=>'object', 'properties'=>['scenes'=>['type'=>'array', 'maxItems'=>20, 'items'=>['type'=>'string'], 'description'=>'Labels of the scenes.']], 'required'=>['scenes']]],
            'write_scene'=>['Adds a scene to the visual novel, by default after the last one. The script is checked line by line; a line that can\'t be read refuses the whole scene. For a long scene, write the first part here and the rest with edit_scene append_script.',
                ['type'=>'object', 'properties'=>self::VN_SCENE + [
                    'label'=>['type'=>'string', 'description'=>'The scene\'s label (lowercase, digits and _). Default: made from the title.'],
                    'position'=>['type'=>'integer', 'minimum'=>1, 'description'=>'Insert as this scene number instead of at the end.']], 'required'=>['title', 'script']]],
            'edit_scene'=>['Changes a scene: the fields given replace the ones there; script_edits makes exact replacements in the script, append_script adds lines at its end. A new label is changed in every exit that led to the old one.',
                ['type'=>'object', 'properties'=>['scene'=>$scene] + self::VN_SCENE + [
                    'new_label'=>['type'=>'string', 'description'=>'Renames the scene.'],
                    'script_edits'=>self::EDITS,
                    'append_script'=>['type'=>'string', 'description'=>'Lines added at the end of the script.']], 'required'=>['scene']]],
            'delete_scene'=>['Deletes a scene. Only when the person asks, or to replace it. Exits that led to it are then flagged by the checks.', ['type'=>'object', 'properties'=>['scene'=>$scene], 'required'=>['scene']]],
            'move_scene'=>['Moves a scene in the list so it becomes scene number `to` (the order of the list is the order of the script; the tree follows the exits).',
                ['type'=>'object', 'properties'=>['scene'=>$scene, 'to'=>['type'=>'integer', 'minimum'=>1]], 'required'=>['scene', 'to']]],
            'vn_settings'=>['The game\'s settings and lists (free): its name, the shape of backgrounds and CGs, how sprites are framed, the first scene, and the variables, routes and music/sound cues (each with set and remove). Leave out what stays.',
                ['type'=>'object', 'properties'=>[
                    'name'=>['type'=>'string', 'description'=>'The game\'s name (default: the story\'s title).'],
                    'aspect'=>['type'=>'string', 'enum'=>Vn::ASPECTS, 'description'=>'The shape of the screen: backgrounds and CGs.'],
                    'framing'=>['type'=>'string', 'enum'=>Vn::FRAMINGS, 'description'=>'How the sprites are framed: full body, down to the knees or down to the waist.'],
                    'start'=>['type'=>'string', 'description'=>'The label of the first scene.'],
                    'variables'=>self::VN_LIST, 'routes'=>self::VN_LIST, 'audio'=>self::VN_LIST]]],
            'read_gameplay'=>['The gameplay notes: the team\'s notes on systems, balance, pacing and direction, apart from the design document.', ['type'=>'object', 'properties'=>new \stdClass()]],
            'update_gameplay'=>['Changes the gameplay notes: the whole text, text added at the end, or exact replacements.', $doc],
            'check_vn'=>['Runs the checks of the visual novel: every problem of the tree and the scripts, the endings and whether each can be reached, a run through every path with the variables, and the art and audio the scripts use that don\'t exist yet.', ['type'=>'object', 'properties'=>new \stdClass()]],
            'make_sprite'=>['Makes a sprite of a character (costs one image): standing, framed as the settings say, on a transparent background, from the character\'s sheet. The first one of a character is made from the sheet; the others (another expression or outfit) are made as an edit of the base sprite, so they line up. Ready in a minute or two; in this turn its request id already works in references.',
                ['type'=>'object', 'properties'=>[
                    'character'=>['type'=>'string', 'description'=>'The character\'s name, as in the sheets (they need a sheet).'],
                    'expression'=>['type'=>'string', 'description'=>'One word, as the script uses it: neutral, happy, sad, angry, surprised, blushing…'],
                    'outfit'=>['type'=>'string', 'description'=>'One word for an outfit other than the usual one (uniforme, pijama). Optional.'],
                    'prompt'=>['type'=>'string', 'description'=>'In English: the expression and the pose (the arms, a gesture), and anything that changes. The app adds the sheet, the framing and the transparent background.'],
                    'from_scratch'=>['type'=>'boolean', 'description'=>'Make it from the sheet even when there is a base sprite (to remake the base, or for a very different pose).'],
                    'references'=>self::VN_ART_REFERENCES], 'required'=>['character', 'expression', 'prompt']]],
            'make_background'=>['Makes a background of a place (costs one image): without people, at eye level, in the project\'s shape, from its place sheet when there is one. A variant of a place that already has a background is made as an edit of it (same place and framing, other light and weather).',
                ['type'=>'object', 'properties'=>[
                    'place'=>['type'=>'string', 'description'=>'The place, as in the place sheets and the script\'s @bg.'],
                    'variant'=>['type'=>'string', 'description'=>'One or two words, as the script uses it: day, night, rain, sunset. Optional (the base one).'],
                    'prompt'=>['type'=>'string', 'description'=>'In English: what the place looks like (or what this variant changes), the light, the mood.'],
                    'from_scratch'=>['type'=>'boolean', 'description'=>'Make it anew even when the place has a base background.'],
                    'references'=>self::VN_ART_REFERENCES], 'required'=>['place', 'prompt']]],
            'make_cg'=>['Makes a CG (costs one image): the full-screen illustration of a special moment, in the project\'s shape, with the sheets of the characters in it. Use it in a script with @cg name.',
                ['type'=>'object', 'properties'=>[
                    'name'=>['type'=>'string', 'description'=>'Its name in the script (lowercase, digits and _), e.g. confissao_ana.'],
                    'title'=>['type'=>'string', 'description'=>'Its title, in the story\'s language.'],
                    'prompt'=>['type'=>'string', 'description'=>'A complete visual description in English: the moment, the setting, the light, the composition, each character as in the bible.'],
                    'characters'=>['type'=>'array', 'maxItems'=>6, 'items'=>['type'=>'string'], 'description'=>'Names of the characters in it: the app adds each one\'s sheet picture after your references.'],
                    'references'=>['type'=>'array', 'maxItems'=>6, 'items'=>['type'=>'string'], 'description'=>'Images named @1, @2… in your prompt. '.self::REFERENCES]], 'required'=>['name', 'prompt']]],
            'update_art'=>['Changes the art without making images (free): puts an existing image (a gallery image, an illustration) as a sprite, background or CG, changes its note, or removes the entry (the image stays in the gallery).',
                ['type'=>'object', 'properties'=>[
                    'kind'=>['type'=>'string', 'enum'=>Vn::ART_KINDS],
                    'character'=>['type'=>'string', 'description'=>'sprite: the character.'], 'expression'=>['type'=>'string', 'description'=>'sprite: the expression.'], 'outfit'=>['type'=>'string', 'description'=>'sprite: the outfit (optional).'],
                    'place'=>['type'=>'string', 'description'=>'background: the place.'], 'variant'=>['type'=>'string', 'description'=>'background: the variant (optional).'],
                    'name'=>['type'=>'string', 'description'=>'cg: its name.'], 'title'=>['type'=>'string', 'description'=>'cg: its title.'],
                    'image_id'=>['type'=>'string', 'description'=>'The image to use.'],
                    'note'=>['type'=>'string'],
                    'remove'=>['type'=>'boolean', 'description'=>'Remove the entry.']], 'required'=>['kind']]],
        ];
    }

    /** The visual novel's tools, as the chat shows a failed one. */
    /** What a failed tool call was doing, for the chat (Writer::failLabel translates it). */
    private const VN_FAIL = ['read_scenes'=>'read scenes', 'write_scene'=>'write a scene', 'edit_scene'=>'change a scene', 'delete_scene'=>'delete a scene', 'move_scene'=>'move a scene',
        'vn_settings'=>'change the visual novel settings', 'read_gameplay'=>'read the gameplay notes', 'update_gameplay'=>'update the gameplay notes',
        'check_vn'=>'check the choice tree', 'make_sprite'=>'make a sprite', 'make_background'=>'make a background', 'make_cg'=>'make a CG', 'update_art'=>'change the art'];

    /** Whether the story of a tool call is a visual novel project (asked once per call context). */
    private function isVnContext(ToolContext $c): bool
    { return $c->vn ??= $this->stories->kind($c->storyId, $c->key) === 'vn'; }

    /** The fields of a scene from a tool's input, as Vn takes them (the chapter by its id, the route by its name). */
    private function vnSceneInput(array $in, ToolContext $c, bool $new): array
    {
        $out = array_intersect_key($in, array_flip(['title', 'summary', 'notes', 'script', 'exit', 'script_edits', 'append_script']));
        if ($new && isset($in['label'])) $out['label'] = $in['label'];
        if (!$new && isset($in['new_label'])) $out['label'] = $in['new_label'];
        if (isset($in['chapter'])) $out['chapter'] = $this->chapterAt($c, $in['chapter'])['id'];
        if (array_key_exists('route', $in)) $out['route'] = trim((string)$in['route']) === '' ? null : $in['route'];
        if (isset($out['exit']) && is_array($out['exit']) && isset($out['exit']['options'])) foreach ($out['exit']['options'] as &$o) if (is_array($o) && isset($o['set']) && !is_array($o['set'])) $o['set'] = [(string)$o['set']];
        unset($o);
        return $out;
    }

    /** What the checks say about one scene, for the writer right after it changed it. */
    private function vnSceneNews(string $storyId, string $label, string $key): string
    {
        $a = $this->stories->vn()->analyze($storyId, $key, 4000);
        $mine = array_values(array_filter($a['problems'], fn($p) => $p['scene'] === $label && $p['level'] !== 'info' && !in_array($p['code'], ['unreachable', 'blocked', 'ending-blocked', 'ending-unreachable'], true)));
        $art = [];
        foreach ($a['missing']['sprites'] as $m) if (in_array($label, $m['scenes'], true)) $art[] = 'sprite '.$m['character'].' ('.$m['attrs'].')';
        foreach ($a['missing']['backgrounds'] as $m) if (in_array($label, $m['scenes'], true)) $art[] = 'background '.$m['place'].($m['variant'] !== '' ? ' ('.$m['variant'].')' : '');
        foreach ($a['missing']['cgs'] as $m) if (in_array($label, $m['scenes'], true)) $art[] = 'CG '.$m['name'];
        foreach ($a['missing']['audio'] as $m) if (in_array($label, $m['scenes'], true)) $art[] = $m['kind'].' '.$m['name'].' (not in the audio list)';
        $out = $mine ? "\nChecks for this scene: ".implode(' ', array_map(fn($p) => '['.$p['level'].'] '.$p['text'], $mine)) : '';
        if ($art) $out .= "\nIt uses art or audio that doesn't exist yet: ".implode('; ', $art).'.';
        return $out;
    }

    /** @return array{0:string|array,1:string} */
    private function vnTool(string $name, array $in, ToolContext $c): array
    {
        $vn = $this->stories->vn(); $id = $c->storyId; $key = $c->key;
        switch ($name) {
            case 'read_scenes': {
                $labels = array_values(array_unique(array_map(fn($l) => trim((string)$l), $in['scenes'])));
                if (!$labels) throw new HttpError(422, 'Give the labels of the scenes to read.');
                $out = [];
                foreach ($labels as $l) {
                    try { $out[] = $vn->sceneText($id, $vn->scene($id, $l, $key), $key); }
                    catch (HttpError $e) { $out[] = 'Scene '.$l.': '.$e->getMessage(); }
                }
                return [implode("\n---\n", $out), count($labels) === 1 ? t('Read scene {label}', ['label'=>$labels[0]]) : t('Read {n} scenes', ['n'=>count($labels)])];
            }
            case 'write_scene': {
                $sc = $vn->addScene($id, $this->vnSceneInput($in, $c, true), isset($in['position']) ? $in['position'] - 1 : null, $c->by, $key);
                $words = Vn::scriptWords(Vn::parse($sc['script'])['steps']);
                return ['Scene '.$sc['label'].' "'.$sc['title'].'" saved as scene '.$sc['number'].' ('.$words.' words). Exit: '.Vn::exitLine($sc['exit']).'.'.$this->vnSceneNews($id, $sc['label'], $key),
                    t('Wrote scene {label} · “{title}” ({words} words)', ['label'=>$sc['label'], 'title'=>$sc['title'], 'words'=>I18n::number($words)])];
            }
            case 'edit_scene': {
                $change = $this->vnSceneInput($in, $c, false);
                if (!$change) throw new HttpError(422, 'Nothing to change: give the fields to change, script_edits or append_script.');
                $sc = $vn->updateScene($id, (string)$in['scene'], $change, $c->by, $key);
                $words = Vn::scriptWords(Vn::parse($sc['script'])['steps']);
                $what = isset($change['append_script']) ? t('Continued scene {label}', ['label'=>$sc['label']]) : (isset($change['exit']) && count($change) === 1 ? t('Changed the exit of scene {label}', ['label'=>$sc['label']]) : t('Revised scene {label}', ['label'=>$sc['label']]));
                return ['Scene '.$sc['label'].' saved (revision '.$sc['rev'].', '.$words.' words). Exit: '.Vn::exitLine($sc['exit']).'.'.$this->vnSceneNews($id, $sc['label'], $key), $what];
            }
            case 'delete_scene': {
                $sc = $vn->deleteScene($id, (string)$in['scene'], $key);
                $this->noteAgent($c, 'agent_scene_deleted', $sc['id']);
                $from = array_values(array_filter($vn->scenes($id, $key), fn($x) => in_array($sc['label'], Vn::targets($x['exit']), true)));
                return ['Scene '.$sc['label'].' deleted.'.($from ? ' These scenes still lead to it: '.implode(', ', array_column($from, 'label')).'.' : ''), t('Deleted scene {label}', ['label'=>$sc['label']])];
            }
            case 'move_scene': {
                $vn->moveScene($id, (string)$in['scene'], $in['to'] - 1, $key);
                return ['Moved. The scenes are now: '.implode(', ', array_map(fn($x) => $x['number'].'. '.$x['label'], $vn->scenes($id, $key))), t('Moved scene {label}', ['label'=>trim((string)$in['scene'])])];
            }
            case 'vn_settings': {
                $change = array_intersect_key($in, array_flip(['name', 'aspect', 'framing', 'start', 'variables', 'routes', 'audio']));
                if (!$change) throw new HttpError(422, 'Nothing to change.');
                $s = $vn->setSettings($id, $change, $c->by, $key);
                $parts = array_keys(array_intersect_key(['variables'=>1, 'routes'=>1, 'audio'=>1], $change));
                $label = $parts && count($change) === count($parts) ? t('Updated {what}', ['what'=>implode(t(' and '), array_map(fn($p) => ['variables'=>t('the variables'), 'routes'=>t('the routes'), 'audio'=>t('the soundtrack')][$p], $parts))]) : t('Updated the visual novel settings');
                return ['Saved. '.Vn::settingsLines($s), $label];
            }
            case 'read_gameplay': {
                $g = $vn->settings($id, $key)['gameplay'];
                return ['Gameplay notes, revision '.$g['rev'].":\n".($g['text'] !== '' ? $g['text'] : '(empty)'), t('Read the gameplay notes')];
            }
            case 'update_gameplay': {
                if (!isset($in['text']) && !isset($in['append']) && empty($in['edits'])) throw new HttpError(422, 'Give text, append or edits.');
                $g = $vn->writeGameplay($id, $in, $c->by, $key);
                return ['Gameplay notes saved (revision '.$g['rev'].', '.Stories::words($g['text']).' words).', t('Updated the gameplay notes')];
            }
            case 'check_vn': {
                $a = $vn->analyze($id, $key);
                $out = Vn::problemLines($a, 200);
                $out .= 'Endings ('.count($a['endings']).'): '.($a['endings'] ? implode('; ', array_map(fn($e) => $e['scene'].' "'.$e['title'].'" ('.$e['kind'].($e['reachable'] ? '' : ', NOT reachable').')', $a['endings'])) : '(none)').".\n";
                if ($a['played'] !== null) $out .= 'Every path was played: '.$a['played']['states'].' states'.($a['played']['truncated'] ? ' (stopped there: the checks of reachability are partial)' : '').'; '.$a['played']['endings'].' of the endings can be reached.'."\n";
                $m = $a['missing'];
                if ($m['sprites'] || $m['backgrounds'] || $m['cgs'] || $m['audio']) $out .= 'Missing: '.implode('; ', array_filter([
                    $m['sprites'] ? 'sprites '.implode(', ', array_map(fn($x) => $x['character'].' ('.$x['attrs'].')', $m['sprites'])) : null,
                    $m['backgrounds'] ? 'backgrounds '.implode(', ', array_map(fn($x) => $x['place'].($x['variant'] !== '' ? ' ('.$x['variant'].')' : ''), $m['backgrounds'])) : null,
                    $m['cgs'] ? 'CGs '.implode(', ', array_column($m['cgs'], 'name')) : null,
                    $m['audio'] ? 'audio cues not in the list '.implode(', ', array_column($m['audio'], 'name')) : null])).".\n";
                $out .= 'Totals: '.$a['stats']['scenes'].' scenes, '.$a['stats']['words'].' words, '.$a['stats']['choices'].' choices, '.$a['stats']['endings'].' endings.';
                $errors = count(array_filter($a['problems'], fn($p) => $p['level'] === 'error'));
                return [$out, !$errors ? t('Checked the choice tree') : ($errors === 1 ? t('Checked the choice tree (1 error)') : t('Checked the choice tree ({n} errors)', ['n'=>$errors]))];
            }
            case 'make_sprite': case 'make_background': case 'make_cg': {
                $kind = ['make_sprite'=>'sprite', 'make_background'=>'background', 'make_cg'=>'cg'][$name];
                [$entry, $job, $how] = $this->makeVnArt($id, $c->writer, $kind, $in, $c->by, $key, $c);
                $c->jobs[] = $job['id'];
                $what = $this->vnArtName($id, $kind, $entry, $key);
                return [ucfirst($what).' requested (request '.$job['id'].'), '.$how.'. It is ready in a minute or two; in this turn its request id already works in references.'.($job['left'] ?? ''),
                    t(['sprite'=>'Asked for the sprite {what}', 'background'=>'Asked for the background {what}', 'cg'=>'Asked for the CG {what}'][$kind], ['what'=>$this->vnArtLabel($id, $kind, $entry, $key)])];
            }
            case 'update_art': {
                $kind = $in['kind'];
                $entry = $vn->artKey($id, $kind, $in, $key);
                if (($in['remove'] ?? false) === true) {
                    [$list, $n] = $vn->findArt($vn->art($id, $key), $kind, $entry, $id, $key);
                    if ($n === null) throw new HttpError(404, 'There is no such '.$kind.' in the art.');
                    $vn->removeArt($id, $kind, $vn->art($id, $key)[$list][$n]['id'], $c->by, $key);
                    return ['Removed from the art; the image stays in the gallery.', t('Removed from the art: {what}', ['what'=>$this->vnArtLabel($id, $kind, $entry, $key)])];
                }
                $put = $entry;
                if (isset($in['image_id'])) $put['image'] = $this->referenceIds([(string)$in['image_id']], $key, $c->tick)[0]['id'];
                if (isset($in['note'])) $put['note'] = $in['note'];
                if (!isset($put['image']) && !isset($put['note'])) throw new HttpError(422, 'Give image_id, note or remove.');
                $vn->putArt($id, $kind, $put, $c->by, $key);
                return [ucfirst($this->vnArtName($id, $kind, $entry, $key)).' saved.', t('Updated the art: {what}', ['what'=>$this->vnArtLabel($id, $kind, $entry, $key)])];
            }
        }
        throw new HttpError(422, "Unknown tool $name.");
    }

    /** An art entry in words, for the writer. */
    private function vnArtName(string $storyId, string $kind, array $entry, string $key): string
    {
        if ($kind === 'sprite') return 'the sprite of '.($entry['who'] ?? '?').' ('.trim($entry['outfit'].' '.$entry['expression']).')';
        if ($kind === 'background') return 'the background '.$entry['name'].($entry['variant'] !== '' ? ' ('.$entry['variant'].')' : '');
        return 'the CG '.$entry['name'];
    }

    /** An art entry in words, for the chat. */
    private function vnArtLabel(string $storyId, string $kind, array $entry, string $key): string
    {
        if ($kind === 'sprite') return ($entry['who'] ?? '?').' ('.trim($entry['outfit'].' '.$entry['expression']).')';
        if ($kind === 'background') return $entry['name'].($entry['variant'] !== '' ? ' ('.$entry['variant'].')' : '');
        return '“'.($entry['title'] ?? $entry['name']).'”';
    }

    /** Expressions that make a sprite the base one of its character and outfit. */
    private const NEUTRAL = ['neutra', 'neutro', 'neutral', 'normal', 'base', 'padrao', 'padrão', 'seria', 'sério', 'serio', 'calma', 'calmo'];
    /** Variants that make a background the base one of its place. */
    private const PLAIN = ['', 'dia', 'day', 'tarde', 'manha', 'manhã', 'base', 'padrao', 'padrão'];

    /** The sprite the others of a character are made from: the neutral one of the same outfit, else another one of theirs. */
    private static function baseSprite(array $sprites, string $characterId, string $outfit, ?string $except): ?array
    {
        $mine = array_values(array_filter($sprites, fn($s) => $s['character'] === $characterId && $s['id'] !== $except && (is_string($s['image'] ?? null) || is_string($s['job'] ?? null))));
        $rank = fn($s) => ($s['outfit'] === $outfit ? 0 : 4) + (in_array($s['expression'], self::NEUTRAL, true) ? 0 : 2) + (is_string($s['image'] ?? null) ? 0 : 1);
        usort($mine, fn($a, $b) => $rank($a) <=> $rank($b));
        return $mine[0] ?? null;
    }

    /** The background a place's variants are made from: its plain one (day), else another one of the place. */
    private static function baseBackground(array $backgrounds, array $entry, ?string $except): ?array
    {
        $key = fn($b) => is_string($b['place'] ?? null) && $b['place'] !== '' ? $b['place'] : 'name:'.Vn::slug((string)$b['name']);
        $mine = array_values(array_filter($backgrounds, fn($b) => $key($b) === $key($entry) && $b['id'] !== $except && (is_string($b['image'] ?? null) || is_string($b['job'] ?? null))));
        $rank = fn($b) => (in_array($b['variant'], self::PLAIN, true) ? 0 : 2) + (is_string($b['image'] ?? null) ? 0 : 1);
        usort($mine, fn($a, $b) => $rank($a) <=> $rank($b));
        return $mine[0] ?? null;
    }

    /** The image model for a sprite: the story's, unless it can't make a transparent background and an OpenAI key can. */
    private function spriteModel(array $writer, string $key): array
    {
        $model = $writer['image_model'];
        if ((Images::model($model)['transparent'] ?? true) !== false) return [$model, true];
        if ($this->vault->apiKey($key) !== '') return [Writer::DEFAULTS['image_model'], true];
        return [$model, false];
    }

    /**
     * A new picture of the visual novel's art, by the writer or the person: a sprite, a background or a CG, from the
     * sheets. A sprite of a character who has one already is an edit of their base sprite, and a variant of a place's
     * background an edit of its base one (unless from_scratch). The entry shows the old picture, if any, until the new
     * one is ready. $in: what names the entry, the prompt ('' from the page: one made from the sheets' notes),
     * references, from_scratch.
     * @return array{0:array,1:array,2:string} the entry, the request (with left: the credit line for the writer) and how it is made
     */
    public function makeVnArt(string $storyId, array $writer, string $kind, array $in, string $by, string $key, ?ToolContext $c = null): array
    {
        $vn = $this->stories->vn(); $person = $by === 'person';
        $settings = $vn->settings($storyId, $key)['settings'];
        $entry = $vn->artKey($storyId, $kind, $in, $key);
        $art = $vn->art($storyId, $key);
        [, $at] = $vn->findArt($art, $kind, $entry, $storyId, $key);
        $current = $at !== null ? $art[Vn::ART_LISTS[$kind]][$at] : null;
        if ($current !== null && is_string($current['job'] ?? null) && !$this->jobFailed($current['job'], $key))
            throw new HttpError(422, $person ? t('That art is already being made. Wait for it to be ready.') : 'That art is already being made (request '.$current['job'].'). Wait for it.');
        $prompt = trim(Stories::cleanText((string)($in['prompt'] ?? ''), 4000, $person ? t('The description') : 'prompt'));
        $scratch = ($in['from_scratch'] ?? false) === true;
        $wait = $c?->tick;
        $refs = $this->referenceIds(is_array($in['references'] ?? null) ? $in['references'] : [], $key, $wait);
        $left = $c !== null && $c->by !== 'person' ? self::leftLine($this->imageBudget($c)) : '';
        $cast = $this->stories->cast($storyId, $key)['characters'];
        $sheetOf = function (string $name) use ($cast): ?array { $n = Stories::findCharacter($cast, $name); return $n !== null ? $cast[$n] : null; };
        $mainPicture = fn(?array $sheet) => $sheet !== null && is_string($sheet['images'][0]['image'] ?? null) ? $sheet['images'][0]['image'] : null;
        $album = $this->stories->album($storyId, $key);
        $how = '';

        if ($kind === 'sprite') {
            $sheet = $sheetOf($entry['who']);
            [$model, $transparent] = $this->spriteModel($writer, $key);
            Images::requireKey($this->vault, $key, $model);
            $framing = ['full'=>'the whole body, head to feet', 'knees'=>'from the head down to the knees', 'waist'=>'from the head down to the waist'][$settings['framing']];
            $back = $transparent ? 'Transparent background: no scenery, floor, shadow, frame, border, text or labels; only the character.' : 'Plain flat pure white background: no scenery, floor, shadow, frame, border, text or labels; only the character.';
            $look = trim($entry['outfit'] !== '' ? $entry['outfit'].' outfit, '.$entry['expression'].' expression' : $entry['expression'].' expression');
            if ($prompt === '') $prompt = $entry['who'].', '.$look.'.'.($sheet !== null && $sheet['note'] !== '' ? ' '.$sheet['note'].'.' : '');
            $base = $scratch ? null : self::baseSprite($art['sprites'], $entry['character'], $entry['outfit'], $current['id'] ?? null);
            $options = ['model'=>$model, 'quality'=>$writer['image_quality'], 'thinking'=>'off', 'n'=>1, 'previews'=>1, 'format'=>'png'] + ($transparent ? ['background'=>'transparent'] : []);
            if ($base !== null) {
                $baseImage = is_string($base['image'] ?? null) ? $base['image'] : null;
                if ($baseImage === null) {
                    if ($c === null) throw new HttpError(422, t('The base sprite of {who} is still being made. Wait for it to be ready to make the other expressions (or turn on “From scratch”).', ['who'=>$entry['who']]));
                    $baseImage = $this->referenceIds([(string)$base['job']], $key, $wait)[0]['id'];
                }
                $source = $this->vault->still($baseImage, $key);
                $options['size'] = Mcp::editSize((int)$source['width'], (int)$source['height'], (bool)(Images::model($model)['flex'] ?? false));
                $changes = [];
                if ($base['expression'] !== $entry['expression']) $changes[] = 'the facial expression becomes '.$entry['expression'];
                if ($base['outfit'] !== $entry['outfit']) $changes[] = $entry['outfit'] !== '' ? 'the outfit becomes the '.$entry['outfit'].' outfit' : 'the outfit becomes their usual one, as in their sheet';
                $text = 'Edit @1, a visual novel sprite of '.$entry['who'].': '.($changes ? implode(' and ', $changes) : 'remake it as described').'. '.$prompt."\n\n"
                    .'Keep everything else exactly as in @1: the same character (face shape, eyes, hair, skin tone, body and proportions)'.($base['outfit'] !== $entry['outfit'] ? '' : ', the same outfit').', the same art style, line and colors, '
                    .'the same framing ('.$framing.'), size and position on the canvas, so the two sprites line up when one replaces the other on screen. '
                    .'Only what is asked changes (the expression, and the arms or a gesture when the description says so). '.$back;
                $sheetPicture = $mainPicture($sheet);
                $references = array_merge([['id'=>$baseImage]], array_values(array_filter($refs, fn($r) => $r['id'] !== $baseImage)));
                if ($sheetPicture !== null && !in_array($sheetPicture, array_column($references, 'id'), true)) {
                    $references[] = ['id'=>$sheetPicture];
                    $text .= ' Attached image '.count($references).' is '.$entry['who'].'\'s reference sheet: the character must keep matching it.';
                }
                $job = $this->jobs->create(['id'=>self::newId(), 'prompt'=>$text, 'label'=>'Sprite · '.$entry['who'].' ('.$look.')', 'options'=>$options, 'references'=>$references,
                    'parent_id'=>$baseImage, 'operation'=>'edit', 'album'=>$album], $key)[0];
                $how = 'as an edit of the sprite of '.$entry['who'].' ('.trim($base['outfit'].' '.$base['expression']).')';
            } else {
                $sheetPicture = $mainPicture($sheet);
                if ($sheetPicture === null && $refs === [])
                    throw new HttpError(422, $person ? t('{who}\'s sheet has no picture yet. Make the sheet first (Sheets and style): the sprite is made from it.', ['who'=>$entry['who']]) : $entry['who'].' has no sheet picture yet, which the sprite is made from. Make it first (character_sheet), or pass a reference.');
                $references = $refs;
                $text = $prompt."\n\nA visual novel character sprite of ".$entry['who'].', '.$look.': one character alone, standing, facing the viewer in a slight three-quarter turn, framed '.$framing
                    .', centered with a small margin, nothing cut off except at the framing edge. Clean, even, neutral lighting so it fits any background. '.$back;
                if ($sheetPicture !== null && !in_array($sheetPicture, array_column($references, 'id'), true)) {
                    $references[] = ['id'=>$sheetPicture];
                    $text .= "\n\nAttached image ".count($references).' is '.$entry['who'].'\'s reference sheet: match it exactly (face, eyes, hair, skin tone, build, outfit, colors, marks).';
                }
                $job = $this->imageRequest($storyId, ['image_model'=>$model] + $writer, $text, 'Sprite · '.$entry['who'].' ('.$look.')',
                    ['full'=>'9:16', 'knees'=>'2:3', 'waist'=>'3:4'][$settings['framing']], $references, $key, false, null, ['options'=>$options]);
                $how = 'from '.$entry['who'].'\'s sheet'.($sheetPicture === null ? ' (no sheet picture: from your references)' : '');
            }
            if (!$transparent) $how .= '; the image model can\'t make a transparent background and there is no OpenAI key, so it has a plain white one';
        } elseif ($kind === 'background') {
            Images::requireKey($this->vault, $key, $writer['image_model']);
            $place = $entry['place'] !== null ? $sheetOf($entry['name']) : null;
            if ($prompt === '') $prompt = $entry['name'].($entry['variant'] !== '' ? ', '.$entry['variant'] : '').'.'.($place !== null && $place['note'] !== '' ? ' '.$place['note'].'.' : '');
            $base = $scratch || in_array($entry['variant'], self::PLAIN, true) ? null : self::baseBackground($art['backgrounds'], $entry, $current['id'] ?? null);
            $flex = (bool)(Images::model($writer['image_model'])['flex'] ?? false);
            $options = ['model'=>$writer['image_model'], 'quality'=>$writer['image_quality'], 'thinking'=>'off', 'n'=>1, 'previews'=>1];
            if ($base !== null) {
                $baseImage = is_string($base['image'] ?? null) ? $base['image'] : null;
                if ($baseImage === null) {
                    if ($c === null) throw new HttpError(422, t('The base background of {place} is still being made. Wait for it to be ready to make the variations (or turn on “From scratch”).', ['place'=>$entry['name']]));
                    $baseImage = $this->referenceIds([(string)$base['job']], $key, $wait)[0]['id'];
                }
                $source = $this->vault->still($baseImage, $key);
                $options['size'] = Mcp::editSize((int)$source['width'], (int)$source['height'], $flex);
                $text = 'Edit @1, a visual novel background of '.$entry['name'].': show the same place '.$entry['variant'].'. '.$prompt."\n\n"
                    .'Keep the place exactly as in @1: the same viewpoint, framing and perspective, the same architecture, furniture, objects and layout, in the same art style. '
                    .'Change only what '.$entry['variant'].' brings: the light, the sky, the weather, the shadows and the atmosphere. No people and no text.';
                $references = array_merge([['id'=>$baseImage]], array_values(array_filter($refs, fn($r) => $r['id'] !== $baseImage)));
                $job = $this->jobs->create(['id'=>self::newId(), 'prompt'=>$text, 'label'=>t('Background · {name}{variant}', ['name'=>$entry['name'], 'variant'=>($entry['variant'] !== '' ? ' ('.$entry['variant'].')' : '')]), 'options'=>$options,
                    'references'=>$references, 'parent_id'=>$baseImage, 'operation'=>'edit', 'album'=>$album], $key)[0];
                $how = 'as an edit of the place\'s base background';
            } else {
                $references = $refs;
                $text = $prompt."\n\nA visual novel background: ".$entry['name'].($entry['variant'] !== '' ? ', '.$entry['variant'] : '').', seen at eye level as the player would stand in it, '
                    .'with room in the middle and foreground for characters to stand in front of it. No people or characters, no text, no UI, no frame.';
                $sheetPicture = $mainPicture($place);
                if ($sheetPicture !== null && !in_array($sheetPicture, array_column($references, 'id'), true)) {
                    $references[] = ['id'=>$sheetPicture];
                    $text .= "\n\nAttached image ".count($references).' is the reference sheet of this place: it must be recognizably the same place (architecture, layout, colors, details).';
                }
                $job = $this->imageRequest($storyId, $writer, $text, t('Background · {name}{variant}', ['name'=>$entry['name'], 'variant'=>($entry['variant'] !== '' ? ' ('.$entry['variant'].')' : '')]), $settings['aspect'], $references, $key);
                $how = $sheetPicture !== null ? 'from the place\'s sheet' : 'from your description (the place has no sheet picture)';
            }
        } else {
            Images::requireKey($this->vault, $key, $writer['image_model']);
            if ($prompt === '') throw new HttpError(422, $person ? t('Describe the CG: the moment, the place, who is in it.') : 'prompt is empty.');
            $references = $refs; $text = $prompt."\n\nA visual novel CG: a full-screen event illustration of this moment, cinematic and expressive. No text, no UI, no speech balloons, no frame.";
            $names = [];
            foreach (is_array($in['characters'] ?? null) ? $in['characters'] : [] as $who) {
                $sheet = $sheetOf((string)$who);
                if ($sheet === null || ($sheet['kind'] ?? 'character') !== 'character') throw new HttpError(422, $person ? t('There is no sheet of “{who}”.', ['who'=>$who]) : 'There is no character sheet of "'.$who.'".');
                $picture = $mainPicture($sheet);
                if ($picture === null || in_array($picture, array_column($references, 'id'), true)) continue;
                $references[] = ['id'=>$picture]; $names[] = 'attached image '.count($references).' is '.$sheet['name'].'\'s reference sheet';
            }
            if ($names) $text .= "\n\n".ucfirst(implode('; ', $names)).': each character must match their sheet exactly.';
            $job = $this->imageRequest($storyId, $writer, $text, 'CG · '.$entry['title'], $settings['aspect'], $references, $key);
            $how = $names ? 'with the sheets of '.count($names).' character'.(count($names) === 1 ? '' : 's') : 'from your description';
        }
        $put = $entry + ['job'=>$job['id'], 'prompt'=>$prompt];
        unset($put['who']);
        if (isset($in['note'])) $put['note'] = $in['note'];
        $saved = $vn->putArt($storyId, $kind, $put, $by, $key);
        return [$saved + ['who'=>$entry['who'] ?? null], $job + ['left'=>$left], $how];
    }

    /** Whether a request ended without an image (the art may then be made again). */
    private function jobFailed(string $jobId, string $key): bool
    {
        $job = $this->jobs->freshJob($jobId, $key);
        return $job === null || !in_array($job['status'], self::ACTIVE, true) && !($job['status'] === 'completed' && $job['image_ids']);
    }

    /** A request of the art that failed, made again by the person. */
    public function retryVnArt(string $storyId, string $jobId, string $key): array
    {
        $vn = $this->stories->vn(); $vn->project($storyId, $key);
        if (!isset($vn->jobs($storyId, $key)[$jobId])) throw new HttpError(404, t('That art is no longer in the project.'));
        $job = $this->jobs->retry(self::newId(), $jobId, null, $key)[0];
        $vn->retryJob($storyId, $jobId, $job['id'], $key);
        return $job;
    }

    /** The visual novel's part of the story's state, for the writer. */
    private function vnState(string $storyId, string $key, array $failed): string
    {
        $vnFailed = [];
        foreach ($failed as $jobId => $f) if (($f['for'] ?? null) === 'vn') $vnFailed[$jobId] = true;
        $vn = $this->stories->vn();
        $s = $vn->settings($storyId, $key);
        return "\nThe visual novel (the person sees all of it in the app's Visual Novel view):\n".$vn->outline($storyId, $key, $vnFailed)
            ."\nGameplay notes (visible to the person), revision ".$s['gameplay']['rev'].":\n<gameplay>\n".($s['gameplay']['text'] !== '' ? $s['gameplay']['text'] : '(empty)')."\n</gameplay>\n";
    }

    /**
     * The full text for the start of a visual novel's conversation: the scenes' scripts and the chapters' base texts,
     * all of it while it fits in the room a book gets (bookText()). Past that, first what the writer works on: the
     * scenes of the route of the scene changed last (the latest first) and the common scenes (no route, or a route
     * named as the common or main one), then the chapters no scene
     * adapts yet (the next ones first), then the other routes, then the chapters already adapted; the rest stays in the
     * outline, by its summary. @return array{0:string,1:string,2:array<string,int>} the chapters' text, the scenes'
     * text, and the revision of each chapter in it by id
     */
    private function vnOpening(string $storyId, array $chapters, string $provider, string $key): array
    {
        $vn = $this->stories->vn();
        $scenes = $vn->scenes($storyId, $key);
        if (!$scenes && !$chapters) return ['', '', []];
        $routes = [];
        foreach ($vn->settings($storyId, $key)['routes'] as $r) $routes[$r['id']] = $r['name'];
        $numbers = [];
        foreach ($chapters as $n => $c) $numbers[$c['id']] = $n + 1;
        // Estimated tokens: prose at about 1.4 a word, a script (names, commands) at about one every 3 characters.
        $cost = fn(string $kind, array $x): int => $kind === 'scene' ? (int)ceil(mb_strlen($x['script'].$x['summary'].$x['notes']) / 3) + 40 : (int)ceil(Stories::words($x['text']) * 1.4) + 20;
        $latest = null;
        foreach ($scenes as $sc) if ($latest === null || $sc['updated'] > $latest['updated']) $latest = $sc;
        $current = $latest['route'] ?? null;
        $adapted = [];
        foreach ($scenes as $sc) if ($sc['chapter'] !== null) $adapted[$sc['chapter']] = true;
        $common = array_keys(array_filter($routes, fn($name) => preg_match('/\b(comum|common|compartilhada|shared|principal|main|pr[oó]logo|prologue)\b/iu', $name) === 1));
        $near = fn(array $sc): bool => $sc['route'] === null || $sc['route'] === $current || in_array($sc['route'], $common, true);
        $mine = array_filter($scenes, $near);
        $groups = [
            array_map(fn($sc) => ['scene', $sc], array_reverse($mine)),
            array_map(fn($c) => ['chapter', $c], array_values(array_filter($chapters, fn($c) => !isset($adapted[$c['id']])))),
            array_map(fn($sc) => ['scene', $sc], array_values(array_filter($scenes, fn($sc) => !$near($sc)))),
            array_map(fn($c) => ['chapter', $c], array_values(array_filter($chapters, fn($c) => isset($adapted[$c['id']])))),
        ];
        $room = intdiv(self::newConversationAt($provider) * 2, 5); $used = 0; $in = ['scene'=>[], 'chapter'=>[]];
        // Each group in its order, up to the first that doesn't fit; then the next group.
        foreach ($groups as $group) foreach ($group as [$kind, $x]) {
            $t = $cost($kind, $x);
            if ($used + $t > $room) break;
            $used += $t; $in[$kind][$x['id']] = true;
        }
        $book = ''; $revs = [];
        $keptChapters = array_values(array_filter($chapters, fn($c) => isset($in['chapter'][$c['id']])));
        if ($keptChapters) {
            $book = "\nThe chapters' base texts, which the scenes adapt".(count($keptChapters) < count($chapters)
                ? ' (only chapters '.implode(', ', array_map(fn($c) => $numbers[$c['id']], $keptChapters)).' here; the others are in the summaries above, and read_chapter brings any of them)' : '')
                .":\n<book>\n";
            foreach ($keptChapters as $c) { $book .= self::chapterBlock($numbers[$c['id']], $c); $revs[(string)$c['id']] = (int)$c['rev']; }
            $book .= "</book>\n";
        }
        $kept = array_values(array_filter($scenes, fn($sc) => isset($in['scene'][$sc['id']])));
        $text = '';
        if ($kept) {
            $text = "\nThe scenes in full, as they are now".(count($kept) < count($scenes)
                ? ' ('.count($kept).' of the '.count($scenes).': first the route you worked on last'.($current !== null && isset($routes[$current]) ? ', '.$routes[$current] : '')
                    .', with the common scenes; the others are only in the outline above, by their summary, and read_scenes brings any of them)' : '')
                .":\n<scenes>\n";
            foreach ($kept as $sc) {
                $text .= '<scene label="'.$sc['label'].'">'."\nTitle: ".$sc['title'].' (scene '.$sc['number']
                    .($sc['chapter'] !== null && isset($numbers[$sc['chapter']]) ? ', chapter '.$numbers[$sc['chapter']] : '')
                    .($sc['route'] !== null && isset($routes[$sc['route']]) ? ', route '.$routes[$sc['route']] : '').")\n"
                    .($sc['summary'] !== '' ? 'Summary: '.$sc['summary']."\n" : '').($sc['notes'] !== '' ? 'Gameplay notes: '.$sc['notes']."\n" : '')
                    ."Script:\n".($sc['script'] !== '' ? rtrim($sc['script']) : '(empty)')."\nExit: ".Vn::exitLine($sc['exit'])."\n</scene>\n\n";
            }
            $text .= "</scenes>\nYou don't need read_scenes for the scenes in <scenes>, nor read_chapter for the chapters in <book>, unless a <story_update> says the person changed one.\n";
        } elseif ($book !== '') $book .= "You don't need read_chapter for the chapters in <book> unless a <story_update> says the person changed one.\n";
        return [$book, $text, $revs];
    }

    /** The revisions of the visual novel, to tell later what changed. */
    private function vnSnapshot(string $storyId, string $key): array
    {
        $vn = $this->stories->vn();
        $s = $vn->settings($storyId, $key); $art = $vn->art($storyId, $key);
        $scenes = [];
        foreach ($vn->scenes($storyId, $key) as $sc) $scenes[$sc['id']] = ['rev'=>$sc['rev'], 'label'=>$sc['label']];
        return ['settings'=>(int)$s['rev'], 'gameplay'=>(int)$s['gameplay']['rev'], 'art'=>(int)$art['rev'], 'scenes'=>$scenes];
    }

    /** What changed in the visual novel since the writer's last turn: lines for <story_update>, and the new snapshot. */
    private function vnUpdate(string $storyId, array $seen, string $key, array $failed): array
    {
        $vn = $this->stories->vn(); $lines = [];
        $now = $this->vnSnapshot($storyId, $key);
        $was = is_array($seen['vn'] ?? null) ? $seen['vn'] : null;
        if ($was === null) return [[], $now];
        $s = $vn->settings($storyId, $key);
        $scenes = $vn->scenes($storyId, $key);
        foreach ($scenes as $sc) {
            $before = $was['scenes'][$sc['id']] ?? null;
            if ($before === null) $lines[] = 'Scene '.$sc['label'].' "'.$sc['title'].'" was added by '.self::who($sc['by']).'. Read it (read_scenes) before you rely on it.';
            elseif ((int)$before['rev'] !== (int)$sc['rev'] && $sc['by'] !== 'agent') $lines[] = 'Scene '.$sc['label'].' "'.$sc['title'].'" was edited by '.self::who($sc['by']).($before['label'] !== $sc['label'] ? ' (it was '.$before['label'].')' : '').'. Read it again before relying on what you remember of it.';
            elseif ($before['label'] !== $sc['label']) $lines[] = 'Scene '.$before['label'].' was renamed '.$sc['label'].'.';
        }
        $ids = array_column($scenes, 'id');
        foreach ($was['scenes'] as $id => $before) if (!in_array($id, $ids, true)) $lines[] = 'Scene '.$before['label'].' was deleted by the person.';
        if ($was['settings'] !== $now['settings'] && $s['by'] !== 'agent') $lines[] = ucfirst(self::who($s['by'])).' changed the visual novel\'s settings or lists. Now: '.rtrim(Vn::settingsLines($s));
        if ($was['gameplay'] !== $now['gameplay'] && $s['gameplay']['by'] !== 'agent') $lines[] = ucfirst(self::who($s['gameplay']['by'])).' edited the gameplay notes. They now read:'."\n<gameplay>\n".$s['gameplay']['text']."\n</gameplay>";
        $art = $vn->art($storyId, $key);
        if ($was['art'] !== $now['art'] && $art['by'] !== 'agent') {
            $vnFailed = [];
            foreach ($failed as $jobId => $f) if (($f['for'] ?? null) === 'vn') $vnFailed[$jobId] = true;
            $lines[] = ucfirst(self::who($art['by'])).' changed the art. Now:'."\n".rtrim($vn->artLines($art, $this->stories->cast($storyId, $key)['characters'], $vnFailed));
        }
        return [$lines, $now];
    }

    /** The writer's own changes to the visual novel, as known. */
    private function vnKnow(string $storyId, array $seen, string $key): array
    {
        $vn = $this->stories->vn(); $now = $this->vnSnapshot($storyId, $key);
        if (!is_array($seen['vn'] ?? null)) return $seen;
        $s = $vn->settings($storyId, $key); $art = $vn->art($storyId, $key);
        if ($s['by'] === 'agent') $seen['vn']['settings'] = $now['settings'];
        if ($s['gameplay']['by'] === 'agent') $seen['vn']['gameplay'] = $now['gameplay'];
        if ($art['by'] === 'agent' || $art['by'] === null) $seen['vn']['art'] = $now['art'];
        foreach ($vn->scenes($storyId, $key) as $sc) if ($sc['by'] === 'agent') $seen['vn']['scenes'][$sc['id']] = $now['scenes'][$sc['id']];
        foreach (array_keys($seen['vn']['scenes']) as $id) if (!isset($now['scenes'][$id]) && ($seen['agent_scene_deleted'][$id] ?? false)) unset($seen['vn']['scenes'][$id]);
        unset($seen['agent_scene_deleted']);
        return $seen;
    }
}
