# Changelog

What changed in each version of Atelier. Versions follow [Semantic Versioning](https://semver.org/), and every release on the [Releases page](https://github.com/JohanKRS/atelier/releases) has the same notes and a zip of the app, ready to upload.

To update an install, replace the app files with the ones from the new zip and keep `config.php`, `data-dir.php` (if present) and the data folder.

## [1.0.0] - 2026-11-22

The first public release.

### Pictures

- Generate with OpenAI's GPT Image models and, with a Google key, with Nano Banana. Pick a shape and a quality, see an estimate before and the real cost after.
- Edit by describing the change, by painting over the part you mean, or with other pictures from the library as references (`@1`, `@2`).
- Upscale, expand the canvas, make a picture photoreal, enhance it, or clean up noise and stray lines.
- Every edit becomes a new version in a tree: compare with the original, go back, or branch off.
- A library with albums, favourites, stacks, hidden pictures, search by prompt and sorting by size or shape. Videos too: pick a frame and edit it.

### Stories

- A writer (Claude, GPT or Gemini, switchable mid-conversation) that keeps the arc in a hidden plan, tracks people, places and rules in a story bible, and writes chapter by chapter at your pace. You can also write or edit chapters yourself.
- Illustrations inside the chapters, covers in a few styles to choose from, and character and place sheets that keep faces consistent.
- "About me as a reader": tell the writer once what you enjoy, and it keeps notes on what it notices.
- A reader with pages or scroll, dark, black, sepia and light themes, your own type settings, and the place where you stopped. Download any story as EPUB.
- Duplicate a story, and export or import it as a pack.

### Comics, manga and visual novels

- Chapters adapted into comic or manga pages: a script for every panel, a storyboard, a lettering sample and the drawn page. Download as PDF or CBZ.
- Visual novels with choices, variables and several endings, with sprites, backgrounds and CGs. Play in the browser, or export a Ren'Py project.

### Everything else

- A built-in MCP server, so Claude or any MCP client can make pictures and work on stories with the same tools as the app's writer.
- Several accounts per install, each with a library encrypted by a key that only its password opens.
- A running balance for each provider, if you want one.
- English and Brazilian Portuguese.
- PHP 8.2 or newer with SQLite, no build step. Long requests keep going in the background, or in a separate process (`bin/worker.php`) on hosts that stop web processes early.

[1.0.0]: https://github.com/JohanKRS/atelier/releases/tag/v1.0.0
