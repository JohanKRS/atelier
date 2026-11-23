<?php
use Studio\I18n;
use function Studio\{e, t};
// The interface's language (I18n): the page names its translations for assets/i18n.js; English needs none.
$language = I18n::language();
$stringsFile = I18n::file($language);
$stringsVersion = $stringsFile !== null && is_file($stringsFile) ? substr(md5(@filemtime($stringsFile).':'.@filesize($stringsFile)), 0, 10) : null;
/** The language menu: each language named in itself. Choosing one sets the cookie and reloads (app.js). */
$languageMenu = static function (string $id) use ($language): string {
    $options = '';
    foreach (I18n::LANGUAGES as $tag => $name) $options .= '<option value="'.$tag.'"'.($tag === $language ? ' selected' : '').' lang="'.$tag.'">'.htmlspecialchars($name, ENT_QUOTES, 'UTF-8').'</option>';
    return '<select id="'.$id.'" data-language>'.$options.'</select>';
};
?>
<!doctype html>
<html lang="<?= $language ?>"<?php if ($stringsVersion !== null): ?> data-strings="assets/i18n/<?= $language ?>.json?v=<?= $stringsVersion ?>"<?php endif ?>>
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow,noarchive,noimageindex">
  <meta name="color-scheme" content="dark"><meta name="theme-color" content="#0e0e10">
  <title>Atelier</title>
<?php
// A new upload changes the version, so browsers and the CDN never mix old and new files.
$assetStamp = '';
foreach (array_merge(glob(__DIR__.'/../assets/*.js') ?: [], glob(__DIR__.'/../assets/*.css') ?: []) as $assetFile) $assetStamp .= basename($assetFile).':'.@filemtime($assetFile).':'.@filesize($assetFile).'|';
$assetVersion = substr(md5($assetStamp), 0, 10);
// Browsers keep favicons for a long time; a new icon gets a new address.
$iconStamp = '';
foreach (array_merge(glob(__DIR__.'/../assets/*.png') ?: [], glob(__DIR__.'/../assets/*.svg') ?: [], glob(__DIR__.'/../assets/*.webmanifest') ?: []) as $iconFile) $iconStamp .= basename($iconFile).':'.@filemtime($iconFile).':'.@filesize($iconFile).'|';
$iconVersion = substr(md5($iconStamp), 0, 10);
?>
  <link rel="icon" href="assets/icon.svg?v=<?= $iconVersion ?>" type="image/svg+xml">
  <link rel="icon" href="assets/icon-32.png?v=<?= $iconVersion ?>" sizes="32x32" type="image/png">
  <link rel="apple-touch-icon" href="assets/apple-touch-icon.png?v=<?= $iconVersion ?>">
  <link rel="manifest" href="assets/manifest.webmanifest?v=<?= $iconVersion ?>">
  <link rel="stylesheet" href="assets/style.css?v=<?= $assetVersion ?>"><script src="assets/language.js?v=<?= $assetVersion ?>" defer></script><script type="module" src="assets/app.js?v=<?= $assetVersion ?>"></script>
</head>
<body>
<div id="gate" class="gate">
  <form id="auth-form" class="gate-card">
    <div class="gate-brand"><img src="assets/icon.svg?v=<?= $iconVersion ?>" alt="" width="40" height="40"><span>Atelier</span></div>
    <h1 id="gate-title" class="gate-title"><?= e('Unlock') ?></h1>
    <p id="gate-description" class="hint" hidden></p>
    <div id="install-fields" class="stack" hidden>
      <label><?= e('Installation code') ?><input id="setup-token" type="password" autocomplete="off"></label>
      <p class="hint" id="setup-hint"></p>
    </div>
    <label><?= e('User') ?><input id="auth-name" autocomplete="username" maxlength="40" autocapitalize="none" spellcheck="false"></label>
    <label><?= e('Password') ?><input id="auth-password" type="password" autocomplete="current-password" required minlength="12" maxlength="1024"></label>
    <label class="trap" aria-hidden="true">Site<input id="auth-website" name="website" tabindex="-1" autocomplete="off"></label>
    <label id="confirm-wrap" hidden><?= e('Confirm password') ?><input id="auth-confirm" type="password" autocomplete="new-password"></label>
    <p id="auth-error" class="error" role="alert"></p>
    <button class="btn primary block" id="auth-submit" type="submit" disabled><?= e('Loading…') ?></button>
    <label class="gate-language"><span data-icon="globe"></span><span class="sr-only"><?= e('Language') ?></span><?= $languageMenu('gate-language') ?></label>
  </form>
</div>

<div id="application" class="app" hidden>
  <header class="topbar">
    <a class="brand" href="./"><img src="assets/icon.svg?v=<?= $iconVersion ?>" alt="" width="26" height="26"><span>Atelier</span></a>
    <div class="segmented section-switch" role="group" aria-label="<?= e('Section') ?>">
      <button type="button" data-section="images" aria-pressed="true" title="<?= e('Images') ?>"><span data-icon="image"></span><span><?= e('Images') ?></span></button>
      <button type="button" data-section="stories" aria-pressed="false" title="<?= e('Stories written with Claude or GPT') ?>"><span data-icon="book"></span><span><?= e('Stories') ?></span></button>
      <button type="button" data-section="vn" aria-pressed="false" title="<?= e('Visual novels: a script with choices, routes and endings, sprites, backgrounds, a playable preview and Ren\'Py') ?>"><span data-icon="dialogue"></span><span>Visual Novel</span></button>
    </div>
    <span class="grow"></span>
    <button id="activity" class="activity" type="button" hidden><span class="spinner small" aria-hidden="true"></span><span id="activity-text"></span></button>
    <button id="gpu-chip" class="balance-chip gpu-chip" type="button" hidden><span class="gpu-dot" aria-hidden="true"></span><span class="balance-label">GPU</span><span class="balance-text" id="gpu-chip-text"></span></button>
    <span id="balances" class="balances">
      <button id="balance-chip-openai" class="balance-chip" data-account="openai" type="button" hidden><span class="balance-label">OpenAI</span><span class="balance-text"></span></button>
      <button id="balance-chip-claude" class="balance-chip" data-account="claude" type="button" hidden><span class="balance-label">Claude</span><span class="balance-text"></span></button>
      <button id="balance-chip-gemini" class="balance-chip" data-account="gemini" type="button" hidden><span class="balance-label">Gemini</span><span class="balance-text"></span></button>
    </span>
    <button id="account-open" class="account-chip" type="button" title="<?= e('Signed-in user') ?>"><span data-icon="user"></span><span id="account-name"></span></button>
    <button id="requests-open" class="icon-btn" type="button" data-icon="history" title="<?= e('History: everything you asked for, images and messages to the writer') ?>" aria-label="<?= e('History') ?>"></button>
    <button id="settings-open" class="icon-btn" type="button" data-icon="settings" title="<?= e('Settings') ?>" aria-label="<?= e('Settings') ?>"></button>
    <button id="lock" class="icon-btn" type="button" data-icon="lock" title="<?= e('Sign out and lock') ?>" aria-label="<?= e('Sign out and lock') ?>"></button>
  </header>

  <div class="workspace">
    <aside class="composer" aria-label="<?= e('New image') ?>">
      <form id="generate-form" novalidate>
        <div class="composer-head"><h2><?= e('New image') ?></h2><button type="button" id="reset-composer" class="link-btn"><?= e('Clear') ?></button></div>
        <div class="composer-body">
          <div class="prompt-box" id="drop-area">
            <label class="sr-only" for="prompt">Prompt</label>
            <textarea id="prompt" rows="4" maxlength="32000" placeholder="<?= e('Describe the image') ?>"></textarea>
            <div id="reference-list" class="refs"></div>
            <span id="prompt-count" class="counter" aria-hidden="true"></span>
          </div>
          <label class="lines-box" id="vary-lines-box" hidden><span><?= e('One line per image') ?></span><textarea id="vary-lines" data-vary-lines rows="3" maxlength="10000" placeholder="<?= e('sitting on the beach
