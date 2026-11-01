(() => {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const failed = '<span class="badge badge-danger">Error</span><p class="error-text">The tool did not answer. Check that it is still running.</p>';

    async function siteStatus(site, sourceCommit) {
        const params = new URLSearchParams({ r: 'site-status', site });
        if (sourceCommit) {
            params.set('source_commit', sourceCommit);
        }
        const response = await fetch('index.php?' + params, {
            headers: { Accept: 'application/json', 'X-CSRF-Token': token },
        });
        const data = await response.json();
        if (!data.html) {
            throw new Error(data.error || 'No status returned');
        }
        return data;
    }

    // Dashboard: check sites in pipeline order, giving each one the commit its source has.
    const siteCards = document.querySelectorAll('.site-card');
    if (siteCards.length) {
        (async () => {
            const commits = {};
            document.querySelectorAll('.local-card').forEach((card) => {
                commits[card.dataset.ref] = card.dataset.commit || null;
            });
            for (const card of siteCards) {
                const box = card.querySelector('.site-status');
                try {
                    const data = await siteStatus(card.dataset.site, commits[card.dataset.source] ?? null);
                    box.innerHTML = data.html;
                    commits['site:' + card.dataset.site] = data.commit;
                } catch (error) {
                    box.innerHTML = failed;
                }
            }
        })();
    }

    // Sites page: "Test connection".
    document.querySelectorAll('.js-test').forEach((button) => {
        button.addEventListener('click', async () => {
            const row = document.querySelector(`.test-row[data-for="${button.dataset.site}"]`);
            const box = row.querySelector('.test-result');
            row.hidden = false;
            box.innerHTML = '<p class="meta">Connecting…</p>';
            button.disabled = true;
            try {
                box.innerHTML = (await siteStatus(button.dataset.site, null)).html;
            } catch (error) {
                box.innerHTML = failed;
            } finally {
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('form.js-confirm').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    // Run page: show progress until the run stops running, then reload to show the result.
    // A background worker normally does the work; without one, each call does a step.
    const live = document.getElementById('run-live');
    if (live) {
        const phase = live.querySelector('.js-phase');
        const count = live.querySelector('.js-count');
        const progress = live.querySelector('.js-progress');
        const log = live.querySelector('.js-log');
        const steps = [...document.querySelectorAll('.steps li[data-phase]')];
        const render = (data) => {
            phase.textContent = data.phase_label;
            const at = steps.findIndex((li) => li.dataset.phase === data.phase);
            if (at >= 0) {
                steps.forEach((li, index) => {
                    li.className = index < at ? 'step-done' : (index === at ? 'step-current' : 'step-pending');
                });
            }
            count.textContent = data.total > 0 ? `${data.done} of ${data.total}` : '';
            progress.max = Math.max(data.total, 1);
            progress.value = data.total > 0 ? data.done : 0;
            log.replaceChildren(...data.log.map(([time, level, message]) => {
                const item = document.createElement('li');
                item.className = 'log-' + level;
                item.textContent = `${time}  ${message}`;
                return item;
            }));
        };
        const step = async () => {
            try {
                const response = await fetch('index.php?r=run-step&id=' + encodeURIComponent(live.dataset.run), {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-Token': token },
                });
                const data = await response.json();
                if (data.ok === false) {
                    throw new Error(data.error);
                }
                render(data);
                if (data.status === 'running') {
                    setTimeout(step, data.worker ? 1000 : (data.busy ? 1500 : 100));
                } else {
                    window.location.reload();
                }
            } catch (error) {
                count.textContent = 'Lost contact with the tool. Trying again…';
                setTimeout(step, 3000);
            }
        };
        step();
    }

    // Review screen: filter the file list.
    document.querySelectorAll('.js-filter').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelectorAll('.js-filter').forEach((other) => other.classList.toggle('active', other === button));
            const filter = button.dataset.filter;
            document.querySelectorAll('.files-table tbody tr').forEach((row) => {
                row.hidden = !(filter === 'all'
                    || (filter === 'warning' && row.dataset.warning === '1')
                    || row.dataset.action === filter);
            });
        });
    });

    // Dashboard: "Gets code from" saves as soon as it changes (production sites ask first).
    document.querySelectorAll('form.js-source').forEach((form) => {
        const select = form.querySelector('select');
        const original = select.value;
        select.addEventListener('change', () => {
            const label = select.options[select.selectedIndex].text;
            if (form.dataset.production === '1'
                && !window.confirm(`${form.dataset.name} is a production site. Make it get its code from ${label}?`)) {
                select.value = original;
                return;
            }
            form.submit();
        });
    });

    // "Copy command" buttons.
    document.querySelectorAll('.js-copy').forEach((button) => {
        const label = button.textContent;
        button.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(button.dataset.copy);
                button.textContent = 'Copied';
            } catch (error) {
                button.textContent = 'Select the command and copy it';
            }
            setTimeout(() => { button.textContent = label; }, 1800);
        });
    });

    // Site form: show the fields for the chosen kind of site (FTP, SSH or folder).
    const siteTypes = [...document.querySelectorAll('.js-site-type')];
    if (siteTypes.length) {
        const sync = () => {
            const type = (siteTypes.find((radio) => radio.checked) || siteTypes[0]).value;
            document.querySelectorAll('.js-type-group').forEach((group) => {
                group.hidden = group.dataset.type !== type;
            });
        };
        siteTypes.forEach((radio) => radio.addEventListener('change', sync));
        sync();
    }

    // Local form: the Protected paths panel beside it. It shows this local's rules with how
    // much each covers, and adds more from compares, suggestions or the folder tree. Every
    // change goes into the form's textarea, which is saved with the form.
    const localForm = document.querySelector('.js-local-form');
    const panel = document.querySelector('.js-protect');
    if (localForm && panel) {
        const textarea = document.getElementById('extra_protected');
        const ruleList = panel.querySelector('.js-rule-list');
        const ruleCount = panel.querySelector('.js-rule-count');
        const status = panel.querySelector('.js-protect-status');
        const tabs = [...panel.querySelectorAll('.js-tab')];
        const panes = Object.fromEntries([...panel.querySelectorAll('.js-panel')].map((pane) => [pane.dataset.tab, pane]));
        const search = panel.querySelector('.js-picker-search');
        const unsaved = document.querySelector('.js-unsaved');
        const summary = panel.querySelector('.js-rule-summary');
        const saved = textarea.value;
        const bigFolder = 500; // ask before protecting this many files at once
        const expanded = new Set();
        let showFiles = false;
        let current = null;
        let data = null;
        let children = new Map(); // parent folder ('' = top) => [[folder, files], ...]
        let request = 0;

        const escape = (text) => text.replace(/[.+?^${}()|[\]\\]/g, '\\$&');
        // Same matching as ProtectedPaths::regex() on the server.
        const toRegex = (rule) => new RegExp('^' + rule.split('*').map(escape).join('[^/]+') + (rule.endsWith('/') ? '' : '$'), 'i');
        const typedRules = () => textarea.value.split(/\r?\n/)
            .map((line) => line.trim().replace(/\\/g, '/').replace(/^\/+/, ''))
            .filter(Boolean);
        // The rule that already protects a path, other than the path's own typed rule (which its tick removes).
        const coveredBy = (path) => (data ? data.builtIn : []).find((rule) => toRegex(rule).test(path))
            || typedRules().find((rule) => rule !== path && toRegex(rule).test(path))
            || null;
        const files = (count) => `${count.toLocaleString()} file${count === 1 ? '' : 's'}`;
        // Long paths keep their start and end: "application/…/Serializer/HTML/x.ser".
        const shorten = (path) => {
            if (path.length <= 52) {
                return path;
            }
            const parts = path.split('/');
            const tail = parts.slice(-2).join('/');
            return tail.length > 46 ? '…' + path.slice(-46) : parts[0] + '/…/' + tail;
        };
        const el = (tag, className, text) => {
            const node = document.createElement(tag);
            if (className) {
                node.className = className;
            }
            if (text !== undefined) {
                node.textContent = text;
            }
            return node;
        };

        const setRules = (rules) => {
            textarea.value = rules.join('\n');
            textarea.dispatchEvent(new Event('input'));
        };
        const toggle = (rule, on, count) => {
            const rules = typedRules().filter((line) => line !== rule);
            if (on) {
                if (count >= bigFolder && !window.confirm(`${rule} has ${files(count)} in git. Protecting it means none of them are ever uploaded to this local's sites. Protect it?`)) {
                    return false;
                }
                rules.push(rule);
            }
            setRules(rules);
            return true;
        };

        // One pickable path: [▸] [✓] path  12 files  (covered by …)
        const pickRow = (rule, count, { depth = null, folder = null, title = '' } = {}) => {
            const item = el('li', 'pick-row');
            if (depth !== null) {
                item.style.paddingLeft = (depth * 16) + 'px';
                if (children.has(folder)) {
                    const open = expanded.has(folder);
                    const button = el('button', 'pick-expand', open ? '▾' : '▸');
                    button.type = 'button';
                    button.setAttribute('aria-label', (open ? 'Close ' : 'Open ') + folder);
                    button.addEventListener('click', () => {
                        open ? expanded.delete(folder) : expanded.add(folder);
                        render();
                    });
                    item.append(button);
                } else {
                    item.append(el('span', 'pick-expand'));
                }
            }
            const label = el('label', 'pick-label');
            const box = document.createElement('input');
            box.type = 'checkbox';
            const cover = coveredBy(rule);
            box.checked = cover !== null || typedRules().includes(rule);
            box.disabled = cover !== null;
            box.addEventListener('change', () => {
                if (!toggle(rule, box.checked, count)) {
                    box.checked = !box.checked;
                }
            });
            const name = depth !== null ? rule.slice(rule.slice(0, -1).lastIndexOf('/') + 1) : shorten(rule);
            const path = el('span', 'pick-path', name);
            path.title = rule + (title ? ' · ' + title : '');
            label.append(box, path);
            item.append(label, el('span', 'pill', files(count)));
            if (cover !== null) {
                const via = el('span', 'pick-via', 'covered');
                via.title = 'Already protected by ' + cover;
                item.append(via);
            }
            return item;
        };

        // This local's rules in three groups: ones that need a fix (a folder missing its "/"),
        // ones that protect files, and ones not in git (fine for server-only files).
        const renderRules = () => {
            const counts = new Map((data ? data.rules : []).map(([rule, inGit, onServer, fix]) => [rule, { inGit, onServer, fix }]));
            const rules = typedRules();
            ruleCount.textContent = rules.length;
            summary.textContent = '';
            if (!rules.length) {
                ruleList.replaceChildren(el('li', 'meta', 'Nothing yet. Tick paths under "Add paths" to protect them.'));
                return;
            }
            const row = (rule, note, noteTitle = '') => {
                const item = el('li', 'rule-row');
                const path = el('code', 'rule-path', shorten(rule));
                path.title = rule;
                const count = el('span', 'rule-count', note);
                count.title = noteTitle;
                const remove = el('button', 'rule-remove', '×');
                remove.type = 'button';
                remove.setAttribute('aria-label', 'Remove ' + rule);
                remove.title = 'Remove';
                remove.addEventListener('click', () => setRules(typedRules().filter((line) => line !== rule)));
                item.append(path, count, remove);
                return item;
            };
            const head = (text, help, className = '') => {
                const item = el('li', 'rule-group ' + className, text);
                if (help) {
                    item.append(el('span', 'rule-group-help', help));
                }
                return item;
            };
            const fixes = [];
            const active = [];
            const unused = [];
            let total = 0;
            for (const rule of rules) {
                const info = counts.get(rule);
                if (!info) {
                    active.push(row(rule, '…'));
                } else if (info.inGit + info.onServer > 0) {
                    total += info.inGit + info.onServer;
                    active.push(row(rule, files(info.inGit + info.onServer), `${files(info.inGit)} in git, ${files(info.onServer)} only on servers`));
                } else if (info.fix) {
                    const item = row(rule, '');
                    item.classList.add('rule-bad');
                    const fix = el('button', 'small', `Change to ${info.fix}`);
                    fix.type = 'button';
                    fix.addEventListener('click', () => setRules(typedRules().map((line) => (line === rule ? info.fix : line))));
                    item.insertBefore(fix, item.lastChild);
                    fixes.push(item);
                } else {
                    unused.push(row(rule, 'not in git'));
                }
            }
            if (data) {
                summary.textContent = `· ${files(total)} covered`;
            }
            ruleList.replaceChildren(
                ...(fixes.length ? [head('Needs a fix', 'These look like folders but have no "/" at the end, so they protect nothing.', 'is-warning'), ...fixes] : []),
                ...active,
                ...(unused.length ? [head('Not in git', 'Fine for files that exist only on the server, like .user.ini. Otherwise check the spelling.'), ...unused] : []),
            );
        };

        const renderServer = () => {
            const list = panes.server.querySelector('.pick-rows');
            const folders = data.server.filter(([rule]) => rule.endsWith('/'));
            const singles = data.server.filter(([rule]) => !rule.endsWith('/'));
            const rows = folders.map(([rule, count, sites]) => pickRow(rule, count, { title: 'on ' + sites.join(', ') }));
            if (singles.length) {
                const item = el('li', 'pick-row');
                const button = el('button', 'pick-more', (showFiles ? '▾ ' : '▸ ') + `${singles.length} single file${singles.length === 1 ? '' : 's'}`);
                button.type = 'button';
                button.addEventListener('click', () => {
                    showFiles = !showFiles;
                    render();
                });
                item.append(button);
                rows.push(item);
                if (showFiles) {
                    rows.push(...singles.map(([rule, count, sites]) => pickRow(rule, count, { title: 'on ' + sites.join(', ') })));
                }
            }
            list.replaceChildren(...rows);
            if (!rows.length) {
                list.replaceChildren(el('li', 'meta', 'No compare has found server-only files yet. Run a Compare on one of this local\'s sites.'));
            }
        };

        const renderSuggest = () => {
            const folderFiles = new Map(data.folders);
            const list = panes.suggest.querySelector('.pick-rows');
            list.replaceChildren(...data.suggestions.map((rule) => pickRow(rule, folderFiles.get(rule.slice(0, -1)) || 0)));
            if (!data.suggestions.length) {
                list.replaceChildren(el('li', 'meta', 'Nothing to suggest: the usual folders are already protected.'));
            }
        };

        const renderTree = () => {
            const query = search.value.trim().toLowerCase();
            const rows = [];
            if (query) {
                for (const [folder, count] of data.folders) {
                    if (folder.toLowerCase().includes(query)) {
                        rows.push(pickRow(folder + '/', count));
                        if (rows.length >= 200) {
                            break;
                        }
                    }
                }
            } else {
                const add = (parent, depth) => {
                    for (const [folder, count] of children.get(parent) || []) {
                        rows.push(pickRow(folder + '/', count, { depth, folder }));
                        if (expanded.has(folder)) {
                            add(folder, depth + 1);
                        }
                    }
                };
                add('', 0);
            }
            const list = panes.browse.querySelector('.pick-rows');
            list.replaceChildren(...rows);
            if (!rows.length) {
                list.replaceChildren(el('li', 'meta', 'No folder matches.'));
            }
        };

        const show = (name) => {
            current = name;
            tabs.forEach((tab) => tab.setAttribute('aria-selected', String(tab.dataset.tab === name)));
            Object.entries(panes).forEach(([key, pane]) => { pane.hidden = !data || key !== name; });
        };

        const render = () => {
            renderRules();
            unsaved.hidden = textarea.value === saved;
            if (!data) {
                return;
            }
            const unprotected = (rules) => rules.filter((rule) => coveredBy(rule) === null && !typedRules().includes(rule)).length;
            tabs[0].querySelector('.js-tab-count').textContent = unprotected(data.server.map(([rule]) => rule)) || '';
            tabs[1].querySelector('.js-tab-count').textContent = unprotected(data.suggestions) || '';
            renderServer();
            renderSuggest();
            renderTree();
        };

        // Typed or ticked rules are counted again by the server; folders are only reloaded
        // when the repository or branch changes.
        const load = async (withFolders) => {
            const mine = ++request;
            if (withFolders) {
                status.textContent = 'Loading the repository\'s folders…';
                status.hidden = false;
            }
            try {
                const response = await fetch('index.php?r=protect-info', {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new FormData(localForm),
                });
                const answer = await response.json();
                if (mine !== request) {
                    return;
                }
                if (!answer.ok) {
                    data = null;
                    status.textContent = answer.error;
                    status.hidden = false;
                    show(current);
                    render();
                    return;
                }
                if (withFolders || !data) {
                    children = new Map();
                    for (const entry of answer.folders) {
                        const slash = entry[0].lastIndexOf('/');
                        const parent = slash < 0 ? '' : entry[0].slice(0, slash);
                        if (!children.has(parent)) {
                            children.set(parent, []);
                        }
                        children.get(parent).push(entry);
                    }
                }
                data = answer;
                status.hidden = true;
                panel.querySelector('.js-picker-ref').textContent = `Folders in git at ${answer.ref}.`;
                show(current || (answer.server.some(([rule]) => rule.endsWith('/')) ? 'server' : 'browse'));
                render();
            } catch (error) {
                if (mine === request) {
                    status.textContent = 'Could not load the folders. Check that the tool is still running.';
                    status.hidden = false;
                }
            }
        };

        tabs.forEach((tab) => tab.addEventListener('click', () => show(tab.dataset.tab)));
        let timer = null;
        textarea.addEventListener('input', () => {
            render(); // ticks and the rule list follow the text right away
            clearTimeout(timer);
            timer = setTimeout(() => load(false), 500);
        });
        ['repo_path', 'branch'].forEach((field) => localForm.querySelector('#' + field).addEventListener('change', () => load(true)));
        search.addEventListener('input', () => data && renderTree());
        render();
        load(true);
    }

    // Compare result: files shown as a folder tree. A folder's box ticks every file in it and
    // shows a part-ticked state; whole lists can be ticked too. Keeps "N of M ticked" up to date.
    const syncForm = document.getElementById('sync-form');
    if (syncForm) {
        const boxes = [...document.querySelectorAll('input[form="sync-form"][name="files[]"]')];
        const folders = [...document.querySelectorAll('.js-tree-dir')].map((box) => ({
            box,
            files: [...box.closest('details').querySelectorAll('input[name="files[]"]')],
        }));
        const count = syncForm.querySelector('.js-pick-count');
        const update = () => {
            count.textContent = boxes.filter((box) => box.checked).length;
            for (const { box, files } of folders) {
                const ticked = files.filter((file) => file.checked).length;
                box.checked = ticked === files.length;
                box.indeterminate = ticked > 0 && ticked < files.length;
            }
        };
        boxes.forEach((box) => box.addEventListener('change', update));
        folders.forEach(({ box, files }) => box.addEventListener('change', () => {
            files.forEach((file) => { file.checked = box.checked; });
            update();
        }));
        document.querySelectorAll('.js-check-all').forEach((button) => {
            button.addEventListener('click', () => {
                boxes.filter((box) => box.dataset.list === button.dataset.list)
                    .forEach((box) => { box.checked = button.dataset.checked === '1'; });
                update();
            });
        });
        update();
    }

    // "Restore the default list" and similar: put a stored value into a field (saved with the form).
    document.querySelectorAll('.js-fill').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById(button.dataset.target).value = button.dataset.fill;
        });
    });

    // Slow actions (like fetching from GitHub): show they are running and block double clicks.
    document.querySelectorAll('form.js-busy').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            button.textContent = button.dataset.busy || 'Working…';
        });
    });

    // Compare result: a number tile opens its list before jumping to it.
    document.querySelectorAll('.js-open-section').forEach((link) => {
        link.addEventListener('click', () => {
            const target = document.querySelector(link.getAttribute('href'));
            if (target && target.tagName === 'DETAILS') {
                target.open = true;
            }
        });
    });

    // Compare start: quick picks and the recent-commit list fill the commit field.
    document.querySelectorAll('.js-fill-input').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById(button.dataset.target).value = button.dataset.value;
        });
    });
    document.querySelectorAll('.js-pick-commit').forEach((select) => {
        select.addEventListener('change', () => {
            if (select.value !== '') {
                document.getElementById(select.dataset.target).value = select.value;
            }
        });
    });

    // Settings: the side menu shows one section at a time without reloading. The open section
    // is kept in the address and sent with the form, so saving comes back to it.
    const settingsForm = document.querySelector('.js-settings-form');
    if (settingsForm) {
        const settingsTabs = [...document.querySelectorAll('.js-settings-tab')];
        const current = settingsForm.querySelector('.js-settings-current');
        const showTab = (name, focus = false) => {
            settingsTabs.forEach((link) => {
                const on = link.dataset.tab === name;
                link.classList.toggle('active', on);
                link.setAttribute('aria-selected', String(on));
                link.tabIndex = on ? 0 : -1;
                if (on && focus) {
                    link.focus();
                }
            });
            document.querySelectorAll('.js-settings-pane').forEach((pane) => { pane.hidden = pane.dataset.tab !== name; });
            current.value = name;
            window.history.replaceState(null, '', 'index.php?r=settings&tab=' + encodeURIComponent(name));
        };
        settingsTabs.forEach((link, index) => {
            link.tabIndex = link.classList.contains('active') ? 0 : -1;
            link.addEventListener('click', (event) => {
                event.preventDefault();
                showTab(link.dataset.tab);
            });
            link.addEventListener('keydown', (event) => {
                const step = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[event.key];
                if (step) {
                    event.preventDefault();
                    showTab(settingsTabs[(index + step + settingsTabs.length) % settingsTabs.length].dataset.tab, true);
                }
            });
        });
        document.querySelectorAll('.js-settings-goto').forEach((link) => link.addEventListener('click', (event) => {
            event.preventDefault();
            showTab(link.dataset.tab);
            window.scrollTo(0, 0);
        }));
        // Storage: default folder or one you choose, with a folder picker that lists this
        // computer's drives and folders.
        const customBlock = document.querySelector('.js-storage-custom');
        if (customBlock) {
            const modes = [...document.querySelectorAll('.js-storage-mode')];
            const pathInput = document.getElementById('storage_path');
            const picker = customBlock.querySelector('.js-folder-picker');
            const list = picker.querySelector('.js-folder-list');
            const current = picker.querySelector('.js-folder-current');
            const note = picker.querySelector('.js-folder-note');
            const up = picker.querySelector('.js-folder-up');
            const use = picker.querySelector('.js-folder-use');
            let shown = null;
            const copyBox = document.querySelector('input[name="storage_copy"]');
            modes.forEach((radio) => radio.addEventListener('change', () => {
                customBlock.hidden = radio.value !== 'custom' || !radio.checked;
                // Back to the default folder: it usually still holds the data from before.
                copyBox.checked = radio.value === 'custom';
            }));
            const browse = async (path) => {
                note.hidden = true;
                try {
                    const response = await fetch('index.php?r=storage-browse&path=' + encodeURIComponent(path), { headers: { Accept: 'application/json', 'X-CSRF-Token': token } });
                    const answer = await response.json();
                    if (!answer.ok) {
                        throw new Error(answer.error);
                    }
                    shown = answer;
                    current.textContent = answer.path || 'This computer';
                    up.disabled = answer.parent === null;
                    use.disabled = answer.path === '';
                    list.replaceChildren(...answer.folders.map((folder) => {
                        const item = document.createElement('li');
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = folder.name;
                        button.addEventListener('click', () => browse(folder.path));
                        item.append(button);
                        return item;
                    }));
                    if (!answer.folders.length) {
                        const empty = document.createElement('li');
                        empty.className = 'meta';
                        empty.textContent = 'No folders inside. You can use this one.';
                        list.append(empty);
                    }
                    if (answer.hasData || (answer.path && !answer.writable)) {
                        note.textContent = answer.hasData
                            ? 'This folder already has deploy tool data: untick "Copy my current data" to use it as it is.'
                            : 'The tool may not be able to write here.';
                        note.hidden = false;
                    }
                } catch (error) {
                    note.textContent = error.message || 'Could not list that folder.';
                    note.hidden = false;
                }
            };
            customBlock.querySelector('.js-folder-browse').addEventListener('click', () => {
                picker.hidden = !picker.hidden;
                if (!picker.hidden) {
                    browse(pathInput.value.trim());
                }
            });
            up.addEventListener('click', () => shown && shown.parent !== null && browse(shown.parent));
            use.addEventListener('click', () => {
                if (shown && shown.path) {
                    pathInput.value = shown.path;
                    picker.hidden = true;
                }
            });
        }

        // Cloud backup: presets fill the endpoint and region; Test, Back up now and the list
        // of backups talk to the tool without leaving the page.
        const cloudResult = document.querySelector('.js-cloud-result');
        if (cloudResult) {
            const endpoint = document.getElementById('cloud_endpoint');
            const region = document.getElementById('cloud_region');
            document.querySelectorAll('.js-cloud-provider').forEach((radio) => radio.addEventListener('change', () => {
                if (radio.dataset.endpoint !== '' && (endpoint.value === '' || [...document.querySelectorAll('.js-cloud-provider')].some((other) => other.dataset.endpoint === endpoint.value))) {
                    endpoint.value = radio.dataset.endpoint;
                }
                region.value = radio.dataset.region;
            }));
            const say = (text, kind) => {
                cloudResult.textContent = text;
                cloudResult.className = 'meta js-cloud-result' + (kind ? ' cloud-' + kind : '');
            };
            const ask = async (button, route, body) => {
                const label = button.textContent;
                button.disabled = true;
                button.textContent = button.dataset.busy || label;
                try {
                    const response = await fetch('index.php?r=' + route, { method: 'POST', headers: { Accept: 'application/json' }, body });
                    return await response.json();
                } catch (error) {
                    return { ok: false, error: 'Could not reach the tool. Check that it is still running.' };
                } finally {
                    button.disabled = false;
                    button.textContent = label;
                }
            };
            const tokenOnly = () => {
                const body = new FormData();
                body.append('_csrf', token);
                return body;
            };
            document.querySelector('.js-cloud-test').addEventListener('click', async (event) => {
                const answer = await ask(event.currentTarget, 'cloud-test', new FormData(settingsForm));
                say(answer.ok ? answer.message : answer.error, answer.ok ? 'ok' : 'bad');
            });
            document.querySelector('.js-cloud-now').addEventListener('click', async (event) => {
                say('Zipping, encrypting and uploading…', '');
                const answer = await ask(event.currentTarget, 'cloud-backup-now', tokenOnly());
                say(answer.ok ? answer.message : answer.error, answer.ok ? 'ok' : 'bad');
            });
            const backups = document.querySelector('.js-cloud-backups');
            document.querySelector('.js-cloud-list').addEventListener('click', async (event) => {
                backups.replaceChildren();
                const answer = await ask(event.currentTarget, 'cloud-list', tokenOnly());
                const line = (text, className) => {
                    const item = document.createElement('li');
                    item.className = className;
                    item.textContent = text;
                    return item;
                };
                if (!answer.ok) {
                    backups.append(line(answer.error, 'error-text'));
                    return;
                }
                if (!answer.backups.length) {
                    backups.append(line('No backups in the bucket yet.', 'meta'));
                    return;
                }
                const restoreForm = document.getElementById('cloud-restore-form');
                backups.append(...answer.backups.map((backup) => {
                    const item = document.createElement('li');
                    const text = document.createElement('span');
                    const name = document.createElement('code');
                    name.textContent = backup.name;
                    const meta = document.createElement('span');
                    meta.className = 'meta';
                    meta.textContent = `${backup.when} · ${backup.size}`;
                    text.append(name, meta);
                    const restore = document.createElement('button');
                    restore.type = 'button';
                    restore.className = 'small';
                    restore.textContent = 'Restore…';
                    restore.addEventListener('click', () => {
                        const passphrase = window.prompt(`Restore ${backup.name}?\n\nThis replaces this computer's data; what's here now is saved to restore-safety first.\n\nPassphrase (leave blank to use the saved one):`, '');
                        if (passphrase === null) {
                            return;
                        }
                        restoreForm.querySelector('.js-cloud-restore-key').value = backup.key;
                        restoreForm.querySelector('.js-cloud-restore-pass').value = passphrase;
                        restoreForm.submit();
                    });
                    item.append(text, restore);
                    return item;
                }));
            });
        }

        // On a phone the menu is a sideways strip: bring the open section into view.
        const activeTab = settingsTabs.find((link) => link.classList.contains('active'));
        if (activeTab) {
            activeTab.parentElement.scrollLeft = activeTab.offsetLeft - activeTab.parentElement.offsetLeft - 8;
        }
        const dirty = settingsForm.querySelector('.js-settings-dirty');
        const markDirty = () => { dirty.hidden = false; };
        settingsForm.addEventListener('input', markDirty);
        settingsForm.addEventListener('change', markDirty);
    }

    // Settings → AI assistant: provider presets fill the base URL; Load models and Test use
    // the form as it is now, saved or not.
    const aiCard = document.querySelector('.js-ai-card');
    if (aiCard) {
        const form = aiCard.closest('form');
        const base = aiCard.querySelector('#ai_base_url');
        const providers = [...aiCard.querySelectorAll('.js-ai-provider')];
        const noKey = aiCard.querySelector('.js-ai-nokey');
        const syncKeyHint = () => {
            const chosen = providers.find((radio) => radio.checked);
            noKey.hidden = !chosen || chosen.dataset.key === '1';
        };
        providers.forEach((radio) => radio.addEventListener('change', () => {
            if (radio.dataset.base !== '') {
                base.value = radio.dataset.base;
            }
            syncKeyHint();
        }));
        syncKeyHint();

        const call = async (button, route) => {
            const label = button.textContent;
            button.disabled = true;
            button.textContent = button.dataset.busy;
            try {
                const response = await fetch('index.php?r=' + route, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new FormData(form),
                });
                return await response.json();
            } catch (error) {
                return { ok: false, error: 'Could not reach the tool. Check that it is still running.' };
            } finally {
                button.disabled = false;
                button.textContent = label;
            }
        };

        const modelsButton = aiCard.querySelector('.js-ai-models');
        const modelsStatus = aiCard.querySelector('.js-ai-models-status');
        modelsButton.addEventListener('click', async () => {
            const answer = await call(modelsButton, 'ai-models');
            if (!answer.ok) {
                modelsStatus.textContent = answer.error;
                modelsStatus.className = 'help error-text js-ai-models-status';
                return;
            }
            aiCard.querySelector('#ai-model-list').replaceChildren(...answer.models.map((id) => {
                const option = document.createElement('option');
                option.value = id;
                return option;
            }));
            modelsStatus.className = 'help js-ai-models-status';
            modelsStatus.textContent = answer.models.length
                ? `Loaded ${answer.models.length} models: click the Model box and type to filter.`
                : 'The provider listed no models. Type the model name yourself.';
        });

        const testButton = aiCard.querySelector('.js-ai-test');
        const testResult = aiCard.querySelector('.js-ai-test-result');
        testButton.addEventListener('click', async () => {
            const answer = await call(testButton, 'ai-test');
            const box = document.createElement('div');
            const title = document.createElement('strong');
            const text = document.createElement('span');
            if (!answer.ok) {
                box.className = 'notice notice-danger';
                title.textContent = 'Not working';
                text.textContent = answer.error;
            } else if (answer.tools) {
                box.className = 'notice notice-success';
                title.textContent = `Connected · answered in ${answer.seconds} s`;
                text.textContent = 'This model supports tool calling, so the assistant can use the tool\'s features.';
            } else {
                box.className = 'notice notice-warning';
                title.textContent = `Connected · answered in ${answer.seconds} s`;
                text.textContent = 'But it didn\'t call the test tool, so it may not support tool calling. Try another model.';
            }
            box.append(title, text);
            testResult.replaceChildren(box);
        });
    }

    // Assistant chat list: a delete button on each chat, and Select mode for deleting
    // several. Works on the list as it is re-drawn after answers and renames.
    const chatListCard = document.querySelector('.js-chat-list-card');
    if (chatListCard) {
        const chatList = chatListCard.querySelector('.js-chat-list');
        const deleteForm = chatListCard.querySelector('.js-chat-delete-form');
        const selectButton = chatListCard.querySelector('.js-chat-select');
        const selectBar = chatListCard.querySelector('.js-chat-select-bar');
        const selectAll = chatListCard.querySelector('.js-chat-select-all');
        const deleteSelected = chatListCard.querySelector('.js-chat-delete-selected');
        const picks = () => [...chatList.querySelectorAll('.js-chat-pick')];
        const sendDelete = (ids) => {
            deleteForm.querySelectorAll('input[name="chats[]"]').forEach((input) => input.remove());
            ids.forEach((id) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'chats[]';
                input.value = id;
                deleteForm.append(input);
            });
            // The page may have moved to a chat made after it loaded.
            const open = new URLSearchParams(window.location.search).get('chat');
            deleteForm.querySelector('.js-chat-delete-back').value = open || '';
            deleteForm.submit();
        };
        const updateSelection = () => {
            const chosen = picks().filter((box) => box.checked);
            deleteSelected.disabled = chosen.length === 0;
            deleteSelected.textContent = chosen.length ? `Delete ${chosen.length}` : 'Delete';
            selectAll.checked = chosen.length > 0 && chosen.length === picks().length;
            selectAll.indeterminate = chosen.length > 0 && chosen.length < picks().length;
        };
        const selecting = (on) => {
            chatListCard.classList.toggle('is-selecting', on);
            selectBar.hidden = !on;
            selectButton.hidden = on;
            picks().forEach((box) => { box.checked = false; });
            updateSelection();
        };
        selectButton.addEventListener('click', () => selecting(true));
        chatListCard.querySelector('.js-chat-select-done').addEventListener('click', () => selecting(false));
        selectAll.addEventListener('change', () => {
            picks().forEach((box) => { box.checked = selectAll.checked; });
            updateSelection();
        });
        deleteSelected.addEventListener('click', () => {
            const ids = picks().filter((box) => box.checked).map((box) => box.value);
            if (ids.length && window.confirm(`Delete ${ids.length} chat${ids.length === 1 ? '' : 's'}? This can't be undone.`)) {
                sendDelete(ids);
            }
        });
        chatList.addEventListener('change', (event) => {
            if (event.target.matches('.js-chat-pick')) {
                updateSelection();
            }
        });
        chatList.addEventListener('click', (event) => {
            const remove = event.target.closest('.js-chat-delete');
            if (remove) {
                if (window.confirm(`Delete the chat "${remove.dataset.title}"? This can't be undone.`)) {
                    sendDelete([remove.closest('[data-chat]').dataset.chat]);
                }
                return;
            }
            // In Select mode a click on a chat ticks it instead of opening it.
            const link = event.target.closest('a');
            if (link && chatListCard.classList.contains('is-selecting')) {
                event.preventDefault();
                const box = link.closest('[data-chat]').querySelector('.js-chat-pick');
                box.checked = !box.checked;
                updateSelection();
            }
        });
    }

    // Assistant: send a message without leaving the page. The message shows at once; while
    // the assistant works, its actions appear as it does them (polled every second), and
    // Stop asks it to stop after the current step. Then its answer replaces the progress.
    const chatForm = document.querySelector('.js-chat-form');
    if (chatForm) {
        const log = document.querySelector('.js-chat-log');
        const input = chatForm.querySelector('textarea');
        const send = chatForm.querySelector('button[type="submit"]');
        const stop = chatForm.querySelector('.js-chat-stop');
        const usage = document.querySelector('.js-chat-usage');
        const chatField = chatForm.querySelector('input[name="chat"]');
        const post = async (route, fields) => {
            const body = new FormData();
            body.append('_csrf', token);
            Object.entries(fields).forEach(([key, value]) => body.append(key, value));
            const response = await fetch('index.php?r=' + route, { method: 'POST', headers: { Accept: 'application/json' }, body });
            return response.json();
        };
        const scrollDown = () => { log.scrollTop = log.scrollHeight; };
        const bubble = (className, text) => {
            const node = document.createElement('div');
            node.className = className;
            node.textContent = text;
            log.append(node);
            scrollDown();
            return node;
        };
        const showUsage = (text) => {
            usage.textContent = text;
            usage.hidden = false;
        };
        // The answer in progress: what the server has so far, plus a "working" line.
        const live = (() => {
            let box = null;
            let pollTimer = null;
            let finished = true;
            const working = () => {
                const line = document.createElement('div');
                line.className = 'chat-bot chat-pending';
                line.textContent = 'Working on it…';
                return line;
            };
            const render = (html) => {
                box.innerHTML = html; // escaped by the server's template
                if (!finished) {
                    box.append(working());
                }
                scrollDown();
            };
            const poll = async () => {
                try {
                    const answer = await (await fetch('index.php?r=assistant-poll&chat=' + encodeURIComponent(chatField.value), { headers: { Accept: 'application/json' } })).json();
                    if (finished || !answer.ok) {
                        return;
                    }
                    render(answer.html);
                    showUsage(answer.usage);
                    if (!answer.busy && box.dataset.reloadWhenDone === '1') {
                        window.location.reload();
                        return;
                    }
                } catch (error) {
                    // the next poll tries again
                }
                if (!finished) {
                    pollTimer = setTimeout(poll, 1000);
                }
            };
            return {
                start(reloadWhenDone = false) {
                    finished = false;
                    box = document.createElement('div');
                    box.className = 'chat-live';
                    box.dataset.reloadWhenDone = reloadWhenDone ? '1' : '';
                    log.append(box);
                    render('');
                    pollTimer = setTimeout(poll, 800);
                    stop.hidden = false;
                    stop.disabled = false;
                    stop.textContent = 'Stop';
                    send.disabled = true;
                },
                finish(html) {
                    finished = true;
                    clearTimeout(pollTimer);
                    render(html);
                    stop.hidden = true;
                    send.disabled = false;
                },
                fail(message) {
                    finished = true;
                    clearTimeout(pollTimer);
                    box.replaceChildren();
                    const note = document.createElement('div');
                    note.className = 'chat-bot is-error';
                    note.textContent = message;
                    box.append(note);
                    stop.hidden = true;
                    send.disabled = false;
                },
            };
        })();
        scrollDown();
        if (log.dataset.busy === '1') {
            live.start(true); // the page was reloaded mid-answer: follow it, then show the result
        }

        stop.addEventListener('click', async () => {
            stop.disabled = true;
            stop.textContent = 'Stopping…';
            try {
                await post('assistant-stop', { chat: chatField.value });
            } catch (error) {
                stop.disabled = false;
                stop.textContent = 'Stop';
            }
        });

        // Rename: the pencil turns the title into a text box. Enter or leaving it saves,
        // Esc cancels.
        const title = document.querySelector('.js-chat-title');
        const titleInput = document.querySelector('.js-chat-title-input');
        const renameButton = document.querySelector('.js-chat-rename');
        const closeRename = () => {
            titleInput.hidden = true;
            title.hidden = false;
            renameButton.hidden = false;
        };
        const saveRename = async () => {
            const name = titleInput.value.trim();
            if (titleInput.hidden) {
                return;
            }
            if (name === '' || name === title.textContent) {
                closeRename();
                return;
            }
            titleInput.disabled = true;
            try {
                const answer = await post('assistant-rename', { chat: chatField.value, title: name });
                if (!answer.ok) {
                    throw new Error(answer.error);
                }
                title.textContent = answer.title;
                document.querySelector('.js-chat-list').innerHTML = answer.list;
            } catch (error) {
                window.alert(error.message || 'Could not rename the chat.');
            } finally {
                titleInput.disabled = false;
                closeRename();
            }
        };
        renameButton.addEventListener('click', () => {
            titleInput.value = title.textContent;
            title.hidden = true;
            renameButton.hidden = true;
            titleInput.hidden = false;
            titleInput.focus();
            titleInput.select();
        });
        titleInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                saveRename();
            } else if (event.key === 'Escape') {
                closeRename();
            }
        });
        titleInput.addEventListener('blur', saveRename);

        chatForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const text = input.value.trim();
            if (text === '' || send.disabled) {
                return;
            }
            document.querySelector('.js-chat-empty')?.remove();
            bubble('chat-user', text);
            input.value = '';
            send.disabled = true;
            try {
                if (chatField.value === '') {
                    // A new chat gets its id first, so its progress can be followed.
                    const created = await post('assistant-new', { local: document.querySelector('.chat-local select')?.value || '' });
                    if (!created.ok) {
                        throw new Error(created.error);
                    }
                    chatField.value = created.chat;
                    document.querySelector('.js-chat-rename').hidden = false;
                    window.history.replaceState(null, '', 'index.php?r=assistant&chat=' + encodeURIComponent(created.chat));
                    const picker = document.querySelector('.chat-local'); // a chat keeps its local
                    if (picker) {
                        const pill = document.createElement('span');
                        pill.className = 'pill';
                        pill.textContent = picker.querySelector('select').selectedOptions[0].text;
                        picker.replaceWith(pill);
                    }
                }
                live.start();
                const answer = await post('assistant-send', { chat: chatField.value, message: text });
                if (!answer.ok) {
                    throw new Error(answer.error || 'The assistant could not answer.');
                }
                live.finish(answer.html);
                showUsage(answer.usage);
                document.querySelector('.js-chat-list').innerHTML = answer.list;
                document.querySelector('.js-chat-title').textContent = answer.title;
            } catch (error) {
                live.fail(error.message || 'Could not reach the tool. Check that it is still running.');
                input.value = text;
            } finally {
                scrollDown();
                input.focus();
            }
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                chatForm.requestSubmit();
            }
        });
        document.querySelectorAll('.js-suggest').forEach((button) => button.addEventListener('click', () => {
            input.value = button.dataset.text;
            chatForm.requestSubmit();
        }));
    }

    // History filters: apply as soon as a dropdown changes (search applies on Enter).
    document.querySelectorAll('.js-autosubmit select').forEach((select) => {
        select.addEventListener('change', () => select.form.submit());
    });
})();
