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
- **Compare only some commits:** on the compare page, **What to compare → Only some commits**
  lists the commits the site doesn't have yet (or the latest 20 when its version isn't known).
  Tick one or more, say a hotfix on staging, and only the files those commits changed are read
  from the server and compared with the newest ticked commit; files they deleted show under
  **Removed by these commits**. "Make it match" then sends just those files: a *partial match*.
  - If a commit you didn't tick also changed one of those files (between the server's version
    and the ticked commit), the page names it, offers **Include that commit too**, and asks you
    to tick a box before sending anyway, since the file would bring that change too.
  - **Files outside the picked commits** (its own switch): under **Not in your selection** the
    page lists the files the site still lacks from the commits you didn't tick, between its
    recorded version and the newest commit it gets code from, grouped by commit. It comes from
    git alone, so nothing more is read from the server. Tick some of those commits and
    **Compare again with the ticked commits added**. The assistant's `get_run` shows the same list.
  - The server's recorded version stays as it was (the site isn't on any one commit then), so
    the next normal deploy still brings everything else. It knows the files the partial match
    sent and shows them as **Sent by a partial match**, not "Edited on server"; a file changed
    by hand afterwards is still flagged. A full deploy or match starts afresh, and rolling back
    the partial match forgets it.
  - "Record version only" isn't offered for these compares, since only a few files were read.
  - The AI assistant can do it too (also from Discord and Slack), under the "Start compares"
    permission: it lists the commits a site doesn't have, compares just the ones you name
    ("compare only the hotfix on prod"), and asks you before a match sends files that other
    commits also changed. `/compare prod f16451a8 9c1e2d3` does the same without the AI.
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
  `/compare staging <commit ids>` (only those commits' files), `/deploy staging`, `/history`, `/help` run at once without the AI: instant, free, and they
  work while the provider is down. The same AI permissions apply.
- **Answers appear as they're written:** the assistant's answer shows word by word. Providers
  that can't stream answer the normal way.
- **Files of each commit:** the dashboard ("Files in this commit", and "What a pull brings" when
  GitHub is ahead), the compare page and deploy/match pages list the commits involved, each with
  the files it changed as a folder tree. Click a file to see its changes under it. With several
  commits each gets its own tree; with no recorded server version, the target commit's own
  files. Loaded only when opened. A commit with malware markers never has its files opened.
- **Delete extra server files with "Make it match":** on a compare of a non-production site,
  **Extra on server** (usually files deleted in git but left on the server) gets tick boxes,
  none ticked. "Make it match" deletes the ticked ones with the usual review (where you can
  still untick the delete), backup first and **Roll back**. Protected paths are skipped and
  production never deletes. Normal deploys already delete files removed in git between the
  server's recorded version and the new commit.
- **Pull from GitHub:** when GitHub has commits your local doesn't, the dashboard offers
  **Pull N commits**. It fetches, then only fast-forwards: it refuses with uncommitted changes,
  another branch checked out, or when both sides have new commits (merge those yourself). It
  stops before any file changes when GitHub's commit has malware markers (the same check as
  Fetch), and runs no git hooks, so a pulled commit can't run code on this computer.
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

### When the project isn't on this computer

Five more speed-ups, each with its own switch in Settings → Speed-ups. The first two read your
servers on a timer, so they start **off**; the others do nothing until you fill in a site's or
local's fields.

- **Watch servers for changes** (off by default): every few hours (*Watch servers every*, default
  6) each site's files are read the way a compare reads them and compared with the last time.
  Files changed, added or removed that no deploy, match or rollback of the tool made (a hotfix on
  the server, or a hack) show on the site's card ("3 files changed on the server") and go to your
  webhook, Discord and Slack. The top folder's build files (`package.json`, `*.config.js`, `init`,
  `api.js`…) are also scanned for the npm malware's markers, in memory. The **Server watch** page
  lists the files, with **Check now** and **Mark as seen**. Protected paths are left out. On FTP
  sites each check uploads the compare's small temporary file.
