# Changelog

What changed in each version of Atelier. Versions follow [Semantic Versioning](https://semver.org/), and every release on the [Releases page](https://github.com/JohanKRS/atelier/releases) has the same notes and a zip of the app, ready to upload.

To update an install, replace the app files with the ones from the new zip and keep `config.php`, `data-dir.php` (if present) and the data folder.

## [Unreleased]

### Pictures on the GPU

- **Save the pictures as JPEG**, in **Settings › GPU**: the notebook saves the pictures that would be PNG as JPEG at the quality chosen there (85 to start, from 50 to 100) before sending them. A PNG of 8K or more takes tens of megabytes; the JPEG is several times smaller, with no difference to see at 85, and reaches the library sooner. It counts for new pictures, edits, **Upscale**, **Expand**, **Photoreal** and **Enhance** on the GPU, from the page and through MCP. A request that asks for JPEG or WebP itself keeps its own format and quality.
- With MiniMax H3-Image and PNG chosen, **More options › Format** says the picture will come as a JPEG, and the details of a GPU picture show the JPEG or WebP quality it was saved at.

### MCP

- `set_gpu_pictures` turns JPEG on or off for the GPU's pictures and sets its quality, as **Settings › GPU** does, and `gpu_status` says how they are saved.

## [1.6.0] - 2026-11-25

### MCP

- **The whole studio over MCP.** Besides making, editing and upscaling, a client can now do everything the page does to pictures, with the same ready-made instructions and the same rules:
  - `clean_image`: **Clean** on the server, with a PHP copy of the page's filter that gives exactly the same result. It is a request of its own: it shows in **Requests** with its progress, can be cancelled and repeated, runs in the separate process where the host has one, uses no API and costs nothing.
  - `clean_first` on `edit_image`, `photoreal_image`, `enhance_image` and `upscale_image`: **Clean before sending**, done on the server right before the image goes to the image API (off unless the client asks; not with a painted area, not on the GPU).
  - `crop_image` (**Crop**: a rectangle, or the largest crop of a proportion) and `expand_image` (**Expand**: margins in pixels, negative ones crop that side, or a proportion with the picture where asked, as the page's proportions make it), made on the server with GD.
  - `edit_image` takes `areas` (rectangles or ellipses, in pixels or fractions) or a `mask`, as the brush does: without a prompt the area is redone clean and correct, and `cover` is **Cover** (the area goes erased; without a prompt it is filled from what surrounds it).
  - `photoreal_image` and `enhance_image` (**Photoreal** and **Enhance**), and `upscale_image` with `with: enhance` or `photoreal`.
  - The image tools take `planning`, `complexity` and `album_id`.
- **The library:** `list_images` lists videos, an album, the hidden items, the trash, grouped by versions and in any of the library's orders; `update_image` (title, prompt, favorite, hidden, cover), `trash_images`, `delete_images` (only what is in the trash), the albums (`list_albums`, `create_album`, `update_album`, `delete_album`), `stack_images`, `unstack_images` and `reorder_images`.
- **Requests:** `cancel_request` and `retry_request`; `check_requests` tells cleanups, GPU pictures and videos apart, shows the GPU's progress and can wait for them (`wait_seconds`).
- **The GPU on Colab:** `minimax-h3-image` can be chosen in the image tools, with `loras` and `seed`, and goes further when upscaling or expanding. `gpu_status`, `stop_gpu`, `set_gpu_idle` and the LoRAs (`list_loras`, `save_lora`, `delete_lora`). The notebook can only be turned on in Colab: while it is off, a GPU request waits in line (not an error), and the answer says so.
- **Videos:** `generate_video` asks the GPU for a video with sound, as the video window does (pictures pinned at any second, reference pictures, videos and sounds, or from a library video: continue it, follow its motion, replace a part), and `improve_video_prompt` is the window's prompt helper. `get_video` gives a video's details and a download link, which now streams the video in byte ranges.
- **Stories:** `duplicate_story`, `favorite_story` and `delete_story` (it needs the story's exact title).
- `ping` answers with an empty result, as MCP asks; it used to answer with an internal error.

### Pictures

- A cleanup or a crop made on the server says so in the details of the picture.

## [1.5.0] - 2026-11-25

### Pictures

- **Expand** has the proportions of **Crop**, to lock the shape of the expansion: Free, Original (the picture's own shape), 1:1, 4:5, 3:4, 2:3, 9:16, 16:9, 4:3 and 3:2. Choosing one gives the frame the smallest size of that shape around the whole picture, with the picture in the middle (a quarter larger all around when the picture already has that shape), and locks it: dragging a corner or a side, the arrow keys, or typing the width or the height keep the shape until **Free** is chosen. A corner holds the opposite corner; a side grows or shrinks the two sides next to it evenly. Dragging the picture places it inside the frame, for example against one side to extend only the other. With new areas the sides are still multiples of 16, so the shape is the closest those sizes allow (within 16 px a side).
- In **Crop**, a chosen proportion now stays locked the same way: before, dragging a handle after choosing one changed the shape. **Original** keeps the picture's own shape, and the shape is kept to the pixel, inside the picture.
- With a proportion locked, **Frame on the image** makes the largest frame of that shape inside the picture.

## [1.4.3] - 2026-11-25

### MCP

- The MCP server sends Atelier's logo when a client connects (`icons` in `serverInfo`, from MCP 2025-11-25: the 512 and 192 px PNGs, served by your own site), with a short description and the app's address, so clients that show a server's own icon can show it next to the connector.
- Claude (claude.ai and its apps) doesn't use a server's icon yet. The icon it shows is the site's favicon, which, as far as can be seen, it gets from Google's favicon service for the domain (on a subdomain, sometimes the main domain's). Atelier's `robots.txt` kept every crawler out, Google's too, so Google had no favicon for it. Now Googlebot may read the sign-in page, which still asks not to be indexed, and the icon files, and nothing else. The page also lists a 192 px icon, for services that want a larger one.
- Update note: upload `robots.txt` and `.htaccess` with the rest of the app. A connector that already exists may keep its old icon for a while: in Claude until Google refreshes it, and in other clients until the connector is removed and added again.

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

[1.6.0]: https://github.com/JohanKRS/atelier/releases/tag/v1.6.0
[1.5.0]: https://github.com/JohanKRS/atelier/releases/tag/v1.5.0
[1.4.3]: https://github.com/JohanKRS/atelier/releases/tag/v1.4.3
[1.4.2]: https://github.com/JohanKRS/atelier/releases/tag/v1.4.2
[1.4.1]: https://github.com/JohanKRS/atelier/releases/tag/v1.4.1
[1.4.0]: https://github.com/JohanKRS/atelier/releases/tag/v1.4.0
[1.3.0]: https://github.com/JohanKRS/atelier/releases/tag/v1.3.0
[1.2.0]: https://github.com/JohanKRS/atelier/releases/tag/v1.2.0
[1.1.0]: https://github.com/JohanKRS/atelier/releases/tag/v1.1.0
[1.0.0]: https://github.com/JohanKRS/atelier/releases/tag/v1.0.0
