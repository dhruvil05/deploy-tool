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

    // History filters: apply as soon as a dropdown changes (search applies on Enter).
    document.querySelectorAll('.js-autosubmit select').forEach((select) => {
        select.addEventListener('change', () => select.form.submit());
    });
})();