- **Uptime and certificate monitor** (off by default): every few minutes (*Check sites every*,
  default 5) each site with an address loads its check pages and reads its HTTPS certificate.
  The card shows **Up**, **Down since …** or **Certificate ends in N days**; you're told when a site
  goes down (after two failed checks in a row), when it's back, and daily while the certificate
  ends within 14 days.
- **Commands after a deploy:** on an SSH or folder site's page, list commands such as
  `composer install --no-dev` or `php artisan migrate --force` (up to 10). They show at every
  review, run in the site's folder after the upload, and their output goes to the log. A failed
  command stops the run (the files stay deployed; Retry or Roll back). Only you set them: the AI
  assistant can't.
- **Database backup before a deploy:** on an SSH or folder site's page, give the database name,
  user, password (stored encrypted) and host. Every deploy and match then saves a mysqldump next
  to the file backup, before the review, and the review links to the `.sql.gz`. The login goes to
  mysqldump as an option file on its input (SSH) or a private temporary file (folder sites),
  never on the command line. If the dump fails, the run stops before anything changes. Restoring
  is up to you (import the file with your database tool).
- **Locals from a Git address:** when adding or editing a local, pick **Git address** and paste
  e.g. `https://github.com/you/project.git` (plus a read-only access token for a private
  repository, stored encrypted and sent as a header, never in the address) or a `git@…` address
  (uses your SSH key). The tool keeps its own copy in its data folder (`repos/`), and Fetch moves
  its branch to GitHub's, stopping on malware markers like Pull. Deploys, compares and the
  assistant work from it as from any local.

The timed checks run in a background process (like the chat bridges) that starts when you open
any page and stops when both are switched off.

### Screens

The pages use the whole width of wide screens (up to 1680px) and adapt down to phones: the menu
scrolls sideways, number tiles go two per row, and a run's files become small cards.

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
  buttons and nothing happens until you click) or **Off**. **Pull from GitHub** (Ask by default)
  lets the assistant pull the chat's local, the same safe way as the dashboard's Pull button:
  fast-forward only, stopped by malware on GitHub, no git hooks. It needs the "Pull from GitHub"
  speed-up switched on.
- **Your instructions** (Settings → AI assistant): house rules in plain words, added to every
  chat, e.g. "Never start an upload unless I say upload".
- **Notes per local** (Sites → edit a local): facts about that project, added to every chat
  about it, e.g. "Staging is safe any time; never deploy production on Fridays".
- Each answer shows the model that wrote it. A router like `openrouter/free` picks a different
  free model per message; one fixed model that is good at tool calling behaves far better.
- Chats are kept in `storage/chats/`. Runs the assistant starts say so in their log.

### Chatting from Discord

Ask the assistant from Discord (your phone, another computer) and get the answer there, and
let it act: compare, prepare deploys, approve staging, pull, and so on, as far as AI
permissions and **What it may do from Discord** allow. By default it asks before every action:
react ✅ or ❌ under its question, or reply yes / no. Approving a production deploy is always
done in the tool, where you type the site key.

How it works: a background process on this computer asks Discord every 3 seconds for new
messages in one channel. Nothing on this computer is opened to the internet, so the tool keeps
working only from `localhost`. Your computer and Laragon must be on; after a restart, open any
page of the tool once and the process starts again.

**Set it up (about 5 minutes):**

