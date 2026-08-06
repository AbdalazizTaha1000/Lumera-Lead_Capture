/* =========================================================================
 * Lumera — admin shell
 * Router, API client, UI primitives (toast / modal / confirm), and the
 * Dashboard, Leads and Settings views.
 *
 * The Funnel Builder view lives in funnel-builder.js and registers itself on
 * window.Lumera.
 * ========================================================================= */
(function () {
    'use strict';

    var body = document.body;

    var Lumera = window.Lumera = {
        csrf: body.dataset.csrf || '',
        funnelId: parseInt(body.dataset.funnelId || '0', 10),
        funnelSlug: body.dataset.funnelSlug || '',
        views: {}
    };

    /* ============================================================== utils */
    function $(selector, scope) { return (scope || document).querySelector(selector); }
    function $$(selector, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(selector)); }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        return node;
    }

    function clear(node) {
        while (node && node.firstChild) { node.removeChild(node.firstChild); }
    }

    function formatDate(value) {
        if (!value) { return '—'; }
        var date = new Date(String(value).replace(' ', 'T'));
        if (isNaN(date.getTime())) { return String(value); }

        return date.toLocaleString(undefined, {
            year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit'
        });
    }

    function relative(value) {
        if (!value) { return 'never'; }
        var date = new Date(String(value).replace(' ', 'T'));
        if (isNaN(date.getTime())) { return String(value); }

        var seconds = Math.floor((Date.now() - date.getTime()) / 1000);
        if (seconds < 60) { return 'just now'; }
        if (seconds < 3600) { return Math.floor(seconds / 60) + ' min ago'; }
        if (seconds < 86400) { return Math.floor(seconds / 3600) + ' h ago'; }
        if (seconds < 2592000) { return Math.floor(seconds / 86400) + ' d ago'; }

        return formatDate(value);
    }

    /* ================================================================ api */
    function api(path, options) {
        options = options || {};

        var init = {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        };

        if (options.body !== undefined) {
            if (options.body instanceof FormData) {
                options.body.append('csrf_token', Lumera.csrf);
                init.body = options.body;
            } else {
                init.headers['Content-Type'] = 'application/json';
                var payload = Object.assign({}, options.body, { csrf_token: Lumera.csrf });
                init.body = JSON.stringify(payload);
            }

            init.headers['X-CSRF-Token'] = Lumera.csrf;
        }

        return fetch(path, init).then(function (response) {
            if (response.status === 401) {
                window.location.href = '/admin/login.php';
                return Promise.reject(new Error('unauthenticated'));
            }

            return response.json().catch(function () {
                return { ok: false, error: 'Unexpected server response.' };
            }).then(function (data) {
                if (!data || data.ok !== true) {
                    var error = new Error((data && data.error) || 'Request failed.');
                    error.data = data || {};
                    error.status = response.status;
                    return Promise.reject(error);
                }

                return data;
            });
        });
    }

    /* ============================================================== toast */
    function toast(message, type) {
        var stack = $('#toast-stack');
        var node = el('div', 'toast' + (type ? ' toast--' + type : ''), message);
        stack.appendChild(node);

        window.setTimeout(function () {
            node.style.opacity = '0';
            window.setTimeout(function () {
                if (node.parentNode) { node.parentNode.removeChild(node); }
            }, 220);
        }, 3600);
    }

    /* ============================================================== modal */
    var modal = {
        node: null,
        open: function (title, bodyNode, footNodes, wide) {
            this.node = $('#modal');
            $('#modal-title').textContent = title;

            var bodyHost = $('#modal-body');
            clear(bodyHost);
            bodyHost.appendChild(bodyNode);

            var footHost = $('#modal-foot');
            clear(footHost);
            (footNodes || []).forEach(function (button) { footHost.appendChild(button); });

            this.node.classList.toggle('modal--wide', wide === true);
            this.node.hidden = false;
            document.body.style.overflow = 'hidden';
        },
        close: function () {
            var node = $('#modal');
            node.hidden = true;
            document.body.style.overflow = '';
        }
    };

    function confirmAction(title, message, confirmLabel) {
        return new Promise(function (resolve) {
            var wrap = el('div');
            wrap.appendChild(el('p', null, message));

            var cancel = el('button', 'btn btn--ghost', 'Cancel');
            cancel.type = 'button';
            cancel.addEventListener('click', function () { modal.close(); resolve(false); });

            var confirm = el('button', 'btn btn--danger', confirmLabel || 'Delete');
            confirm.type = 'button';
            confirm.addEventListener('click', function () { modal.close(); resolve(true); });

            modal.open(title, wrap, [cancel, confirm]);
        });
    }

    /* =========================================================== form kit */
    function formGroup(label, control, help) {
        var group = el('div', 'form-group');
        var labelNode = el('label', 'form-label', label);

        if (control.id) { labelNode.htmlFor = control.id; }

        group.appendChild(labelNode);
        group.appendChild(control);

        if (help) { group.appendChild(el('p', 'form-help', help)); }

        return group;
    }

    function input(id, value, type, placeholder) {
        var node = document.createElement('input');
        node.className = 'form-control';
        node.type = type || 'text';
        node.id = id;
        node.value = value === null || value === undefined ? '' : value;
        if (placeholder) { node.placeholder = placeholder; }
        return node;
    }

    function textarea(id, value, rows) {
        var node = document.createElement('textarea');
        node.className = 'form-control';
        node.id = id;
        node.rows = rows || 3;
        node.value = value === null || value === undefined ? '' : value;
        return node;
    }

    function select(id, options, value) {
        var node = document.createElement('select');
        node.className = 'form-control';
        node.id = id;

        options.forEach(function (option) {
            var opt = document.createElement('option');
            opt.value = option.value;
            opt.textContent = option.label;
            if (String(option.value) === String(value)) { opt.selected = true; }
            node.appendChild(opt);
        });

        return node;
    }

    function checkbox(id, label, checked) {
        var wrap = el('div', 'form-check');
        var node = document.createElement('input');
        node.type = 'checkbox';
        node.id = id;
        node.checked = !!checked;

        var labelNode = el('label', null, label);
        labelNode.htmlFor = id;

        wrap.appendChild(node);
        wrap.appendChild(labelNode);

        return wrap;
    }

    function badge(text, variant) {
        return el('span', 'badge' + (variant ? ' badge--' + variant : ''), text);
    }

    function button(label, className, onClick) {
        var node = el('button', 'btn ' + className, label);
        node.type = 'button';
        if (onClick) { node.addEventListener('click', onClick); }
        return node;
    }

    function miniButton(symbol, title, className, onClick) {
        var node = el('button', 'mini-button' + (className ? ' ' + className : ''), symbol);
        node.type = 'button';
        node.title = title;
        node.setAttribute('aria-label', title);
        node.addEventListener('click', onClick);
        return node;
    }

    function table(headers, rows) {
        var node = el('table', 'table');
        var thead = el('thead');
        var tr = el('tr');

        headers.forEach(function (header) { tr.appendChild(el('th', null, header)); });
        thead.appendChild(tr);
        node.appendChild(thead);

        var tbody = el('tbody');
        rows.forEach(function (row) { tbody.appendChild(row); });
        node.appendChild(tbody);

        return node;
    }

    function emptyState(title, text) {
        var wrap = el('div', 'empty-state');
        wrap.appendChild(el('p', 'empty-state__title', title));
        wrap.appendChild(el('p', 'empty-state__text', text));
        return wrap;
    }

    function barList(items) {
        var wrap = el('div', 'bar-list');
        var max = items.reduce(function (acc, item) { return Math.max(acc, parseInt(item.total, 10) || 0); }, 0);

        if (items.length === 0) {
            return emptyState('No data yet', 'Figures appear once leads start arriving.');
        }

        items.forEach(function (item) {
            var total = parseInt(item.total, 10) || 0;
            var row = el('div', 'bar-item');
            var head = el('div', 'bar-item__head');

            head.appendChild(el('span', 'bar-item__label', item.label || '—'));
            head.appendChild(el('span', 'bar-item__value', total));
            row.appendChild(head);

            var track = el('div', 'bar-item__track');
            var fill = el('div', 'bar-item__fill');
            fill.style.width = (max > 0 ? Math.round((total / max) * 100) : 0) + '%';
            track.appendChild(fill);
            row.appendChild(track);

            wrap.appendChild(row);
        });

        return wrap;
    }

    var STATUS_VARIANTS = {
        new: 'info', contacted: 'warning', qualified: 'success',
        unqualified: 'muted', converted: 'gold', archived: 'muted'
    };

    var EMAIL_VARIANTS = { sent: 'success', failed: 'danger', pending: 'warning', skipped: 'muted' };

    /* ============================================================= expose */
    Object.assign(Lumera, {
        $: $, $$: $$, el: el, clear: clear, api: api, toast: toast, modal: modal,
        confirmAction: confirmAction, formGroup: formGroup, input: input,
        textarea: textarea, select: select, checkbox: checkbox, badge: badge,
        button: button, miniButton: miniButton, table: table, emptyState: emptyState,
        barList: barList, formatDate: formatDate, relative: relative,
        STATUS_VARIANTS: STATUS_VARIANTS, EMAIL_VARIANTS: EMAIL_VARIANTS
    });

    /* ========================================================== dashboard */
    Lumera.views.dashboard = {
        title: 'Dashboard',
        subtitle: 'Overview of your lead capture funnel',
        actions: function () { return []; },
        render: function () {
            return api('/api/admin/dashboard.php').then(function (data) {
                renderStats(data.stats);
                renderLatest(data.latest);
                renderFunnelStatus(data.funnel);

                $('#breakdown-source').replaceChildren(barList(data.breakdowns.source || []));
                $('#breakdown-budget').replaceChildren(barList(data.breakdowns.budget || []));
                $('#breakdown-purpose').replaceChildren(barList(data.breakdowns.purpose || []));
            });
        }
    };

    function statCard(label, value, meta, variant) {
        var card = el('div', 'stat-card' + (variant ? ' stat-card--' + variant : ''));
        card.appendChild(el('p', 'stat-card__label', label));
        card.appendChild(el('p', 'stat-card__value', value));
        if (meta) { card.appendChild(el('p', 'stat-card__meta', meta)); }
        return card;
    }

    function renderStats(stats) {
        var grid = $('#stat-grid');
        clear(grid);

        grid.appendChild(statCard('Total leads', stats.total, 'All time'));
        grid.appendChild(statCard('Today', stats.today, 'Since midnight'));
        grid.appendChild(statCard('This week', stats.week, 'Last 7 days'));
        grid.appendChild(statCard('This month', stats.month, 'Last 30 days'));
        grid.appendChild(statCard('New / unhandled', stats.new_leads, 'Status: new'));

        if (stats.email_failures > 0) {
            grid.appendChild(statCard('Email failures', stats.email_failures, 'Leads were still saved', 'warn'));
        }
    }

    function renderLatest(latest) {
        var host = $('#latest-leads');
        clear(host);

        if (!latest || latest.length === 0) {
            host.appendChild(emptyState('No leads yet', 'Submissions from the public funnel will appear here.'));
            return;
        }

        var rows = latest.map(function (lead) {
            var tr = el('tr', 'is-clickable');
            tr.addEventListener('click', function () { openLeadDetail(lead.id); });

            tr.appendChild(el('td', 'num', '#' + lead.id));
            tr.appendChild(el('td', null, lead.full_name || '—'));

            var contact = el('td', 'muted', (lead.country_code || '') + ' ' + (lead.phone || ''));
            tr.appendChild(contact);

            var statusCell = el('td');
            statusCell.appendChild(badge(lead.status, STATUS_VARIANTS[lead.status]));
            tr.appendChild(statusCell);

            tr.appendChild(el('td', 'num', lead.lead_score));
            tr.appendChild(el('td', 'muted', relative(lead.submitted_at)));

            return tr;
        });

        host.appendChild(table(['ID', 'Name', 'Phone', 'Status', 'Score', 'Submitted'], rows));
    }

    function renderFunnelStatus(funnel) {
        var host = $('#funnel-status-card');
        clear(host);

        if (!funnel) {
            host.appendChild(emptyState('No funnel found', 'Run the installer to seed the Property Finder funnel.'));
            return;
        }

        var list = el('div', 'definition-list');

        function row(label, valueNode) {
            var line = el('div', 'definition-list__row');
            line.appendChild(el('span', 'definition-list__label', label));

            var value = el('span', 'definition-list__value');
            if (typeof valueNode === 'string') { value.textContent = valueNode; } else { value.appendChild(valueNode); }

            line.appendChild(value);
            list.appendChild(line);
        }

        row('Funnel', funnel.name);
        row('Status', badge(funnel.status, funnel.status === 'active' ? 'success' : 'warning'));
        row('Active steps', funnel.active_steps + ' of ' + funnel.total_steps);
        row('Published version', funnel.published_version > 0 ? 'v' + funnel.published_version : 'not published');
        row('Last published', formatDate(funnel.published_at));
        row('Draft changes', funnel.has_unpublished ? badge('unpublished', 'warning') : badge('in sync', 'success'));

        host.appendChild(list);

        var actions = el('div', 'form-group');
        actions.appendChild(button('Open Funnel Builder', 'btn--primary btn--sm', function () {
            window.location.hash = '#/builder';
        }));
        host.appendChild(actions);
    }

    /* ============================================================ funnels */
    var funnelState = { includeArchived: false, rows: [] };

    Lumera.views.funnels = {
        title: 'Funnels',
        subtitle: 'Every funnel on this installation, with its own branding and URL',
        actions: function () {
            return [button('New funnel', 'btn--primary btn--sm', openCreateFunnel)];
        },
        render: loadFunnels
    };

    function loadFunnels() {
        return api('/api/admin/funnels.php' + (funnelState.includeArchived ? '?include_archived=1' : ''))
            .then(function (data) {
                funnelState.rows = data.funnels || [];
                renderFunnelTools(data);
                renderFunnelTable(data.funnels || []);
            });
    }

    function renderFunnelTools(data) {
        var host = $('#funnels-tools');
        clear(host);

        var toggle = checkbox('show-archived', 'Show archived', funnelState.includeArchived);
        toggle.querySelector('input').addEventListener('change', function () {
            funnelState.includeArchived = this.checked;
            loadFunnels().catch(function (e) { toast(e.message, 'error'); });
        });

        host.appendChild(toggle);

        if (data.archived > 0 && !funnelState.includeArchived) {
            host.appendChild(badge(data.archived + ' archived', 'muted'));
        }
    }

    /** Small logo thumbnail, falling back to the funnel's brand initial. */
    function funnelMark(funnel) {
        if (funnel.logo_path) {
            var img = el('img', 'funnel-mark__img');
            img.src = funnel.logo_path;
            img.alt = funnel.company_name || funnel.name;
            img.loading = 'lazy';
            return img;
        }

        var mark = el('span', 'funnel-mark', (funnel.company_name || funnel.name || '?').charAt(0).toUpperCase());
        mark.style.background = funnel.primary_color || '#0F2E4C';
        mark.style.color = funnel.accent_color || '#fff';

        return mark;
    }

    function renderFunnelTable(funnels) {
        var host = $('#funnels-table');
        clear(host);

        if (funnels.length === 0) {
            host.appendChild(emptyState('No funnels yet', 'Create your first funnel to start capturing leads.'));
            return;
        }

        var rows = funnels.map(function (funnel) {
            var tr = el('tr');

            if (funnel.is_archived) { tr.className = 'is-archived'; }

            var markCell = el('td');
            markCell.appendChild(funnelMark(funnel));
            tr.appendChild(markCell);

            var nameCell = el('td');
            nameCell.appendChild(el('div', 'cell-title', funnel.name));
            nameCell.appendChild(el('div', 'cell-sub', funnel.company_name || '—'));
            tr.appendChild(nameCell);

            var slugCell = el('td');
            slugCell.appendChild(el('code', 'cell-code', '/' + funnel.slug));
            tr.appendChild(slugCell);

            var statusCell = el('td');
            if (funnel.is_archived) {
                statusCell.appendChild(badge('archived', 'muted'));
            } else {
                statusCell.appendChild(badge(funnel.status, funnel.status === 'active' ? 'success' : 'warning'));
            }
            if (funnel.webhook_enabled) { statusCell.appendChild(badge('webhook', 'info')); }
            tr.appendChild(statusCell);

            tr.appendChild(el('td', 'num', funnel.leads_count));

            var publishedCell = el('td', 'muted');
            publishedCell.textContent = funnel.published_version > 0
                ? 'v' + funnel.published_version + ' · ' + relative(funnel.published_at)
                : 'never published';
            tr.appendChild(publishedCell);

            var actions = el('td');
            var group = el('div', 'row-actions');

            group.appendChild(button('Edit', 'btn--ghost btn--sm', function () {
                Lumera.openFunnelInBuilder(funnel.id);
            }));

            var openLink = el('a', 'btn btn--ghost btn--sm', 'Open');
            openLink.href = funnel.public_url;
            openLink.target = '_blank';
            openLink.rel = 'noopener noreferrer';
            openLink.title = funnel.public_url;

            if (funnel.is_archived || funnel.status !== 'active') {
                openLink.classList.add('is-disabled');
                openLink.title = 'The public URL is only live while the funnel is active and not archived.';
            }

            group.appendChild(openLink);

            group.appendChild(button('Duplicate', 'btn--ghost btn--sm', function () {
                openDuplicateFunnel(funnel);
            }));

            if (funnel.is_archived) {
                group.appendChild(button('Restore', 'btn--ghost btn--sm', function () {
                    funnelAction('restore', funnel, 'Funnel restored.');
                }));
            } else {
                group.appendChild(button('Archive', 'btn--ghost btn--sm', function () {
                    confirmAction(
                        'Archive funnel',
                        '"' + funnel.name + '" will stop serving its public URL and disappear from the active list. '
                        + 'Nothing is deleted and you can restore it at any time.',
                        'Archive'
                    ).then(function (ok) {
                        if (ok) { funnelAction('archive', funnel, 'Funnel archived.'); }
                    });
                }));
            }

            group.appendChild(button('Delete', 'btn--danger btn--sm', function () {
                openDeleteFunnel(funnel);
            }));

            actions.appendChild(group);
            tr.appendChild(actions);

            return tr;
        });

        host.appendChild(table(
            ['', 'Funnel', 'Slug', 'Status', 'Leads', 'Last published', 'Actions'],
            rows
        ));
    }

    function funnelAction(action, funnel, successMessage) {
        return api('/api/admin/funnels.php', { method: 'POST', body: { action: action, funnel_id: funnel.id } })
            .then(function () {
                toast(successMessage, 'success');
                loadFunnels();
            })
            .catch(function (error) { toast(error.message, 'error'); });
    }

    function slugify(value) {
        return String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120);
    }

    function openCreateFunnel() {
        var wrap = el('div');

        var name = input('new-funnel-name', '', 'text', 'e.g. Reef 996 Launch');
        wrap.appendChild(formGroup('Funnel name', name));

        var company = input('new-funnel-company', '', 'text', 'e.g. Reef Developments');
        wrap.appendChild(formGroup('Company name', company, 'Shown on the public funnel and in notifications.'));

        var slug = input('new-funnel-slug', '', 'text', 'reef-996');
        wrap.appendChild(formGroup('Public URL slug', slug, 'The funnel will be served at /<slug>.'));

        // Keep the slug in step with the name until the user edits it directly.
        var slugTouched = false;
        slug.addEventListener('input', function () { slugTouched = true; });
        name.addEventListener('input', function () {
            if (!slugTouched) { slug.value = slugify(name.value); }
        });

        var cancel = button('Cancel', 'btn--ghost', function () { modal.close(); });

        var create = button('Create funnel', 'btn--primary', function () {
            api('/api/admin/funnels.php', {
                method: 'POST',
                body: {
                    action: 'create',
                    name: name.value,
                    company_name: company.value,
                    slug: slug.value || slugify(name.value)
                }
            }).then(function (data) {
                modal.close();
                toast('Funnel created as a draft.', 'success');
                Lumera.openFunnelInBuilder(data.funnel.id);
            }).catch(function (error) {
                if (error.data && error.data.errors) {
                    toast(Object.values(error.data.errors)[0], 'error');
                    return;
                }
                toast(error.message, 'error');
            });
        });

        modal.open('New funnel', wrap, [cancel, create]);
    }

    function openDuplicateFunnel(funnel) {
        var wrap = el('div');

        wrap.appendChild(el('p', 'form-help',
            'Steps, options, contact fields, conditional rules, branding and settings are copied. '
            + 'Leads, analytics and audit history are not. The copy starts as an unpublished draft.'));

        var name = input('dup-name', funnel.name + ' (Copy)');
        wrap.appendChild(formGroup('New funnel name', name));

        var slug = input('dup-slug', slugify(funnel.slug + '-copy'));
        wrap.appendChild(formGroup('New URL slug', slug));

        var cancel = button('Cancel', 'btn--ghost', function () { modal.close(); });

        var run = button('Duplicate', 'btn--primary', function () {
            api('/api/admin/funnels.php', {
                method: 'POST',
                body: { action: 'duplicate', funnel_id: funnel.id, name: name.value, slug: slug.value }
            }).then(function (data) {
                modal.close();
                toast('Funnel duplicated as "' + data.funnel.name + '".', 'success');
                loadFunnels();
            }).catch(function (error) { toast(error.message, 'error'); });
        });

        modal.open('Duplicate funnel', wrap, [cancel, run]);
    }

    function openDeleteFunnel(funnel) {
        var wrap = el('div');

        wrap.appendChild(el('p', null,
            'Permanently delete "' + funnel.name + '"? Its steps, options and contact fields are removed.'));

        if (funnel.leads_count > 0) {
            var warn = el('p', 'alert alert--warning',
                funnel.leads_count + ' lead(s) were captured by this funnel. They are kept in the database with '
                + 'their answers intact and stay visible under Leads — but they will no longer be linked to a funnel.');
            wrap.appendChild(warn);
        }

        if (funnel.published_version > 0) {
            wrap.appendChild(el('p', 'alert alert--warning',
                'This funnel is published (v' + funnel.published_version + ') and is serving /' + funnel.slug + '. '
                + 'Consider archiving instead — archiving is reversible.'));
        }

        var needsConfirm = funnel.leads_count > 0 || funnel.published_version > 0;
        var confirmBox = null;

        if (needsConfirm) {
            confirmBox = checkbox('confirm-permanent', 'I understand this is permanent and cannot be undone', false);
            wrap.appendChild(confirmBox);
        }

        var cancel = button('Cancel', 'btn--ghost', function () { modal.close(); });

        var archiveInstead = button('Archive instead', 'btn--ghost', function () {
            modal.close();
            funnelAction('archive', funnel, 'Funnel archived.');
        });

        var remove = button('Delete permanently', 'btn--danger', function () {
            if (needsConfirm && !confirmBox.querySelector('input').checked) {
                toast('Tick the confirmation box to delete this funnel.', 'error');
                return;
            }

            api('/api/admin/funnels.php', {
                method: 'POST',
                body: { action: 'delete', funnel_id: funnel.id, confirm_permanent: needsConfirm }
            }).then(function (data) {
                modal.close();
                toast(
                    data.leads_retained > 0
                        ? 'Funnel deleted. ' + data.leads_retained + ' lead(s) were kept.'
                        : 'Funnel deleted.',
                    'success'
                );
                loadFunnels();
            }).catch(function (error) { toast(error.message, 'error'); });
        });

        modal.open('Delete funnel', wrap, [cancel, archiveInstead, remove]);
    }

    /* ============================================================== leads */
    var leadState = {
        filters: { search: '', status: '', date_from: '', date_to: '', source: '', campaign: '', budget: '', purpose: '' },
        page: 1,
        perPage: 25,
        options: { statuses: [], sources: [], campaigns: [] }
    };

    Lumera.views.leads = {
        title: 'Leads',
        subtitle: 'Submissions captured by the funnel',
        actions: function () {
            return [button('Export CSV', 'btn--ghost btn--sm', exportCsv)];
        },
        render: function () { return loadLeads(); }
    };

    function queryString() {
        var params = new URLSearchParams();

        Object.keys(leadState.filters).forEach(function (key) {
            if (leadState.filters[key]) { params.set(key, leadState.filters[key]); }
        });

        params.set('page', String(leadState.page));
        params.set('per_page', String(leadState.perPage));

        return params.toString();
    }

    function loadLeads() {
        return api('/api/admin/leads.php?' + queryString()).then(function (data) {
            leadState.options = data.filters;
            renderFilters();
            renderLeadTable(data.leads);
            renderPagination(data.pagination);
        });
    }

    function renderFilters() {
        var host = $('#lead-filters');
        clear(host);

        var search = input('filter-search', leadState.filters.search, 'search', 'Name, email, phone or ID');
        host.appendChild(formGroup('Search', search));

        var statuses = [{ value: '', label: 'All statuses' }].concat(
            (leadState.options.statuses || []).map(function (s) { return { value: s, label: s }; })
        );
        var statusSelect = select('filter-status', statuses, leadState.filters.status);
        host.appendChild(formGroup('Status', statusSelect));

        var from = input('filter-from', leadState.filters.date_from, 'date');
        host.appendChild(formGroup('From', from));

        var to = input('filter-to', leadState.filters.date_to, 'date');
        host.appendChild(formGroup('To', to));

        var sources = [{ value: '', label: 'All sources' }].concat(
            (leadState.options.sources || []).map(function (s) { return { value: s, label: s }; })
        );
        var sourceSelect = select('filter-source', sources, leadState.filters.source);
        host.appendChild(formGroup('Source', sourceSelect));

        var campaigns = [{ value: '', label: 'All campaigns' }].concat(
            (leadState.options.campaigns || []).map(function (c) { return { value: c, label: c }; })
        );
        var campaignSelect = select('filter-campaign', campaigns, leadState.filters.campaign);
        host.appendChild(formGroup('Campaign', campaignSelect));

        var budget = input('filter-budget', leadState.filters.budget, 'text', 'e.g. 2m_5m');
        host.appendChild(formGroup('Budget value', budget, 'Internal option value'));

        var purpose = input('filter-purpose', leadState.filters.purpose, 'text', 'e.g. invest');
        host.appendChild(formGroup('Purpose value', purpose, 'Internal option value'));

        var actions = el('div', 'form-group');
        var row = el('div', 'filters__actions');

        row.appendChild(button('Apply', 'btn--primary btn--sm', function () {
            leadState.filters.search = search.value.trim();
            leadState.filters.status = statusSelect.value;
            leadState.filters.date_from = from.value;
            leadState.filters.date_to = to.value;
            leadState.filters.source = sourceSelect.value;
            leadState.filters.campaign = campaignSelect.value;
            leadState.filters.budget = budget.value.trim();
            leadState.filters.purpose = purpose.value.trim();
            leadState.page = 1;
            loadLeads().catch(function (e) { toast(e.message, 'error'); });
        }));

        row.appendChild(button('Reset', 'btn--ghost btn--sm', function () {
            Object.keys(leadState.filters).forEach(function (key) { leadState.filters[key] = ''; });
            leadState.page = 1;
            loadLeads().catch(function (e) { toast(e.message, 'error'); });
        }));

        actions.appendChild(row);
        host.appendChild(actions);

        search.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                leadState.filters.search = search.value.trim();
                leadState.page = 1;
                loadLeads().catch(function (e) { toast(e.message, 'error'); });
            }
        });
    }

    function renderLeadTable(leads) {
        var host = $('#leads-table');
        clear(host);

        if (!leads || leads.length === 0) {
            host.appendChild(emptyState('No leads match these filters', 'Adjust or reset the filters to see more results.'));
            return;
        }

        var rows = leads.map(function (lead) {
            var tr = el('tr', 'is-clickable');
            tr.addEventListener('click', function () { openLeadDetail(lead.id); });

            tr.appendChild(el('td', 'num', '#' + lead.id));
            tr.appendChild(el('td', null, lead.full_name || '—'));
            tr.appendChild(el('td', 'muted', ((lead.country_code || '') + ' ' + (lead.phone || '')).trim() || '—'));
            tr.appendChild(el('td', 'muted', lead.email || '—'));

            var purposeCell = el('td', 'muted', (lead.answers_summary && lead.answers_summary.property_purpose) || '—');
            tr.appendChild(purposeCell);

            var budgetCell = el('td', 'muted', (lead.answers_summary && lead.answers_summary.budget) || '—');
            tr.appendChild(budgetCell);

            tr.appendChild(el('td', 'num', lead.lead_score));

            var statusCell = el('td');
            statusCell.appendChild(badge(lead.status, STATUS_VARIANTS[lead.status]));
            tr.appendChild(statusCell);

            var emailCell = el('td');
            emailCell.appendChild(badge(lead.email_status, EMAIL_VARIANTS[lead.email_status]));
            tr.appendChild(emailCell);

            tr.appendChild(el('td', 'muted', formatDate(lead.submitted_at)));

            return tr;
        });

        host.appendChild(table(
            ['ID', 'Name', 'Phone', 'Email', 'Purpose', 'Budget', 'Score', 'Status', 'Email', 'Submitted'],
            rows
        ));
    }

    function renderPagination(pagination) {
        var host = $('#leads-pagination');
        clear(host);

        var from = pagination.total === 0 ? 0 : ((pagination.page - 1) * pagination.per_page) + 1;
        var to = Math.min(pagination.page * pagination.per_page, pagination.total);

        host.appendChild(el('span', 'pagination__info',
            'Showing ' + from + '–' + to + ' of ' + pagination.total));

        var controls = el('div', 'pagination__controls');

        var prev = button('Previous', 'btn--ghost btn--sm', function () {
            if (leadState.page > 1) { leadState.page -= 1; loadLeads(); }
        });
        prev.disabled = pagination.page <= 1;

        var next = button('Next', 'btn--ghost btn--sm', function () {
            if (leadState.page < pagination.pages) { leadState.page += 1; loadLeads(); }
        });
        next.disabled = pagination.page >= pagination.pages;

        controls.appendChild(prev);
        controls.appendChild(next);
        host.appendChild(controls);
    }

    function exportCsv() {
        var params = new URLSearchParams();

        Object.keys(leadState.filters).forEach(function (key) {
            if (leadState.filters[key]) { params.set(key, leadState.filters[key]); }
        });

        params.set('token', Lumera.csrf);
        window.location.href = '/api/admin/export.php?' + params.toString();
        toast('Preparing your CSV export…', 'success');
    }

    /* ------------------------------------------------------ lead detail -- */
    function openLeadDetail(leadId) {
        api('/api/admin/lead-details.php?id=' + encodeURIComponent(leadId)).then(function (data) {
            renderLeadDetail(data);
        }).catch(function (error) { toast(error.message, 'error'); });
    }

    function definitionRow(list, label, value) {
        var row = el('div', 'definition-list__row');
        row.appendChild(el('span', 'definition-list__label', label));

        var valueNode = el('span', 'definition-list__value');
        if (typeof value === 'string' || typeof value === 'number') {
            valueNode.textContent = (value === '' || value === null || value === undefined) ? '—' : String(value);
        } else if (value) {
            valueNode.appendChild(value);
        } else {
            valueNode.textContent = '—';
        }

        row.appendChild(valueNode);
        list.appendChild(row);
    }

    function renderLeadDetail(data) {
        var lead = data.lead;
        var wrap = el('div');

        var tabs = el('div', 'tabs');
        var panels = el('div');

        var tabDefinitions = [
            { id: 'overview', label: 'Overview' },
            { id: 'answers', label: 'Answers' },
            { id: 'attribution', label: 'Attribution' },
            { id: 'notes', label: 'Notes & timeline' }
        ];

        tabDefinitions.forEach(function (definition, index) {
            var tab = el('button', 'tab' + (index === 0 ? ' is-active' : ''), definition.label);
            tab.type = 'button';
            tab.dataset.tab = definition.id;

            tab.addEventListener('click', function () {
                $$('.tab', tabs).forEach(function (t) { t.classList.toggle('is-active', t === tab); });
                $$('.tab-panel', panels).forEach(function (p) { p.hidden = p.dataset.panel !== definition.id; });
            });

            tabs.appendChild(tab);
        });

        wrap.appendChild(tabs);

        /* --- overview --- */
        var overview = el('div', 'tab-panel');
        overview.dataset.panel = 'overview';

        var list = el('div', 'definition-list');
        definitionRow(list, 'Lead ID', '#' + lead.id);
        definitionRow(list, 'Full name', lead.full_name);
        definitionRow(list, 'Phone', ((lead.country_code || '') + ' ' + (lead.phone || '')).trim());
        definitionRow(list, 'Normalised phone', lead.phone_normalized);
        definitionRow(list, 'Email', lead.email);
        definitionRow(list, 'Preferred language', lead.preferred_language);
        definitionRow(list, 'Interface language', lead.interface_language);
        definitionRow(list, 'Consent', lead.consent_given ? 'Given · ' + formatDate(lead.consent_at) : 'Not given');
        definitionRow(list, 'Lead score', lead.lead_score);
        definitionRow(list, 'Funnel version', 'v' + lead.funnel_version);
        definitionRow(list, 'Submitted', formatDate(lead.submitted_at));
        definitionRow(list, 'Status', badge(lead.status, STATUS_VARIANTS[lead.status]));

        var emailBadge = badge(lead.email_status, EMAIL_VARIANTS[lead.email_status]);
        definitionRow(list, 'Email notification', emailBadge);

        if (lead.email_error) { definitionRow(list, 'Email error', lead.email_error); }

        definitionRow(list, 'Device', lead.device_type);
        definitionRow(list, 'Screen', lead.screen_size);
        definitionRow(list, 'User agent', lead.user_agent);
        definitionRow(list, 'IP (hashed)', lead.ip_hash);

        if (lead.ip_address) { definitionRow(list, 'IP address', lead.ip_address); }

        overview.appendChild(list);

        var statusRow = el('div', 'form-row form-row--2');
        var statusSelect = select('lead-status', (data.statuses || []).map(function (s) {
            return { value: s, label: s };
        }), lead.status);

        statusRow.appendChild(formGroup('Change status', statusSelect));

        var actionsGroup = el('div', 'form-group');
        actionsGroup.appendChild(el('label', 'form-label', 'Actions'));
        var actionRow = el('div', 'filters__actions');

        actionRow.appendChild(button('Save status', 'btn--primary btn--sm', function () {
            api('/api/admin/lead-status.php', { method: 'POST', body: { lead_id: lead.id, status: statusSelect.value } })
                .then(function () {
                    toast('Lead status updated.', 'success');
                    modal.close();
                    loadLeads();
                })
                .catch(function (error) { toast(error.message, 'error'); });
        }));

        if (data.whatsapp_url) {
            var waLink = el('a', 'btn btn--ghost btn--sm', 'WhatsApp');
            waLink.href = data.whatsapp_url;
            waLink.target = '_blank';
            waLink.rel = 'noopener noreferrer';
            actionRow.appendChild(waLink);
        }

        actionRow.appendChild(button(lead.deleted_at ? 'Restore' : 'Archive', 'btn--danger btn--sm', function () {
            var isArchived = !!lead.deleted_at;

            confirmAction(
                isArchived ? 'Restore lead' : 'Archive lead',
                isArchived
                    ? 'This lead will be visible in the default list again.'
                    : 'The lead is kept in the database but hidden from the default list.',
                isArchived ? 'Restore' : 'Archive'
            ).then(function (confirmed) {
                if (!confirmed) { return; }

                api('/api/admin/lead-status.php', {
                    method: 'POST',
                    body: { lead_id: lead.id, action: isArchived ? 'restore' : 'archive' }
                }).then(function () {
                    toast(isArchived ? 'Lead restored.' : 'Lead archived.', 'success');
                    modal.close();
                    loadLeads();
                }).catch(function (error) { toast(error.message, 'error'); });
            });
        }));

        actionsGroup.appendChild(actionRow);
        statusRow.appendChild(actionsGroup);
        overview.appendChild(statusRow);
        panels.appendChild(overview);

        /* --- answers --- */
        var answersPanel = el('div', 'tab-panel');
        answersPanel.dataset.panel = 'answers';
        answersPanel.hidden = true;

        if (!data.answers || data.answers.length === 0) {
            answersPanel.appendChild(emptyState('No answers recorded', 'This lead has no stored funnel answers.'));
        } else {
            var answerList = el('div', 'definition-list');

            data.answers.forEach(function (answer) {
                definitionRow(answerList, answer.step_title || answer.step_key, answer.answer_label || answer.answer_value || '—');
            });

            answersPanel.appendChild(answerList);

            if (data.contact_detail && Object.keys(data.contact_detail).length > 0) {
                answersPanel.appendChild(el('p', 'form-help', 'Contact fields captured at submission time:'));
                var contactList = el('div', 'definition-list');

                Object.keys(data.contact_detail).forEach(function (key) {
                    definitionRow(contactList, key, data.contact_detail[key]);
                });

                answersPanel.appendChild(contactList);
            }
        }

        panels.appendChild(answersPanel);

        /* --- attribution --- */
        var attributionPanel = el('div', 'tab-panel');
        attributionPanel.dataset.panel = 'attribution';
        attributionPanel.hidden = true;

        var attributionList = el('div', 'definition-list');
        [
            ['UTM source', lead.utm_source], ['UTM medium', lead.utm_medium],
            ['UTM campaign', lead.utm_campaign], ['UTM content', lead.utm_content],
            ['UTM term', lead.utm_term], ['GCLID', lead.gclid], ['FBCLID', lead.fbclid],
            ['Referrer', lead.referrer], ['Landing page', lead.landing_page]
        ].forEach(function (pair) { definitionRow(attributionList, pair[0], pair[1]); });

        attributionPanel.appendChild(attributionList);
        panels.appendChild(attributionPanel);

        /* --- notes --- */
        var notesPanel = el('div', 'tab-panel');
        notesPanel.dataset.panel = 'notes';
        notesPanel.hidden = true;

        var noteInput = textarea('lead-note', '', 3);
        notesPanel.appendChild(formGroup('Internal note', noteInput));

        var noteList = el('div', 'note-list');

        function renderNotes(notes) {
            clear(noteList);

            (notes || []).forEach(function (note) {
                var card = el('div', 'note');
                card.appendChild(el('p', 'note__meta', (note.admin_email || 'system') + ' · ' + formatDate(note.created_at)));
                card.appendChild(el('p', 'note__body', note.note));
                noteList.appendChild(card);
            });
        }

        notesPanel.appendChild(button('Add note', 'btn--primary btn--sm', function () {
            var value = noteInput.value.trim();

            if (value === '') { toast('The note cannot be empty.', 'error'); return; }

            api('/api/admin/lead-notes.php', { method: 'POST', body: { lead_id: lead.id, note: value } })
                .then(function (response) {
                    noteInput.value = '';
                    renderNotes(response.notes);
                    toast('Note added.', 'success');
                })
                .catch(function (error) { toast(error.message, 'error'); });
        }));

        renderNotes(data.notes);
        notesPanel.appendChild(noteList);

        var timeline = el('ul', 'timeline');
        (data.timeline || []).forEach(function (entry) {
            var item = el('li');
            item.appendChild(el('span', 'timeline__dot'));

            var textWrap = el('div');
            textWrap.appendChild(el('div', null, entry.label));
            textWrap.appendChild(el('div', 'timeline__at', formatDate(entry.at)));
            item.appendChild(textWrap);

            timeline.appendChild(item);
        });

        notesPanel.appendChild(el('p', 'form-help', 'Submission timeline'));
        notesPanel.appendChild(timeline);
        panels.appendChild(notesPanel);

        wrap.appendChild(panels);

        modal.open('Lead #' + lead.id + ' — ' + (lead.full_name || 'Unnamed'), wrap,
            [button('Close', 'btn--ghost', function () { modal.close(); })], true);
    }

    /* =========================================================== settings */
    Lumera.views.settings = {
        title: 'Settings',
        subtitle: 'Safe application settings — secrets remain in .env',
        actions: function () { return []; },
        render: function () {
            return api('/api/admin/settings.php').then(function (data) {
                renderSettingsForm(data.settings);
                renderEnvironment(data.environment);
            });
        }
    };

    function renderSettingsForm(settings) {
        var host = $('#settings-form');
        clear(host);

        var company = input('set-company', settings.company_name || '');
        host.appendChild(formGroup('Company display name', company));

        var logoGroup = el('div', 'form-group');
        logoGroup.appendChild(el('label', 'form-label', 'Company logo'));

        var logoPath = input('set-logo', settings.company_logo || '', 'text', '/assets/uploads/…');
        logoPath.readOnly = true;
        logoGroup.appendChild(logoPath);

        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.className = 'form-control';
        fileInput.accept = '.png,.jpg,.jpeg,.webp';

        fileInput.addEventListener('change', function () {
            if (!fileInput.files || !fileInput.files[0]) { return; }

            var formData = new FormData();
            formData.append('file', fileInput.files[0]);
            formData.append('purpose', 'logo');

            api('/api/admin/upload.php', { method: 'POST', body: formData })
                .then(function (response) {
                    logoPath.value = response.path;
                    toast('Logo uploaded. Remember to save.', 'success');
                })
                .catch(function (error) { toast(error.message, 'error'); });
        });

        logoGroup.appendChild(fileInput);
        logoGroup.appendChild(el('p', 'form-help', 'PNG, JPG or WEBP up to 2 MB. SVG is not accepted.'));
        host.appendChild(logoGroup);

        var language = select('set-language', [
            { value: 'en', label: 'English' }, { value: 'ar', label: 'Arabic' }
        ], settings.admin_interface_language || 'en');
        host.appendChild(formGroup('Default interface language', language));

        var timezone = document.createElement('select');
        timezone.className = 'form-control';
        timezone.id = 'set-timezone';

        var template = $('#timezone-options');
        timezone.appendChild(template.content.cloneNode(true));
        timezone.value = settings.timezone || 'Asia/Dubai';
        host.appendChild(formGroup('Timezone', timezone));

        var privacy = input('set-privacy', settings.privacy_policy_url || '', 'url', 'https://…');
        host.appendChild(formGroup('Privacy policy URL', privacy));

        var subject = input('set-subject', settings.notification_subject_template || '');
        host.appendChild(formGroup('Notification subject template', subject,
            'Tokens: {lead_id} {full_name} {purpose} {budget} {score} {funnel}'));

        host.appendChild(button('Save settings', 'btn--primary', function () {
            api('/api/admin/settings.php', {
                method: 'POST',
                body: {
                    settings: {
                        company_name: company.value,
                        company_logo: logoPath.value,
                        admin_interface_language: language.value,
                        timezone: timezone.value,
                        privacy_policy_url: privacy.value,
                        notification_subject_template: subject.value
                    }
                }
            }).then(function () {
                toast('Settings saved.', 'success');
            }).catch(function (error) {
                if (error.data && error.data.errors) {
                    toast(Object.values(error.data.errors)[0], 'error');
                    return;
                }
                toast(error.message, 'error');
            });
        }));
    }

    function renderEnvironment(env) {
        var host = $('#settings-environment');
        clear(host);

        var note = el('p', 'alert alert--info',
            'These values are read from .env and cannot be edited here. SMTP credentials, the database password and APP_SECRET are never exposed to the dashboard.');
        host.appendChild(note);

        var list = el('div', 'definition-list');
        definitionRow(list, 'Environment', env.app_env);
        definitionRow(list, 'Application URL', env.app_url);
        definitionRow(list, 'Server timezone', env.timezone);
        definitionRow(list, 'SMTP configured', badge(env.smtp_configured ? 'yes' : 'no', env.smtp_configured ? 'success' : 'danger'));
        definitionRow(list, 'Lead recipient set', badge(env.lead_recipient_set ? 'yes' : 'no', env.lead_recipient_set ? 'success' : 'danger'));
        definitionRow(list, 'WhatsApp number set', badge(env.whatsapp_configured ? 'yes' : 'no', env.whatsapp_configured ? 'success' : 'muted'));
        definitionRow(list, 'Raw IP storage', badge(env.store_raw_ip ? 'enabled' : 'disabled', env.store_raw_ip ? 'warning' : 'success'));

        host.appendChild(list);
    }

    /* ============================================================= router */
    var ROUTES = ['dashboard', 'funnels', 'builder', 'leads', 'settings'];

    function currentRoute() {
        var hash = (window.location.hash || '').replace(/^#\/?/, '');
        var name = hash.split('/')[0];

        return ROUTES.indexOf(name) !== -1 ? name : 'dashboard';
    }

    function navigate() {
        var route = currentRoute();
        var view = Lumera.views[route];

        if (!view) { return; }

        $$('.nav-item').forEach(function (item) {
            item.classList.toggle('is-active', item.dataset.route === route);
        });

        $$('.view').forEach(function (section) {
            section.hidden = section.id !== 'view-' + route;
        });

        $('#page-title').textContent = view.title;
        $('#page-subtitle').textContent = view.subtitle;

        var actions = $('#page-actions');
        clear(actions);
        (view.actions() || []).forEach(function (node) { actions.appendChild(node); });

        $('#global-loading').hidden = false;

        Promise.resolve(view.render()).then(function () {
            $('#global-loading').hidden = true;
        }).catch(function (error) {
            $('#global-loading').hidden = true;
            if (error && error.message !== 'unauthenticated') { toast(error.message, 'error'); }
        });

        // Deep links: #/leads/123 opens a lead, #/builder/4 opens that funnel.
        var parts = (window.location.hash || '').replace(/^#\/?/, '').split('/');
        if (route === 'leads' && parts[1]) { openLeadDetail(parts[1]); }

        $('#layout').classList.remove('is-open');
        $('#sidebar-scrim').hidden = true;
    }

    /* ============================================================== chrome */
    function bindChrome() {
        $('#sidebar-toggle').addEventListener('click', function () {
            var layout = $('#layout');

            if (window.innerWidth <= 900) {
                var open = layout.classList.toggle('is-open');
                $('#sidebar-scrim').hidden = !open;
                return;
            }

            layout.classList.toggle('is-collapsed');
        });

        $('#sidebar-scrim').addEventListener('click', function () {
            $('#layout').classList.remove('is-open');
            $('#sidebar-scrim').hidden = true;
        });

        $('#logout-button').addEventListener('click', function () {
            api('/api/admin/logout.php', { method: 'POST', body: {} })
                .then(function (data) { window.location.href = data.redirect || '/admin/login.php'; })
                .catch(function () { window.location.href = '/admin/login.php'; });
        });

        $$('[data-modal-close]').forEach(function (node) {
            node.addEventListener('click', function () { modal.close(); });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !$('#modal').hidden) { modal.close(); }
        });

        window.addEventListener('hashchange', navigate);
    }

    Lumera.openLeadDetail = openLeadDetail;
    Lumera.navigate = navigate;

    /** Switches the builder to a funnel and routes there. */
    Lumera.openFunnelInBuilder = function (funnelId) {
        Lumera.funnelId = parseInt(funnelId, 10);

        if (currentRoute() === 'builder') {
            navigate();
            return;
        }

        window.location.hash = '#/builder';
    };

    bindChrome();

    // funnel-builder.js registers Lumera.views.builder on the same defer queue.
    window.setTimeout(navigate, 0);
})();
