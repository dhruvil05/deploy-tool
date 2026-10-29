# Testing the deploy tool locally

Everything here runs against two folders on your computer that pretend to be
"staging" and "prod". Nothing is sent to any server. Leave the real **Staging site**
and **Prod** entries without a password: without a login they can't be used.

Run the commands in **Git Bash**.

## 1. Set up

1. Start the tool: double-click `C:\laragon\www\deploy-tool\start.bat`, then open
   http://127.0.0.1:8090
2. **Settings**: enter your name and save. The repository and branch are on the
   **Backoffice** local (Sites → Locals); leave it as it is.
3. Create the two test folders from older commits (about 30 seconds each):

   ```bash
   mkdir -p /c/deploy-test/staging /c/deploy-test/prod && cd /c/laragon/www/backoffice-bkp && git archive --format=tar 9c638b0f | tar -x -C /c/deploy-test/staging && git archive --format=tar 154244ad | tar -x -C /c/deploy-test/prod
   ```

4. Add files that must never be touched, and make one "edit on the server":

   ```bash
   cd /c/deploy-test/staging && mkdir -p uploads modules/hrm/uploads application/config && echo "customer file" > uploads/keep.txt && echo "module file" > modules/hrm/uploads/keep.txt && echo "<?php // fake config" > application/config/app-config.php && echo "// edited on the server" >> application/controllers/admin/Reports_employee.php
   ```

5. **Sites → Add site**, twice:

   | Field | Test staging | Test prod |
   |---|---|---|
   | Name / Key | Test staging / `test-staging` | Test prod / `test-prod` |
   | Gets code from | Local · Backoffice (staging-latest) | Test staging |
   | Production site | off | **on** |
   | Where the site is | A folder on this computer | A folder on this computer |
   | Folder | `C:\deploy-test\staging` | `C:\deploy-test\prod` |
   | Site address / Check page | leave empty | leave empty |

## 2. First deploy to staging (review, warnings, backup)

1. Dashboard → **Test staging → Deploy**.
2. It asks which commit the server is on: choose **9c638b0f** → **Prepare deploy**.
3. Watch the steps run. The review should show **134 uploads (40 new, 94 changed), 1 delete,
   1 warning**.
4. Click **Warnings**. `Reports_employee.php` says "Edited on server". Click **Server edits**
   to see your line.
5. Copy the backup folder path shown and open it in Explorer: the server copies are there.
6. Click **Upload** without ticking the warning box: it's blocked. Tick it, leave
   "Also delete" on, and click **Upload**.
7. When it reaches **SQL**, click **SQL is done**.

Check in `C:\deploy-test\staging`:
- `uploads\keep.txt`, `modules\hrm\uploads\keep.txt`, `application\config\app-config.php`: unchanged
- `modules\recruitment\views\candidate_profile\_quick_view.php`: gone (deleted in git)
- `application\.deploy\state.json`: exists, with commit 108d6cbd and your name
- Dashboard: Test staging is **Up to date**

## 3. Roll it back

1. **History** → open that deploy → **Roll back this deploy…** → review → **Roll back**.

Check:
- `Reports_employee.php` has your "edited on the server" line again
- `assets\js\task_list_dates.js` is gone (it was new in the deploy)
- `_quick_view.php` is back
- `application\.deploy\state.json` is gone (there was none before)

## 4. Compare, record, deploy normally

1. Dashboard → **Test staging → Compare** → commit `9c638b0f` → **Start compare**.
2. The result should show **1 different** (`Reports_employee.php`) and nothing missing.
   The uploads files don't appear: protected folders are never read.
3. **Record version only** (tick the box) → **Record version**.
4. **Deploy** again. This time it doesn't ask for a starting commit. Approve it as before.

## 5. Prod (a server that was copied by hand)

1. Make prod look hand-edited:

   ```bash
   cd /c/deploy-test/prod && mkdir -p uploads && echo "customer file" > uploads/keep.txt && echo "// hand edit on prod" >> application/config/config.php
   ```