running on the sand
from behind, looking at the sea') ?>"></textarea></label>
          <div class="tools" role="group" aria-label="<?= e('References and variations') ?>">
            <button type="button" id="file-open" class="tool" title="<?= e('References from the computer (you can also drag or paste them)') ?>"><span data-icon="image-plus"></span><span><?= e('File') ?></span></button>
            <button type="button" id="gallery-refs" class="tool" title="<?= e('Choose one or more images from the gallery as references') ?>"><span data-icon="gallery"></span><span><?= e('Gallery') ?></span></button>
            <button type="button" id="vary-open" class="tool" aria-pressed="false" title="<?= e('Several different images from a single request: the app varies each one, or you write one line per image') ?>"><span data-icon="variations"></span><span><?= e('Variations') ?></span></button>
            <button type="button" id="video-open" class="tool" title="<?= e('A video with sound from this prompt, made on your GPU (Colab). The references go with it.') ?>"><span data-icon="video"></span><span><?= e('Video') ?></span></button>
            <input id="file-input" type="file" accept="image/png,image/jpeg,image/webp" multiple hidden>
          </div>
          <div class="vary-box" id="vary-box" hidden>
            <div class="vary-head">
              <span class="field-title" id="vary-title"><?= e('Several images') ?></span>
              <div class="segmented small" role="group" aria-labelledby="vary-title">
                <button type="button" data-vary="auto" aria-pressed="true"><?= e('Varied') ?></button>
                <button type="button" data-vary="lines" aria-pressed="false"><?= e('One per line') ?></button>
                <button type="button" data-vary="same" aria-pressed="false"><?= e('Identical') ?></button>
              </div>
            </div>
            <p class="hint" data-vary-hint></p>
          </div>
          <fieldset class="field">
            <legend><?= e('Aspect ratio') ?></legend>
            <div id="ratios" class="ratios"></div>
            <div id="custom-size" class="row2" hidden>
              <label><?= e('Width') ?><input type="number" id="width" min="256" max="3840" step="16" value="1536" inputmode="numeric"></label>
              <label><?= e('Height') ?><input type="number" id="height" min="256" max="3840" step="16" value="1024" inputmode="numeric"></label>
            </div>
          </fieldset>
          <div class="quality-block">
            <div class="field-head">
              <span class="field-title quality-title" id="quality-title"><?= e('Quality') ?></span>
              <label class="switch compact" title="<?= e('Choose the model, quality, complexity and prompt planning one by one') ?>"><input type="checkbox" class="mode-toggle" id="advanced-mode"><span class="switch-track" aria-hidden="true"></span><span><?= e('Advanced') ?></span></label>
            </div>
            <div id="presets" class="presets simple-only" role="group" aria-labelledby="quality-title"></div>
            <p id="preset-summary" class="hint simple-only"></p>
            <label class="advanced-only"><?= e('Model') ?><select id="model"></select></label>
            <div class="row2 advanced-only">
              <label><?= e('Quality') ?><select id="quality"></select></label>
              <label><?= e('Complexity') ?><select id="complexity"></select></label>
            </div>
          </div>
          <details class="more" id="more-options">
            <summary><?= e('More options') ?></summary>
            <div class="more-body">
              <div class="row2">
                <label><?= e('Format') ?><select id="format"><option value="png">PNG</option><option value="webp">WebP</option><option value="jpeg">JPEG</option></select></label>
                <label><?= e('Background') ?><select id="background"><option value="auto"><?= e('Automatic', ['ctx' => 'background']) ?></option><option value="opaque"><?= e('Opaque') ?></option><option value="transparent"><?= e('Transparent') ?></option></select></label>
              </div>
              <label id="compression-wrap" hidden><?= e('Compression') ?><span class="range-row"><input type="range" id="compression" min="0" max="100" value="90"><output id="compression-value">90</output></span></label>
              <div class="row2">
                <label><?= e('Previews') ?><select id="previews"><option value="0"><?= e('Off', ['ctx' => 'previews']) ?></option><option value="1">1</option><option value="2">2</option><option value="3">3</option></select></label>
                <label><?= e('Moderation') ?><select id="moderation"><option value="low"><?= e('Low') ?></option><option value="auto"><?= e('Automatic') ?></option></select></label>
              </div>
              <p class="hint"><?= e('Previews show partial versions while the image is generated. Each preview has a small extra cost.') ?></p>
              <div class="stack advanced-only">
                <label><?= e('Prompt planning') ?><select id="thinking"></select></label>
                <p class="hint"><?= e('Rewrites the prompt before generating: with GPT-6 Sol for the GPT Image models, with Gemini 3.8 Flash for Nano Banana. Extra cost. If it refuses, the image is made with the prompt as written.') ?></p>
              </div>
              <p id="size-note" class="hint"></p>
              <button type="button" id="restore-defaults" class="link-btn"><?= e('Restore defaults') ?></button>
            </div>
          </details>
        </div>
        <div class="composer-foot">
          <p id="generate-error" class="error" role="alert"></p>
          <p id="generate-cost" class="cost-note" hidden></p>
          <div class="submit-row">
            <button type="submit" id="generate" class="btn primary block"><span id="generate-label"><?= e('Generate') ?></span><kbd>Ctrl ↵</kbd></button>
            <select id="quantity" aria-label="<?= e('Number of images') ?>" title="<?= e('Number of images') ?>"></select>
          </div>
        </div>
      </form>
      <div class="col-handle" data-resize="side" data-target=".composer" role="separator" aria-orientation="vertical" tabindex="0" aria-label="<?= e('Width of the new image column') ?>" title="<?= e('Drag to change the column\'s width. Double-click to go back to the default.') ?>"></div>
    </aside>

    <main class="feed" id="feed-view">
      <div class="feed-toolbar">
        <div class="toolbar-main">
          <label class="search"><span data-icon="search"></span><input id="search" type="search" placeholder="<?= e('Search') ?>" aria-label="<?= e('Search the prompts') ?>" autocomplete="off"></label>
          <button type="button" id="scope-button" class="btn ghost small scope-button" aria-haspopup="menu" aria-expanded="false" title="<?= e('Library, albums and trash') ?>"></button>
          <div class="segmented" role="group" aria-label="<?= e('Source') ?>" id="origin-filter">
            <button type="button" data-origin="all" aria-pressed="true"><?= e('All') ?></button>
            <button type="button" data-origin="generated" aria-pressed="false" title="<?= e('Made here: generated, edited, expanded, upscaled') ?>"><?= e('Generated') ?></button>
            <button type="button" data-origin="imported" aria-pressed="false" title="<?= e('Brought from the computer, pasted or saved from videos') ?>"><?= e('Imported', ['ctx' => 'filter']) ?></button>
            <button type="button" data-origin="video" aria-pressed="false"><?= e('Videos') ?></button>
            <button type="button" data-origin="hidden" aria-pressed="false" aria-label="<?= e('Hidden', ['ctx' => 'filter']) ?>" title="<?= e('Hidden: items taken out of the library with Hide. They stay only here (and in the reference picker).') ?>"><span data-icon="eye-off"></span><span class="seg-text"><?= e('Hidden', ['ctx' => 'filter']) ?></span></button>
          </div>
          <button type="button" id="favorites-only" class="chip icon-only" aria-pressed="false" data-icon="star" aria-label="<?= e('Favorites only') ?>" title="<?= e('Favorites only') ?>"></button>
          <button type="button" id="view-button" class="btn ghost small" aria-haspopup="menu" aria-expanded="false"><span data-icon="sort"></span><span id="sort-label"><?= e('Newest') ?></span><span data-icon="chevron-down"></span></button>
          <span class="grow"></span>
          <span id="gallery-count" class="muted"></span>
          <button type="button" id="select-mode" class="btn ghost small" title="<?= e('Select several items. Also: Ctrl + click, or touch and hold on a phone.') ?>"><span data-icon="check-square"></span><span><?= e('Select') ?></span></button>
          <label class="btn ghost small" title="<?= e('Add images or videos from the computer to the gallery. You can also drag them onto the gallery, or paste images with Ctrl+V.') ?>"><span data-icon="upload"></span><span><?= e('Import') ?></span><input id="import-input" type="file" accept="image/png,image/jpeg,image/webp,video/mp4,video/webm,video/quicktime,video/x-m4v,video/x-matroska,.mp4,.m4v,.mov,.webm,.mkv" multiple hidden></label>
        </div>
        <div class="selection-bar" id="selection-bar" hidden>
          <button type="button" class="icon-btn small" id="selection-close" data-icon="close" aria-label="<?= e('Leave the selection') ?>" title="<?= e('Leave the selection (Esc)') ?>"></button>
          <strong id="selection-count" aria-live="polite"></strong>
          <button type="button" class="link-btn" id="select-all"></button>
          <span class="grow"></span>
          <div class="selection-actions">
            <button type="button" class="btn ghost small" data-bulk="favorite"><span data-icon="star"></span><span><?= e('Favorite') ?></span></button>
            <button type="button" class="btn ghost small" data-bulk="album"><span data-icon="album-add"></span><span><?= e('Add to album') ?></span></button>
            <button type="button" class="btn ghost small" data-bulk="album-remove"><span data-icon="album-out"></span><span><?= e('Remove from album') ?></span></button>
            <button type="button" class="btn ghost small" data-bulk="stack" title="<?= e('Put together in a stack: the library shows a single card, like the versions of an image') ?>"><span data-icon="stack"></span><span><?= e('Stack', ['ctx' => 'action']) ?></span></button>
            <button type="button" class="btn ghost small" data-bulk="unstack" title="<?= e('Undo the chosen stacks: each image gets its own card again') ?>"><span data-icon="variations"></span><span><?= e('Unstack') ?></span></button>
            <button type="button" class="btn ghost small" data-bulk="hide" title="<?= e('Take out of the library without deleting. They stay in the Hidden filter.') ?>"><span data-icon="eye-off"></span><span><?= e('Hide') ?></span></button>
            <button type="button" class="btn ghost small" data-bulk="top" title="<?= e('Move to the start of the custom order') ?>"><span data-icon="to-top"></span><span><?= e('To the start') ?></span></button>
            <button type="button" class="btn ghost small" data-bulk="bottom" title="<?= e('Move to the end of the custom order') ?>"><span data-icon="to-bottom"></span><span><?= e('To the end') ?></span></button>
            <button type="button" class="btn ghost small" data-bulk="restore"><span data-icon="undo"></span><span><?= e('Restore') ?></span></button>
            <button type="button" class="btn ghost small danger-text" data-bulk="trash" title="<?= e('Move to the trash (Delete)') ?>"><span data-icon="trash"></span><span><?= e('Trash') ?></span></button>
            <button type="button" class="btn ghost small danger-text" data-bulk="purge" title="<?= e('Delete permanently (Delete)') ?>"><span data-icon="trash"></span><span><?= e('Delete') ?></span></button>
          </div>
        </div>
      </div>
      <div id="feed" class="feed-body"></div>
      <div id="feed-empty" class="empty" hidden></div>
      <div id="feed-loading" class="feed-loading" hidden><span class="spinner"></span></div>
      <div id="drop-overlay" class="drop-overlay" hidden><span data-icon="upload"></span><span><?= e('Drop to import') ?></span></div>
      <div id="timeline" class="date-rail" role="slider" tabindex="0" aria-label="<?= e('Gallery timeline') ?>" aria-orientation="vertical" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden>
        <div class="timeline-marks" aria-hidden="true"></div>
        <div class="timeline-thumb" aria-hidden="true"><span class="timeline-bubble"></span></div>
        <div class="timeline-hover" aria-hidden="true"><span class="timeline-bubble"></span></div>
      </div>
    </main>
  </div>

  <div class="stories" id="stories-view" hidden>
    <section class="stories-home" id="stories-home">
      <div class="stories-head">
        <div><h2 id="stories-title"><?= e('Stories') ?></h2><p class="hint" id="stories-hint"><?= e('Written with Claude or GPT, planned from beginning to end, one chapter at a time.') ?></p></div>
        <span class="grow"></span>
        <button type="button" id="stories-history" class="btn ghost small" title="<?= e('History: everything you asked for, the messages to the writer and the images') ?>"><span data-icon="history"></span><span><?= e('History') ?></span></button>
        <label class="btn ghost small" id="story-import" title="<?= e('A story downloaded from here as a .zip, which comes in as a new story. A zip with only chapters in Markdown (.md) also works.') ?>"><span data-icon="upload"></span><span><?= e('Import') ?></span><input id="story-import-input" type="file" accept=".zip,application/zip,application/x-zip-compressed" hidden></label>
        <button type="button" id="story-new" class="btn primary small"><span data-icon="plus"></span><span id="story-new-label"><?= e('New story') ?></span></button>
      </div>
      <div id="stories-grid" class="stories-grid"></div>
      <div id="stories-empty" class="empty compact" hidden><span data-icon="book"></span><strong id="stories-empty-title"><?= e('No stories yet') ?></strong><span class="hint" id="stories-empty-hint"><?= e('Create one and tell the writer the idea.') ?></span></div>
    </section>
    <section class="story" id="story" hidden></section>
  </div>
</div>

<dialog id="story-doc-dialog" class="sheet wide story-doc-sheet" aria-labelledby="story-doc-title">
  <form id="story-doc-form" class="stack">
    <div class="sheet-head"><h2 id="story-doc-title"></h2><button type="button" class="icon-btn" data-close="story-doc-dialog" data-icon="close" aria-label="<?= e('Close') ?>"></button></div>
    <p id="story-doc-hint" class="hint"></p>
    <textarea id="story-doc-text" rows="22" maxlength="200000" aria-labelledby="story-doc-title" spellcheck="true"></textarea>
    <p id="story-doc-meta" class="muted"></p>
    <p id="story-doc-error" class="error" role="alert"></p>
    <div class="row-end"><button type="button" class="btn ghost" data-close="story-doc-dialog"><?= e('Close') ?></button><button type="submit" class="btn primary"><?= e('Save') ?></button></div>
  </form>
</dialog>

