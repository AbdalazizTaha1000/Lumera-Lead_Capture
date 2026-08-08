/* =========================================================================
 * Funnel Builder
 *
 * One screen, five sections, no Save buttons. Every edit is written back
 * through the existing admin APIs by the autosave engine below; the status
 * pill in the top bar is the only save affordance in the product.
 *
 * Publishing is reduced to a status: Draft, Live, Paused. Version numbers are
 * still kept by the backend but are never shown — "Publish updates" simply
 * means "make the current draft live".
 * ========================================================================= */
(function () {
    'use strict';

    var body = document.body;

    /* ============================================================== dom === */
    function $(sel, scope) { return (scope || document).querySelector(sel); }
    function $$(sel, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(sel)); }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = String(text); }
        return n;
    }

    function clear(node) { while (node && node.firstChild) { node.removeChild(node.firstChild); } }

    function frag() { return document.createDocumentFragment(); }

    /** Inline icon. Kept as markup-free SVG so nothing is fetched at runtime. */
    function icon(path, size) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 16 16');
        svg.setAttribute('width', size || 16);
        svg.setAttribute('height', size || 16);
        svg.setAttribute('fill', 'none');
        svg.setAttribute('aria-hidden', 'true');

        var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        p.setAttribute('d', path);
        p.setAttribute('stroke', 'currentColor');
        p.setAttribute('stroke-width', '1.5');
        p.setAttribute('stroke-linecap', 'round');
        p.setAttribute('stroke-linejoin', 'round');
        svg.appendChild(p);

        return svg;
    }

    var I = {
        general:  'M8 1.8 2.5 4.4v4c0 3.3 2.3 5.4 5.5 6.3 3.2-.9 5.5-3 5.5-6.3v-4L8 1.8Z',
        brand:    'M8 1.8 9.8 5.6l4.2.5-3 2.9.7 4.2L8 11.2l-3.7 2 .7-4.2-3-2.9 4.2-.5L8 1.8Z',
        steps:    'M2.5 4h11M2.5 8h11M2.5 12h7',
        plug:     'M5.5 2v4M10.5 2v4M4 6h8v2.5a4 4 0 0 1-8 0V6ZM8 12.5V15',
        gear:     'M8 10a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM13 8a5 5 0 0 0-.1-.9l1.3-1-1.5-2.6-1.6.6a5 5 0 0 0-1.5-.9L9.3 1.5H6.7l-.3 1.7a5 5 0 0 0-1.5.9l-1.6-.6L1.8 6.1l1.3 1A5 5 0 0 0 3 8c0 .3 0 .6.1.9l-1.3 1 1.5 2.6 1.6-.6c.4.4 1 .7 1.5.9l.3 1.7h2.6l.3-1.7a5 5 0 0 0 1.5-.9l1.6.6 1.5-2.6-1.3-1c.1-.3.1-.6.1-.9Z',
        chart:    'M2.5 13.5h11M4.5 11V7M8 11V3.5M11.5 11V8.5',
        grip:     'M6 3.5h.01M6 8h.01M6 12.5h.01M10 3.5h.01M10 8h.01M10 12.5h.01',
        chevron:  'M6 3.5 10.5 8 6 12.5',
        copy:     'M5.5 5.5V3.2c0-.4.3-.7.7-.7h6.6c.4 0 .7.3.7.7v6.6c0 .4-.3.7-.7.7h-2.3M3.2 5.5h6.6c.4 0 .7.3.7.7v6.6c0 .4-.3.7-.7.7H3.2a.7.7 0 0 1-.7-.7V6.2c0-.4.3-.7.7-.7Z',
        open:     'M9 2.5h4.5V7M13.5 2.5 7 9M11.5 9.5v3a1 1 0 0 1-1 1h-7a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1h3',
        qr:       'M2.5 2.5h4v4h-4v-4ZM9.5 2.5h4v4h-4v-4ZM2.5 9.5h4v4h-4v-4ZM9.5 9.5h1.5M13.5 9.5v1.5M11 12v1.5h2.5',
        share:    'M11.5 5.5a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM4.5 10a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM11.5 14.5a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM6.3 7 9.7 5.2M6.3 9l3.4 1.8',
        plus:     'M8 3.5v9M3.5 8h9',
        trash:    'M3 4.5h10M6.5 4.5V3h3v1.5M4.5 4.5l.6 8.2c0 .5.4.8.8.8h4.2c.4 0 .8-.3.8-.8l.6-8.2',
        dup:      'M5.5 5.5V3.2c0-.4.3-.7.7-.7h6.6c.4 0 .7.3.7.7v6.6c0 .4-.3.7-.7.7h-2.3M3.2 5.5h6.6c.4 0 .7.3.7.7v6.6c0 .4-.3.7-.7.7H3.2a.7.7 0 0 1-.7-.7V6.2c0-.4.3-.7.7-.7Z',
        eye:      'M1.5 8S3.9 3.5 8 3.5 14.5 8 14.5 8 12.1 12.5 8 12.5 1.5 8 1.5 8Z M8 9.8a1.8 1.8 0 1 0 0-3.6 1.8 1.8 0 0 0 0 3.6Z',
        eyeOff:   'M6.3 3.7A5.7 5.7 0 0 1 8 3.5c4.1 0 6.5 4.5 6.5 4.5a12 12 0 0 1-1.9 2.5M4.2 4.9A12 12 0 0 0 1.5 8S3.9 12.5 8 12.5c.9 0 1.7-.2 2.4-.5M2 2l12 12',
        check:    'M3.5 8.5 6.5 11.5 12.5 5',
        cloud:    'M5 12.5h6.2a2.8 2.8 0 0 0 .3-5.6A4 4 0 0 0 4 7.6 2.5 2.5 0 0 0 5 12.5Z',
        image:    'M2.5 3.5h11v9h-11v-9ZM2.5 10.5l3-3 2.5 2.5 2-2 3.5 3.5M6 6.5a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z',
        desktop:  'M2 3h12v7.5H2V3ZM6 13.5h4M8 10.5v3',
        tablet:   'M4 2h8v12H4V2ZM7.4 12h1.2',
        mobile:   'M5 1.8h6v12.4H5V1.8ZM7.2 12.5h1.6',
        mail:     'M2 4h12v8H2V4ZM2 4.5l6 4 6-4',
        bolt:     'M9 1.5 3.5 9H8l-1 5.5L12.5 7H8l1-5.5Z',
        info:     'M8 14.5A6.5 6.5 0 1 0 8 1.5a6.5 6.5 0 0 0 0 13ZM8 7.5v4M8 5h.01',
        warn:     'M8 6v3M8 11.5h.01M6.9 2.6 1.6 11.8c-.5.8.1 1.7 1 1.7h10.8c.9 0 1.5-.9 1-1.7L9.1 2.6a1.2 1.2 0 0 0-2.2 0Z',
        radio:    'M8 14.5A6.5 6.5 0 1 0 8 1.5a6.5 6.5 0 0 0 0 13ZM8 10.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z',
        checkbox: 'M3 2.5h10v11H3v-11ZM5.5 8l1.8 1.8L10.5 6.5',
        list:     'M4 4.5h8M4 8h8M4 11.5h5',
        text:     'M3 4h10M3 8h10M3 12h6',
        hash:     'M6 2.5 5 13.5M11 2.5l-1 11M2.5 6h11M2 10h11',
        phone:    'M5.6 2.5H3.4c-.6 0-1 .5-.9 1.1.6 5.2 3.7 8.3 8.9 8.9.6.1 1.1-.3 1.1-.9V9.4c0-.5-.3-.9-.8-1l-1.6-.3c-.4-.1-.9.1-1.1.5l-.4.7A8.9 8.9 0 0 1 6.2 6l.7-.4c.4-.2.6-.7.5-1.1l-.3-1.6c-.1-.5-.5-.8-1-.8Z',
        card:     'M2 4h12v8H2V4ZM2 6.5h12M4.5 9.5h3',
        shield:   'M8 1.8 2.5 4.4v4c0 3.3 2.3 5.4 5.5 6.3 3.2-.9 5.5-3 5.5-6.3v-4L8 1.8ZM5.8 8.2 7.3 9.7l3-3.2'
    };

    /* ============================================================ state === */
    var state = {
        csrf: body.dataset.csrf || '',
        funnelId: parseInt(body.dataset.funnelId || '0', 10),
        appUrl: (body.dataset.appUrl || '').replace(/\/$/, ''),
        funnel: null,
        steps: [],
        contactFields: [],
        meta: {},
        publish: {},
        settings: {},
        uploads: {},
        section: 'general',
        device: 'desktop',
        openStepId: null,
        stepTab: {},
        editLang: 'en',
        analytics: null,
        range: { id: '30', days: 30, from: null, to: null }
    };

    var TYPES = JSON.parse(($('#step-types') || {}).textContent || '{}');

    /* ============================================================== api === */
    function api(path, options) {
        options = options || {};

        var init = {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        };

        if (options.body !== undefined) {
            if (options.body instanceof FormData) {
                options.body.append('csrf_token', state.csrf);
                init.body = options.body;
            } else {
                init.headers['Content-Type'] = 'application/json';
                init.body = JSON.stringify(Object.assign({}, options.body, { csrf_token: state.csrf }));
            }
            init.headers['X-CSRF-Token'] = state.csrf;
        }

        return fetch(path, init).then(function (res) {
            if (res.status === 401) { window.location.href = '/admin/login.php'; return Promise.reject(new Error('auth')); }

            return res.json().catch(function () { return null; }).then(function (data) {
                if (!res.ok || !data || data.ok !== true) {
                    var err = new Error((data && (data.message || data.error)) || 'Request failed.');
                    err.data = data || {};
                    err.status = res.status;
                    return Promise.reject(err);
                }
                return data;
            });
        });
    }

    /* ========================================================== autosave === */
    /**
     * Coalescing autosave.
     *
     * Edits queue by target ("funnel", "step:12", …). Each target flushes at
     * most one in-flight request; anything typed meanwhile is folded into the
     * next flush. The pill never lies: it reads the queue, not a timer.
     */
    var save = {
        queue: {},
        timers: {},
        inflight: 0,
        failed: false,

        push: function (key, sender, delay) {
            var self = this;
            this.queue[key] = sender;
            this.failed = false;
            paint.saveState();

            clearTimeout(this.timers[key]);
            this.timers[key] = setTimeout(function () { self.flush(key); }, delay === undefined ? 650 : delay);
        },

        flush: function (key) {
            var self = this;
            var sender = this.queue[key];
            if (!sender) { return Promise.resolve(); }

            delete this.queue[key];
            this.inflight++;
            paint.saveState();

            return sender().then(function () {
                self.inflight--;
                paint.saveState();
                preview.schedule();
            }).catch(function (error) {
                self.inflight--;
                self.failed = true;
                paint.saveState();

                if (error && error.data && error.data.errors) {
                    var first = Object.keys(error.data.errors)[0];
                    toast(error.data.errors[first], 'error');
                } else if (error && error.message !== 'auth') {
                    toast(error.message || 'Could not save.', 'error');
                }
            });
        },

        /** Flushes everything now — used before publishing. */
        flushAll: function () {
            var self = this;
            var keys = Object.keys(this.queue);
            keys.forEach(function (k) { clearTimeout(self.timers[k]); });

            return Promise.all(keys.map(function (k) { return self.flush(k); }));
        },

        pending: function () { return Object.keys(this.queue).length > 0; },

        state: function () {
            if (this.failed) { return 'error'; }
            if (this.inflight > 0) { return 'saving'; }
            if (this.pending()) { return 'dirty'; }
            return 'saved';
        }
    };

    function patchFunnel(patch) {
        Object.assign(state.funnel, patch);

        save.push('funnel', function () {
            return api('/api/admin/funnel.php', {
                method: 'POST',
                body: Object.assign({ funnel_id: state.funnelId }, patch)
            }).then(function (data) {
                state.funnel = data.funnel;
                state.publish = data.status || state.publish;
                paint.topbar();
            });
        });
    }

    function patchSetting(patch) {
        Object.assign(state.settings, patch);

        save.push('settings', function () {
            return api('/api/admin/settings.php', { method: 'POST', body: { settings: patch } })
                .then(function (data) { state.settings = data.settings; });
        });
    }

    /** Steps must be sent whole: the validator rebuilds every column. */
    function stepPayload(step) {
        return {
            action: 'update',
            funnel_id: state.funnelId,
            step_id: step.id,
            step_key: step.step_key,
            step_type: step.step_type,
            title_en: step.title_en || '',
            title_ar: step.title_ar || '',
            description_en: step.description_en || '',
            description_ar: step.description_ar || '',
            placeholder_en: step.placeholder_en || '',
            placeholder_ar: step.placeholder_ar || '',
            validation_message_en: step.validation_message_en || '',
            validation_message_ar: step.validation_message_ar || '',
            image_path: step.image_path || '',
            is_required: num(step.is_required) === 1,
            is_active: num(step.is_active) === 1,
            auto_advance: num(step.auto_advance) === 1,
            min_length: blankNull(step.min_length),
            max_length: blankNull(step.max_length),
            min_value: blankNull(step.min_value),
            max_value: blankNull(step.max_value),
            validation_pattern: step.validation_pattern || '',
            condition_parent_key: step.condition_parent_key || '',
            condition_operator: step.condition_operator || '',
            condition_value: step.condition_value || ''
        };
    }

    function patchStep(step, patch) {
        Object.assign(step, patch);

        save.push('step:' + step.id, function () {
            return api('/api/admin/steps.php', { method: 'POST', body: stepPayload(step) })
                .then(function (data) { Object.assign(step, data.step); });
        });
    }

    function patchOption(step, option, patch) {
        Object.assign(option, patch);

        save.push('option:' + option.id, function () {
            return api('/api/admin/options.php', {
                method: 'POST',
                body: {
                    action: 'update',
                    funnel_id: state.funnelId,
                    option_id: option.id,
                    option_value: option.option_value,
                    label_en: option.label_en || '',
                    label_ar: option.label_ar || '',
                    icon: option.icon || '',
                    score: option.score || 0,
                    metadata: option.metadata || '',
                    is_active: num(option.is_active) === 1
                }
            }).then(function (data) { Object.assign(option, data.option); });
        });
    }

    function patchContactField(field, patch) {
        Object.assign(field, patch);

        save.push('cf:' + field.id, function () {
            return api('/api/admin/contact-fields.php', {
                method: 'POST',
                body: Object.assign({ action: 'update', funnel_id: state.funnelId, field_id: field.id }, patch)
            }).then(function (data) { Object.assign(field, data.field); });
        });
    }

    /* ============================================================ utils === */
    function num(v) { return parseInt(v, 10) || 0; }
    function blankNull(v) { return (v === null || v === undefined || v === '') ? '' : v; }
    function bool(v) { return num(v) === 1; }

    function slugify(v) {
        return String(v || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120);
    }

    function keyify(v) {
        return String(v || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 64);
    }

    function publicUrl() {
        return (state.appUrl || window.location.origin) + '/' + (state.funnel ? state.funnel.slug : '');
    }

    function langs() {
        var raw = (state.funnel && state.funnel.enabled_languages) || 'en';
        return raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    }

    function suffix() { return state.editLang === 'ar' ? '_ar' : '_en'; }

    /* ============================================================ toast === */
    function toast(message, kind) {
        var host = $('#toasts');
        var node = el('div', 'toast' + (kind ? ' toast--' + kind : ''));

        if (kind === 'ok') { node.appendChild(icon(I.check, 15)); }
        if (kind === 'error') { node.appendChild(icon(I.warn, 15)); }
        node.appendChild(el('span', null, message));

        host.appendChild(node);
        setTimeout(function () {
            node.style.opacity = '0';
            node.style.transform = 'translateY(6px)';
            setTimeout(function () { if (node.parentNode) { node.parentNode.removeChild(node); } }, 220);
        }, 3200);
    }

    /* ============================================================ modal === */
    var modal = {
        open: function (opts) {
            $('#modal-title').textContent = opts.title || '';
            $('#modal-sub').textContent = opts.sub || '';
            $('#modal-sub').hidden = !opts.sub;

            var bodyHost = $('#modal-body');
            clear(bodyHost);
            if (opts.body) { bodyHost.appendChild(opts.body); }

            var footHost = $('#modal-foot');
            clear(footHost);
            (opts.actions || []).forEach(function (a) { footHost.appendChild(a); });
            footHost.hidden = !(opts.actions && opts.actions.length);

            $('#modal').classList.toggle('modal--wide', opts.wide === true);
            $('#scrim').hidden = false;
        },
        close: function () { $('#scrim').hidden = true; }
    };

    function confirmDialog(opts) {
        return new Promise(function (resolve) {
            var wrap = el('div');
            if (opts.message) { wrap.appendChild(el('p', null, opts.message)); }
            if (opts.extra) { wrap.appendChild(opts.extra); }

            var cancel = button(opts.cancelLabel || 'Cancel', 'btn--ghost', function () { modal.close(); resolve(false); });
            var ok = button(opts.confirmLabel || 'Confirm', opts.danger ? 'btn--danger' : 'btn--publish', function () {
                modal.close();
                resolve(true);
            });

            modal.open({ title: opts.title, sub: opts.sub, body: wrap, actions: [cancel, ok] });
        });
    }

    /* ======================================================== primitives === */
    function button(label, cls, onClick, iconPath) {
        var b = el('button', 'btn ' + (cls || 'btn--ghost'));
        b.type = 'button';
        if (iconPath) { var i = icon(iconPath, 15); i.classList.add('btn__icon'); b.appendChild(i); }
        if (label) { b.appendChild(el('span', null, label)); }
        if (onClick) { b.addEventListener('click', onClick); }
        return b;
    }

    function iconButton(iconPath, title, cls, onClick) {
        var b = el('button', 'btn btn--sm btn--icon ' + (cls || 'btn--quiet'));
        b.type = 'button';
        b.title = title;
        b.setAttribute('aria-label', title);
        b.appendChild(icon(iconPath, 15));
        b.addEventListener('click', onClick);
        return b;
    }

    function field(label, control, hint) {
        var f = el('div', 'field');
        if (label) {
            var l = el('label', 'field__label', label);
            if (control.id) { l.htmlFor = control.id; }
            f.appendChild(l);
        }
        f.appendChild(control);
        if (hint) { f.appendChild(el('p', 'field__hint', hint)); }
        return f;
    }

    var uid = 0;
    function nextId() { uid++; return 'f' + uid; }

    function input(value, opts) {
        opts = opts || {};
        var n = document.createElement(opts.multiline ? 'textarea' : 'input');
        n.className = opts.multiline ? 'textarea' : 'input';
        n.id = nextId();
        if (!opts.multiline) { n.type = opts.type || 'text'; }
        if (opts.rows) { n.rows = opts.rows; }
        if (opts.placeholder) { n.placeholder = opts.placeholder; }
        if (opts.mono) { n.classList.add('input--mono'); }
        n.value = value === null || value === undefined ? '' : value;

        if (opts.onInput) {
            n.addEventListener('input', function () { opts.onInput(n.value, n); });
        }
        if (opts.onChange) {
            n.addEventListener('change', function () { opts.onChange(n.value, n); });
        }
        return n;
    }

    function select(options, value, onChange) {
        var n = el('select', 'select');
        n.id = nextId();
        options.forEach(function (o) {
            var opt = document.createElement('option');
            opt.value = o.value;
            opt.textContent = o.label;
            if (String(o.value) === String(value)) { opt.selected = true; }
            n.appendChild(opt);
        });
        n.addEventListener('change', function () { onChange(n.value); });
        return n;
    }

    function toggleRow(label, hint, on, onChange) {
        var row = el('div', 'toggle-row');
        var text = el('div', 'toggle-row__text');
        text.appendChild(el('div', 'toggle-row__label', label));
        if (hint) { text.appendChild(el('div', 'toggle-row__hint', hint)); }
        row.appendChild(text);

        var t = el('button', 'toggle' + (on ? ' is-on' : ''));
        t.type = 'button';
        t.setAttribute('role', 'switch');
        t.setAttribute('aria-checked', on ? 'true' : 'false');
        t.setAttribute('aria-label', label);
        t.addEventListener('click', function () {
            var next = !t.classList.contains('is-on');
            t.classList.toggle('is-on', next);
            t.setAttribute('aria-checked', next ? 'true' : 'false');
            onChange(next);
        });

        row.appendChild(t);
        return row;
    }

    function card(title, sub, bodyNode, headExtra, cls) {
        var c = el('div', 'card' + (cls ? ' ' + cls : ''));
        if (title) {
            var head = el('div', 'card__head');
            var t = el('div');
            t.appendChild(el('div', 'card__title', title));
            if (sub) { t.appendChild(el('div', 'card__sub', sub)); }
            head.appendChild(t);
            if (headExtra) {
                var spacer = el('div');
                spacer.style.flex = '1';
                head.appendChild(spacer);
                head.appendChild(headExtra);
            }
            c.appendChild(head);
        }
        var b = el('div', 'card__body');
        b.appendChild(bodyNode);
        c.appendChild(b);
        return c;
    }

    function sectionHead(title, sub) {
        var h = el('div', 'section-head');
        h.appendChild(el('h1', 'section-head__title', title));
        if (sub) { h.appendChild(el('p', 'section-head__sub', sub)); }
        return h;
    }

    function note(text, kind, iconPath) {
        var n = el('div', 'note' + (kind ? ' note--' + kind : ''));
        var i = icon(iconPath || I.info, 15);
        i.classList.add('note__icon');
        n.appendChild(i);
        n.appendChild(el('span', null, text));
        return n;
    }

    /* ====================================================== media picker === */
    /**
     * Upload control shared by every image in the builder. It posts to the
     * existing hardened upload endpoint — there is no second upload path.
     */
    function mediaField(opts) {
        var wrap = el('div', 'media' + (opts.value ? ' has-file' : ''));

        var preview;
        if (opts.value) {
            preview = el('img', 'media__preview');
            preview.src = opts.value;
            preview.alt = '';
            preview.loading = 'lazy';
        } else {
            preview = el('div', 'media__empty');
            preview.appendChild(icon(opts.icon || I.image, 20));
        }
        wrap.appendChild(preview);

        var text = el('div', 'media__text');
        var name = el('div', 'media__name', opts.value ? opts.value.split('/').pop() : (opts.emptyLabel || 'No image'));
        text.appendChild(name);
        text.appendChild(el('div', 'media__hint', opts.hint || ''));
        wrap.appendChild(text);

        var file = document.createElement('input');
        file.type = 'file';
        var formats = opts.formats || ['png', 'jpg', 'jpeg', 'webp'];
        file.accept = formats.map(function (f) { return '.' + f; }).join(',');

        file.addEventListener('change', function () {
            if (!file.files || !file.files[0]) { return; }

            var fd = new FormData();
            fd.append('file', file.files[0]);
            fd.append('purpose', opts.purpose);

            name.textContent = 'Uploading…';

            api('/api/admin/upload.php', { method: 'POST', body: fd })
                .then(function (res) { opts.onChange(res.path); render(); })
                .catch(function (err) {
                    file.value = '';
                    name.textContent = opts.value ? opts.value.split('/').pop() : (opts.emptyLabel || 'No image');
                    var msg = (err.data && err.data.errors && err.data.errors.file) || err.message;
                    toast(msg, 'error');
                });
        });

        var actions = el('div', 'media__actions');
        actions.appendChild(button(opts.value ? 'Replace' : 'Upload', 'btn--ghost btn--sm', function () { file.click(); }, I.cloud));
        if (opts.value) {
            actions.appendChild(iconButton(I.trash, 'Remove', 'btn--quiet', function () {
                opts.onChange('');
                render();
            }));
        }

        wrap.appendChild(actions);
        wrap.appendChild(file);

        return wrap;
    }

    function colorField(label, value, onChange) {
        var row = el('div', 'swatch-row');

        var swatch = document.createElement('input');
        swatch.type = 'color';
        swatch.className = 'swatch';
        swatch.value = (value || '#000000').slice(0, 7);

        var hex = input(value, { mono: true, onInput: function (v) {
            if (/^#[0-9a-fA-F]{6}$/.test(v)) { swatch.value = v; onChange(v); }
        } });

        swatch.addEventListener('input', function () { hex.value = swatch.value; onChange(swatch.value); });

        row.appendChild(swatch);
        row.appendChild(hex);

        return field(label, row);
    }

    /* ============================================================ paint === */
    var paint = {};

    paint.saveState = function () {
        var host = $('#save-state');
        var st = save.state();
        host.dataset.state = st;
        clear(host);

        var map = {
            saved: { icon: I.check, label: 'Saved' },
            saving: { icon: I.cloud, label: 'Saving…' },
            dirty: { icon: I.cloud, label: 'Unsaved changes' },
            error: { icon: I.warn, label: 'Not saved' }
        };

        var conf = map[st];
        var i = icon(conf.icon, 13);
        i.classList.add('save-state__icon');
        host.appendChild(i);
        host.appendChild(el('span', null, conf.label));
    };

    /** Draft / Live / Paused, derived from status + whether anything is published. */
    function liveState() {
        var f = state.funnel;
        if (!f) { return 'draft'; }
        if (f.archived_at) { return 'paused'; }
        if (f.status === 'paused') { return 'paused'; }
        if (f.status === 'active' && num(f.published_version) > 0) { return 'live'; }
        return 'draft';
    }

    paint.topbar = function () {
        var f = state.funnel;

        // brand mark
        var slot = $('#brand-mark');
        clear(slot);
        if (f.logo_path) {
            var img = el('img', 'topbar__logo');
            img.src = f.logo_path;
            img.alt = '';
            slot.appendChild(img);
        } else {
            slot.appendChild(el('span', 'topbar__mark', (f.company_name || f.name || '?').charAt(0).toUpperCase()));
        }

        // status pill
        var st = liveState();
        var pillHost = $('#status-pill');
        clear(pillHost);
        var pill = el('span', 'pill pill--' + st);
        pill.appendChild(el('span', 'pill__dot'));
        pill.appendChild(el('span', null, st === 'live' ? 'Live' : (st === 'paused' ? 'Paused' : 'Draft')));
        pillHost.appendChild(pill);

        // url bar
        var bar = $('#urlbar');
        clear(bar);
        var url = publicUrl();
        bar.appendChild(el('span', 'urlbar__text', url.replace(/^https?:\/\//, '')));
        var acts = el('div', 'urlbar__actions');
        acts.appendChild(iconButton(I.copy, 'Copy link', 'btn--quiet', function () { copy(url); }));
        acts.appendChild(iconButton(I.open, 'Open funnel', 'btn--quiet', function () { window.open(url, '_blank', 'noopener'); }));
        acts.appendChild(iconButton(I.qr, 'QR code', 'btn--quiet', showQr));
        acts.appendChild(iconButton(I.share, 'Share & embed', 'btn--quiet', showShare));
        bar.appendChild(acts);

        // publish action
        var slot2 = $('#publish-slot');
        clear(slot2);

        if (st === 'draft') {
            slot2.appendChild(button('Publish', 'btn--publish', publishFlow, I.bolt));
        } else if (state.publish.has_unpublished) {
            slot2.appendChild(button('Publish updates', 'btn--publish', publishFlow, I.bolt));
        } else {
            var done = button('All changes live', 'btn--quiet', null, I.check);
            done.disabled = true;
            slot2.appendChild(done);
        }
    };

    function copy(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { toast('Link copied', 'ok'); },
                function () { toast('Could not copy', 'error'); });
            return;
        }
        var tmp = document.createElement('textarea');
        tmp.value = text;
        document.body.appendChild(tmp);
        tmp.select();
        try { document.execCommand('copy'); toast('Link copied', 'ok'); } catch (e) { toast('Could not copy', 'error'); }
        document.body.removeChild(tmp);
    }

    /* ========================================================== publish === */
    function publishFlow() {
        var first = liveState() === 'draft';

        confirmDialog({
            title: first ? 'Publish funnel?' : 'Publish updates?',
            sub: first
                ? 'Your funnel becomes reachable at its public link straight away.'
                : 'Visitors will see your latest changes immediately.',
            extra: (function () {
                var box = el('div');
                box.style.marginTop = '14px';
                box.appendChild(note(publicUrl(), 'info', I.open));
                return box;
            })(),
            confirmLabel: first ? 'Publish' : 'Publish updates'
        }).then(function (ok) {
            if (!ok) { return; }

            // Anything still queued must land before the snapshot is taken.
            save.flushAll().then(function () {
                var chain = first
                    ? api('/api/admin/funnel.php', { method: 'POST', body: { funnel_id: state.funnelId, status: 'active' } })
                    : Promise.resolve();

                return chain.then(function () {
                    return api('/api/admin/publish.php', { method: 'POST', body: { funnel_id: state.funnelId } });
                });
            }).then(function () {
                toast(first ? 'Your funnel is live' : 'Updates published', 'ok');
                return load();
            }).catch(function (err) {
                var blockers = err.data && err.data.blockers;
                if (blockers && blockers.length) {
                    var list = el('div');
                    blockers.forEach(function (b) { list.appendChild(note(b, 'warn', I.warn)); list.lastChild.style.marginBottom = '8px'; });
                    modal.open({
                        title: 'Almost there',
                        sub: 'Fix these before publishing:',
                        body: list,
                        actions: [button('Got it', 'btn--primary', function () { modal.close(); })]
                    });
                    return;
                }
                toast(err.message || 'Could not publish.', 'error');
            });
        });
    }

    function setLiveState(next) {
        var current = liveState();
        if (next === current) { return; }

        if (next === 'live') {
            publishFlow();
            return;
        }

        patchFunnel({ status: next === 'paused' ? 'paused' : 'draft' });
        toast(next === 'paused' ? 'Funnel paused' : 'Moved back to draft', 'ok');
        setTimeout(function () { paint.topbar(); render(); }, 60);
    }

    /* ============================================================= rail === */
    var SECTIONS = [
        { id: 'general', label: 'General', icon: I.general },
        { id: 'branding', label: 'Branding', icon: I.brand },
        { id: 'steps', label: 'Steps', icon: I.steps },
        { id: 'integrations', label: 'Integrations', icon: I.plug },
        { id: 'settings', label: 'Settings', icon: I.gear }
    ];

    paint.rail = function () {
        var rail = $('#rail');
        clear(rail);

        SECTIONS.forEach(function (s) {
            var b = el('button', 'rail__item' + (state.section === s.id ? ' is-active' : ''));
            b.type = 'button';
            var i = icon(s.icon, 17);
            i.classList.add('rail__icon');
            b.appendChild(i);
            b.appendChild(el('span', null, s.label));

            if (s.id === 'steps') {
                b.appendChild(el('span', 'rail__count', state.steps.length));
            }

            b.addEventListener('click', function () { go(s.id); });
            rail.appendChild(b);
        });

        var foot = el('div', 'rail__foot');
        var a = el('button', 'rail__item' + (state.section === 'analytics' ? ' is-active' : ''));
        a.type = 'button';
        var ai = icon(I.chart, 17);
        ai.classList.add('rail__icon');
        a.appendChild(ai);
        a.appendChild(el('span', null, 'Analytics'));
        a.addEventListener('click', function () { go('analytics'); });
        foot.appendChild(a);
        rail.appendChild(foot);
    };

    function go(section) {
        state.section = section;
        window.location.hash = section;
        paint.rail();
        render();
        $('#canvas').scrollTop = 0;
    }

    /* ========================================================== sections === */
    function render() {
        var host = $('#canvas-inner');
        clear(host);

        $('#canvas').classList.toggle('canvas--wide', state.section === 'analytics');
        $('#preview').classList.toggle('is-collapsed', state.section === 'analytics');

        var map = {
            general: renderGeneral,
            branding: renderBranding,
            steps: renderSteps,
            integrations: renderIntegrations,
            settings: renderSettings,
            analytics: renderAnalytics
        };

        (map[state.section] || renderGeneral)(host);
    }

    /* ---------------------------------------------------------- general -- */
    function renderGeneral(host) {
        var f = state.funnel;

        host.appendChild(sectionHead('General', 'Name your funnel, choose its web address and control whether it is live.'));

        // --- status switch ---
        var current = liveState();
        var sw = el('div', 'switch-group');

        [
            { v: 'draft', label: 'Draft', sub: 'Only you can see it' },
            { v: 'active', label: 'Live', sub: 'Open to the public' },
            { v: 'paused', label: 'Paused', sub: 'Temporarily closed' }
        ].forEach(function (o) {
            var on = (o.v === 'active' && current === 'live') || (o.v === current);
            var b = el('button', 'switch-opt' + (on ? ' is-on' : ''));
            b.type = 'button';
            b.dataset.value = o.v;
            b.appendChild(el('span', null, o.label));
            b.appendChild(el('span', 'switch-opt__sub', o.sub));
            b.addEventListener('click', function () { setLiveState(o.v === 'active' ? 'live' : o.v); });
            sw.appendChild(b);
        });

        host.appendChild(card('Status', 'Switch to Live when you are ready for visitors.', sw));

        // --- identity ---
        var box = el('div');

        box.appendChild(field('Funnel name',
            input(f.name, { onInput: function (v) { $('#funnel-title').value = v; patchFunnel({ name: v }); } }),
            'Internal name. Visitors never see this.'));

        var affix = el('div', 'affix');
        affix.appendChild(el('span', 'affix__prefix', (state.appUrl || window.location.origin).replace(/^https?:\/\//, '') + '/'));
        var slugInput = input(f.slug, { mono: true, onInput: function (v) {
            var clean = slugify(v);
            if (clean !== v) { slugInput.value = clean; }
            patchFunnel({ slug: clean });
            paint.topbar();
        } });
        affix.appendChild(slugInput);
        box.appendChild(field('Public address', affix, 'Changing this changes your public link.'));

        box.appendChild(field('Company name',
            input(f.company_name, { onInput: function (v) { patchFunnel({ company_name: v }); } }),
            'Shown on the funnel, the browser tab and lead notifications.'));

        var langRow = el('div', 'grid-2');

        langRow.appendChild(field('Default language',
            select([{ value: 'en', label: 'English' }, { value: 'ar', label: 'Arabic' }], f.default_language,
                function (v) { patchFunnel({ default_language: v }); })));

        langRow.appendChild(field('Available languages',
            select([
                { value: 'en', label: 'English only' },
                { value: 'ar', label: 'Arabic only' },
                { value: 'en,ar', label: 'English + Arabic' }
            ], f.enabled_languages, function (v) {
                patchFunnel({ enabled_languages: v });
                setTimeout(render, 80);
            })));

        box.appendChild(langRow);

        host.appendChild(card('Details', null, box));
    }

    /* --------------------------------------------------------- branding -- */
    function renderBranding(host) {
        var f = state.funnel;

        host.appendChild(sectionHead('Branding', 'Everything visitors see. Changes appear in the preview instantly.'));

        var logos = el('div');
        logos.appendChild(field('Company logo', mediaField({
            value: f.logo_path, purpose: 'logo',
            formats: state.uploads.logo_formats || ['png', 'svg', 'webp', 'jpg', 'jpeg'],
            hint: 'Shown at the top of your funnel.',
            emptyLabel: 'No logo yet',
            onChange: function (p) { patchFunnel({ logo_path: p }); }
        })));

        logos.appendChild(field('Favicon', mediaField({
            value: f.favicon_path, purpose: 'favicon',
            formats: state.uploads.favicon_formats || ['png', 'svg', 'webp', 'ico'],
            hint: 'The small icon in the browser tab.',
            emptyLabel: 'Using the default',
            onChange: function (p) { patchFunnel({ favicon_path: p }); }
        })));

        logos.appendChild(field('Background image', mediaField({
            value: f.background_image_path, purpose: 'background',
            formats: state.uploads.background_formats || ['png', 'webp', 'jpg', 'jpeg'],
            hint: 'Optional. Sits behind the form.',
            emptyLabel: 'No background image',
            onChange: function (p) { patchFunnel({ background_image_path: p }); }
        })));

        host.appendChild(card('Visuals', null, logos));

        var colors = el('div', 'grid-3');
        colors.appendChild(colorField('Primary', f.primary_color, function (v) { patchFunnel({ primary_color: v }); }));
        colors.appendChild(colorField('Secondary', f.accent_color, function (v) { patchFunnel({ accent_color: v }); }));
        colors.appendChild(colorField('Background', f.background_color, function (v) { patchFunnel({ background_color: v }); }));
        host.appendChild(card('Colours', 'Used across buttons, progress and highlights.', colors));

        var tag = el('div');
        tag.appendChild(field('Tagline',
            input(state.settings.site_tagline || '', {
                placeholder: 'Find the right property in Dubai',
                onInput: function (v) { patchSetting({ site_tagline: v }); }
            }),
            'Used for the browser title and the page description across all funnels.'));
        host.appendChild(card('Tagline', null, tag));
    }

    /* ------------------------------------------------------------ steps -- */
    function renderSteps(host) {
        var head = sectionHead('Steps', 'The questions visitors answer, in order. Drag to rearrange.');
        host.appendChild(head);

        var add = button('Add step', 'btn--primary', showTypePicker, I.plus);
        add.style.marginBottom = '18px';
        host.appendChild(add);

        if (state.steps.length === 0) {
            var empty = el('div', 'empty');
            var ei = icon(I.steps, 26);
            ei.classList.add('empty__icon');
            empty.appendChild(ei);
            empty.appendChild(el('p', 'empty__title', 'No steps yet'));
            empty.appendChild(el('p', 'empty__text', 'Add your first question and it will appear in the preview straight away.'));
            empty.appendChild(button('Add your first step', 'btn--primary', showTypePicker, I.plus));
            host.appendChild(empty);
            return;
        }

        var list = el('div', 'steps');
        list.id = 'step-list';
        state.steps.forEach(function (step, index) { list.appendChild(stepCard(step, index)); });
        host.appendChild(list);

        makeSortable(list, '.step-card', function (ids) {
            api('/api/admin/steps.php', { method: 'POST', body: { action: 'reorder', funnel_id: state.funnelId, order: ids } })
                .then(function () { return load(true); })
                .then(function () { preview.schedule(); })
                .catch(function (e) { toast(e.message, 'error'); load(true); });
        });

        // Contact fields live with the step that uses them.
        if (state.steps.some(function (s) { return s.step_type === 'contact_information'; })) {
            host.appendChild(contactFieldsCard());
        }
    }

    function typeLabel(t) { return TYPES[t] || t; }

    function typeIcon(t) {
        return {
            single_select: I.radio, multi_select: I.checkbox, dropdown: I.list,
            short_text: I.text, email: I.mail, phone: I.phone, number: I.hash,
            contact_information: I.card, consent: I.shield, information: I.info
        }[t] || I.text;
    }

    function stepCard(step, index) {
        var open = state.openStepId === step.id;
        var c = el('div', 'step-card' + (open ? ' is-open' : '') + (bool(step.is_active) ? '' : ' is-inactive'));
        c.dataset.id = step.id;
        c.draggable = true;

        /* ---- head ---- */
        var head = el('div', 'step-card__head');

        var grip = el('span', 'step-card__grip');
        grip.appendChild(icon(I.grip, 15));
        grip.title = 'Drag to reorder';
        head.appendChild(grip);

        head.appendChild(el('span', 'step-card__index', index + 1));

        var main = el('div', 'step-card__main');
        var title = step['title' + suffix()] || step.title_en || '';
        var q = el('div', 'step-card__q' + (title ? '' : ' is-empty'), title || 'Untitled question');
        main.appendChild(q);

        var meta = el('div', 'step-card__meta');
        meta.appendChild(el('span', 'chip', typeLabel(step.step_type)));
        if (bool(step.is_required)) { meta.appendChild(el('span', 'chip chip--req', 'Required')); }
        if (!bool(step.is_active)) { meta.appendChild(el('span', 'chip chip--off', 'Hidden')); }
        if (step.condition_parent_key) { meta.appendChild(el('span', 'chip chip--logic', 'Conditional')); }
        if (step.image_path) { meta.appendChild(el('span', 'chip chip--img', 'Image')); }
        if (step.uses_options) { meta.appendChild(el('span', 'chip', (step.options || []).length + ' options')); }
        main.appendChild(meta);
        head.appendChild(main);

        var tools = el('div', 'step-card__tools');
        tools.appendChild(iconButton(bool(step.is_active) ? I.eye : I.eyeOff,
            bool(step.is_active) ? 'Hide from funnel' : 'Show in funnel', 'btn--quiet', function (ev) {
                ev.stopPropagation();
                toggleStep(step);
            }));
        tools.appendChild(iconButton(I.dup, 'Duplicate', 'btn--quiet', function (ev) { ev.stopPropagation(); duplicateStep(step); }));
        tools.appendChild(iconButton(I.trash, 'Delete', 'btn--quiet', function (ev) { ev.stopPropagation(); deleteStep(step); }));
        head.appendChild(tools);

        var chev = el('span', 'step-card__chev');
        chev.appendChild(icon(I.chevron, 15));
        head.appendChild(chev);

        head.addEventListener('click', function (ev) {
            if (ev.target.closest('.step-card__tools') || ev.target.closest('.step-card__grip')) { return; }
            state.openStepId = open ? null : step.id;
            render();
        });

        c.appendChild(head);

        /* ---- body ---- */
        var bodyWrap = el('div', 'step-card__body');
        var inner = el('div', 'step-card__bodyInner');
        var pad = el('div', 'step-card__pad');

        if (open) { pad.appendChild(stepEditor(step)); }

        inner.appendChild(pad);
        bodyWrap.appendChild(inner);
        c.appendChild(bodyWrap);

        return c;
    }

    function stepEditor(step) {
        var wrap = el('div');
        var tabs = ['Content'];
        if (step.uses_options) { tabs.push('Options'); }
        tabs.push('Logic', 'Settings');

        var activeTab = state.stepTab[step.id] || 'Content';
        if (tabs.indexOf(activeTab) === -1) { activeTab = 'Content'; }

        var bar = el('div', 'subtabs');
        tabs.forEach(function (t) {
            var b = el('button', 'subtab' + (t === activeTab ? ' is-on' : ''), t);
            b.type = 'button';
            b.addEventListener('click', function () { state.stepTab[step.id] = t; render(); });
            bar.appendChild(b);
        });
        wrap.appendChild(bar);

        if (activeTab === 'Content') { wrap.appendChild(stepContent(step)); }
        if (activeTab === 'Options') { wrap.appendChild(optionsEditor(step)); }
        if (activeTab === 'Logic') { wrap.appendChild(logicEditor(step)); }
        if (activeTab === 'Settings') { wrap.appendChild(stepSettings(step)); }

        return wrap;
    }

    function langSwitch() {
        var available = langs();
        if (available.length < 2) { return null; }

        var g = el('div', 'subtabs');
        g.style.maxWidth = '210px';
        g.style.marginBottom = '16px';

        available.forEach(function (l) {
            var b = el('button', 'subtab' + (state.editLang === l ? ' is-on' : ''), l === 'ar' ? 'العربية' : 'English');
            b.type = 'button';
            b.addEventListener('click', function () { state.editLang = l; render(); });
            g.appendChild(b);
        });

        return g;
    }

    function stepContent(step) {
        var w = el('div');
        var sw = langSwitch();
        if (sw) { w.appendChild(sw); }

        var sfx = suffix();

        w.appendChild(field('Question',
            input(step['title' + sfx], {
                placeholder: 'What would you like to ask?',
                onInput: function (v) {
                    var patch = {}; patch['title' + sfx] = v;
                    patchStep(step, patch);
                    var q = $('.step-card[data-id="' + step.id + '"] .step-card__q');
                    if (q) { q.textContent = v || 'Untitled question'; q.classList.toggle('is-empty', !v); }
                }
            })));

        w.appendChild(field('Description',
            input(step['description' + sfx], {
                multiline: true, rows: 2, placeholder: 'Optional helper text shown under the question.',
                onInput: function (v) { var p = {}; p['description' + sfx] = v; patchStep(step, p); }
            })));

        if (['short_text', 'email', 'phone', 'number', 'dropdown'].indexOf(step.step_type) !== -1) {
            w.appendChild(field('Placeholder',
                input(step['placeholder' + sfx], {
                    placeholder: 'Shown inside the empty field',
                    onInput: function (v) { var p = {}; p['placeholder' + sfx] = v; patchStep(step, p); }
                })));
        }

        w.appendChild(field('Image', mediaField({
            value: step.image_path, purpose: 'step',
            formats: state.uploads.step_formats || ['jpg', 'jpeg', 'png', 'webp'],
            hint: 'Optional. Shown above the question on mobile, beside it on desktop.',
            emptyLabel: 'No image',
            onChange: function (p) { patchStep(step, { image_path: p }); }
        })));

        return w;
    }

    function optionsEditor(step) {
        var w = el('div');
        var options = step.options || [];

        if (options.length === 0) {
            w.appendChild(note('Add at least one choice — a selection step cannot go live without one.', 'warn', I.warn));
        }

        var list = el('div', 'opt-list');
        list.id = 'opts-' + step.id;

        options.forEach(function (o) { list.appendChild(optionRow(step, o)); });
        w.appendChild(list);

        makeSortable(list, '.opt', function (ids) {
            api('/api/admin/options.php', {
                method: 'POST',
                body: { action: 'reorder', funnel_id: state.funnelId, step_id: step.id, order: ids }
            }).then(function () { return load(true); })
              .then(function () { preview.schedule(); })
              .catch(function (e) { toast(e.message, 'error'); load(true); });
        });

        var add = button('Add choice', 'btn--ghost btn--sm', function () { addOption(step); }, I.plus);
        add.style.marginTop = '10px';
        w.appendChild(add);

        var hint = el('p', 'field__hint', 'Score is optional and never shown to visitors — it is added to the lead score for prioritising follow-up.');
        hint.style.marginTop = '10px';
        w.appendChild(hint);

        return w;
    }

    function optionRow(step, option) {
        var row = el('div', 'opt' + (bool(option.is_active) ? '' : ' is-off'));
        row.dataset.id = option.id;
        row.draggable = true;

        var grip = el('span', 'opt__grip');
        grip.appendChild(icon(I.grip, 14));
        row.appendChild(grip);

        var labelKey = state.editLang === 'ar' ? 'label_ar' : 'label_en';
        var text = el('input', 'opt__label');
        text.value = option[labelKey] || '';
        text.placeholder = state.editLang === 'ar' ? 'التسمية' : 'Choice label';
        text.addEventListener('input', function () {
            var p = {}; p[labelKey] = text.value;
            patchOption(step, option, p);
        });
        row.appendChild(text);

        var score = el('input', 'opt__score');
        score.type = 'number';
        score.value = option.score || 0;
        score.title = 'Lead score';
        score.addEventListener('input', function () { patchOption(step, option, { score: score.value }); });
        row.appendChild(score);

        var tools = el('div', 'opt__tools');
        tools.appendChild(iconButton(bool(option.is_active) ? I.eye : I.eyeOff,
            bool(option.is_active) ? 'Hide choice' : 'Show choice', 'btn--quiet', function () {
                api('/api/admin/options.php', {
                    method: 'POST',
                    body: { action: 'toggle', funnel_id: state.funnelId, option_id: option.id, is_active: !bool(option.is_active) }
                }).then(function () { return load(true); }).then(preview.schedule)
                  .catch(function (e) { toast(e.message, 'error'); });
            }));
        tools.appendChild(iconButton(I.dup, 'Duplicate', 'btn--quiet', function () {
            api('/api/admin/options.php', { method: 'POST', body: { action: 'duplicate', funnel_id: state.funnelId, option_id: option.id } })
                .then(function () { return load(true); }).then(preview.schedule)
                .catch(function (e) { toast(e.message, 'error'); });
        }));
        tools.appendChild(iconButton(I.trash, 'Delete', 'btn--quiet', function () {
            api('/api/admin/options.php', { method: 'POST', body: { action: 'delete', funnel_id: state.funnelId, option_id: option.id } })
                .then(function () { return load(true); }).then(preview.schedule)
                .catch(function (e) { toast(e.message, 'error'); });
        }));
        row.appendChild(tools);

        return row;
    }

    function addOption(step) {
        var n = (step.options || []).length + 1;
        var value = 'option_' + n;
        var existing = (step.options || []).map(function (o) { return o.option_value; });
        while (existing.indexOf(value) !== -1) { n++; value = 'option_' + n; }

        api('/api/admin/options.php', {
            method: 'POST',
            body: {
                action: 'create', funnel_id: state.funnelId, step_id: step.id,
                option_value: value, label_en: 'Choice ' + n, label_ar: '', score: 0
            }
        }).then(function () { return load(true); })
          .then(preview.schedule)
          .catch(function (e) { toast(e.message, 'error'); });
    }

    function logicEditor(step) {
        var w = el('div');

        w.appendChild(note('Show this step only when an earlier answer matches. Leave as “Always show” to keep it simple.', 'info', I.info));

        var parents = [{ value: '', label: 'Always show' }].concat(
            state.steps.filter(function (s) { return s.id !== step.id; })
                .map(function (s) { return { value: s.step_key, label: (s.title_en || s.step_key) }; })
        );

        var grid = el('div', 'grid-3');
        grid.style.marginTop = '16px';

        grid.appendChild(field('Depends on', select(parents, step.condition_parent_key || '', function (v) {
            patchStep(step, { condition_parent_key: v });
            setTimeout(render, 60);
        })));

        grid.appendChild(field('Condition', select([
            { value: 'equals', label: 'is exactly' },
            { value: 'not_equals', label: 'is not' },
            { value: 'contains', label: 'contains' }
        ], step.condition_operator || 'equals', function (v) { patchStep(step, { condition_operator: v }); })));

        grid.appendChild(field('Value', input(step.condition_value, {
            placeholder: 'e.g. invest',
            onInput: function (v) { patchStep(step, { condition_value: v }); }
        })));

        w.appendChild(grid);
        return w;
    }

    function stepSettings(step) {
        var w = el('div');

        var toggles = el('div');
        toggles.appendChild(toggleRow('Required', 'Visitors must answer before continuing.',
            bool(step.is_required), function (on) { patchStep(step, { is_required: on ? 1 : 0 }); setTimeout(render, 60); }));
        toggles.appendChild(toggleRow('Visible', 'Hidden steps stay saved but are skipped.',
            bool(step.is_active), function (on) { patchStep(step, { is_active: on ? 1 : 0 }); setTimeout(render, 60); }));

        if (['single_select', 'consent', 'information'].indexOf(step.step_type) !== -1) {
            toggles.appendChild(toggleRow('Continue automatically', 'Move to the next step as soon as they choose.',
                bool(step.auto_advance), function (on) { patchStep(step, { auto_advance: on ? 1 : 0 }); }));
        }
        w.appendChild(toggles);

        if (['short_text', 'email', 'phone', 'number'].indexOf(step.step_type) !== -1) {
            var v = el('div', 'grid-2');
            v.style.marginTop = '18px';

            if (step.step_type === 'number') {
                v.appendChild(field('Minimum value', input(step.min_value, { type: 'number', onInput: function (x) { patchStep(step, { min_value: x }); } })));
                v.appendChild(field('Maximum value', input(step.max_value, { type: 'number', onInput: function (x) { patchStep(step, { max_value: x }); } })));
            } else {
                v.appendChild(field('Minimum length', input(step.min_length, { type: 'number', onInput: function (x) { patchStep(step, { min_length: x }); } })));
                v.appendChild(field('Maximum length', input(step.max_length, { type: 'number', onInput: function (x) { patchStep(step, { max_length: x }); } })));
            }
            w.appendChild(v);

            w.appendChild(field('Pattern', input(step.validation_pattern, {
                mono: true, placeholder: '^[A-Za-z ]+$',
                onInput: function (x) { patchStep(step, { validation_pattern: x }); }
            }), 'Advanced: a regular expression the answer must match.'));
        }

        var sfx = suffix();
        w.appendChild(field('Custom error message', input(step['validation_message' + sfx], {
            placeholder: 'Shown when the answer is not accepted',
            onInput: function (x) { var p = {}; p['validation_message' + sfx] = x; patchStep(step, p); }
        })));

        var adv = el('details');
        adv.style.marginTop = '10px';
        var sum = el('summary', 'field__hint', 'Advanced: reference key');
        sum.style.cursor = 'pointer';
        adv.appendChild(sum);
        var keyBox = el('div');
        keyBox.style.marginTop = '10px';
        keyBox.appendChild(field(null, input(step.step_key, {
            mono: true,
            onChange: function (x) {
                var clean = keyify(x);
                patchStep(step, { step_key: clean });
                setTimeout(render, 60);
            }
        }), 'Used in exports and integrations. Changing it will not affect leads already collected.'));
        adv.appendChild(keyBox);
        w.appendChild(adv);

        return w;
    }

    function toggleStep(step) {
        api('/api/admin/steps.php', {
            method: 'POST',
            body: { action: 'toggle', funnel_id: state.funnelId, step_id: step.id, is_active: !bool(step.is_active) }
        }).then(function () { return load(true); }).then(preview.schedule)
          .catch(function (e) { toast(e.message, 'error'); });
    }

    function duplicateStep(step) {
        api('/api/admin/steps.php', { method: 'POST', body: { action: 'duplicate', funnel_id: state.funnelId, step_id: step.id } })
            .then(function (data) {
                state.openStepId = data.step.id;
                toast('Step duplicated — it starts hidden', 'ok');
                return load(true);
            }).then(preview.schedule)
            .catch(function (e) { toast(e.message, 'error'); });
    }

    function deleteStep(step) {
        confirmDialog({
            title: 'Delete this step?',
            sub: (step.title_en || step.step_key),
            message: 'Leads already collected keep their answers — only the question is removed.',
            confirmLabel: 'Delete step',
            danger: true
        }).then(function (ok) {
            if (!ok) { return; }
            api('/api/admin/steps.php', { method: 'POST', body: { action: 'delete', funnel_id: state.funnelId, step_id: step.id } })
                .then(function () {
                    if (state.openStepId === step.id) { state.openStepId = null; }
                    toast('Step deleted', 'ok');
                    return load(true);
                }).then(preview.schedule)
                .catch(function (e) { toast(e.message, 'error'); });
        });
    }

    /* --------------------------------------------------- add step modal -- */
    var TYPE_GROUPS = [
        {
            group: 'Choices',
            items: [
                { t: 'single_select', name: 'Single choice', desc: 'Pick one option' },
                { t: 'multi_select', name: 'Multiple choice', desc: 'Pick several' },
                { t: 'dropdown', name: 'Dropdown', desc: 'Long option lists' }
            ]
        },
        {
            group: 'Answers',
            items: [
                { t: 'short_text', name: 'Text', desc: 'A short written answer' },
                { t: 'email', name: 'Email', desc: 'Validated address' },
                { t: 'phone', name: 'Phone', desc: 'Validated number' },
                { t: 'number', name: 'Number', desc: 'Numeric, with limits' }
            ]
        },
        {
            group: 'Blocks',
            items: [
                { t: 'contact_information', name: 'Contact details', desc: 'Name, phone, email in one step' },
                { t: 'consent', name: 'Consent', desc: 'Permission to make contact' },
                { t: 'information', name: 'Information', desc: 'A message, no answer' }
            ]
        }
    ];

    function showTypePicker() {
        var grid = el('div', 'picker');

        TYPE_GROUPS.forEach(function (g) {
            grid.appendChild(el('div', 'picker__group', g.group));
            g.items.forEach(function (item) {
                var b = el('button', 'type');
                b.type = 'button';

                var ic = el('span', 'type__icon');
                ic.appendChild(icon(typeIcon(item.t), 17));
                b.appendChild(ic);

                var t = el('div');
                t.appendChild(el('div', 'type__name', item.name));
                t.appendChild(el('div', 'type__desc', item.desc));
                b.appendChild(t);

                b.addEventListener('click', function () { modal.close(); createStep(item.t, item.name); });
                grid.appendChild(b);
            });
        });

        modal.open({
            title: 'Add a step',
            sub: 'Pick what you want to ask. You can change it later.',
            body: grid,
            wide: true
        });
    }

    function createStep(type, friendly) {
        var base = keyify(type);
        var used = state.steps.map(function (s) { return s.step_key; });
        var key = base;
        var n = 1;
        while (used.indexOf(key) !== -1) { n++; key = base + '_' + n; }

        var defaults = {
            contact_information: 'How can we reach you?',
            consent: 'I agree to be contacted about this enquiry.',
            information: 'A quick note before you continue'
        };

        api('/api/admin/steps.php', {
            method: 'POST',
            body: {
                action: 'create',
                funnel_id: state.funnelId,
                step_key: key,
                step_type: type,
                title_en: defaults[type] || (friendly + ' question'),
                title_ar: '',
                is_required: true,
                is_active: true,
                auto_advance: type === 'single_select'
            }
        }).then(function (data) {
            var newId = data.step.id;
            state.openStepId = newId;
            state.stepTab[newId] = 'Content';

            // Selection steps are unpublishable while empty — seed two choices.
            if (['single_select', 'multi_select', 'dropdown'].indexOf(type) !== -1) {
                return Promise.all([1, 2].map(function (i) {
                    return api('/api/admin/options.php', {
                        method: 'POST',
                        body: {
                            action: 'create', funnel_id: state.funnelId, step_id: newId,
                            option_value: 'option_' + i, label_en: 'Choice ' + i, score: 0
                        }
                    });
                }));
            }
        }).then(function () { return load(true); })
          .then(function () {
              preview.schedule();
              var node = $('.step-card[data-id="' + state.openStepId + '"]');
              if (node) { node.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
          })
          .catch(function (e) {
              var msg = (e.data && e.data.errors) ? Object.values(e.data.errors)[0] : e.message;
              toast(msg, 'error');
          });
    }

    /* -------------------------------------------------- contact fields -- */
    function contactFieldsCard() {
        var wrap = el('div');
        wrap.appendChild(note('These fields appear inside your Contact details step.', 'info', I.info));

        var list = el('div');
        list.style.marginTop = '6px';

        state.contactFields.forEach(function (f) {
            var row = el('div', 'toggle-row');

            var text = el('div', 'toggle-row__text');
            text.appendChild(el('div', 'toggle-row__label', f.label_en || f.field_key));
            text.appendChild(el('div', 'toggle-row__hint',
                f.field_key + (bool(f.is_required) ? ' · required' : ' · optional')));
            row.appendChild(text);

            var edit = iconButton(I.gear, 'Edit field', 'btn--quiet', function () { editContactField(f); });
            row.appendChild(edit);

            var t = el('button', 'toggle' + (bool(f.is_active) ? ' is-on' : ''));
            t.type = 'button';
            t.setAttribute('role', 'switch');
            t.setAttribute('aria-label', 'Show ' + (f.label_en || f.field_key));
            t.addEventListener('click', function () {
                api('/api/admin/contact-fields.php', {
                    method: 'POST',
                    body: { action: 'toggle', funnel_id: state.funnelId, field_id: f.id, is_active: !bool(f.is_active) }
                }).then(function () { return load(true); }).then(preview.schedule)
                  .catch(function (e) {
                      var msg = (e.data && e.data.errors && e.data.errors.is_active) || e.message;
                      toast(msg, 'error');
                  });
            });
            row.appendChild(t);

            list.appendChild(row);
        });

        wrap.appendChild(list);
        return card('Contact fields', 'Choose what you collect and what is required.', wrap);
    }

    function editContactField(f) {
        var w = el('div');
        var sfx = suffix();

        var sw = langSwitch();
        if (sw) {
            // Re-rendering inside a modal would lose it; keep language local here.
            sw = null;
        }

        w.appendChild(field('Label', input(f['label' + sfx], {
            onInput: function (v) { var p = {}; p['label' + sfx] = v; patchContactField(f, p); }
        })));

        w.appendChild(field('Placeholder', input(f['placeholder' + sfx], {
            onInput: function (v) { var p = {}; p['placeholder' + sfx] = v; patchContactField(f, p); }
        })));

        var grid = el('div', 'grid-2');
        grid.appendChild(field('Minimum length', input(f.min_length, { type: 'number', onInput: function (v) { patchContactField(f, { min_length: v }); } })));
        grid.appendChild(field('Maximum length', input(f.max_length, { type: 'number', onInput: function (v) { patchContactField(f, { max_length: v }); } })));
        w.appendChild(grid);

        w.appendChild(toggleRow('Required', 'Visitors must fill this in.', bool(f.is_required),
            function (on) { patchContactField(f, { is_required: on }); }));

        modal.open({
            title: 'Edit ' + (f.label_en || f.field_key),
            sub: 'Changes save automatically.',
            body: w,
            actions: [button('Done', 'btn--primary', function () { modal.close(); render(); })]
        });
    }

    /* ----------------------------------------------------- integrations -- */
    function renderIntegrations(host) {
        var f = state.funnel;

        host.appendChild(sectionHead('Integrations', 'Where each new lead should go the moment it arrives.'));

        var mail = el('div');
        mail.appendChild(field('Send new leads to',
            input(f.recipient_email, {
                placeholder: 'sales@yourcompany.com',
                onInput: function (v) { patchFunnel({ recipient_email: v }); }
            }),
            'Separate several addresses with commas. Leave empty to use the system default.'));
        host.appendChild(card('Email notifications', 'A formatted summary of every lead.', mail));

        var hook = el('div');
        hook.appendChild(field('Webhook URL',
            input(f.webhook_url, {
                mono: true, placeholder: 'https://hooks.zapier.com/…',
                onInput: function (v) { patchFunnel({ webhook_url: v }); }
            }),
            'The full lead is posted as JSON. Works with Zapier, Make and any HTTPS endpoint.'));

        hook.appendChild(toggleRow('Enable webhook', 'Turn on once the URL is saved.', bool(f.webhook_enabled),
            function (on) { patchFunnel({ webhook_enabled: on }); }));
        host.appendChild(card('Webhook', 'Push leads into any other tool.', hook));

        var soon = el('div');
        [
            { n: 'Zapier', d: 'Use the webhook above with a Zapier Catch Hook.', ready: true },
            { n: 'CRM sync', d: 'Two-way sync with HubSpot, Salesforce and Pipedrive.' },
            { n: 'Meta Conversions API', d: 'Send qualified leads back to Meta Ads.' },
            { n: 'Google Ads', d: 'Offline conversion import.' }
        ].forEach(function (item) {
            var row = el('div', 'integration' + (item.ready ? '' : ' is-soon'));
            row.appendChild(el('div', 'integration__logo', item.n.charAt(0)));
            var t = el('div', 'integration__text');
            t.appendChild(el('div', 'integration__name', item.n));
            t.appendChild(el('div', 'integration__desc', item.d));
            row.appendChild(t);
            row.appendChild(el('span', 'chip' + (item.ready ? '' : ' chip--off'), item.ready ? 'Available' : 'Coming soon'));
            soon.appendChild(row);
        });

        var c = el('div', 'card');
        var h = el('div', 'card__head');
        h.appendChild(el('div', 'card__title', 'More destinations'));
        c.appendChild(h);
        c.appendChild(soon);
        host.appendChild(c);
    }

    /* --------------------------------------------------------- settings -- */
    function renderSettings(host) {
        var f = state.funnel;
        var sfx = suffix();

        host.appendChild(sectionHead('Settings', 'What happens after someone completes your funnel, plus the finer details.'));

        // success screen
        var success = el('div');
        var sw = langSwitch();
        if (sw) { success.appendChild(sw); }

        success.appendChild(field('Headline', input(f['success_title' + sfx], {
            onInput: function (v) { var p = {}; p['success_title' + sfx] = v; patchFunnel(p); }
        })));
        success.appendChild(field('Message', input(f['success_message' + sfx], {
            multiline: true, rows: 3,
            onInput: function (v) { var p = {}; p['success_message' + sfx] = v; patchFunnel(p); }
        })));

        var g2 = el('div', 'grid-2');
        g2.appendChild(field('Submit button', input(f['submit_label' + sfx], {
            onInput: function (v) { var p = {}; p['submit_label' + sfx] = v; patchFunnel(p); }
        })));
        g2.appendChild(field('Button after success', input(f['success_button' + sfx], {
            onInput: function (v) { var p = {}; p['success_button' + sfx] = v; patchFunnel(p); }
        })));
        success.appendChild(g2);
        host.appendChild(card('Success screen', 'What visitors see once they finish.', success));

        // redirect
        var redirect = el('div');
        redirect.appendChild(field('Redirect to', input(f.redirect_url, {
            placeholder: 'https://yourcompany.com/thank-you',
            onInput: function (v) { patchFunnel({ redirect_url: v }); }
        }), 'Optional. Leave empty to keep visitors on the success screen.'));
        redirect.appendChild(field('Delay before redirect', input(f.redirect_delay, {
            type: 'number', onInput: function (v) { patchFunnel({ redirect_delay: v }); }
        }), 'Seconds. 0 sends them immediately.'));
        host.appendChild(card('Redirect', null, redirect));

        // whatsapp + privacy
        var extras = el('div');
        extras.appendChild(toggleRow('WhatsApp button', 'Offer a WhatsApp chat on the success screen.',
            bool(f.whatsapp_enabled), function (on) { patchFunnel({ whatsapp_enabled: on }); }));
        extras.appendChild(field('WhatsApp button text', input(f['whatsapp_label' + sfx], {
            onInput: function (v) { var p = {}; p['whatsapp_label' + sfx] = v; patchFunnel(p); }
        })));
        extras.appendChild(field('Privacy policy link', input(f.privacy_policy_url, {
            placeholder: 'https://yourcompany.com/privacy',
            onInput: function (v) { patchFunnel({ privacy_policy_url: v }); }
        })));
        host.appendChild(card('Follow-up', null, extras));

        // seo
        var seo = el('div');
        seo.appendChild(field('Tagline', input(state.settings.site_tagline || '', {
            onInput: function (v) { patchSetting({ site_tagline: v }); }
        }), 'Browser title becomes “Company — Tagline”, and this is used as the page description.'));
        seo.appendChild(note('Custom domains are not available yet. Your funnel is served on ' +
            (state.appUrl || window.location.origin).replace(/^https?:\/\//, '') + '.', null, I.info));
        host.appendChild(card('SEO & domain', null, seo));

        // experience
        var exp = el('div');
        exp.appendChild(toggleRow('Progress bar', 'Show how far through they are.', bool(f.progress_bar_enabled),
            function (on) { patchFunnel({ progress_bar_enabled: on }); }));
        exp.appendChild(toggleRow('Step counter', 'Show “Step 2 of 6”.', bool(f.step_counter_enabled),
            function (on) { patchFunnel({ step_counter_enabled: on }); }));
        exp.appendChild(toggleRow('Back button', 'Let visitors revisit earlier answers.', bool(f.back_button_enabled),
            function (on) { patchFunnel({ back_button_enabled: on }); }));
        exp.appendChild(toggleRow('Remember answers', 'Keep answers if the page is reloaded.', bool(f.save_progress_enabled),
            function (on) { patchFunnel({ save_progress_enabled: on }); }));
        host.appendChild(card('Experience', null, exp));

        // advanced
        var adv = el('div');
        adv.appendChild(field('Minimum completion time', input(f.min_completion_seconds, {
            type: 'number', onInput: function (v) { patchFunnel({ min_completion_seconds: v }); }
        }), 'Seconds. Submissions faster than this are treated as automated.'));
        host.appendChild(card('Advanced', null, adv));

        // danger zone
        var danger = el('div');
        var row = el('div', 'toggle-row');
        var t = el('div', 'toggle-row__text');
        t.appendChild(el('div', 'toggle-row__label', 'Delete this funnel'));
        t.appendChild(el('div', 'toggle-row__hint', 'Leads already collected are kept and stay visible under Leads.'));
        row.appendChild(t);
        row.appendChild(button('Delete', 'btn--danger btn--sm', deleteFunnel, I.trash));
        danger.appendChild(row);
        host.appendChild(card('Danger zone', null, danger, null, 'danger-zone'));
    }

    function deleteFunnel() {
        confirmDialog({
            title: 'Delete this funnel?',
            sub: state.funnel.name,
            message: 'Its steps and settings are removed permanently. Leads already collected are kept.',
            confirmLabel: 'Delete funnel',
            danger: true
        }).then(function (ok) {
            if (!ok) { return; }
            api('/api/admin/funnels.php', {
                method: 'POST',
                body: { action: 'delete', funnel_id: state.funnelId, confirm_permanent: true }
            }).then(function () { window.location.href = '/admin/#/funnels'; })
              .catch(function (e) { toast(e.message, 'error'); });
        });
    }

    /* -------------------------------------------------------- analytics -- */
    /**
     * Analytics reads from the event engine, never from the leads table alone.
     *
     * Two rules run through everything below. A metric with no measurement is
     * drawn as "No data yet" or an em dash, never as a zero — a zero is a
     * measurement. And days that predate tracking are marked lead-only, because
     * their lead count is real while their traffic was simply never recorded.
     */
    var RANGES = [
        { id: 'today', label: 'Today', days: 1 },
        { id: '7', label: '7 days', days: 7 },
        { id: '30', label: '30 days', days: 30 },
        { id: '90', label: '90 days', days: 90 }
    ];

    function fmtNumber(n) {
        if (n === null || n === undefined) { return '—'; }
        return Number(n).toLocaleString();
    }

    function fmtRate(v) {
        if (v === null || v === undefined) { return '—'; }
        return Number(v).toFixed(1) + '%';
    }

    function fmtDuration(seconds) {
        if (seconds === null || seconds === undefined) { return '—'; }
        var s = Math.max(0, Math.round(seconds));
        if (s < 60) { return s + 's'; }
        var m = Math.floor(s / 60);
        if (m < 60) { return m + 'm ' + (s % 60) + 's'; }
        return Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
    }

    function noData(text) {
        var n = el('p', 'nodata', text || 'No data yet');
        return n;
    }

    function analyticsQuery() {
        var q = '/api/admin/analytics.php?funnel_id=' + state.funnelId + '&compare=1';

        if (state.range.from && state.range.to) {
            return q + '&date_from=' + state.range.from + '&date_to=' + state.range.to;
        }
        return q + '&days=' + state.range.days;
    }

    function renderAnalytics(host) {
        host.appendChild(sectionHead('Analytics', 'How this funnel is performing.'));
        host.appendChild(rangeBar());

        if (!state.analytics) {
            var boot = el('div', 'boot');
            boot.style.height = '240px';
            boot.appendChild(el('span', 'boot__spinner'));
            host.appendChild(boot);

            api(analyticsQuery())
                .then(function (d) { state.analytics = d; render(); })
                .catch(function (e) { if (e.message !== 'auth') { toast(e.message, 'error'); } });
            return;
        }

        var a = state.analytics;
        var s = a.summary;

        if (!a.meta.analytics_enabled) {
            host.appendChild(note('Visitor tracking is switched off for this installation. '
                + 'Figures already recorded are still shown.', 'warn', I.warn));
        }

        var kpis = el('div', 'kpi-row');
        [
            ['Visitors', fmtNumber(s.unique_visitors), 'unique people'],
            ['Sessions', fmtNumber(s.sessions), fmtNumber(s.views) + ' views'],
            ['Leads', fmtNumber(s.leads), s.attributed_leads + ' matched to a visit'],
            ['Conversion', fmtRate(s.conversion_rate), 'matched leads per visit'],
            ['Completion', fmtRate(s.completion_rate), 'of engaged sessions'],
            ['Avg. time', fmtDuration(s.avg_completion_seconds), 'to complete']
        ].forEach(function (k) {
            var box = el('div', 'kpi');
            box.appendChild(el('div', 'kpi__label', k[0]));
            box.appendChild(el('div', 'kpi__value', k[1]));
            box.appendChild(el('div', 'kpi__meta', k[2]));
            kpis.appendChild(box);
        });
        host.appendChild(kpis);

        host.appendChild(trendCard(a));
        host.appendChild(dropOffCard(a));

        var g1 = el('div', 'grid-2');
        g1.appendChild(barCard('Traffic sources', a.sources));
        g1.appendChild(barCard('Campaigns', a.campaigns));
        host.appendChild(g1);

        var g2 = el('div', 'grid-2');
        g2.appendChild(barCard('Devices', a.devices));
        g2.appendChild(barCard('Countries', a.countries));
        host.appendChild(g2);

        var g3 = el('div', 'grid-2');
        g3.appendChild(barCard('Browsers', a.browsers));
        g3.appendChild(barCard('Operating systems', a.os));
        host.appendChild(g3);

        var g4 = el('div', 'grid-2');
        g4.appendChild(barCard('Referrers', a.referrers));
        g4.appendChild(barCard('Cities', a.cities));
        host.appendChild(g4);

        if ((a.comparison || []).length > 1) {
            host.appendChild(comparisonCard(a.comparison));
        }

        if (a.meta.tracking_started_on) {
            host.appendChild(note('Visitor tracking started on ' + a.meta.tracking_started_on
                + '. Earlier days show their real lead count only — traffic was not measured then, '
                + 'so it is left blank rather than shown as zero.', null, I.info));
        } else {
            host.appendChild(note('No visits have been recorded yet. Lead figures are real; '
                + 'traffic, conversion and drop-off appear once the funnel receives its first visitor.',
                null, I.info));
        }

        var footnote = 'Per-step figures come from the event timeline, which is kept for '
            + a.meta.event_retention_days + ' days. Visitors, sessions, leads and completions stay exact for any range.';

        if (s.leads > s.attributed_leads) {
            footnote += ' ' + (s.leads - s.attributed_leads) + ' lead'
                + (s.leads - s.attributed_leads === 1 ? '' : 's')
                + ' in this range could not be matched to a tracked visit, so the '
                + 'conversion rate counts only the ' + s.attributed_leads + ' that could.';
        }

        host.appendChild(el('p', 'field__hint', footnote));
    }

    function rangeBar() {
        var bar = el('div', 'range-bar');
        var group = el('div', 'subtabs');

        RANGES.forEach(function (r) {
            var b = el('button', 'subtab' + (state.range.id === r.id ? ' is-on' : ''), r.label);
            b.type = 'button';
            b.addEventListener('click', function () {
                state.range = { id: r.id, days: r.days, from: null, to: null };
                state.analytics = null;
                render();
            });
            group.appendChild(b);
        });

        var custom = el('button', 'subtab' + (state.range.id === 'custom' ? ' is-on' : ''), 'Custom');
        custom.type = 'button';
        custom.addEventListener('click', openCustomRange);
        group.appendChild(custom);

        bar.appendChild(group);

        var spacer = el('div');
        spacer.style.flex = '1';
        bar.appendChild(spacer);

        if (state.analytics) {
            bar.appendChild(el('span', 'range-bar__dates',
                state.analytics.range.from + ' → ' + state.analytics.range.to));
        }

        bar.appendChild(button('Refresh', 'btn--ghost btn--sm', function () {
            state.analytics = null;
            render();
        }));

        return bar;
    }

    function openCustomRange() {
        var today = new Date().toISOString().slice(0, 10);
        var from = input(state.range.from || today, { type: 'date' });
        var to = input(state.range.to || today, { type: 'date' });

        var grid = el('div', 'grid-2');
        grid.appendChild(field('From', from));
        grid.appendChild(field('To', to));

        var wrap = el('div');
        wrap.appendChild(grid);

        modal.open({
            title: 'Custom date range',
            sub: 'Both dates are included.',
            body: wrap,
            actions: [
                button('Cancel', 'btn--ghost', function () { modal.close(); }),
                button('Apply', 'btn--publish', function () {
                    if (!from.value || !to.value) { toast('Choose both dates.', 'error'); return; }
                    state.range = { id: 'custom', days: 0, from: from.value, to: to.value };
                    state.analytics = null;
                    modal.close();
                    render();
                })
            ]
        });
    }

    function trendCard(a) {
        var series = a.trend || [];
        var hasTraffic = series.some(function (p) { return !p.lead_only && (p.sessions > 0 || p.views > 0); });
        var hasLeads = series.some(function (p) { return p.leads > 0; });

        if (!hasTraffic && !hasLeads) {
            return card('Performance', null, noData('No activity in this date range.'));
        }

        var wrap = el('div');

        var legend = el('div', 'legend');
        [['views', 'Views'], ['sessions', 'Sessions'], ['leads', 'Leads']].forEach(function (m) {
            var item = el('span', 'legend__item');
            item.appendChild(el('span', 'legend__dot legend__dot--' + m[0]));
            item.appendChild(el('span', null, m[1]));
            legend.appendChild(item);
        });
        wrap.appendChild(legend);

        var max = Math.max.apply(null, series.map(function (p) {
            return Math.max(p.views, p.sessions, p.leads);
        }).concat([1]));

        var chart = el('div', 'chart');

        series.forEach(function (p) {
            var col = el('div', 'chart__col');
            col.title = p.date + (p.lead_only
                ? ' · ' + p.leads + ' leads · traffic not tracked yet'
                : ' · ' + p.views + ' views · ' + p.sessions + ' sessions · ' + p.leads + ' leads');

            var stack = el('div', 'chart__stack');

            if (p.lead_only) {
                // Traffic was never measured on this day, so only the lead bar
                // is drawn — hatched, so the gap reads as absence not as zero.
                var lo = el('div', 'chart__bar chart__bar--leadonly');
                lo.style.height = Math.max(p.leads > 0 ? 3 : 0, Math.round((p.leads / max) * 100)) + '%';
                stack.appendChild(lo);
            } else {
                [['views', p.views], ['sessions', p.sessions], ['leads', p.leads]].forEach(function (m) {
                    var b = el('div', 'chart__bar chart__bar--' + m[0]);
                    b.style.height = Math.max(m[1] > 0 ? 3 : 0, Math.round((m[1] / max) * 100)) + '%';
                    stack.appendChild(b);
                });
            }

            col.appendChild(stack);
            chart.appendChild(col);
        });

        wrap.appendChild(chart);

        var axis = el('div', 'chart__axis');
        axis.appendChild(el('span', null, series.length ? series[0].date : ''));
        axis.appendChild(el('span', null, series.length ? series[series.length - 1].date : ''));
        wrap.appendChild(axis);

        return card('Performance', a.range.days + ' day' + (a.range.days === 1 ? '' : 's'), wrap);
    }

    function dropOffCard(a) {
        var steps = a.steps || [];

        if (steps.length === 0) {
            return card('Funnel drop-off', null, noData('This funnel has no active steps.'));
        }

        if (!steps.some(function (s) { return s.views > 0; })) {
            return card('Funnel drop-off', 'Where visitors leave',
                noData('No step activity recorded in this date range.'));
        }

        var top = Math.max.apply(null, steps.map(function (s) { return s.views; }).concat([1]));
        var wrap = el('div', 'steps-chart');

        steps.forEach(function (s) {
            var row = el('div', 'stepbar');

            var head = el('div', 'stepbar__head');
            var name = el('div', 'stepbar__name');
            name.appendChild(el('span', 'stepbar__pos', s.position));
            name.appendChild(el('span', null, s.title));
            head.appendChild(name);

            var stats = el('div', 'stepbar__stats');
            stats.appendChild(el('span', null, fmtNumber(s.views) + ' views'));
            stats.appendChild(el('span', null, fmtNumber(s.starts) + ' started'));
            stats.appendChild(el('span', null, fmtNumber(s.completions) + ' completed'));
            if (s.avg_seconds !== null) { stats.appendChild(el('span', null, fmtDuration(s.avg_seconds))); }
            head.appendChild(stats);
            row.appendChild(head);

            var track = el('div', 'stepbar__track');
            var done = el('div', 'stepbar__fill');
            done.style.width = Math.round((s.completions / top) * 100) + '%';
            track.appendChild(done);

            var lost = el('div', 'stepbar__drop');
            lost.style.width = Math.round((s.dropped / top) * 100) + '%';
            track.appendChild(lost);
            row.appendChild(track);

            var foot = el('div', 'stepbar__foot');
            var left = el('span', null, s.type_label);
            if (s.errors > 0) { left.textContent += ' · ' + s.errors + ' validation errors'; }
            if (s.backs > 0) { left.textContent += ' · ' + s.backs + ' went back'; }
            foot.appendChild(left);

            var drop = el('span', 'stepbar__droprate', s.drop_off_rate === null
                ? 'No data yet'
                : fmtRate(s.drop_off_rate) + ' drop-off · ' + fmtNumber(s.dropped) + ' lost');
            if (s.drop_off_rate !== null && s.drop_off_rate >= 40) { drop.classList.add('is-high'); }
            foot.appendChild(drop);
            row.appendChild(foot);

            wrap.appendChild(row);
        });

        return card('Funnel drop-off', 'Where visitors leave', wrap);
    }

    function barCard(title, rows) {
        rows = rows || [];

        if (rows.length === 0) { return card(title, null, noData()); }

        var top = Math.max.apply(null, rows.map(function (r) { return r.total; }).concat([1]));
        var wrap = el('div', 'bars');

        rows.forEach(function (r) {
            var item = el('div');
            var head = el('div', 'bar__top');
            head.appendChild(el('span', 'bar__label', r.label));
            head.appendChild(el('span', 'bar__value',
                fmtNumber(r.total) + (r.leads ? ' · ' + r.leads + ' leads' : '')));
            item.appendChild(head);

            var track = el('div', 'bar__track');
            var fill = el('div', 'bar__fill');
            fill.style.width = Math.round((r.total / top) * 100) + '%';
            track.appendChild(fill);
            item.appendChild(track);
            wrap.appendChild(item);
        });

        return card(title, null, wrap);
    }

    function comparisonCard(rows) {
        var wrap = el('div', 'compare');

        rows.forEach(function (r) {
            var row = el('div', 'compare__row' + (r.funnel_id === state.funnelId ? ' is-current' : ''));

            var name = el('div', 'compare__name');
            name.appendChild(el('div', null, r.name));
            name.appendChild(el('div', 'compare__slug', '/' + r.slug));
            row.appendChild(name);

            [
                [fmtNumber(r.unique_visitors), 'visitors'],
                [fmtNumber(r.sessions), 'sessions'],
                [fmtNumber(r.leads), 'leads'],
                [fmtRate(r.conversion_rate), 'conversion']
            ].forEach(function (m) {
                var cell = el('div', 'compare__metric');
                cell.appendChild(el('div', 'compare__value', m[0]));
                cell.appendChild(el('div', 'compare__label', m[1]));
                row.appendChild(cell);
            });

            if (r.funnel_id === state.funnelId) {
                row.appendChild(el('span', 'compare__here', 'This funnel'));
            } else {
                row.appendChild(button('Open', 'btn--ghost btn--sm', function () {
                    window.location.href = '/admin/builder.php?funnel='
                        + encodeURIComponent(r.funnel_id) + '#analytics';
                }));
            }

            wrap.appendChild(row);
        });

        return card('All funnels', 'Same date range', wrap);
    }

    /* ============================================================ share === */
    function showQr() {
        var url = publicUrl();
        var wrap = el('div', 'qr-wrap');
        var box = el('div', 'qr-box');

        var svg = window.LumeraQR ? window.LumeraQR.svg(url) : null;
        if (svg) {
            box.innerHTML = svg;
        } else {
            box.appendChild(el('p', 'field__hint', 'Link is too long to encode.'));
        }
        wrap.appendChild(box);
        wrap.appendChild(el('p', 'field__hint', url));

        var download = button('Download SVG', 'btn--ghost', function () {
            var blob = new Blob([svg], { type: 'image/svg+xml' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = state.funnel.slug + '-qr.svg';
            a.click();
            URL.revokeObjectURL(a.href);
        }, I.cloud);

        modal.open({
            title: 'Scan to open',
            sub: 'Point a phone camera at this code.',
            body: wrap,
            actions: [button('Close', 'btn--ghost', function () { modal.close(); }), download]
        });
    }

    function showShare() {
        var url = publicUrl();
        var w = el('div');

        w.appendChild(field('Public link', input(url, { mono: true }), 'Share this anywhere.'));

        var embed = '<iframe src="' + url + '" style="width:100%;height:720px;border:0" title="'
            + (state.funnel.name || 'Funnel') + '"></iframe>';
        w.appendChild(field('Embed on your website', input(embed, { multiline: true, rows: 3, mono: true }),
            'Paste this into any page.'));

        modal.open({
            title: 'Share your funnel',
            sub: 'Copy the link or embed it on your site.',
            body: w,
            actions: [
                button('Copy link', 'btn--ghost', function () { copy(url); }, I.copy),
                button('Copy embed', 'btn--primary', function () { copy(embed); }, I.copy)
            ]
        });
    }

    /* ========================================================== preview === */
    var preview = {
        timer: null,

        sizes: { desktop: [1120, 760], tablet: [820, 1024], mobile: [390, 760] },

        paintBar: function () {
            var g = $('#device-group');
            clear(g);

            [['desktop', I.desktop], ['tablet', I.tablet], ['mobile', I.mobile]].forEach(function (d) {
                var b = el('button', 'device-btn' + (state.device === d[0] ? ' is-on' : ''));
                b.type = 'button';
                b.title = d[0];
                b.setAttribute('aria-label', d[0] + ' preview');
                b.appendChild(icon(d[1], 15));
                b.addEventListener('click', function () { state.device = d[0]; preview.paintBar(); preview.mount(); });
                g.appendChild(b);
            });
        },

        mount: function () {
            var stage = $('#preview-stage');
            clear(stage);

            if (!state.funnel) { return; }

            var size = this.sizes[state.device];
            var pad = 40;
            var avail = { w: stage.clientWidth - pad, h: stage.clientHeight - pad };
            var scale = Math.min(avail.w / size[0], avail.h / size[1], 1);

            var frame = el('div', 'preview__frame');
            frame.style.width = Math.round(size[0] * scale) + 'px';
            frame.style.height = Math.round(size[1] * scale) + 'px';

            var iframe = document.createElement('iframe');
            iframe.id = 'preview-iframe';
            iframe.src = '/admin/preview.php?slug=' + encodeURIComponent(state.funnel.slug) + '&t=' + Date.now();
            iframe.style.width = size[0] + 'px';
            iframe.style.height = size[1] + 'px';
            iframe.style.transform = 'scale(' + scale + ')';
            iframe.style.transformOrigin = 'top left';
            iframe.title = 'Funnel preview';

            frame.appendChild(iframe);
            stage.appendChild(frame);
        },

        /** Reload shortly after edits settle, so typing does not thrash it. */
        schedule: function () {
            clearTimeout(this.timer);
            this.timer = setTimeout(function () { preview.reload(); }, 700);
        },

        reload: function () {
            var iframe = $('#preview-iframe');
            if (!iframe || !state.funnel) { preview.mount(); return; }
            iframe.src = '/admin/preview.php?slug=' + encodeURIComponent(state.funnel.slug) + '&t=' + Date.now();
        }
    };

    /* ========================================================= sortable === */
    function makeSortable(container, selector, onReorder) {
        var dragged = null;

        container.addEventListener('dragstart', function (ev) {
            var item = ev.target.closest(selector);
            if (!item) { return; }
            dragged = item;
            item.classList.add('is-dragging');
            ev.dataTransfer.effectAllowed = 'move';
            try { ev.dataTransfer.setData('text/plain', item.dataset.id); } catch (e) { /* ignore */ }
        });

        container.addEventListener('dragend', function () {
            if (dragged) { dragged.classList.remove('is-dragging'); }
            $$(selector, container).forEach(function (n) { n.classList.remove('is-drop-before', 'is-drop-after'); });
            dragged = null;
        });

        container.addEventListener('dragover', function (ev) {
            if (!dragged) { return; }
            ev.preventDefault();

            var target = ev.target.closest(selector);
            $$(selector, container).forEach(function (n) { n.classList.remove('is-drop-before', 'is-drop-after'); });
            if (!target || target === dragged) { return; }

            var rect = target.getBoundingClientRect();
            var after = (ev.clientY - rect.top) > rect.height / 2;
            target.classList.add(after ? 'is-drop-after' : 'is-drop-before');
        });

        container.addEventListener('drop', function (ev) {
            if (!dragged) { return; }
            ev.preventDefault();

            var target = ev.target.closest(selector);
            $$(selector, container).forEach(function (n) { n.classList.remove('is-drop-before', 'is-drop-after'); });
            if (!target || target === dragged) { return; }

            var rect = target.getBoundingClientRect();
            var after = (ev.clientY - rect.top) > rect.height / 2;
            container.insertBefore(dragged, after ? target.nextSibling : target);

            onReorder($$(selector, container).map(function (n) { return parseInt(n.dataset.id, 10); }));
        });
    }

    /* ============================================================= load === */
    function load(quiet) {
        return api('/api/admin/funnel.php?funnel_id=' + state.funnelId).then(function (data) {
            state.funnel = data.funnel;
            state.steps = data.steps || [];
            state.contactFields = data.contact_fields || [];
            state.publish = data.status || {};
            state.meta = data.meta || {};
            state.uploads = {
                logo_formats: data.meta.logo_formats,
                favicon_formats: data.meta.favicon_formats,
                background_formats: data.meta.background_formats,
                step_formats: ['jpg', 'jpeg', 'png', 'webp']
            };

            var available = langs();
            if (available.indexOf(state.editLang) === -1) { state.editLang = available[0] || 'en'; }

            paint.topbar();
            paint.rail();
            if (!quiet) { paint.saveState(); }
            render();
        });
    }

    function loadSettings() {
        return api('/api/admin/settings.php').then(function (data) { state.settings = data.settings || {}; });
    }

    /* ============================================================= boot === */
    function boot() {
        $('#funnel-title').addEventListener('input', function () {
            patchFunnel({ name: this.value });
        });

        $('#preview-reload').addEventListener('click', function () { preview.reload(); });

        $('#scrim').addEventListener('click', function (ev) {
            if (ev.target === $('#scrim')) { modal.close(); }
        });

        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && !$('#scrim').hidden) { modal.close(); }
        });

        // Nothing is lost, but a queued write should still land before unload.
        window.addEventListener('beforeunload', function (ev) {
            if (save.pending() || save.inflight > 0) {
                save.flushAll();
                ev.preventDefault();
                ev.returnValue = '';
            }
        });

        var hash = (window.location.hash || '').replace('#', '');
        var known = SECTIONS.map(function (s) { return s.id; }).concat(['analytics']);
        if (known.indexOf(hash) !== -1) { state.section = hash; }

        window.addEventListener('hashchange', function () {
            var h = (window.location.hash || '').replace('#', '');
            if (known.indexOf(h) !== -1 && h !== state.section) { state.section = h; paint.rail(); render(); }
        });

        window.addEventListener('resize', function () { preview.mount(); });

        preview.paintBar();
        paint.saveState();

        loadSettings()
            .then(load)
            .then(function () { preview.mount(); })
            .catch(function (e) {
                if (e && e.message !== 'auth') { toast(e.message || 'Could not load this funnel.', 'error'); }
            });
    }

    boot();
})();
