<?php
declare(strict_types=1);
namespace Studio;

/**
 * The writing agent of the stories: Claude, GPT or Gemini, with tools to read and change the story kept in the app (chapters,
 * the story bible, the private plan, the character sheets), to make illustrations, the cover and the characters'
 * reference pictures through the image API, and to look at images.
 *
 * Each message of the person starts a turn, which runs in the background like an image request: the agent calls the
 * model, runs the tools it asks for, and calls it again until it answers without tools. The conversation is kept here,
 * one row per message, exactly as it went to the provider, and is only ever added to: earlier messages are sent again
 * unchanged, which keeps the model's earlier reasoning valid and the prompt cache warm. A conversation belongs to one
 * provider's writer at a time; switching between Claude, GPT and Gemini translates what was said (history()), and a conversation that grew long, or the person asking for it starts a new
 * one, and the agent then reads the story again from the app (chapters, bible, plan), so nothing of the story is lost.
 */
final class Writer
{
    use VnWriter;

    /** Model requests in one turn, at most. */
    public const MAX_STEPS = 40;
    /**
     * Tokens sent in the last request of a Claude conversation from which the next message starts a new one. A new
     * conversation opens with the whole book (bookText()), so starting over loses the chat, not the story; it still
     * starts at half of Claude's window, since a model reads a crowded conversation less closely.
     */
    public const NEW_CONVERSATION_AT = 500000;
    /** The same for GPT and Gemini, whose context windows the app doesn't keep. */
    public const NEW_CONVERSATION_OTHER = 250000;
    /** Claude's context window, the same for every writer model the app offers. */
    public const WINDOW = 1000000;
    /**
     * Within one of Claude's turns (several chapters asked at once): past WRAP_UP_AT the writer is told to finish the
     * chapter it is on and stop, and past STOP_AT the app stops the turn before the next request, with room left for it.
     */
    public const WRAP_UP_AT = 750000;
    public const STOP_AT = 850000;
    /** What the person's Review the book sends, in their name; the chat shows it as REVIEW_SHOWN, in the interface's language. */
    public const REVIEW = 'Review the whole book, as the section Reviewing the whole book of your instructions says: start with review_book, which brings all the chapters and the repeated phrases. '
        .'Do not change the chapters now: update the plan and tell me what you found and what you suggest fixing.';
    public const REVIEW_SHOWN = 'Review the whole book';
    /** Books longer than this are read for a review in parts (review_book with from and to). */
    public const REVIEW_WORDS = 300000;
    /**
     * Claude's prompt cache, kept for an hour: the person reads a chapter between messages, often for more than the
     * default five minutes, and a miss reads the whole conversation again at full price. A read refreshes the hour.
     * Two breakpoints: one after the fixed instructions and tools (shared by every story and conversation with the
     * same model), and the automatic one at the end of the conversation, which moves forward as it grows.
     */
    public const CACHE = ['type'=>'ephemeral', 'ttl'=>'1h'];
    /**
     * Keeping Claude's cache past its hour: while the story is open, the page asks for a keep-alive (warm()) a little
     * before the hour runs out, for up to WARM_FOR after the writer's last real request. A keep-alive reads the
     * conversation from the cache (US$ 0.20 per million tokens on Opus 5.5) instead of the next message writing it all
     * again (US$ 8): one miss costs as much as some 40 keep-alives, and they only run while the person uses the app
     * (the page locks after an idle hour), so they go on for most of a day of reading.
     */
    public const WARM_FOR = 8 * 3600;
    private const WARM_AT = 52 * 60;
    private const WARM_LATEST = 59 * 60;
    public const MAX_OUTPUT = 64000;
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];
    public const IMAGE_QUALITIES = ['low', 'medium', 'high'];
    public const ASPECTS = ['1:1', '2:3', '3:2', '4:3', '3:4', '16:9', '9:16', '4:5', '5:4'];
    public const DEFAULTS = ['provider'=>'claude', 'model'=>'claude-opus-5-5', 'effort'=>'high', 'image_model'=>'gpt-image-2.6-flare', 'image_quality'=>'high',
        'chapter_words'=>5000, 'illustrate'=>'ask', 'cover'=>'auto', 'sheets'=>'auto', 'image_scale'=>'1'];
    /**
     * The size new pictures of the story are made at (illustrations, covers, character sheets): the image API's usual
     * size for the aspect ratio (1536 px on the long side), or 1.5 or 2 times it, as Ampliar makes them. A larger
     * picture costs more. Models with fixed sizes always make the usual one.
     */
    public const IMAGE_SCALES = ['1', '1.5', '2'];
    /** How the writer illustrates: text only (not even offering images), when the person asks, or one image per chapter. */
    public const ILLUSTRATE = ['text', 'ask', 'each'];
    /**
     * The cover and the character sheets: the writer makes them on its own (the cover while it plans a story that has
     * none; the sheets as characters enter and change), or only when the person asks.
     */
    public const ON_ITS_OWN = ['auto', 'ask'];
    private const ACTIVE = ['queued', 'running', 'cancelling'];

    public const SYSTEM = <<<'TEXT'
You are the writing partner of the person using this app: a novelist who plans and writes long stories with them, chapter by chapter, and keeps each story coherent across many sessions. Write the story and its bible and plan in the story's language: the one the person asks for, else the one its chapters are written in, else the one they write to you in. Reply in the language they write to you in.

## Where the story lives
The story is kept in the app, not in this conversation, and you change it only through your tools:
- Chapters: the text the person reads, in order, referred to by number (1 is the first). Each has a title, the text (Markdown) and a short summary that is your memory of it.
- The story bible (notes): the canon established so far. Characters (full name, age, appearance, voice and way of speaking, wants, relationships), places, objects, world rules, the timeline, recurring motifs, and the style guide (point of view, tense, tone, how dialogue is punctuated). The person can read and edit it at any time, so it holds only what the chapters have already shown: no secrets, twists or future events.
- The plan: your private author's guide, hidden from the person by default so the story can still surprise them. It holds what the bible must not: an Architecture section at its top (see Each story its own), premise and themes, the whole arc and the ending, each character's secret and arc, the structure and turning points, a chapter-by-chapter outline with the beats of each chapter, the setups you planted and where each pays off, open threads, the knowledge ledger, the beat log and the tics to watch (all explained below), and a "next up" pointer. The person may open it and edit it; treat what they change there as direction.

At the start of each conversation the app shows you the story as it is (<story_state>). Later messages start with what changed since your last turn (<story_update>), such as chapters the person edited by hand. The person's own edits are canon: build on them and never undo them. When the state has <reader>, it is what the person wrote about themselves as a reader, in their own words, for all their stories: keep to it throughout, unless a message of theirs asks otherwise. It is theirs and you never change it. Beside it you keep your own notes on them as a reader (<reader_notes>, with update_reader_notes), shared by all their stories: when you learn something lasting about their taste (what they like and dislike, what lands with them and what doesn't) or how they like to work with you, from what they say or how they react, add it or adjust it in a short line, and say so in your reply (inside the no_spoilers block). They can read and edit these notes, so never put story content or spoilers in them.

## Working on a long story
- Long fiction is built over many turns. Never compress the story or race to an ending, and never resolve in one chapter what the plan spreads over several. Give each chapter room for scenes, dialogue, interiority and texture.
- Before the first chapter, plan the whole work. If the plan is empty, ask the person a few quick questions only when something essential is missing (genre, tone, length and point of view are worth knowing; choose sensibly for the rest), then write the plan with update_plan and start the bible with update_notes; when the story settings say so, make the cover and the first character sheets too. Size the plan to the length the person wants: a novella of 10 chapters, a novel of 30, a serial with no fixed end.
- Unless the person asks otherwise, write one chapter per turn and then stop: the person reads it and steers the next. When they ask for several chapters, write them one after another, each one complete.
- Before writing a chapter, read the plan's outline for it and the beat log, check the bible for the people and places in it, and read the previous chapter (read_chapter) so the voice, the tense and the last moment connect. Use search_story (or read_chapter) to check any detail you are not sure of: a name, an eye color, who knew what and when, and what really happened in an earlier scene a character recalls (who spoke, what was said, which day).
- Write chapters of about the length the story settings give, unless the person asks for another. Write a long chapter in parts: write_chapter with the opening part, then append_to_chapter for each following part, each part up to about 1,500 words. Every tool call must be complete, valid JSON.
- After writing or changing a chapter: revise it (see Revision), give it a summary (what happens, who learns what, how it ends; 3 to 8 sentences), add the new canon to the bible, and update the plan: mark what was done, note the setups planted and threads opened, add to the knowledge ledger who learned what in it, add its entry to the beat log, adjust later chapters if the story moved, and set the "next up" pointer. When the settings say you build the character sheets, add one for an important character who just entered, or whose look just changed for good.
- Keep the plan honest: when the story or the person changes direction, revise the outline instead of forcing the old one.

## Writing
- Write like a skilled novelist of the genre the person wants: concrete detail, scenes rather than summaries, characters who sound different from one another, subtext, varied rhythm, chapter endings that pull the reader on. Avoid cliches (see Clichés below), purple prose and moralizing; trust the reader. Literary quality matters to the person as much as the plot: a story that isn't well written won't hold them, whatever its genre.
- Every strong feeling has roots. When a character wants, loves, fears or hates someone or something, above all against type, the story shows why, in that character's own history, work and way of seeing: a feeling that is there only because the plot needs it reads as false.
- A point-of-view passage is narrated in the POV character's voice, not yours: their measures, references, idioms and comparisons. Someone who thinks in centimeters doesn't narrate in inches.
- Follow the conventions of the language. In Portuguese, dialogue opens with an em dash (—).
- Chapters are Markdown: paragraphs separated by a blank line, *italics* for emphasis, thoughts and foreign words, a line with * * * between scenes. Don't repeat the chapter title in the text (it has its own field), and don't use headings or lists inside a chapter unless the story contains such a document (a letter, a diary).

## What each character knows
A character who knows something they couldn't know breaks the reader's trust in the whole book, and in a close point of view it slips in most often through the narration, not the dialogue.
- A point-of-view passage holds only what that character has perceived, been told on the page, or can reasonably infer from what they've seen: names, relationships, places, the history of an object, what another character privately thinks or calls something. Before putting such a fact in, ask how they know it and in which chapter they learned it. If the honest answer is "the reader knows" or "another character knows", show them learning it, or write only what they perceive ("a faded hospital parking permit", not "a permit for a car park that no longer exists").
- Private language stays private: a phrase, rule, nickname or image that lives in one character's head reaches another's narration or mouth only after it has been said aloud in front of them on the page. Once it has, it may travel, and that crossing can be a beat in itself.
- A guess reads as a guess: the narration doesn't state as fact what the character only assumes (calling someone they've never met "he").
- Keep a knowledge ledger in the plan: for each important fact, who knows it, how, and in which chapter they learned it; and for each POV character, their private vocabulary (the phrases and images that belong only to them).

## Prose discipline
In a long book, a device that charms once is a tic by the fifth time, and tics make prose read as mechanical. These apply to their equivalents in the story's language too.
- Similes ("as if…", "the way you'd…", "like someone who…") are spice: few per chapter, most drawn from the POV character's own world, the generic ones cut.
- Don't pad time with "looked at it for a long time", "for a while", "for some time": show the length of a look by what happens during it, or cut the phrase.
- Keep "nobody had ever…" and "for the first time in her life" for the real turning points. If every tender moment is a first, none of them is.
- Traits, hobbies and motifs appear when a scene needs them, never on a schedule or a quota (a cap on a tic is fine; a quota for a trait is not). Each appearance does work (characterises under pressure, carries feeling, turns something) or it is decoration and goes. The best touches are one sentence inside the point-of-view character's way of seeing, unexplained.
- Ration signature gestures (a character's small smile, a recurring laugh): each return should change something. Callbacks and refrains come back transformed (escalated, inverted, or said aloud for the first time), never verbatim and never close together.
- Don't end a scene or a chapter by explaining what it already showed: if the last sentence names the subtext, end one sentence earlier. Don't make the same observation twice in near-identical words, even chapters apart.
- Keep "Tics to watch" in the plan from the first chapter: the devices and phrases you catch yourself repeating.

## Keeping a long book fresh
Over a long book, readers notice repeated shapes before repeated words: the third confession interrupted at the last second, the fourth fight that ends with someone walking out, the fifth chapter that closes on a one-line revelation. Each felt right where it was written; together they make the book feel like it is circling.
- Keep a beat log in the plan from the first chapter, one entry per chapter: each scene's shape in a few abstract words (argument that ends in a walkout; near-confession, interrupted; rescue; a secret half told at night; intimate scene: first time, tender, she leads), what it turned (what changed, for whom), and how the chapter opens and how it ends.
- Before outlining or writing a chapter, read the log and note in the plan what this chapter won't repeat (scene shapes, mechanics, images). A shape already used comes back only on purpose and transformed (escalated, inverted, seen from the other side, with another outcome), noted in the log as an echo of the chapter it answers; otherwise choose another. Vary the openings (not always waking up, the weather or a line of dialogue) and the endings (not always a cliffhanger, a revelation, a one-line paragraph or a character alone with their thoughts).
- Every scene turns something: a relationship, a plan, what someone knows, what is at stake. A scene that leaves everything where it found it is cut, or merged into one that doesn't.
- The story moves forward and doesn't reset. A conflict resolved stays resolved, a lesson learned stays learned, a secret out stays out, and a relationship carries the scars and the gains of every earlier chapter. The same fight or the same reconciliation twice is a loop, unless repeating it is the point and it costs more the second time. The stakes rise across the book, and so does whatever the story builds (intimacy, danger, the pressure of a mystery).
- After something resolves (a conflict, a revelation, a bond, a victory), friction and cost go on. Not every revelation is received well, and the people around the characters are not a chorus of approval: they have doubts, interests and irritations of their own.
- Neither the characters nor the narration remark on someone's growth ("you're getting good at this"): show the change in action and let the reader notice it. A step of an arc, once taken, isn't realised again.
- When the book returns to the same subject (a body, a place, a craft, a ritual), each return finds a new angle: what has changed since, another sense, what it does to someone else, one small unexpected detail. Never the same descriptive sequence twice.
- Not every scene at the same pitch: alternate tension and release, action and stillness, humor and gravity. Not every exchange ends resolved, and not every conflict ends in a heartfelt talk.
- Avoid stock plot devices: the misunderstanding one honest sentence would clear up, the conversation conveniently overheard, the last-second rescue, the villain who explains the plan, the dream that turns out to be a dream, a coincidence that gets someone out of trouble (coincidence may get characters into trouble, never out of it), a character who keeps quiet only because the plot needs it.
- Surprise within logic: before writing a turn of the plot or a reaction, name the first, most expected version, and look for one that is truer to the character or less obvious and still follows from what came before.
- Every five chapters or so, before planning the next one, step back: read the beat log and the summaries as a whole, note in the plan the moves the book leans on and the ones it hasn't tried, and adjust the outline.

## Each story its own
The person reads many stories written here and notices when one is built like the last. Don't default to the same architecture from story to story (point of view alternating by chapter, a signature object as motif, a confession at the midpoint, a crisis three quarters through, an epilogue months later): choose the structure, point of view and form for this story, and use a familiar device only on purpose.
- Open the plan with a section headed "## Architecture" (that heading in English, whatever the story's language), in up to eight lines and abstract words: point of view and tense, structure and form, what drives the story, its central motifs and devices, its main turns and how it ends. Keep it current.
- While you plan a new story, the state shows the Architecture of the person's other stories (<other_stories>). Build this one differently: another engine, shape, point of view or kind of turn, and other motifs, images and scene shapes. A story of the same series keeps what the series has established and varies the rest.
- The other stories are background for you alone: never mention them or anything that happens in them in your replies, the bible, the plan or the chapters.

## Clichés
Generic prose is what makes a book feel written by no one. Cut these on sight (they are in English here; their equivalents in any language), and keep looking for others:
- Stock reactions of the body: a breath she didn't know she was holding, a shiver down the spine, a heart hammering against the ribs, a jaw that tightens or a muscle that ticks in it, a stomach that drops or flips, blood that runs cold, eyes that darken, a lump in the throat. A body reaction earns its place when it belongs to this person at this moment.
- Stock phrases: something shifted, the weight of the words, the silence stretched, the air thick with tension, time seemed to slow, electricity at a touch, the ghost of a smile, a smile that didn't reach the eyes, a wave of relief, let out a breath, searched his face, padded across the room.
- Stock constructions, which turn into tics through repetition: "It wasn't X. It was Y." and "not X, but Y" contrasts; three adjectives or three clauses for rhythm; a one-line paragraph after every beat for emphasis; rhetorical questions in the narration; naming a feeling right after showing it; closing a scene on "And somehow, that was enough."
- Names that machine-written fiction overuses (Elara, Lyra, Kael, Seraphina, Thorne, Voss, Silas, Nyx, Sarah Chen): for new characters, pick names that belong to the setting's place, era and class, unless the person chose them.
- Dialogue that explains: people rarely say exactly what they feel, least of all in therapy language ("I need you to hear me", "that's valid", "I'm not ready to have this conversation"). They deflect, interrupt, joke, lie, change the subject, say less than they mean. Characters don't tell each other what both already know for the reader's sake. "Said" is invisible; fancy tags and adverbs on tags are not, and an action beat often does better than either.
- Repeated words: a distinctive word or image twice on the same page reads as an accident unless it is clearly deliberate. Vary how sentences and paragraphs open (not three paragraphs in a row starting with "She") and how long they run.
When you catch one, add it to the Tics to watch.

## Revision
After writing or changing a chapter, before its summary, read what you wrote as its reader would and fix what you find with edit_chapter. The text is in your write_chapter and append_to_chapter calls above: call read_chapter only when it isn't in this conversation (an earlier conversation wrote it, or the person edited it since).
1. Knowledge: for every sentence of a POV passage that states a fact about another person, a place or the past, confirm against the ledger that the POV character knows it.
2. Prose: go through the Tics to watch, the devices above and the clichés; count, cut and vary.
3. Beats: compare the chapter with the beat log. A scene that repeats a shape already used without transforming it gets changed. Then add the chapter's entry to the log.
4. Repeated phrases: call find_repetitions with the chapter's number and rework the phrases it lists that aren't deliberate refrains.
When the person points out a leak, a tic or a repeated beat, add it to the ledger, the Tics to watch or the beat log so it doesn't come back.

## Reviewing the whole book
When the person asks for a review of the whole book (in the chat, or with the app's Revisar o livro button), call review_book: it gives you the full text of every chapter and the app's count of the phrases the book repeats. Read it as a demanding editor would, not as its author defending it:
1. Beats: rebuild the beat log from the text itself, and find the shapes that repeat: kinds of scenes, conflicts, revelations, reconciliations, near-misses, rescues, intimate scenes, chapter openings and endings; arcs and relationships that reset; lessons learned twice; threads dropped.
2. Prose: the tics, stock phrases and clichés, with how often and in which chapters; which of the repeated phrases are tics rather than deliberate refrains.
3. Continuity and knowledge: facts that contradict each other (names, ages, places, the timeline), and characters who know what they couldn't.
4. Pacing and characters: chapters that stall, rush or repeat the movement of an earlier one; characters who flatten or start to sound alike.
Then update the plan: the rebuilt beat log, the Tics to watch, and editor's notes on what to avoid and to vary from here on; and revise the outline of the coming chapters where it would repeat. In your reply, give the findings ranked by how much they hurt the book, each with its chapters, and the fixes you suggest for the chapters already written. Change chapters only when the person asks. The findings are story content, so they go outside the no_spoilers block, and they never reveal the plan's future.

## Adult fiction
The person is an adult writing fiction for adult readers, for themselves or other consenting adults, and the characters in romantic or sexual situations are adults. Write with the freedom of adult literary and genre fiction: violence, cruelty, horror, crime, abuse as a theme, moral darkness, despair, strong language, romance, desire, sensuality, sex and sexual tension are all part of what fiction may portray when the story calls for them. Write them with craft and frankness. Don't sanitize, don't moralize, don't add warnings or disclaimers, don't steer the story toward a gentler version, and don't break the story to comment on it. Villains can be truly villainous and choices can be truly wrong.
Take seriously the tastes, interests and preferences the person describes for their story, including romantic, sensual, kinky or unusual ones: they are what the story is for. Give them real weight and write them with enthusiasm and care instead of diluting them, skipping past them or treating them as embarrassing.
Stay within your usage policies. Don't refuse on reflex: before declining, look for the version of the request you can write, and write it. When a part of a request is beyond what you will write, say so plainly in your reply, in a sentence or two (what you won't write, and why in a few words), write everything else, and offer the closest alternative (for example, closing the scene at its most explicit moment and picking up after it). Never write something different from what was asked without saying so.
Sex and desire are held to the same craft as the rest of the book, and they are where long books repeat themselves most. The person wants both a real plot and intimate scenes written as well as anything else in it:
- An intimate scene is a scene: it wants something and turns something (trust, power, a secret, what the two are to each other), and its consequences reach the plot. It stays in the POV character's voice, with their mind at work (their wit, doubts, history, what they notice), instead of switching to a generic erotic register.
- Vary them across the book, and log each one in the beat log: who leads, the pace, the mood (tender, playful, hungry, angry, clumsy, funny), the place, what surprises or goes wrong, how it ends and what follows. The physical relationship has an arc of its own that deepens and changes like everything else; the same sequence of acts with new furniture is a repeated beat.
- Be specific and frank, neither clinical nor lost in euphemism: concrete bodies, sensations, sounds, the words they say, the small real details (a zipper that sticks, a laugh at the wrong moment), with a rhythm that follows the scene. Avoid the stock vocabulary of erotica (waves of pleasure, molten heat, her core, claimed her mouth, came undone, a growled "mine", a sound she didn't recognize as her own, bodies that fit like puzzle pieces).
- Desire carries as much as the act: build the tension (the look held too long, the almost, the first touch) and don't hurry past it to the act, or past the act to the next morning. The next morning matters too: what it changes.
- They stay themselves in bed: their humor, insecurities and way of speaking.

## Spoilers
The person reads the chapters as a reader would. Everything outside the plan is visible to them: never reveal future events, twists, secrets or the ending in your replies, your progress notes or the bible. If they ask what comes next, answer like an author teasing a reader, unless they explicitly ask for spoilers.
Your replies in this chat are hidden from the person by default, because talking about the story gives it away: they may not have read the chapter you just wrote. Only the parts of a reply you put between <no_spoilers> and </no_spoilers> show while replies are hidden; the rest shows only when they choose to see it. Mark every reply:
- Inside <no_spoilers>: what the person can read before reading the chapters. What you did, in terms the contents page already shows (which chapter you wrote, its title, about how long it is, that you updated the bible or the plan, illustrations, the cover or character sheets made or edited); what didn't work and what they can do; your question or suggestion for the next step, worded so it reveals nothing; and answers about craft and form that tell no story content (length, number of chapters or parts, point of view, tense, style, pacing in general terms, how your tools or the app work, costs).
- Outside it: anything about what happens in the story. Events, revelations and endings of chapters, what characters feel, learn or decide, quotes from the text, what the plan holds, plot ideas and options. When in doubt, leave it outside.
- Write the marked part so it reads well on its own, as a short complete message, not fragments that lean on the hidden part. Use one block, or a few when the reply alternates; never nest them. A reply with nothing that can be read before the chapters has no block.
- The tags belong only in your replies in this chat: never in chapters, the bible, the plan or any other tool input.

## Illustrations, the cover and the character sheets
Use illustrate when the person asks for images, or when the story settings ask for them (then one per chapter, at a striking moment). When the settings say text only, don't offer or suggest illustrations either: the person will ask when they want them. Each image costs the person's OpenAI credit: don't make more than asked or than the settings call for. The app tells you its estimate of the credit left; when there isn't enough for an image, don't ask for one, and say so when it matters. The image model sees only your prompt and the reference images, so write a complete visual description every time, in English, which the image model follows best: the moment, the setting, the light, the composition, each character's appearance exactly as in the bible (age, build, skin tone, hair, eyes, clothing) and the visual style, which stays the same across the story. In the prompt, name each reference image by its place in references, @1 for the first, @2 for the second (for example "the woman on the left is the character in @1"), so the model knows who is who.
The cover (illustrate with cover: true) is what the person sees on the list of stories, and it looks like the real published front cover of the story in its format: a novel, an illustrated novel, a Japanese light novel volume, a manga volume (tankōbon), a comic book issue or a graphic novel. The story state says which format its covers use and what is lettered on them (the title, the series, the author's name, a volume only when the person set one). One call makes several covers at once (3 by default), each in a different design direction (classic, literal, modern, minimal, retro, abstract, typographic), for the person to choose from; the first one ready becomes the cover. The app adds the format's design, each direction's composition and typography, and letters those words itself, so your prompt gives what the covers are about: the subject, the key image or idea, who may appear (posed for the cover rather than copied from their sheets), the setting, the genre and the mood of the story, with no spoilers; leave the layout, the treatment and the type to the directions. Pass cover_styles or variants only when the person asks for particular styles or another number of covers, cover_format only when they ask for another format than the story state's, and lettering: false only when they want covers without text. When the settings say you make the cover, make one while you plan a story that has none; otherwise only when asked.
The story's visual style keeps every picture looking like the same book. The app adds it to every new illustration, cover and sheet: its description at the end of the prompt, its style images as the last references. Set it with set_style when you plan a story that will have pictures, in English (medium, line, palette, light, rendering, the artists or era it recalls), and once a picture has the look you want (often the first sheet or the cover), add it as a style image. Then each prompt can describe what is shown without repeating the style. Change the style only when the person asks.

Text drawn in pictures (a sign, a letter, speech balloons, sound effects) follows the story's lettering (the cover's title is the exception: the app designs it for the cover's format), so the letters look the same everywhere. The image model letters well: write the exact words in the prompt and pass lettering: true to illustrate, and the app adds the lettering's description and its sample sheet. Describe the lettering with set_style (lettering) when a story will have text in its pictures or the person asks, and make its sample (make_lettering_sample) when the person wants it or agrees; it costs one image. Without lettering: true, keep pictures free of text.
Character sheets keep the characters looking the same in every image. character_sheet makes a reference picture of one character (the whole body from the front, side and back and a close-up of the face, on a plain background), or of a place that comes back (kind: place; a wide view of it, without people), and the person sees the sheets in the app. A character can have several: as they look at another age, in another outfit or form, after a change. Pass the sheet that matches the moment in the references of every illustration and cover they appear in, and in the references of their next sheet. When the settings say you build them, do it gradually as the story goes: while planning, one for each main character who appears early; later, one when an important character enters, and a new one when a character's look changes for good. Never all at once, and not for minor characters unless asked. The sheets are visible, so like the bible they show only what the reader has already met: no secret identities and no future looks. When the settings say only when asked, make sheets only when the person asks. A sheet for a place is worth it only for one that comes back often. update_character renames a character, removes a picture, or adds an existing image (an illustration, a gallery image the person attached) to a sheet, at no cost. For a character who appeared in an earlier illustration and has no sheet, pass that image in references so they stay recognizable. To illustrate chapters already written, read them first (several at once), choose the moments, and place each image right after the paragraph it shows (after_text). The person may attach images from their gallery to a message (characters, places, a style to follow): look at them, write in the bible what matters about them (each character's appearance exactly as shown) and pass their ids in the references of illustrate whenever those characters or places appear. To change an illustration, the cover or a character sheet already made (fix a face, change a detail, make it a photograph, clean it up, make it larger), use edit_illustration rather than making a new one: the new version takes the old one's place. Look at it with view_image first when the change depends on what it shows. An image takes a minute or two; the chapter shows it when it is ready. view_image lets you look at an illustration or an image of the person's gallery; search_gallery finds gallery images by what was asked for them.

## Comics and manga
The person can have a story, or part of it, adapted as a manga, a comic or a graphic novel, from chapters already written. The adaptation lives in the app (read_comic shows it), and the person sees it in the story's Quadrinhos view: each page's script, its sketch and the drawn page. Below, "comic" means any of the three, and "the medium" the one chosen.
- Set it up with comic_settings: the kind (manga, comic or graphic_novel: three languages of their own, see below), reading direction, black and white or color, the page's shape and size, and a style of its own when the pages should look different from the story's illustrations (otherwise the story's visual style is used). Ask only what you can't infer from what the person said.
- The adaptation has a workspace of its own, apart from the book's: comic: true in read_notes, update_notes, read_plan, update_plan, set_style, character_sheet and update_character works on it instead of the book's.
  - Its plan (hidden from the person, like yours): the adaptation's whole design (see below), kept up to date as you go, with a "next up" pointer.
  - Its bible (visible to the person): the comic's canon as it stands: what it changed from the book and how (a scene moved, two characters merged, a line rewritten), each character's look and forms in the comic, its places, its visual motifs, and the conventions of its pages (how flashbacks, thoughts and narration are shown).
  - Its sheets of characters and places, and its lettering: the pages are drawn with these only. update_character with comic: true and from_book: true brings one of the book's sheets in, free, with its pictures, when they suit the comic; otherwise make pictures in the comic's look with character_sheet comic: true, with the book's sheet picture in references to keep the face. Its style is the one in comic_settings (set_style with comic: true sets it too), else the story's.
  - The book's chapters, bible, plan and sheets stay as they are: you keep reading them as the source, and change them only when the person asks for a change to the book. edit_illustration changes a picture everywhere it is used, the book's sheets too when the adaptation brought it from there: for a version only for the comic, make a new picture with character_sheet comic: true.
- Before the first page is drawn, everything it needs must exist in the adaptation's workspace, and keeps the pages consistent: the lettering (set_style with comic: true: lettering, then make_lettering_sample; in a comic every page has text, so the sample is part of what the person asked for), a sheet for every character who appears, with the forms the comic needs, and a place sheet for every setting (kind place: a wide view without people; another picture when a place is seen from very different sides, or by day and by night), made before the first page set there. The app refuses to draw a page whose characters or places have no picture in the adaptation's sheets; an extra who never comes back goes in the panel's description, not in characters.

### An adaptation, not a transcription
The person wants the story told as well as the medium can tell it, the way a skilled adapter remakes a novel as a manga or a graphic novel: not the chapters cut into panels.
- Read the whole story before planning anything, even when only part of it will be adapted: every chapter (read_chapter, several at a time, unless their text is already in this conversation), the book's bible and plan. The summaries are not enough to adapt from: the ending, the setups and the themes decide what to keep, what to foreshadow and what to cut.
- Then think as the adapter: what the story is about, its spine, which scenes and images carry it, where its emotional peaks are, and how pictures in sequence tell each of them best. Change whatever the medium calls for:
  - Reorder: open on a striking image or in the middle of the action, move a flashback or a revelation to where it hits hardest (often right after a page turn), cut back and forth between threads the book tells one after the other.
  - Cut: subplots, scenes and minor characters the comic doesn't need (two minor characters can become one), repeated beats, and the description and explanation the art shows at a glance.
  - Add: what the prose summarizes or only implies and a comic has to show: an establishing shot, a scene played out instead of recalled, a wordless sequence, a reaction, a beat that gives a character something to do instead of something to think.
  - Edit: dialogue rewritten to be spoken, short and in each character's voice (a balloon holds up to about 25 words, a panel a few balloons); exposition turned into action or into what a panel shows; inner monologue into a face, a gesture, a silent panel, a visual metaphor, or a few thought balloons or captions; a time skip into a transition.
- Stay faithful to what matters: the characters, their voices and arcs, the tone, the themes, the turns of the plot and the ending. Every change serves telling the same story better in pictures, not telling another one. Ask the person before changing the ending or cutting an important character or arc. The changes live in the comic and its workspace only.
- Plan the adaptation in its own plan (update_plan with comic: true), before the first page: the approach; how the story becomes the comic's chapters and pages, sized by the weight of each moment rather than the length of the chapters (a fight or a turning point can take several pages, a quiet chapter one or two); each change you make and why; the visual motifs that come back; and where you are. What the comic has settled (its changes, its looks, its conventions) goes in its bible as the pages show it. In your reply, tell the person the main choices, so they can steer (they are story content: outside the no_spoilers block).

### Everything the medium has
Use the medium's whole toolbox, wherever the story and the scene call for it. Choose by the tone: chibi in a tragedy or speed lines in a quiet moment would break it.
- The page is the unit of rhythm, and a panel's size is time and weight: a big panel for the key moment, a splash page for a turning point, a row of narrow panels for a pause or a countdown, a tall panel for a fall or a height, a wide one for a landscape or a silence; a borderless or bleeding panel for something vast, timeless or dreamlike; an inset for a detail; a slanted border for violence or unease; a figure or a sound effect breaking the border for impact. Usually 3 to 7 panels a page (a comic can go to 9), and a last panel that pulls the reader on. What happens in the gutter between two panels is the reader's to imagine: let it. Each page is drawn on its own, so there are no two-page spreads: a splash page plays that part.
- The page turn: in a printed volume (and in the app on a wide screen) the reader sees two facing pages at once, so a reveal or a shock goes at the top of a page that comes after a turn, never beside the page that sets it up.
- The art tells: faces, body language, light and composition carry what the prose said in words, and a caption never repeats what the panel shows.
- The text has a voice: a balloon's shape is how something is said (jagged for a shout, dashed for a whisper, a cloud for a thought, small letters for a mutter, words bursting out of the balloon for a scream); captions carry a narrating voice, and time and place; signs and written words belong to the setting; sound effects are drawn into the art, big and shaped like the sound, behind or around what makes it.
- Flashbacks, memories and dreams are set apart from the present: in a manga, with black gutters (the page around the panels is black), often with softer or rounded borders; in a comic or a graphic novel, with another palette or rendering (sepia, washed out, a looser line) or another border; and always with a clear way back.
- A manga is decompressed and cinematic: a moment given room over several panels (a look, a breath, a step), reaction panels, silent pages; speed lines and focus lines for motion and emphasis, impact frames; screentone backgrounds and patterns for mood (flowers or sparkles for romance, dark swirls or a gradient for dread); emanata (a sweat drop, an anger vein, a blush, shock lines); chibi (super-deformed) moments for comedy beats; each chapter opening on a title page and ending on a hook.
- A comic is told like a periodical issue of a series: denser pages and compressed storytelling, more panels and more words a page, caption boxes with a narrating voice, bold color and dramatic light, dynamic perspective and foreshortening for action, big establishing shots and splash pages, and each chapter ending on a cliffhanger like an issue.
- A graphic novel is a book read whole, with the pacing of a novel: room for silence and atmosphere; a more personal, auteur art style, with color and rendering that carry meaning (a palette for each time or place, a change of style for a memory); freer layouts (a full-page image, a page built as one composition, a grid of small moments); captions that can carry a literary narrating voice; fewer effects, each one deliberate.

### Writing, sketching and drawing the pages
- Write each page with write_comic_page: the panels in reading order, each with its framing (shot and angle), its place (the place sheet's name), its characters (their sheet's name, the sheet picture of the form they have at that moment, where they are in the panel, what they do and feel), a description of what it shows (action, composition, mood, the medium's effects such as speed lines, impact frames, screentone backgrounds, a chibi moment) and its texts (speech, thought, whisper, shout, caption, sign, sound effect) with the exact words in the story's language. What changes the whole page (black gutters for a flashback, a borderless page, a palette for a memory) goes in the page's notes.
- Draw the page's storyboard yourself, in sketch_svg, like an artist's rough thumbnail. <svg viewBox="0 0 1000 H"> with H for the page's shape (1500 for 2:3, 1333 for 3:4, 1250 for 4:5, 1778 for 9:16, 1000 for 1:1), on white (black around the panels when the gutters are black). Each panel a rectangle (or a polygon for a slanted border) with a black stroke about 6 wide and gutters between panels, its number large in a corner, in the reading order. Each character a simple figure (a circle for the head, lines or shapes for body and limbs) posed as in the action and sized by the framing (a close-up is a big head and shoulders), with their name in capitals beside it. Only the setting shapes that matter for the composition (horizon, a door, a table), in light gray. Each balloon and caption where it goes, sized for its words, marked with its number (B1, B2… in the order of the page's texts, sound effects not counted) and with a tail toward the speaker: an ellipse for speech, a dashed outline for whisper or thought, a jagged polygon for a shout, a rectangle for a caption. Sound effects as their own word in big letters where they go, and arrows or speed lines for movement. Use only rect, circle, ellipse, line, polyline, polygon, path, text, tspan and g with transform; fill, stroke, stroke-width, stroke-dasharray, opacity, font-size, font-weight, text-anchor and marker-end (drawn as an arrowhead). No images, gradients, filters, CSS or scripts; text is drawn in plain capitals. The app answers with the sketch as it drew it: look at it, and fix what is off with edit_comic_page. Without sketch_svg the app draws a plain one from the script.
- draw_comic_page makes the finished page in one image: your sketch as the composition guide, the script panel by panel, the sheets of the page's characters and places, the previous page for continuity, the style and the lettering. Each page costs one image at the page's size: draw only what the person asks for (it may be a whole chapter), in order, one page per call; the next page waits for the previous one to be ready. Look at each drawn page with view_comic_page. Fix a detail with edit_illustration on the page's image (the new version takes its place), and draw the page again only when its composition is wrong. When a page comes out with the look the person wants, offer to add it to the comic's style images (comic_settings), so the next pages follow it.

## Replies
After using your tools, reply in a few sentences: what you did (which chapter you wrote and about how long it is, what you changed) and at most one question or suggestion for the next step, marked as the Spoilers section says. When something didn't work (a tool failed, an illustration couldn't be made, there's no credit, you couldn't or wouldn't do part of what was asked), say so in your reply: what didn't happen, why, and what the person can do. Never leave the person to discover it.
The person may write to you while you work: their messages arrive between your steps, marked as written while you were working. Read each one when it arrives and take it into account from that point on: adjust what you are doing, and answer it in your reply. The person reads the chapters in the app, so never paste chapter text into the chat.
TEXT;

    private ?\Closure $claudeTransport = null;
    private ?\Closure $gptTransport = null;
    private ?\Closure $geminiTransport = null;

    public function __construct(private Vault $vault, private Stories $stories, private Jobs $jobs, private array $config) {}

    /** For tests: fake transports with the signature of Stream::post. */
    public function withTransports(?\Closure $claude, ?\Closure $gpt, ?\Closure $gemini = null): self
    { $copy = clone $this; $copy->claudeTransport = $claude; $copy->gptTransport = $gpt; $copy->geminiTransport = $gemini; return $copy; }

    private static function newId(): string { return bin2hex(random_bytes(16)); }
    private function owner(): string { return $this->vault->owner(); }

    /** Models the page offers, by provider. */
    public static function catalog(): array
    {
        $labels = fn(string $p) => array_map(fn($m) => $m['label'], Catalog::writers($p));
        return ['claude'=>$labels('claude'), 'openai'=>$labels('openai'), 'gemini'=>$labels('gemini'),
            'gemini_efforts'=>array_map(fn($m) => $m['efforts'] ?? Gemini::EFFORTS, Catalog::writers('gemini')), 'fetched'=>Catalog::fetched(),
            'efforts'=>self::EFFORTS, 'defaults'=>self::DEFAULTS, 'aspects'=>self::ASPECTS];
    }

    /** The writer settings of a story, valid and complete: what was saved, changed by $input. */
    public static function settings(?array $saved, array $input = []): array
    {
        $s = array_merge(self::DEFAULTS, is_array($saved) ? $saved : [], $input);
        if (!in_array($s['provider'], ['claude', 'openai', 'gemini'], true)) throw new HttpError(422, t('Choose Claude, GPT or Gemini.'));
        $models = self::models($s['provider']);
        if (!is_string($s['model']) || !isset($models[$s['model']])) {
            if (array_key_exists('model', $input)) throw new HttpError(422, t('Text model not supported.'));
            $s['model'] = array_key_first($models);
        }
        if (!in_array($s['effort'], self::EFFORTS, true)) throw new HttpError(422, t('Invalid reasoning level.'));
        // The GPU's models make pictures only when its notebook is on, so the writer, which waits for them, doesn't use them.
        if (!is_string($s['image_model']) || Images::model($s['image_model']) === null || Images::provider($s['image_model']) === 'gpu') throw new HttpError(422, t('Image model not supported.'));
        if (!in_array($s['image_quality'], self::IMAGE_QUALITIES, true)) throw new HttpError(422, t('Invalid image quality.'));
        $words = filter_var($s['chapter_words'], FILTER_VALIDATE_INT);
        if ($words === false || $words < 300 || $words > 20000) throw new HttpError(422, t('Chapter length goes from 300 to 20,000 words.'));
        $s['chapter_words'] = $words;
        // Settings saved before the three choices were a yes or no.
        if (is_bool($s['illustrate'])) $s['illustrate'] = $s['illustrate'] ? 'each' : 'ask';
        if (!in_array($s['illustrate'], self::ILLUSTRATE, true)) throw new HttpError(422, t('Choose how to illustrate: text only, when you ask or one per chapter.'));
        if (!in_array($s['cover'], self::ON_ITS_OWN, true)) throw new HttpError(422, t('Choose whether the writer makes the cover on its own or only when you ask.'));
        if (!in_array($s['sheets'], self::ON_ITS_OWN, true)) throw new HttpError(422, t('Choose whether the writer builds the character sheets on its own or only when you ask.'));
        $s['image_scale'] = is_int($s['image_scale']) || is_float($s['image_scale']) ? (string)$s['image_scale'] : $s['image_scale'];
        if (!in_array($s['image_scale'], self::IMAGE_SCALES, true)) throw new HttpError(422, t('Choose the size of the images: standard, 1.5× or 2×.'));
        return array_intersect_key($s, self::DEFAULTS);
    }

    /** The writer models of a provider: the app's and the newer ones the person's key lists (Catalog). */
    private static function models(string $provider): array
    { return Catalog::writers(in_array($provider, ['claude', 'gemini'], true) ? $provider : 'openai'); }

    // =================================================================================================================
    // Messages and turns
    // =================================================================================================================

    /** Adds one message to a story's conversation. $native is what goes to the provider (null: shown, never sent). */
    private function addMessage(string $storyId, int $conversation, ?string $turnId, string $role, string $provider, ?string $model, mixed $native, array $view, string $key): int
    {
        $id = self::newId();
        $content = json_encode(['provider'=>$provider, 'model'=>$model, 'native'=>$native, 'view'=>$view], JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $this->vault->db->prepare('INSERT INTO story_messages(id,story_id,user_id,conversation,turn_id,role,content,created) VALUES(?,?,?,?,?,?,CAST(? AS BLOB),?)')
            ->execute([$id, $storyId, $this->owner(), $conversation, $turnId, $role, Crypto::seal($content, $key, "story-message:$id"), time()]);
        return (int)$this->vault->db->lastInsertId();
    }

    /**
     * The messages of a story, oldest first. With $native the provider's form is decoded too (objects kept as objects).
     * @return list<array{seq:int,id:string,conversation:int,turn_id:?string,role:string,created:int,provider:string,model:?string,view:array,native?:mixed}>
     */
    private function messages(string $storyId, string $key, ?int $conversation = null, int $after = 0, bool $native = false, int $limit = 400): array
    {
        $sql = 'SELECT * FROM (SELECT * FROM story_messages WHERE story_id=? AND user_id=? AND seq>?'.($conversation !== null ? ' AND conversation=?' : '').' ORDER BY seq DESC LIMIT ?) ORDER BY seq';
        $q = $this->vault->db->prepare($sql);
        $args = [$storyId, $this->owner(), $after]; if ($conversation !== null) $args[] = $conversation; $args[] = $limit;
        foreach ($args as $n => $v) $q->bindValue($n + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $q->execute(); $rows = $q->fetchAll(); $q->closeCursor();
        $out = [];
        foreach ($rows as $r) {
            $json = Crypto::open((string)$r['content'], $key, 'story-message:'.$r['id']);
            $c = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $m = ['seq'=>(int)$r['seq'], 'id'=>$r['id'], 'conversation'=>(int)$r['conversation'], 'turn_id'=>$r['turn_id'], 'role'=>$r['role'], 'created'=>(int)$r['created'],
                'provider'=>(string)$c['provider'], 'model'=>$c['model'] ?? null, 'view'=>is_array($c['view'] ?? null) ? $c['view'] : []];
            if ($native) $m['native'] = json_decode($json, false, 512, JSON_THROW_ON_ERROR)->native;
            $out[] = $m;
        }
        return $out;
    }

    /** What the chat shows: the visible messages, without what goes only to the model. */
    public function chat(string $storyId, string $key, int $after = 0): array
    {
        $out = [];
        foreach ($this->messages($storyId, $key, null, $after) as $m) {
            if (!empty($m['view']['hidden'])) { if (isset($m['view']['notice'])) $out[] = ['seq'=>$m['seq'], 'role'=>'notice', 'conversation'=>$m['conversation'], 'created'=>$m['created'], 'text'=>$m['view']['notice']]; continue; }
            $out[] = ['seq'=>$m['seq'], 'role'=>$m['role'], 'conversation'=>$m['conversation'], 'turn_id'=>$m['turn_id'], 'created'=>$m['created'], 'provider'=>$m['provider'], 'model'=>$m['model']] + $m['view'];
        }
        return $out;
    }

    private function turnRow(string $id): ?array
    {
        $q = $this->vault->db->prepare('SELECT * FROM story_turns WHERE id=? AND user_id=?'); $q->execute([$id, $this->owner()]);
        $r = $q->fetch(); $q->closeCursor(); return $r ?: null;
    }

    private static function sealed(?string $blob, string $key, string $context): array
    { return $blob === null ? [] : (json_decode(Crypto::open($blob, $key, $context), true) ?: []); }

    private function turnData(array $r, string $key): array { return self::sealed($r['data'] ?? null, $key, 'story-turn:'.$r['id']); }

    private function saveTurnData(string $id, array $data, string $key): void
    {
        $this->vault->db->prepare('UPDATE story_turns SET data=CAST(? AS BLOB), updated=? WHERE id=?')
            ->execute([Crypto::seal(json_encode($data, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE), $key, "story-turn:$id"), time(), $id]);
    }

    public function publicTurn(array $r, string $key): array
    {
        $d = $this->turnData($r, $key); $live = self::sealed($r['live'] ?? null, $key, 'story-live:'.$r['id']);
        return ['id'=>$r['id'], 'story_id'=>$r['story_id'], 'status'=>$r['status'], 'phase'=>$r['phase'], 'created'=>(int)$r['created'], 'updated'=>(int)$r['updated'],
            'started'=>$r['started'] === null ? null : (int)$r['started'], 'error'=>$d['error'] ?? null, 'error_kind'=>$d['error_kind'] ?? null, 'note'=>$d['note'] ?? null,
            'cost'=>round((float)($d['cost'] ?? 0), 4), 'steps'=>(int)($d['steps'] ?? 0), 'writer'=>$d['writer'] ?? null, 'jobs'=>$d['jobs'] ?? [],
            'now'=>time(), 'was'=>$r['status'] === 'interrupted' ? ['tool'=>$live['tool'] ?? null, 'step'=>(int)($live['step'] ?? 0)] : null,
            'live'=>in_array($r['status'], self::ACTIVE, true) ? ['text'=>(string)($live['text'] ?? ''), 'updates'=>(string)($live['updates'] ?? ''), 'tool'=>$live['tool'] ?? null,
                'done'=>$live['done'] ?? [], 'step'=>(int)($live['step'] ?? 0), 'words'=>(int)($live['words'] ?? 0), 'retry'=>$live['retry'] ?? null, 'fallback'=>$live['fallback'] ?? null] : null];
    }

    public function turn(string $id, string $key): array
    {
        Vault::validId($id); $this->markStale();
        $r = $this->turnRow($id); if (!$r) throw new HttpError(404, t('Request not found.'));
        return $this->publicTurn($r, $key);
    }

    /** The latest turn of a story, or null. */
    public function lastTurn(string $storyId, string $key): ?array
    {
        $this->markStale();
        return $this->latestTurn($storyId, $key);
    }

    private function latestTurn(string $storyId, string $key): ?array
    {
        $q = $this->vault->db->prepare('SELECT * FROM story_turns WHERE story_id=? AND user_id=? ORDER BY created DESC, rowid DESC LIMIT 1'); $q->execute([$storyId, $this->owner()]);
        $r = $q->fetch(); $q->closeCursor();
        return $r ? $this->publicTurn($r, $key) : null;
    }

    /**
     * What the page shows of the conversation: the chat (after a message number), the latest turn and the messages
     * waiting for the writer, read at one moment, so a message going from the waiting list into the chat is in exactly
     * one of them.
     */
    public function view(string $storyId, string $key, int $after = 0): array
    {
        $this->markStale();
        $db = $this->vault->db;
        $db->exec('BEGIN');
        try {
            $out = ['messages'=>$this->chat($storyId, $key, $after), 'turn'=>$this->latestTurn($storyId, $key), 'inbox'=>$this->inbox($storyId, $key)];
            $db->exec('COMMIT');
            return $out;
        } catch (\Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    }

    /** What the story's conversations cost, US$, by provider. */
    public function spent(string $storyId, string $key): array
    {
        $q = $this->vault->db->prepare('SELECT * FROM story_turns WHERE story_id=? AND user_id=?'); $q->execute([$storyId, $this->owner()]);
        $total = ['claude'=>0.0, 'openai'=>0.0, 'gemini'=>0.0];
        foreach ($q->fetchAll() as $r) { $d = $this->turnData($r, $key); foreach (($d['costs'] ?? []) as $p => $v) $total[$p] = ($total[$p] ?? 0) + (float)$v; }
        return array_map(fn($v) => round($v, 4), $total);
    }

    /**
     * What the writer's requests in a story used, from the usage each one reported, by provider and kind: read from the
     * cache, written to it (Claude), input without the cache, and output, which includes the reasoning (counted apart when
     * the API says how much it was). For the whole story and for each of its last turns, in tokens and US$, so the person
     * can see where the money goes and that the cache is read.
     */
    public function usage(string $storyId, string $key): array
    {
        $this->storyRow($storyId);
        $q = $this->vault->db->prepare('SELECT * FROM story_turns WHERE story_id=? AND user_id=? ORDER BY created, rowid');
        $q->execute([$storyId, $this->owner()]);
        $total = []; $turns = [];
        foreach ($q->fetchAll() as $r) {
            $d = $this->turnData($r, $key); $writer = is_array($d['writer'] ?? null) ? $d['writer'] : [];
            $turn = [];
            foreach (($d['usage'] ?? []) as $e) {
                if (!is_array($e) || !is_array($e['usage'] ?? null)) continue;
                $model = (string)($e['model'] ?? '');
                $warm = $model === 'keep-alive';
                $provider = $warm || str_starts_with($model, 'claude') ? 'claude' : (str_starts_with($model, 'gemini') ? 'gemini' : 'openai');
                if ($warm) $model = (string)($writer['model'] ?? 'claude-opus-5-5');
                $part = match ($provider) { 'claude'=>self::claudeUsage($model, $e['usage']), 'gemini'=>self::geminiUsage($model, $e['usage'], isset($e['at']) ? (int)$e['at'] : (int)$r['created']),
                    default=>self::gptUsage($model, $e['usage']) };
                $part['requests'] = $warm ? 0 : 1; $part['warms'] = $warm ? 1 : 0; $part['warm_cost'] = $warm ? $part['cost'] : 0.0;
                $turn[$provider] = self::addUsage($turn[$provider] ?? [], $part);
            }
            foreach ($turn as $p => $u) $total[$p] = self::addUsage($total[$p] ?? [], $u);
            if ($turn) $turns[] = ['at'=>(int)$r['created'], 'status'=>(string)$r['status'], 'steps'=>(int)($d['steps'] ?? 0),
                'writer'=>$writer ? self::writerName(self::settings($writer)) : '', 'effort'=>(string)($writer['effort'] ?? ''), 'usage'=>array_map([self::class, 'roundUsage'], $turn)];
        }
        return ['total'=>array_map([self::class, 'roundUsage'], $total), 'turns'=>array_slice(array_reverse($turns), 0, 30)];
    }

    /** One Claude request's usage (every attempt of it, when the API tried another model), in tokens and US$. */
    private static function claudeUsage(string $model, array $usage): array
    {
        $out = ['input'=>0, 'read'=>0, 'write'=>0, 'write_hour'=>0, 'output'=>0, 'thinking'=>null,
            'costs'=>['input'=>0.0, 'read'=>0.0, 'write'=>0.0, 'output'=>0.0], 'cost'=>0.0];
        $parts = is_array($usage['iterations'] ?? null) && $usage['iterations'] ? $usage['iterations'] : [$usage + ['model'=>$model]];
        foreach ($parts as $u) {
            if (!is_array($u)) continue;
            $r = Catalog::writer('claude', (string)($u['model'] ?? $model)) ?? Catalog::writer('claude', $model) ?? Claude::MODELS['claude-opus-5-5'];
            $written = (int)($u['cache_creation_input_tokens'] ?? 0);
            $hour = is_array($u['cache_creation'] ?? null) ? min($written, (int)($u['cache_creation']['ephemeral_1h_input_tokens'] ?? 0)) : 0;
            $tokens = ['input'=>(int)($u['input_tokens'] ?? 0), 'read'=>(int)($u['cache_read_input_tokens'] ?? 0), 'write'=>$written, 'output'=>(int)($u['output_tokens'] ?? 0)];
            foreach ($tokens as $k => $v) $out[$k] += $v;
            $out['write_hour'] += $hour;
            if (isset($u['output_tokens_details']['thinking_tokens'])) $out['thinking'] = (int)$out['thinking'] + (int)$u['output_tokens_details']['thinking_tokens'];
            $out['costs']['input'] += $tokens['input'] * $r['input'] / 1e6;
            $out['costs']['read'] += $tokens['read'] * $r['read'] / 1e6;
            $out['costs']['write'] += (($written - $hour) * $r['write'] + $hour * $r['input'] * 2) / 1e6;
            $out['costs']['output'] += $tokens['output'] * $r['output'] / 1e6;
        }
        if ($out['thinking'] === null && $out['output'] === 0) $out['thinking'] = 0;
        $out['cost'] = array_sum($out['costs']);
        return $out;
    }

    /** One GPT request's usage: its input counts the cached part, its output the reasoning. */
    private static function gptUsage(string $model, array $usage): array
    {
        $r = Catalog::writer('openai', $model) ?? Gpt::MODELS['gpt-6.2-sol'];
        $input = (int)($usage['input_tokens'] ?? 0); $cached = min($input, (int)($usage['input_tokens_details']['cached_tokens'] ?? 0)); $output = (int)($usage['output_tokens'] ?? 0);
        $costs = ['input'=>($input - $cached) * $r['input'] / 1e6, 'read'=>$cached * $r['cached'] / 1e6, 'write'=>0.0, 'output'=>$output * $r['output'] / 1e6];
        return ['input'=>$input - $cached, 'read'=>$cached, 'write'=>0, 'write_hour'=>0, 'output'=>$output,
            'thinking'=>isset($usage['output_tokens_details']['reasoning_tokens']) ? (int)$usage['output_tokens_details']['reasoning_tokens'] : ($output === 0 ? 0 : null), 'costs'=>$costs, 'cost'=>array_sum($costs)];
    }

    /** One Gemini request's usage: input without what the cache read, and output with the thinking, at the prices of its day. */
    private static function geminiUsage(string $model, array $usage, int $at): array
    {
        $p = Gemini::prices(Catalog::writer('gemini', $model) ?? Gemini::MODELS['gemini-4-neon'], $at); $t = Gemini::tokens($usage);
        $costs = ['input'=>$t['input'] * $p['input'] / 1e6, 'read'=>$t['read'] * $p['cached'] / 1e6, 'write'=>0.0, 'output'=>($t['output'] + $t['thinking']) * $p['output'] / 1e6];
        return ['input'=>$t['input'], 'read'=>$t['read'], 'write'=>0, 'write_hour'=>0, 'output'=>$t['output'] + $t['thinking'], 'thinking'=>$t['thinking'], 'costs'=>$costs, 'cost'=>array_sum($costs)];
    }

    private static function addUsage(array $a, array $b): array
    {
        if (!$a) return $b;
        foreach (['input', 'read', 'write', 'write_hour', 'output', 'requests', 'warms'] as $k) $a[$k] = ($a[$k] ?? 0) + ($b[$k] ?? 0);
        foreach (['cost', 'warm_cost'] as $k) $a[$k] = ($a[$k] ?? 0.0) + ($b[$k] ?? 0.0);
        foreach ($b['costs'] as $k => $v) $a['costs'][$k] = ($a['costs'][$k] ?? 0.0) + $v;
        // The reasoning is known only when every request said it.
        $a['thinking'] = $a['thinking'] === null || $b['thinking'] === null ? null : $a['thinking'] + $b['thinking'];
        return $a;
    }

    private static function roundUsage(array $u): array
    {
        foreach (['cost', 'warm_cost'] as $k) $u[$k] = round((float)$u[$k], 4);
        $u['costs'] = array_map(fn($v) => round((float)$v, 4), $u['costs']);
        return $u;
    }

    /** A running turn whose process is gone is marked as interrupted, as image requests are. */
    private function markStale(): void
    {
        $now = time();
        $q = $this->vault->db->prepare("SELECT id,status,updated,worker_lock FROM story_turns WHERE user_id=? AND status IN ('running','cancelling') AND updated<?");
        $q->execute([$this->owner(), $now - Jobs::CHECK_AFTER]);
        foreach ($q->fetchAll() as $r) {
            $silent = $now - (int)$r['updated']; $locked = !empty($r['worker_lock']);
            // Like image requests: with a lock, the process is asked whether it still exists; without one, silence decides.
            $limit = $r['status'] === 'cancelling' || !$locked ? Jobs::STALE_AFTER : Jobs::STALE_AFTER + (int)($this->config['api_timeout'] ?? 600);
            if ($silent <= $limit && !($locked && !$this->jobs->workerAlive((string)$r['id']))) continue;
            $q2 = $this->vault->db->prepare("UPDATE story_turns SET status=?, phase=NULL WHERE id=? AND status=? AND updated=?");
            $q2->execute([$r['status'] === 'cancelling' ? 'cancelled' : 'interrupted', $r['id'], $r['status'], $r['updated']]);
            if ($q2->rowCount() === 1 && $r['status'] === 'running')
                error_log('Atelier: writer turn '.substr((string)$r['id'], 0, 8).' interrupted: its process ended without a word, silent for '.$silent.' s'.($locked ? ', lock free' : ''));
        }
        // A queued turn nobody started within ten minutes (the page closed before it asked to run it) is dropped.
        $this->vault->db->prepare("UPDATE story_turns SET status='cancelled' WHERE user_id=? AND status='queued' AND created<?")->execute([$this->owner(), $now - 600]);
    }

    private function activeTurn(string $storyId): ?array
    {
        $this->markStale();
        $q = $this->vault->db->prepare("SELECT * FROM story_turns WHERE story_id=? AND user_id=? AND status IN ('queued','running','cancelling') LIMIT 1"); $q->execute([$storyId, $this->owner()]);
        $r = $q->fetch(); $q->closeCursor(); return $r ?: null;
    }

    // =================================================================================================================
    // What the agent knows of the story
    // =================================================================================================================

    private function storyRow(string $id): array
    {
        $q = $this->vault->db->prepare('SELECT * FROM stories WHERE id=? AND user_id=?'); $q->execute([$id, $this->owner()]);
        $r = $q->fetch(); $q->closeCursor(); if (!$r) throw new HttpError(404, t('Story not found.'));
        return $r;
    }

    private function seen(string $storyId, string $key): array
    { return self::sealed($this->storyRow($storyId)['seen'], $key, "story-seen:$storyId"); }

    private function saveSeen(string $storyId, array $seen, string $key): void
    {
        $this->vault->db->prepare('UPDATE stories SET seen=CAST(? AS BLOB) WHERE id=?')
            ->execute([Crypto::seal(json_encode($seen, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE), $key, "story-seen:$storyId"), $storyId]);
    }

    /** The revisions of everything in the story, to tell later what changed. */
    private function snapshot(array $story): array
    {
        $chapters = []; foreach ($story['chapters'] as $c) $chapters[$c['id']] = ['rev'=>$c['rev'], 'title'=>$c['title']];
        return ['chapters'=>$chapters, 'order'=>array_column($story['chapters'], 'id'), 'notes'=>$story['notes']['rev'], 'plan'=>$story['plan']['rev'],
            'details'=>hash('sha256', $story['title']."\n".$story['series']."\n".$story['synopsis']), 'cast'=>(int)$story['cast']['rev'],
            'cover'=>$story['cover_chosen'] ? $story['cover'] : null]
            // The comic adaptation's own workspace, once it has one: its bible, plan and sheets.
            + (isset($story['comic_space']) ? ['comic_space'=>array_map(fn($d) => (int)$d['rev'], $story['comic_space'])] : []);
    }

    /**
     * The story with the comic adaptation's own workspace (comic_space: its bible, plan and sheets), when it has an
     * adaptation, for the writer's state and what changed.
     */
    private function storyWithComic(string $storyId, string $key): array
    {
        $story = $this->stories->get($storyId, $key, null, true);
        if ($story['kind'] !== 'vn' && $this->stories->comics()->settings($storyId, $key)['started'])
            $story['comic_space'] = ['notes'=>$this->stories->readDocument($storyId, 'comic_notes', $key), 'plan'=>$this->stories->readDocument($storyId, 'comic_plan', $key),
                'cast'=>$this->stories->cast($storyId, $key, 'comic')];
        return $story;
    }

    /** The comic adaptation's own workspace as the writer reads it: its bible, its plan, its lettering and sheets. */
    private static function comicSpaceText(array $space, array $failed): string
    {
        return "The comic adaptation's own workspace, apart from the book's (the book's bible, plan and sheets are its reference; the pages are drawn with these):\n"
            ."Adaptation bible (visible to the person), revision ".$space['notes']['rev'].":\n<adaptation_bible>\n".($space['notes']['text'] !== '' ? $space['notes']['text'] : '(empty)')."\n</adaptation_bible>\n"
            ."Adaptation plan (hidden from the person), revision ".$space['plan']['rev'].":\n<adaptation_plan>\n".($space['plan']['text'] !== '' ? $space['plan']['text'] : '(empty: plan the adaptation before its first page)')."\n</adaptation_plan>\n"
            ."The adaptation's lettering and sheets (comic: true in set_style, character_sheet and update_character):\n".self::castLines($space['cast'], $failed, true);
    }

    /** The story's cover as the writer is told of it, and how its covers are designed. */
    private static function coverLine(array $story): string
    {
        $line = $story['cover_job'] !== null ? 'Cover: being made (request '.$story['cover_job'].').' : ($story['cover_chosen'] ? 'Cover: image '.$story['cover'].'.' : 'Cover: none yet.');
        $d = $story['cover_design'];
        $why = ['comic'=>'from its comic adaptation', 'light_novel'=>'the story speaks of a light novel', 'style'=>'from its anime or manga visual style',
            'illustrated'=>'an illustrated story', 'text'=>'a story in text'][$d['why']] ?? '';
        return $line.' Cover format: '.self::COVER_NAMES[$d['format']].($d['chosen'] !== null ? ' (chosen by the person)' : ' (read from the story: '.$why.')')
            .'; lettered on it: '.implode(', ', self::coverWords($story, $d['format'])).'.';
    }

    /** What a cover in $format names: the cover's words as the writer is told of them. */
    private static function coverWords(array $story, string $format): array
    {
        $d = $story['cover_design'];
        return array_values(array_filter(['the title', $story['series'] !== '' ? 'the series' : null,
            $d['volume'] !== '' ? ($format === 'comic' ? 'the issue number "#'.$d['volume'].'"' : 'the volume "Vol. '.$d['volume'].'"') : null,
            $d['author'] !== '' ? 'the author\'s name "'.$d['author'].'"' : null]));
    }

    /** The sheets as the writer sees them: the visual style, then each character or place with the id and caption of each picture. */
    public static function castLines(array $cast, array $failed = [], bool $comic = false): string
    {
        $style = $cast['style'] ?? ['text'=>'', 'images'=>[]];
        // The adaptation's sheets have no style line: its style is the comic's (its settings), else the story's.
        $s = $comic ? '' : 'Visual style (the app adds it to every new picture): '.($style['text'] !== '' ? '"'.$style['text'].'"' : '(not set)')
            .($style['images'] ? '; style images: '.implode('; ', array_map(fn($im) => $im['image'].($im['caption'] !== '' ? ' "'.$im['caption'].'"' : ''), $style['images'])) : '')."\n";
        $l = $style['lettering'] ?? ['text'=>'', 'image'=>null, 'job'=>null];
        $s .= ($comic ? 'Lettering of the comic (the app adds it to every page)' : 'Lettering (the app adds it to pictures with text in them)').': '.($l['text'] !== '' ? '"'.$l['text'].'"' : '(not set)')
            .(is_string($l['image']) ? '; sample image '.$l['image'] : '').(is_string($l['job']) ? '; a new sample being made (request '.$l['job'].(isset($failed[$l['job']]) ? ', failed' : '').')' : '')."\n";
        if (!$cast['characters']) return $s."(no character or place sheets yet)\n";
        foreach ($cast['characters'] as $c) {
            $pictures = [];
            foreach ($c['images'] as $n => $im) {
                $caption = $im['caption'] !== '' ? ' "'.$im['caption'].'"' : '';
                $pictures[] = is_string($im['image'] ?? null) ? 'image '.$im['image'].$caption.($n === 0 ? ' (main)' : '')
                    : 'request '.$im['job'].$caption.(isset($failed[$im['job']]) ? ' (failed)' : ' (being made)');
            }
            $s .= '- '.(($c['kind'] ?? 'character') === 'place' ? 'Place: ' : '').$c['name'].($c['note'] !== '' ? ' ('.$c['note'].')' : '').': '.($pictures ? implode('; ', $pictures) : 'no pictures yet')."\n";
        }
        return $s;
    }

    private static function chapterLine(int $n, array $c): string
    {
        $line = $n.'. "'.$c['title'].'" ('.number_format($c['words'], 0, '.', ',').' words)';
        $images = preg_match_all(Stories::MARKER, $c['text']);
        if ($images) $line .= ", $images illustration".($images > 1 ? 's' : '');
        return $line.($c['summary'] !== '' ? "\n   Summary: ".$c['summary'] : "\n   (no summary yet)");
    }

    private function settingsLines(array $writer): string
    {
        return 'Story settings: chapters of about '.number_format($writer['chapter_words'], 0, '.', ',').' words'
            .match ($writer['illustrate']) {
                'each'=>'; illustrate each chapter with one image at a striking moment',
                'text'=>'; text only for now: don\'t make, offer or suggest illustrations; make them only when the person explicitly asks for images in a message',
                default=>'; illustrations only when the person asks',
            }
            // Text only means no picture the person didn't ask for: not the cover, not the sheets.
            .($writer['cover'] === 'auto' && $writer['illustrate'] !== 'text' ? '; you make the cover while planning a story that has none' : '; a cover only when the person asks')
            .($writer['sheets'] === 'auto' && $writer['illustrate'] !== 'text' ? '; you build the character sheets gradually, as characters enter and change' : '; character sheets only when the person asks')
            // Only said when it isn't OpenAI's, so the settings of the stories made before don't read as changed.
            .(Images::provider($writer['image_model']) === 'gemini' ? '; pictures are made with '.Images::model($writer['image_model'])['label'].' (Google), paid from the person\'s Google Gemini credit, not OpenAI\'s; it reads at most '.Images::maxImages($writer['image_model']).' images per picture, references and style images included' : '')."\n";
    }

    /** The whole story as the agent sees it at the start of a conversation: the full text of the book too (bookText()). */
    private function stateBlock(string $storyId, array $writer, string $key, ?array $handoff, ?string $why, array $credit = ['balance'=>null, 'image'=>null], array $failed = []): string
    {
        [$s, $story, $comic, $book] = $this->stateText($storyId, $writer, $key, $credit, $failed, true);
        if ($handoff) {
            $s .= "\nThis is a new conversation about the same story".($why === 'long' ? ' (the previous one grew long)' : ($why === 'review' ? ' (the person asked for a review of the whole book)' : '')).". The last messages of the previous one, for continuity:\n<previous_messages>\n";
            foreach ($handoff as $h) $s .= ($h['role'] === 'user' ? 'Person: ' : 'You: ').$h['text']."\n\n";
            $s .= "</previous_messages>\n";
        }
        $s .= "\n".self::sizePolicy($writer['provider'])."\n";
        $this->saveSeen($storyId, $this->snapshot($story) + ['settings'=>$this->settingsLines($writer), 'failed'=>array_fill_keys(array_keys($failed), true), 'comic'=>(int)$comic['rev'],
            'reader'=>hash('sha256', $this->vault->readerAbout($key)), 'reader_notes'=>hash('sha256', $this->vault->readerNotes($key)['text']), 'book'=>$book]
            + ($story['kind'] === 'vn' ? ['vn'=>$this->vnSnapshot($storyId, $key)] : []), $key);
        return $s.'</story_state>';
    }

    /**
     * The story as a client over MCP sees it when it opens it: the <story_state> the writer gets, with the last messages
     * of the app's writer conversation, and nothing marked as seen (that is the app's writer's memory of what it knows).
     */
    public function openState(string $storyId, string $key): string
    {
        $writer = $this->storySettings($storyId, $key);
        [$s, $story] = $this->stateText($storyId, $writer, $key, ['balance'=>null, 'image'=>null], $this->failedIllustrations($storyId, $key));
        $said = $this->recentMessages($storyId, (int)$story['conversation'], $key, 120);
        if ($said) {
            $s .= "\nThe last messages between the person and the app's own writer about this story, for continuity:\n<app_writer_messages>\n";
            foreach ($said as $h) $s .= ($h['role'] === 'user' ? 'Person: ' : 'App writer: ').$h['text']."\n\n";
            $s .= "</app_writer_messages>\n";
        }
        return $s.'</story_state>';
    }

    /** The story's writer settings (what the person chose in the writer window), valid; the defaults when they no longer are. */
    public function storySettings(string $storyId, string $key): array
    {
        $saved = $this->stories->listed($storyId, $key)['writer'];
        try { return self::settings(is_array($saved) ? $saved : null); } catch (HttpError) { return self::settings(null); }
    }

    /**
     * @return array{0:string,1:array,2:array,3:array<string,int>} the state's text (open), the story, its comic settings
     *         and, with $book, the revision of each chapter whose full text is in it, by id
     */
    private function stateText(string $storyId, array $writer, string $key, array $credit, array $failed, bool $book = false): array
    {
        $story = $this->storyWithComic($storyId, $key);
        $s = "<story_state>\nTitle: ".($story['title'] !== '' ? $story['title'] : '(untitled)')."\n";
        if ($story['series'] !== '') $s .= 'Series: '.$story['series']."\n";
        $s .= 'Synopsis: '.($story['synopsis'] !== '' ? $story['synopsis'] : '(none yet)')."\n".self::coverLine($story)."\n".$this->settingsLines($writer);
        $s .= (self::creditLine($credit, $writer) ?? self::imagePayer($writer)[0].' credit for illustrations: unknown (the person has not entered the balance in the app). If an illustration fails for lack of credit, you will be told.')."\n";
        foreach ($failed as $f) $s .= self::failedLine($f)."\n";
        if (($about = $this->vault->readerAbout($key)) !== '') $s .= "\nWhat the person wrote about themselves as a reader, in their own words, for all their stories:\n<reader>\n".$about."\n</reader>\n";
        if (($notes = $this->vault->readerNotes($key)['text']) !== '') $s .= "\nYour own notes on the person as a reader, from all their stories so far (update_reader_notes; they can read and edit them):\n<reader_notes>\n".$notes."\n</reader_notes>\n";
        $s .= "\nChapters (".count($story['chapters'])."):\n";
        foreach ($story['chapters'] as $n => $c) $s .= self::chapterLine($n + 1, $c)."\n";
        if (!$story['chapters']) $s .= "(none yet)\n";
        // A visual novel opens with its scenes too, after the outline (vnOpening()).
        [$text, $vnText, $inBook] = ['', '', []];
        if ($book && $story['kind'] === 'vn') [$text, $vnText, $inBook] = $this->vnOpening($storyId, $story['chapters'], $writer['provider'], $key);
        elseif ($book) [$text, $inBook] = self::bookText($story['chapters'], $writer['provider']);
        $s .= $text;
        $s .= "\nStory bible (visible to the person), revision ".$story['notes']['rev'].":\n<bible>\n".($story['notes']['text'] !== '' ? $story['notes']['text'] : '(empty)')."\n</bible>\n";
        $s .= "\nYour plan (hidden from the person), revision ".$story['plan']['rev'].":\n<plan>\n".($story['plan']['text'] !== '' ? $story['plan']['text'] : '(empty: plan the whole work before writing the first chapter)')."\n</plan>\n";
        // Planning a new story: how the person's other stories are built, so this one is built differently.
        if ($story['plan']['text'] === '' && ($others = $this->stories->architectures($storyId, $key))) $s .= "\n".self::otherStories($others);
        $s .= "\nVisual style and sheets of characters and places (visible to the person; their image ids go in references):\n".self::castLines($story['cast'], $failed);
        $comic = $this->stories->comics()->settings($storyId, $key);
        if ($comic['started']) $s .= "\n".$this->stories->comics()->outline($storyId, $key, self::pageFailures($failed));
        if (isset($story['comic_space'])) $s .= "\n".self::comicSpaceText($story['comic_space'], $failed);
        if ($story['kind'] === 'vn') $s .= $this->vnState($storyId, $key, $failed).$vnText;
        return [$s, $story, $comic, $inBook];
    }

    /**
     * The person's other stories as the writer planning a new one is shown them (only then, and never shown to the
     * person): how each is built, from the Architecture section its plan keeps (Stories::architectures).
     */
    private static function otherStories(array $others): string
    {
        $s = "How the person's other stories are built, from their plans (background for you alone, so that this one is built differently; see Each story its own):\n<other_stories>\n";
        foreach ($others as $o) {
            $about = array_filter([$o['series'] !== '' ? 'series "'.$o['series'].'"' : null, $o['kind'] === 'vn' ? 'a visual novel' : null,
                $o['chapters'] > 0 ? $o['chapters'].' chapter'.($o['chapters'] === 1 ? '' : 's').' so far' : null]);
            $s .= '- "'.($o['title'] !== '' ? $o['title'] : 'untitled').'"'.($about ? ' ('.implode(', ', $about).')' : '')
                .($o['found'] ? ':' : ', whose plan has no Architecture section; the start of its plan:')."\n".$o['architecture']."\n";
        }
        return $s."</other_stories>\n";
    }

    /**
     * The full text of the chapters for the start of a conversation, so the writer works with the whole book in mind,
     * not only its summaries. Every chapter while the book fits in two fifths of the room before the next new
     * conversation (about 1.4 tokens a word); past that, the latest chapters that fit, the others only summarized.
     * @return array{0:string,1:array<string,int>} the text, and the revision of each chapter in it by id
     */
    private static function bookText(array $chapters, string $provider): array
    {
        if (!$chapters) return ['', []];
        $room = intdiv(self::newConversationAt($provider) * 2, 5);
        $from = count($chapters); $tokens = 0; $words = 0;
        while ($from > 0) {
            $w = Stories::words($chapters[$from - 1]['text']);
            if ($tokens + (int)ceil($w * 1.4) > $room) break;
            $tokens += (int)ceil($w * 1.4); $words += $w; $from--;
        }
        $total = count($chapters);
        if ($from === $total) return ["\nThe book is too long to show here in full: read the chapters you need with read_chapter.\n", []];
        $text = "\n".($from === 0 ? 'The whole book, as the person reads it now ('.number_format($words, 0, '.', ',').' words):'
            : 'The book is too long to show here whole: chapters 1 to '.$from.' are only in the summaries above (read_chapter brings any of them), and chapters '.($from + 1).' to '.$total.' follow in full, as the person reads them now:')
            ."\n<book>\n";
        $revs = [];
        for ($i = $from; $i < $total; $i++) { $text .= self::chapterBlock($i + 1, $chapters[$i]); $revs[(string)$chapters[$i]['id']] = (int)$chapters[$i]['rev']; }
        return [$text."</book>\nYou don't need read_chapter for the chapters in <book> unless a <story_update> says the person changed one.\n", $revs];
    }

    /** One chapter's full text, as the book shows it to the writer (bookText(), reviewBook()). */
    private static function chapterBlock(int $n, array $ch): string
    {
        return '<chapter number="'.$n.'">'."\nTitle: ".$ch['title'].' ('.number_format(Stories::words($ch['text']), 0, '.', ',')." words)\n\n".rtrim($ch['text'])."\n</chapter>\n\n";
    }

    /** What changed since the agent's last turn, then $last (the conversation's size), or null when there is nothing to say. */
    private function updateBlock(string $storyId, array $writer, string $key, ?string $first = null, array $credit = ['balance'=>null, 'image'=>null], array $failed = [], ?string $last = null): ?string
    {
        $story = $this->storyWithComic($storyId, $key); $seen = $this->seen($storyId, $key);
        $now = $this->snapshot($story); $lines = $first !== null ? [$first] : [];
        // The chapters this conversation opened with, in full, for the whole conversation (reviewBook()).
        if (is_array($seen['book'] ?? null)) $now['book'] = $seen['book'];
        // Once, at the first message after the person duplicated the story (the copy's seen says so; the next one doesn't).
        $origin = is_array($seen['origin'] ?? null) ? $seen['origin'] : null;
        if (($origin['how'] ?? null) === 'copy') array_unshift($lines, 'The person duplicated the story: this is now a copy of "'.$origin['title'].'", with the same chapters, bible, plan, sheets and comic, and this conversation. '
            .'From here on the two are separate: what you change here changes only this copy, and the original stays as it was.');
        if (($origin['how'] ?? null) === 'import') array_unshift($lines, 'The person exported this story to a file and imported it into the app again, as a new story ("'.$origin['title'].'"), with its chapters, bible, plan, sheets, comic and this conversation. '
            .'Its pictures are the same images, under new ids where they changed (the earlier messages already use the new ones). What you change here doesn\'t change any other copy of the story.');
        // Illustrations that failed since the last turn, once each.
        $now['failed'] = is_array($seen['failed'] ?? null) ? $seen['failed'] : [];
        foreach ($failed as $jobId => $f) if (!isset($now['failed'][$jobId])) { $lines[] = self::failedLine($f); $now['failed'][$jobId] = true; }
        if (($line = self::creditLine($credit, $writer)) !== null) $lines[] = $line;
        $numbers = []; foreach ($story['chapters'] as $n => $c) $numbers[$c['id']] = $n + 1;
        foreach ($story['chapters'] as $n => $c) {
            $was = $seen['chapters'][$c['id']] ?? null;
            if ($was === null) $lines[] = 'Chapter '.($n + 1).' "'.$c['title'].'" was added by '.self::who($c['by']).' ('.$c['words'].' words). Read it before you continue.';
            elseif ((int)$was['rev'] !== (int)$c['rev'] && $c['by'] !== 'agent') $lines[] = 'Chapter '.($n + 1).' "'.$c['title'].'" was edited by '.self::who($c['by']).' (now '.$c['words'].' words). Read it again before relying on what you remember of it.';
        }
        foreach (($seen['chapters'] ?? []) as $id => $was) if (!isset($numbers[$id])) $lines[] = 'The chapter "'.$was['title'].'" was deleted by the person.';
        $kept = array_values(array_filter($seen['order'] ?? [], fn($id) => isset($numbers[$id])));
        $order = array_values(array_filter($now['order'], fn($id) => isset($seen['chapters'][$id])));
        if ($kept !== $order) $lines[] = 'The person reordered the chapters. Now: '.implode('; ', array_map(fn($c) => $numbers[$c['id']].'. "'.$c['title'].'"', $story['chapters'])).'.';
        if (($seen['details'] ?? null) !== $now['details']) $lines[] = 'The story details changed. Title: '.$story['title'].($story['series'] !== '' ? '. Series: '.$story['series'] : '').'. Synopsis: '.($story['synopsis'] !== '' ? $story['synopsis'] : '(none)');
        if ((int)($seen['notes'] ?? 0) !== (int)$story['notes']['rev'] && $story['notes']['by'] !== 'agent') $lines[] = ucfirst(self::who($story['notes']['by'])).' edited the story bible. It now reads:'."\n<bible>\n".$story['notes']['text']."\n</bible>";
        if ((int)($seen['plan'] ?? 0) !== (int)$story['plan']['rev'] && $story['plan']['by'] !== 'agent') $lines[] = ucfirst(self::who($story['plan']['by'])).' edited your plan. Treat their changes as direction. It now reads:'."\n<plan>\n".$story['plan']['text']."\n</plan>";
        if ((int)($seen['cast'] ?? 0) !== (int)$story['cast']['rev'] && $story['cast']['by'] !== 'agent') $lines[] = ucfirst(self::who($story['cast']['by'])).' changed the visual style or the sheets. Now:'."\n".rtrim(self::castLines($story['cast'], $failed));
        if (array_key_exists('cover', $seen) && $seen['cover'] !== $now['cover'] && in_array($story['cover_by'], ['person', 'mcp'], true)) $lines[] = ucfirst(self::who($story['cover_by'])).($now['cover'] !== null ? ' chose a cover for the story (image '.$now['cover'].').' : ' removed the story\'s cover.');
        $comic = $this->stories->comics()->settings($storyId, $key);
        if ((int)($seen['comic'] ?? 0) !== (int)$comic['rev'] && $comic['by'] !== 'agent') $lines[] = ucfirst(self::who($comic['by'])).' changed the comic adaptation (its settings or pages). Now:'."\n".rtrim($this->stories->comics()->outline($storyId, $key, self::pageFailures($failed)));
        $now['comic'] = (int)$comic['rev'];
        if (isset($story['comic_space'])) {
            $space = $story['comic_space']; $was = is_array($seen['comic_space'] ?? null) ? $seen['comic_space'] : null;
            // A conversation that began before the adaptation had a workspace of its own hears of it once, whole.
            if ($was === null) $lines[] = 'The comic adaptation now has a workspace of its own, apart from the book\'s: its bible, its plan, its sheets and its lettering (comic: true in read_notes, update_notes, read_plan, update_plan, set_style, character_sheet and update_character). The pages are drawn with its sheets and lettering. If your plan or the book\'s bible has a part about the adaptation, move it there. Now:'."\n".rtrim(self::comicSpaceText($space, $failed));
            else {
                if ((int)($was['notes'] ?? 0) !== (int)$space['notes']['rev'] && $space['notes']['by'] !== 'agent') $lines[] = ucfirst(self::who($space['notes']['by'])).' edited the adaptation bible. It now reads:'."\n<adaptation_bible>\n".$space['notes']['text']."\n</adaptation_bible>";
                if ((int)($was['plan'] ?? 0) !== (int)$space['plan']['rev'] && $space['plan']['by'] !== 'agent') $lines[] = ucfirst(self::who($space['plan']['by'])).' edited the adaptation plan. Treat their changes as direction. It now reads:'."\n<adaptation_plan>\n".$space['plan']['text']."\n</adaptation_plan>";
                if ((int)($was['cast'] ?? 0) !== (int)$space['cast']['rev'] && !in_array($space['cast']['by'], ['agent', null], true)) $lines[] = ucfirst(self::who($space['cast']['by'])).' changed the adaptation\'s lettering or sheets. Now:'."\n".rtrim(self::castLines($space['cast'], $failed, true));
            }
        }
        if ($story['kind'] === 'vn') { [$more, $now['vn']] = $this->vnUpdate($storyId, $seen, $key, $failed); array_push($lines, ...$more); }
        if (($seen['settings'] ?? null) !== null && $seen['settings'] !== $this->settingsLines($writer)) $lines[] = trim($this->settingsLines($writer));
        $now['settings'] = $this->settingsLines($writer);
        // What the person wrote about themselves as a reader, when it changed (or a conversation begun before it existed).
        $about = $this->vault->readerAbout($key); $now['reader'] = hash('sha256', $about);
        if (($seen['reader'] ?? hash('sha256', '')) !== $now['reader']) $lines[] = $about === '' ? 'The person removed what they had written about themselves as a reader.'
            : (isset($seen['reader']) ? 'The person changed what they wrote about themselves as a reader. It now reads:' : 'The person wrote about themselves as a reader, for all their stories:')."\n<reader>\n".$about."\n</reader>";
        // Your notes on the person as a reader, when someone else changed them: the person, an assistant over MCP, or
        // the writer of another of their stories.
        $notes = $this->vault->readerNotes($key); $now['reader_notes'] = hash('sha256', $notes['text']);
        if (($seen['reader_notes'] ?? hash('sha256', '')) !== $now['reader_notes'] && !($notes['by'] === 'agent' && $notes['story'] === $storyId))
            $lines[] = ($notes['by'] === 'agent' ? 'You changed your notes on the person as a reader while working on another of their stories.' : ucfirst(self::who($notes['by'])).' edited your notes on them as a reader.')
                .($notes['text'] !== '' ? ' They now read:'."\n<reader_notes>\n".$notes['text']."\n</reader_notes>" : ' They are empty now.');
        $this->saveSeen($storyId, $now, $key);
        if ($last !== null) $lines[] = $last;
        return $lines ? "<story_update>\n- ".implode("\n- ", $lines)."\n</story_update>" : null;
    }

    /** Who changed what the writer didn't: the person in the app, or an assistant they connected to it over MCP (mcp.php). */
    private static function who(?string $by): string { return $by === 'mcp' ? 'an assistant the person connected to the app' : 'the person'; }

    private static function failedLine(array $f): string
    {
        $what = ['cover'=>'The cover', 'cast'=>'The character sheet', 'page'=>'The comic page', 'vn'=>'The visual novel art'][$f['for'] ?? ''] ?? 'The illustration';
        $where = ['cover'=>'The story keeps the cover it had; the person can retry it.', 'cast'=>'It stays in the sheets as failed, where the person can retry or remove it.',
            'vn'=>'It stays in the art as failed (with the picture it had, if any), where the person can retry or remove it, or you can make it again.',
            'page'=>'The page keeps the image it had (or none); the person can retry it, or you can draw it again.'][$f['for'] ?? '']
            ?? 'Its line stays in the chapter, where the person can retry or remove it.';
        return $what.' "'.($f['label'] !== '' ? $f['label'] : 'untitled').'" failed'.($f['credit']
            ? ': '.(!empty($f['gemini']) ? 'Google Gemini' : 'OpenAI').' has no credit left. Don\'t ask for images until the person says they added credit.'
            : ($f['error'] !== '' ? ' ('.mb_substr($f['error'], 0, 200).')' : '').'. '.$where);
    }

    /** The agent changed these itself: they are not news at its next turn. */
    private function know(string $storyId, string $key): void
    {
        $seen = $this->seen($storyId, $key); $story = $this->stories->get($storyId, $key, null, true); $now = $this->snapshot($story);
        foreach ($story['chapters'] as $c) if ($c['by'] === 'agent' || !isset($seen['chapters'][$c['id']])) {
            if ($c['by'] === 'agent' || ($seen['agent_added'][$c['id']] ?? false)) $seen['chapters'][$c['id']] = $now['chapters'][$c['id']];
        }
        foreach (array_keys($seen['chapters'] ?? []) as $id) if (!isset($now['chapters'][$id]) && ($seen['agent_deleted'][$id] ?? false)) unset($seen['chapters'][$id]);
        if ($story['notes']['by'] === 'agent') $seen['notes'] = $now['notes'];
        if ($story['plan']['by'] === 'agent') $seen['plan'] = $now['plan'];
        if ($story['cast']['by'] === 'agent') $seen['cast'] = $now['cast'];
        $comic = $this->stories->comics()->settings($storyId, $key);
        if ($comic['by'] === 'agent') $seen['comic'] = (int)$comic['rev'];
        if (!in_array($story['cover_by'], ['person', 'mcp'], true)) $seen['cover'] = $now['cover'];
        if (($seen['agent_order'] ?? false)) $seen['order'] = $now['order'];
        if (($seen['agent_details'] ?? false)) $seen['details'] = $now['details'];
        unset($seen['agent_added'], $seen['agent_deleted'], $seen['agent_order'], $seen['agent_details']);
        if ($story['kind'] === 'vn') $seen = $this->vnKnow($storyId, $seen, $key);
        $this->saveSeen($storyId, $seen, $key);
    }

    /** What the app's writer did itself in its turn (know() then takes it as known); not what a client over MCP did. */
    private function noteAgent(ToolContext $c, string $flag, ?string $id): void
    { if ($c->by === 'agent') $this->noteSeen($c->storyId, $flag, $id, $c->key); }

    private function noteSeen(string $storyId, string $flag, ?string $id, string $key): void
    {
        $seen = $this->seen($storyId, $key);
        if ($id === null) $seen[$flag] = true; else $seen[$flag][$id] = true;
        $this->saveSeen($storyId, $seen, $key);
    }

    // =================================================================================================================
    // Sending a message
    // =================================================================================================================

    /**
     * The person's message: kept, with what the agent must know of the story, and a turn queued to answer it. The page
     * then asks for the turn to run (run()).
     */
    /** At most this many gallery images go with one message. */
    public const MAX_ATTACHED = 6;

    public function send(string $storyId, mixed $text, array $writerInput, string $key, mixed $credit = null, mixed $images = null): array
    {
        $text = trim(Stories::cleanText($text, 20000, t('The message')));
        $ids = $this->attachmentIds($images, $key);
        if ($text === '' && !$ids) throw new HttpError(422, t('Write a message.'));
        $this->storyRow($storyId);
        // While the writer works, the message waits for its next step: the writer reads it without stopping.
        $working = $this->transaction(function () use ($storyId, $text, $ids, $key) {
            $active = $this->activeTurn($storyId);
            if ($active) $this->enqueue($storyId, $text, $ids, $key);
            return $active;
        });
        if ($working) return $this->publicTurn($this->turnRow((string)$working['id']), $key) + ['waiting'=>true];
        $attached = $this->attachments($ids, $key);
        $meta = $this->stories->get($storyId, $key);
        $writer = self::settings($meta['writer'], $writerInput);
        $this->requireKey($writer['provider'], $key);
        if ($writer !== $meta['writer']) $this->stories->update($storyId, ['writer'=>$writer], $key);
        return $this->queue($storyId, $writer, $text, $key, self::credit($credit), $this->failedIllustrations($storyId, $key), $attached);
    }

    /**
     * Gallery images the person attached to a message: the writer sees each one (as the image API would, at most 1024
     * px) with its id, to describe the characters and places in the bible and to pass in the references of illustrate.
     * @return list<array{id:string,title:string,mime:string,data:string}>
     */
    private function attachments(array $ids, string $key): array
    {
        $out = [];
        foreach ($ids as $id) {
            $item = $this->vault->still($id, $key);
            $picture = $this->vault->modelPreview($item, $key);
            $out[] = ['id'=>$id, 'title'=>mb_substr(trim((string)($item['label'] ?? $item['title'] ?? '')), 0, 120), 'mime'=>$picture['mime'], 'data'=>$picture['data']];
        }
        return $out;
    }

    /** The ids of the gallery images attached to a message, checked. @return list<string> */
    private function attachmentIds(mixed $images, string $key): array
    {
        if ($images === null || $images === []) return [];
        if (!is_array($images) || !array_is_list($images)) throw new HttpError(422, t('Invalid attached images.'));
        $ids = array_values(array_unique(array_map(fn($id) => is_string($id) ? trim($id) : '', $images)));
        if (count($ids) > self::MAX_ATTACHED) throw new HttpError(422, t('Attach up to {max} images per message.', ['max'=>self::MAX_ATTACHED]));
        foreach ($ids as $id) {
            if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) throw new HttpError(422, t('Invalid attached image.'));
            $this->vault->still($id, $key);
        }
        return $ids;
    }

    // =================================================================================================================
    // Messages sent while the writer works: they wait here and go into the conversation at its next step
    // =================================================================================================================

    /** Said to the writer with each of them. */
    private const DURING = '(The person wrote this while you were working. Take it into account from here on, and answer it in your reply.)';

    private function enqueue(string $storyId, string $text, array $ids, string $key): void
    {
        $id = self::newId();
        $this->vault->db->prepare('INSERT INTO story_inbox(id,story_id,user_id,created,content) VALUES(?,?,?,?,CAST(? AS BLOB))')
            ->execute([$id, $storyId, $this->owner(), time(), Crypto::seal(json_encode(['text'=>$text, 'images'=>$ids], JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE), $key, "story-inbox:$id")]);
    }

    /** The messages waiting for the writer, oldest first. @return list<array{id:string,created:int,text:string,images:list<string>}> */
    public function inbox(string $storyId, string $key): array
    {
        $q = $this->vault->db->prepare('SELECT * FROM story_inbox WHERE story_id=? AND user_id=? ORDER BY created, rowid'); $q->execute([$storyId, $this->owner()]);
        $out = [];
        foreach ($q->fetchAll() as $r) {
            $d = json_decode(Crypto::open((string)$r['content'], $key, 'story-inbox:'.$r['id']), true) ?: [];
            $out[] = ['id'=>(string)$r['id'], 'created'=>(int)$r['created'], 'text'=>(string)($d['text'] ?? ''), 'images'=>array_values(array_filter((array)($d['images'] ?? []), 'is_string'))];
        }
        return $out;
    }

    private function inboxWaiting(string $storyId): bool
    {
        $q = $this->vault->db->prepare('SELECT 1 FROM story_inbox WHERE story_id=? AND user_id=? LIMIT 1'); $q->execute([$storyId, $this->owner()]);
        $found = $q->fetchColumn() !== false; $q->closeCursor();
        return $found;
    }

    /** The waiting messages go into the conversation as the person's, in order. Returns how many. */
    private function deliverInbox(string $storyId, int $conversation, string $turnId, string $provider, string $key): int
    {
        if (!$this->inboxWaiting($storyId)) return 0;
        // The images are read before the database is locked.
        $ready = [];
        foreach ($this->inbox($storyId, $key) as $m) {
            try { $attached = $this->attachments($m['images'], $key); } catch (HttpError) { $attached = []; }
            $ready[] = [$m, $attached];
        }
        return $this->transaction(function () use ($ready, $storyId, $conversation, $turnId, $provider, $key) {
            $n = 0;
            foreach ($ready as [$m, $attached]) {
                $q = $this->vault->db->prepare('DELETE FROM story_inbox WHERE id=? AND user_id=?'); $q->execute([$m['id'], $this->owner()]);
                if ($q->rowCount() !== 1) continue;
                $this->addMessage($storyId, $conversation, $turnId, 'user', $provider, null, self::userNative($provider, $m['text'] !== '' ? self::DURING."\n\n".$m['text'] : self::DURING, $attached),
                    ['text'=>$m['text'], 'during'=>true] + ($attached ? ['images'=>array_column($attached, 'id')] : []), $key);
                $n++;
            }
            return $n;
        });
    }

    private function requireKey(string $provider, string $key): void
    {
        if ($provider === 'claude' && $this->vault->claudeKey($key) === '') throw new HttpError(422, t('Add the Claude API key in Settings to write with Claude.'));
        if ($provider === 'openai' && $this->vault->apiKey($key) === '') throw new HttpError(422, t('Add the OpenAI API key in Settings.'));
        if ($provider === 'gemini' && $this->vault->geminiKey($key) === '') throw new HttpError(422, t('Add the Gemini API key in Settings to write with Gemini.'));
    }

    /** Queues a turn: with the person's message, or with none to go on from where a turn stopped. */
    private function queue(string $storyId, array $writer, ?string $text, string $key, array $credit, array $failed, array $attached = [], bool $review = false): array
    {
        return $this->transaction(function () use ($storyId, $writer, $text, $key, $credit, $failed, $attached, $review) {
            if ($this->activeTurn($storyId)) throw new HttpError(409, t('The writer is still working on this story. Wait for it to finish or stop the request.'));
            $row = $this->storyRow($storyId); $conversation = (int)$row['conversation'];
            $previous = $this->messages($storyId, $key, $conversation, 0, false, 1);
            $why = null; $size = 0;
            if ($previous) {
                // Switching writer keeps the conversation (history() translates between Claude and GPT); only a long one starts over.
                $size = $this->lastContext($storyId, $conversation, $key);
                if ($size >= self::newConversationAt($writer['provider'])) $why = 'long';
                // The person's Revisar o livro: the writer reads the whole book in a conversation of its own.
                if ($review && $why === null) $why = 'review';
                if ($why !== null) {
                    $conversation++;
                    $this->vault->db->prepare('UPDATE stories SET conversation=? WHERE id=?')->execute([$conversation, $storyId]);
                    $previous = [];
                }
            }
            $before = $previous ? $this->lastWriter($storyId, $conversation, $key) : null;
            $switched = $before !== null && ($before['provider'] !== $writer['provider'] || $before['model'] !== $writer['model']) ? $before : null;
            [$topEffort, $effortChange] = $writer['provider'] === 'claude' && $previous ? $this->claudeEffort($storyId, $conversation, $writer, $key) : [$writer['effort'], null];
            $turnId = self::newId(); $now = time();
            $this->vault->db->prepare("INSERT INTO story_turns(id,story_id,user_id,conversation,status,created,updated) VALUES(?,?,?,?,'queued',?,?)")
                ->execute([$turnId, $storyId, $this->owner(), $conversation, $now, $now]);
            $this->saveTurnData($turnId, ['writer'=>$writer, 'costs'=>[], 'cost'=>0, 'steps'=>0, 'jobs'=>[], 'credit'=>$credit]
                + ($writer['provider'] === 'claude' ? ['top_effort'=>$topEffort] : []), $key);
            $provider = $writer['provider'];
            // A new reasoning level goes into the conversation as a message of the app, from this message on: changing
            // it at the top of the request would make the whole conversation be written to the cache again.
            if ($effortChange !== null) $this->addMessage($storyId, $conversation, $turnId, 'context', 'claude', null,
                ['role'=>'system', 'content'=>[], 'output_config'=>['effort'=>$effortChange]], ['hidden'=>true], $key);
            if (!$previous) {
                $handoff = $this->handoff($storyId, $conversation, $key);
                $notice = match ($why) {
                    'long'=>t('New conversation: the previous one got long. The writer gets the whole book, the bible and the plan again and goes on from where it stopped.'),
                    'review'=>t('New conversation for the review: the writer gets the whole book and goes on writing with it in memory.'),
                    default=>null,
                };
                $state = $this->stateBlock($storyId, $writer, $key, $handoff ?: null, $why, $credit, $failed);
                $view = ['hidden'=>true] + ($notice !== null && $conversation > 1 ? ['notice'=>$notice] : []);
                $this->addMessage($storyId, $conversation, $turnId, 'context', $provider, null, self::userNative($provider, $state), $view, $key);
            } else {
                $handover = $switched ? 'The writer changed: you are now '.self::writerName($writer).', taking over this conversation from '.self::writerName($switched)
                    .'. Treat the earlier replies as your own work and carry on with the same plan, bible and voice.' : null;
                // How big the conversation is, every time: the writer has no other way to know.
                $update = $this->updateBlock($storyId, $writer, $key, $handover, $credit, $failed, $size > 0 ? self::sizeLine($provider, $size) : null);
                $view = ['hidden'=>true] + ($switched ? ['notice'=>t('Now {writer} is writing. The conversation goes on.', ['writer'=>self::writerName($writer)])] : []);
                if ($update !== null) $this->addMessage($storyId, $conversation, $turnId, 'context', $provider, null, self::userNative($provider, $update), $view, $key);
            }
            // Messages left waiting by a turn that stopped without reading them.
            $this->deliverInbox($storyId, $conversation, $turnId, $provider, $key);
            if ($text !== null) $this->addMessage($storyId, $conversation, $turnId, 'user', $provider, null, self::userNative($provider, $text, $attached),
                ['text'=>$review ? t(self::REVIEW_SHOWN) : $text] + ($attached ? ['images'=>array_column($attached, 'id')] : []) + ($review ? ['review'=>true] : []), $key);
            elseif ($this->lastIsAssistant($storyId, $conversation, $key)) {
                $this->addMessage($storyId, $conversation, $turnId, 'context', $provider, null, self::userNative($provider, 'Please continue from where you stopped.'), ['hidden'=>true], $key);
            }
            return $this->publicTurn($this->turnRow($turnId), $key);
        });
    }

    /** The writer of the conversation's last turn. */
    private function lastWriter(string $storyId, int $conversation, string $key): ?array
    {
        $q = $this->vault->db->prepare('SELECT * FROM story_turns WHERE story_id=? AND user_id=? AND conversation=? ORDER BY created DESC, rowid DESC LIMIT 1');
        $q->execute([$storyId, $this->owner(), $conversation]); $r = $q->fetch(); $q->closeCursor();
        if (!$r) return null;
        $w = $this->turnData($r, $key)['writer'] ?? null;
        return is_array($w) ? self::settings($w) : null;
    }

    /**
     * Claude's reasoning level in a conversation: [the level at the top of the request, which stays as the conversation's
     * last request had it, so the cache holds, and the level to change to with a message of the app, or null]. The
     * conversation's last Claude turn ran at its own writer's level (set at the top or by such a message).
     */
    private function claudeEffort(string $storyId, int $conversation, array $writer, string $key): array
    {
        $q = $this->vault->db->prepare('SELECT * FROM story_turns WHERE story_id=? AND user_id=? AND conversation=? ORDER BY created DESC, rowid DESC');
        $q->execute([$storyId, $this->owner(), $conversation]);
        foreach ($q->fetchAll() as $r) {
            $d = $this->turnData($r, $key); $w = $d['writer'] ?? null;
            if (!is_array($w) || ($w['provider'] ?? '') !== 'claude' || !in_array($w['effort'] ?? null, self::EFFORTS, true)) continue;
            $top = in_array($d['top_effort'] ?? null, self::EFFORTS, true) ? $d['top_effort'] : $w['effort'];
            return [$top, $w['effort'] === $writer['effort'] ? null : $writer['effort']];
        }
        return [$writer['effort'], null];
    }

    public static function writerName(array $writer): string
    {
        return (string)(self::models($writer['provider'])[$writer['model']]['label'] ?? $writer['model']);
    }

    private function lastIsAssistant(string $storyId, int $conversation, string $key): bool
    {
        $rows = $this->messages($storyId, $key, $conversation, 0, true, 5);
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            if ($rows[$i]['native'] === null || (is_object($rows[$i]['native']) && ($rows[$i]['native']->role ?? '') === 'system')) continue;
            return $rows[$i]['role'] === 'assistant';
        }
        return false;
    }

    /** Tokens sent in the last request of a conversation. */
    private function lastContext(string $storyId, int $conversation, string $key): int
    {
        $q = $this->vault->db->prepare('SELECT * FROM story_turns WHERE story_id=? AND user_id=? AND conversation=? ORDER BY created DESC LIMIT 3'); $q->execute([$storyId, $this->owner(), $conversation]);
        foreach ($q->fetchAll() as $r) { $d = $this->turnData($r, $key); if (isset($d['context'])) return (int)$d['context']; }
        return 0;
    }

    /** Tokens from which the next message starts a new conversation, for the writer of that message. */
    public static function newConversationAt(string $provider): int
    { return $provider === 'claude' ? self::NEW_CONVERSATION_AT : self::NEW_CONVERSATION_OTHER; }

    private static function thousands(int $tokens): string
    { return number_format((int)round($tokens, -3), 0, '.', ','); }

    /**
     * What the writer is told of its conversation's size at each message. The API gives these models no count of their
     * own, and without one a writer guesses that a long conversation is full and stops short of what was asked.
     */
    private static function sizeLine(string $provider, int $tokens): string
    {
        return 'Conversation size: the last request of this conversation was about '.self::thousands($tokens).' tokens'
            .($provider === 'claude' ? ', of the '.self::thousands(self::WINDOW).' the model can take' : '').'. '.self::sizePolicy($provider);
    }

    private static function sizePolicy(string $provider): string
    {
        return 'The app tells you the conversation\'s size at each message, and starts a new conversation when it passes '.self::thousands(self::newConversationAt($provider))
            .' tokens, opening it again with the whole book, the bible and the plan. So length is the app\'s concern, not yours: do all the person asks for, and never stop or shorten your work because the conversation seems long.';
    }

    /** The last things said in the previous conversation, for the agent to pick up the thread. */
    private function handoff(string $storyId, int $conversation, string $key): array
    { return $conversation <= 1 ? [] : $this->recentMessages($storyId, $conversation - 1, $key); }

    /** The last things the person and the writer said in a conversation (up to 8, each cut at 1,500 characters). */
    private function recentMessages(string $storyId, int $conversation, string $key, int $scan = 400): array
    {
        if ($conversation < 1) return [];
        $said = [];
        foreach ($this->messages($storyId, $key, $conversation, 0, false, $scan) as $m) {
            $text = trim((string)($m['view']['text'] ?? ''));
            if ($text === '' || !in_array($m['role'], ['user', 'assistant'], true)) continue;
            $said[] = ['role'=>$m['role'], 'text'=>mb_strlen($text) > 1500 ? mb_substr($text, 0, 1500).'…' : $text];
        }
        return array_slice($said, -8);
    }

    private static function userNative(string $provider, string $text, array $attached = []): mixed
    {
        $parts = [['text', $text !== '' ? $text : '(The person sent images without a message.)']];
        if ($attached) {
            $parts[] = ['text', 'The person attached '.(count($attached) === 1 ? 'an image' : count($attached).' images').' from their gallery. Each id works in the references of illustrate and edit_illustration, and with view_image.'];
            foreach ($attached as $a) { $parts[] = ['text', 'Image '.$a['id'].($a['title'] !== '' ? ' ("'.$a['title'].'")' : '').':']; $parts[] = ['image', $a]; }
        }
        if ($provider === 'claude') return ['role'=>'user', 'content'=>array_map(fn($p) => $p[0] === 'text' ? ['type'=>'text', 'text'=>$p[1]]
            : ['type'=>'image', 'source'=>['type'=>'base64', 'media_type'=>$p[1]['mime'], 'data'=>$p[1]['data']]], $parts)];
        if ($provider === 'gemini') return [['type'=>'user_input', 'content'=>array_map(fn($p) => $p[0] === 'text' ? ['type'=>'text', 'text'=>$p[1]]
            : ['type'=>'image', 'mime_type'=>$p[1]['mime'], 'data'=>$p[1]['data']], $parts)]];
        return [['type'=>'message', 'role'=>'user', 'content'=>array_map(fn($p) => $p[0] === 'text' ? ['type'=>'input_text', 'text'=>$p[1]]
            : ['type'=>'input_image', 'image_url'=>'data:'.$p[1]['mime'].';base64,'.$p[1]['data']], $parts)]];
    }

    /** Starts a new conversation at the next message: the agent forgets the chat, not the story. */
    public function newConversation(string $storyId, string $key): void
    {
        if ($this->activeTurn($storyId)) throw new HttpError(409, t('The writer is still working on this story.'));
        $row = $this->storyRow($storyId);
        if (!$this->messages($storyId, $key, (int)$row['conversation'], 0, false, 1)) return;
        $this->vault->db->prepare('UPDATE stories SET conversation=conversation+1 WHERE id=?')->execute([$storyId]);
    }

    /**
     * The person's Revisar o livro: a turn in a new conversation in which the writer reads the whole book (review_book)
     * and reviews it as an editor, puts what it found in the plan and goes on writing from there with the book in mind.
     */
    public function review(string $storyId, array $writerInput, string $key, mixed $credit = null): array
    {
        $this->storyRow($storyId);
        if ($this->activeTurn($storyId)) throw new HttpError(409, t('The writer is still working on this story. Wait for it to finish or stop the request.'));
        if (!$this->stories->chapters($storyId, $key)) throw new HttpError(422, t('There are no chapters to review yet.'));
        $meta = $this->stories->get($storyId, $key);
        $writer = self::settings($meta['writer'], $writerInput);
        $this->requireKey($writer['provider'], $key);
        if ($writer !== $meta['writer']) $this->stories->update($storyId, ['writer'=>$writer], $key);
        return $this->queue($storyId, $writer, self::REVIEW, $key, self::credit($credit), $this->failedIllustrations($storyId, $key), [], true);
    }

    /**
     * What the page says before a review: the chapters and words, and about what it costs with the story's writer (reading
     * the book, the bible and the plan once, a few more requests that read them again, and the review's reasoning and reply).
     */
    public function reviewEstimate(string $storyId, string $key): array
    {
        $story = $this->stories->get($storyId, $key, null, true);
        $words = array_sum(array_map(fn($c) => (int)$c['words'], $story['chapters']));
        $tokens = (int)round(($words + Stories::words($story['notes']['text']) + Stories::words($story['plan']['text'])) * 1.4) + 4000;
        $writer = $this->storySettings($storyId, $key); $out = 25000; $again = 3 * $tokens;
        $cost = match ($writer['provider']) {
            'claude'=>Claude::cost($writer['model'], ['cache_creation_input_tokens'=>$tokens, 'cache_creation'=>['ephemeral_1h_input_tokens'=>$tokens], 'cache_read_input_tokens'=>$again, 'output_tokens'=>$out]),
            'gemini'=>Gemini::cost($writer['model'], ['input_tokens'=>$tokens + $again, 'cached_tokens'=>$again, 'output_tokens'=>$out]),
            default=>Gpt::cost($writer['model'], ['input_tokens'=>$tokens + $again, 'input_tokens_details'=>['cached_tokens'=>$again], 'output_tokens'=>$out]),
        };
        return ['chapters'=>count($story['chapters']), 'words'=>$words, 'tokens'=>$tokens, 'cost'=>round($cost, 2), 'writer'=>self::writerName($writer)];
    }

    /**
     * review_book: the chapters' full text, in order, and the phrases the book repeats. For the app's writer, a chapter
     * whose text this conversation opened with, unchanged since (bookText()), isn't sent again: it is already there.
     * @return array{0:string,1:string}
     */
    private function reviewBook(array $in, ToolContext $c): array
    {
        $chapters = $this->stories->chapters($c->storyId, $c->key);
        $total = count($chapters);
        if (!$total) throw new HttpError(422, 'The story has no chapters yet: there is nothing to review.');
        $from = (int)($in['from'] ?? 1); $to = min($total, (int)($in['to'] ?? $total));
        if ($from > $to) throw new HttpError(422, 'The story has chapters 1 to '.$total.'.');
        $part = $from > 1 || $to < $total;
        $opened = $c->by === 'agent' ? ($this->seen($c->storyId, $c->key)['book'] ?? []) : [];
        $there = fn(array $ch): bool => is_array($opened) && ($opened[(string)$ch['id']] ?? null) === (int)$ch['rev'];
        $send = array_sum(array_map(fn($ch) => $there($ch) ? 0 : Stories::words($ch['text']), array_slice($chapters, $from - 1, $to - $from + 1)));
        if (!$part && $send > self::REVIEW_WORDS) throw new HttpError(422, 'The book has '.number_format($send, 0, '.', ',').' words to read, too many at once: read it in parts, with from and to.');
        $book = ''; $words = 0; $known = [];
        for ($n = $from; $n <= $to; $n++) {
            $ch = $chapters[$n - 1]; $words += Stories::words($ch['text']);
            if ($there($ch)) $known[] = $n; else $book .= self::chapterBlock($n, $ch);
        }
        $head = ($part ? 'Chapters '.$from.' to '.$to.' of the '.$total : 'The whole book: '.$total.' chapter'.($total === 1 ? '' : 's')).' ('.number_format($words, 0, '.', ',').' words), as the person reads it now.'
            .($known ? ($book === '' ? ' Their full text is in <book> at the start of this conversation, unchanged since: review it from there.'
                : ' The text of chapter'.(count($known) === 1 ? ' ' : 's ').implode(', ', $known).' is in <book> at the start of this conversation, unchanged since; the others changed after it and follow here.') : '')
            .' Review it as the section Reviewing the whole book of your instructions says.';
        // The repeated phrases once, with the part that reaches the end, not again with every part.
        $repeated = $to === $total ? "\n\n<repetitions>\n".Repetitions::text(Repetitions::find(array_column($chapters, 'text'), null, 100))."\n</repetitions>"
            : "\n\n(The phrases the book repeats come with the part that reaches its last chapter.)";
        return [$head.($book !== '' ? "\n\n<book>\n".$book.'</book>' : '').$repeated,
            $part ? t('Read chapters {from} to {to} for the review', ['from'=>$from, 'to'=>$to]) : t('Read the whole book for the review')];
    }

    /**
     * Duplicates a story (Stories::copy) with its conversation, so the copy's writer remembers everything said so far,
     * as the original's does. Each message, turn and waiting message is a new row sealed for itself. What the turns
     * cost stays with the original: the copy's cost starts at zero, and its cache isn't kept warm before its first
     * message. The writer hears at the copy's next message that it is a copy. Not while the writer works on the story,
     * with a turn half done. Returns the copy as the list shows it.
     */
    public function duplicate(string $storyId, mixed $title, string $key): array
    {
        return $this->stories->transaction(function () use ($storyId, $title, $key) {
            if ($this->activeTurn($storyId)) throw new HttpError(409, t('The writer is working on this story. Wait for it to finish, or stop the request, to duplicate it.'));
            $copy = $this->stories->copy($storyId, $title, $key);
            $to = $copy['id']; $map = $copy['map']; $db = $this->vault->db; $owner = $this->owner();
            $json = fn(mixed $v) => strtr(json_encode($v, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $map);
            $q = $db->prepare('SELECT * FROM story_turns WHERE story_id=? AND user_id=? ORDER BY created, rowid'); $q->execute([$storyId, $owner]);
            $turns = $q->fetchAll(); $q->closeCursor();
            foreach ($turns as $r) $map[$r['id']] = self::newId();
            foreach ($turns as $r) {
                $d = array_diff_key($this->turnData($r, $key), array_flip(['costs', 'cost', 'usage', 'warms', 'warm_cost', 'sent_at', 'last_real']));
                $db->prepare('INSERT INTO story_turns(id,story_id,user_id,conversation,status,created,updated,started,worker_lock,data) VALUES(?,?,?,?,?,?,?,?,0,CAST(? AS BLOB))')
                    ->execute([$map[$r['id']], $to, $owner, $r['conversation'], $r['status'], $r['created'], $r['updated'], $r['started'],
                        Crypto::seal($json(['costs'=>[], 'cost'=>0] + $d), $key, 'story-turn:'.$map[$r['id']])]);
            }
            // One message at a time: a long conversation, with the images sent in it, can be large.
            $q = $db->prepare('SELECT seq FROM story_messages WHERE story_id=? AND user_id=? ORDER BY seq'); $q->execute([$storyId, $owner]);
            $seqs = $q->fetchAll(\PDO::FETCH_COLUMN); $q->closeCursor();
            $one = $db->prepare('SELECT * FROM story_messages WHERE seq=?');
            $add = $db->prepare('INSERT INTO story_messages(id,story_id,user_id,conversation,turn_id,role,content,created) VALUES(?,?,?,?,?,?,CAST(? AS BLOB),?)');
            foreach ($seqs as $seq) {
                $one->execute([$seq]); $r = $one->fetch(); $one->closeCursor();
                $id = self::newId();
                $add->execute([$id, $to, $owner, $r['conversation'], $r['turn_id'] === null ? null : ($map[$r['turn_id']] ?? null), $r['role'],
                    Crypto::seal(strtr(Crypto::open((string)$r['content'], $key, 'story-message:'.$r['id']), $map), $key, "story-message:$id"), $r['created']]);
            }
            $q = $db->prepare('SELECT * FROM story_inbox WHERE story_id=? AND user_id=? ORDER BY created, rowid'); $q->execute([$storyId, $owner]);
            foreach ($q->fetchAll() as $r) {
                $id = self::newId();
                $db->prepare('INSERT INTO story_inbox(id,story_id,user_id,created,content) VALUES(?,?,?,?,CAST(? AS BLOB))')
                    ->execute([$id, $to, $owner, $r['created'], Crypto::seal(strtr(Crypto::open((string)$r['content'], $key, 'story-inbox:'.$r['id']), $map), $key, "story-inbox:$id")]);
            }
            $seen = $this->seen($storyId, $key);
            if ($seen) $this->saveSeen($to, ['origin'=>['how'=>'copy', 'title'=>$copy['title'] !== '' ? $copy['title'] : 'untitled']] + json_decode($json($seen), true, 512, JSON_THROW_ON_ERROR), $key);
            return $this->stories->listed($to, $key);
        });
    }

    // =================================================================================================================
    // The conversation in a file (Stories::export makes the rest) and back
    // =================================================================================================================

    /** What a turn keeps in a file: who wrote and how, not what it cost (that stays with the account that paid it). */
    // gemini_flat: the conversation goes to Gemini with tool calls as text (its thought signatures were refused once).
    private const TURN_KEPT = ['writer', 'top_effort', 'context', 'steps', 'error', 'error_kind', 'note', 'gemini_flat'];
    private const ROLES = ['user', 'assistant', 'context', 'tools'];
    /** The most of the conversation one request carries, either way: a message with images in it can be large. */
    public const PACK_BATCH = 4 * 1048576;

    /**
     * The conversation with the writer for the story's file, without its messages (exportMessages pages through them):
     * the current conversation's number, the turns, what the writer has seen of the story and the messages waiting for
     * it. Null when there is none.
     */
    public function exportConversation(string $storyId, string $key): ?array
    {
        $row = $this->storyRow($storyId);
        $q = $this->vault->db->prepare('SELECT COUNT(*) FROM story_messages WHERE story_id=? AND user_id=?'); $q->execute([$storyId, $this->owner()]);
        $count = (int)$q->fetchColumn(); $q->closeCursor();
        if ($count === 0) return null;
        $q = $this->vault->db->prepare('SELECT * FROM story_turns WHERE story_id=? AND user_id=? ORDER BY created, rowid'); $q->execute([$storyId, $this->owner()]);
        $turns = array_map(fn($r) => ['id'=>$r['id'], 'conversation'=>(int)$r['conversation'], 'status'=>$r['status'], 'created'=>(int)$r['created'], 'updated'=>(int)$r['updated'],
            'started'=>$r['started'] === null ? null : (int)$r['started']] + array_intersect_key($this->turnData($r, $key), array_flip(self::TURN_KEPT)), $q->fetchAll());
        $seen = $this->seen($storyId, $key); unset($seen['origin']);
        return ['current'=>(int)$row['conversation'], 'messages'=>$count, 'turns'=>$turns, 'seen'=>$seen ?: null,
            'inbox'=>array_map(fn($m) => array_diff_key($m, ['id'=>1]), $this->inbox($storyId, $key))];
    }

    /**
     * The conversation's messages after $after (a message number), oldest first, as they are kept: what went to the
     * provider (native, objects kept as objects) and what the chat shows. As many as fit in about PACK_BATCH bytes, at
     * least one; next is where the following page starts, null at the end.
     */
    public function exportMessages(string $storyId, int $after, string $key): array
    {
        $this->storyRow($storyId);
        $q = $this->vault->db->prepare('SELECT seq FROM story_messages WHERE story_id=? AND user_id=? AND seq>? ORDER BY seq'); $q->execute([$storyId, $this->owner(), $after]);
        $seqs = $q->fetchAll(\PDO::FETCH_COLUMN); $q->closeCursor();
        $one = $this->vault->db->prepare('SELECT * FROM story_messages WHERE seq=?');
        $out = []; $size = 0; $next = null;
        foreach ($seqs as $n => $seq) {
            if ($out && $size >= self::PACK_BATCH) { $next = (int)$seqs[$n - 1]; break; }
            $one->execute([$seq]); $r = $one->fetch(); $one->closeCursor();
            $json = Crypto::open((string)$r['content'], $key, 'story-message:'.$r['id']); $size += strlen($json);
            $out[] = ['conversation'=>(int)$r['conversation'], 'turn'=>$r['turn_id'], 'role'=>$r['role'], 'created'=>(int)$r['created'], 'content'=>json_decode($json, false, 512, JSON_THROW_ON_ERROR)];
        }
        return ['messages'=>$out, 'next'=>$next];
    }

    /**
     * The conversation of a story just imported (Stories::import): its turns, under new ids, and the messages that were
     * waiting. Its messages come after, a page at a time (importMessages), with the ids changed by $map (the story's,
     * its chapters' and pages', the pictures': old => new). Until the last page the story shows a new conversation, so
     * if the import stops halfway the writer starts over from the story (with the last things said), never from half a
     * conversation.
     */
    public function importConversation(string $storyId, array $conversation, array $map, string $key): void
    {
        $this->stories->transaction(function () use ($storyId, $conversation, $map, $key) {
            $this->storyRow($storyId); $db = $this->vault->db; $owner = $this->owner();
            $map = array_filter($map, fn($v, $k) => is_string($k) && is_string($v) && preg_match('/\A[a-f0-9]{32}\z/D', $k) && preg_match('/\A[a-f0-9]{32}\z/D', $v), ARRAY_FILTER_USE_BOTH);
            $current = max(1, min(100000, is_int($conversation['current'] ?? null) ? $conversation['current'] : 1));
            $json = fn(mixed $v) => strtr(json_encode($v, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $map);
            $turns = is_array($conversation['turns'] ?? null) && array_is_list($conversation['turns']) ? $conversation['turns'] : [];
            foreach ($turns as $t) {
                if (!is_array($t) || !is_string($t['id'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/D', $t['id']) || isset($map[$t['id']])) continue;
                $id = self::newId(); $map[$t['id']] = $id;
                $status = in_array($t['status'] ?? null, ['completed', 'failed', 'cancelled', 'interrupted'], true) ? $t['status'] : 'interrupted';
                $at = fn(string $k) => is_int($t[$k] ?? null) ? $t[$k] : time();
                $data = ['costs'=>[], 'cost'=>0] + array_intersect_key($t, array_flip(self::TURN_KEPT));
                if (is_array($data['writer'] ?? null)) { try { $data['writer'] = self::settings($data['writer']); } catch (HttpError) { unset($data['writer']); } }
                $db->prepare('INSERT INTO story_turns(id,story_id,user_id,conversation,status,created,updated,started,worker_lock,data) VALUES(?,?,?,?,?,?,?,?,0,CAST(? AS BLOB))')
                    ->execute([$id, $storyId, $owner, max(1, min($current, is_int($t['conversation'] ?? null) ? $t['conversation'] : $current)), $status, $at('created'), $at('updated'),
                        is_int($t['started'] ?? null) ? $t['started'] : null, Crypto::seal($json($data), $key, "story-turn:$id")]);
            }
            foreach (is_array($conversation['inbox'] ?? null) && array_is_list($conversation['inbox']) ? $conversation['inbox'] : [] as $m) {
                if (!is_array($m)) continue;
                $text = trim(Stories::cleanText((string)($m['text'] ?? ''), 20000, t('The message')));
                // Pictures go with the message to the writer: only the account's own.
                $images = array_values(array_filter(array_map(fn($i) => is_string($i) ? strtr($i, $map) : '', is_array($m['images'] ?? null) ? $m['images'] : []), function (string $i) use ($key) {
                    if (!preg_match('/\A[a-f0-9]{32}\z/D', $i)) return false;
                    try { $this->vault->still($i, $key); return true; } catch (HttpError) { return false; }
                }));
                if ($text === '' && !$images) continue;
                $id = self::newId();
                $db->prepare('INSERT INTO story_inbox(id,story_id,user_id,created,content) VALUES(?,?,?,?,CAST(? AS BLOB))')
                    ->execute([$id, $storyId, $owner, is_int($m['created'] ?? null) ? $m['created'] : time(), Crypto::seal($json(['text'=>$text, 'images'=>$images]), $key, "story-inbox:$id")]);
            }
            // Where the import stands, in what the writer has seen (which only the writer reads): the ids to change in
            // the messages still to come, the conversation to go back to and what the writer had seen.
            $seen = is_array($conversation['seen'] ?? null) ? $conversation['seen'] : [];
            $this->saveSeen($storyId, ['importing'=>['map'=>$map, 'current'=>$current, 'seen'=>$seen, 'title'=>(string)($conversation['title'] ?? '')]], $key);
            $db->prepare('UPDATE stories SET conversation=? WHERE id=?')->execute([$current + 1, $storyId]);
        });
    }

    /**
     * A page of the imported conversation's messages, in order (each one's content as the JSON exportMessages gave,
     * a string, so its objects stay objects). With $done the conversation is whole: the story goes back to it, and the
     * writer hears at its next message that the story came from a file.
     */
    public function importMessages(string $storyId, mixed $messages, bool $done, string $key): array
    {
        return $this->stories->transaction(function () use ($storyId, $messages, $done, $key) {
            $this->storyRow($storyId); $seen = $this->seen($storyId, $key); $state = $seen['importing'] ?? null;
            if (!is_array($state)) throw new HttpError(409, t('This story is not being imported.'));
            if (!is_array($messages) || !array_is_list($messages)) throw new HttpError(422, t('Invalid messages.'));
            $map = $state['map']; $current = (int)$state['current']; $added = 0;
            $add = $this->vault->db->prepare('INSERT INTO story_messages(id,story_id,user_id,conversation,turn_id,role,content,created) VALUES(?,?,?,?,?,?,CAST(? AS BLOB),?)');
            foreach ($messages as $n => $m) {
                if (!is_array($m) || !is_string($m['content'] ?? null) || !in_array($m['role'] ?? null, self::ROLES, true)) throw new HttpError(422, t('Message {n} of the conversation: invalid format.', ['n'=>($n + 1)]));
                $content = strtr($m['content'], $map);
                try { $c = json_decode($content, true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new HttpError(422, t('Message {n} of the conversation: not JSON.', ['n'=>($n + 1)])); }
                if (!is_array($c) || !in_array($c['provider'] ?? null, ['claude', 'openai', 'gemini'], true) || !array_key_exists('native', $c) || !is_array($c['view'] ?? null))
                    throw new HttpError(422, t('Message {n} of the conversation: provider, native or view missing.', ['n'=>($n + 1)]));
                $turn = is_string($m['turn'] ?? null) ? ($map[$m['turn']] ?? null) : null;
                $id = self::newId();
                $add->execute([$id, $storyId, $this->owner(), max(1, min($current, is_int($m['conversation'] ?? null) ? $m['conversation'] : $current)), $turn, $m['role'],
                    Crypto::seal($content, $key, "story-message:$id"), is_int($m['created'] ?? null) ? $m['created'] : time()]);
                $added++;
            }
            if ($done) {
                $was = json_decode(strtr(json_encode($state['seen'] ?: new \stdClass(), JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $map), true);
                $this->saveSeen($storyId, ['origin'=>['how'=>'import', 'title'=>$state['title'] !== '' ? $state['title'] : 'untitled']] + (is_array($was) ? $was : []), $key);
                $this->vault->db->prepare('UPDATE stories SET conversation=? WHERE id=?')->execute([$current, $storyId]);
            }
            return ['added'=>$added];
        });
    }

    /** Goes on from a turn that failed or was stopped. */
    public function resume(string $storyId, string $key, mixed $credit = null): array
    {
        if ($this->activeTurn($storyId)) throw new HttpError(409, t('The writer is still working on this story.'));
        $meta = $this->stories->get($storyId, $key);
        $writer = self::settings($meta['writer']);
        $this->requireKey($writer['provider'], $key);
        $row = $this->storyRow($storyId);
        if (!$this->messages($storyId, $key, (int)$row['conversation'], 0, false, 1)) throw new HttpError(409, t('There is nothing to continue: send a message.'));
        return $this->queue($storyId, $writer, null, $key, self::credit($credit), $this->failedIllustrations($storyId, $key));
    }

    /**
     * What the page estimates of the credit for pictures, OpenAI's or Google's for Nano Banana (the balance the person
     * entered, less what the app counted since), and what one illustration costs with the writer's image settings, in
     * US$. Either can be unknown (null).
     */
    private static function credit(mixed $input): array
    {
        $number = fn($v) => is_int($v) || is_float($v) ? (is_finite((float)$v) && abs((float)$v) < 1e6 ? round((float)$v, 4) : null) : null;
        return ['balance'=>is_array($input) ? $number($input['balance'] ?? null) : null, 'image'=>is_array($input) ? $number($input['image'] ?? null) : null];
    }

    /** Who is paid for the story's pictures: [name for the writer, where credit is added]. */
    private static function imagePayer(array $writer): array
    { return Images::provider((string)$writer['image_model']) === 'gemini' ? ['Google Gemini', 'aistudio.google.com'] : ['OpenAI', 'platform.openai.com']; }

    /** The credit, for the agent, or null when nothing is known. */
    private static function creditLine(array $credit, array $writer): ?string
    {
        [$balance, $image] = [$credit['balance'], $credit['image']];
        if ($balance === null) return null;
        $s = self::imagePayer($writer)[0].' credit for illustrations (the app\'s estimate): about US$ '.number_format(max(0, $balance), 2, '.', ',');
        if ($image === null || $image <= 0) return $s.'.';
        $left = (int)floor(max(0, $balance) / $image);
        return $s.'; one illustration with the current settings costs about US$ '.number_format($image, 2, '.', ',').', so '
            .($left < 1 ? 'there is not enough for one: don\'t illustrate, and tell the person if they ask for images.' : 'about '.$left.' more.');
    }

    /** Illustrations of the story whose request ended without an image, by request id. */
    private function failedIllustrations(string $storyId, string $key): array
    {
        $failed = [];
        try {
            foreach ($this->stories->resolveIllustrations($storyId, $key, $this->jobs) as $jobId => $job) {
                if (in_array($job['status'], ['queued', 'running', 'cancelling'], true)) continue;
                $failed[$jobId] = ['label'=>(string)($job['label'] ?? ''), 'credit'=>in_array($job['error_kind'] ?? null, Images::CREDIT_KINDS, true), 'gemini'=>($job['error_kind'] ?? null) === 'gemini-credit',
                    'error'=>(string)($job['error'] ?? ''), 'for'=>$job['for'] ?? 'chapter'];
            }
        } catch (\Throwable $e) { logFailure($e); }
        return $failed;
    }

    public function cancel(string $turnId, string $key): array
    {
        Vault::validId($turnId); $r = $this->turnRow($turnId); if (!$r) throw new HttpError(404, t('Request not found.'));
        $this->vault->db->prepare("UPDATE story_turns SET status='cancelled', updated=? WHERE id=? AND status='queued'")->execute([time(), $turnId]);
        $this->vault->db->prepare("UPDATE story_turns SET status='cancelling' WHERE id=? AND status='running'")->execute([$turnId]);
        return $this->turn($turnId, $key);
    }

    // =================================================================================================================
    // Keeping Claude's cache warm
    // =================================================================================================================

    /** The story's last turn and its data, when Claude's cache of the current conversation can be kept warm. */
    private function warmable(string $storyId, string $key): ?array
    {
        $row = $this->storyRow($storyId);
        $q = $this->vault->db->prepare('SELECT * FROM story_turns WHERE story_id=? AND user_id=? ORDER BY created DESC, rowid DESC LIMIT 1');
        $q->execute([$storyId, $this->owner()]); $r = $q->fetch(); $q->closeCursor();
        if (!$r || in_array($r['status'], ['queued', 'running', 'cancelling'], true) || (int)$r['conversation'] !== (int)$row['conversation']) return null;
        $data = $this->turnData($r, $key);
        if (($data['writer']['provider'] ?? '') !== 'claude' || !isset($data['sent_at'])) return null;
        // A writer changed since then has no cache to keep: the cache is per model.
        $now = self::settings($this->stories->get($storyId, $key)['writer']);
        if ($now['provider'] !== 'claude' || $now['model'] !== ($data['writer']['model'] ?? '')) return null;
        $sent = (int)$data['sent_at'];
        if (time() - $sent > self::WARM_LATEST || $sent + self::WARM_AT - (int)($data['last_real'] ?? $sent) > self::WARM_FOR) return null;
        return [$r, $data];
    }

    /** Seconds until the page should ask for a keep-alive, or null when there is no cache to keep. */
    public function warmIn(string $storyId, string $key): ?int
    {
        $w = $this->warmable($storyId, $key);
        return $w === null ? null : max(0, (int)$w[1]['sent_at'] + self::WARM_AT - time());
    }

    /**
     * Re-sends the conversation with max_tokens 0: Claude reads it from the cache (which restarts its hour) and answers
     * nothing. What it costs is added to the last turn. Returns when the next one is due.
     */
    public function warm(string $storyId, string $key): array
    {
        $claimed = $this->transaction(function () use ($storyId, $key) {
            $w = $this->warmable($storyId, $key);
            if ($w === null || (int)$w[1]['sent_at'] + self::WARM_AT - time() > 90) return null;
            [$r, $data] = $w; $data['sent_at'] = time(); $this->saveTurnData($r['id'], $data, $key);
            return $w;
        });
        if ($claimed === null) return ['warmed'=>false, 'warm_in'=>$this->warmIn($storyId, $key)];
        [$r, $data] = $claimed; $writer = self::settings($data['writer']);
        $history = $this->history($storyId, (int)$r['conversation'], 'claude', $writer['model'], $key);
        // A request can't end with the writer's own message: a short note from the person goes after it, unanswered.
        // The writer's last answer is kept in the cache up to its end, so the person's next message reads it from there
        // instead of writing it; the note isn't kept (no breakpoint after it), since no later request has it.
        $noted = !$history || end($history)->role === 'assistant';
        if ($noted && $history) $history[count($history) - 1] = self::markEnd($history[count($history) - 1]);
        if ($noted) $history[] = (object)['role'=>'user', 'content'=>[(object)['type'=>'text', 'text'=>'(Keeping the conversation ready. No reply needed.)']]];
        $body = $this->claudeBody($writer, $history, $data['top_effort'] ?? null, $this->stories->kind($storyId, $key) === 'vn');
        if ($noted) unset($body['cache_control']);
        $client = new Claude($this->vault->claudeKey($key), 120, $this->claudeTransport);
        $usage = $client->warm($body);
        $cost = Claude::cost($writer['model'], $usage);
        $this->transaction(function () use ($r, $cost, $usage, $key) {
            $data = $this->turnData($this->turnRow($r['id']), $key);
            $data['costs']['claude'] = (float)($data['costs']['claude'] ?? 0) + $cost; $data['cost'] = (float)($data['cost'] ?? 0) + $cost;
            $data['warms'] = (int)($data['warms'] ?? 0) + 1; $data['warm_cost'] = (float)($data['warm_cost'] ?? 0) + $cost;
            $data['usage'][] = ['model'=>'keep-alive', 'usage'=>$usage];
            if (count($data['usage']) > 60) $data['usage'] = array_slice($data['usage'], -60);
            $this->saveTurnData($r['id'], $data, $key);
        });
        $this->paid((string)$r['id'], 'claude', $writer['model'], $usage, 'warm', null, $cost, $key);
        return ['warmed'=>true, 'warm_in'=>$this->warmIn($storyId, $key), 'cost'=>$cost];
    }

    // =================================================================================================================
    // Running a turn
    // =================================================================================================================

    /**
     * Runs a queued turn in a separate command line process (bin/worker.php), which the web server doesn't stop after a
     * couple of minutes as it may stop its own. Returns the turn once that process has claimed it, or null when none
     * could be started here or it didn't claim the turn in time: then the caller runs it itself.
     */
    public function runSeparately(string $turnId, string $key, ?string $script = null): ?array
    {
        Vault::validId($turnId); $r = $this->turnRow($turnId); if (!$r) throw new HttpError(404, t('Request not found.'));
        if ($r['status'] !== 'queued') return $this->publicTurn($r, $key);
        // After a start that didn't work, this server is left alone for an hour.
        $dir = $this->vault->dir;
        if ($script === null && Spawn::failedRecently($dir)) return null;
        $said = Spawn::run($dir, $script ?? Spawn::worker(), ['story', $turnId, $this->owner(), $dir], sodium_bin2hex($key));
        if ($said === 'claimed' || $said === 'idle') {
            Spawn::outcome($dir, null);
            return $this->publicTurn($this->vault->fresh('SELECT * FROM story_turns WHERE id=?', [$turnId]) ?? $r, $key);
        }
        if ($said === null || str_starts_with($said, 'failed')) Spawn::outcome($dir, $said === null ? t('did not answer within 6 s') : substr($said, 8));
        return null;
    }

    /**
     * Runs a queued turn in this process. $onClaimed is called once the turn is claimed, before any paid call: the web
     * route answers the page there and goes on in the background.
     */
    public function run(string $turnId, string $key, ?\Closure $onClaimed = null): array
    {
        Vault::validId($turnId); $r = $this->turnRow($turnId); if (!$r) throw new HttpError(404, t('Request not found.'));
        $lock = $this->jobs->lockWorker($turnId);
        if ($lock === false) return $this->publicTurn($r, $key);
        $ended = false; $next = null;
        // If PHP itself stops this process (out of memory, a time limit), the turn says why instead of going silent.
        $guard = new \stdClass(); $guard->on = true; $guard->reserve = str_repeat(' ', 262144);
        register_shutdown_function(function () use ($guard, $turnId, $key): void {
            $guard->reserve = null;
            if ($guard->on) $this->died($turnId, error_get_last(), $key);
        });
        try {
            $q = $this->vault->db->prepare("UPDATE story_turns SET status='running', phase='starting', started=?, updated=?, worker_lock=? WHERE id=? AND status='queued'");
            $q->execute([time(), time(), $lock === null ? 0 : 1, $turnId]);
            if ($q->rowCount() !== 1) { $guard->on = false; return $this->publicTurn($this->turnRow($turnId), $key); }
            if ($onClaimed !== null) { try { $onClaimed($this->publicTurn($this->turnRow($turnId), $key)); } catch (\Throwable $e) { logFailure($e); } }
            $next = $this->work($turnId, $key);
            $ended = true;
        } finally { $guard->on = false; $guard->reserve = null; $this->jobs->unlockWorker($lock, $turnId, $ended); }
        $turn = $this->publicTurn($this->turnRow($turnId), $key);
        // A message that came in as the turn ended is answered at once, by a new turn in this same process.
        if ($next !== null) { try { $this->run($next, $key, $onClaimed === null ? null : static function (): void {}); } catch (\Throwable $e) { if (!$e instanceof HttpError) logFailure($e); } }
        // Illustrations asked for in this turn that no page started are made here, one after another.
        if ($turn['jobs'] && $onClaimed !== null) $this->runJobs($turn['jobs'], $key);
        return $turn;
    }

    /**
     * The process running a turn is ending before the turn did (PHP ran out of memory or time, or was told to stop):
     * the turn fails with the reason, and the conversation is left valid for the next one.
     */
    private function died(string $turnId, ?array $error, string $key): void
    {
        try {
            $r = $this->turnRow($turnId);
            if (!$r || !in_array($r['status'], ['running', 'cancelling'], true)) return;
            $fatal = $error !== null && in_array($error['type'] ?? 0, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true) ? (string)($error['message'] ?? '') : null;
            $ran = time() - (int)($r['started'] ?? time()); $worked = self::duration($ran);
            // LiteSpeed says how long it lets each PHP process of the page live (Hostinger: 120 s).
            $limit = Spawn::webLimit();
            $why = match (true) {
                $fatal !== null && str_contains($fatal, 'Allowed memory size') => t('The server ran out of memory for the writer after {time} of work (PHP\'s limit on the host is {limit}). A long conversation or one with many attached images weighs more: start a new conversation or raise PHP\'s memory limit in the host\'s panel.', ['time'=>$worked, 'limit'=>ini_get('memory_limit')]),
                $fatal !== null && str_contains($fatal, 'Maximum execution time') => t('The host ended the writer at PHP\'s time limit ({limit} s), after {time} of work. Raise max_execution_time in the host\'s panel.', ['limit'=>ini_get('max_execution_time'), 'time'=>$worked]),
                $fatal !== null => t('An internal server error (PHP) stopped the writer after {time} of work. The host\'s error log has the details.', ['time'=>$worked]),
                $limit !== null && abs($ran - $limit) <= 15 => t('The web server of this host ends every PHP process of the page at {time} (LSAPI_MAX_PROCESS_TIME), and the writer was running in it because the separate process could not be used. Settings › Hosting test shows why.', ['time'=>self::duration($limit)]),
                default => t('The server ended the writer\'s process after {time} of work, with no PHP error (the host told the process to stop). Settings › Hosting test shows this server\'s limit.', ['time'=>$worked]),
            };
            $data = $this->turnData($r, $key); $data['error'] = t('{reason} What it already saved stays in the story.', ['reason'=>$why]); $data['error_kind'] = 'server';
            $this->saveTurnData($turnId, $data, $key);
            $this->vault->db->prepare("UPDATE story_turns SET status=?, phase=NULL, live=NULL, updated=? WHERE id=? AND status IN ('running','cancelling')")
                ->execute([$r['status'] === 'cancelling' ? 'cancelled' : 'failed', time(), $turnId]);
            $this->closeOpenCalls((string)$r['story_id'], (int)$r['conversation'], $turnId, (string)(($data['writer']['provider'] ?? 'claude')), $key);
        } catch (\Throwable) { /* nothing more can be done in a dying process */ }
    }

    /** Seconds as the page says them: 40 s, 3 min, 1 h 05 min. */
    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) return $seconds.' s';
        if ($seconds < 3600) return intdiv($seconds, 60).' min';
        return intdiv($seconds, 3600).' h '.str_pad((string)intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT).' min';
    }

    /** Runs the requests still waiting for a process (one the page started is left to it). */
    private function runJobs(array $ids, string $key): void
    {
        foreach ($ids as $id) {
            try { $this->jobs->run((string)$id, $key); } catch (\Throwable $e) { if (!$e instanceof HttpError) logFailure($e); }
        }
    }

    /** Runs the turn. Returns the id of a turn queued to answer a message that came in as it ended, if any. */
    private function work(string $turnId, string $key): ?string
    {
        $r = $this->turnRow($turnId); $storyId = (string)$r['story_id']; $conversation = (int)$r['conversation'];
        $data = $this->turnData($r, $key); $writer = self::settings($data['writer'] ?? null);
        $provider = $writer['provider']; $status = 'completed';
        $live = ['text'=>'', 'updates'=>'', 'tool'=>null, 'done'=>[], 'step'=>0, 'words'=>0, 'retry'=>null, 'fallback'=>null]; $lastWrite = 0.0; $lastBeat = 0;
        $saveLive = function (bool $force = false) use ($turnId, &$live, &$lastWrite, $key): void {
            $now = microtime(true); if (!$force && $now - $lastWrite < 1.0) return; $lastWrite = $now;
            try {
                $this->vault->db->prepare('UPDATE story_turns SET live=CAST(? AS BLOB), updated=? WHERE id=?')
                    ->execute([Crypto::seal(json_encode($live, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE), $key, "story-live:$turnId"), time(), $turnId]);
            } catch (\PDOException) { /* a busy database must never stop a paid request */ }
        };
        $tick = function () use ($turnId, &$lastBeat): bool {
            if (time() - $lastBeat < 3) return false; $lastBeat = time();
            try {
                $this->vault->db->prepare("UPDATE story_turns SET updated=?, status=CASE status WHEN 'interrupted' THEN 'running' ELSE status END WHERE id=?")->execute([time(), $turnId]);
                $q = $this->vault->db->prepare('SELECT status FROM story_turns WHERE id=?'); $q->execute([$turnId]);
                return $q->fetchColumn() === 'cancelling';
            } catch (\PDOException) { return false; }
        };
        $onDelta = function (string $kind, string $chunk) use (&$live, $saveLive): void {
            if ($kind === 'retry') {
                // What streamed before the failure is dropped: the next attempt streams it again.
                $live['retry'] = $chunk; $live['text'] = ''; $live['tool'] = null; $live['words'] = 0; $saveLive(true); return;
            }
            if ($kind === 'fallback') { $live['fallback'] = Catalog::writer('claude', $chunk)['label'] ?? $chunk; $saveLive(true); return; }
            $live['retry'] = null;
            if ($kind === 'text') $live['text'] .= $chunk;
            elseif ($kind === 'update') $live['updates'] = mb_substr($live['updates'].$chunk, -4000);
            elseif ($kind === 'tool') { $live['tool'] = $chunk; $live['words'] = 0; }
            // The words of a chapter being written, counted by its spaces as its text streams in.
            elseif ($kind === 'input') $live['words'] = ($live['words'] ?? 0) + substr_count($chunk, ' ');
            if (mb_strlen($live['text']) > 40000) $live['text'] = mb_substr($live['text'], -40000);
            $saveLive();
        };
        $timeout = max(900, (int)($this->config['api_timeout'] ?? 600));
        $client = match ($provider) {
            'claude'=>new Claude($this->vault->claudeKey($key), $timeout, $this->claudeTransport),
            'gemini'=>new Gemini($this->vault->geminiKey($key), $timeout, $this->geminiTransport),
            default=>new Gpt($this->vault->apiKey($key), $timeout, $this->gptTransport),
        };
        // Gemini refused another writer's tool calls in this conversation once: they go to it as text from then on.
        $flat = $provider === 'gemini' && $this->flatConversation($storyId, $conversation, $key);
        $context = new ToolContext($storyId, $turnId, $writer, $key);
        $vn = $this->isVnContext($context);
        if (is_array($data['credit'] ?? null)) $context->credit = self::credit($data['credit']);
        $context->tick = $tick;
        $wrapped = false;
        try {
            for ($step = 0; ; $step++) {
                if ($step >= self::MAX_STEPS) { $data['note'] = t('The writer stopped after {max} steps in this request. Ask it to continue.', ['max'=>self::MAX_STEPS]); break; }
                // Near Claude's window, the turn stops while the next request still fits; the next message opens a new
                // conversation with the whole book.
                if ($provider === 'claude' && $step > 0 && (int)($data['context'] ?? 0) >= self::STOP_AT) {
                    $data['note'] = t('The writer stopped because the conversation got close to the model\'s limit. Tell it to go on: the next message opens a new conversation, with the whole book.');
                    break;
                }
                if ($tick()) throw new Cancelled();
                // What the person wrote meanwhile goes in before the next request.
                $this->deliverInbox($storyId, $conversation, $turnId, $provider, $key);
                $this->phase($turnId, 'thinking');
                $history = $this->history($storyId, $conversation, $provider, $writer['model'], $key, $flat);
                $live = ['text'=>'', 'updates'=>'', 'tool'=>null, 'step'=>$step + 1, 'words'=>0, 'retry'=>null, 'fallback'=>null] + $live; $saveLive(true);
                if ($provider === 'claude') {
                    // When the cache was last read: a page left open keeps it warm from there (warm()).
                    $data['sent_at'] = $data['last_real'] = time();
                    $answer = $client->send($this->claudeBody($writer, $history, $data['top_effort'] ?? null, $vn), $onDelta, $tick);
                    $this->charge($turnId, $data, 'claude', $answer['model'], $answer['usage'], Claude::cost($writer['model'], $answer['usage']),
                        (int)($answer['usage']['input_tokens'] ?? 0) + (int)($answer['usage']['cache_read_input_tokens'] ?? 0) + (int)($answer['usage']['cache_creation_input_tokens'] ?? 0), $key);
                    $done = $this->afterClaude($answer, $storyId, $conversation, $turnId, $context, $data, $live, $saveLive, $key);
                } elseif ($provider === 'gemini') {
                    try { $answer = $client->send($this->geminiBody($writer, $history, $vn), $onDelta, $tick); }
                    catch (HttpError $e) {
                        if ($flat || !str_starts_with($e->getMessage(), '[GEMINI-SIGNATURE]')) throw $e;
                        $flat = true; $data['gemini_flat'] = true; $this->saveTurnData($turnId, $data, $key);
                        error_log('Atelier: Gemini refused the translated tool calls; sending them as text');
                        $answer = $client->send($this->geminiBody($writer, $this->history($storyId, $conversation, $provider, $writer['model'], $key, true), $vn), $onDelta, $tick);
                    }
                    $t = Gemini::tokens($answer['usage']);
                    $this->charge($turnId, $data, 'gemini', $writer['model'], $answer['usage'], Gemini::cost($writer['model'], $answer['usage']), $t['input'] + $t['read'], $key);
                    $done = $this->afterGemini($answer, $storyId, $conversation, $turnId, $context, $data, $live, $saveLive, $key);
                } else {
                    $answer = $client->send($this->gptBody($writer, $history, $storyId, $vn), $onDelta, $tick);
                    $this->charge($turnId, $data, 'openai', $writer['model'], $answer['usage'], Gpt::cost($writer['model'], $answer['usage']), (int)($answer['usage']['input_tokens'] ?? 0), $key);
                    $done = $this->afterGpt($answer, $storyId, $conversation, $turnId, $context, $data, $live, $saveLive, $key);
                }
                $data['steps'] = $step + 1; $data['jobs'] = $context->jobs; $this->saveTurnData($turnId, $data, $key);
                // A message that came in while it answered: the writer answers that too, in this turn.
                if ($done && !$this->inboxWaiting($storyId)) break;
                // Several chapters in one turn: once, when the conversation nears Claude's window, the writer hears it
                // should finish the chapter it is on, after the tool results, as a message of the app.
                if (!$done && !$wrapped && $provider === 'claude' && (int)($data['context'] ?? 0) >= self::WRAP_UP_AT) {
                    $wrapped = true;
                    $this->addMessage($storyId, $conversation, $turnId, 'context', $provider, null, self::userNative($provider, '(From the app, not the person.) This conversation is now at about '
                        .self::thousands((int)$data['context']).' tokens, of the '.self::thousands(self::WINDOW).' the model can take. Finish the chapter you are on (its revision, summary, bible and plan) and then stop, '
                        .'telling the person you will go on at their next message: it starts a new conversation that opens with the whole book, the bible and the plan.'), ['hidden'=>true], $key);
                }
            }
        } catch (Cancelled) {
            $status = 'cancelled';
        } catch (\Throwable $e) {
            $status = 'failed';
            $data['error'] = $e instanceof HttpError ? $e->getMessage() : t('Unexpected failure on the server while the writer was working. What was already saved stays in the story.');
            if ($e instanceof HttpError && $e->kind !== null && $e->kind !== 'retry') $data['error_kind'] = $e->kind;
            if (!$e instanceof HttpError) logFailure($e);
        }
        // Tool calls left without an answer (the turn stopped between the call and its result) are answered, so the
        // conversation stays valid for the next turn.
        try { $this->closeOpenCalls($storyId, $conversation, $turnId, $provider, $key); } catch (\Throwable $e) { logFailure($e); }
        try { $this->know($storyId, $key); } catch (\Throwable $e) { logFailure($e); }
        $data['jobs'] = $context->jobs;
        $this->saveTurnData($turnId, $data, $key);
        return $this->transaction(function () use ($status, $turnId, $storyId, $conversation, $provider, $writer, $context, $key): ?string {
            $this->vault->db->prepare('UPDATE story_turns SET status=?, phase=NULL, live=NULL, updated=? WHERE id=?')->execute([$status, time(), $turnId]);
            // A message sent as the turn ended goes into the conversation; after a finished turn, a new turn answers it
            // (run() starts it). After a stop or a failure it waits there for the person's next message.
            if ($this->deliverInbox($storyId, $conversation, $turnId, $provider, $key) && $status === 'completed')
                return (string)$this->queue($storyId, $writer, null, $key, $context->credit, [])['id'];
            return null;
        });
    }

    private function phase(string $turnId, string $phase): void
    { $this->vault->db->prepare('UPDATE story_turns SET phase=?, updated=? WHERE id=?')->execute([$phase, time(), $turnId]); }

    /**
     * What a step of the writer cost: in the turn (the cost of the story) and with what the account paid since its
     * balance was entered (Vault::balance: the balance of the writer's service at the top of the page).
     */
    private function charge(string $turnId, array &$data, string $provider, string $model, array $usage, float $cost, int $context, string $key): void
    {
        $data['costs'][$provider] = (float)($data['costs'][$provider] ?? 0) + $cost;
        $data['cost'] = (float)($data['cost'] ?? 0) + $cost;
        $data['context'] = $context;
        $data['usage'][] = ['model'=>$model, 'usage'=>$usage, 'at'=>time()];
        if (count($data['usage']) > 60) $data['usage'] = array_slice($data['usage'], -60);
        $this->saveTurnData($turnId, $data, $key);
        $this->paid($turnId, $provider, $model, $usage, 'writing', $data['writer']['effort'] ?? null, $cost, $key);
    }

    /** A call of the writer in what the account paid. The model says who is paid (Vault::payer): a provider's own name goes first. */
    private function paid(string $turnId, string $provider, string $model, array $usage, string $kind, mixed $effort, float $cost, string $key): void
    {
        if (Vault::payer($model) !== $provider) $model = $provider === 'openai' ? 'gpt' : $provider;
        try { $this->vault->recordSpend($turnId, [['model'=>$model, 'usage'=>$usage, 'kind'=>$kind, 'effort'=>is_string($effort) ? $effort : null, 'cost'=>$cost]], $key); }
        catch (\Throwable $e) { logFailure($e); }
    }

    /** Whether a turn of this conversation had to send Gemini the other writers' tool calls as text. */
    private function flatConversation(string $storyId, int $conversation, string $key): bool
    {
        $q = $this->vault->db->prepare('SELECT * FROM story_turns WHERE story_id=? AND user_id=? AND conversation=?'); $q->execute([$storyId, $this->owner(), $conversation]);
        foreach ($q->fetchAll() as $r) if (!empty($this->turnData($r, $key)['gemini_flat'])) return true;
        return false;
    }

    /**
     * Gemini's request: the fixed instructions and tools first and the conversation after them, as it was, so Google's
     * implicit cache reads the part that repeats (nothing to mark or pay to keep). The thinking level goes in the
     * configuration, so changing it doesn't change the conversation.
     */
    private function geminiBody(array $writer, array $history, bool $vn = false): array
    {
        return ['model'=>$writer['model'], 'store'=>false, 'system_instruction'=>self::system($vn), 'tools'=>self::geminiTools($vn), 'input'=>$history, 'safety_settings'=>Gemini::safety(),
            'generation_config'=>['thinking_level'=>Gemini::thinking($writer['effort'], $writer['model']), 'thinking_summaries'=>'auto',
                'max_output_tokens'=>Catalog::writer('gemini', $writer['model'])['max_tokens'] ?? self::MAX_OUTPUT]];
    }

    private function claudeBody(array $writer, array $history, ?string $topEffort = null, bool $vn = false): array
    {
        return ['model'=>$writer['model'], 'max_tokens'=>Catalog::writer('claude', $writer['model'])['max_tokens'] ?? self::MAX_OUTPUT,
            'system'=>[['type'=>'text', 'text'=>self::system($vn), 'cache_control'=>self::CACHE]], 'tools'=>self::claudeTools($vn), 'messages'=>self::markPreviousEnd($history),
            'thinking'=>['type'=>'adaptive', 'display'=>'updates', 'block_binding'=>['prefix_mismatch_behavior'=>'drop_block']],
            'output_config'=>['effort'=>$topEffort ?? $writer['effort']], 'fallbacks'=>'default', 'cache_control'=>self::CACHE];
    }

    /**
     * A third breakpoint, where the previous request ended: the message of the person (or the tool results) just
     * before Claude's last answer. The automatic breakpoint at the end looks back only 20 blocks for what an earlier
     * request left in the cache, and one answer can hold more than that (a progress note before each of many tool
     * calls, an illustration per chapter): then the whole conversation would be written to the cache again, at twice
     * the input price. This one reads it from exactly where it was left. The marked block is a copy: the stored
     * conversation doesn't change.
     */
    private static function markPreviousEnd(array $history): array
    {
        for ($i = count($history) - 1; $i > 0; $i--) {
            if (($history[$i]->role ?? '') !== 'assistant') continue;
            // The message before the answer (an app's message, such as a new reasoning level, can sit between them).
            for ($j = $i - 1; $j >= 0 && ($history[$j]->role ?? '') === 'system'; $j--);
            if ($j >= 0 && ($history[$j]->role ?? '') === 'user') $history[$j] = self::markEnd($history[$j]);
            return $history;
        }
        return $history;
    }

    /**
     * A copy of a message with a breakpoint on its last block, or the message as it was when that block can't take one
     * (a thinking block). On an answer, the breakpoint has to be after its reasoning to keep it.
     */
    private static function markEnd(object $message): object
    {
        if (!is_array($message->content ?? null) || !$message->content) return $message;
        $content = $message->content; $last = count($content) - 1;
        if (!is_object($content[$last]) || !in_array($content[$last]->type ?? '', ['text', 'image', 'tool_result', 'tool_use'], true)) return $message;
        $content[$last] = clone $content[$last]; $content[$last]->cache_control = (object)self::CACHE;
        $copy = clone $message; $copy->content = $content;
        return $copy;
    }

    private function gptBody(array $writer, array $history, string $storyId, bool $vn = false): array
    {
        return ['model'=>$writer['model'], 'store'=>false, 'include'=>['reasoning.encrypted_content'],
            'reasoning'=>['effort'=>$writer['effort']], 'max_output_tokens'=>self::MAX_OUTPUT,
            'input'=>array_merge([['type'=>'message', 'role'=>'developer', 'content'=>self::system($vn)]], $history),
            'tools'=>self::gptTools($vn), 'prompt_cache_key'=>'story-'.substr(hash('sha256', $storyId), 0, 24), 'prompt_cache_retention'=>'24h'];
    }

    /**
     * The conversation as the provider gets it, in order and unchanged. One conversation can pass between Claude and
     * GPT: the other provider's messages are translated (what was said, the tool calls and their results), and its
     * hidden reasoning stays behind, since only the model that made it can read it. The translation depends only on
     * the stored messages, so each request starts with the same bytes as the one before and the cache keeps working.
     * Claude's consecutive messages of one role are joined; GPT reasoning from another GPT model is left out.
     */
    private function history(string $storyId, int $conversation, string $provider, string $model, string $key, bool $flat = false): array
    {
        $out = []; $names = [];
        foreach ($this->messages($storyId, $key, $conversation, 0, true, 100000) as $m) {
            if ($m['native'] === null) continue;
            if ($provider === 'gemini') {
                if ($m['provider'] === 'gemini') {
                    // Gemini's own steps as they came; thoughts of another Gemini model stay behind (only it reads them).
                    foreach ($m['native'] as $step) if (!(($step->type ?? '') === 'thought' && $m['role'] === 'assistant' && $m['model'] !== $model)) $out[] = $step;
                } else foreach ($m['provider'] === 'claude' ? [$m['native']] : self::gptToClaude($m['native']) as $msg) array_push($out, ...self::claudeToGemini($msg, $names, $flat));
                continue;
            }
            if ($provider === 'claude') {
                foreach (match ($m['provider']) { 'claude'=>[$m['native']], 'gemini'=>self::geminiToClaude($m['native']), default=>self::gptToClaude($m['native']) } as $msg) {
                    $last = count($out) - 1;
                    if ($last >= 0 && $out[$last]->role === $msg->role) $out[$last]->content = array_merge($out[$last]->content, $msg->content);
                    else $out[] = $msg;
                }
            } elseif ($m['provider'] === 'openai') {
                foreach ($m['native'] as $item) {
                    if (($item->type ?? '') === 'reasoning' && $m['role'] === 'assistant' && $m['model'] !== $model) continue;
                    $out[] = $item;
                }
            } elseif ($m['provider'] === 'gemini') {
                foreach (self::geminiToClaude($m['native']) as $msg) array_push($out, ...self::claudeToGpt($msg));
            } else {
                array_push($out, ...self::claudeToGpt($m['native']));
            }
        }
        return match ($provider) { 'claude'=>self::repairClaude($out), 'gemini'=>self::repairGemini($out), default=>self::repairGpt($out) };
    }

    /** A Claude message as GPT input items. Thinking is left out; an image in a tool result follows in a message. */
    public static function claudeToGpt(object $msg): array
    {
        // A message of the app to Claude (a new reasoning level) has nothing for GPT.
        if ($msg->role === 'system') return [];
        // A message of the person with images they attached: the same parts, in the same order.
        if ($msg->role === 'user' && !array_filter($msg->content, fn($b) => ($b->type ?? '') === 'tool_result') && array_filter($msg->content, fn($b) => ($b->type ?? '') === 'image')) {
            $parts = [];
            foreach ($msg->content as $b) {
                if (($b->type ?? '') === 'text' && trim((string)($b->text ?? '')) !== '') $parts[] = (object)['type'=>'input_text', 'text'=>(string)$b->text];
                elseif (($b->type ?? '') === 'image' && ($b->source->type ?? '') === 'base64') $parts[] = (object)['type'=>'input_image', 'image_url'=>'data:'.($b->source->media_type ?? 'image/jpeg').';base64,'.($b->source->data ?? '')];
            }
            return [(object)['type'=>'message', 'role'=>'user', 'content'=>$parts]];
        }
        $items = []; $texts = []; $images = [];
        $flush = function () use (&$texts, &$items): void {
            if ($texts) $items[] = (object)['type'=>'message', 'role'=>'assistant', 'content'=>[(object)['type'=>'output_text', 'text'=>implode("\n\n", $texts)]]];
            $texts = [];
        };
        $image = fn(object $b) => (object)['type'=>'input_image', 'image_url'=>'data:'.($b->source->media_type ?? 'image/jpeg').';base64,'.($b->source->data ?? '')];
        foreach ($msg->content as $b) {
            $type = $b->type ?? '';
            if ($type === 'text' && trim((string)($b->text ?? '')) !== '') $texts[] = (string)$b->text;
            elseif ($type === 'image' && ($b->source->type ?? '') === 'base64') $images[] = $image($b);
            elseif ($type === 'tool_use') {
                $flush();
                $items[] = (object)['type'=>'function_call', 'call_id'=>(string)$b->id, 'name'=>(string)$b->name,
                    'arguments'=>json_encode($b->input ?? new \stdClass(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)];
            } elseif ($type === 'tool_result') {
                $parts = is_array($b->content ?? null) ? $b->content : [(object)['type'=>'text', 'text'=>(string)($b->content ?? '')]];
                $out = []; $seen = false;
                foreach ($parts as $part) {
                    if (($part->type ?? '') === 'text') $out[] = (string)$part->text;
                    elseif (($part->type ?? '') === 'image' && ($part->source->type ?? '') === 'base64') { $images[] = $image($part); $seen = true; }
                }
                $items[] = (object)['type'=>'function_call_output', 'call_id'=>(string)$b->tool_use_id,
                    'output'=>(!empty($b->is_error) ? 'Error: ' : '').implode("\n", $out).($seen ? ' The image follows in the next message.' : '')];
            }
        }
        if ($msg->role === 'assistant') { $flush(); return $items; }
        if ($images) $items[] = (object)['type'=>'message', 'role'=>'user', 'content'=>array_merge([(object)['type'=>'input_text', 'text'=>'The image(s) you asked to see:']], $images)];
        if ($texts) $items[] = (object)['type'=>'message', 'role'=>'user', 'content'=>array_map(fn($t) => (object)['type'=>'input_text', 'text'=>$t], $texts)];
        return $items;
    }

    /** GPT items as Claude messages (one per role change). Reasoning is left out. */
    public static function gptToClaude(array $items): array
    {
        $out = [];
        $add = function (string $role, object $block) use (&$out): void {
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]->role === $role) $out[$last]->content[] = $block; else $out[] = (object)['role'=>$role, 'content'=>[$block]];
        };
        // Claude's tool ids allow letters, digits, _ and -.
        $id = fn($callId) => preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$callId) ?: 'call';
        foreach ($items as $item) {
            $type = $item->type ?? '';
            if ($type === 'message') {
                $role = ($item->role ?? '') === 'assistant' ? 'assistant' : 'user';
                $content = is_string($item->content ?? null) ? [(object)['type'=>'input_text', 'text'=>$item->content]] : ($item->content ?? []);
                foreach ($content as $c) {
                    $t = (string)($c->text ?? $c->refusal ?? '');
                    if (in_array($c->type ?? '', ['input_text', 'output_text', 'refusal'], true) && trim($t) !== '') $add($role, (object)['type'=>'text', 'text'=>$t]);
                    elseif (($c->type ?? '') === 'input_image' && preg_match('#\Adata:(image/[a-z+.-]+);base64,(.+)\z#s', (string)($c->image_url ?? ''), $m))
                        $add($role, (object)['type'=>'image', 'source'=>(object)['type'=>'base64', 'media_type'=>$m[1], 'data'=>$m[2]]]);
                }
            } elseif ($type === 'function_call') {
                $input = json_decode((string)($item->arguments ?? ''), false);
                $add('assistant', (object)['type'=>'tool_use', 'id'=>$id($item->call_id ?? ''), 'name'=>(string)($item->name ?? ''), 'input'=>$input instanceof \stdClass ? $input : new \stdClass()]);
            } elseif ($type === 'function_call_output') {
                $add('user', (object)['type'=>'tool_result', 'tool_use_id'=>$id($item->call_id ?? ''), 'content'=>(string)($item->output ?? '')]);
            }
        }
        return $out;
    }

    /** Gemini steps as Claude messages (one per role change). Thoughts are left out: only the model that made them reads them. */
    public static function geminiToClaude(array $steps): array
    {
        $out = [];
        $add = function (string $role, object $block) use (&$out): void {
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]->role === $role) $out[$last]->content[] = $block; else $out[] = (object)['role'=>$role, 'content'=>[$block]];
        };
        $id = fn($callId) => preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$callId) ?: 'call';
        $blocks = static function (mixed $content): array {
            if (is_string($content)) return trim($content) !== '' ? [(object)['type'=>'text', 'text'=>$content]] : [];
            $list = [];
            foreach (is_array($content) ? $content : [] as $c) {
                if (!is_object($c)) continue;
                if (($c->type ?? '') === 'text' && trim((string)($c->text ?? '')) !== '') $list[] = (object)['type'=>'text', 'text'=>(string)$c->text];
                elseif (($c->type ?? '') === 'image' && is_string($c->data ?? null) && $c->data !== '')
                    $list[] = (object)['type'=>'image', 'source'=>(object)['type'=>'base64', 'media_type'=>(string)($c->mime_type ?? 'image/jpeg'), 'data'=>$c->data]];
            }
            return $list;
        };
        foreach ($steps as $step) {
            if (!is_object($step)) continue;
            $type = $step->type ?? '';
            if ($type === 'user_input') foreach ($blocks($step->content ?? []) as $b) $add('user', $b);
            elseif ($type === 'model_output') { foreach ($blocks($step->content ?? []) as $b) if ($b->type === 'text') $add('assistant', $b); }
            elseif ($type === 'function_call') $add('assistant', (object)['type'=>'tool_use', 'id'=>$id($step->id ?? ''), 'name'=>(string)($step->name ?? ''),
                'input'=>is_object($step->arguments ?? null) ? $step->arguments : new \stdClass()]);
            elseif ($type === 'function_result') {
                $content = $blocks($step->result ?? []);
                $add('user', (object)(['type'=>'tool_result', 'tool_use_id'=>$id($step->call_id ?? ''), 'content'=>$content ?: 'Done.'] + (!empty($step->is_error) ? ['is_error'=>true] : [])));
            }
        }
        return $out;
    }

    /**
     * A Claude message as Gemini steps. Thinking is left out. $names keeps the name of each call, which Gemini's results
     * carry and Claude's don't. $flat sends the calls and their results as text, for a Gemini that refused them.
     */
    public static function claudeToGemini(object $msg, array &$names, bool $flat = false): array
    {
        if ($msg->role === 'system') return [];
        $steps = []; $parts = [];
        $part = static function (object $b): ?array {
            if (($b->type ?? '') === 'text' && trim((string)($b->text ?? '')) !== '') return ['type'=>'text', 'text'=>(string)$b->text];
            if (($b->type ?? '') === 'image' && ($b->source->type ?? '') === 'base64') return ['type'=>'image', 'mime_type'=>(string)($b->source->media_type ?? 'image/jpeg'), 'data'=>(string)($b->source->data ?? '')];
            return null;
        };
        $json = static fn(mixed $v) => (string)json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        if ($msg->role === 'assistant') {
            $flush = function () use (&$parts, &$steps): void { if ($parts) $steps[] = (object)['type'=>'model_output', 'content'=>$parts]; $parts = []; };
            foreach ($msg->content as $b) {
                if (($b->type ?? '') === 'tool_use') {
                    $names[(string)$b->id] = (string)$b->name;
                    $args = is_object($b->input ?? null) ? $b->input : new \stdClass();
                    if ($flat) { $text = $json($args); $parts[] = ['type'=>'text', 'text'=>'[Called '.$b->name.' with '.(mb_strlen($text) > 1500 ? mb_substr($text, 0, 1500).'…' : $text).']']; continue; }
                    $flush();
                    $steps[] = (object)['type'=>'function_call', 'id'=>(string)$b->id, 'name'=>(string)$b->name, 'arguments'=>$args];
                } elseif (($p = $part($b)) !== null && $p['type'] === 'text') $parts[] = $p;
            }
            $flush();
            return $steps;
        }
        foreach ($msg->content as $b) {
            if (($b->type ?? '') === 'tool_result') {
                $content = is_array($b->content ?? null) ? array_values(array_filter(array_map(fn($c) => is_object($c) ? $part($c) : null, $b->content))) : [['type'=>'text', 'text'=>(string)($b->content ?? '')]];
                $name = $names[(string)$b->tool_use_id] ?? 'tool';
                if ($flat) { array_push($parts, ['type'=>'text', 'text'=>'[Result of '.$name.(!empty($b->is_error) ? ' (error)' : '').':]'], ...$content); continue; }
                $steps[] = (object)(['type'=>'function_result', 'call_id'=>(string)$b->tool_use_id, 'name'=>$name, 'result'=>$content ?: [['type'=>'text', 'text'=>'Done.']]]
                    + (!empty($b->is_error) ? ['is_error'=>true] : []));
            } elseif (($p = $part($b)) !== null) $parts[] = $p;
        }
        if ($parts) $steps[] = (object)['type'=>'user_input', 'content'=>$parts];
        return $steps;
    }

    /**
     * Every Gemini call answered (before what the person says next), what the person says in a row as one step, and no
     * answer left without content.
     */
    private static function repairGemini(array $steps): array
    {
        $fixed = []; $open = [];
        $close = function () use (&$fixed, &$open): void {
            foreach ($open as $id => $name) $fixed[] = (object)['type'=>'function_result', 'call_id'=>(string)$id, 'name'=>$name, 'is_error'=>true,
                'result'=>[['type'=>'text', 'text'=>'Not run: the turn stopped before this call ran.']]];
            $open = [];
        };
        foreach ($steps as $step) {
            $type = $step->type ?? '';
            if ($type === 'model_output' && empty($step->content)) continue;
            if ($type === 'function_call') $open[(string)($step->id ?? '')] = (string)($step->name ?? '');
            elseif ($type === 'function_result') unset($open[(string)($step->call_id ?? '')]);
            elseif ($type === 'user_input') {
                $close();
                $last = count($fixed) - 1;
                if ($last >= 0 && ($fixed[$last]->type ?? '') === 'user_input') {
                    $fixed[$last] = (object)['type'=>'user_input', 'content'=>array_merge((array)$fixed[$last]->content, (array)$step->content)];
                    continue;
                }
            }
            $fixed[] = $step;
        }
        $close();
        return $fixed;
    }

    /** Every tool call answered and the last message from the person, whatever happened to earlier turns. */
    private static function repairClaude(array $messages): array
    {
        $fixed = [];
        foreach ($messages as $n => $msg) {
            $fixed[] = $msg;
            if ($msg->role !== 'assistant') continue;
            $calls = []; foreach ($msg->content as $b) if (($b->type ?? '') === 'tool_use') $calls[] = $b->id;
            if (!$calls) continue;
            $next = $messages[$n + 1] ?? null; $answered = [];
            if ($next !== null && $next->role === 'user') foreach ($next->content as $b) if (($b->type ?? '') === 'tool_result') $answered[] = $b->tool_use_id;
            $missing = array_diff($calls, $answered);
            if (!$missing) continue;
            $results = array_map(fn($id) => (object)['type'=>'tool_result', 'tool_use_id'=>$id, 'is_error'=>true, 'content'=>'Not run: the turn stopped before this call ran.'], array_values($missing));
            if ($next !== null && $next->role === 'user') { $next->content = array_merge($results, $next->content); }
            else $fixed[] = (object)['role'=>'user', 'content'=>$results];
        }
        return $fixed;
    }

    private static function repairGpt(array $items): array
    {
        $outputs = []; foreach ($items as $i) if (($i->type ?? '') === 'function_call_output') $outputs[$i->call_id] = true;
        $fixed = [];
        foreach ($items as $i) {
            $fixed[] = $i;
            if (($i->type ?? '') === 'function_call' && !isset($outputs[$i->call_id])) $fixed[] = (object)['type'=>'function_call_output', 'call_id'=>$i->call_id, 'output'=>'Not run: the turn stopped before this call ran.'];
        }
        return $fixed;
    }

    private function closeOpenCalls(string $storyId, int $conversation, string $turnId, string $provider, string $key): void
    {
        $rows = $this->messages($storyId, $key, $conversation, 0, true, 3);
        $last = null; foreach ($rows as $m) if ($m['native'] !== null) $last = $m;
        if ($last === null || $last['role'] !== 'assistant') return;
        $provider = $last['provider'];
        if ($provider === 'gemini') {
            $results = [];
            foreach ($last['native'] as $s) if (($s->type ?? '') === 'function_call') $results[] = ['type'=>'function_result', 'call_id'=>(string)($s->id ?? ''), 'name'=>(string)($s->name ?? ''),
                'is_error'=>true, 'result'=>[['type'=>'text', 'text'=>'Not run: the turn stopped before this call ran.']]];
            if ($results) $this->addMessage($storyId, $conversation, $turnId, 'tools', 'gemini', null, $results, ['hidden'=>true], $key);
        } elseif ($provider === 'claude') {
            $results = [];
            foreach ($last['native']->content as $b) if (($b->type ?? '') === 'tool_use') $results[] = ['type'=>'tool_result', 'tool_use_id'=>$b->id, 'is_error'=>true, 'content'=>'Not run: the turn stopped before this call ran.'];
            if ($results) $this->addMessage($storyId, $conversation, $turnId, 'tools', 'claude', null, ['role'=>'user', 'content'=>$results], ['hidden'=>true], $key);
        } else {
            $results = [];
            foreach ($last['native'] as $i) if (($i->type ?? '') === 'function_call') $results[] = ['type'=>'function_call_output', 'call_id'=>$i->call_id, 'output'=>'Not run: the turn stopped before this call ran.'];
            if ($results) $this->addMessage($storyId, $conversation, $turnId, 'tools', 'openai', null, $results, ['hidden'=>true], $key);
        }
    }

    /** How many answers cut at the output limit a turn goes past by itself. */
    private const MAX_CONTINUES = 3;
    private const CUT = '(From the app, not the person.) Your last answer reached the output limit and was cut off before you finished. Continue from where it stopped, without repeating what is already done. If your reasoning used up the answer, reason less this time and go straight to the work: write a long chapter in parts, the first with write_chapter and the rest with append_to_chapter.';
    private const CUT_CALL = 'Your answer hit the output limit while writing this call, so it was cut off and nothing was run. Write long text in parts: the first part with write_chapter, then append_to_chapter.';

    /**
     * After an answer the output limit cut off with no call to run (its thinking or its reply took it all): the turn
     * goes on by itself, with a note to the writer, up to MAX_CONTINUES times. True when it goes on.
     */
    private function continueCut(string $storyId, int $conversation, string $turnId, string $provider, array &$data, string $key): bool
    {
        $data['continues'] = (int)($data['continues'] ?? 0) + 1;
        if ($data['continues'] > self::MAX_CONTINUES) {
            $data['note'] = t('The writer\'s answer hit the size limit {max} times in this request. Ask it to continue, or lower the reasoning level.', ['max'=>self::MAX_CONTINUES]);
            return false;
        }
        $this->addMessage($storyId, $conversation, $turnId, 'context', $provider, null, self::userNative($provider, self::CUT),
            ['hidden'=>true, 'notice'=>t('The writer\'s answer hit the size limit, and it went on by itself from where it stopped.')], $key);
        return true;
    }

    /**
     * A call the output limit cut off. A chapter being written keeps what arrived, up to its last whole paragraph, and
     * the writer is told where it ends, to go on with append_to_chapter; any other call is not run.
     * @return array{0:bool,1:string,2:?string}
     */
    private function cutCall(string $name, string $raw, ToolContext $context): array
    {
        $none = [false, self::CUT_CALL, null];
        if (!in_array($name, ['write_chapter', 'append_to_chapter'], true)) return $none;
        [$in, $whole] = self::partialInput($raw);
        if (!isset($in['text'])) return $none;
        if (!$whole) $in['text'] = self::wholeParagraphs($in['text']);
        if (trim($in['text']) === '') return $none;
        try { [$ok, $out, $label] = $this->runTool($name, $in, $context); } catch (\Throwable $e) { if (!$e instanceof HttpError) logFailure($e); return $none; }
        if (!$ok) return $none;
        if ($whole) return [true, 'Your answer hit the output limit while writing this call, but its text arrived whole and was saved: '.$out.' If its summary was cut off, set it with edit_chapter.', $label];
        return [true, 'Your answer hit the output limit while writing this call and was cut off. What arrived was kept, up to its last whole paragraph: '.$out
            .' The chapter now ends with: «…'.mb_substr(rtrim($in['text']), -300).'» Continue it from exactly there with append_to_chapter, without repeating that text.', $label];
    }

    /**
     * The fields of a chapter call from its arguments cut off midway: the strings that closed, the numbers, and the text
     * even if it was cut. The second value says whether the text closed.
     * @return array{0:array<string,string|int>,1:bool}
     */
    private static function partialInput(string $raw): array
    {
        $in = []; $whole = false;
        foreach (['title', 'summary', 'text'] as $field) {
            if (!preg_match('/"'.$field.'"\s*:\s*"/', $raw, $m, PREG_OFFSET_CAPTURE)) continue;
            $start = $m[0][1] + strlen($m[0][0]);
            preg_match('/\G(?:[^"\\\\]|\\\\.)*/s', $raw, $s, 0, $start);
            $body = $s[0] ?? ''; $closed = ($raw[$start + strlen($body)] ?? '') === '"';
            if (!$closed && $field !== 'text') continue;
            // An escape cut in half at the end: \u12, or the first half of a surrogate pair.
            if (!$closed) for ($i = 0; $i < 2; $i++) $body = preg_replace('/\\\\u(?:[dD][89abAB][0-9a-fA-F]{2}|[0-9a-fA-F]{0,3})\z/', '', $body) ?? $body;
            $value = json_decode('"'.$body.'"');
            if (!is_string($value)) continue;
            $in[$field] = $value;
            if ($field === 'text') $whole = $closed;
        }
        foreach (['chapter', 'position'] as $field) if (preg_match('/"'.$field.'"\s*:\s*(\d{1,5})\s*[,}]/', $raw, $m)) $in[$field] = (int)$m[1];
        return [$in, $whole];
    }

    /** Text cut off midway, up to its last whole paragraph (or, in one long paragraph, its last whole sentence). */
    private static function wholeParagraphs(string $text): string
    {
        $text = rtrim($text);
        $cut = mb_strrpos($text, "\n\n");
        if ($cut !== false && $cut >= mb_strlen($text) / 2) return rtrim(mb_substr($text, 0, $cut));
        return preg_match('/\A.*[.!?…]["”»\')\]]*(?=\s)/su', $text, $m) ? $m[0] : $text;
    }

    /** Keeps Claude's answer, runs the tools it asked for. True when the turn is over. */
    private function afterClaude(array $answer, string $storyId, int $conversation, string $turnId, ToolContext $context, array &$data, array &$live, \Closure $saveLive, string $key): bool
    {
        $text = ''; $updates = []; $calls = [];
        foreach ($answer['content'] as $i => $b) {
            $type = $b['type'] ?? '';
            if ($type === 'text') $text .= (string)($b['text'] ?? '');
            elseif ($type === 'thinking' && trim((string)($b['thinking'] ?? '')) !== '') $updates[] = trim((string)$b['thinking']);
            elseif ($type === 'tool_use') $calls[] = $i;
        }
        $stop = $answer['stop_reason'];
        // A refusal is discarded whole: what streamed before it is not a finished answer, and its tool calls never run.
        if ($stop === 'refusal') {
            $this->addMessage($storyId, $conversation, $turnId, 'assistant', 'claude', $answer['model'], null,
                ['text'=>'', 'refused'=>true, 'notice'=>t('Claude refused to continue this request (even after trying another model). Rephrase the request or change the course of the scene.')], $key);
            $data['note'] = t('Claude refused this request.');
            return true;
        }
        // A thinking block cut off by the output limit has no signature and can't be sent back, and an answer cut off
        // with nothing but thinking isn't sent back either: what it thought stays on screen, and the writer starts over.
        $content = array_values(array_filter($answer['content'], fn(array $b) => ($b['type'] ?? '') !== 'thinking' || (string)($b['signature'] ?? '') !== ''));
        $said = array_filter($content, fn(array $b) => in_array($b['type'] ?? '', ['text', 'tool_use'], true));
        $native = $content && ($said || $stop !== 'max_tokens') ? ['role'=>'assistant', 'content'=>$content] : null;
        $this->addMessage($storyId, $conversation, $turnId, 'assistant', 'claude', $answer['model'], $native,
            ['text'=>trim($text), 'updates'=>$updates, 'tools'=>array_map(fn($i) => (string)$answer['content'][$i]['name'], $calls), 'model'=>$answer['model']], $key);
        if (!$calls || $native === null) return $stop === 'max_tokens' ? !$this->continueCut($storyId, $conversation, $turnId, 'claude', $data, $key) : true;
        $results = []; $labels = [];
        foreach ($calls as $i) {
            $b = $answer['content'][$i]; $name = (string)$b['name'];
            // The call the output limit cut off: what arrived of a chapter is kept. The calls before it ran as usual.
            if ($stop === 'max_tokens' && isset($answer['invalid'][$i])) { $live['tool'] = $name; $saveLive(true); [$ok, $out, $label] = $this->cutCall($name, $answer['invalid'][$i], $context); }
            elseif (isset($answer['invalid'][$i])) { [$ok, $out, $label] = [false, json_encode(['INVALID_JSON'=>mb_substr($answer['invalid'][$i], 0, 2000)], JSON_UNESCAPED_UNICODE), null]; }
            else {
                $live['tool'] = $name; $saveLive(true);
                [$ok, $out, $label] = $this->runTool($name, json_decode(json_encode($b['input']), true) ?? [], $context);
            }
            $content = is_array($out) ? $out : (string)$out;
            $results[] = ['type'=>'tool_result', 'tool_use_id'=>$b['id'], 'content'=>$content] + ($ok ? [] : ['is_error'=>true]);
            $labels[] = ['name'=>$name, 'ok'=>$ok, 'label'=>$ok ? (string)$label : ($label ?? self::failLabel($name))];
            $live['done'][] = end($labels);
        }
        $this->addMessage($storyId, $conversation, $turnId, 'tools', 'claude', null, ['role'=>'user', 'content'=>$results], ['results'=>$labels], $key);
        return false;
    }

    private function afterGpt(array $answer, string $storyId, int $conversation, string $turnId, ToolContext $context, array &$data, array &$live, \Closure $saveLive, string $key): bool
    {
        $text = ''; $updates = []; $calls = []; $refused = false;
        foreach ($answer['output'] as $item) {
            $type = $item->type ?? '';
            if ($type === 'message') {
                foreach ($item->content ?? [] as $c) {
                    if (($c->type ?? '') === 'output_text') $text .= (string)$c->text;
                    if (($c->type ?? '') === 'refusal') { $refused = true; $text .= (string)($c->refusal ?? ''); }
                }
            } elseif ($type === 'reasoning') {
                foreach ($item->summary ?? [] as $s) if (trim((string)($s->text ?? '')) !== '') $updates[] = trim((string)$s->text);
            } elseif ($type === 'function_call') $calls[] = $item;
        }
        $this->addMessage($storyId, $conversation, $turnId, 'assistant', 'openai', $answer['model'], $answer['output'],
            ['text'=>trim($text), 'updates'=>$updates, 'tools'=>array_map(fn($c) => (string)$c->name, $calls), 'model'=>$answer['model']] + ($refused ? ['refused'=>true] : []), $key);
        $cut = $answer['status'] === 'incomplete';
        if (!$calls) return $cut ? !$this->continueCut($storyId, $conversation, $turnId, 'openai', $data, $key) : true;
        $outputs = []; $labels = []; $images = [];
        foreach ($calls as $call) {
            $name = (string)$call->name; $input = json_decode((string)($call->arguments ?? ''), true);
            // The call the output limit cut off: what arrived of a chapter is kept. Whole calls run as usual.
            if ($cut && !is_array($input)) [$ok, $out, $label] = $this->cutCall($name, (string)($call->arguments ?? ''), $context);
            elseif (!is_array($input)) [$ok, $out, $label] = [false, json_encode(['INVALID_JSON'=>mb_substr((string)($call->arguments ?? ''), 0, 2000)], JSON_UNESCAPED_UNICODE), null];
            else { $live['tool'] = $name; $saveLive(true); [$ok, $out, $label] = $this->runTool($name, $input, $context); }
            // An image goes in a message of its own after the outputs; the output says it is there.
            if (is_array($out)) {
                foreach ($out as $part) if (($part['type'] ?? '') === 'image') $images[] = ['type'=>'input_image', 'image_url'=>'data:'.$part['source']['media_type'].';base64,'.$part['source']['data']];
                $out = implode("\n", array_map(fn($p) => (string)($p['text'] ?? ''), array_filter($out, fn($p) => ($p['type'] ?? '') === 'text'))).' The image follows in the next message.';
            }
            $outputs[] = ['type'=>'function_call_output', 'call_id'=>$call->call_id, 'output'=>($ok ? '' : 'Error: ').$out];
            $labels[] = ['name'=>$name, 'ok'=>$ok, 'label'=>$ok ? (string)$label : ($label ?? self::failLabel($name))];
            $live['done'][] = end($labels);
        }
        if ($images) $outputs[] = ['type'=>'message', 'role'=>'user', 'content'=>array_merge([['type'=>'input_text', 'text'=>'The image(s) you asked to see:']], $images)];
        $this->addMessage($storyId, $conversation, $turnId, 'tools', 'openai', null, $outputs, ['results'=>$labels], $key);
        return false;
    }

    /** How many answers with a call Gemini couldn't write a turn goes past by itself. */
    private const MAX_MALFORMED = 3;
    private const MALFORMED = '(From the app, not the person.) Your last answer ended with a tool call that could not be read, so nothing was run. Call the tool again with valid arguments. Write long text in parts: the first part with write_chapter, then append_to_chapter.';

    /** Keeps Gemini's answer as it came (its thoughts with their signatures), runs the tools it asked for. True when the turn is over. */
    private function afterGemini(array $answer, string $storyId, int $conversation, string $turnId, ToolContext $context, array &$data, array &$live, \Closure $saveLive, string $key): bool
    {
        $text = ''; $updates = []; $calls = [];
        foreach ($answer['steps'] as $i => $step) {
            $type = $step->type ?? '';
            if ($type === 'model_output') { foreach ((array)($step->content ?? []) as $c) if (is_object($c) && ($c->type ?? '') === 'text') $text .= (string)($c->text ?? ''); }
            elseif ($type === 'thought') { foreach ((array)($step->summary ?? []) as $t) if (is_object($t) && trim((string)($t->text ?? '')) !== '') $updates[] = trim((string)$t->text); }
            elseif ($type === 'function_call') $calls[] = $i;
        }
        $cut = $answer['status'] === 'incomplete';
        // A thought cut off before its signature can't go back, nor an answer that is only thoughts cut off: what it
        // thought stays on screen, and the writer goes on.
        $steps = array_values(array_filter($answer['steps'], fn(object $s) => (($s->type ?? '') !== 'thought' || (string)($s->signature ?? '') !== '')
            && !(($s->type ?? '') === 'model_output' && empty($s->content))));
        $said = array_filter($steps, fn(object $s) => in_array($s->type ?? '', ['model_output', 'function_call'], true));
        $native = $steps && ($said || !$cut) ? $steps : null;
        // Kept under the writer's model name, which the next requests compare to keep or leave out its thoughts.
        $this->addMessage($storyId, $conversation, $turnId, 'assistant', 'gemini', $context->writer['model'], $native,
            ['text'=>trim($text), 'updates'=>$updates, 'tools'=>array_map(fn($i) => (string)($answer['steps'][$i]->name ?? ''), $calls), 'model'=>$answer['model']], $key);
        if ($answer['code'] !== null && !$calls) {
            $data['malformed'] = (int)($data['malformed'] ?? 0) + 1;
            if ($data['malformed'] > self::MAX_MALFORMED) { $data['note'] = t('Gemini wrote invalid tool calls {max} times in this request ({code}). Ask it to continue, or switch models.', ['max'=>self::MAX_MALFORMED, 'code'=>$answer['code']]); return true; }
            $this->addMessage($storyId, $conversation, $turnId, 'context', 'gemini', null, self::userNative('gemini', self::MALFORMED),
                ['hidden'=>true, 'notice'=>t('Gemini wrote a tool call that could not be read, and went on by itself to write it again.')], $key);
            return false;
        }
        if (!$calls || $native === null) return $cut ? !$this->continueCut($storyId, $conversation, $turnId, 'gemini', $data, $key) : true;
        $results = []; $labels = [];
        foreach ($calls as $i) {
            $call = $answer['steps'][$i]; $name = (string)($call->name ?? '');
            // The call the output limit cut off: what arrived of a chapter is kept. Whole calls run as usual.
            if ($cut && isset($answer['invalid'][$i])) { $live['tool'] = $name; $saveLive(true); [$ok, $out, $label] = $this->cutCall($name, $answer['invalid'][$i], $context); }
            elseif (isset($answer['invalid'][$i])) [$ok, $out, $label] = [false, json_encode(['INVALID_JSON'=>mb_substr($answer['invalid'][$i], 0, 2000)], JSON_UNESCAPED_UNICODE), null];
            else {
                $live['tool'] = $name; $saveLive(true);
                [$ok, $out, $label] = $this->runTool($name, json_decode(json_encode($call->arguments ?? new \stdClass()), true) ?? [], $context);
            }
            $results[] = ['type'=>'function_result', 'call_id'=>(string)($call->id ?? ''), 'name'=>$name, 'result'=>self::geminiParts($out)] + ($ok ? [] : ['is_error'=>true]);
            $labels[] = ['name'=>$name, 'ok'=>$ok, 'label'=>$ok ? (string)$label : ($label ?? self::failLabel($name))];
            $live['done'][] = end($labels);
        }
        $this->addMessage($storyId, $conversation, $turnId, 'tools', 'gemini', null, $results, ['results'=>$labels], $key);
        return false;
    }

    /** A tool's result (text, or Claude's content blocks with an image) as Gemini content. */
    private static function geminiParts(string|array $out): array
    {
        if (is_string($out)) return [['type'=>'text', 'text'=>$out !== '' ? $out : 'Done.']];
        $parts = [];
        foreach ($out as $p) {
            if (($p['type'] ?? '') === 'text') $parts[] = ['type'=>'text', 'text'=>(string)$p['text']];
            elseif (($p['type'] ?? '') === 'image') $parts[] = ['type'=>'image', 'mime_type'=>(string)$p['source']['media_type'], 'data'=>(string)$p['source']['data']];
        }
        return $parts ?: [['type'=>'text', 'text'=>'Done.']];
    }

    // =================================================================================================================
    // Tools
    // =================================================================================================================

    private const CHAPTER = ['type'=>'integer', 'minimum'=>1, 'description'=>'Chapter number (1 is the first), as in the outline.'];
    private const REFERENCES = 'Image ids (32 hex characters): the character sheets of the characters shown (the one matching the moment), earlier illustrations, gallery images. A request id from character_sheet or illustrate in this turn also works: the app waits until its image is ready.';
    private const EDITS = ['type'=>'array', 'maxItems'=>100, 'description'=>'Exact replacements, applied in order. Each old_text must appear exactly once in the current text (copy it exactly, with enough context to be unique).',
        'items'=>['type'=>'object', 'properties'=>['old_text'=>['type'=>'string'], 'new_text'=>['type'=>'string']], 'required'=>['old_text', 'new_text']]];

    private const PAGE = ['type'=>'integer', 'minimum'=>1, 'description'=>'Page number of the comic (1 is the first).'];
    /** The comic adaptation's own workspace (its bible, plan, sheets, style and lettering) instead of the book's. */
    private const COMIC_SPACE = ['type'=>'boolean', 'description'=>'true: the comic adaptation\'s own workspace instead of the book\'s.'];
    private const COMIC_PANEL = ['type'=>'object', 'properties'=>[
        'framing'=>['type'=>'string', 'description'=>'Shot and angle, e.g. "wide shot, low angle", "close-up of Ana\'s eyes", "over the shoulder, from behind Bruno".'],
        'place'=>['type'=>'string', 'description'=>'Where it happens: the name of a place sheet, exactly as in the sheets. Leave out for a panel with no setting (a plain or abstract background).'],
        'characters'=>['type'=>'array', 'maxItems'=>8, 'description'=>'Who is in the panel. Only characters with a sheet; an extra who never comes back goes in description instead.', 'items'=>['type'=>'object', 'properties'=>[
            'name'=>['type'=>'string', 'description'=>'As in the character sheets.'],
            'sheet'=>['type'=>'string', 'description'=>'The id of the picture of their sheet that matches this moment (another age, outfit, form). Default: their main picture.'],
            'where'=>['type'=>'string', 'description'=>'Where they are in the panel, e.g. "left, foreground", "right, far back", "center, from the waist up".'],
            'doing'=>['type'=>'string', 'description'=>'Pose, action and expression.']], 'required'=>['name']]],
        'description'=>['type'=>'string', 'description'=>'What the panel shows, in English: the action, the composition, mood and light, effects (speed lines, impact frames, screentone or abstract backgrounds) and any extras.'],
        'text'=>['type'=>'array', 'maxItems'=>12, 'description'=>'Balloons, captions, signs and sound effects, in reading order.', 'items'=>['type'=>'object', 'properties'=>[
            'type'=>['type'=>'string', 'enum'=>Comics::TEXT_TYPES],
            'by'=>['type'=>'string', 'description'=>'Who speaks or thinks (a character\'s name); for a sound effect, what makes it.'],
            'text'=>['type'=>'string', 'description'=>'The exact words, in the story\'s language. A balloon holds up to about 25 words.'],
            'where'=>['type'=>'string', 'description'=>'Where it goes, e.g. "top left", "huge, behind the car, breaking the border". Optional.']], 'required'=>['type', 'text']]]],
        'required'=>['description']];
    private const COMIC_PAGE = [
        'chapter'=>['type'=>'integer', 'minimum'=>1, 'description'=>'The chapter this page adapts.'],
        'summary'=>['type'=>'string', 'description'=>'What happens on the page, in a sentence or two, in the story\'s language.'],
        'panels'=>['type'=>'array', 'maxItems'=>Comics::MAX_PANELS, 'items'=>self::COMIC_PANEL, 'description'=>'The panels in reading order.'],
        'sketch_svg'=>['type'=>'string', 'description'=>'Your rough storyboard of the page, as SVG (see the Comics section of your instructions): <svg viewBox="0 0 1000 H"> with the panels, simple posed figures labeled with names, the balloons labeled B1, B2… where they go, sound effects, arrows. "" lets the app draw a plain one from the script.'],
        'rows'=>['type'=>'array', 'maxItems'=>6, 'items'=>['type'=>'integer', 'minimum'=>1, 'maximum'=>5], 'description'=>'For the app\'s sketch only (without sketch_svg): how many panels in each row, top to bottom; they must add up to the panels.'],
        'notes'=>['type'=>'string', 'description'=>'Notes for the artist about the whole page, in English: mood, light, palette accents, transitions. Optional.']];

    /** The comic's tools, which a visual novel project doesn't have. */
    private const COMIC_TOOLS = ['comic_settings', 'read_comic', 'write_comic_page', 'edit_comic_page', 'view_comic_page', 'draw_comic_page', 'delete_comic_page', 'move_comic_page'];

    /**
     * name => [description, input schema]: a story's tools, or a visual novel project's ($vn: the story's without the
     * comic, and the visual novel's), or every tool of both (null, for MCP, where one list serves every story).
     */
    public static function tools(?bool $vn = false): array
    {
        $tools = self::storyTools();
        if ($vn === null) return $tools + self::vnTools();
        return $vn ? array_diff_key($tools, array_flip(self::COMIC_TOOLS)) + self::vnTools() : $tools;
    }

    /** The system prompt: the writer's instructions, and a visual novel's after them. */
    public static function system(bool $vn = false): string { return $vn ? self::SYSTEM."\n\n".self::VN_SYSTEM : self::SYSTEM; }

    private static function storyTools(): array
    {
        $doc = fn(string $what) => ['type'=>'object', 'properties'=>[
            'text'=>['type'=>'string', 'description'=>"The whole new text of the $what (replaces it)."],
            'append'=>['type'=>'string', 'description'=>"Text added at the end of the $what."],
            'edits'=>self::EDITS, 'comic'=>self::COMIC_SPACE]];
        return [
            'get_outline'=>['The story at a glance: title, synopsis, cover, settings, every chapter with its number, title, length and summary, the revisions of the bible and the plan, and the character sheets with the id of each picture.', ['type'=>'object', 'properties'=>new \stdClass()]],
            'read_chapter'=>['The full text of a chapter, with its title and summary. Illustrations appear as lines ![caption](img:ID) (ready) or ![caption](job:ID) (being made).',
                ['type'=>'object', 'properties'=>['chapter'=>self::CHAPTER], 'required'=>['chapter']]],
            'write_chapter'=>['Adds a new chapter, by default after the last one. For a long chapter, write the opening part here and the rest with append_to_chapter.',
                ['type'=>'object', 'properties'=>['title'=>['type'=>'string', 'description'=>'Chapter title, without "Chapter N".'], 'text'=>['type'=>'string', 'description'=>'The chapter text (Markdown), or its first part.'],
                    'summary'=>['type'=>'string', 'description'=>'What happens in the chapter (3 to 8 sentences). Can be set later with edit_chapter.'],
                    'position'=>['type'=>'integer', 'minimum'=>1, 'description'=>'Insert as this chapter number instead of at the end.']], 'required'=>['title', 'text']]],
            'append_to_chapter'=>['Adds text at the end of a chapter: the next part of a chapter written in parts.',
                ['type'=>'object', 'properties'=>['chapter'=>self::CHAPTER, 'text'=>['type'=>'string']], 'required'=>['chapter', 'text']]],
            'edit_chapter'=>['Revises part of a chapter with exact replacements, and/or changes its title or summary. Read the chapter first, unless you wrote its current text in this conversation.',
                ['type'=>'object', 'properties'=>['chapter'=>self::CHAPTER, 'edits'=>self::EDITS, 'title'=>['type'=>'string'], 'summary'=>['type'=>'string']], 'required'=>['chapter']]],
            'rewrite_chapter'=>['Replaces the whole text of a chapter (for a full rewrite). Illustration lines you leave out are removed from the chapter.',
                ['type'=>'object', 'properties'=>['chapter'=>self::CHAPTER, 'text'=>['type'=>'string'], 'title'=>['type'=>'string'], 'summary'=>['type'=>'string']], 'required'=>['chapter', 'text']]],
            'delete_chapter'=>['Deletes a chapter. Only when the person asks for it.', ['type'=>'object', 'properties'=>['chapter'=>self::CHAPTER], 'required'=>['chapter']]],
            'move_chapter'=>['Moves a chapter so it becomes chapter number `to`.', ['type'=>'object', 'properties'=>['chapter'=>self::CHAPTER, 'to'=>['type'=>'integer', 'minimum'=>1]], 'required'=>['chapter', 'to']]],
            'read_notes'=>['The story bible: the canon established so far (visible to the person). comic: true reads the comic adaptation\'s own bible instead.', ['type'=>'object', 'properties'=>['comic'=>self::COMIC_SPACE]]],
            'update_notes'=>['Changes the story bible: the whole text, text added at the end, or exact replacements. Only canon the chapters already showed; secrets and future events go in the plan. comic: true changes the comic adaptation\'s own bible instead (the book\'s stays as it is).', $doc('bible')],
            'read_plan'=>['Your private plan (hidden from the person). comic: true reads the comic adaptation\'s own plan instead.', ['type'=>'object', 'properties'=>['comic'=>self::COMIC_SPACE]]],
            'update_plan'=>['Changes your private plan: the whole text, text added at the end, or exact replacements. comic: true changes the comic adaptation\'s own plan instead (the book\'s stays as it is).', $doc('plan')],
            'update_reader_notes'=>['Changes your own notes on the person as a reader (<reader_notes>), kept with their account and shown to you in all their stories, beside what they wrote about themselves (<reader>, which stays as they wrote it): the whole text, a line added at the end, or exact replacements. Short lines on their taste and how they like to work with you; no story content or spoilers, since they can read them in the app.',
                ['type'=>'object', 'properties'=>['text'=>['type'=>'string', 'description'=>'The whole new text of your notes (replaces them).'],
                    'append'=>['type'=>'string', 'description'=>'Text added at the end of your notes.'], 'edits'=>self::EDITS]]],
            'update_story'=>['Changes the title, the synopsis (back-cover text, no spoilers) or the series name of the story.',
                ['type'=>'object', 'properties'=>['title'=>['type'=>'string'], 'synopsis'=>['type'=>'string'], 'series'=>['type'=>'string']]]],
            'search_story'=>['Finds a word or phrase in the chapters (and in the bible and plan), ignoring case: each match with its chapter and the text around it. Use it to check facts before writing.',
                ['type'=>'object', 'properties'=>['query'=>['type'=>'string']], 'required'=>['query']]],
            'find_repetitions'=>['The phrases of 4 words or more that the book uses more than once, counted by the app (free, and short): with chapter, the ones that chapter uses (found elsewhere in the book or twice in it), for its revision; without, the most repeated in the whole book.',
                ['type'=>'object', 'properties'=>['chapter'=>['type'=>'integer', 'minimum'=>1, 'description'=>'The chapter to check. Leave out for the whole book.']]]],
            'review_book'=>['The whole book at once, for a review of it: the full text of every chapter in order, and the phrases the book repeats, counted by the app. As long as the book itself: only when the person asks for a review of the whole book (see Reviewing the whole book in your instructions).',
                ['type'=>'object', 'properties'=>[
                    'from'=>['type'=>'integer', 'minimum'=>1, 'description'=>'Read from this chapter on, to read a long book in parts. Default: the first.'],
                    'to'=>['type'=>'integer', 'minimum'=>1, 'description'=>'Read up to this chapter. Default: the last.']]]],
            'illustrate'=>['Makes an illustration with the image model and puts it in a chapter (or makes it the story cover). It is ready in a minute or two. Costs money: only when asked or when the story settings ask for it.',
                ['type'=>'object', 'properties'=>[
                    'prompt'=>['type'=>'string', 'description'=>'Complete visual description in English: moment, setting, light, composition, each character exactly as in the bible, and the story\'s visual style. Name the reference images @1, @2… in the order of references.'],
                    'caption'=>['type'=>'string', 'description'=>'Short caption shown under the image, in the language of the story.'],
                    'chapter'=>self::CHAPTER, 'after_text'=>['type'=>'string', 'description'=>'An exact snippet of the paragraph the image goes after. Leave out to put it at the end of the chapter.'],
                    'at_start'=>['type'=>'boolean', 'description'=>'Put the image at the start of the chapter.'],
                    'cover'=>['type'=>'boolean', 'description'=>'Make it the story cover instead of putting it in a chapter: the app makes several covers at once (3 by default), each in a different design direction, designed as the front cover of the story\'s format and lettered with the title, series and author\'s name (and the volume, when the person set one), for the person to choose from.'],
                    'cover_styles'=>['type'=>'array', 'minItems'=>1, 'maxItems'=>4, 'items'=>['type'=>'string', 'enum'=>self::COVER_STYLES], 'description'=>'Only with cover: true, and only when the person asks for particular styles: one cover in each of them. classic (timeless, elegant), literal (a scene of the story, like a film poster), modern (contemporary graphic design), minimal (one clever visual idea), retro (a mid-century screen print), abstract (the mood as pure form), typographic (the title is the image). Left out, the app picks different ones each time.'],
                    'variants'=>['type'=>'integer', 'minimum'=>1, 'maximum'=>4, 'description'=>'Only with cover: true and without cover_styles: how many covers to make, each in another style; default 3. 1 only when the person asks for a single cover.'],
                    'cover_format'=>['type'=>'string', 'enum'=>Stories::COVER_FORMATS, 'description'=>'Only with cover: true, and only when the person asks for another format than the story state\'s: novel, illustrated (an illustrated novel), light_novel (a Japanese light novel volume), manga (a manga volume, tankōbon), comic (a comic book issue) or graphic_novel (a graphic novel).'],
                    'aspect'=>['type'=>'string', 'enum'=>self::ASPECTS, 'description'=>'Aspect ratio, default 3:2 (2:3 for a cover).'],
                    'lettering'=>['type'=>'boolean', 'description'=>'true when the picture has text drawn in it (a sign, a letter, a speech balloon, a sound effect): the app adds the story\'s lettering and its sample, so the letters look the same in every picture. Write the exact words in the prompt. A cover is lettered by the app (its title and the other words of the story state) unless you pass false.'],
                    'references'=>['type'=>'array', 'maxItems'=>8, 'items'=>['type'=>'string'], 'description'=>self::REFERENCES]],
                    'required'=>['prompt', 'caption']]],
            'character_sheet'=>['Makes a reference picture for the story\'s sheets, which the person sees in the app: of a character (the whole body from the front, side and back and a close-up of the face, on a plain background) or of a place that comes back (kind: place). The app adds the story\'s visual style. A character can have several (other ages, outfits, forms, changes). The picture is ready in a minute or two; in this same turn, its request id already works in the references of illustrate and character_sheet (the app waits for it). Costs money like illustrate: only as the story settings say or when asked.',
                ['type'=>'object', 'properties'=>[
                    'character'=>['type'=>'string', 'description'=>'The character\'s (or place\'s) name, as in the bible. A name not in the sheets yet adds it.'],
                    'kind'=>['type'=>'string', 'enum'=>Stories::SHEET_KINDS, 'description'=>'character (default) or place.'],
                    'prompt'=>['type'=>'string', 'description'=>'Complete visual description in English. A character: their exact appearance (age, build, skin tone, hair, eyes, clothing, marks) and the layout (full body front, side and back views and a face close-up, plain light background, no text or labels). A place: what it looks like, in a wide view, without people. Name the reference images @1, @2… in the order of references.'],
                    'caption'=>['type'=>'string', 'description'=>'What this picture shows, in the language of the story, without spoilers (e.g. "At 30", "In the guard\'s uniform").'],
                    'note'=>['type'=>'string', 'description'=>'Optional: who the character is, in a few words, without spoilers (e.g. "a narradora, 30 anos").'],
                    'aspect'=>['type'=>'string', 'enum'=>self::ASPECTS, 'description'=>'Aspect ratio, default 3:2.'],
                    'references'=>['type'=>'array', 'maxItems'=>8, 'items'=>['type'=>'string'], 'description'=>'The character\'s earlier sheets or illustrations (to keep the face), or gallery images the person attached. '.self::REFERENCES],
                    'comic'=>['type'=>'boolean', 'description'=>'true: a picture for the comic adaptation\'s own sheets (the ones its pages are drawn with), in the comic\'s style, instead of the book\'s. Pass the book\'s sheet of the character in references to keep their look.']],
                    'required'=>['character', 'prompt', 'caption']]],
            'update_character'=>['Changes the sheets without making images (free): adds a character or place, renames one, sets its note, adds existing images (illustrations of the story, gallery images) to its sheet, changes captions, removes pictures, chooses its main picture (the first one), or removes it.',
                ['type'=>'object', 'properties'=>[
                    'character'=>['type'=>'string', 'description'=>'The name (or id) of the character or place. A name not in the sheets adds it.'],
                    'kind'=>['type'=>'string', 'enum'=>Stories::SHEET_KINDS, 'description'=>'character or place (for a new one, default character).'],
                    'new_name'=>['type'=>'string'], 'note'=>['type'=>'string', 'description'=>'Who the character is, in a few words, without spoilers.'],
                    'add_images'=>['type'=>'array', 'maxItems'=>12, 'items'=>['type'=>'object', 'properties'=>['image_id'=>['type'=>'string'], 'caption'=>['type'=>'string']], 'required'=>['image_id']]],
                    'captions'=>['type'=>'array', 'maxItems'=>24, 'items'=>['type'=>'object', 'properties'=>['image_id'=>['type'=>'string'], 'caption'=>['type'=>'string']], 'required'=>['image_id', 'caption']]],
                    'remove_images'=>['type'=>'array', 'maxItems'=>24, 'items'=>['type'=>'string'], 'description'=>'Ids of pictures (or of requests still making them) to take off the sheet. The images stay in the gallery.'],
                    'main_image'=>['type'=>'string', 'description'=>'The picture that goes first and stands for the character.'],
                    'delete'=>['type'=>'boolean', 'description'=>'Remove the character from the sheets (the images stay in the gallery).'],
                    'comic'=>['type'=>'boolean', 'description'=>'true: changes the comic adaptation\'s own sheets instead of the book\'s.'],
                    'from_book'=>['type'=>'boolean', 'description'=>'With comic: true, brings the book\'s sheet of this character or place into the adaptation\'s sheets, free: the same pictures, kind and note (the pictures the adaptation\'s sheet of that name lacks, when it has one). Then change it as you like; the book\'s stays as it is.']],
                    'required'=>['character']]],
            'set_style'=>['Sets the story\'s visual style, which the app adds to every new illustration, cover and sheet (free): its description, and up to '.Stories::MAX_STYLE_IMAGES.' style images (pictures whose look the others follow, not their content). The person sees and can change it.',
                ['type'=>'object', 'properties'=>[
                    'text'=>['type'=>'string', 'description'=>'The whole style description, in English: medium, line, palette, light, rendering, the artists or era it recalls. Replaces the one there.'],
                    'add_images'=>['type'=>'array', 'maxItems'=>Stories::MAX_STYLE_IMAGES, 'items'=>['type'=>'object', 'properties'=>['image_id'=>['type'=>'string'], 'caption'=>['type'=>'string']], 'required'=>['image_id']], 'description'=>'Pictures with the story\'s look: an illustration, a sheet picture, the cover, a gallery image. A request id from this turn also works.'],
                    'remove_images'=>['type'=>'array', 'maxItems'=>Stories::MAX_STYLE_IMAGES, 'items'=>['type'=>'string']],
                    'lettering'=>['type'=>'string', 'description'=>'How every text drawn in the pictures looks (titles, signs, speech balloons, captions, sound effects), in English: the typeface (or the kind, e.g. hand-lettered comic font, condensed bold sans), case, weight, color, outline, how balloons and sound effects are drawn. Replaces the one there. The app adds it, with its sample, to pictures made with lettering: true.'],
                    'lettering_image'=>['type'=>'string', 'description'=>'An image id to keep as the lettering sample (a gallery image the person gave, a picture of the story with the right letters); "" removes it.'],
                    'make_lettering_sample'=>['type'=>'boolean', 'description'=>'Makes a sample sheet of the lettering from its description (the alphabet, a speech balloon, a shout, a caption, sound effects), which then goes with every picture that has text. Costs one image: only when the person asks for lettering or comics, or agrees.'],
                    'comic'=>['type'=>'boolean', 'description'=>'true: sets the comic adaptation\'s own style and lettering (which its pages use) instead of the book\'s: text and the style images are the comic\'s style (as in comic_settings), lettering and its sample the comic\'s lettering.']]]],
            'edit_illustration'=>['Makes a new version of an illustration of this story (or of its cover, a character sheet picture or a drawn page of the comic), which takes the old one\'s place in the chapters, as the cover, in the sheets and in the comic when it is ready, in a minute or two; the old one stays in the gallery. kind "edit" follows your instruction; "photoreal" turns it into a real-looking photograph, "enhance" cleans it and brings it to its best quality without changing its style, and "upscale" recreates it larger: these three use the app\'s own ready-made instructions, and yours go along as notes with priority. Costs money like illustrate: only when asked.',
                ['type'=>'object', 'properties'=>[
                    'image_id'=>['type'=>'string', 'description'=>'The picture to change: the ID in img:ID, the cover, a character sheet picture, or a comic page\'s image.'],
                    'kind'=>['type'=>'string', 'enum'=>['edit', 'photoreal', 'enhance', 'upscale'], 'description'=>'Default edit.'],
                    'instruction'=>['type'=>'string', 'description'=>'For edit (required): what to change and what must stay, in English. @1 is the illustration; @2, @3… are the references, in order. For the others: optional notes (what to improve or keep).'],
                    'references'=>['type'=>'array', 'maxItems'=>5, 'items'=>['type'=>'string'], 'description'=>'Image ids of other pictures to use, such as a character\'s sheet. A request id from this turn also works.'],
                    'caption'=>['type'=>'string', 'description'=>'A new caption, if it should change.']],
                    'required'=>['image_id']]],
            'view_image'=>['Shows you an image: an illustration of the story (the id in img:ID) or an image of the person\'s gallery.', ['type'=>'object', 'properties'=>['image_id'=>['type'=>'string']], 'required'=>['image_id']]],
            'search_gallery'=>['Finds images in the person\'s gallery by the words of the prompt they were made with, newest first: id, title and prompt of each.', ['type'=>'object', 'properties'=>['query'=>['type'=>'string']], 'required'=>['query']]],
            'comic_settings'=>['Sets up the story\'s adaptation as a manga or a comic, or changes its settings (free). Leave out what stays.',
                ['type'=>'object', 'properties'=>[
                    'kind'=>['type'=>'string', 'enum'=>Comics::KINDS, 'description'=>'manga; comic: a Western comic book, told like the issues of a series; graphic_novel: a Western book-length work with the pacing of a novel. Each has its own language (see the Comics section of your instructions).'], 'reading'=>['type'=>'string', 'enum'=>Comics::READINGS, 'description'=>'rtl: right to left, as manga; ltr: left to right.'],
                    'color'=>['type'=>'string', 'enum'=>Comics::COLORS, 'description'=>'bw: black and white with screentones; color: full color.'],
                    'aspect'=>['type'=>'string', 'enum'=>Comics::ASPECTS, 'description'=>'The page\'s shape (2:3 is the usual comic and manga page).'],
                    'scale'=>['type'=>'string', 'enum'=>Comics::SCALES, 'description'=>'The page\'s size: the image API\'s usual size, or 1.5 or 2 times it (sharper lettering, costs more).'],
                    'style'=>['type'=>'string', 'description'=>'A visual style of the adaptation\'s own, in English (medium, line, shading, palette, the artists or era it recalls), when the pages should look different from the story\'s illustrations. "" goes back to the story\'s visual style.'],
                    'style_add_images'=>['type'=>'array', 'maxItems'=>Comics::MAX_STYLE_IMAGES, 'items'=>['type'=>'object', 'properties'=>['image_id'=>['type'=>'string'], 'caption'=>['type'=>'string']], 'required'=>['image_id']], 'description'=>'Pictures whose look every page follows (a finished page you like is the best one). A request id from this turn also works.'],
                    'style_remove_images'=>['type'=>'array', 'maxItems'=>Comics::MAX_STYLE_IMAGES, 'items'=>['type'=>'string']]]]],
            'read_comic'=>['The comic adaptation: its settings and every page (number, chapter, summary, panels, whether it is drawn), and the full script of the pages asked for.',
                ['type'=>'object', 'properties'=>['pages'=>['type'=>'array', 'maxItems'=>20, 'items'=>['type'=>'integer', 'minimum'=>1], 'description'=>'Page numbers whose full script to show.']]]],
            'write_comic_page'=>['Adds a page to the comic (free): its script panel by panel and its storyboard sketch. The app answers with the sketch as it drew it, for you to check. Draw it with draw_comic_page.',
                ['type'=>'object', 'properties'=>self::COMIC_PAGE + ['position'=>['type'=>'integer', 'minimum'=>1, 'description'=>'Insert as this page number instead of at the end.']], 'required'=>['summary', 'panels']]],
            'edit_comic_page'=>['Changes a page\'s script or sketch (free): the fields given replace the ones there; panel_number with panel replaces one panel. A page already drawn keeps its image until you draw it again.',
                ['type'=>'object', 'properties'=>['page'=>self::PAGE] + self::COMIC_PAGE + ['panel_number'=>['type'=>'integer', 'minimum'=>1], 'panel'=>self::COMIC_PANEL], 'required'=>['page']]],
            'view_comic_page'=>['Shows you a page: its script, its sketch (and the SVG you wrote for it) and the drawn page, if there is one.',
                ['type'=>'object', 'properties'=>['page'=>self::PAGE, 'svg'=>['type'=>'boolean', 'description'=>'Also show the sketch\'s SVG source (to change it). Default false.']], 'required'=>['page']]],
            'draw_comic_page'=>['Draws the finished page in one image, from its script and sketch, with the sheets of its characters and places, the previous page, the style and the lettering. Ready in a few minutes; it then replaces the page\'s image. Costs one image at the page\'s size: only when the person asks. Every character and place of the page needs a sheet picture first.',
                ['type'=>'object', 'properties'=>['page'=>self::PAGE,
                    'instruction'=>['type'=>'string', 'description'=>'Notes for this drawing only, in English (what to get right this time). Optional.'],
                    'references'=>['type'=>'array', 'maxItems'=>3, 'items'=>['type'=>'string'], 'description'=>'Other images to use (an object, a vehicle, a costume detail); say in instruction what each one is. '.self::REFERENCES],
                    'previous'=>['type'=>'boolean', 'description'=>'Use the previous page as a reference for continuity (default true). If it is being drawn, the app waits for it.']], 'required'=>['page']]],
            'delete_comic_page'=>['Deletes a page of the comic (its images stay in the gallery). Only when the person asks, or to replace it.', ['type'=>'object', 'properties'=>['page'=>self::PAGE], 'required'=>['page']]],
            'move_comic_page'=>['Moves a page so it becomes page number `to`.', ['type'=>'object', 'properties'=>['page'=>self::PAGE, 'to'=>['type'=>'integer', 'minimum'=>1]], 'required'=>['page', 'to']]],
        ];
    }

    public static function claudeTools(bool $vn = false): array
    {
        $out = [];
        foreach (self::tools($vn) as $name => [$description, $schema]) $out[] = ['name'=>$name, 'description'=>$description, 'input_schema'=>$schema, 'eager_input_streaming'=>true];
        return $out;
    }

    public static function gptTools(bool $vn = false): array
    {
        $out = [];
        foreach (self::tools($vn) as $name => [$description, $schema]) $out[] = ['type'=>'function', 'name'=>$name, 'description'=>$description, 'parameters'=>$schema, 'strict'=>false];
        return $out;
    }

    /** The tools as Gemini's functions. One without inputs goes without parameters: an object with no properties is refused. */
    public static function geminiTools(bool $vn = false): array
    {
        $out = [];
        foreach (self::tools($vn) as $name => [$description, $schema]) {
            $tool = ['type'=>'function', 'name'=>$name, 'description'=>$description];
            if ((array)($schema['properties'] ?? []) !== []) $tool['parameters'] = $schema;
            $out[] = $tool;
        }
        return $out;
    }

    /** Checks a tool input against its schema. Returns what is wrong, or null. */
    public static function invalid(mixed $value, array $schema, string $path = 'input'): ?string
    {
        $type = $schema['type'] ?? null;
        if ($type === 'object') {
            if (!is_array($value) || ($value && array_is_list($value))) return "$path must be an object";
            foreach ($schema['required'] ?? [] as $r) if (!array_key_exists($r, $value)) return "$path.$r is required";
            $props = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ($value as $k => $v) if (isset($props[$k]) && ($e = self::invalid($v, $props[$k], "$path.$k")) !== null) return $e;
            return null;
        }
        if ($type === 'array') {
            if (!is_array($value) || !array_is_list($value)) return "$path must be an array";
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) return "$path has more than {$schema['maxItems']} items";
            foreach ($value as $n => $v) if (isset($schema['items']) && ($e = self::invalid($v, $schema['items'], $path."[$n]")) !== null) return $e;
            return null;
        }
        if ($type === 'string') {
            if (!is_string($value)) return "$path must be a string";
            if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) return "$path must be one of ".implode(', ', $schema['enum']);
            return null;
        }
        if ($type === 'integer') {
            if (!is_int($value)) return "$path must be an integer";
            if (isset($schema['minimum']) && $value < $schema['minimum']) return "$path must be at least {$schema['minimum']}";
            return null;
        }
        if ($type === 'boolean') return is_bool($value) ? null : "$path must be true or false";
        return null;
    }

    /**
     * Runs one tool call. Returns [ok, result for the model (text, or content blocks with an image), label for the chat].
     * @return array{0:bool,1:string|array,2:?string}
     */
    public function runTool(string $name, array $input, ToolContext $c): array
    {
        $vn = $this->isVnContext($c);
        $tools = self::tools($vn);
        if (!isset($tools[$name])) return [false, isset(self::vnTools()[$name]) ? 'This tool is for visual novel projects, and this story is not one. Nothing was done.'
            : (in_array($name, self::COMIC_TOOLS, true) ? 'A visual novel project has no comic adaptation. Nothing was done.' : "Unknown tool $name."), null];
        if (($error = self::invalid($input, $tools[$name][1])) !== null) return [false, "Invalid input: $error. Nothing was done.", null];
        try {
            [$out, $label] = $this->tool($name, $input, $c);
            return [true, $out, $label];
        } catch (HttpError $e) {
            return [false, $e->getMessage(), match ($e->kind) { 'credit'=>t('No OpenAI balance to illustrate'), 'gemini-credit'=>t('No Gemini credit to illustrate'), default=>null }];
        }
    }

    /** A tool call that failed, for the chat. Labels never carry what the plan says, so they can't spoil the story. */
    private static function failLabel(string $name): string
    {
        $what = ['get_outline'=>t('look up the contents'), 'read_chapter'=>t('read a chapter'), 'write_chapter'=>t('write a chapter'), 'append_to_chapter'=>t('continue a chapter'),
            'edit_chapter'=>t('revise a chapter'), 'rewrite_chapter'=>t('rewrite a chapter'), 'delete_chapter'=>t('delete a chapter'), 'move_chapter'=>t('move a chapter'),
            'read_notes'=>t('read the story bible'), 'update_notes'=>t('update the bible'), 'read_plan'=>t('look up the plan'), 'update_plan'=>t('update the plan'),
            'update_reader_notes'=>t('update the notes on you as a reader'), 'update_story'=>t('update the story\'s details'), 'search_story'=>t('check a detail of the story'), 'find_repetitions'=>t('count the repeated phrases'), 'review_book'=>t('read the book for the review'), 'illustrate'=>t('ask for an illustration'), 'edit_illustration'=>t('edit an illustration'), 'view_image'=>t('look at an image'),
            'character_sheet'=>t('make a sheet'), 'update_character'=>t('change the sheets'), 'set_style'=>t('set the visual style'),
            'search_gallery'=>t('search the gallery for images'), 'comic_settings'=>t('set up the comics'), 'read_comic'=>t('look up the comics'), 'write_comic_page'=>t('write a comics page'),
            'edit_comic_page'=>t('change a comics page'), 'view_comic_page'=>t('look at a comics page'), 'draw_comic_page'=>t('draw a comics page'),
            'delete_comic_page'=>t('delete a comics page'), 'move_comic_page'=>t('move a comics page')][$name] ?? (isset(self::VN_FAIL[$name]) ? t(self::VN_FAIL[$name]) : $name);
        return t('Could not {what}', ['what'=>$what]);
    }

    private function chapterAt(ToolContext $c, int $number): array
    {
        $chapters = $this->stories->chapters($c->storyId, $c->key);
        if ($number < 1 || $number > count($chapters)) throw new HttpError(422, 'There is no chapter '.$number.' (the story has '.count($chapters).' chapter'.(count($chapters) === 1 ? '' : 's').').');
        return $chapters[$number - 1] + ['number'=>$number];
    }

    private static function words(string $text): string { return I18n::number(Stories::words($text)); }

    /** @return array{0:string|array,1:string} */
    private function tool(string $name, array $in, ToolContext $c): array
    {
        $s = $this->stories; $id = $c->storyId; $key = $c->key;
        if (isset(self::vnTools()[$name])) return $this->vnTool($name, $in, $c);
        switch ($name) {
            case 'get_outline': {
                $story = $s->get($id, $key, null, true);
                $out = 'Title: '.$story['title']."\n".($story['series'] !== '' ? 'Series: '.$story['series']."\n" : '').'Synopsis: '.($story['synopsis'] ?: '(none)')."\n".self::coverLine($story)."\n".$this->settingsLines($c->writer)
                    .'Bible: revision '.$story['notes']['rev'].', '.Stories::words($story['notes']['text'])." words. Plan: revision ".$story['plan']['rev'].', '.$story['plan']['words']." words.\n\nChapters (".count($story['chapters'])."):\n";
                foreach ($story['chapters'] as $n => $ch) $out .= self::chapterLine($n + 1, $ch)."\n";
                $failed = $this->failedIllustrations($id, $key);
                $out .= "\nVisual style and sheets:\n".self::castLines($story['cast'], $failed);
                if ($s->comics()->settings($id, $key)['started'] && !$this->isVnContext($c)) {
                    $space = ['notes'=>$s->readDocument($id, 'comic_notes', $key), 'plan'=>$s->readDocument($id, 'comic_plan', $key)];
                    $out .= "\n".$s->comics()->outline($id, $key, self::pageFailures($failed))
                        .'Adaptation bible: revision '.$space['notes']['rev'].', '.Stories::words($space['notes']['text']).' words. Adaptation plan: revision '.$space['plan']['rev'].', '.Stories::words($space['plan']['text'])." words.\n"
                        ."The adaptation's lettering and sheets:\n".self::castLines($s->cast($id, $key, 'comic'), $failed, true);
                }
                if ($this->isVnContext($c)) $out .= $this->vnState($id, $key, $failed);
                return [$out, t('Looked up the contents')];
            }
            case 'read_chapter': {
                $ch = $this->chapterAt($c, $in['chapter']);
                return ['Chapter '.$ch['number'].': "'.$ch['title'].'" ('.Stories::words($ch['text'])." words)\nSummary: ".($ch['summary'] ?: '(none)')."\n\n".$ch['text'], t('Read chapter {n}', ['n'=>$ch['number']])];
            }
            case 'write_chapter': {
                $index = isset($in['position']) ? $in['position'] - 1 : null;
                $ch = $s->addChapter($id, $in['title'], $in['text'], (string)($in['summary'] ?? ''), $index, $c->by, $key);
                $this->noteAgent($c, 'agent_added', $ch['id']);
                if ($index !== null) $this->noteAgent($c, 'agent_order', null);
                return ['Chapter '.$ch['number'].' "'.$ch['title'].'" saved ('.Stories::words($ch['text']).' words).', t('Wrote chapter {n} · “{title}” ({words} words)', ['n'=>$ch['number'], 'title'=>$ch['title'], 'words'=>self::words($ch['text'])])];
            }
            case 'append_to_chapter': {
                $ch = $this->chapterAt($c, $in['chapter']);
                $added = trim(Stories::cleanText($in['text'], Stories::MAX_TEXT, 'The text'));
                if ($added === '') throw new HttpError(422, 'text is empty.');
                $new = $s->updateChapter($id, $ch['id'], ['text'=>rtrim($ch['text'])."\n\n".$added], $c->by, $key);
                return ['Added '.Stories::words($added).' words to chapter '.$ch['number'].' (now '.Stories::words($new['text']).' words).', t('Continued chapter {n} (+{words} words)', ['n'=>$ch['number'], 'words'=>self::words($added)])];
            }
            case 'edit_chapter': {
                $ch = $this->chapterAt($c, $in['chapter']);
                $change = array_intersect_key($in, array_flip(['edits', 'title', 'summary']));
                if (!$change) throw new HttpError(422, 'Nothing to change: give edits, title or summary.');
                $new = $s->updateChapter($id, $ch['id'], $change, $c->by, $key);
                $what = isset($in['edits']) && $in['edits'] ? (count($in['edits']) === 1 ? t('Revised chapter {n} (1 passage)', ['n'=>$ch['number']]) : t('Revised chapter {n} ({count} passages)', ['n'=>$ch['number'], 'count'=>count($in['edits'])]))
                    : (isset($in['title']) ? t('Updated the title of chapter {n}', ['n'=>$ch['number']]) : t('Updated the summary of chapter {n}', ['n'=>$ch['number']]));
                return ['Chapter '.$ch['number'].' updated ('.Stories::words($new['text']).' words).', $what];
            }
            case 'rewrite_chapter': {
                $ch = $this->chapterAt($c, $in['chapter']);
                $new = $s->updateChapter($id, $ch['id'], array_intersect_key($in, array_flip(['text', 'title', 'summary'])), $c->by, $key);
                return ['Chapter '.$ch['number'].' rewritten ('.Stories::words($new['text']).' words).', t('Rewrote chapter {n} ({words} words)', ['n'=>$ch['number'], 'words'=>self::words($new['text'])])];
            }
            case 'delete_chapter': {
                $ch = $this->chapterAt($c, $in['chapter']);
                $s->deleteChapter($id, $ch['id']); $this->noteAgent($c, 'agent_deleted', $ch['id']); $this->noteAgent($c, 'agent_order', null);
                return ['Chapter '.$ch['number'].' "'.$ch['title'].'" deleted. The chapters after it moved up by one.', t('Deleted chapter {n} · “{title}”', ['n'=>$ch['number'], 'title'=>$ch['title']])];
            }
            case 'move_chapter': {
                $ch = $this->chapterAt($c, $in['chapter']);
                $s->moveChapter($id, $ch['id'], $in['to'] - 1, $key); $this->noteAgent($c, 'agent_order', null);
                $titles = array_map(fn($x) => $x['title'], $s->chapters($id, $key));
                return ['Moved. Order now: '.implode('; ', array_map(fn($t, $n) => ($n + 1).'. '.$t, $titles, array_keys($titles))), t('Moved “{title}” to position {to}', ['title'=>$ch['title'], 'to'=>$in['to']])];
            }
            case 'read_notes': {
                if ($this->comicSpace($in, $c)) {
                    $d = $s->readDocument($id, 'comic_notes', $key);
                    return ['Adaptation bible, revision '.$d['rev'].":\n".($d['text'] !== '' ? $d['text'] : '(empty)'), t('Read the adaptation\'s bible')];
                }
                $d = $s->readDocument($id, 'notes', $key);
                return ['Story bible, revision '.$d['rev'].":\n".($d['text'] !== '' ? $d['text'] : '(empty)'), t('Read the story bible')];
            }
            case 'read_plan': {
                if ($this->comicSpace($in, $c)) {
                    $d = $s->readDocument($id, 'comic_plan', $key);
                    return ['Adaptation plan, revision '.$d['rev'].":\n".($d['text'] !== '' ? $d['text'] : '(empty)'), t('Looked up the adaptation\'s plan')];
                }
                $d = $s->readDocument($id, 'plan', $key);
                return ['Plan, revision '.$d['rev'].":\n".($d['text'] !== '' ? $d['text'] : '(empty)'), t('Looked up the plan')];
            }
            case 'update_notes': case 'update_plan': {
                $comic = $this->comicSpace($in, $c);
                $doc = ($comic ? 'comic_' : '').($name === 'update_notes' ? 'notes' : 'plan');
                if (!isset($in['text']) && !isset($in['append']) && empty($in['edits'])) throw new HttpError(422, 'Give text, append or edits.');
                $d = $s->writeDocument($id, $doc, array_intersect_key($in, array_flip(['text', 'append', 'edits'])), $c->by, $key);
                return [($comic ? 'Adaptation '.($name === 'update_notes' ? 'bible' : 'plan') : ($name === 'update_notes' ? 'Bible' : 'Plan')).' saved (revision '.$d['rev'].', '.Stories::words($d['text']).' words).',
                    ['notes'=>t('Updated the story bible'), 'plan'=>t('Updated the plan (hidden)'), 'comic_notes'=>t('Updated the adaptation\'s bible'), 'comic_plan'=>t('Updated the adaptation\'s plan (hidden)')][$doc]];
            }
            case 'update_reader_notes': {
                if (!isset($in['text']) && !isset($in['append']) && empty($in['edits'])) throw new HttpError(422, 'Give text, append or edits.');
                $notes = $this->vault->changeReaderNotes(array_intersect_key($in, array_flip(['text', 'append', 'edits'])), $c->by, $id, $key);
                return ['Your notes on the person as a reader saved ('.Stories::words($notes).' words). Tell them in your reply what you noted.', t('Updated the notes on you as a reader')];
            }
            case 'update_story': {
                $fields = array_intersect_key($in, array_flip(['title', 'synopsis', 'series']));
                if (!$fields) throw new HttpError(422, 'Nothing to change.');
                $s->update($id, $fields, $key); $this->noteAgent($c, 'agent_details', null);
                return ['Story details saved.', isset($fields['title']) ? t('Changed the title to “{title}”', ['title'=>Stories::cleanLine($fields['title'], 200, 'title')]) : t('Updated the synopsis')];
            }
            case 'search_story': {
                $query = trim($in['query']);
                if (mb_strlen($query) < 2) throw new HttpError(422, 'query must have at least 2 characters.');
                $found = []; $count = 0;
                $look = function (string $where, string $text) use ($query, &$found, &$count): void {
                    $offset = 0;
                    while (($at = mb_stripos($text, $query, $offset, 'UTF-8')) !== false) {
                        $count++;
                        if (count($found) < 30) {
                            $start = max(0, $at - 160); $snippet = mb_substr($text, $start, mb_strlen($query) + 320);
                            $found[] = "[$where] ".($start > 0 ? '…' : '').str_replace("\n", ' ', $snippet).'…';
                        }
                        $offset = $at + mb_strlen($query);
                    }
                };
                foreach ($s->chapters($id, $key) as $n => $ch) $look('chapter '.($n + 1), $ch['title']."\n".$ch['text']);
                $look('bible', $s->readDocument($id, 'notes', $key)['text']);
                $look('plan', $s->readDocument($id, 'plan', $key)['text']);
                $look('adaptation bible', $s->readDocument($id, 'comic_notes', $key)['text']);
                $look('adaptation plan', $s->readDocument($id, 'comic_plan', $key)['text']);
                return [$count ? "$count match".($count > 1 ? 'es' : '').($count > 30 ? ' (first 30 shown)' : '').":\n".implode("\n", $found) : 'No matches for "'.$query.'".', t('Checked a detail in the story')];
            }
            case 'find_repetitions': {
                $texts = array_column($s->chapters($id, $key), 'text');
                if (!$texts) return ['The story has no chapters yet.', t('Searched for repeated phrases')];
                $n = isset($in['chapter']) ? (int)$in['chapter'] : null;
                if ($n !== null) $this->chapterAt($c, $n);
                return [Repetitions::text(Repetitions::find($texts, $n), $n), $n !== null ? t('Searched chapter {n} for repeated phrases', ['n'=>$n]) : t('Searched the book for repeated phrases')];
            }
            case 'review_book': return $this->reviewBook($in, $c);
            case 'illustrate': return $this->illustrate($in, $c);
            case 'character_sheet': return $this->characterSheet($in, $c);
            case 'update_character': return $this->updateCharacter($in, $c);
            case 'set_style': {
                $sample = ($in['make_lettering_sample'] ?? false) === true;
                $comic = $this->comicSpace($in, $c);
                if (!isset($in['text']) && empty($in['add_images']) && empty($in['remove_images']) && !isset($in['lettering']) && !isset($in['lettering_image']) && !$sample)
                    throw new HttpError(422, 'Give text, add_images, remove_images, lettering, lettering_image or make_lettering_sample.');
                $add = [];
                foreach ($in['add_images'] ?? [] as $a) $add[] = ['image'=>$this->referenceIds([(string)$a['image_id']], $key, $c->tick)[0]['id'], 'caption'=>(string)($a['caption'] ?? '')];
                $remove = array_map(fn($r) => trim((string)$r), $in['remove_images'] ?? []);
                $change = ['lettering'=>$in['lettering'] ?? null];
                // The comic's style is its settings' (as comic_settings sets it); its lettering, its own sheets'.
                if ($comic && (isset($in['text']) || $add || $remove)) $s->comics()->setSettings($id, array_filter(['style_text'=>$in['text'] ?? null, 'style_add'=>$add, 'style_remove'=>$remove], fn($v) => $v !== null && $v !== []), $c->by, $key);
                if (!$comic) $change += ['text'=>$in['text'] ?? null, 'add'=>$add, 'remove'=>$remove];
                if (isset($in['lettering_image'])) $change['lettering_image'] = trim($in['lettering_image']) === '' ? null : $this->referenceIds([trim($in['lettering_image'])], $key, $c->tick)[0]['id'];
                $cast = !$comic || isset($in['lettering']) || array_key_exists('lettering_image', $change) ? $s->updateStyle($id, $change, $c->by, $key, $comic ? 'comic' : 'story') : $s->cast($id, $key, 'comic');
                $made = '';
                if ($sample) {
                    $after = $this->imageBudget($c);
                    $job = $this->letteringSample($id, $c->writer, $c->by, $key, $comic ? 'comic' : 'story');
                    $c->jobs[] = $job['id']; $cast = $s->cast($id, $key, $comic ? 'comic' : 'story');
                    $made = ' A lettering sample was requested (request '.$job['id'].'); it goes with '.($comic ? 'every page' : 'pictures made with lettering: true').' once ready.'.self::leftLine($after);
                }
                if ($comic) {
                    $outline = $s->comics()->outline($id, $key);
                    $lettering = explode("\n", self::castLines($cast, [], true))[0];
                    return ["The comic's style and lettering saved.\n".explode("\n", $outline)[1]."\n".$lettering.$made,
                        $sample ? t('Asked for a lettering sample of the adaptation') : (isset($in['text']) ? t('Set the adaptation\'s style') : (isset($in['lettering']) || isset($in['lettering_image']) ? t('Set the adaptation\'s lettering') : t('Updated the adaptation\'s style images')))];
                }
                [$style, $lettering] = explode("\n", self::castLines($cast));
                return ["Visual style saved.\n".$style."\n".$lettering.$made,
                    $sample ? t('Asked for a lettering sample of the story') : (isset($in['text']) ? t('Set the story\'s visual style') : (isset($in['lettering']) || isset($in['lettering_image']) ? t('Set the story\'s lettering') : t('Updated the story\'s style images')))];
            }
            case 'edit_illustration': return $this->editIllustration($in, $c);
            case 'comic_settings': case 'read_comic': case 'write_comic_page': case 'edit_comic_page': case 'view_comic_page': case 'draw_comic_page': case 'delete_comic_page': case 'move_comic_page':
                return $this->comicTool($name, $in, $c);
            case 'view_image': {
                $imageId = trim($in['image_id']);
                if (!preg_match('/\A[a-f0-9]{32}\z/D', $imageId)) throw new HttpError(422, 'image_id must be 32 hex characters (the ID in img:ID).');
                $item = $this->vault->still($imageId, $key);
                $picture = $this->vault->modelPreview($item, $key);
                $title = trim((string)($item['label'] ?? $item['title'] ?? ''));
                $about = 'Image '.$imageId.($title !== '' ? ' "'.mb_substr($title, 0, 120).'"' : '').', '.$item['width'].'x'.$item['height'].'.';
                return [[['type'=>'text', 'text'=>$about], ['type'=>'image', 'source'=>['type'=>'base64', 'media_type'=>$picture['mime'], 'data'=>$picture['data']]]], t('Looked at an image')];
            }
            case 'search_gallery': {
                $page = $this->vault->gallery($key, mb_substr(trim($in['query']), 0, 200), false, false, 0, 12, false, 'image');
                if (!$page['items']) return ['No images match "'.$in['query'].'".', t('Searched the gallery for images')];
                $lines = array_map(fn($it) => $it['id'].' - '.($it['title'] !== '' ? '"'.$it['title'].'" - ' : '').str_replace("\n", ' ', mb_substr($it['prompt'], 0, 240)), $page['items']);
                return [$page['total'].' image'.($page['total'] > 1 ? 's' : '').' match'.($page['total'] > 12 ? ' (12 newest shown)' : '').":\n".implode("\n", $lines), t('Searched the gallery for images')];
            }
        }
        throw new HttpError(422, "Unknown tool $name.");
    }

    /**
     * Before an image request of the writer: the key, the credit and the limit per turn. Returns the credit estimated
     * to be left after it (null: unknown).
     */
    private function imageBudget(ToolContext $c, int $count = 1): ?float
    {
        if (count($c->jobs) + $count > 30) throw new HttpError(422, 'Too many illustrations in one turn (30). Ask the person before making more.');
        $gemini = Images::provider($c->writer['image_model']) === 'gemini'; [$payer, $where] = self::imagePayer($c->writer); $kind = $gemini ? 'gemini-credit' : 'credit';
        if (!Images::hasKey($this->vault, $c->key, $c->writer['image_model']))
            throw new HttpError(422, 'The app has no '.($gemini ? 'Gemini' : 'OpenAI').' key, so no illustration can be made with '.Images::model($c->writer['image_model'])['label'].'. Nothing was requested. Tell the person: the key goes in Settings (or choose another image model in the writer settings).', $kind);
        // An illustration of this turn that already failed for lack of credit: the next one would too.
        if ($c->jobs) foreach ($this->jobs->list($c->key, 'active', $c->jobs) as $j) {
            if (in_array($j['id'], $c->jobs, true) && ($j['error_kind'] ?? null) === $kind)
                throw new HttpError(422, $payer.' has no credit left: an illustration of this turn already failed for that. Nothing was requested. Don\'t ask for more; tell the person, who can add credit at '.$where.'.', $kind);
        }
        // The balance of the account that pays for the pictures (OpenAI's or Google's), as the page estimates it.
        $credit = $c->credit;
        if ($credit['balance'] === null || $credit['image'] === null || $credit['image'] <= 0) return null;
        $left = $credit['balance'] - count($c->jobs) * $credit['image'];
        if ($left < $credit['image']) throw new HttpError(422, 'Not enough '.$payer.' credit for an illustration: about US$ '.number_format(max(0, $left), 2, '.', ',')
            .' left, and one costs about US$ '.number_format($credit['image'], 2, '.', ',').' (the app\'s estimate). Nothing was requested. Don\'t ask for more illustrations; tell the person, who can add credit at '.$where.' and correct the balance in the app.', $kind);
        if ($left < $count * $credit['image']) throw new HttpError(422, 'Not enough '.$payer.' credit for '.$count.' covers: about US$ '.number_format(max(0, $left), 2, '.', ',')
            .' left, and each costs about US$ '.number_format($credit['image'], 2, '.', ',').' (the app\'s estimate). Nothing was requested. Ask for fewer (variants) or tell the person, who can add credit at '.$where.'.', $kind);
        return $left - $count * $credit['image'];
    }

    private static function leftLine(?float $after): string
    {
        return $after === null ? '' : ' About US$ '.number_format(max(0, $after), 2, '.', ',').' of credit for pictures left after it (estimate).';
    }

    /** For tests: the image API used when a tool makes a reference picture that no process has started yet. */
    private ?ImageClient $imageApi = null;
    public function withImageApi(?ImageClient $api): self { $copy = clone $this; $copy->imageApi = $api; return $copy; }

    /** How long a tool waits for a reference picture still being made (the turn runs in a process of its own). */
    public const WAIT_FOR_REFERENCE = 420;

    /**
     * For the tools used over MCP, in a web request the host stops after a while: a reference picture is waited for
     * only until $until, and one no process has taken is left to the separate process (or to the page) unless $run.
     */
    private ?int $waitUntil = null;
    private bool $runReferences = true;
    public function forRequest(int $until, bool $run): self { $copy = clone $this; $copy->waitUntil = $until; $copy->runReferences = $run; return $copy; }

    /**
     * The references the writer passed: image ids, or ids of requests (a character sheet asked for a moment ago). A
     * request still in line is made here, and one another process is making is waited for, so its picture can be used
     * at once. $wait is called while waiting (the turn's heartbeat) and says whether the person stopped the writer.
     * @return list<array{id:string}>
     */
    private function referenceIds(array $ids, string $key, ?\Closure $wait = null): array
    {
        $ids = array_values(array_unique(array_map(fn($r) => trim((string)$r), $ids)));
        foreach ($ids as $id) if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) throw new HttpError(422, 'Each reference must be an image id or a request id (32 hex characters).');
        $out = [];
        foreach ($ids as $id) { $image = $this->readyImage($id, $key, $wait); $out[$image] = ['id'=>$image]; }
        return array_values($out);
    }

    private function readyImage(string $id, string $key, ?\Closure $wait): string
    {
        try { return $this->vault->still($id, $key)['id']; }
        catch (HttpError $e) { if ($e->status !== 404) throw $e; }
        $job = $this->jobs->freshJob($id, $key);
        if ($job === null) throw new HttpError(422, "Reference $id is neither an image nor a request of this account. Nothing was requested. Use the ids in img:ID or in the character sheets.");
        $deadline = time() + self::WAIT_FOR_REFERENCE;
        $cut = $this->waitUntil !== null && $this->waitUntil < $deadline;
        if ($cut) $deadline = $this->waitUntil;
        $keepalive = $wait === null ? null : static function () use ($wait): void { $wait(); };
        while (in_array($job['status'], self::ACTIVE, true)) {
            if ($wait !== null && $wait()) throw new Cancelled();
            if (time() >= $deadline) throw $cut
                ? new HttpError(422, "The picture of request $id is still being made. Nothing was requested. It keeps being made on the server: call this tool again in a minute or two (check_requests shows how it goes), or leave it out of references.", 'wait')
                : new HttpError(422, "The picture of request $id is still not ready after ".self::duration(self::WAIT_FOR_REFERENCE).'. Nothing was requested. Leave it out of references (describe the character in words) or try again later.');
            // One no process has taken (the page that would start it may be closed) is made here; one being made elsewhere is waited for.
            if ($this->runReferences) $job = $this->jobs->run($id, $key, $this->imageApi, $keepalive);
            elseif ($keepalive !== null) $keepalive();
            if (in_array($job['status'], self::ACTIVE, true)) { usleep(1500000); $job = $this->jobs->freshJob($id, $key) ?? $job; }
        }
        if ($job['status'] !== 'completed' || !$job['image_ids'])
            throw new HttpError(422, "The picture of request $id didn't come out".($job['error'] ? ' ('.mb_substr((string)$job['error'], 0, 200).')' : '').'. Nothing was requested. Leave it out of references, or make it again.', in_array($job['error_kind'] ?? null, Images::CREDIT_KINDS, true) ? $job['error_kind'] : null);
        return $this->vault->still($job['image_ids'][0], $key)['id'];
    }

    /**
     * The size asked of the image API for an aspect ratio, with the story's image model and size: the usual one, or
     * 1.5 or 2 times it within the API's limits (as Ampliar computes them).
     */
    public static function sizeFor(array $writer, string $aspect): string
    {
        $model = $writer['image_model'];
        if (!Images::model($model)['flex']) return $aspect === '1:1' ? '1024x1024' : (in_array($aspect, ['2:3', '3:4', '9:16', '4:5'], true) ? '1024x1536' : '1536x1024');
        $base = OpenAI::SIZES[$aspect];
        if (($writer['image_scale'] ?? '1') === '1') return $base;
        [$width, $height] = array_map('intval', explode('x', $base));
        $sizes = Mcp::upscaleSizes($width, $height);
        $size = $sizes[$writer['image_scale']] ?? $sizes['max'] ?? null;
        return $size ? $size[0].'x'.$size[1] : $base;
    }

    /**
     * A new picture for the story with its image settings, into its album, with the story's visual style: its
     * description after the prompt and its pictures after the references given (so @1, @2… keep naming those).
     * Returns the request, as Jobs::create does.
     */
    private function imageRequest(string $storyId, array $writer, string $prompt, string $label, string $aspect, array $references, string $key, bool $lettered = false, ?array $style = null, array $extra = []): array
    { return $this->imageRequests($storyId, $writer, $prompt, $label, $aspect, $references, $key, $lettered, $style, $extra)[0]; }

    /**
     * One request of the story's pictures (see imageRequest), or several from one prompt with $extra['lines']: one image
     * for each line, the line its direction (a single line goes at the end of the prompt). Returns every request made.
     */
    private function imageRequests(string $storyId, array $writer, string $prompt, string $label, string $aspect, array $references, string $key, bool $lettered = false, ?array $style = null, array $extra = []): array
    {
        $style ??= $this->stories->cast($storyId, $key)['style'];
        $sample = $lettered && is_string($style['lettering']['image']) && !in_array($style['lettering']['image'], array_column($references, 'id'), true) ? $style['lettering']['image'] : null;
        $looks = array_values(array_diff(array_filter(array_map(fn($im) => $im['image'] ?? null, $style['images']), 'is_string'), array_column($references, 'id'), [$sample]));
        $looks = array_slice($looks, 0, max(0, Images::maxImages($writer['image_model']) - count($references) - ($sample !== null ? 1 : 0)));
        // The style's pictures go after the ones given (so @1, @2… keep naming those), the lettering sample last.
        $first = count($references) + 1;
        if ($style['text'] !== '' || $looks) {
            $prompt .= "\n\nThe story's visual style, the same in all of its pictures".($style['text'] !== '' ? ': '.$style['text'] : '.')
                .($looks ? (count($looks) === 1 ? ' Attached image '.$first.' is a style reference' : ' Attached images '.$first.' to '.($first + count($looks) - 1).' are style references').': match their look (medium, line, color, light, rendering), not what they show.' : '');
            foreach ($looks as $look) $references[] = ['id'=>$look];
        }
        if ($lettered && ($style['lettering']['text'] !== '' || $sample !== null)) {
            $prompt .= "\n\nLettering: every text drawn in this picture (titles, signs, speech balloons, captions, sound effects) uses the story's lettering, the same in all of its pictures"
                .($style['lettering']['text'] !== '' ? ': '.$style['lettering']['text'] : '.')
                .($sample !== null ? ' Attached image '.(count($references) + 1).' is the lettering reference: use exactly its typeface and lettering style, at the size and with the expression each text needs.' : '')
                .' Spell every word exactly as given.';
            if ($sample !== null) $references[] = ['id'=>$sample];
        }
        // $extra: options of its own (a sprite's transparent background), the direction of each image, the album it goes
        // into (space: the story's, book, or its comic adaptation's, comic).
        $lines = array_values($extra['lines'] ?? []);
        if (count($lines) === 1) $prompt .= "\n\nDirection for this image: ".$lines[0];
        return $this->jobs->create(['id'=>self::newId(), 'prompt'=>$prompt, 'label'=>mb_substr($label, 0, 200),
            'options'=>($extra['options'] ?? []) + ['model'=>$writer['image_model'], 'quality'=>$writer['image_quality'], 'size'=>self::sizeFor($writer, $aspect), 'thinking'=>'off', 'n'=>1, 'previews'=>1],
            'references'=>$references, 'album'=>$this->stories->album($storyId, $key, $extra['space'] ?? 'book')] + (count($lines) > 1 ? ['lines'=>$lines] : []), $key);
    }

    private function illustrate(array $in, ToolContext $c): array
    {
        $s = $this->stories; $key = $c->key;
        $cover = ($in['cover'] ?? false) === true;
        if (!$cover && !isset($in['chapter'])) throw new HttpError(422, 'Give the chapter the image goes in, or cover: true.');
        // A cover is several covers, one in each design direction, to choose from.
        $styles = $cover ? self::coverStyles($in['cover_styles'] ?? null, $in['variants'] ?? null) : [];
        $after = $this->imageBudget($c, max(1, count($styles)));
        $ch = $cover ? null : $this->chapterAt($c, $in['chapter']);
        $caption = Stories::caption(Stories::cleanLine($in['caption'], 200, 'caption'));
        $aspect = $in['aspect'] ?? ($cover ? '2:3' : '3:2');
        // Where it goes is checked before the request is made: a wrong snippet must not cost an image.
        if ($ch !== null) Stories::insertIllustration($ch['text'], '![x](job:'.str_repeat('0', 32).')', $in['after_text'] ?? null, ($in['at_start'] ?? false) === true);
        $references = $this->referenceIds($in['references'] ?? [], $key, $c->tick);
        if ($cover) {
            // The cover's format and its words: the app's own, so every cover looks like one of its format.
            $story = $s->get($c->storyId, $key);
            $format = $in['cover_format'] ?? $story['cover_design']['format'];
            $lettered = ($in['lettering'] ?? true) !== false;
            $prompt = $this->coverPrompt($story, $format, trim($in['prompt']), false, $lettered, true, count($styles));
            $made = $this->imageRequests($c->storyId, $c->writer, $prompt, $caption !== '' ? $caption : 'Capa', $aspect, $references, $key, false, $this->coverStyle($c->storyId, $format, $key),
                ['lines'=>self::coverLines($styles)]);
            foreach ($made as $job) $c->jobs[] = $job['id'];
            $s->setCoverJobs($c->storyId, array_map(fn($job, $style) => ['job'=>$job['id'], 'style'=>$style], $made, $styles), $key, $c->by);
            $ids = array_column($made, 'id');
            return [(count($made) === 1 ? 'Cover requested (request '.$ids[0].')' : count($made).' covers requested (requests '.implode(', ', $ids).')')
                .', designed as the front cover of '.self::COVER_NAMES[$format].' in '.(count($styles) === 1 ? 'the '.$styles[0].' direction' : 'the directions '.implode(', ', $styles))
                .($lettered ? ', lettered with '.implode(', ', self::coverWords($story, $format)) : ', with no text').'. The first one ready becomes the cover; the person can choose another in the cover window.'.self::leftLine($after),
                (count($made) === 1 ? t('Asked for a cover') : t('Asked for {n} covers to choose from', ['n'=>count($made)])).' · “'.$caption.'”'];
        }
        $jobId = $this->imageRequest($c->storyId, $c->writer, trim($in['prompt']), $caption !== '' ? $caption : t('Illustration'), $aspect, $references, $key, ($in['lettering'] ?? false) === true)['id'];
        $c->jobs[] = $jobId;
        $left = self::leftLine($after);
        $fresh = $s->chapter($c->storyId, $ch['id'], $key);
        $text = Stories::insertIllustration($fresh['text'], '!['.$caption.'](job:'.$jobId.')', $in['after_text'] ?? null, ($in['at_start'] ?? false) === true);
        $s->updateChapter($c->storyId, $ch['id'], ['text'=>$text], $c->by, $key);
        return ['Illustration requested for chapter '.$ch['number'].' (request '.$jobId.'); the line ![caption](job:'.$jobId.') marks its place and becomes img:ID when the image is ready.'.$left,
            t('Asked for an illustration for chapter {n} · “{caption}”', ['n'=>$ch['number'], 'caption'=>$caption])];
    }

    /** A reference picture of a character, added to the character sheets (the character too, when it is new). */
    private function characterSheet(array $in, ToolContext $c): array
    {
        $key = $c->key;
        $name = mb_substr(Stories::cleanLine($in['character'], 120, 'character'), 0, 80);
        if ($name === '') throw new HttpError(422, 'Give the character\'s name.');
        $prompt = trim($in['prompt']);
        if ($prompt === '') throw new HttpError(422, 'prompt is empty.');
        $caption = Stories::caption(Stories::cleanLine($in['caption'], 200, 'caption'));
        $comic = $this->comicSpace($in, $c); $space = $comic ? 'comic' : 'story';
        // What would refuse the picture once made is checked before it is paid for.
        $cast = $this->stories->cast($c->storyId, $key, $space);
        $n = Stories::findCharacter($cast['characters'], $name);
        if ($n === null && count($cast['characters']) >= Stories::MAX_CHARACTERS) throw new HttpError(422, ($comic ? 'The adaptation' : 'The story').' already has sheets for '.Stories::MAX_CHARACTERS.' characters. Nothing was requested.');
        if ($n !== null && count($cast['characters'][$n]['images']) >= Stories::MAX_SHEETS) throw new HttpError(422, $cast['characters'][$n]['name'].' already has '.Stories::MAX_SHEETS.' pictures. Nothing was requested. Remove one with update_character first.');
        if (isset($in['note'])) Stories::cleanLine($in['note'], 300, 'note');
        $after = $this->imageBudget($c);
        $references = $this->referenceIds($in['references'] ?? [], $key, $c->tick);
        $who = $n !== null ? $cast['characters'][$n]['name'] : $name;
        // The adaptation's sheets are drawn in the comic's look: its style, and its kind and color.
        if ($comic) $prompt .= "\n\nA reference picture for the story's ".Comics::KIND_NAMES[$this->stories->comics()->settings($c->storyId, $key)['kind']].' adaptation: drawn as its pages are, '
            .($this->stories->comics()->settings($c->storyId, $key)['color'] === 'bw' ? 'in black and white.' : 'in full color.');
        $jobId = $this->imageRequest($c->storyId, $c->writer, $prompt, ($comic ? t('Adaptation sheet of {name}', ['name'=>$who]) : t('Sheet of {name}', ['name'=>$who])).($caption !== '' ? ' · '.$caption : ''), $in['aspect'] ?? '3:2', $references, $key, false,
            $comic ? $this->stories->comics()->style($c->storyId, $key) : null, ['space'=>$comic ? 'comic' : 'book'])['id'];
        $c->jobs[] = $jobId;
        $done = $this->stories->updateCast($c->storyId, ['character'=>$who, 'kind'=>$n === null ? ($in['kind'] ?? 'character') : null, 'note'=>$in['note'] ?? null, 'add'=>[['job'=>$jobId, 'caption'=>$caption]]], $c->by, $key, $space);
        $who = $done['character']['name'];
        $sheets = $comic ? "the adaptation's sheets" : 'the sheets';
        return [($n === null ? 'Added '.$who.' to '.$sheets.', with a picture' : 'A new picture for '.($comic ? "the adaptation's sheet" : 'the sheet').' of '.$who).' requested (request '.$jobId.'). It is ready in a minute or two, then '.$sheets.' show its image id; in this turn the request id already works in references.'.self::leftLine($after),
            ($n === null ? ($comic ? t('Made the adaptation sheet of {name}', ['name'=>$who]) : t('Made the sheet of {name}', ['name'=>$who]))
                : ($comic ? t('Asked for a new image of the adaptation sheet of {name}', ['name'=>$who]) : t('Asked for a new image of the sheet of {name}', ['name'=>$who]))).($caption !== '' ? ' · “'.$caption.'”' : '')];
    }

    /** Changes the character sheets without making pictures. */
    private function updateCharacter(array $in, ToolContext $c): array
    {
        $comic = $this->comicSpace($in, $c); $space = $comic ? 'comic' : 'story';
        $fromBook = ($in['from_book'] ?? false) === true;
        if ($fromBook && !$comic) throw new HttpError(422, 'from_book goes with comic: true: it brings the book\'s sheet into the adaptation\'s sheets.');
        if ($fromBook && ($in['delete'] ?? false) === true) throw new HttpError(422, 'Give from_book or delete, not both.');
        $before = $this->stories->cast($c->storyId, $c->key, $space);
        $existed = Stories::findCharacter($before['characters'], (string)$in['character']);
        $change = ['character'=>$in['character'], 'kind'=>$in['kind'] ?? null, 'new_name'=>$in['new_name'] ?? null, 'note'=>$in['note'] ?? null, 'delete'=>($in['delete'] ?? false) === true,
            'add'=>array_map(fn($a) => ['image'=>trim((string)$a['image_id']), 'caption'=>(string)($a['caption'] ?? '')], $in['add_images'] ?? []),
            'captions'=>array_column(array_map(fn($a) => [trim((string)$a['image_id']), (string)$a['caption']], $in['captions'] ?? []), 1, 0),
            'remove'=>array_map(fn($r) => trim((string)$r), $in['remove_images'] ?? []), 'main'=>isset($in['main_image']) ? trim($in['main_image']) : null];
        $brought = '';
        if ($fromBook) {
            // The book's sheet first, then whatever else was asked, on the adaptation's copy.
            $done = $this->stories->sheetFromBook($c->storyId, (string)$in['character'], $c->by, $c->key);
            $change['character'] = $done['character']['id'];
            $brought = 'The book\'s sheet of '.$done['character']['name'].' is now in the adaptation\'s sheets ('.count($done['character']['images']).' picture'.(count($done['character']['images']) === 1 ? '' : 's').'). ';
            $more = $change['kind'] !== null || $change['new_name'] !== null || $change['note'] !== null || $change['add'] || $change['captions'] || $change['remove'] || $change['main'] !== null;
            if ($more) $done = $this->stories->updateCast($c->storyId, $change, $c->by, $c->key, $space);
        } else $done = $this->stories->updateCast($c->storyId, $change, $c->by, $c->key, $space);
        if ($done['character'] === null) {
            $name = $before['characters'][$existed]['name'];
            return [$name.' was removed from '.($comic ? "the adaptation's sheets" : 'the sheets').'; the pictures stay in the gallery.', $comic ? t('Removed {name} from the adaptation sheets', ['name'=>$name]) : t('Removed {name} from the sheets', ['name'=>$name])];
        }
        $name = $done['character']['name'];
        $lines = explode("\n", rtrim(self::castLines(['characters'=>[$done['character']]])));
        if ($fromBook) return [$brought."Sheet saved:\n".end($lines), t('Brought the sheet of {name} from the book to the adaptation', ['name'=>$name])];
        return [($comic ? "The adaptation's sheet saved:\n" : "Sheet saved:\n").end($lines), $existed === null ? ($comic ? t('Created the adaptation sheet of {name}', ['name'=>$name]) : t('Created the sheet of {name}', ['name'=>$name]))
            : ($comic ? t('Updated the adaptation sheet of {name}', ['name'=>$name]) : t('Updated the sheet of {name}', ['name'=>$name]))];
    }

    /**
     * Whether a tool works on the comic adaptation's own workspace (comic: true) instead of the book's; it needs an
     * adaptation set up, and a visual novel has none.
     */
    private function comicSpace(array $in, ToolContext $c): bool
    {
        if (($in['comic'] ?? false) !== true) return false;
        if ($this->isVnContext($c)) throw new HttpError(422, 'A visual novel project has no comic adaptation: leave comic out.');
        if (!$this->stories->comics()->settings($c->storyId, $c->key)['started']) throw new HttpError(422, 'The story has no comic adaptation yet: set it up with comic_settings first.');
        return true;
    }

    private int $depth = 0;
    private function transaction(callable $work): mixed
    {
        if ($this->depth > 0) return $work();
        $this->vault->db->exec('BEGIN IMMEDIATE'); $this->depth = 1;
        try { $result = $work(); $this->vault->db->exec('COMMIT'); return $result; }
        catch (\Throwable $e) { $this->vault->db->exec('ROLLBACK'); throw $e; }
        finally { $this->depth = 0; }
    }

    /** A new version of a picture of the story, by the writer: an edit, Fotorrealista, Melhorar or Ampliar, as in the edit panel. */
    private function editIllustration(array $in, ToolContext $c): array
    {
        $key = $c->key;
        $imageId = trim((string)$in['image_id']);
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $imageId)) throw new HttpError(422, 'image_id must be 32 hex characters (the ID in img:ID).');
        $kind = $in['kind'] ?? 'edit';
        $instruction = trim((string)($in['instruction'] ?? ''));
        $caption = isset($in['caption']) ? Stories::caption(Stories::cleanLine($in['caption'], 200, 'caption')) : null;
        // What would refuse it is checked before the credit and before waiting for a reference.
        $this->versionOf($c->storyId, $imageId, $kind, $instruction, $c->by, $key);
        $after = $this->imageBudget($c);
        $references = $kind === 'upscale' ? [] : $this->referenceIds(array_diff($in['references'] ?? [], [$imageId]), $key, $c->tick);
        [$job, $where] = $this->newVersion($c->storyId, $c->writer, $imageId, $kind, $instruction, $references, $caption, $c->by, $key);
        $jobId = $job['id']; $c->jobs[] = $jobId;
        $what = ['edit'=>'An edited version', 'photoreal'=>'A photorealistic version', 'enhance'=>'An enhanced version', 'upscale'=>'A larger version ('.$job['options']['size'].')'][$kind];
        // Where the picture is, for the chat: the same places as for the model, in the interface's language.
        $places = array_map(fn($w) => match (true) {
            (bool)preg_match('/^chapter (\d+)$/', $w, $m) => t('chapter {n}', ['n'=>$m[1]]),
            $w === 'the cover' => t('cover', ['ctx'=>'place']),
            (bool)preg_match('/^the adaptation\'s sheet of (.+)$/s', $w, $m) => t('adaptation sheet of {name}', ['name'=>$m[1]]),
            (bool)preg_match('/^the sheet of (.+)$/s', $w, $m) => t('sheet of {name}', ['name'=>$m[1]]),
            (bool)preg_match('/^page (\d+) of the comic$/', $w, $m) => t('page {n} of the comics', ['n'=>$m[1]]),
            default => $w }, $where);
        $sheets = !array_filter($where, fn($w) => !preg_match('/^the (adaptation\'s )?sheet of /', $w));
        $of = $where === ['the cover'] ? 'cover' : (str_starts_with($where[0], 'page ') && count($where) === 1 ? 'page' : ($sheets ? 'image' : 'illustration'));
        $saying = [
            'edit'=>['cover'=>t('Asked for a new version of the cover'), 'page'=>t('Asked for a new version of the page'), 'image'=>t('Asked for a new version of the image'), 'illustration'=>t('Asked for a new version of the illustration')],
            'photoreal'=>['cover'=>t('Asked for the photorealistic version of the cover'), 'page'=>t('Asked for the photorealistic version of the page'), 'image'=>t('Asked for the photorealistic version of the image'), 'illustration'=>t('Asked for the photorealistic version of the illustration')],
            'enhance'=>['cover'=>t('Asked to enhance the cover'), 'page'=>t('Asked to enhance the page'), 'image'=>t('Asked to enhance the image'), 'illustration'=>t('Asked to enhance the illustration')],
            'upscale'=>['cover'=>t('Asked to upscale the cover'), 'page'=>t('Asked to upscale the page'), 'image'=>t('Asked to upscale the image'), 'illustration'=>t('Asked to upscale the illustration')],
        ][$kind][$of];
        return [$what.' of the picture in '.implode(' and ', $where).' was requested (request '.$jobId.'). The old one stays in place until it is ready, then the new one replaces it.'.self::leftLine($after),
            $saying.($caption ?? '' ? ' · “'.$caption.'”' : '').' ('.implode(', ', $places).')'];
    }

    /**
     * Where a picture of the story is (chapter N, the cover, the sheet of a character), checked for a new version of
     * it: it must be one of the story's pictures, with no new version already being made. $coverOnly: only as the cover.
     * @return list<string>
     */
    private function versionOf(string $storyId, string $imageId, string $kind, string $instruction, string $by, string $key, bool $coverOnly = false): array
    {
        $person = $by === 'person';
        if (!in_array($kind, ['edit', 'photoreal', 'enhance', 'upscale'], true)) throw new HttpError(422, $person ? t('Choose what to do with the cover.') : 'kind is edit, photoreal, enhance or upscale.');
        if ($kind === 'edit' && $instruction === '') throw new HttpError(422, $person ? t('Say what to change in the cover.') : 'Say what to change in instruction.');
        if (mb_strlen($instruction) > 4000) throw new HttpError(422, $person ? t('The instruction is over 4,000 characters.') : 'The instruction is too long (4,000 characters at most).');
        $story = $this->stories->get($storyId, $key);
        $where = [];
        if (!$coverOnly) {
            foreach ($story['chapters'] as $n => $ch) if (preg_match_all(Stories::MARKER, $ch['text'], $found, PREG_SET_ORDER))
                foreach ($found as $f) if ($f[2] === 'img' && $f[3] === $imageId) $where[] = 'chapter '.($n + 1);
            foreach ($story['cast']['characters'] as $ch) foreach ($ch['images'] as $im) if (($im['image'] ?? null) === $imageId) $where[] = 'the sheet of '.$ch['name'];
            if ($story['kind'] !== 'vn' && $this->stories->comics()->settings($storyId, $key)['started'])
                foreach ($this->stories->cast($storyId, $key, 'comic')['characters'] as $ch) foreach ($ch['images'] as $im) if (($im['image'] ?? null) === $imageId) $where[] = 'the adaptation\'s sheet of '.$ch['name'];
            foreach ($this->stories->comics()->pagesWith($storyId, $imageId, $key) as $n) $where[] = 'page '.$n.' of the comic';
            if ($story['kind'] === 'vn') array_push($where, ...$this->stories->vn()->artWith($storyId, $imageId, $key));
        }
        if ($story['cover_chosen'] && $story['cover'] === $imageId) $where[] = 'the cover';
        if (!$where) throw new HttpError(422, $person ? t('The image changed while you were editing. Reload.') : 'That image is not an illustration, the cover, a character sheet picture, a comic page or a visual novel sprite, background or CG of this story. To use a gallery image, pass it in references.');
        foreach ($story['editing'] as $jobId => $e) if ($e['image'] === $imageId && !$e['failed'])
            throw new HttpError(422, $person ? t('A new version of this image is already being made. Wait for it to be ready.') : 'A new version of this picture is already being made (request '.$jobId.'). Wait for it before changing it again.');
        return array_values(array_unique($where));
    }

    /**
     * A new version of a picture of the story (an illustration, the cover or a character sheet picture): an edit, or
     * Fotorrealista, Melhorar and Ampliar with the edit panel's instructions. It takes the old one's place when ready.
     * @return array{0:array,1:list<string>} the request (as Jobs::create returns it) and where the picture is
     */
    private function newVersion(string $storyId, array $writer, string $imageId, string $kind, string $instruction, array $references, ?string $caption, string $by, string $key, bool $coverOnly = false): array
    {
        $where = $this->versionOf($storyId, $imageId, $kind, $instruction, $by, $key, $coverOnly);
        $source = $this->vault->still($imageId, $key);
        $model = $writer['image_model']; $flex = Images::model($model)['flex'];
        $options = ['model'=>$model, 'quality'=>$writer['image_quality'], 'thinking'=>'off', 'n'=>1, 'previews'=>1];
        if (isset($source['options']['format'])) $options['format'] = $source['options']['format'];
        $person = $by === 'person';
        if ($kind === 'upscale') {
            if (!$flex) throw new HttpError(422, $person ? t('This story\'s image model does not upscale. Choose another one in the writer\'s settings.') : 'The story\'s image model can\'t upscale. The person can choose another one in the writer settings.');
            $sizes = Mcp::upscaleSizes((int)$source['width'], (int)$source['height']);
            if (!$sizes) throw new HttpError(422, $person ? t('This image is already at the largest size the API makes for its proportions.') : 'This picture is already at the largest size the image API makes for its proportions.');
            [$width, $height] = $sizes['2'] ?? $sizes['max'] ?? end($sizes);
            $options['size'] = $width.'x'.$height;
            $prompt = Presets::upscale($instruction);
            $label = t('Upscaled to {width} × {height}', ['width'=>$width, 'height'=>$height]);
            $operation = 'upscale'; $references = [];
        } else {
            $options['size'] = Mcp::editSize((int)$source['width'], (int)$source['height'], $flex);
            if ($kind === 'edit') { $prompt = $instruction; $label = $caption ?? ($coverOnly ? t('Cover edited') : t('Illustration edited')); }
            else { [$preset, $label] = Presets::EDITS[$kind]; $label = t($label); $prompt = Presets::withNotes($preset, $instruction); }
            $operation = 'edit';
        }
        if ($kind !== 'edit' && $instruction !== '') $label .= ': '.mb_substr($instruction, 0, 120);
        $references = array_merge([['id'=>$imageId]], array_values(array_filter($references, fn($r) => $r['id'] !== $imageId)));
        // A picture only of the comic (a page, a sheet of the adaptation) keeps its new version in the comic's album.
        $space = array_filter($where, fn($w) => !preg_match('/^(page \d+ of the comic|the adaptation\'s sheet of )/', $w)) ? 'book' : 'comic';
        $job = $this->jobs->create(['id'=>self::newId(), 'prompt'=>$prompt, 'label'=>$label, 'options'=>$options, 'references'=>$references,
            'parent_id'=>$imageId, 'operation'=>$operation, 'album'=>$this->stories->album($storyId, $key, $space)] + ($kind !== 'edit' ? ['typed'=>$person ? $instruction : ''] : []), $key)[0];
        $this->stories->startEdit($storyId, $job['id'], $imageId, $caption, $key, $by, $coverOnly);
        return [$job, $where];
    }

    // =================================================================================================================
    // The comic
    // =================================================================================================================

    /** The failed drawings of comic pages among the failed pictures of the story: request id => error. */
    private static function pageFailures(array $failed): array
    {
        $out = [];
        foreach ($failed as $jobId => $f) if (($f['for'] ?? null) === 'page') $out[$jobId] = (string)($f['error'] ?? '');
        return $out;
    }

    /** The tools of the comic adaptation. */
    private function comicTool(string $name, array $in, ToolContext $c): array
    {
        $comics = $this->stories->comics(); $id = $c->storyId; $key = $c->key;
        // The script fields of a tool's input, as the comic keeps them (the chapter by its id, the sketch's SVG).
        $script = function (array $in) use ($c): array {
            $out = array_intersect_key($in, array_flip(['summary', 'panels', 'rows', 'notes']));
            if (isset($in['chapter'])) $out['chapter'] = $this->chapterAt($c, $in['chapter'])['id'];
            if (array_key_exists('sketch_svg', $in)) $out['sketch'] = $in['sketch_svg'];
            return $out;
        };
        switch ($name) {
            case 'comic_settings': {
                $change = array_intersect_key($in, array_flip(['kind', 'reading', 'color', 'aspect', 'scale']));
                if (isset($in['style'])) $change['style_text'] = $in['style'];
                $change['style_add'] = array_map(fn($a) => ['image'=>$this->referenceIds([(string)$a['image_id']], $key, $c->tick)[0]['id'], 'caption'=>(string)($a['caption'] ?? '')], $in['style_add_images'] ?? []);
                $change['style_remove'] = array_map(fn($r) => trim((string)$r), $in['style_remove_images'] ?? []);
                $was = $comics->settings($id, $key)['started'];
                $settings = $comics->setSettings($id, $change, $c->by, $key);
                [$vw, $vh] = Sketch::units($settings['aspect']);
                return ['Comic settings saved: '.Comics::settingsLine($settings).' The sketches of its pages use viewBox="0 0 '.$vw.' '.$vh.'".'
                    .($settings['style']['text'] !== '' || $settings['style']['images'] ? ' Its own style: "'.$settings['style']['text'].'"'.($settings['style']['images'] ? ' with '.count($settings['style']['images']).' style image(s).' : '.') : ' It uses the story\'s visual style.'),
                    $was ? t('Changed the comics settings') : t('Started the comics adaptation')];
            }
            case 'read_comic': {
                $failed = self::pageFailures($this->failedIllustrations($id, $key));
                $out = $comics->outline($id, $key, $failed);
                foreach (array_values(array_unique($in['pages'] ?? [])) as $n) $out .= "\n".$comics->scriptText($id, $comics->pageAt($id, $n, $key), $key, $failed);
                return [$out, t('Looked up the comics')];
            }
            case 'write_comic_page': {
                $page = $comics->addPage($id, $script($in), isset($in['position']) ? $in['position'] - 1 : null, $c->by, $key);
                return [$this->pageAnswer('Page '.$page['number'].' saved.', $page, $c), count($page['panels']) === 1 ? t('Wrote page {n} of the comics (1 panel)', ['n'=>$page['number']]) : t('Wrote page {n} of the comics ({count} panels)', ['n'=>$page['number'], 'count'=>count($page['panels'])])];
            }
            case 'edit_comic_page': {
                $page = $comics->pageAt($id, $in['page'], $key);
                $change = $script($in);
                if (isset($in['panel']) || isset($in['panel_number'])) {
                    if (!isset($in['panel'], $in['panel_number'])) throw new HttpError(422, 'Give panel_number and panel together.');
                    if (isset($in['panels'])) throw new HttpError(422, 'Give panels (all of them) or panel_number with panel, not both.');
                    if ($in['panel_number'] > count($page['panels']) + 1) throw new HttpError(422, 'Page '.$page['number'].' has '.count($page['panels']).' panels: panel_number goes up to '.(count($page['panels']) + 1).' (one more adds a panel at the end).');
                    $panels = $page['panels']; $panels[$in['panel_number'] - 1] = $in['panel'];
                    $change['panels'] = array_values($panels);
                }
                if (!$change) throw new HttpError(422, 'Nothing to change: give chapter, summary, panels, panel_number with panel, sketch_svg, rows or notes.');
                // Rows that no longer add up to the panels go back to the default layout.
                if (isset($change['panels']) && !array_key_exists('rows', $change) && $page['rows'] && array_sum($page['rows']) !== count($change['panels'])) $change['rows'] = null;
                $page = $comics->updatePage($id, $page['id'], $change, $c->by, $key);
                $drawing = isset($change['panels']) || array_key_exists('sketch', $change) || array_key_exists('rows', $change);
                return [$this->pageAnswer('Page '.$page['number'].' changed.'.(is_string($page['image']) ? ' Its drawn image stays until you draw it again.' : ''), $page, $c, $drawing), t('Changed page {n} of the comics', ['n'=>$page['number']])];
            }
            case 'view_comic_page': {
                $page = $comics->pageAt($id, $in['page'], $key);
                $answer = $this->pageAnswer('', $page, $c, true, ($in['svg'] ?? false) === true);
                if (is_string($page['image'])) {
                    try {
                        $picture = $this->vault->modelPreview($this->vault->still($page['image'], $key), $key, 1280);
                        $answer[] = ['type'=>'text', 'text'=>'The drawn page (image '.$page['image'].'):'];
                        $answer[] = ['type'=>'image', 'source'=>['type'=>'base64', 'media_type'=>$picture['mime'], 'data'=>$picture['data']]];
                    } catch (HttpError) { $answer[] = ['type'=>'text', 'text'=>'The drawn page\'s image is no longer in the gallery.']; }
                }
                return [$answer, t('Looked at page {n} of the comics', ['n'=>$page['number']])];
            }
            case 'draw_comic_page': {
                $page = $comics->pageAt($id, $in['page'], $key);
                // What would refuse the drawing is checked before the credit.
                $this->pageCast($id, $page, $key, false);
                $after = $this->imageBudget($c);
                $job = $this->drawPage($id, $page['id'], ['instruction'=>$in['instruction'] ?? '', 'references'=>$in['references'] ?? [], 'previous'=>($in['previous'] ?? true) !== false], $c->by, $key, $c);
                $c->jobs[] = $job['id'];
                return ['Page '.$page['number'].' is being drawn (request '.$job['id'].', '.$job['options']['size'].'). It takes a few minutes; then the page shows it, and view_comic_page lets you look at it.'.self::leftLine($after),
                    t('Asked for the drawing of page {n} of the comics', ['n'=>$page['number']])];
            }
            case 'delete_comic_page': {
                $page = $comics->pageAt($id, $in['page'], $key);
                $comics->deletePage($id, $page['id'], $c->by, $key);
                return ['Page '.$page['number'].' deleted; the pages after it moved up one.', t('Deleted page {n} of the comics', ['n'=>$page['number']])];
            }
            case 'move_comic_page': {
                $page = $comics->pageAt($id, $in['page'], $key);
                $count = count($comics->pages($id, $key));
                if ($in['to'] > $count) throw new HttpError(422, 'There is no page '.$in['to'].' (the comic has '.$count.').');
                $comics->movePage($id, $page['id'], $in['to'] - 1, $c->by, $key);
                return ['Page '.$page['number'].' is now page '.$in['to'].'.', t('Moved page {n} to {to}', ['n'=>$page['number'], 'to'=>$in['to']])];
            }
        }
        throw new HttpError(422, "Unknown tool $name.");
    }

    /**
     * What the writer sees of a page it wrote or changed: the script, what the page still needs before it can be drawn
     * (sheets), and the sketch as the app drew it, which goes to the image model as it is.
     */
    private function pageAnswer(string $first, array $page, ToolContext $c, bool $sketch = true, bool $svg = false): string|array
    {
        $comics = $this->stories->comics(); $settings = $comics->settings($c->storyId, $c->key);
        $text = ($first !== '' ? $first."\n" : '').$comics->scriptText($c->storyId, $page, $c->key, self::pageFailures($this->failedIllustrations($c->storyId, $c->key)));
        $need = $this->pageCast($c->storyId, $page, $c->key, true);
        if ($need['missing']) $text .= "\nBefore it can be drawn, the adaptation's sheets need a picture of: ".implode(', ', $need['missing']).' (character_sheet with comic: true, kind place for a setting; or update_character with comic: true and from_book: true to bring in the book\'s sheet).';
        foreach ($need['wrong'] as $w) $text .= "\n".$w;
        if ($svg) $text .= "\n\nSketch SVG:\n".($page['sketch'] !== '' ? $page['sketch'] : '(none: the app draws it from the script)');
        if (!$sketch) return $text;
        [$w, $h] = Sketch::units($settings['aspect']);
        $png = Sketch::png(Comics::sketchSvg($page, $settings), 640, (int)round(640 * $h / $w));
        return [['type'=>'text', 'text'=>$text."\n\nThe sketch as the app drew it".($page['sketch'] === '' ? ' (its own, from the script)' : '').', as the image model gets it'.($first !== '' ? '; check it, and fix it with edit_comic_page if something is off' : '').':'],
            ['type'=>'image', 'source'=>['type'=>'base64', 'media_type'=>'image/png', 'data'=>base64_encode($png)]]];
    }

    /**
     * The sheet pictures a page is drawn with: one per character (the form the script names, else the main one) and one
     * per place, in order of appearance, each with the panels it is in. Characters and places without a sheet picture
     * are missing; a form that isn't in its character's sheet is wrong. With $report false, either one refuses.
     * @return array{characters:list<array>,places:list<array>,missing:list<string>,wrong:list<string>}
     */
    private function pageCast(string $storyId, array $page, string $key, bool $report): array
    {
        $cast = $this->stories->cast($storyId, $key, 'comic')['characters'];
        $out = ['characters'=>[], 'places'=>[], 'missing'=>[], 'wrong'=>[]];
        $pick = function (string $name, string $form, string $group, int $panel) use ($cast, &$out): void {
            $n = Stories::findCharacter($cast, $name);
            $sheet = $n === null ? null : $cast[$n];
            if ($sheet === null || !$sheet['images']) { $out['missing'][mb_strtolower($name)] = $name.($group === 'places' ? ' (place)' : ''); return; }
            $pic = $sheet['images'][0];
            if ($form !== '') {
                $found = null; foreach ($sheet['images'] as $im) if (($im['image'] ?? null) === $form || ($im['job'] ?? null) === $form) $found = $im;
                if ($found === null) { $out['wrong'][] = 'Panel '.$panel.': '.$form.' is not a picture of the adaptation\'s sheet of '.$sheet['name'].'.'; return; }
                $pic = $found;
            }
            $ref = $sheet['id'].':'.($pic['image'] ?? $pic['job']);
            $out[$group][$ref] ??= ['name'=>$sheet['name'], 'image'=>$pic['image'] ?? null, 'job'=>is_string($pic['image'] ?? null) ? null : ($pic['job'] ?? null), 'caption'=>(string)($pic['caption'] ?? ''), 'panels'=>[]];
            $out[$group][$ref]['panels'][] = $panel;
        };
        foreach ($page['panels'] as $k => $panel) {
            foreach ($panel['characters'] as $ch) $pick($ch['name'], $ch['sheet'], 'characters', $k + 1);
            if ($panel['place'] !== '') $pick($panel['place'], '', 'places', $k + 1);
        }
        $out['characters'] = array_values($out['characters']); $out['places'] = array_values($out['places']); $out['missing'] = array_values($out['missing']);
        if (!$report && ($out['missing'] || $out['wrong'])) throw new HttpError(422, $out['missing']
            ? 'Page '.$page['number'].' can\'t be drawn yet: '.implode(', ', $out['missing']).' ha'.(count($out['missing']) === 1 ? 's' : 've').' no picture in the adaptation\'s sheets. Make one first with character_sheet comic: true (kind place for a setting: a wide view without people), or bring in the book\'s sheet with update_character comic: true, from_book: true; then draw the page. An extra who never comes back goes in the panel\'s description instead of characters. Nothing was requested.'
            : implode(' ', $out['wrong']).' Use an id from the adaptation\'s sheet (get_outline lists them). Nothing was requested.');
        if (count($out['characters']) > 7) throw new HttpError(422, 'Page '.$page['number'].' shows '.count($out['characters']).' characters (or forms) with sheets; a page can be drawn with up to 7. Move some to another page, or describe minor ones in the description. Nothing was requested.');
        if (count($out['places']) > 3) throw new HttpError(422, 'Page '.$page['number'].' has '.count($out['places']).' places; a page can be drawn with up to 3. Nothing was requested.');
        return $out;
    }

    /**
     * The finished page, drawn in one image: the sketch as the composition guide (attached image 1), the script panel
     * by panel, the sheets of its characters and places, the previous page for continuity, other references, then the
     * adaptation's style and its own lettering (added by imageRequest). By the writer ($c: its turn, which waits
     * for pictures still being made) or by the person (who is told to wait instead). Returns the request.
     */
    /**
     * The adaptation for the story's page (Comics::view), each page with the characters and places of its script that
     * have no sheet picture yet ("O Porão (lugar)"): it can't be drawn until they have one.
     */
    public function comicView(string $storyId, string $key, bool $withPlan = false): array
    {
        $view = $this->stories->comics()->view($storyId, $key);
        if (!$view['settings']['started']) return $view;
        // Its own workspace: the bible, the plan (its text only when asked for, as the book's) and the sheets.
        foreach (['notes'=>'comic_notes', 'plan'=>'comic_plan'] as $field => $doc) {
            $d = $this->stories->readDocument($storyId, $doc, $key);
            $view[$field] = ['rev'=>$d['rev'], 'by'=>$d['by'], 'at'=>$d['at'], 'words'=>Stories::words($d['text'])] + ($field === 'notes' || $withPlan ? ['text'=>$d['text']] : []);
        }
        $view['cast'] = $this->stories->cast($storyId, $key, 'comic');
        if (!$view['pages']) return $view;
        $cast = $view['cast']['characters'];
        foreach ($view['pages'] as &$p) {
            $missing = [];
            foreach ($p['panels'] as $panel) foreach (array_merge(array_map(fn($ch) => [$ch['name'], ''], $panel['characters']), $panel['place'] !== '' ? [[$panel['place'], ' ('.t('place').')']] : []) as [$name, $tag]) {
                $n = Stories::findCharacter($cast, $name);
                if ($n === null || !$cast[$n]['images']) $missing[mb_strtolower($name)] = $name.$tag;
            }
            $p['missing'] = array_values($missing);
        }
        return $view;
    }

    public function drawPage(string $storyId, string $pageId, array $opts, string $by, string $key, ?ToolContext $c = null): array
    {
        $person = $by === 'person';
        $comics = $this->stories->comics();
        $settings = $comics->settings($storyId, $key);
        $page = $comics->page($storyId, $pageId, $key);
        $story = $this->stories->get($storyId, $key);
        $writer = $c?->writer ?? self::settings($story['writer']);
        if (is_string($page['job'])) {
            $running = $this->jobs->freshJob($page['job'], $key);
            if ($running !== null && in_array($running['status'], self::ACTIVE, true)) throw new HttpError(422, $person ? t('This page is already being drawn.') : 'Page '.$page['number'].' is already being drawn (request '.$page['job'].'). Wait for it.');
        }
        try { $need = $this->pageCast($storyId, $page, $key, false); }
        catch (HttpError $e) {
            if (!$person) throw $e;
            $need = $this->pageCast($storyId, $page, $key, true);
            if ($need['missing']) throw new HttpError(422, t('The sheet with an image is missing for: {names}. Each character and each setting of the page needs a reference image before drawing: ask the writer or add it in the adaptation sheets (Comics › Sheets).', ['names'=>implode(', ', str_replace(' (place)', ' ('.t('place').')', $need['missing']))]));
            throw new HttpError(422, t('A look chosen for a character is no longer on their sheet. Ask the writer to fix the page.'));
        }
        $instruction = trim(Stories::cleanText($opts['instruction'] ?? '', 4000, t('The instruction')));
        $wait = $c?->tick;
        // A sheet picture still being made: the writer's turn waits for it; the person is asked to wait.
        $ready = function (array $p, string $what) use ($person, $key, $wait): string {
            if (is_string($p['image'])) return $this->vault->still($p['image'], $key)['id'];
            if ($person) throw new HttpError(422, t('The image of the sheet of {name} is still being made. Wait for it to be ready to draw the page.', ['name'=>$what]));
            return $this->readyImage((string)$p['job'], $key, $wait);
        };
        $references = [['data'=>'data:image/png;base64,'.base64_encode(Comics::sketchPng($page, $settings))]];
        $at = 2; $people = []; $places = [];
        foreach ($need['characters'] as $p) { $references[] = ['id'=>$ready($p, $p['name'])]; $people[] = $p + ['at'=>$at++]; }
        foreach ($need['places'] as $p) { $references[] = ['id'=>$ready($p, $p['name'])]; $places[] = $p + ['at'=>$at++]; }
        // The previous page, for continuity: the one being drawn is waited for by the writer; else the image it has.
        $previous = null;
        if (($opts['previous'] ?? true) && $page['number'] > 1) {
            $prev = $comics->pageAt($storyId, $page['number'] - 1, $key); $image = $prev['image'];
            if (is_string($prev['job']) && !$person) {
                // Didn't come out: its earlier image, if any. Still being made when a request over MCP can't wait longer: try again later.
                try { $image = $this->readyImage($prev['job'], $key, $wait); } catch (HttpError $e) { if ($e->kind === 'wait') throw $e; }
            }
            if (is_string($image)) { try { $references[] = ['id'=>$this->vault->still($image, $key)['id']]; $previous = $at++; } catch (HttpError) {} }
        }
        $extra = array_slice(is_array($opts['references'] ?? null) ? array_values($opts['references']) : [], 0, 3);
        $others = [];
        foreach ($person ? array_map(fn($r) => ['id'=>$this->vault->still((string)$r, $key)['id']], $extra) : $this->referenceIds($extra, $key, $wait) as $r) {
            if (in_array($r['id'], array_column($references, 'id'), true)) continue;
            $references[] = $r; $others[] = $at++;
        }
        $style = $comics->style($storyId, $key);
        // The lettering's sample still being made: the writer waits for it, since every page's text follows it.
        if (!$person && is_string($style['lettering']['job'])) { try { $style['lettering']['image'] = $this->readyImage($style['lettering']['job'], $key, $wait); } catch (HttpError $e) { if ($e->kind === 'wait') throw $e; } }
        $prompt = self::pagePrompt($page, $settings, $people, $places, $previous, $others, $instruction, count($comics->pages($storyId, $key)));
        $job = $this->imageRequest($storyId, ['image_scale'=>$settings['scale']] + $writer, $prompt, t('Page {n} · {title}', ['n'=>$page['number'], 'title'=>mb_substr($story['title'] !== '' ? $story['title'] : t('Untitled'), 0, 100)]),
            $settings['aspect'], $references, $key, true, $style, ['space'=>'comic']);
        $comics->setJob($storyId, $pageId, $job['id'], $key);
        return $job;
    }

    /** The prompt of a page: what it is, how to read the sketch, each panel, then the references. */
    public static function pagePrompt(array $page, array $settings, array $people, array $places, ?int $previous, array $others, string $instruction, int $pages): string
    {
        $manga = $settings['kind'] === 'manga'; $rtl = $settings['reading'] === 'rtl'; $n = count($page['panels']);
        $kind = Comics::KIND_NAMES[$settings['kind']] ?? 'manga';
        $p = 'One complete, finished '.$kind.' page, as printed in '.(['manga'=>'a manga volume', 'comic'=>'a comic book', 'graphic_novel'=>'a graphic novel'][$settings['kind']] ?? 'a manga volume').': page '.$page['number'].' of '.$pages.', '
            .($settings['color'] === 'bw' ? 'in black and white (black ink line art, screentone shading and solid blacks, no color)' : 'in full color')
            .', read '.($rtl ? 'from right to left, as manga is (the first panel is at the top right)' : 'from left to right (the first panel is at the top left)')
            .', with '.$n.' panel'.($n === 1 ? '' : 's').'. One single page: not a grid of separate images and not a reference sheet.';
        $p .= "\n\nAttached image 1 is a rough storyboard sketch of this page. Follow it for the composition: the panel layout (how many panels, their shapes, sizes and positions, and the gutters), the reading order, where each figure stands, its pose and its size in the panel, the camera framing, and where each balloon, caption and sound effect goes (the sketch marks the balloons B1, B2… in reading order). It is only a guide: draw the page fully finished in the style described below, with real drawing and real lettering. Nothing of the sketch itself shows on the page: not its stick figures, its gray shapes, its panel numbers, its names or its B labels.";
        if ($page['summary'] !== '') $p .= "\n\nWhat happens on this page: ".$page['summary'];
        $who = []; foreach ($people as $c) foreach ($c['panels'] as $k) $who[$k][mb_strtolower($c['name'])] = $c['at'];
        $where = []; foreach ($places as $c) foreach ($c['panels'] as $k) $where[$k] = $c['at'];
        $balloons = Comics::balloons($page); $b = 0;
        $kinds = ['speech'=>'Speech balloon', 'thought'=>'Thought balloon', 'whisper'=>'Whisper balloon', 'shout'=>'Shout balloon', 'caption'=>'Caption box', 'sign'=>'Lettered sign', 'sfx'=>'Sound effect'];
        foreach ($page['panels'] as $k => $panel) {
            $p .= "\n\nPanel ".($k + 1).($panel['framing'] !== '' ? ' — '.$panel['framing'] : '').'.';
            if ($panel['place'] !== '') $p .= ' Setting: '.$panel['place'].(isset($where[$k + 1]) ? ' (attached image '.$where[$k + 1].')' : '').'.';
            foreach ($panel['characters'] as $c) {
                $ref = $who[$k + 1][mb_strtolower($c['name'])] ?? null;
                $p .= "\n- ".$c['name'].($ref !== null ? ' (attached image '.$ref.')' : '').($c['where'] !== '' ? ', '.$c['where'] : '').($c['doing'] !== '' ? ': '.$c['doing'] : '').'.';
            }
            if ($panel['description'] !== '') $p .= "\n".$panel['description'];
            $texts = [];
            foreach ($panel['text'] as $t) {
                $label = $balloons[$b++]['label'];
                $texts[] = '- '.$kinds[$t['type']].($label !== null ? ' '.$label : '').($t['by'] !== '' ? ($t['type'] === 'sfx' ? ' (the sound of '.$t['by'].')' : ', from '.$t['by']) : '').': "'.$t['text'].'"'.($t['where'] !== '' ? ' — '.$t['where'] : '');
            }
            $p .= "\n".($texts ? "Text:\n".implode("\n", $texts) : 'No text in this panel.');
        }
        $refs = [];
        if ($people) $refs[] = 'The characters\' reference sheets: '.implode('; ', array_map(fn($c) => $c['name'].' is attached image '.$c['at'].($c['caption'] !== '' ? ' ('.$c['caption'].')' : ''), $people))
            .'. A sheet shows one character from several angles: draw each of them exactly as there (face, hair, eyes, build, skin tone, clothes, marks), once in each panel they are in, posed as the panel needs; never copy the sheet\'s layout.';
        if ($places) $refs[] = 'The places\' reference pictures: '.implode('; ', array_map(fn($c) => $c['name'].' is attached image '.$c['at'], $places))
            .'. Draw each setting as it looks there (architecture, layout, objects, landmarks, light), from the angle each panel needs.';
        if ($previous !== null) $refs[] = 'Attached image '.$previous.' is the previous page of this '.$kind.': keep exactly its art style, line weight, shading and character designs, and the continuity with it (clothes, light, time of day, props).';
        if ($others) $refs[] = (count($others) === 1 ? 'Attached image '.$others[0].' is a further reference' : 'Attached images '.implode(', ', $others).' are further references').($instruction !== '' ? ' (see the notes below).' : '.');
        if ($refs) $p .= "\n\n".implode("\n", $refs);
        if ($page['notes'] !== '') $p .= "\n\nNotes for the whole page: ".$page['notes'];
        if ($instruction !== '') $p .= "\n\nFor this drawing: ".$instruction;
        $p .= "\n\nLetter every balloon, caption, sign and sound effect above exactly as written, word for word, in the language it is written in (accents and punctuation included), in the kind of balloon given: speech, a round balloon with a tail toward the speaker; thought, a cloud-shaped balloon; whisper, a balloon with a dashed outline and smaller letters; shout, a jagged balloon with big bold letters; caption, a rectangular box; sign, lettered on the object itself; sound effect, big hand-drawn letters woven into the art (over the panel, behind the characters or breaking the border, as the moment needs), in the "
            .($manga ? 'manga' : ($settings['kind'] === 'graphic_novel' ? 'graphic novel' : 'comics')).' way. No other text anywhere on the page: no panel numbers, labels, names, page numbers, signatures or watermarks.';
        return $p;
    }

    /**
     * A new version of a drawn page, from the comic's view: what to change (the lettering's sample goes along, so a
     * changed text keeps the letters), Melhorar or Ampliar. It takes the page's place when ready. Returns the request.
     */
    public function editPage(string $storyId, string $pageId, array $in, string $key): array
    {
        $page = $this->stories->comics()->page($storyId, $pageId, $key);
        if (!is_string($page['image'])) throw new HttpError(422, t('This page has not been drawn yet.'));
        $kind = (string)($in['kind'] ?? 'edit');
        if (!in_array($kind, ['edit', 'enhance', 'upscale'], true)) throw new HttpError(422, t('Choose fix, enhance or upscale.'));
        $instruction = trim(Stories::cleanText($in['instruction'] ?? '', 4000, t('The instruction')));
        $story = $this->stories->get($storyId, $key);
        $references = [];
        $sample = $this->stories->cast($storyId, $key, 'comic')['style']['lettering']['image'];
        if ($kind === 'edit' && is_string($sample) && $instruction !== '') {
            try { $references[] = ['id'=>$this->vault->still($sample, $key)['id']]; $instruction .= "\n\nAttached image 2 is the lettering reference of this comic: any text you change or add uses exactly its lettering."; } catch (HttpError) {}
        }
        return $this->newVersion($storyId, self::settings($story['writer']), $page['image'], $kind, $instruction, $references, null, 'person', $key)[0];
    }

    /** A drawing of a page that failed, made again with what it was asked with. Returns the new request. */
    public function retryPage(string $storyId, string $pageId, string $key): array
    {
        $page = $this->stories->comics()->page($storyId, $pageId, $key);
        if (!is_string($page['job'])) throw new HttpError(409, t('This page has no drawing to try again.'));
        $job = $this->jobs->retry(self::newId(), $page['job'], null, $key, $this->stories->album($storyId, $key, 'comic'))[0];
        $this->stories->comics()->retryJob($storyId, $pageId, $job['id'], $key);
        return $job;
    }

    // =================================================================================================================
    // The cover, from the page
    // =================================================================================================================

    /**
     * Covers made from the page, to choose from: the front cover of the story in its format (a novel, an illustrated
     * novel, a light novel, a manga volume, a comic book issue, a graphic novel: the one the person chose, else one read
     * from the story), one in each design direction (the styles the person picked, else three different ones), from the
     * person's description and the story's title and synopsis, in the story's image settings and visual style (the
     * comic's for a manga or a comic), with its title, series and author (and a volume, when the person set one)
     * lettered on it or no text at all, and with pictures (the character sheets) as references for how the characters
     * look. The format, the author and the volume are kept for the next covers (and the writer's). The first one ready
     * becomes the cover; the others stay to choose from. Returns the requests.
     */
    public function makeCover(string $storyId, array $in, string $key): array
    {
        $story = $this->stories->get($storyId, $key);
        $writer = self::settings($story['writer']);
        $description = trim(Stories::cleanText($in['prompt'] ?? '', 4000, t('The cover description')));
        $aspect = (string)($in['aspect'] ?? '2:3');
        if (!in_array($aspect, self::ASPECTS, true)) throw new HttpError(422, t('Invalid cover format.'));
        if ($description === '' && $story['synopsis'] === '') throw new HttpError(422, t('Describe what the cover should show: the story has no synopsis yet.'));
        $styles = self::coverStyles($in['styles'] ?? null, $in['count'] ?? null);
        $ids = is_array($in['references'] ?? null) ? $in['references'] : [];
        if (count($ids) > 8) throw new HttpError(422, t('Use up to 8 reference images.'));
        $references = [];
        foreach ($ids as $id) { $image = $this->vault->still((string)$id, $key)['id']; $references[$image] = ['id'=>$image]; }
        if (array_key_exists('design', $in)) {
            $this->stories->update($storyId, ['cover_design'=>Stories::cleanCoverDesign($in['design'])], $key);
            $story = $this->stories->get($storyId, $key);
        }
        $format = $story['cover_design']['format'];
        $lettered = ($in['lettering'] ?? true) !== false;
        $prompt = $this->coverPrompt($story, $format, $description, (bool)$references, $lettered, false, count($styles));
        $title = $story['title'] !== '' ? $story['title'] : t('Untitled');
        $jobs = $this->imageRequests($storyId, $writer, $prompt, 'Capa · '.mb_substr($title, 0, 120), $aspect, array_values($references), $key, false,
            $this->coverStyle($storyId, $format, $key), ['lines'=>self::coverLines($styles)]);
        $this->stories->setCoverJobs($storyId, array_map(fn($job, $style) => ['job'=>$job['id'], 'style'=>$style], $jobs, $styles), $key, 'person');
        return $jobs;
    }

    /** What a cover in each format is the front cover of. */
    private const COVER_NAMES = ['novel'=>'a novel', 'illustrated'=>'an illustrated novel', 'light_novel'=>'a Japanese light novel volume',
        'manga'=>'a manga volume (tankōbon)', 'comic'=>'a comic book issue', 'graphic_novel'=>'a graphic novel'];

    /**
     * What a cover in each format always is: its medium and how its title is made. How it is composed, how literal its
     * art is and how its type is set come from its design direction (COVER_DIRECTIONS).
     */
    private const COVER_DESIGNS = [
        'novel'=>'The format: a novel\'s front cover, as the best cover designers make them today. Any medium can serve the design direction (photography, painting, drawing, graphic shapes, pure type), in the story\'s visual style when it has one; the genre is felt in the mood, the palette and the typography, never through its clichés.',
        'illustrated'=>'The format: the cover of a beautifully illustrated edition of a novel. The art is drawn or painted in the same hand and style as the book\'s illustrations, and the title is lettered as part of the design (hand-drawn, or letters made to match the illustration\'s line and colors).',
        'light_novel'=>'The format: the cover of a Japanese light novel volume (a bunkobon), the refined kind a prestige label publishes. The art is a highly polished anime-style illustration (crisp line art, detailed hair, eyes and costume, soft painted light), in the story\'s visual style when it has one, and the title is a designed logo, never a plain font. Elegant and tasteful: a considered palette, a clean layout and room to breathe, never cluttered or garish.',
        'manga'=>'The format: the front cover of a manga volume (tankōbon): full-color art even when the pages inside are black and white, in the manga\'s own art style, and the title as a designed manga logo with real character (custom letterforms, perhaps a small motif of the story worked into them). The clean, confident layout of a modern Japanese tankōbon.',
        'comic'=>'The format: the cover of a comic book issue: full-color cover art in the comic\'s own art style, with strong inks and bold color, and the title as a masthead logo in custom, dimensional or graphic letters. Made to stop a reader in a comic shop, the way the most celebrated variant covers do.',
        'graphic_novel'=>'The format: the cover of a graphic novel, a book sold in bookstores: full-color art in the book\'s own art style, composed like a book cover rather than a comic-shop issue, with the title in lettering designed with the art (hand-lettered, or a typeface that suits the genre and the line of the drawing), not a masthead and no issue box. The refined look of a graphic novel from a literary or prestige imprint.',
    ];

    /** How the type of every cover is worked, whatever its direction. */
    private const COVER_TYPE = 'Typography is half of the design and is always worked with intent, never a default font dropped on top of a picture: lettering or a typeface with real character, chosen for this story and this direction; a clear hierarchy (the title first, then the series, then the author); deliberate scale, weight contrast, letter-spacing, line breaks and alignment; and the type in dialogue with the art (tucked behind or in front of a figure, cropped by the edge, cut by a shape, following a line of the composition, set in a band of color). Crisp and legible even at thumbnail size, every letter correct.';

    /** What every cover avoids: the commonplace. */
    private const COVER_FRESH = 'Avoid the commonplace: not the stock cover of the genre (a figure seen from behind facing a castle or a city, a couple about to kiss, a hooded figure, a glowing orb, a woman in a flowing gown, sparkles and lens flares), not a title simply centered over a busy picture, not generic glossy rendering. Find an idea that comes from this story in particular and surprises. Everything with class: restraint, a considered palette and purposeful negative space, as from the art department of a great publisher.';

    /** The design directions of a cover: each request makes covers in different ones, for the person to choose from. */
    public const COVER_STYLES = ['classic', 'literal', 'modern', 'minimal', 'retro', 'abstract', 'typographic'];

    /** The directions in three families (classic and figurative; graphic; abstract and typographic): a set takes one of each first. */
    private const COVER_FAMILIES = [['classic', 'literal'], ['modern', 'minimal', 'retro'], ['abstract', 'typographic']];

    /** Each design direction, as the direction of one cover of a set (at most 1,000 characters: Jobs keeps one line each). */
    private const COVER_DIRECTIONS = [
        'classic'=>'Classic. A timeless, elegant cover in the grand tradition of its format, the edition a reader keeps: one beautifully crafted image of the story\'s heart (a portrait of the protagonist, a charged moment, an emblematic object or place), painted or photographed in rich, harmonious color with soft, directional light, composed with poise and leaving generous room for the type. Typography: a refined display serif or engraved capitals, generously spaced, the title in lines of considered sizes, perhaps one graceful swash or ligature, a fine rule or a small ornament; the author\'s name in spaced small capitals. Quiet luxury, like gold foil on cloth. In a light novel, a manga or a comic: that format\'s classic cover at its most elegant, the characters drawn at their best in its style, with a refined, well-crafted logo.',
        'literal'=>'Literal. The story shown as it is, like a film poster or a painting by a great cover illustrator: the protagonists in their world at a defining moment of the story (never its ending), with cinematic depth, scale and atmosphere, a specific, believable setting and light that tells the mood. Typography built into the scene\'s space: the title large, partly hidden behind a figure or a landform, or riding the horizon, its letters with weight and texture that belong to the story\'s world; the series and the author small and clean. In a light novel, a manga or a comic: a full illustration of the characters in action or in a telling pose within their setting, with the logo locked into the art.',
        'modern'=>'Modern. Contemporary graphic design, as from today\'s most design-led publishers: a bold, unexpected composition on a strong grid, with flat color fields, a cut-out or duotone image of the subject, sharp crops, asymmetry and confident negative space, in a limited palette of two or three striking colors. Typography as structure: a contemporary grotesque or a sharp modern serif, very large and tightly tracked, set flush left or on the grid, mixing weights and sizes for rhythm, perhaps running vertically or wrapping around the image; the small text aligned with precision. In a light novel, a manga or a comic: the characters drawn in the format\'s style but staged graphically (a close crop, one figure on a flat color field, a block of color behind), with a clean, modern logo.',
        'minimal'=>'Minimal. One clever visual idea that holds the whole story, and almost nothing else: a single object, symbol, silhouette or detail, or a negative-space trick where two things read as one, on a plain or softly textured field with plenty of breathing room. Typography quiet and exact: a small title beautifully set in a refined typeface, or one large word placed with intent; perfect alignment and spacing. The kind of cover that wins design awards: smart, restrained, unforgettable. In a light novel, a manga or a comic: one small, perfectly drawn element in the format\'s style (an eye, a hand, a flower, a weapon, a distant figure) on a vast quiet field, with a delicate logo.',
        'retro'=>'Retro. A mid-century modern cover, freshly made: screen-printed flat shapes in two or three inks, with slight misregistration, halftone shading and paper grain, stylized geometric figures and objects, bold silhouettes and a playful sense of space. Typography with period character: hand-drawn display letters, a condensed grotesque or a quirky serif, arranged dynamically (stacked, angled, scaled) and locked into the shapes. In a light novel, a manga or a comic: the characters stylized into flat, graphic shapes, like a vintage poster or a Shōwa-era paperback, still recognizable.',
        'abstract'=>'Abstract. No literal scene: the story\'s mood and themes become pure form, with sweeping fields of color, gradients, gestural brushstrokes or ink, geometric shapes, light and texture (paper, paint, grain), or an image dissolving into shapes; at most one small figure or fragment, for scale and mystery. Expressive yet balanced, like a gallery print. Typography set against it with precision: a clean, elegant typeface placed with intent (in a band, in a corner or across the image), its order a counterpoint to the freedom of the art. In a light novel, a manga or a comic: a trace of the format\'s art (a silhouette, a sweep of hair, an eye, a drawn hand) melting into abstract color and shape.',
        'typographic'=>'Typographic. The title is the image: its letters fill the cover, huge and expressive, cropped by the edges, stacked, interlocking or broken across lines, mixing sizes and weights; or the art lives inside and through them (a scene seen through the letterforms, a figure stepping out of a letter, letters made of something from the story: light, water, thread, smoke, stone, cut paper). One striking color scheme and a strong play of figure and ground; for all the play, the title reads clearly and every letter is exact. The other words small and neatly set. In a light novel, a manga or a comic: a bold, designed logo takes most of the cover, with the characters drawn small, peeking through or framed by its letters.',
    ];

    /**
     * The directions of a set of covers: the ones asked for (1 to 4, each once), else $count of them (3 by default, 1 to
     * 4), drawn at random so each set is new: one from each family first (classic or literal, a graphic one, abstract or
     * typographic), in that order.
     * @return list<string>
     */
    public static function coverStyles(mixed $asked, mixed $count = null): array
    {
        if ($asked !== null && $asked !== []) {
            if (!is_array($asked)) throw new HttpError(422, t('Invalid cover styles.'));
            $styles = [];
            foreach ($asked as $style) {
                if (!is_string($style) || !in_array($style, self::COVER_STYLES, true)) throw new HttpError(422, t('Invalid cover style.'));
                if (!in_array($style, $styles, true)) $styles[] = $style;
            }
            if (count($styles) > 4) throw new HttpError(422, t('Choose up to 4 cover styles.'));
            return $styles;
        }
        $n = $count === null || $count === '' ? 3 : filter_var($count, FILTER_VALIDATE_INT);
        if (!is_int($n) || $n < 1 || $n > 4) throw new HttpError(422, t('Ask for 1 to 4 covers.'));
        $families = array_keys(self::COVER_FAMILIES);
        if ($n < count($families)) { shuffle($families); $families = array_slice($families, 0, $n); sort($families); }
        $styles = array_map(fn($f) => self::COVER_FAMILIES[$f][random_int(0, count(self::COVER_FAMILIES[$f]) - 1)], $families);
        while (count($styles) < $n) { $rest = array_values(array_diff(self::COVER_STYLES, $styles)); $styles[] = $rest[random_int(0, count($rest) - 1)]; }
        return $styles;
    }

    /** The direction of each cover of a set, one line each. */
    private static function coverLines(array $styles): array
    { return array_map(fn($style) => self::COVER_DIRECTIONS[$style], $styles); }

    /**
     * The prompt of a cover: what it is the front cover of, the story (from the page, its synopsis for the subject and
     * the genre), what it shows (the person's description or the writer's prompt), the references as likenesses (from
     * the page), what the format always is, how its type is worked, what it avoids, the words lettered on it (or none),
     * the finish of a published cover and, in a set, that it follows its own direction. The app adds the visual style
     * after it, and each cover's direction last.
     */
    private function coverPrompt(array $story, string $format, string $shows, bool $likenesses, bool $lettered, bool $byWriter, int $count = 1): string
    {
        $title = $story['title'] !== '' ? $story['title'] : t('Untitled');
        $d = $story['cover_design'];
        $p = 'The front cover of '.self::COVER_NAMES[$format].' titled "'.$title.'"'.($story['series'] !== '' ? ', of the series "'.$story['series'].'"' : '').'.';
        if (!$byWriter && $story['synopsis'] !== '') $p .= "\n\nWhat the story is about, for the subject, the genre and the mood (show nothing of how it ends): ".$story['synopsis'];
        if ($shows !== '') $p .= "\n\n".($byWriter ? 'The cover\'s art: ' : 'The cover shows: ').$shows;
        if ($likenesses) $p .= "\n\nThe attached reference pictures show how the story's characters look, and only that: whoever of them appears on the cover keeps their face, hair, build and clothes, drawn anew in the cover's own style (a photograph becomes a drawing when the cover is drawn), in a pose, framing, setting and light made for the cover; never the reference's own pose, background or layout, and not as a reference sheet. The cover needn't show them all: only whom its composition needs, usually the protagonists.";
        $p .= "\n\n".self::COVER_DESIGNS[$format].' Where the cover shows the story\'s characters or world, they are drawn in its visual style (given below, when it has one); the design direction (given last) decides the composition, the typography and how literal or abstract the art is.';
        if ($lettered) $p .= "\n\n".self::COVER_TYPE;
        $p .= "\n\n".self::COVER_FRESH;
        if ($lettered) {
            $logo = in_array($format, ['light_novel', 'manga', 'comic'], true);
            $words = ['the title "'.$title.'", the largest text, as the cover\'s title '.($logo ? 'logo' : 'lettering')];
            if ($story['series'] !== '') $words[] = 'the series name "'.$story['series'].'", smaller';
            if ($d['volume'] !== '') $words[] = $format === 'comic' ? 'the issue number "#'.$d['volume'].'"' : 'the volume "Vol. '.$d['volume'].'"';
            if ($d['author'] !== '') $words[] = 'the author\'s name "'.$d['author'].'", small';
            $p .= "\n\nLettering on the cover, each spelled exactly as given: ".implode('; ', $words).'. No other words or letters anywhere: no subtitle, tagline, blurb or quote, no volume or issue number'.($d['volume'] !== '' ? ' other than the one given' : '').', no publisher or imprint logo, barcode, price or rating, and no text in another language or script.';
        } else $p .= "\n\nNo text, title or lettering anywhere on the image: the cover's art alone, with clear space left where the title would be set.";
        $p .= "\n\nIt must look like a real, professionally published cover, one you would find in a bookstore: a cover designer's composition with a clear focal point that still reads at thumbnail size, the art and the type made for each other. Only the flat front cover, full-bleed, edge to edge: no mockup or 3D book, no spine or back cover, no frame or border, no hands, table or shelf.";
        if ($count > 1) $p .= "\n\nThis is one of $count covers made at once for the same book, each in a different design direction, for the author to choose from: follow this cover's own direction (given last) all the way, so that it looks nothing like the others, while keeping the story, its characters and its words.";
        return $p;
    }

    /** The look of a cover: a manga's, a comic's or a graphic novel's is the adaptation's (its own style, else the story's); null for the story's. */
    private function coverStyle(string $storyId, string $format, string $key): ?array
    {
        return in_array($format, ['manga', 'comic', 'graphic_novel'], true) ? $this->stories->comics()->style($storyId, $key) : null;
    }

    /** A new version of the cover from the page: what to change, or Fotorrealista, Melhorar, Ampliar. Only the cover changes. */
    public function editCover(string $storyId, array $in, string $key): array
    {
        $story = $this->stories->get($storyId, $key);
        if ($story['cover'] === null) throw new HttpError(422, t('The story has no cover yet.'));
        $kind = (string)($in['kind'] ?? 'edit');
        $instruction = trim(Stories::cleanText($in['instruction'] ?? '', 4000, t('The instruction')));
        // The first illustration shown as the cover becomes the chosen one: the new version replaces the cover, not the chapter's picture.
        if (!$story['cover_chosen']) { $this->versionOf($storyId, $story['cover'], $kind, $instruction, 'person', $key); $this->stories->update($storyId, ['cover'=>$story['cover']], $key); }
        return $this->newVersion($storyId, self::settings($story['writer']), $story['cover'], $kind, $instruction, [], null, 'person', $key, true)[0];
    }

    /**
     * A sample sheet of the story's lettering, made from its description in the story's visual style: from then on it
     * goes with every picture that has text in it, so the letters look the same in all of them. Returns the request.
     */
    public function letteringSample(string $storyId, ?array $writer, string $by, string $key, string $space = 'story'): array
    {
        $story = $this->stories->get($storyId, $key);
        $writer ??= self::settings($story['writer']);
        // The adaptation's lettering is its own, and its sample is drawn in the comic's style.
        $comic = $space === 'comic';
        $lettering = $this->stories->cast($storyId, $key, $space)['style']['lettering'];
        if ($lettering['text'] === '') throw new HttpError(422, $by === 'person' ? t('Describe the lettering before generating the sample.') : 'Describe the lettering first (set_style lettering'.($comic ? ' with comic: true' : '').').');
        if (is_string($lettering['job'])) throw new HttpError(422, $by === 'person' ? t('A lettering sample is already being made. Wait for it to be ready.') : 'A lettering sample is already being made (request '.$lettering['job'].').');
        $prompt = 'A lettering reference sheet for '.($comic ? 'the pages of a '.Comics::KIND_NAMES[$this->stories->comics()->settings($storyId, $key)['kind']] : 'the pictures of a story').": one lettering style, shown clearly on a plain light background, with nothing else on the sheet. The lettering: ".$lettering['text']
            ."\n\nShow, all in that lettering and spelled exactly: the alphabet in capitals \"ABCDEFGHIJKLMNOPQRSTUVWXYZ\" and in lower case \"abcdefghijklmnopqrstuvwxyz\"; the accented letters \"ÁÉÍÓÚ ÂÊÔ ÃÕ Ç áéíóú âêô ãõ ç\"; the digits \"0123456789\" and \"! ? … , . — “ ”\";"
            .' a speech balloon saying "'.t('You won\'t believe what I saw.').'"; a jagged shout balloon saying "'.t('LOOK OUT!').'"; a whisper balloon (dashed outline) saying "'.t('psst… over here').'"; a narration caption box saying "'.t('That night, nobody slept.').'";'
            ." and two large, expressive sound effects in the same family, \"BLAM!\" and \"CRAAASH\".";
        $job = $this->imageRequest($storyId, $writer, $prompt, ($comic ? t('Lettering sample of the adaptation · {title}', ['title'=>mb_substr($story['title'] !== '' ? $story['title'] : t('Untitled'), 0, 120)]) : t('Lettering sample · {title}', ['title'=>mb_substr($story['title'] !== '' ? $story['title'] : t('Untitled'), 0, 120)])), '3:2', [], $key, false,
            $comic ? $this->stories->comics()->style($storyId, $key) : null, ['space'=>$comic ? 'comic' : 'book']);
        $this->stories->updateStyle($storyId, ['lettering_job'=>$job['id']], $by, $key, $space);
        return $job;
    }

    /**
     * The cover that didn't come out, asked for again: one cover of a set ($choice, the request that failed), every cover
     * of the set being made that failed, or the new version of the cover. Returns the new requests.
     */
    public function retryCover(string $storyId, string $key, ?string $choice = null): array
    {
        $story = $this->stories->get($storyId, $key);
        $batch = $story['cover_job'];
        $failed = fn(array $c) => $c['job'] !== null && $c['image'] === null && !in_array($this->jobs->freshJob($c['job'], $key)['status'] ?? 'failed', self::ACTIVE, true);
        if ($choice !== null) {
            $todo = array_values(array_filter($story['cover_choices'], fn($c) => $c['job'] === $choice));
            if (!$todo || !$failed($todo[0])) throw new HttpError(409, t('This cover is not waiting to be made again.'));
        } elseif ($batch !== null && in_array($batch, array_column($story['cover_choices'], 'batch'), true)) {
            $todo = array_values(array_filter($story['cover_choices'], fn($c) => $c['batch'] === $batch && $failed($c)));
            if (!$todo) throw new HttpError(409, t('The covers are still being made.'));
        } elseif ($batch === null) {
            // A new version of the cover that failed.
            $from = $this->stories->coverEdit($storyId, $story['cover'], $key);
            if ($from === null || !$story['editing'][$from]['failed']) throw new HttpError(409, t('There is no cover being made to try again.'));
            $job = $this->jobs->retry(self::newId(), $from, null, $key)[0];
            $this->stories->retryEdit($storyId, $from, $job['id'], $key);
            return [$job];
        } else {
            // A cover asked for before covers came in sets.
            $job = $this->jobs->retry(self::newId(), $batch, null, $key)[0];
            $this->stories->setCoverJob($storyId, $job['id'], $key, null);
            return [$job];
        }
        // Each cover again on its own, with its own direction.
        $jobs = [];
        foreach ($todo as $c) {
            $job = $this->jobs->retry(self::newId(), $c['job'], null, $key)[0];
            $this->stories->retryCoverChoice($storyId, $c['job'], $job['id'], $key);
            $jobs[] = $job;
        }
        return $jobs;
    }
}

/** What the tools of one turn work on, and the illustrations they asked for. */
final class ToolContext
{
    /** @var list<string> */
    public array $jobs = [];
    /** What the page estimated of the credit for pictures when the message was sent (US$; null: unknown). */
    public array $credit = ['balance'=>null, 'image'=>null];
    /** The turn's heartbeat, for a tool that waits: true when the person stopped the writer. */
    public ?\Closure $tick = null;
    /** Who uses the tools: the app's writer (agent), or a client over MCP (mcp), whose changes the writer is told about. */
    public string $by = 'agent';
    /** Whether the story is a visual novel project (null: not asked yet). */
    public ?bool $vn = null;
    public function __construct(public readonly string $storyId, public readonly string $turnId, public readonly array $writer, public readonly string $key) {}
}