2. Dashboard → **Test prod → Compare**. The commit is filled in with what the staging
   folder runs → **Start compare**.
3. Expect **10 different** (9 changed in git, plus your edit in `config.php`).
4. **Make it match** → review → click Upload without typing: it's refused. Type
   `test-prod` → Upload → **SQL is done**.
5. Deploy to Test prod again: it says the site is already on this commit.
6. Check that `C:\deploy-test\prod\uploads\keep.txt` is unchanged.

## 6. Pipelines and locals on the dashboard

1. The dashboard shows **Local · Backoffice** with two rows: Pipeline 1 (Staging site → Prod)
   and Pipeline 2 (Test staging → Test prod). Arrows only join sites that are connected.
2. **Hide** on the Staging site and Prod cards: they move to "Show 2 hidden site(s)" at the
   bottom. Show them again from there.
3. **Add local**: name `Backoffice main`, key `backoffice-main`, folder
   `C:\laragon\www\backoffice-bkp`, branch `main`. It appears as a second local with
   "No site gets its code from this local yet".
4. On the Test staging card, set **Gets code from** to `Local · Backoffice main (main)`.
   Test staging and Test prod move under the new local together.
5. Open Test prod's dropdown: Test prod is not offered for Test staging (that would be a loop).
6. Try deleting the Backoffice main local (Sites → Locals): it's refused while a site uses it.
7. Set Test staging back to `Local · Backoffice (staging-latest)`, then delete Backoffice main.

## 7. Stop, resume and safety checks

- **Close the tab while it runs:** start a Compare on Test staging and close the tab halfway.
  The dashboard shows it as running; open it and it carries on.
- **Stop:** press **Stop** on a running compare. It becomes "Stopped by you". Press **Retry**
  to continue.
- **Uncommitted changes:** change any tracked file in the backoffice repo (don't commit) and
  start a deploy to Test staging. The safety checks fail with "uncommitted changes" and
  nothing happens. Undo the change afterwards.
- **One run per site:** while a deploy waits for review, try to start another one on the
  same site. It's refused.

## 8. Clean up

Delete `C:\deploy-test`, and in **Sites** delete the two test sites.
History and backups are in `C:\laragon\www\deploy-tool\storage` (safe to delete).

## Background runs

Start a Compare or Deploy, then close the tab or reload it. Open the dashboard later: the
run kept going. If it didn't, `storage/worker.log` says why (the page then works as before).

## SSH site

1. Make a key without a passphrase: `ssh-keygen -t ed25519 -N "" -f %USERPROFILE%\.ssh\deploy_tool`
2. cPanel → SSH Access → Manage SSH Keys → Import the `.pub` file → Authorize it.
3. Sites → Add site → "On a server (SSH)": host, port, cPanel username, key file
   `C:\Users\you\.ssh\deploy_tool`, folder e.g. `~/stagingoffice.dvijinfotech.com`, PHP `php`.
   Production off, and use a test folder first, not staging or prod.
4. **Test connection** → "Connected". Then **Compare**: it should take seconds.

## FTP with several connections

On the real staging site: **Compare** with "File sizes over FTP". It should be about 4× faster
than before (the old run took about 45–60 minutes). If the host refuses connections, set
"Connections at once" to 2 or 1.

## Later: test over FTP, on a server that isn't staging or prod

Folder sites test everything except the FTP connection itself. To test FTP:

1. On a test server (not staging or prod), make an **empty** folder.
2. Add a site: "On a server (FTPS)", with that server's login, **FTP folder** = the empty
   folder, and the matching site address. Leave Production off.
3. **Test connection**: "Connected". It warns there's no index.php there, which is fine.
4. **Deploy**, choose starting commit **154244ad** → review shows **9 files**, all
   "Missing on server" (the folder is empty) → tick the warnings → Upload.
5. Check the 9 files on the server, then **Roll back**: they're deleted again.