<dialog id="writer-dialog" class="sheet" aria-labelledby="writer-title">
  <form id="writer-form" class="stack">
    <div class="sheet-head"><h2 id="writer-title"><?= e('Writer of this story') ?></h2><button type="button" class="icon-btn" data-close="writer-dialog" data-icon="close" aria-label="<?= e('Close') ?>"></button></div>
    <div class="segmented" id="writer-provider" role="group" aria-label="<?= e('Who writes') ?>">
      <button type="button" data-provider="claude" aria-pressed="true">Claude</button>
      <button type="button" data-provider="openai" aria-pressed="false">GPT</button>
      <button type="button" data-provider="gemini" aria-pressed="false">Gemini</button>
    </div>
    <div class="row2">
      <label><?= e('Model') ?><select id="writer-model"></select></label>
      <label><?= e('Reasoning') ?><select id="writer-effort"></select></label>
    </div>
    <p id="writer-key-hint" class="error" hidden></p>
    <div id="writer-switch" class="stack" hidden>
      <div class="segmented" id="writer-conversation" role="group" aria-label="<?= e('When switching writers') ?>">
        <button type="button" data-conversation="same" aria-pressed="true"><?= e('Keep this conversation') ?></button>
        <button type="button" data-conversation="new" aria-pressed="false"><?= e('Start a new conversation') ?></button>
      </div>
      <p class="hint" id="writer-switch-hint"></p>
    </div>
    <p class="hint"><?= e('You can switch writers at any time, even between Claude, GPT and Gemini. More reasoning plans better and costs more.') ?></p>
    <label><?= e('Length of the chapters (words, roughly)') ?><input id="writer-chapter-words" type="number" min="300" max="20000" step="100" inputmode="numeric"></label>
    <div class="stack tight">
      <span class="field-label" id="writer-illustrate-label"><?= e('Illustrations') ?></span>
      <div class="segmented" id="writer-illustrate" role="group" aria-labelledby="writer-illustrate-label">
        <button type="button" data-illustrate="text" aria-pressed="false"><?= e('Text only') ?></button>
        <button type="button" data-illustrate="ask" aria-pressed="true"><?= e('When I ask') ?></button>
        <button type="button" data-illustrate="each" aria-pressed="false"><?= e('One per chapter') ?></button>
      </div>
      <p class="hint" id="writer-illustrate-hint"></p>
    </div>
    <label class="switch"><input type="checkbox" id="writer-cover"><span class="switch-track" aria-hidden="true"></span><span><?= e('The writer makes the cover while planning the story') ?></span></label>
    <label class="switch"><input type="checkbox" id="writer-sheets"><span class="switch-track" aria-hidden="true"></span><span><?= e('The writer builds the character sheets as the story moves on') ?></span></label>
    <p class="hint"><?= e('Turned off (or with Text only), it only makes the cover and the sheets when you ask. The sheets are reference images of each character (in several phases, if the story calls for it), used in the illustrations to keep each one the same.') ?></p>
    <div class="row3">
      <label><?= e('Image model') ?><select id="writer-image-model"></select></label>
      <label><?= e('Image quality') ?><select id="writer-image-quality"><option value="low"><?= e('Low') ?></option><option value="medium"><?= e('Medium') ?></option><option value="high"><?= e('High') ?></option></select></label>
      <label><?= e('Size of the images') ?><select id="writer-image-scale"><option value="1"><?= e('Standard') ?></option><option value="1.5">1,5×</option><option value="2">2×</option></select></label>
    </div>
    <p class="hint" id="writer-image-size-hint"></p>
    <p class="hint"><?= e('The illustrations, the covers and the sheets use the key of the image model (OpenAI for GPT Image, Gemini for Nano Banana) and go to the library, in the story\'s album.') ?></p>
    <p id="writer-error" class="error" role="alert"></p>
    <div class="row-end"><button type="button" class="btn ghost" data-close="writer-dialog"><?= e('Cancel') ?></button><button type="submit" class="btn primary"><?= e('Save') ?></button></div>
  </form>
</dialog>

<dialog id="illustrate-dialog" class="sheet" aria-labelledby="illustrate-title">
  <form id="illustrate-form" class="stack">
    <div class="sheet-head"><h2 id="illustrate-title"><?= e('Illustrate the story') ?></h2><button type="button" class="icon-btn" data-close="illustrate-dialog" data-icon="close" aria-label="<?= e('Close') ?>"></button></div>
    <p class="hint"><?= e('The writer reads the chapters and puts each image right after the passage it shows. The images also go to the library, in the story\'s album.') ?></p>
    <div class="row2">
      <label><?= e('From chapter') ?><select id="illustrate-from"></select></label>
      <label><?= e('To chapter') ?><select id="illustrate-to"></select></label>
    </div>
    <label><?= e('Images per chapter') ?><select id="illustrate-per"><option value="1"><?= e('1, at the most striking moment') ?></option><option value="2">2</option><option value="3">3</option></select></label>
    <label class="switch"><input type="checkbox" id="illustrate-cover"><span class="switch-track" aria-hidden="true"></span><span><?= e('Also make the cover (3 options to choose from)') ?></span></label>
    <label><?= e('Guidance for the writer (optional)') ?><textarea id="illustrate-notes" rows="3" maxlength="2000" placeholder="<?= e('Style, scenes you want to see, what to avoid…') ?>"></textarea></label>
    <p id="illustrate-cost" class="hint"></p>
    <p id="illustrate-error" class="error" role="alert"></p>
    <div class="row-end"><button type="button" class="btn ghost" data-close="illustrate-dialog"><?= e('Cancel') ?></button><button type="submit" class="btn primary"><?= e('Ask the writer') ?></button></div>
  </form>
</dialog>

<dialog id="story-details-dialog" class="sheet details-sheet" aria-labelledby="story-details-title">
  <form id="story-details-form" class="stack">
    <div class="sheet-head"><h2 id="story-details-title"><?= e('Story') ?></h2><button type="button" class="icon-btn" data-close="story-details-dialog" data-icon="close" aria-label="<?= e('Close') ?>"></button></div>
    <div class="details-body">
      <button type="button" id="story-details-cover" class="details-cover" title="<?= e('See the cover in full screen') ?>" aria-label="<?= e('See the cover in full screen') ?>" hidden></button>
      <div class="details-fields stack">
        <label><?= e('Title') ?><input id="story-title-input" maxlength="200" autocomplete="off"></label>
        <label><?= e('Series (optional)') ?><input id="story-series-input" maxlength="120" autocomplete="off"></label>
        <label class="details-synopsis"><?= e('Synopsis') ?><textarea id="story-synopsis-input" rows="5" maxlength="6000"></textarea></label>
        <p class="hint"><?= e('The writer can also change the title and the synopsis when you ask.') ?></p>
      </div>
    </div>
    <p id="story-details-error" class="error" role="alert"></p>
    <div class="row-end"><button type="button" class="btn ghost" data-close="story-details-dialog"><?= e('Cancel') ?></button><button type="submit" class="btn primary"><?= e('Save') ?></button></div>
  </form>
</dialog>

<dialog id="story-cover-dialog" class="sheet" aria-labelledby="story-cover-title">
  <div class="sheet-head"><h2 id="story-cover-title"><?= e('Story cover') ?></h2><button type="button" class="icon-btn" data-close="story-cover-dialog" data-icon="close" aria-label="<?= e('Close') ?>"></button></div>
  <div id="story-cover-body" class="stack"></div>
</dialog>

<dialog id="story-cast-dialog" class="sheet wide" aria-labelledby="story-cast-title">
  <div class="sheet-head"><h2 id="story-cast-title"><?= e('Sheets and style') ?></h2><button type="button" class="icon-btn" data-close="story-cast-dialog" data-icon="close" aria-label="<?= e('Close') ?>"></button></div>
  <div id="story-cast-body" class="stack"></div>
</dialog>

<dialog id="comic-dialog" class="sheet wide comic-sheet" aria-labelledby="comic-title">
  <div class="sheet-head"><h2 id="comic-title"><?= e('Comics') ?></h2><button type="button" class="icon-btn" data-close="comic-dialog" data-icon="close" aria-label="<?= e('Close') ?>"></button></div>
  <div id="comic-body" class="stack"></div>
</dialog>

<dialog id="comic-reader" class="comic-reader" aria-label="<?= e('Comics reader') ?>"></dialog>

<dialog id="story-export-dialog" class="sheet narrow" aria-labelledby="story-export-title">
  <div class="sheet-head"><h2 id="story-export-title"><?= e('Download the story') ?></h2><button type="button" class="icon-btn" data-close="story-export-dialog" data-icon="close" aria-label="<?= e('Close') ?>"></button></div>
  <div class="stack">
    <button type="button" id="export-renpy" class="btn primary block" data-vn hidden><span><?= e('Game for Ren\'Py (.zip with the script, the characters, the variables and the images)') ?></span></button>
    <button type="button" id="export-pack" class="btn secondary block"><span data-story><?= e('The whole story (.zip, to keep or import again)') ?></span><span data-vn hidden><?= e('The whole project (.zip, to keep or import again)') ?></span></button>
    <button type="button" id="export-html" class="btn secondary block" data-story><span><?= e('Page to read or print (.html, with the illustrations)') ?></span></button>
    <button type="button" id="export-epub" class="btn secondary block" data-story><span><?= e('E-book (.epub, with the cover and the illustrations)') ?></span></button>
    <button type="button" id="export-md" class="btn secondary block" data-story><span><?= e('Text in Markdown (.md)') ?></span></button>
    <p class="hint" data-story><?= e('The zip takes everything: chapters, bible, plan (with the spoilers), sheets, style, comics, the conversation with the writer and the images. It comes back in Stories › Import. The page, the EPUB and the Markdown take only the text, without the plan. For a PDF, open the page in the browser and use Print → Save as PDF.') ?></p>
    <p class="hint" data-story><?= e('The EPUB opens in Apple Books, Google Play Books, Kobo and on Kindle (through Send to Kindle). The book\'s author is the name in “Author on the cover”, in the cover window.') ?></p>
    <p class="hint" data-vn hidden><?= e('The game for Ren\'Py 8: create a new project in Ren\'Py and copy the zip\'s game/ folder into it. Along with it go the whole script to read (roteiro.md) and the list of music and sounds to put in the game/audio folder. The project zip takes everything (scenes, tree, art, sheets, the conversation with the writer) and comes back in Visual Novel › Import.') ?></p>
    <p id="export-pack-status" class="hint" hidden></p>
  </div>
</dialog>

<dialog id="story-usage-dialog" class="sheet wide" aria-labelledby="story-usage-title">
  <div class="sheet-head"><h2 id="story-usage-title"><?= e('Cost of this story') ?></h2><button type="button" class="icon-btn" data-close="story-usage-dialog" data-icon="close" aria-label="<?= e('Close') ?>"></button></div>
  <div id="story-usage-body" class="stack"></div>
</dialog>

