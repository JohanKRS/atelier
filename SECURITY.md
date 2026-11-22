# Security

Atelier is a personal app for one person or a few people who trust each other and share the same install. It protects against unauthenticated web access, against one account reading another's library, and against files leaking through backups, by encrypting each account's library with its own key, which only that account's password (and, if enabled, its MCP token) can open. It does not protect against a compromised server while a session is unlocked or an MCP call is running, nor against someone with access to a signed-in device.

- Never expose the private data folder through the document root, an alias, a CDN or a virtual host.
- HTTPS is required. Protect backups, `config.php` and access to your hosting panel as well.
- No secret, real prompt or personal picture should ever end up in the repository or in the logs.
- The code must not forward arbitrary URLs to the server, accept file paths from the client, or pass raw provider messages back to the browser.
- Every change goes through POST with a CSRF token, and every private read needs a signed-in session, thumbnails and downloads included. MCP is the one controlled exception: it authenticates with a Bearer token (never a cookie), only reaches the library of the token's account, and its download links expire after 24 hours.
- Every query for pictures and requests is filtered by the account of the session or token, and decrypting anything also needs that account's key.
- Videos arrive in pieces through POST with CSRF, only into an upload the same account opened, and their type is checked from the first bytes (MP4/MOV or WebM). They are encrypted in blocks whose position and total are authenticated, and served with Range requests only to the owner's session. They are never sent to OpenAI or over MCP.
- Never store the master key in plain text on disk. Nonces are random, and every object has its own authenticated context.
- Never resend a generation automatically after a timeout: it could charge twice.
- Extensions, PHP and web server updates, and the policies of each provider account are the responsibility of whoever runs the install.

## Reporting a vulnerability

Please report security issues privately to the repository owner, through GitHub's private vulnerability reporting (Security › Report a vulnerability) where it is enabled, and not in a public issue. Never include credentials or the contents of a library in a report.
