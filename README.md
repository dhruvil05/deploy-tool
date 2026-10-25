# Deploy tool

A small local web app that deploys the backoffice from the `staging-latest` branch
to the staging site, and from the staging site to prod, over FTPS.

It runs only on this computer. It is never uploaded to staging or prod.

## Start it

Either double-click `start.bat` (or run it from a terminal) and open http://127.0.0.1:8090.

Or, with Laragon's Apache running, open http://localhost/deploy-tool/.
`.htaccess` limits Apache to this computer (`Require local`).

## Security

- Only requests from this computer are accepted, and only for known host names.
- Every change (saving, fetching) needs the page's CSRF token, so other websites open in
  the same browser can't trigger anything.
- FTP logins are stored in `storage/sites.json`. That folder is git-ignored and blocked
  from the web. Passwords are never shown back on the page.
- Files are always taken from git commits, never from the working folder.

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

Runs move forward while their page is open. Close it and the run waits; open it and it continues.

Protected paths (uploads, module uploads, media, app-config.php, .htaccess, …) are never
uploaded, overwritten, deleted or read. See Settings.

## Testing

See [TESTING.md](TESTING.md): local test folders, no server involved.