<dialog id="viewer" class="viewer" aria-label="<?= e('Image') ?>">
  <div class="viewer-shell">
    <header class="viewer-bar">
      <button class="icon-btn" type="button" id="viewer-close" data-icon="back" aria-label="<?= e('Back') ?>" title="<?= e('Back (Esc)') ?>"></button>
      <span id="viewer-version" class="pill"></span>
      <span class="grow"></span>
      <button class="icon-btn" type="button" id="viewer-prev" data-icon="chevron-left" aria-label="<?= e('Previous') ?>" title="<?= e('Previous (←)') ?>"></button>
      <button class="icon-btn" type="button" id="viewer-next" data-icon="chevron-right" aria-label="<?= e('Next') ?>" title="<?= e('Next (→)') ?>"></button>
      <button class="icon-btn" type="button" id="viewer-fullscreen" data-icon="fullscreen" aria-label="<?= e('Full screen') ?>" aria-pressed="false" title="<?= e('Full screen (T)') ?>"></button>
      <span class="bar-divider"></span>
      <button class="icon-btn" type="button" id="viewer-favorite" data-icon="star" aria-label="<?= e('Favorite') ?>" aria-pressed="false" title="<?= e('Favorite (F)') ?>"></button>
      <button class="icon-btn" type="button" id="viewer-album" data-icon="album-add" aria-label="<?= e('Add to album') ?>" title="<?= e('Add to album') ?>"></button>
      <button class="icon-btn" type="button" id="viewer-hide" data-icon="eye-off" aria-label="<?= e('Hide from the library') ?>" title="<?= e('Hide from the library (it stays in the Hidden filter)') ?>"></button>
      <a class="icon-btn" id="viewer-download" data-icon="download" aria-label="<?= e('Download') ?>" title="<?= e('Download') ?>"></a>
      <button class="icon-btn danger" type="button" id="viewer-trash" data-icon="trash" aria-label="<?= e('Move to the trash') ?>" title="<?= e('Move to the trash') ?>"></button>
    </header>

    <aside class="versions" id="versions-aside" aria-label="<?= e('Versions') ?>">
      <div class="panel-head image-only">
        <h3 id="versions-title" title="<?= e('↑ and ↓ go through the versions in the order of this list') ?>"><?= e('Versions') ?></h3>
        <div class="segmented small" role="group" aria-label="<?= e('Version view') ?>">
          <button type="button" data-versions="tree" aria-pressed="true"><span data-icon="branch"></span><?= e('Tree') ?></button>
          <button type="button" data-versions="time" aria-pressed="false"><span data-icon="clock"></span><?= e('Chronological') ?></button>
        </div>
      </div>
      <div id="versions-list" class="versions-list image-only"></div>
      <div class="panel-head video-only" id="stack-head" hidden><h3 title="<?= e('↑ and ↓ go through the stack\'s items') ?>"><?= e('Stack') ?></h3><span id="stack-count" class="muted"></span></div>
      <div id="stack-list" class="versions-list stack-list video-only" hidden></div>
      <div id="cover-bar" class="cover-bar" hidden>
        <p id="cover-note" class="hint"></p>
        <button type="button" id="cover-toggle" class="btn secondary small"></button>
      </div>
      <div id="stack-bar" class="cover-bar stack-bar" hidden>
        <p id="stack-note" class="hint"></p>
        <div class="stack-actions">
          <button type="button" id="stack-leave" class="btn secondary small"><span data-icon="album-out"></span><span><?= e('Take out of the stack') ?></span></button>
          <button type="button" id="stack-undo" class="btn ghost small"><span data-icon="variations"></span><span><?= e('Undo the stack') ?></span></button>
        </div>
      </div>
      <div class="panel-head video-only"><h3><?= e('Saved frames') ?></h3><span id="frames-count" class="muted"></span></div>
      <div id="frames-list" class="versions-list video-only"></div>
    </aside>

    <div class="col-handle at-start" data-resize="side" data-target=".inspector" role="separator" aria-orientation="vertical" tabindex="0" aria-label="<?= e('Width of the edit column') ?>" title="<?= e('Drag to change the column\'s width. Double-click to go back to the default.') ?>"></div>
    <div class="col-handle at-end" data-resize="versions" data-target=".versions" role="separator" aria-orientation="vertical" tabindex="0" aria-label="<?= e('Width of the versions column') ?>" title="<?= e('Drag to change the column\'s width. Double-click to go back to the default.') ?>"></div>
    <section class="stage" id="stage">
      <div id="stage-frame" class="stage-frame">
        <img id="stage-image" alt="">
        <canvas id="mask-canvas" class="mask-canvas" hidden aria-label="<?= e('Paint the area to change') ?>"></canvas>
        <video id="stage-video" class="video-only" playsinline preload="auto" disablepictureinpicture disableremoteplayback></video>
      </div>
      <div id="brush-cursor" class="brush-cursor" hidden></div>
      <span id="compare-label" class="stage-label" hidden></span>
      <div id="expand-stage" class="expand-stage" hidden>
        <canvas id="expand-canvas" aria-label="<?= e('Preview of the crop and the expansion') ?>"></canvas>
        <div id="expand-frame" class="expand-frame"><button type="button" class="resize-handle nw" data-corner="nw" aria-label="<?= e('Drag the frame\'s top left corner') ?>"></button><button type="button" class="resize-handle n" data-corner="n" aria-label="<?= e('Drag the frame\'s top edge') ?>"></button><button type="button" class="resize-handle ne" data-corner="ne" aria-label="<?= e('Drag the frame\'s top right corner') ?>"></button><button type="button" class="resize-handle e" data-corner="e" aria-label="<?= e('Drag the frame\'s right edge') ?>"></button><button type="button" class="resize-handle se" data-corner="se" aria-label="<?= e('Drag the frame\'s bottom right corner') ?>"></button><button type="button" class="resize-handle s" data-corner="s" aria-label="<?= e('Drag the frame\'s bottom edge') ?>"></button><button type="button" class="resize-handle sw" data-corner="sw" aria-label="<?= e('Drag the frame\'s bottom left corner') ?>"></button><button type="button" class="resize-handle w" data-corner="w" aria-label="<?= e('Drag the frame\'s left edge') ?>"></button></div>
      </div>
      <div id="stage-loading" class="stage-loading" hidden><span class="spinner"></span></div>
      <div class="stage-bar" id="stage-bar">
        <button type="button" id="compare" class="chip" aria-pressed="false" hidden><span data-icon="compare"></span><span id="compare-text"><?= e('Compare') ?></span></button>
        <button type="button" id="animate-open" class="chip image-only" title="<?= e('A video with sound that starts from this image, made on your GPU (Colab).') ?>"><span data-icon="video"></span><span><?= e('Animate') ?></span></button>
        <span id="stage-meta" class="muted"></span>
        <button type="button" id="zoom-reset" class="chip" hidden title="<?= e('Fit to the screen (key 0)') ?>"><span id="zoom-text">100%</span><span><?= e('Fit') ?></span></button>
      </div>
      <div class="video-bar video-only" id="video-bar">
        <input type="range" id="video-scrub" min="0" max="0" step="1" value="0" aria-label="<?= e('Video frame', ['ctx' => 'slider']) ?>">
        <div class="video-controls">
          <button type="button" class="icon-btn" id="video-first" data-icon="skip-back" aria-label="<?= e('First frame') ?>" title="<?= e('First frame (Home)') ?>"></button>
          <button type="button" class="icon-btn" id="video-back10" data-icon="rewind" aria-label="<?= e('Back 10 frames') ?>" title="<?= e('Back 10 frames (Shift + ,)') ?>"></button>
          <button type="button" class="icon-btn" id="video-back" data-icon="step-back" aria-label="<?= e('Previous frame') ?>" title="<?= e('Previous frame (,)') ?>"></button>
          <button type="button" class="icon-btn play" id="video-play" data-icon="play" aria-label="<?= e('Play') ?>" title="<?= e('Play (Space)') ?>"></button>
          <button type="button" class="icon-btn" id="video-forward" data-icon="step-forward" aria-label="<?= e('Next frame') ?>" title="<?= e('Next frame (.)') ?>"></button>
          <button type="button" class="icon-btn" id="video-forward10" data-icon="fast-forward" aria-label="<?= e('Forward 10 frames') ?>" title="<?= e('Forward 10 frames (Shift + .)') ?>"></button>
          <button type="button" class="icon-btn" id="video-last" data-icon="skip-forward" aria-label="<?= e('Last frame') ?>" title="<?= e('Last frame (End)') ?>"></button>
          <span class="video-readout" aria-live="off"><strong id="video-frame"></strong><span id="video-time"></span></span>
          <span class="grow"></span>
          <select id="video-rate" class="compact-select" aria-label="<?= e('Speed') ?>" title="<?= e('Playback speed') ?>"><option value="0.25">0,25×</option><option value="0.5">0,5×</option><option value="1" selected>1×</option><option value="2">2×</option></select>
          <button type="button" class="icon-btn" id="video-mute" data-icon="volume" aria-label="<?= e('Muted') ?>" aria-pressed="false" title="<?= e('Muted (M)') ?>"></button>
        </div>
      </div>
      <div class="brush-bar" id="brush-bar" hidden>
        <span class="brush-hint"><?= e('Paint what should be redone') ?></span>
        <label class="brush-size"><span class="sr-only"><?= e('Brush size') ?></span><span data-icon="brush"></span><input type="range" id="brush-size" min="6" max="220" value="56"></label>
        <button type="button" id="mask-clear" class="chip"><?= e('Clear') ?></button>
        <button type="button" id="mask-done" class="chip" title="<?= e('Keep the marked area and go write the request') ?>"><?= e('Done') ?></button>
        <button type="button" id="mask-go" class="chip strong" title="<?= e('Generate a new version with only the painted area redone. The text in the box, if any, says what should appear in it. (Ctrl+Enter)') ?>"><?= e('Redo area') ?></button>
      </div>
    </section>

    <aside class="inspector">
      <div id="video-panel" class="inspector-body video-only">
        <section class="stack">
          <h3><?= e('Current frame') ?></h3>
          <p class="frame-now"><strong id="frame-label"></strong><span id="frame-at" class="muted"></span></p>
          <div class="frame-actions">
            <button type="button" id="frame-edit" class="btn primary"><span data-icon="pencil"></span><?= e('Edit this frame') ?></button>
            <button type="button" id="frame-upscale" class="btn secondary"><span data-icon="upscale"></span><?= e('Upscale', ['ctx' => 'action']) ?></button>
            <button type="button" id="frame-reference" class="btn secondary"><span data-icon="image-plus"></span><?= e('Use as reference') ?></button>
            <button type="button" id="frame-animate" class="btn secondary"><span data-icon="video"></span><?= e('Continue as a video') ?></button>
            <button type="button" id="frame-save" class="btn secondary"><span data-icon="camera"></span><?= e('Save to the gallery') ?></button>
            <button type="button" id="frame-download" class="btn ghost"><span data-icon="download"></span><?= e('Download PNG') ?></button>
          </div>
          <p id="frame-status" class="hint" aria-live="polite"></p>
          <p class="hint"><?= e('Comma and period move one frame; with Shift, 10. ← and → go to the previous or next item. Space plays or pauses. The mouse wheel zooms in to check details. The frame is saved at the video\'s resolution.') ?></p>
        </section>
        <section class="stack" id="video-from-part">
          <h3><?= e('A new video from this one') ?></h3>
          <p class="hint"><?= e('On the GPU: continue it, redo it following its movement, or replace a part of it.') ?></p>
          <div><button type="button" id="video-from" class="btn secondary"><span data-icon="video"></span><?= e('Make a video from this one') ?></button></div>
        </section>
        <section id="video-upload" class="upload-box" hidden>
          <div class="upload-head"><span id="video-upload-text" class="grow"></span><button type="button" id="video-upload-action" class="link-btn"></button></div>
          <div class="progress" aria-hidden="true"><span id="video-upload-bar"></span></div>
        </section>
        <section class="info">
          <h3><?= e('Video') ?></h3>
          <dl id="video-details" class="details"></dl>
          <label><?= e('Frames per second') ?><select id="video-fps"></select></label>
          <p id="video-fps-hint" class="hint"></p>
        </section>
      </div>

      <div id="edit-panel" class="inspector-body image-only">
        <form id="edit-form" class="stack edit-form" novalidate>
          <div class="form-head"><h3><?= e('Edit this image') ?></h3><button type="button" id="edit-clear" class="link-btn" title="<?= e('Empty the text box') ?>" hidden><?= e('Clear text') ?></button></div>
          <div class="prompt-box" id="edit-drop">
            <label class="sr-only" for="edit-prompt"><?= e('Request text') ?></label>
            <textarea id="edit-prompt" rows="3" maxlength="32000" placeholder="<?= e('What should change?') ?>"></textarea>
            <div id="edit-refs" class="refs"></div>
          </div>
          <label class="lines-box" id="edit-vary-lines-box" hidden><span><?= e('One line per image') ?></span><textarea id="edit-vary-lines" data-vary-lines rows="3" maxlength="10000" placeholder="<?= e('sitting on the beach