1. **Create the bot.** Open the [Discord Developer Portal](https://discord.com/developers/applications)
   → **New Application** → name it (e.g. `Deploy tool`) → **Bot** on the left:
   - **Reset Token** → copy the token (shown once).
   - Under **Privileged Gateway Intents**, switch on **Message Content Intent** → **Save**.
2. **Get the IDs.** In Discord: **User Settings → Advanced → Developer Mode** on. Then
   right-click the channel the bot should read → **Copy Channel ID**, and right-click your own
   name → **Copy User ID**. A private server of your own with a `#deploys` channel works well.
3. **In the tool:** Settings → Speed-ups → **Discord chat**: paste the token, channel ID and
   user ID, then **Test connection**. It shows the bot's name and an **Add the bot to your
   server** link: open it, pick your server, **Authorize**. Test again: it should say it can
   read the channel.
4. Pick **What it may do from Discord**: **Ask in Discord before each action** (recommended),
   **Same as AI permissions**, or **Only read**.
5. Switch on **Chat with the assistant from Discord** and **Save settings**. The status line
   shows **Running** within a few seconds (reload the page).

A bot added before reactions were used lacks **Add Reactions**: open the **Add the bot to your
server** link from **Test connection** again, or just reply yes / no to its questions.

**Use it** in that channel:

- `!new nova` starts a chat about the Nova local and opens a thread for it (with one local,
  just `!new`). In the thread: *is staging up to date?*, *compare staging*, *prepare a deploy
  to staging*. Before an action it posts **Confirm: …?** with ✅ and ❌: tap one, or reply
  yes / no. Writing something else instead counts as no.
- `!status`, `!status staging`, `!history`, `!compare staging`, `!deploy staging` in a thread
  run at once, without the AI (`!compare` and `!deploy` are refused with **Only read**). A
  prepared deploy still stops at its review, which you approve by asking in the thread
  (staging) or on the run's page.
- `!chats` lists the latest chats and `!open 2` opens a thread for one, so you can continue a
  chat you started in the tool. The same chats show on the Assistant page.
- `!help` lists all of this.

**Safety:**

- Only the Discord user IDs you list are answered; anyone else in the channel is ignored.
- From Discord the assistant never does more than AI permissions allow, and with the default
  choice every action waits for your ✅. Only reactions and replies from listed user IDs count.
- Approving a production deploy or match is refused from Discord: open its review page in the
  tool and type the site key. Production also never deletes files.
- The bot token is stored encrypted like your FTP passwords, and never appears in messages.
- Answers leave this computer: the assistant keeps them short and doesn't paste file contents
  unless you ask. The bot never @mentions anyone.
- Switching the speed-up off stops the process within seconds. After the tool is updated, the
  process restarts itself with the new code.

### Chatting from Slack

The same as Discord, in a Slack channel: `!new`, threads, `!status`, the ✅ / ❌ confirm
(Slack's ✅ and ❌ reactions), and **What it may do from Slack**. The tool keeps a connection
open to Slack (Socket Mode), so nothing on this computer is opened to the internet.

**Set it up (about 10 minutes).** In a company workspace an admin may have to approve the app.

1. **Create the app.** Open [api.slack.com/apps](https://api.slack.com/apps) → **Create New
   App** → **From scratch** → name it (e.g. `Deploy tool`) and pick your workspace.
2. **Socket Mode** (left menu) → switch it on → name the token (e.g. `deploy-tool`) → it gets
   the `connections:write` scope → **Generate** → copy the **app-level token** (`xapp-…`).
3. **OAuth & Permissions** → **Bot Token Scopes** → add `chat:write`, `channels:history`,
   `channels:read`, `reactions:read` and `reactions:write`. For a private channel also
   `groups:history` and `groups:read`.
4. **Event Subscriptions** → switch on → **Subscribe to bot events** → add `message.channels`
   and `reaction_added` (for a private channel also `message.groups`) → **Save Changes**.
5. **Install App** → **Install to Workspace** → **Allow** → copy the **Bot User OAuth Token**
   (`xoxb-…`). After changing scopes later, reinstall the app.
6. In Slack, open the channel and type `/invite @Deploy tool` (your app's name).
7. Copy the IDs: click the channel's name → the **Channel ID** (`C…`) is at the bottom; your
   profile picture → **Profile** → **⋯** → **Copy member ID** (`U…`).
8. **In the tool:** Settings → Speed-ups → **Slack chat**: paste both tokens, the channel ID and
   your member ID → **Test connection** (it checks both tokens, Socket Mode and the channel) →
   pick **What it may do from Slack** → switch on **Chat with the assistant from Slack** →
   **Save settings**. The status line shows **Connected** within a few seconds.

**Use it:** in the channel, `!new shop` posts a 🧵 message and the chat continues in its thread
(reply in the thread). Everything else works as described for Discord above. The safety rules
are the same: only your member IDs count, tokens are stored encrypted, production approval
stays in the tool.

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
