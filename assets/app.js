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

    // Run page: keep stepping the run until it stops running, then reload to show the result.
    const live = document.getElementById('run-live');
    if (live) {
        const phase = live.querySelector('.js-phase');
        const count = live.querySelector('.js-count');
        const progress = live.querySelector('.js-progress');
        const log = live.querySelector('.js-log');
        const render = (data) => {
            phase.textContent = data.phase_label;
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
                    setTimeout(step, data.busy ? 1500 : 100);
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

    // Site form: show the fields for FTP or folder sites.
    const siteType = document.querySelector('.js-site-type');
    if (siteType) {
        const sync = () => document.querySelectorAll('.js-type-group').forEach((group) => {
            group.hidden = group.dataset.type !== siteType.value;
        });
        siteType.addEventListener('change', sync);
        sync();
    }

    // Slow actions (like fetching from GitHub): show they are running and block double clicks.
    document.querySelectorAll('form.js-busy').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            button.textContent = button.dataset.busy || 'Working…';
        });
    });
})();