running on the sand
from behind, looking at the sea') ?>"></textarea></label>
          <div class="tools" role="group" aria-label="<?= e('Edit tools') ?>">
            <button type="button" id="brush-toggle" class="tool" aria-pressed="false" title="<?= e('Paint the area to redo: only it changes. Without text, that part is redone; with text, it becomes what you ask for. (B)') ?>"><span data-icon="brush"></span><span><?= e('Area') ?></span></button>
            <button type="button" id="edit-file-open" class="tool" title="<?= e('References from the computer (you can also drag or paste them)') ?>"><span data-icon="image-plus"></span><span><?= e('File') ?></span></button>
            <button type="button" id="edit-gallery-refs" class="tool" title="<?= e('Choose one or more images from the gallery as references') ?>"><span data-icon="gallery"></span><span><?= e('Gallery') ?></span></button>
            <button type="button" id="edit-vary-open" class="tool" aria-pressed="false" title="<?= e('Several different versions of this image from a single request (for example, poses or scenes): the app varies each one, or you write one line per image') ?>"><span data-icon="variations"></span><span><?= e('Variations') ?></span></button>
            <button type="button" id="upscale-open" class="tool" aria-pressed="false" aria-controls="action-panel" title="<?= e('Recreate at a higher resolution. The text in the box, if any, says what to improve.') ?>"><span data-icon="upscale"></span><span><?= e('Upscale', ['ctx' => 'action']) ?></span></button>
            <button type="button" id="expand-open" class="tool" aria-pressed="false" aria-controls="action-panel" title="<?= e('Crop the image and enlarge the canvas beyond its edges, on whichever side you want. The text in the box, if any, says what should appear in the new areas.') ?>"><span data-icon="expand"></span><span><?= e('Expand') ?></span></button>
            <button type="button" id="photoreal" class="tool" aria-pressed="false" aria-controls="action-panel" title="<?= e('The same image as a real photo, from a professional camera or a phone. Keeps the composition, the people, the anatomy and every detail; only the style changes. The text in the box, if any, goes along as a note and has priority.') ?>"><span data-icon="camera"></span><span><?= e('Photoreal') ?></span></button>
            <button type="button" id="enhance" class="tool" aria-pressed="false" aria-controls="action-panel" title="<?= e('Recreates the same image clean, without noise, specks, grain, artifacts or strange textures, with natural skin in the same tone and no oily shine, without changing the style, the people or the composition. The text in the box, if any, goes along as a note and has priority.') ?>"><span data-icon="sparkle"></span><span><?= e('Enhance') ?></span></button>
            <button type="button" id="clean" class="tool" aria-pressed="false" aria-controls="action-panel" title="<?= e('Removes specks and the web of fine lines from the image right on your device, without using the API and at no cost. Outlines, eyes, hair and details stay sharp. The result becomes a new version.') ?>"><span data-icon="eraser"></span><span><?= e('Clean') ?></span></button>
            <input id="edit-file" type="file" accept="image/png,image/jpeg,image/webp" multiple hidden>
          </div>
          <div id="mask-chip" class="mask-chip" hidden><span data-icon="brush"></span><span><?= e('Only the marked area') ?></span><button type="button" id="mask-remove" class="icon-btn tiny" data-icon="close" aria-label="<?= e('Remove the marked area') ?>"></button></div>
          <div class="vary-box" id="edit-vary-box" hidden>
            <div class="vary-head">
              <span class="field-title" id="edit-vary-title"><?= e('Several images') ?></span>
              <div class="segmented small" role="group" aria-labelledby="edit-vary-title">
                <button type="button" data-vary="auto" aria-pressed="true"><?= e('Varied') ?></button>
                <button type="button" data-vary="lines" aria-pressed="false"><?= e('One per line') ?></button>
                <button type="button" data-vary="same" aria-pressed="false"><?= e('Identical') ?></button>
              </div>
            </div>
            <p class="hint" data-vary-hint></p>
          </div>
          <div id="action-panel" class="action-panel" hidden>
            <div class="action-head"><span id="action-icon" aria-hidden="true"></span><strong id="action-title"></strong><span class="grow"></span><button type="button" id="action-close" class="icon-btn tiny" data-icon="close" title="<?= e('Back to the edit (Esc)') ?>" aria-label="<?= e('Back to the edit') ?>"></button></div>
            <p class="hint" id="action-hint"></p>
            <p class="action-note" id="action-note"></p>
            <div id="preset-before" class="preset-before" title="<?= e('Removes specks and fine lines from the image, on your device, before sending it as a reference, so the result does not copy those flaws. The prompt says the blur was intentional and asks for the focus back. The image in the gallery does not change. Use Strong on images that have been through many edits.') ?>">
              <span class="field-title" id="preset-before-title"><?= e('Clean before sending') ?></span>
              <div class="segmented small" role="group" aria-labelledby="preset-before-title">
                <button type="button" data-before="off" aria-pressed="false"><?= e('No') ?></button>
                <button type="button" data-before="leve" aria-pressed="false"><?= e('Light') ?></button>
                <button type="button" data-before="media" aria-pressed="true"><?= e('Medium') ?></button>
                <button type="button" data-before="forte" aria-pressed="false"><?= e('Strong') ?></button>
              </div>
            </div>
            <div id="preset-levels" class="segmented small" role="group" aria-label="<?= e('Cleaning strength') ?>" hidden>
              <button type="button" data-level="leve" aria-pressed="false"><?= e('Light') ?></button>
              <button type="button" data-level="media" aria-pressed="true"><?= e('Medium') ?></button>
              <button type="button" data-level="forte" aria-pressed="false"><?= e('Strong') ?></button>
            </div>
            <div id="upscale-options" class="stack" hidden>
              <fieldset class="field">
                <legend><?= e('Size') ?></legend>
                <div id="upscale-sizes" class="size-options"></div>
                <p id="upscale-current" class="hint"></p>
              </fieldset>
              <div class="preset-before">
                <span class="field-title" id="upscale-with-title"><?= e('Along with the upscale') ?></span>
                <div id="upscale-with" class="segmented small" role="group" aria-labelledby="upscale-with-title">
                  <button type="button" data-with="none" aria-pressed="true" title="<?= e('Only raises the resolution, cleaning noise and artifacts, without changing anything.') ?>"><?= e('Upscale only') ?></button>
                  <button type="button" data-with="enhance" aria-pressed="false" title="<?= e('Does the same cleanup as the Enhance button: removes noise, artifacts and strange textures and improves the skin and the overall quality, without changing the style.') ?>"><?= e('Enhance') ?></button>
                  <button type="button" data-with="photoreal" aria-pressed="false" title="<?= e('Uses the Photoreal button\'s prompt: the same scene photographed for real, already at the larger size.') ?>"><?= e('Photoreal') ?></button>
                </div>
              </div>
              <div class="row2">
                <label><?= e('Model') ?><select id="upscale-model"></select></label>
                <label><?= e('Quality') ?><select id="upscale-quality"></select></label>
              </div>
            </div>
            <div id="expand-options" class="stack" hidden>
              <div class="row2">
                <label><?= e('Width') ?><input id="expand-width" type="number" step="16" min="16" max="3840" inputmode="numeric"></label>
                <label><?= e('Height') ?><input id="expand-height" type="number" step="16" min="16" max="3840" inputmode="numeric"></label>
              </div>
              <div class="expand-line">
                <button type="button" id="expand-fit" class="link-btn" title="<?= e('Make the frame the size of the image, to start a crop') ?>"><?= e('Frame on the image') ?></button>
                <button type="button" id="expand-center" class="link-btn" title="<?= e('Put the image in the middle of the frame') ?>"><?= e('Center') ?></button>
              </div>
              <p id="expand-dimensions" class="hint" aria-live="polite"></p>
              <div class="row2" id="expand-engine">
                <label><?= e('Model') ?><select id="expand-model"></select></label>
                <label><?= e('Quality') ?><select id="expand-quality"></select></label>
              </div>
            </div>
          </div>
          <div class="quality-block" id="edit-quality-block">
            <div class="field-head">
              <span class="field-title quality-title" id="edit-quality-title"><?= e('Quality') ?></span>
              <label class="switch compact" title="<?= e('Choose the model, quality, complexity and prompt planning one by one') ?>"><input type="checkbox" class="mode-toggle" id="edit-advanced-mode"><span class="switch-track" aria-hidden="true"></span><span><?= e('Advanced') ?></span></label>
            </div>
            <div id="edit-presets" class="presets simple-only" role="group" aria-labelledby="edit-quality-title"></div>
            <p id="edit-preset-summary" class="hint simple-only"></p>
            <label class="advanced-only"><?= e('Model') ?><select id="edit-model"></select></label>
            <div class="row2 advanced-only">
              <label><?= e('Quality') ?><select id="edit-quality"></select></label>
              <label><?= e('Complexity') ?><select id="edit-complexity"></select></label>
            </div>
          </div>
          <details class="more" id="edit-more">
            <summary><?= e('More options') ?></summary>
            <div class="more-body">
              <div class="row2">
                <label><?= e('Previews') ?><select id="edit-previews"><option value="0"><?= e('Off', ['ctx' => 'previews']) ?></option><option value="1">1</option><option value="2">2</option><option value="3">3</option></select></label>
                <label class="advanced-only"><?= e('Planning') ?><select id="edit-thinking"></select></label>
              </div>
              <label><?= e('Clean before sending') ?><select id="edit-clean"><option value="off"><?= e('No') ?></option><option value="leve"><?= e('Light') ?></option><option value="media"><?= e('Medium') ?></option><option value="forte"><?= e('Strong') ?></option></select></label>
              <p class="hint"><?= e('Removes specks and fine lines from the image, on your device, before sending it as a reference, so the edit does not copy those flaws. The prompt says the blur was intentional and asks for the focus back. The image in the gallery does not change. It applies to edits, Photoreal, Enhance and Upscale, and has no cost. With a painted area the image always goes as it is, so the rest of it comes back sharp. With No, the image goes as it is (better for cards and art with text). The choice is remembered for this user.') ?></p>
            </div>
          </details>
          <div class="edit-foot">
            <p id="edit-error" class="error" role="alert"></p>
            <p id="edit-cost" class="cost-note" hidden></p>
            <div class="submit-row">
              <button type="submit" class="btn primary block" id="edit-submit"><span id="edit-submit-label"><?= e('Generate edit') ?></span><kbd>Ctrl ↵</kbd></button>
              <select id="edit-quantity" aria-label="<?= e('Number of images') ?>" title="<?= e('Number of images') ?>"></select>
            </div>
          </div>
        </form>

        <section class="info" aria-label="<?= e('Details') ?>">
          <div id="typed-wrap" class="typed" hidden>
            <div class="info-head"><h3><?= e('What you wrote') ?></h3><span class="grow"></span><button type="button" id="typed-copy" class="btn secondary small"><span data-icon="copy"></span><?= e('Copy') ?></button></div>
            <p id="info-typed" class="prompt-text"></p>
          </div>
          <div class="info-head">
            <h3 id="prompt-heading">Prompt</h3>
            <span class="grow"></span>
            <button type="button" id="prompt-copy" class="icon-btn small" data-icon="copy" title="<?= e('Copy prompt') ?>" aria-label="<?= e('Copy prompt') ?>"></button>
            <button type="button" id="prompt-edit" class="icon-btn small" data-icon="pencil" title="<?= e('Edit saved text') ?>" aria-label="<?= e('Edit saved text') ?>"></button>
          </div>
          <p id="info-prompt" class="prompt-text"></p>
          <div id="prompt-editor" class="stack" hidden>
            <textarea id="detail-prompt" rows="6" maxlength="32000" aria-label="<?= e('Saved text of the prompt') ?>"></textarea>
            <p class="hint"><?= e('Changes only the text saved in the history. The original request is still recorded.') ?></p>
            <div class="row-end"><button type="button" id="prompt-cancel" class="btn ghost small"><?= e('Cancel') ?></button><button type="button" id="prompt-save" class="btn secondary small"><?= e('Save') ?></button></div>
          </div>
          <details id="planned-wrap" class="plain" hidden><summary>Prompt planejado</summary><p id="info-planned" class="prompt-text"></p></details>
          <details id="sent-wrap" class="plain" hidden><summary><?= e('Prompt sent to the API') ?></summary><p class="hint"><?= e('The exact text that went to OpenAI, with what the server adds: the protection of the skin and of fidelity and, when the image was cleaned before sending, the note that the blur was intentional.') ?></p><p id="info-sent" class="prompt-text"></p></details>
          <details id="variation-wrap" class="plain" hidden open><summary><?= e('Direction for this image') ?></summary><p id="info-variation" class="prompt-text"></p></details>
          <div class="action-grid">
            <button type="button" id="reuse" class="btn secondary small"><span data-icon="compose"></span><?= e('Use prompt') ?></button>
            <button type="button" id="regenerate" class="btn secondary small"><span data-icon="redo"></span><?= e('Generate again') ?></button>
            <button type="button" id="as-reference" class="btn secondary small"><span data-icon="image-plus"></span><?= e('Use as reference') ?></button>
            <button type="button" id="open-video" class="btn secondary small" hidden><span data-icon="video"></span><?= e('Open in the video') ?></button>
          </div>
          <dl id="info-details" class="details"></dl>
        </section>
      </div>
    </aside>
  </div>
