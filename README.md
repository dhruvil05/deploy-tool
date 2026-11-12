# Deploy tool

A small local web app that deploys the backoffice from the `staging-latest` branch
to the staging site, and from the staging site to prod, over FTPS or SSH.

It runs only on this computer. It is never uploaded to staging or prod.

## Start it

Either double-click `start.bat` (or run it from a terminal) and open http://127.0.0.1:8090.

Or, with Laragon's Apache running, open http://localhost/deploy-tool/.
`.htaccess` limits Apache to this computer (`Require local`) and to the app's own files.

## Security

This tool can deploy to production with saved passwords, so it guards itself:

- **Safest way to run it:** `start.bat`. PHP's server then listens on 127.0.0.1 only and
  `router.php` serves nothing but `index.php` and `assets/`. Run with Apache only if you need to.
- **Only this computer:** requests from other machines and unknown host names (DNS rebinding)
  are refused by the app itself, whatever the web server allows.
- **Web server check:** `.htaccess` serves only `index.php` and `assets/` (core Apache rules,
  no `mod_rewrite` needed). Servers that ignore `.htaccess` (Apache with `AllowOverride None`,
  Nginx, IIS) are caught too: once a day the tool asks its own web server for a random file in
  its data folder, and if it gets it, every page shows **"Your passwords may be public."** Then
  move the data folder out of the web folder in Settings → Storage. Started with `php -S`
  without `router.php`, it warns the same way.
- **Password (optional):** Settings → Security. Without one, anyone who can use this computer
  (other Windows users, programs running on it) can open the tool. Kept as a hash in
  `auth.json`; 8 idle hours sign you out; wrong tries lock it for a while. Forgot it? Delete
  `auth.json` in the data folder.
- **Saved passwords are encrypted:** FTP passwords, the AI key and the cloud keys are encrypted
  (AES-256-GCM) with a key kept outside the data folder: `%APPDATA%\DeployTool\secret.key`
  on Windows, `~/.config/deploy-tool/secret.key` elsewhere, or the file `DEPLOY_TOOL_KEY_FILE`
  names in `.env`. A cloud backup carries this key, encrypted with your passphrase.
- **CSRF:** every change needs the page's token, so other websites in the same browser can't
  trigger anything. Passwords and keys are never shown back on a page.
- **Servers are checked:** FTPS checks the certificate (off only for IP-address hosts); SSH only
  connects to servers whose key you trusted on the site's page; the fingerprint helper and the
  site check verify HTTPS certificates.
- **Deploys:** files always come from git commits, never from the working folder; production
  needs the typed site key and never deletes files.
- **AI assistant:** it only acts on the chat's own local, asks or refuses per Settings → AI
  permissions, never sees passwords, can't move a site with a saved password to another server,
  add folder sites or point a local at another repository, and treats file contents and server
  answers as data, not instructions. Approving production in the chat always shows a card where
  you type the site key yourself; the key goes straight to the approval, never through the model.
  Code it reads is sent to the AI provider you picked.
- **Exports:** CSV cells that would run as Excel formulas are escaped.

## What each server remembers

After a deploy, the server gets `application/.deploy/state.json` with the commit it runs,
when, and who deployed it. The app's own `application/.htaccess` blocks it from the web.

## What it does

- **Deploy**: safety checks → PHP syntax check → back up the server's copies → your review
  → upload (to a temp name, then renamed into place) → delete removed files (never on prod)
  → download everything again and compare → record the version → site check → SQL reminder.
- **Compare**: what is really on a server versus a commit (file sizes over FTP, or a
  temporary fingerprint file). Then **Make it match** or **Record version only**.
- **Rollback**: puts back the backed-up files, removes added ones, restores the recorded version.
- **History**: every run with its files, log and CSV export.

Runs work in the background (a hidden `php src/run-worker.php` process per run), so you can
close or reload the page. If a worker can't be started, the page does the work itself as before,
and `storage/worker.log` says why.

## Fast transfers

- **SSH sites** (fastest): the tool copies a small helper to `~/.deploy-tool/` on the server
  (outside the web folder) and sends hundreds of files per SSH connection. Each file is written
  to a temp name, then renamed to its own path. A compare is one call. It needs a key login with
  no passphrase (cPanel → SSH Access → Manage SSH Keys) and PHP on the server. Before the
  first connection, trust the server's key under **Server key** on the site's page, after
  comparing its fingerprint with the one your host shows.
- **FTP sites**: files go over several connections at once ("Connections at once", default 4).
  A file that fails there is tried again on its own.
- **Compare** uses the fingerprint file by default. "File sizes over FTP" is still there for when
  the fingerprint file can't run.

Protected paths (uploads, module uploads, media, app-config.php, .htaccess, …) are never
uploaded, overwritten, deleted or read. See Settings.

## Speed-ups (Settings → Speed-ups)

