# Changelog

What changed in each version of Atelier. Versions follow [Semantic Versioning](https://semver.org/), and every release on the [Releases page](https://github.com/JohanKRS/atelier/releases) has the same notes and a zip of the app, ready to upload.

To update an install, replace the app files with the ones from the new zip and keep `config.php`, `data-dir.php` (if present) and the data folder.

## [1.4.2] - 2026-11-24

### Settings

- The top of **Settings** shows the version of Atelier the server is running, next to the account's name, so after uploading a new release you can see that it took. It links to that version's notes on the Releases page.

## [1.4.1] - 2026-11-24

### Fixes

- The GPU notebook checks the GPU Colab gave it before installing or downloading anything. On one too small for the models, such as an L4 or a T4 (Colab gives them when the A100s are busy, even with the A100 chosen), it says what to choose instead and turns itself off, and **Settings › GPU** shows why.
- The G4 GPU (RTX PRO 6000 Blackwell, 96 GB) works like the A100: when Colab's PyTorch has no kernels for it, the notebook installs a build that does.
- Update note: upload `colab/atelier_worker.py` with the rest of the app. The notebook loads it from your server.

## [1.4.0] - 2026-11-23

### Pictures on the GPU

- **MiniMax H3-Image**, an open picture model, on the same GPU notebook as the videos. It is one more model in the generator and the edit panel (Advanced › Model), and works with the same tools: references from the library (`@1`, up to 16), Variations, edits by describing, an area painted with the brush, Expand, Upscale, Photoreal, Enhance and Clean. There is no cost per picture: the GPU's time is paid to whoever rents it, such as Colab's compute units.
- The quality sets the megapixels (Low 4 MP, Medium 8 MP, High 16 MP, the model's native size); the size sets the shape. A custom size can be anything from 64 to 12,000 pixels a side, up to 40 MP and 5:1. Upscales and expansions are drawn at up to 20 MP and brought to the size asked for.
- **Planning** with H3-Image is MiniMax's own prompt expander, from ComfyUI's official templates, running on the GPU at no cost: it rewrites the prompt with the pictures in view and, with the size on Auto, chooses the shape. If it fails, the picture is made with the prompt as written, and the details say so.
- A painted area or an expansion changes only that area: the model gets a copy of the picture with the area marked, and the notebook pastes the result into the original inside the area, so the rest stays exactly as it was. Edits keep the size of the picture being changed.
- **LoRAs**: a list in **Settings › GPU** (the link of the `.safetensors` file on Hugging Face or Civitai, a name, trigger words and the strength it starts at). Choose up to 4 per request in the generator or the edit panel, each with its strength from -2 to 2. The notebook downloads each one the first time a request uses it in a session and adds its trigger words to the prompt. Files that ask you to sign in use the `HF_TOKEN` or `CIVITAI_TOKEN` secret in Colab, never Atelier. A picture's details list its LoRAs, seed and GPU.
- The model's license (MiniMax-H3-Image Community License) doesn't allow use in the United States, the European Union, the United Kingdom or South Korea, and offering it to others as a service needs MiniMax's authorization.

### GPU

- **Settings › Video (GPU)** is now **Settings › GPU**, and the notebook is now `colab/atelier_gpu.ipynb` (it replaces `colab/atelier_video.ipynb`, which can be deleted from the server). Its new optional secret `CIVITAI_TOKEN` is only for LoRAs that ask you to sign in.
- The notebook downloads only the models the waiting requests need (or the latest one's), and each other model the first time a request needs it in the session: about 45 GB for videos, 47 GB for pictures and 7 GB for the prompt expander.
- The page shows what the GPU is doing for pictures too (preparing the pictures, planning the prompt, the steps, the upload), and the **GPU** chip says whether it is making a video or a picture.
- Update note: upload the whole `colab/` folder (the notebook was renamed) with `gpu.php` and the rest of the app.

## [1.3.0] - 2026-11-23

### Pictures

- Crop is a step of its own: the new Crop button shows a frame that stays inside the picture. Pull its handles or pick a proportion (1:1, 4:5, 3:4, 2:3, 9:16, 16:9, 4:3, 3:2), and drag the picture to choose which part stays. The crop is made in the browser, at no cost, and the cropped picture opens as a new version, ready to edit or expand. Expanding it toward the side that was cut off draws new content there, since the model only gets the cropped picture.
- The brush has a Cover switch. Off, the painted area only marks the part to change, and the model still sees what is there. On, the area is erased from the picture that is sent, so the model draws it from scratch without seeing what was there. With nothing written, Fill area fills it with what surrounds it, which removes what was there; with text, the text says what goes there. A covered area shows as a solid checkered patch on the picture.
- The edit buttons are in two groups: the four helpers of a plain edit on top (Area, File, Gallery, Variations), smaller, and the requests below in two rows of three (Crop, Expand, Upscale; Photoreal, Enhance, Clean).
- Fixed: after a handle of the Expand frame was let go, the frame and the picture were not fitted to the screen again (since 1.0.0).
- On a phone, choosing Crop or Expand brings the picture back into view, as the brush already did.

## [1.2.0] - 2026-11-23

### Videos

- Videos with sound made with open models on a GPU you rent by the hour, such as Google Colab. A notebook there (`colab/atelier_video.ipynb`) takes the account's video requests from the new `gpu.php`, makes them with ComfyUI inside the runtime and sends them to the library. Every call is short and goes from the GPU to your site, so no tunnel or open port is needed and host limits such as LiteSpeed's 120 seconds don't get in the way.
- The model is MiniMax H3: 5 to 15 seconds with voices, effects and music, at 480p or 768p. Three qualities follow ComfyUI's official templates: Fast (Pika H3 Flex), Balanced (Turbo) and Maximum.
- **New video**: from the prompt, with pictures pinned at moments of it (the first frame, the last one, and any second in between) and references: up to 9 pictures of people, places and things, up to 3 videos of the library whose movement, camera or style it follows, and up to 3 sounds (MP3, WAV, OGG, M4A, AAC or FLAC, up to 5 MB) with the voices to use or to play from a chosen second. In the prompt they are `@1` (pictures), `@v1` (videos) and `@s1` (sounds).
- **From a video** of the library: continue it (joined to the original or apart), redo it following the movement of its people (pose), its shapes and camera (depth) or its outlines, or replace a part of it that you name (found in every frame by SAM 3), keeping its sound if you want. Each of these downloads its extra model (2 to 4 GB) the first time in a session.
- **Improve the prompt**: Claude, GPT or Gemini (whichever has a key in Settings) rewrites your idea in the structure H3 was trained on: the alignment of the pinned pictures, the subjects and what to keep of each reference, the shots with their timestamps, the camera, the dialogue with its language, the sound and the music. **Back to mine** undoes it. Its cost goes into the account's spending.
- Ask for a video with **Video** in the composer (its prompt and references come along), **Animate** under a picture in the viewer (the picture becomes the first frame), **Continue as a video** on the frame of a video, or **Make a video from this one** beside a video of the library. The request shows in the feed with what the GPU is doing: preparing the videos, downloading the model, the steps and the time left, the upload. Its details list what it was made from, and **Use prompt** opens the window again with the same pictures and videos.
- **Settings › Video (GPU)** makes the notebook's token, shows whether the GPU is on and when it turns off, and has **Turn off now**. The runtime turns itself off after the minutes without requests you choose (15 by default), so it spends no credits waiting. A **GPU** chip at the top shows it while it is on.
- Update note: upload `gpu.php` and the new `colab/` folder with the rest of the app. `.htaccess` (and `docs/nginx.conf.example`) now close `colab/` to the web and pass the Authorization header to `gpu.php`.

### Fixes

- The hosting test says why a process could not start in the language of whoever reads it, not of whoever ran the test.

## [1.1.0] - 2026-11-22

### Pictures

- The pictures made for a story, a comic or a visual novel no longer fill the library. Each story gets an album for its covers, illustrations and sheets, each comic or manga adaptation one for its pages and sheets, and each visual novel one for its sprites, backgrounds and CGs. They are listed under Stories, Comics and manga, and Visual novels in the library menu, and open with every picture.
- Any album, your own included, can keep its pictures out of the library or show them there too, with "Show in the library" in the menu while the album is open. Adding pictures to an album kept out moves them there.
- A picture from the library that a story only uses (a cover or a sheet picked from the gallery) stays in the library.
- Existing installs move the pictures the stories already made into these albums the first time each account opens the app.

### Stories

- A button by the cover opens the story's or visual novel's album, and the comic window has one for the adaptation's. From an album, "Open the story" goes back.

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

[1.4.2]: https://github.com/JohanKRS/atelier/releases/tag/v1.4.2
[1.4.1]: https://github.com/JohanKRS/atelier/releases/tag/v1.4.1
[1.4.0]: https://github.com/JohanKRS/atelier/releases/tag/v1.4.0
[1.3.0]: https://github.com/JohanKRS/atelier/releases/tag/v1.3.0
[1.2.0]: https://github.com/JohanKRS/atelier/releases/tag/v1.2.0
[1.1.0]: https://github.com/JohanKRS/atelier/releases/tag/v1.1.0
[1.0.0]: https://github.com/JohanKRS/atelier/releases/tag/v1.0.0