</dialog>

<dialog id="settings-dialog" class="sheet settings-sheet" aria-labelledby="settings-title">
  <div class="sheet-head settings-head">
    <div class="settings-heading">
      <h2 id="settings-title"><?= e('Settings') ?></h2>
      <p class="signed-in"><span data-icon="user"></span><span><?= t('Signed in as <strong id="settings-user"></strong>') ?></span><span id="settings-role" class="pill"></span></p>
    </div>
    <button type="button" class="icon-btn" data-close="settings-dialog" data-icon="close" aria-label="<?= e('Close') ?>"></button>
  </div>
  <div class="settings-body">
    <nav class="settings-nav" aria-label="<?= e('Settings sections') ?>">
      <p class="settings-group"><?= e('Services') ?></p>
      <button type="button" class="settings-tab" data-pane="keys" aria-controls="settings-keys"><span data-icon="key"></span><span><?= e('API keys') ?></span></button>
      <button type="button" class="settings-tab" data-pane="balance" aria-controls="settings-balance"><span data-icon="coin"></span><span><?= e('Balances') ?></span></button>
      <p class="settings-group"><?= e('Account') ?></p>
      <button type="button" class="settings-tab" data-pane="account" aria-controls="settings-account"><span data-icon="user"></span><span><?= e('Name and password') ?></span></button>
      <button type="button" class="settings-tab" data-pane="mcp" aria-controls="settings-mcp"><span data-icon="plug"></span><span><?= e('MCP access') ?></span></button>
      <button type="button" class="settings-tab" data-pane="gpu" aria-controls="settings-gpu"><span data-icon="video"></span><span><?= e('Video (GPU)') ?></span></button>
      <button type="button" class="settings-tab" data-pane="about" aria-controls="settings-about"><span data-icon="book"></span><span><?= e('About me as a reader') ?></span></button>
      <button type="button" class="settings-tab" data-pane="language" aria-controls="settings-language"><span data-icon="globe"></span><span><?= e('Language') ?></span></button>
      <p class="settings-group"><?= e('System') ?></p>
      <button type="button" class="settings-tab" data-pane="users" aria-controls="settings-users" id="settings-tab-users" hidden><span data-icon="users"></span><span><?= e('Users') ?></span></button>
      <button type="button" class="settings-tab" data-pane="host" aria-controls="settings-host" id="settings-tab-host" hidden><span data-icon="server"></span><span><?= e('Hosting test') ?></span></button>
      <button type="button" class="settings-tab" data-pane="privacy" aria-controls="settings-privacy"><span data-icon="shield"></span><span><?= e('Privacy') ?></span></button>
    </nav>
    <div class="settings-main">
      <p id="exposed-warning" class="error" role="alert" hidden><?= e('The app\'s internal folders (app/, bin/ and tests/) are open to the web. This server does not follow .htaccess (nginx, for example): block those folders in its configuration, as docs/nginx.conf.example shows.') ?></p>
      <section class="settings-pane" id="settings-keys" aria-labelledby="settings-keys-title">
        <div class="pane-head">
          <h3 id="settings-keys-title"><?= e('API keys') ?></h3>
          <p class="hint"><?= e('Each user uses their own keys, with the credits of their account at each service. They are kept encrypted on the server and never go back to the browser.') ?></p>
        </div>
        <form id="key-form" class="key-card" data-key="openai" novalidate>
          <div class="key-head">
            <span class="key-mark" data-icon="key"></span>
            <div class="key-name"><h4>OpenAI</h4><p><?= e('GPT Image images, prompt planning and the GPT writer') ?></p></div>
            <span id="key-state" class="key-state"></span>
          </div>
          <label class="key-field"><span class="sr-only"><?= e('OpenAI API key') ?></span><input id="api-key" type="password" autocomplete="off" spellcheck="false" placeholder="sk-…"></label>
          <p id="key-status" class="hint"></p>
          <div id="key-test-result" class="key-test" aria-live="polite" hidden></div>
          <div class="key-actions">
            <button type="button" id="key-remove" class="btn ghost small danger-text"><?= e('Remove') ?></button>
            <span class="grow"></span>
            <button type="button" id="key-test" class="btn secondary small"><?= e('Test') ?></button>
            <button type="submit" class="btn primary small"><?= e('Save') ?></button>
          </div>
        </form>
        <form id="claude-key-form" class="key-card" data-key="claude" novalidate>
          <div class="key-head">
            <span class="key-mark" data-icon="key"></span>
            <div class="key-name"><h4>Claude</h4><p><?= e('Claude writer for stories, comics and the visual novel') ?></p></div>
            <span id="claude-key-state" class="key-state"></span>
          </div>
          <label class="key-field"><span class="sr-only"><?= e('Claude API key') ?></span><input id="claude-key" type="password" autocomplete="off" spellcheck="false" placeholder="sk-ant-…"></label>
          <p id="claude-key-status" class="hint"></p>
          <div id="claude-test-result" class="key-test" aria-live="polite" hidden></div>
          <div class="key-actions">
            <button type="button" id="claude-key-remove" class="btn ghost small danger-text"><?= e('Remove') ?></button>
            <span class="grow"></span>
            <button type="button" id="claude-key-test" class="btn secondary small"><?= e('Test') ?></button>
            <button type="submit" class="btn primary small"><?= e('Save') ?></button>
          </div>
        </form>
        <form id="gemini-key-form" class="key-card" data-key="gemini" novalidate>
          <div class="key-head">
            <span class="key-mark" data-icon="key"></span>
            <div class="key-name"><h4>Gemini</h4><p><?= e('Gemini writer and Nano Banana images, from Google') ?></p></div>
            <span id="gemini-key-state" class="key-state"></span>
          </div>
          <label class="key-field"><span class="sr-only"><?= e('Gemini API key') ?></span><input id="gemini-key" type="password" autocomplete="off" spellcheck="false" placeholder="AIza…"></label>
          <p id="gemini-key-status" class="hint"></p>
          <div id="gemini-test-result" class="key-test" aria-live="polite" hidden></div>
          <div class="key-actions">
            <button type="button" id="gemini-key-remove" class="btn ghost small danger-text"><?= e('Remove') ?></button>
            <span class="grow"></span>
            <button type="button" id="gemini-key-test" class="btn secondary small"><?= e('Test') ?></button>
            <button type="submit" class="btn primary small"><?= e('Save') ?></button>
          </div>
        </form>
      </section>

      <section class="settings-pane" id="settings-balance" aria-labelledby="settings-balance-title" hidden>
        <div class="pane-head">
          <h3 id="settings-balance-title"><?= e('API balances') ?></h3>
          <p class="hint"><?= e('None of the services tells the balance through the key. Enter the balance shown on each one\'s site: the app subtracts the cost of each request made here, calculated from the tokens the API reports, and adds the top-up every month. The top bar shows the balance of the service the screen uses: in Images, the chosen model\'s; in Stories and Visual Novel, the writer\'s and the story\'s images\'.') ?></p>
        </div>
        <form id="balance-form" class="key-card balance-card" data-account="openai" novalidate>
          <div class="key-head">
            <span class="key-mark" data-icon="coin"></span>
            <div class="key-name"><h4>OpenAI</h4><p><?= e('GPT Image images, their prompt planning and the GPT writer') ?></p></div>
            <span id="balance-state" class="key-state"></span>
          </div>
          <div class="balance-fields">
            <label><?= e('Balance now (US$)') ?><input id="balance-amount" type="number" step="0.01" inputmode="decimal" autocomplete="off"></label>
            <label><?= e('Monthly top-up (US$)') ?><input id="balance-topup" type="number" min="0" step="0.01" inputmode="decimal" autocomplete="off"></label>
            <label><?= e('Top-up day') ?><input id="balance-day" type="number" min="1" max="31" step="1" inputmode="numeric" autocomplete="off"></label>
          </div>
          <p class="hint balance-where"><?= e('The balance is at platform.openai.com, under Billing.') ?></p>
          <p id="balance-status" class="hint"></p>
          <div class="key-actions">
            <button type="button" id="balance-off" class="btn ghost small danger-text"><?= e('Turn off') ?></button>
            <span class="grow"></span>
            <button type="submit" class="btn primary small"><?= e('Save balance') ?></button>
          </div>
        </form>
        <form id="claude-balance-form" class="key-card balance-card" data-account="claude" novalidate>
          <div class="key-head">
            <span class="key-mark" data-icon="coin"></span>
            <div class="key-name"><h4>Claude</h4><p><?= e('Claude writer for stories, comics and the visual novel') ?></p></div>
            <span id="claude-balance-state" class="key-state"></span>
          </div>
          <div class="balance-fields">
            <label><?= e('Balance now (US$)') ?><input id="claude-balance-amount" type="number" step="0.01" inputmode="decimal" autocomplete="off"></label>
            <label><?= e('Monthly top-up (US$)') ?><input id="claude-balance-topup" type="number" min="0" step="0.01" inputmode="decimal" autocomplete="off"></label>
            <label><?= e('Top-up day') ?><input id="claude-balance-day" type="number" min="1" max="31" step="1" inputmode="numeric" autocomplete="off"></label>
          </div>
          <p class="hint balance-where"><?= e('The balance is at platform.claude.com, under Billing (Credit balance).') ?></p>
          <p id="claude-balance-status" class="hint"></p>
          <div class="key-actions">
            <button type="button" id="claude-balance-off" class="btn ghost small danger-text"><?= e('Turn off') ?></button>
            <span class="grow"></span>
            <button type="submit" class="btn primary small"><?= e('Save balance') ?></button>
          </div>
        </form>
        <form id="gemini-balance-form" class="key-card balance-card" data-account="gemini" novalidate>
          <div class="key-head">
            <span class="key-mark" data-icon="coin"></span>
            <div class="key-name"><h4>Gemini</h4><p><?= e('Nano Banana images, their prompt planning and the Gemini writer') ?></p></div>
            <span id="gemini-balance-state" class="key-state"></span>
          </div>
          <div class="balance-fields">
            <label><?= e('Balance now (US$)') ?><input id="gemini-balance-amount" type="number" step="0.01" inputmode="decimal" autocomplete="off"></label>
            <label><?= e('Monthly top-up (US$)') ?><input id="gemini-balance-topup" type="number" min="0" step="0.01" inputmode="decimal" autocomplete="off"></label>
            <label><?= e('Top-up day') ?><input id="gemini-balance-day" type="number" min="1" max="31" step="1" inputmode="numeric" autocomplete="off"></label>
          </div>
          <p class="hint balance-where"><?= e('The spending is at aistudio.google.com, under Usage and Billing. If Google bills at the end of the month, enter how much you want to spend in the month and the same top-up on the day the bill closes.') ?></p>
          <p id="gemini-balance-status" class="hint"></p>
          <div class="key-actions">
            <button type="button" id="gemini-balance-off" class="btn ghost small danger-text"><?= e('Turn off') ?></button>
            <span class="grow"></span>
            <button type="submit" class="btn primary small"><?= e('Save balance') ?></button>
          </div>
        </form>
        <p class="hint"><?= e('Requests interrupted midway are not counted (they may be charged without the API reporting the tokens), nor is spending with the same key outside this user (another user here or other apps). When the value here drifts from the real one, type the real balance and save: the app measures the difference and counts the following spending in the same proportion.') ?></p>
      </section>

      <section class="settings-pane" id="settings-account" aria-labelledby="settings-account-title" hidden>
        <div class="pane-head">
          <h3 id="settings-account-title"><?= e('Name and password') ?></h3>
          <p class="hint"><?= e('The name and password you use to sign in to this user.') ?></p>
        </div>
        <form id="rename-form" class="settings-card stack">
          <h4><?= e('User name') ?></h4>
          <label><?= e('Name used to sign in') ?><input id="rename-input" maxlength="40" autocomplete="off" autocapitalize="none" spellcheck="false" required></label>
          <div class="row-end"><button type="submit" class="btn primary small"><?= e('Save name') ?></button></div>
        </form>
        <form id="password-form" class="settings-card stack">
          <h4><?= e('Change password') ?></h4>
          <label><?= e('Current password') ?><input id="current-password" type="password" autocomplete="current-password" required></label>
          <div class="row2">
            <label><?= e('New password') ?><input id="new-password" type="password" autocomplete="new-password" minlength="12" required></label>
            <label><?= e('Confirm new password') ?><input id="new-password-confirm" type="password" autocomplete="new-password" minlength="12" required></label>
          </div>
          <p class="hint"><?= e('At least 12 characters. The other open sessions are ended. A forgotten password cannot be recovered: without it, the library does not open.') ?></p>
          <div class="row-end"><button type="submit" class="btn primary small"><?= e('Change password') ?></button></div>
        </form>
      </section>

      <section class="settings-pane" id="settings-language" aria-labelledby="settings-language-title" hidden>
        <div class="pane-head">
          <h3 id="settings-language-title"><?= e('Language') ?></h3>
          <p class="hint"><?= e('The language of the menus and messages, on this browser. Stories keep the language they are written in, and the sign-in screen has the same menu.') ?></p>
        </div>
        <div class="settings-card stack">
          <label><?= e('Interface language') ?><?= $languageMenu('settings-language-select') ?></label>
        </div>
      </section>

      <section class="settings-pane" id="settings-mcp" aria-labelledby="settings-mcp-title" hidden>
        <div class="pane-head">
          <h3 id="settings-mcp-title"><?= e('MCP access') ?></h3>
          <p class="hint"><?= e('Lets Claude, ChatGPT, Gemini or another MCP client create, edit, upscale and list this user\'s images, with their API key, and write the stories with the same tools as the writer (chapters, bible, planning, sheets, cover, comics). Everything is kept here; the conversation in the client can be deleted afterwards.') ?></p>
        </div>
        <div class="settings-card stack" id="mcp-details">
          <p id="mcp-status" class="hint"></p>
          <div id="mcp-output" class="stack" hidden></div>
          <div class="row-end"><button type="button" id="mcp-revoke" class="btn ghost small danger-text"><?= e('Revoke') ?></button><span class="grow"></span><button type="button" id="mcp-generate" class="btn primary small"><?= e('Generate token') ?></button></div>
        </div>
      </section>

      <section class="settings-pane" id="settings-gpu" aria-labelledby="settings-gpu-title" hidden>
        <div class="pane-head">
          <h3 id="settings-gpu-title"><?= e('Video (GPU)') ?></h3>
          <p class="hint"><?= e('Videos with sound made with open models on a GPU you rent by the hour, such as Google Colab. A notebook there takes this account\'s video requests, makes them and sends them to the library, and turns its runtime off by itself when no request comes, so it spends no credits waiting.') ?></p>
        </div>
        <div class="settings-card stack" id="gpu-card">
          <div class="gpu-now"><span class="gpu-dot" aria-hidden="true"></span><strong id="gpu-state"></strong></div>
          <p id="gpu-detail" class="hint"></p>
          <div class="row-end"><button type="button" id="gpu-stop" class="btn ghost small danger-text" hidden><?= e('Turn off now') ?></button><span class="grow"></span><a id="gpu-notebook" class="btn secondary small" target="_blank" rel="noopener noreferrer"><span data-icon="video"></span><?= e('Open the notebook in Colab') ?></a></div>
        </div>
        <div class="settings-card stack">
          <h4><?= e('Worker token') ?></h4>
          <p id="gpu-token-status" class="hint"></p>
          <div id="gpu-token-output" class="stack" hidden></div>
          <div class="row-end"><button type="button" id="gpu-revoke" class="btn ghost small danger-text"><?= e('Revoke') ?></button><span class="grow"></span><button type="button" id="gpu-token" class="btn primary small"><?= e('Make a token') ?></button></div>
        </div>
        <div class="settings-card stack">
          <label><?= e('Turn the GPU off after') ?><select id="gpu-idle"></select></label>
          <p class="hint"><?= e('Minutes without video requests. Each session downloads and loads the model again (a few minutes), so a longer wait is better while you are still making videos.') ?></p>
        </div>
        <div class="settings-card stack gpu-steps">
          <h4><?= e('How to turn it on') ?></h4>
          <ol>
            <li><?= e('Make a token here and copy it.') ?></li>
            <li><?= e('Open the notebook in Colab. In Secrets (the key on the left), add ATELIER_URL with this site\'s address and ATELIER_TOKEN with the token, with notebook access on.') ?></li>
            <li><?= e('Choose the A100 GPU (Runtime › Change runtime type) and Runtime › Run all. Requests made meanwhile wait in line.') ?></li>
          </ol>
          <p class="hint"><?= e('In Colab Pro keep the notebook\'s tab open while you use it. An A100 80 GB uses about 7 to 15 compute units per hour, while it is on.') ?></p>
          <p class="hint" id="gpu-license"></p>
          <p class="hint"><?= e('Colab does not allow deepfakes: don\'t make videos of real people\'s faces.') ?></p>
        </div>
      </section>

      <section class="settings-pane" id="settings-about" aria-labelledby="settings-about-title" hidden>
        <div class="pane-head">
          <h3 id="settings-about-title"><?= e('About me as a reader') ?></h3>
          <p class="hint"><?= e('The writer reads this in all your stories, here and through MCP: what you like and dislike in a story, and how you want it to work with you. Write it your way; it applies to all of them, and what you ask for in a conversation counts more.') ?></p>
        </div>
        <form id="about-form" class="settings-card stack" novalidate>
          <label><?= e('In your words') ?><textarea id="about-text" rows="9" spellcheck="true" placeholder="<?= e('For example: I like subtlety and hate formula; I want your honest opinion; I read the chapters as they come out, no spoilers.') ?>"></textarea></label>
          <label><?= e('What the writer noticed') ?><textarea id="about-notes" rows="6" spellcheck="true" placeholder="<?= e('Nothing yet. As you work together, the writer notes here what it notices about your taste.') ?>"></textarea></label>
          <p class="hint"><?= e('The writer only writes in this part, without touching yours, and says so in the conversation when it changes something. You can correct or delete what it noted.') ?></p>
          <p id="about-status" class="hint"></p>
          <div class="row-end"><button type="submit" class="btn primary small"><?= e('Save') ?></button></div>
        </form>
      </section>

      <section class="settings-pane" id="settings-users" aria-labelledby="settings-users-title" hidden>
        <div class="pane-head">
          <h3 id="settings-users-title"><?= e('Users') ?></h3>
          <p class="hint"><?= e('Each user has their own password, their own keys and their own library, encrypted with a key of their own. Nobody sees the others\' images, not even the administrator. A forgotten password cannot be recovered.') ?></p>
        </div>
        <div id="users-list" class="users"></div>
        <form id="user-form" class="settings-card stack user-form">
          <h4><?= e('New user') ?></h4>
          <label><?= e('Name') ?><input id="user-name" maxlength="40" autocomplete="off" autocapitalize="none" spellcheck="false" required></label>
          <div class="row2">
            <label><?= e('Password') ?><input id="user-password" type="password" autocomplete="new-password" minlength="12" required></label>
            <label><?= e('Confirm password') ?><input id="user-confirm" type="password" autocomplete="new-password" minlength="12" required></label>
          </div>
          <label class="switch"><input type="checkbox" id="user-copy-key" checked><span class="switch-track" aria-hidden="true"></span><span><?= e('Use the same OpenAI key as me') ?></span></label>
          <label class="switch"><input type="checkbox" id="user-mcp"><span class="switch-track" aria-hidden="true"></span><span><?= e('Create MCP access for this user') ?></span></label>
          <div class="row-end"><button type="submit" class="btn primary small"><?= e('Create user') ?></button></div>
        </form>
        <div id="user-created" class="settings-card stack" hidden></div>
      </section>

      <section class="settings-pane" id="settings-host" aria-labelledby="settings-host-title" hidden>
        <div class="pane-head">
          <h3 id="settings-host-title"><?= e('Hosting test') ?></h3>
          <p class="hint"><?= e('Shows how long this host lets the app work. Three processes just wait, for up to 10 minutes, each in its own way: the way the writer used to run (in the page, with the .htaccess rule), the same without the rule, and in a separate process, the way the writer and the images run now when the server allows it. It calls no API and spends nothing. You can close this window: the test goes on, and the result stays here.') ?></p>
        </div>
        <div class="settings-card stack">
          <div id="host-test-result" class="host-test" aria-live="polite" hidden></div>
          <div class="row-end"><button type="button" id="host-test-start" class="btn primary small"><?= e('Test (10 min)') ?></button></div>
        </div>
      </section>

      <section class="settings-pane" id="settings-privacy" aria-labelledby="settings-privacy-title" hidden>
        <div class="pane-head">
          <h3 id="settings-privacy-title"><?= e('Privacy') ?></h3>
        </div>
        <div class="settings-card stack">
          <p class="hint"><?= e('Images, thumbnails, prompts and the API keys are kept encrypted on the server, with a different key for each user. Every read needs the session unlocked (or the user\'s MCP token). No analytics and no CDNs.') ?></p>
          <p class="hint"><?= e('Image requests go to the OpenAI API (GPT Image) or to Google\'s (Nano Banana, with the Gemini key), which may keep data for safety under each one\'s terms. The browser keeps only the generation options you chose, never prompts or images.') ?></p>
          <p class="hint"><?= e('Stories (chapters, bible, planning and the conversation with the writer) are kept encrypted like the images. When writing, the text goes to the API of Anthropic (Claude), OpenAI (GPT) or Google (Gemini), depending on the writer chosen. With the Gemini key, requests go without storing anything at Google (store: false); on a free Google AI Studio account, Google may use the content to improve its products.') ?></p>
          <p class="hint"><?= e('Without the password there is no way to recover the library. Back up the whole private folder.') ?></p>
        </div>
      </section>
      <p id="settings-error" class="error settings-error" role="alert"></p>
    </div>
  </div>
