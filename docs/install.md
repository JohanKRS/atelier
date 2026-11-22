# Installing Atelier

Atelier is PHP, SQLite and plain JavaScript. It needs no database server, no Composer, no Node on the server and no build step. This page covers the install and the technical details; the [README](../README.md) shows what the app does.

## Requirements

- **PHP 8.2 or newer** with `pdo_sqlite`, `sodium`, `curl`, `gd` (with WebP) and `mbstring`.
- **HTTPS.** The app refuses plain HTTP anywhere but localhost.
- `memory_limit` of 512M is recommended, and `post_max_size` of at least 2 MB (videos are uploaded in pieces).
- Optional but recommended: `proc_open` and PHP's command-line binary, ideally the same version as the site. The writer and long image requests then run in a separate process that the web server can't cut short (see [Long requests](#long-requests)).

Apache and LiteSpeed read the included `.htaccess`. On nginx, see [nginx](#nginx-and-servers-that-ignore-htaccess).

## Install

1. **Upload** the contents of the zip from the [latest release](https://github.com/JohanKRS/atelier/releases/latest) to a folder of your site: `index.php`, `mcp.php`, `.htaccess`, `robots.txt`, `favicon.ico`, `config.example.php` and the folders `app/`, `assets/` and `bin/`. The root of a domain or a subfolder both work. From a clone of the repository, upload the same files and leave out `.git`, `tests/` and `docs/`.
2. **Configure.** Copy `config.example.php` to `config.php` and set `setup_token` to a long random code (24 characters or more), for example from `php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'`. The token only allows creating the first account; it is not a password, and you can clear it afterwards.
3. **The data folder.** The app creates a private folder next to your document root on its own. PHP must be able to write there. If your host doesn't allow it, set `data_dir` in `config.php` to an absolute path outside every published folder, such as `/home/you/atelier-data`. The app refuses to store data inside the document root or its own folder.
4. **Create the first account.** Open the site, enter the setup token, and choose a name and a password of at least 12 characters. This account is the administrator. Keep the password safe: **there is no way to recover it, or the library, without it.**
5. **Add your keys.** In **Settings › API keys**, add your OpenAI key, and optionally a Claude (Anthropic) key and a Gemini (Google) key for the writer and Nano Banana. Keys are encrypted in your library and never sent back to the browser. **Test** checks a key with a tiny request.

To update, replace the app files with the ones from the new release (what changed is in [CHANGELOG.md](../CHANGELOG.md)) and keep `config.php`, `data-dir.php` (if present) and the data folder.

## Configuration

All options live in `config.php`, which must keep `<?php` at the top and `return [...];` around the options.

| Option | Default | What it does |
| --- | --- | --- |
| `setup_token` | `''` | The code for creating the first account. |
| `data_dir` | `''` | Absolute path of the private data folder. Empty means a folder created next to the document root. |
| `require_https` | `true` | Set `false` only for local development over HTTP. |
| `trusted_proxy` | `false` | Trust `X-Forwarded-Proto` from a reverse proxy. Enable only if the proxy is the only way to reach the server and overwrites that header. |
| `session_minutes` | `60` | Idle time before the session locks. |
| `api_timeout` | `600` | Seconds to wait for each call to a provider. |
| `max_parallel` | `4` | Image requests running at the same time, for the whole server (1 to 16). Each one keeps a PHP process busy, so stay below your host's process limit; on shared plans 2 or 3 is safer. Extra requests wait in line. |
| `max_video_mb` | `2048` | Largest video accepted, in MB. `0` turns video uploads off. |
| `run_in_background` | `true` | On PHP-FPM and LiteSpeed, answer the browser as soon as a request starts and keep working in the background. |
| `separate_process` | `true` | Run the writer's turns and image requests in a separate command-line PHP process when the server allows it. |

## nginx and servers that ignore `.htaccess`

The `.htaccess` file closes the internal folders (`app/`, `bin/`, `tests/`), the configuration and the data files on Apache and LiteSpeed. nginx, and Apache with `AllowOverride None`, ignore it, so the same rules have to go into the server configuration. [`nginx.conf.example`](nginx.conf.example) is a complete example for nginx with PHP-FPM. If the internal folders answer on the web, the app warns the administrator in Settings.

On Apache, `mod_rewrite` and `mod_headers` are recommended. Never use `777` permissions: the app creates its files as `0600` and its folders as `0700`.

## Long requests

A high-quality image with prompt planning can take several minutes, and a writer's turn longer still. Atelier is built so that nothing depends on one long web request staying open.

- **In the background.** On PHP-FPM and LiteSpeed the browser gets its answer as soon as a request starts; the work goes on in the same process and the page follows it by polling. Closing the tab doesn't stop it.
- **In a separate process.** Some hosts end every web PHP process after a fixed time, whatever `max_execution_time` says. LiteSpeed's `LSAPI_MAX_PROCESS_TIME`, often 120 seconds, is the usual one. When `proc_open` and the command-line PHP are available, the writer's turns and the image requests run in `bin/worker.php`, a process of its own that the web server doesn't end. The library key reaches it through a pipe, never the command line. If the process can't start or misses an extension, the work runs in the web server as before and the app waits an hour before trying again.
- **Timeouts to raise**, to at least `api_timeout` + 120 seconds: `request_terminate_timeout` on PHP-FPM (and `ProxyTimeout` with Apache's `mod_proxy_fcgi`), `FcgidIOTimeout` and `FcgidBusyTimeout` with `mod_fcgid`, and `fastcgi_read_timeout` or `proxy_read_timeout` on nginx. On LiteSpeed, the `.htaccess` turns on `noabort` and `noconntimeout` for the long routes; without `noabort`, LiteSpeed ends the process as soon as the answer is sent.
- **CDNs and proxies** often cut requests with no answer after 100 to 120 seconds. With background requests this doesn't matter; otherwise the app sends a keep-alive byte every 10 seconds.
- **Settings › Hosting test** runs three processes that only wait, for up to 10 minutes, and shows when and how each one was stopped, along with the host's limits and why the separate process last failed. It calls no API.

A request is marked **Interrupted** only when its PHP process is gone, never for taking long: the process writes a heartbeat every 3 seconds and holds a lock on a file of its own. An interrupted request is never repeated on its own. To find out what happened, look for entries starting with `Atelier` in PHP's error log: they name the class, file, line and step, without request contents or secrets.

## If it doesn't open

The startup page names the step that failed, without paths, passwords or keys:

- `CONFIG-PHP`, `CONFIG-RETURN`, `CONFIG-VALUE`, `CONFIG-READ`: check the syntax, format and permissions of `config.php`.
- `DATA-PATH`, `DATA-CREATE`: PHP can't reach or create the data folder. On shared hosts, create one outside `public_html`, within the paths `open_basedir` allows, and set it as `data_dir`.
- `DATA-MANY`: the app moved, and there's more than one library from earlier installs. Set the right one in `data_dir` (see [Moving](#moving-to-another-folder-or-address)).
- `DATA-WRITE`, `DATA-SQLITE`: check the owner, write permission, disk space and SQLite. Don't delete the database or the encrypted files.
- `SESSION-START`: check the space and permissions of the `sessions` subfolder.
- `SERVER`: look for the `Atelier` entry in PHP's error log.

## Accounts

The administrator creates and deletes accounts in **Settings › Users**. Each account has its own name, password, keys and library, encrypted with a random key that only its password opens. Nobody else can see an account's pictures or stories, including the administrator, who only sees names, picture counts and whether MCP access is on. A forgotten password can't be recovered, and deleting an account deletes its library for good.

Accounts are meant for people who trust each other: there are no quotas, and `max_parallel` is shared by everyone.

## MCP

`mcp.php` is a remote MCP server (Streamable HTTP, token authentication) that gives Claude, or another MCP client, one account's library and stories, with that account's keys. It runs on your site, so no computer needs to stay on.

Generate a token in **Settings › MCP access**, or when creating an account. It's shown once, in two forms:

- **Claude connectors** (claude.ai, the desktop and mobile apps): add a custom connector with the address `https://your-site/…/mcp.php?token=…`. These connectors don't take headers, so the token goes in the address, which means it can end up in your server's access logs.
- **Claude Code and clients that send headers:** `claude mcp add --transport http atelier https://your-site/…/mcp.php --header "Authorization: Bearer …"`. The token then stays out of the address and the logs.

Whoever has the token acts as that account, so keep it like a password. **Revoke** or **Generate a new token** invalidates the old one at once; changing the password doesn't. The server keeps only an identifier and the account's key sealed with the token's secret, never the token itself.

The tools cover images (`generate_image`, `edit_image`, `upscale_image`, `list_images`, `get_image`, `import_image`, `check_requests`) and stories (`list_stories`, `create_story`, `open_story`, and the same tools the app's writer uses). Story tools only work after `open_story`, which hands the client the writer's instructions. Generated pictures come back with a preview and a download link valid for 24 hours.

## Moving to another folder or address

The library lives in the data folder, outside the app's folder. When `data_dir` isn't set, the app writes the folder's location to `data-dir.php` next to `config.php` on first use. Move the app's folder with `config.php` and `data-dir.php`, leave the data folder where it is, and the new address opens the same library. You'll need to sign in again, and an MCP connector needs the new address of `mcp.php`.

To move the data folder itself, rename it, or copy the database with SQLite's own backup rather than a file copy while the app is in use:

```sh
php -r '(new SQLite3($argv[1], SQLITE3_OPEN_READONLY))->backup(new SQLite3($argv[2]));' old/vault.sqlite new/vault.sqlite
```

Then check the copy with `PRAGMA integrity_check`, which must answer `ok`.

## Backups

Keep `config.php`, your password and the data folder. Back up while no request is running: copy `vault.sqlite` together with its `-wal` and `-shm` files and the `blobs/` folder, or use SQLite's online backup as above. `sessions/` doesn't need to be kept. Old backups still need the password that was in use when they were made.

## Privacy and security

- Pictures, thumbnails, prompts, request details, references, stories and keys are encrypted at rest with XChaCha20-Poly1305. Each account's key is protected by its password with Argon2id (256 MiB) and, with MCP on, by the token's secret. IDs, times, statuses, favourites and user names stay readable in SQLite.
- Sessions use HttpOnly, SameSite=Strict, Secure cookies, and the session's copy of the library key is sealed with a separate secret. There's CSRF protection, a strict Content-Security-Policy and `no-store` caching. Images are only served to an unlocked session; the exceptions are MCP's token address and 24-hour download links.
- Sign-in is rate-limited per address and overall, with a growing delay after wrong passwords and a hidden field that only bots fill in.
- Every answer asks search engines not to index the site, and nothing in the app links outward.
- Your prompts and pictures go to the providers you use (OpenAI, Anthropic, Google), with `store: false` where the API allows. That is **not** zero data retention: providers may keep logs under their own terms.

The threat model and what the app doesn't protect against are in [SECURITY.md](../SECURITY.md).

## Development and tests

```sh
# PHP: the vault, the API contracts, stories, comics, Gemini, the model catalog, visual novels, startup, translations
php tests/run.php && php tests/stories.php && php tests/comics.php && php tests/gemini.php && php tests/catalog.php && php tests/vn.php && php tests/startup.php && php tests/i18n.php
# JavaScript
node tests/outpaint.mjs && node tests/lineage.mjs && node tests/pricing.mjs && node tests/video.mjs && node tests/library.mjs && node tests/clean.mjs && node tests/story.mjs && node tests/reader.mjs && node tests/comics.mjs && node tests/pack.mjs
# A local server
php -S 127.0.0.1:8099 -t .
```

The browser tests (`tests/browser.mjs`, `tests/*-browser.mjs`) use Playwright against a fresh local instance prepared by `tests/http_checks.py`; [`.github/workflows/checks.yml`](../.github/workflows/checks.yml) shows the exact order. No test calls a real API: the providers are played back by fixtures.

**Translations.** English is the language of the code. Every piece of interface text goes through `t()` (or `e()` in PHP templates), and `assets/i18n/pt-BR.json` holds the Portuguese, shared by PHP and the browser. `tests/i18n.php` fails when a text has no translation or a translation has no text. The existing tests read the interface in Portuguese.