Optional extras, each switched on or off on its own (all on by default except "Upload big FTP
deploys in one go"). Off, the tool works exactly as before.

- **Upload big FTP deploys in one go** (off by default): from a set number of files (50), an FTP
  deploy goes up as one compressed file plus a small one-time helper that unpacks it on the
  server: each file to a temporary name, then renamed into place. The helper needs a token,
  expires after 15 minutes and deletes itself and the bundle. Anything it couldn't write is
  uploaded one by one as usual. Needs the site address. Off by default because on some shared
  hosts PHP writes files as another user, and later FTP uploads can't replace them: try it on
  one deploy first.
- **Smarter checks after a deploy:** a site's check page can list several pages separated by
  commas (`/, /login, /api/health`). Each is measured before anything changes and again after;
  a page that breaks, changes its status or gets much slower is named, with **Roll back** right
  there.
- **Three-way view for server edits:** for a file someone changed on the server, one page shows
  the server edit, what the upload does to it, and which server lines would be lost.
- **Flag leftover debug code:** the review lists `var_dump`, `print_r`, `dd`, `die`,
  `console.log`, `debugger` and `phpinfo` in the lines a deploy adds, with file and line.
- **"Explain this deploy" (AI):** a button on the review asks the AI assistant for a short
  summary: what changes, risks, database steps and what to check afterwards. Sends the file
  list and the diffs of the most changed files, only when clicked.
- **Quick commands in the chat:** `/status`, `/status staging`, `/compare staging`,
  `/deploy staging`, `/history`, `/help` run at once without the AI: instant, free, and they
  work while the provider is down. The same AI permissions apply.
- **Answers appear as they're written:** the assistant's answer shows word by word. Providers
  that can't stream answer the normal way.
- **Verify SSH uploads by fingerprint:** after uploading, the server sends git's hash of each
  file instead of the whole file. Just as exact, far less to transfer. FTP and folder sites
  still download and compare.
- **Promote to the next site:** a finished deploy offers to prepare the same commit for the site
  that gets its code from it (staging → prod). You still review and approve.
- **Review insights:** the review groups files by folder with lines added and removed, marks
  risky files (config, `.htaccess`, dependencies, routes, entry points) and opens each file's
  changes in place with **Preview**.
- **Spot database changes:** migrations, `.sql` files, module installers and code that creates
  or alters tables are listed before you prepare a deploy, at the review and at the SQL step.
- **Check GitHub in the background:** while the dashboard is open it fetches every 10 minutes
  (and on opening, when the last fetch is older). Branches and files never change.
- **Fast history:** a small index of past runs in `cache/`, so the dashboard and History stay
  quick however long the history gets. Rebuilt by itself; deleting it is harmless.
- **Release notes:** a deploy's page lists the commits it brings, with a "Copy as text" button.
- **Messages to Slack, Teams or Discord:** paste a channel webhook in Settings → Speed-ups; a
  short message goes out when a deploy, match or rollback finishes, waits for SQL or fails. The
  webhook is stored encrypted.
- **AI backup model** (Settings → AI assistant): when the main model times out or fails, the step
  is tried once with this one. Blank switches it off.

## Where the data lives, and cloud backup

- **Storage folder**: everything (settings, sites with their passwords, runs, backups, chats)
  is in `storage/` here, or in a folder you pick in Settings → Storage.
- **Cloud backup** (Settings → Cloud backup): encrypted copies in any S3-compatible storage
  (Cloudflare R2, Backblaze B2, Amazon S3, Wasabi, MinIO…). The data is zipped and encrypted
  on this computer (AES-256-GCM, with your passphrase) before upload, so the provider never
  sees what's inside. Back up on demand, once a day, or after every deploy; the newest N
  copies are kept. **Restore** replaces this computer's data with a backup, after saving
  what's here to `restore-safety\` in the storage folder.
- Keep the passphrase somewhere safe: without it no backup can be opened.

## AI assistant

An optional chat bot (**Assistant** in the menu) that uses the tool for you: "is staging up to
date?", "what changed since the last deploy?", "compare staging and prepare the upload".

- **What it can do:** read locals, sites, history, runs, compare results, deploy previews and
  file changes; start compares; prepare deploys and "Make it match" uploads. A prepared run
  stops at the review and the chat shows an **Open review** button.
- **What it may do is up to you:** approving, rolling back, recording a version and the rest are
  off until you allow them in AI permissions, and it never changes settings. Approving a
  production deploy shows a card in the chat where you type the site key yourself (every time,
  even when Approve is set to Allow); then it carries on. It asks before preparing anything
  for a production site.
- **Set it up** in Settings → AI assistant: pick a provider (OpenRouter, OpenAI, Anthropic,
  Groq, Ollama, LM Studio, or Custom for any OpenAI-style API), the API key, and a model
  (**Load models** lists them). **Test** checks the connection and that the model can call
  tools, which the assistant needs. Then tick **Enable AI assistant**.
- **Costs:** each message is a few requests to the provider. "Steps per message" and
  "Messages kept per chat" in Settings cap that. Ollama or LM Studio on this computer cost
  nothing but need a model that is good at tool calling.
- **AI permissions** (Settings): each action is **Allow**, **Ask** (the chat shows Allow / Deny
  buttons and nothing happens until you click) or **Off**.
- **Your instructions** (Settings → AI assistant): house rules in plain words, added to every
  chat, e.g. "Never start an upload unless I say upload".
- **Notes per local** (Sites → edit a local): facts about that project, added to every chat
  about it, e.g. "Staging is safe any time; never deploy production on Fridays".
- Each answer shows the model that wrote it. A router like `openrouter/free` picks a different
  free model per message; one fixed model that is good at tool calling behaves far better.
- Chats are kept in `storage/chats/`. Runs the assistant starts say so in their log.

### Turning it off

- **For now:** untick **Enable AI assistant** in Settings. The menu item and page go away;
  the provider details stay saved.
- **For good:** copy `.env.example` to `.env` and set `DEPLOY_TOOL_AI=false`. Then there is no
  Assistant page and no AI settings, and the tool never contacts an AI provider. `.env` is read
  on every page, so the change counts at once; it is git-ignored and blocked from the web.

## Testing

**Automatic tests:** run `php tests/run.php` from the tool's folder (about 40 seconds). Add a
word to run only matching tests, e.g. `php tests/run.php bundle`. They use their own throwaway
data folder, key and local test servers, so they never touch your real data and need no
internet. Each test file in `tests/` covers one area: uploads, checks, review, the assistant,
security, secrets, sign-in and history.

**By hand:** see [TESTING.md](TESTING.md): local test folders, no server involved.