</dialog>

<dialog id="requests-dialog" class="sheet wide history-sheet" aria-labelledby="requests-title">
  <div class="sheet-head"><h2 id="requests-title"><?= e('History') ?></h2><button type="button" class="icon-btn" data-close="requests-dialog" data-icon="close" aria-label="<?= e('Close') ?>"></button></div>
  <p class="hint"><?= e('Everything that was requested, from newest to oldest: the images, including the stories\' and the ones that failed, and the messages to the writer. References uploaded from the computer are kept with the request so it can be repeated.') ?></p>
  <div class="history-bar">
    <label class="history-search"><span data-icon="search"></span><input id="history-search" type="search" placeholder="<?= e('Search the prompts and messages') ?>" autocomplete="off" aria-label="<?= e('Search the prompts and messages') ?>"></label>
    <div class="segmented small" id="history-kind" role="group" aria-label="<?= e('Show') ?>">
      <button type="button" data-kind="all" aria-pressed="true"><?= e('All') ?></button>
      <button type="button" data-kind="images" aria-pressed="false"><?= e('Images') ?></button>
      <button type="button" data-kind="writer" aria-pressed="false"><?= e('Writer') ?></button>
    </div>
  </div>
  <div id="history-story" class="history-story" hidden></div>
  <div id="requests-list" class="requests"></div>
  <div id="history-foot" class="history-foot"></div>
</dialog>

<dialog id="video-dialog" class="sheet wide video-sheet" aria-labelledby="video-title">
  <form id="video-form" class="stack" novalidate>
    <div class="sheet-head"><h2 id="video-title"><?= e('New video') ?></h2><button type="button" class="icon-btn" data-close="video-dialog" data-icon="close" aria-label="<?= e('Close') ?>"></button></div>
    <button type="button" id="video-gpu" class="gpu-line"><span class="gpu-dot" aria-hidden="true"></span><span id="video-gpu-text"></span></button>
    <div class="segmented small" id="video-kinds" role="group" aria-label="<?= e('Kind of video') ?>">
      <button type="button" data-video-kind="new" aria-pressed="true"><?= e('New video') ?></button>
      <button type="button" data-video-kind="video" aria-pressed="false"><?= e('From a video') ?></button>
    </div>
    <div class="field" id="video-keys-part">
      <span class="field-title"><?= e('Pinned pictures') ?></span>
      <p class="hint"><?= e('The video passes exactly through these pictures: the first frame, the last one and any moment in between. All optional.') ?></p>
      <div id="video-keys" class="video-frames video-keys"></div>
    </div>
    <div class="field" id="video-source-part" hidden>
      <span class="field-title"><?= e('The video') ?></span>
      <div id="video-source" class="video-frames"></div>
      <label><?= e('What to do') ?><select id="video-op"></select></label>
      <p class="hint" id="video-op-hint"></p>
      <label id="video-target-label"><?= e('What to replace, in English') ?><input type="text" id="video-target" maxlength="120" autocomplete="off" spellcheck="false" placeholder="<?= e('the red car') ?>"></label>
      <label class="switch" id="video-join-label"><input type="checkbox" id="video-join" checked><span class="switch-track" aria-hidden="true"></span><span><?= e('Join it to the end of the original video') ?></span></label>
      <label class="switch" id="video-keep-audio-label"><input type="checkbox" id="video-keep-audio" checked><span class="switch-track" aria-hidden="true"></span><span><?= e('Keep the sound of the original video') ?></span></label>
    </div>
    <div class="field">
      <span class="field-title"><?= e('References') ?></span>
      <p class="hint" id="video-refs-hint"></p>
      <div id="video-refs" class="video-frames"></div>
      <div class="video-add">
        <button type="button" class="btn small ghost" id="video-add-picture"><span data-icon="image-plus"></span><?= e('Picture') ?></button>
        <button type="button" class="btn small ghost" id="video-add-video"><span data-icon="video"></span><?= e('Video') ?></button>
        <button type="button" class="btn small ghost" id="video-add-sound"><span data-icon="music"></span><?= e('Sound') ?></button>
        <input type="file" id="video-sound-file" accept="audio/*,.mp3,.wav,.ogg,.m4a,.aac,.flac" hidden>
      </div>
    </div>
    <label class="prompt-box video-prompt"><span class="sr-only"><?= e('Describe the video') ?></span><textarea id="video-prompt" rows="6" maxlength="8000" placeholder="<?= e('What happens, second by second: the action, the camera, the voices and sounds, the music') ?>"></textarea></label>
    <div class="video-improve">
      <button type="button" class="btn small secondary" id="video-improve"><span data-icon="sparkle"></span><?= e('Improve the prompt') ?></button>
      <label class="sr-only" for="video-improve-with"><?= e('Model that improves the prompt') ?></label><select id="video-improve-with"></select>
      <button type="button" class="btn small ghost" id="video-improve-undo" hidden><span data-icon="undo"></span><?= e('Back to mine') ?></button>
      <span class="hint grow" id="video-improve-note" aria-live="polite"></span>
    </div>
    <div class="video-options">
      <label><?= e('Length') ?><span class="range-row"><input type="range" id="video-seconds" min="5" max="15" step="1" value="5"><output id="video-seconds-out"></output></span></label>
      <label id="video-aspect-label"><?= e('Aspect ratio') ?><select id="video-aspect"></select></label>
      <label><?= e('Resolution') ?><select id="video-resolution"></select></label>
      <label><?= e('Quality') ?><select id="video-quality"></select></label>
      <label><?= e('Seed (optional)') ?><input type="number" id="video-seed" min="0" step="1" inputmode="numeric" placeholder="<?= e('Random') ?>"></label>
    </div>
    <p class="hint" id="video-note"></p>
    <p id="video-error" class="error" role="alert"></p>
    <div class="row-end"><button type="button" class="btn ghost" data-close="video-dialog"><?= e('Cancel') ?></button><button type="submit" class="btn primary" id="video-submit"><span data-icon="video"></span><?= e('Make the video') ?></button></div>
  </form>
</dialog>

<dialog id="job-dialog" class="sheet wide job-sheet" aria-labelledby="job-title">
  <div class="sheet-head"><h2 id="job-title"><?= e('Request') ?></h2><button type="button" class="icon-btn" data-close="job-dialog" data-icon="close" aria-label="<?= e('Close') ?>"></button></div>
  <div class="job-sheet-body">
    <div id="job-visual" class="job-visual"><span class="spinner"></span><span id="job-visual-label" class="job-visual-label" hidden></span></div>
    <div class="job-sheet-info">
      <div class="job-state"><span class="spinner small" id="job-spinner"></span><span id="job-phase" class="status"></span><span id="job-elapsed" class="job-time"></span></div>
      <p id="job-signal" class="job-signal" hidden></p>
      <p id="job-error" class="job-error" hidden></p>
      <section class="info">
        <div id="job-typed-wrap" class="typed" hidden>
          <div class="info-head"><h3><?= e('What you wrote') ?></h3><span class="grow"></span><button type="button" id="job-typed-copy" class="btn secondary small"><span data-icon="copy"></span><?= e('Copy') ?></button></div>
          <p id="job-typed" class="prompt-text"></p>
        </div>
        <div class="info-head">
          <h3 id="job-prompt-heading">Prompt</h3>
          <span class="grow"></span>
          <button type="button" id="job-copy" class="icon-btn small" data-icon="copy" title="<?= e('Copy prompt') ?>" aria-label="<?= e('Copy prompt') ?>"></button>
        </div>
        <p id="job-prompt" class="prompt-text"></p>
        <details id="job-sent-wrap" class="plain" hidden><summary><?= e('Instruction sent') ?></summary><p id="job-sent" class="prompt-text"></p></details>
        <details id="job-variation-wrap" class="plain" hidden open><summary><?= e('Direction for this image') ?></summary><p id="job-variation" class="prompt-text"></p></details>
        <div id="job-refs" class="job-refs" hidden></div>
        <dl id="job-details" class="details"></dl>
      </section>
      <div id="job-actions" class="action-grid"></div>
    </div>
  </div>
</dialog>

<dialog id="picker" class="sheet wide picker" aria-labelledby="picker-title">
  <div class="sheet-head"><h2 id="picker-title"><?= e('References from the gallery') ?></h2><button type="button" class="icon-btn" data-close="picker" data-icon="close" aria-label="<?= e('Close') ?>"></button></div>
  <div class="picker-toolbar">
    <label class="search"><span data-icon="search"></span><input id="picker-search" type="search" placeholder="<?= e('Search') ?>" aria-label="<?= e('Search the prompts') ?>" autocomplete="off"></label>
    <div class="segmented" role="group" aria-label="<?= e('Filter') ?>">
      <button type="button" data-picker-filter="all" aria-pressed="true"><?= e('All', ['ctx' => 'picker']) ?></button>
      <button type="button" data-picker-filter="favorites" aria-pressed="false"><?= e('Favorites') ?></button>
    </div>
    <label class="switch" title="<?= e('Show only the newest version of each edited image') ?>"><input type="checkbox" id="picker-group"><span class="switch-track" aria-hidden="true"></span><span><?= e('Group versions') ?></span></label>
  </div>
  <div id="picker-body" class="picker-body">
    <div id="picker-grid" class="justified picker-grid" role="group" aria-label="<?= e('Gallery images') ?>"></div>
    <div id="picker-empty" class="empty compact" hidden></div>
    <div class="sentinel"><span class="spinner" id="picker-loading" hidden></span></div>
  </div>
  <div class="picker-foot">
    <p id="picker-status" class="muted grow" aria-live="polite"></p>
    <button type="button" id="picker-cancel" class="btn ghost"><?= e('Cancel') ?></button>
    <button type="button" id="picker-done" class="btn primary"><?= e('Done') ?></button>
  </div>
</dialog>

<dialog id="confirm-dialog" class="sheet narrow" aria-labelledby="confirm-title">
  <h2 id="confirm-title"></h2>
  <p id="confirm-message" class="hint"></p>
  <div class="row-end"><button type="button" id="confirm-no" class="btn ghost"><?= e('Back') ?></button><button type="button" id="confirm-yes" class="btn primary"></button></div>
</dialog>

<dialog id="album-dialog" class="sheet narrow" aria-labelledby="album-title">
  <div class="sheet-head"><h2 id="album-title"><?= e('Add to album') ?></h2><button type="button" class="icon-btn" data-close="album-dialog" data-icon="close" aria-label="<?= e('Close') ?>"></button></div>
  <p id="album-summary" class="hint"></p>
  <div id="album-list" class="album-list"></div>
  <form id="album-new" class="album-new">
    <input id="album-name" maxlength="80" placeholder="<?= e('New album') ?>" aria-label="<?= e('Name of the new album') ?>" autocomplete="off">
    <button type="submit" class="btn secondary"><?= e('Create and add') ?></button>
  </form>
</dialog>

<dialog id="name-dialog" class="sheet narrow" aria-labelledby="name-title">
  <form id="name-form" class="stack">
    <h2 id="name-title"></h2>
    <p id="name-message" class="muted" hidden></p>
    <label><span id="name-label"><?= e('Name') ?></span><input id="name-input" maxlength="80" autocomplete="off" required></label>
    <p id="name-error" class="error" role="alert"></p>
    <div class="row-end"><button type="button" class="btn ghost" id="name-cancel"><?= e('Cancel') ?></button><button type="submit" class="btn primary" id="name-ok"><?= e('Save') ?></button></div>
  </form>
</dialog>

<div id="scope-menu" class="menu" popover role="menu" aria-label="<?= e('Library, albums and trash') ?>"></div>
<div id="view-menu" class="menu" popover role="menu" aria-label="<?= e('Sort and grouping') ?>"></div>

<div id="toast" class="toast" popover="manual" role="status" aria-live="polite"><span id="toast-text"></span><button type="button" id="toast-action" class="toast-action" hidden></button></div>
</body></html>
