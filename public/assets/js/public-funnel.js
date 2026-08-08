/* =========================================================================
 * Lumera — public funnel renderer
 *
 * Everything rendered here comes from the published configuration returned by
 * /api/public/funnel.php. There are no hardcoded steps, titles, options,
 * option counts, required flags, languages or progress percentages.
 *
 * The same file drives the admin preview (?preview=1), so the preview and the
 * live funnel can never drift apart.
 * ========================================================================= */
(function () {
    'use strict';

    var root = document.getElementById('funnel-root');
    if (!root) { return; }

    /* ---------------------------------------------------------------- i18n */
    /* Interface chrome only. All funnel content is server-driven. */
    var UI = {
        en: {
            loading: 'Loading…',
            error_title: 'We could not load this form',
            error_text: 'Please refresh the page and try again.',
            retry: 'Try again',
            next: 'Next',
            back: 'Back',
            submit: 'Submit',
            submitting: 'Sending…',
            privacy: 'Privacy Policy',
            step_counter: 'Step {current} of {total}',
            required: 'This field is required.',
            select_required: 'Please make a selection.',
            consent_required: 'Please confirm to continue.',
            invalid_email: 'Please enter a valid email address.',
            invalid_phone: 'Please enter a valid phone number.',
            invalid_number: 'Please enter a valid number.',
            min_length: 'Please enter at least {n} characters.',
            max_length: 'Please enter no more than {n} characters.',
            min_value: 'The value must be at least {n}.',
            max_value: 'The value must be at most {n}.',
            pattern: 'Please check the format of your answer.',
            generic_error: 'Something went wrong. Please try again.',
            select_placeholder: 'Please choose…',
            preview_note: 'Preview mode — submissions are disabled.',
            continue: 'Continue',
            redirecting: 'Redirecting you in {n} seconds…'
        },
        ar: {
            loading: 'جارٍ التحميل…',
            error_title: 'تعذّر تحميل النموذج',
            error_text: 'يرجى تحديث الصفحة والمحاولة مرة أخرى.',
            retry: 'إعادة المحاولة',
            next: 'التالي',
            back: 'رجوع',
            submit: 'إرسال',
            submitting: 'جارٍ الإرسال…',
            privacy: 'سياسة الخصوصية',
            step_counter: 'الخطوة {current} من {total}',
            required: 'هذا الحقل مطلوب.',
            select_required: 'يرجى الاختيار.',
            consent_required: 'يرجى الموافقة للمتابعة.',
            invalid_email: 'يرجى إدخال بريد إلكتروني صحيح.',
            invalid_phone: 'يرجى إدخال رقم هاتف صحيح.',
            invalid_number: 'يرجى إدخال رقم صحيح.',
            min_length: 'يرجى إدخال {n} أحرف على الأقل.',
            max_length: 'يرجى إدخال {n} حرفاً كحد أقصى.',
            min_value: 'يجب ألا تقل القيمة عن {n}.',
            max_value: 'يجب ألا تزيد القيمة عن {n}.',
            pattern: 'يرجى التحقق من صيغة الإجابة.',
            generic_error: 'حدث خطأ ما. يرجى المحاولة مرة أخرى.',
            select_placeholder: 'يرجى الاختيار…',
            preview_note: 'وضع المعاينة — الإرسال معطّل.',
            continue: 'متابعة',
            redirecting: 'سيتم تحويلك خلال {n} ثوانٍ…'
        }
    };

    var COUNTRY_CODES = ['+971', '+966', '+974', '+973', '+965', '+968', '+20', '+44', '+91', '+92', '+1', '+33', '+49', '+7', '+86', '+90', '+234', '+27'];

    /* --------------------------------------------------------------- state */
    var state = {
        slug: root.dataset.slug || '',
        preview: root.dataset.preview === '1',
        lang: root.dataset.defaultLanguage || 'en',
        languages: (root.dataset.languages || 'en').split(',').filter(Boolean),
        csrf: root.dataset.csrf || '',
        submissionToken: root.dataset.submissionToken || '',
        config: null,
        steps: [],
        index: 0,
        answers: {},
        submitting: false,
        startedAt: Date.now()
    };

    var el = {
        stage: document.getElementById('funnel-stage'),
        loading: document.getElementById('state-loading'),
        error: document.getElementById('state-error'),
        errorText: document.getElementById('error-text'),
        retry: document.getElementById('retry-button'),
        form: document.getElementById('step-form'),
        container: document.getElementById('step-container'),
        stepError: document.getElementById('step-error'),
        back: document.getElementById('back-button'),
        next: document.getElementById('next-button'),
        progress: document.getElementById('funnel-progress'),
        progressFill: document.getElementById('progress-fill'),
        counter: document.getElementById('funnel-counter'),
        success: document.getElementById('state-success'),
        successTitle: document.getElementById('success-title'),
        successMessage: document.getElementById('success-message'),
        whatsapp: document.getElementById('whatsapp-cta'),
        successCta: document.getElementById('success-cta'),
        redirectNote: document.getElementById('redirect-note'),
        privacy: document.getElementById('privacy-link'),
        company: document.getElementById('footer-company'),
        wordmark: document.querySelector('.funnel__wordmark'),
        logo: document.querySelector('.funnel__logo'),
        brand: document.querySelector('.funnel__brand'),
        honeypot: document.getElementById('hp-contact-ref')
    };

    /* ------------------------------------------------------------- helpers */
    function t(key, replacements) {
        var pack = UI[state.lang] || UI.en;
        var value = pack[key] || UI.en[key] || key;

        if (replacements) {
            Object.keys(replacements).forEach(function (token) {
                value = value.replace('{' + token + '}', replacements[token]);
            });
        }

        return value;
    }

    /** Picks the active-language string with an English fallback. */
    function localized(bundle) {
        if (!bundle) { return ''; }
        if (typeof bundle === 'string') { return bundle; }

        return bundle[state.lang] || bundle.en || '';
    }

    function storageKey(suffix) {
        return 'lumera_funnel_' + (state.slug || 'default') + '_' + suffix;
    }

    function sessionRead(key, fallback) {
        try {
            var raw = window.sessionStorage.getItem(key);
            return raw ? JSON.parse(raw) : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function sessionWrite(key, value) {
        try {
            window.sessionStorage.setItem(key, JSON.stringify(value));
        } catch (e) { /* private mode / quota — non fatal */ }
    }

    function sessionRemove(key) {
        try { window.sessionStorage.removeItem(key); } catch (e) { /* ignore */ }
    }

    function clear(node) {
        while (node.firstChild) { node.removeChild(node.firstChild); }
    }

    function element(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = text; }
        return node;
    }

    /* ---------------------------------------------------------- attribution */
    var ATTRIBUTION_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid'];

    function captureAttribution() {
        var stored = sessionRead('lumera_attribution', {}) || {};
        var params = new URLSearchParams(window.location.search);
        var changed = false;

        ATTRIBUTION_KEYS.forEach(function (key) {
            var value = params.get(key);
            if (value && !stored[key]) {
                stored[key] = value.slice(0, 190);
                changed = true;
            }
        });

        if (!stored.landing_page) {
            stored.landing_page = window.location.href.slice(0, 500);
            changed = true;
        }

        if (!stored.referrer && document.referrer) {
            stored.referrer = document.referrer.slice(0, 500);
            changed = true;
        }

        // Persisted in sessionStorage so a language switch or a page reload
        // during the funnel keeps the original attribution intact.
        if (changed) { sessionWrite('lumera_attribution', stored); }

        return stored;
    }

    /* ------------------------------------------------------------ analytics */
    /**
     * Funnel instrumentation.
     *
     * Fire-and-forget by construction: sendBeacon when available, keepalive
     * fetch otherwise, and every call swallows its own errors. Nothing here is
     * awaited, so a slow or failing tracker cannot delay a step transition or a
     * lead submission. No answer value is ever sent — only step keys, which are
     * the funnel's own identifiers.
     */
    var track = {
        started: {},
        endpoint: '/api/public/analytics.php',

        context: function () {
            var attribution = sessionRead('lumera_attribution', {}) || {};

            return {
                utm_source: attribution.utm_source || null,
                utm_medium: attribution.utm_medium || null,
                utm_campaign: attribution.utm_campaign || null,
                utm_content: attribution.utm_content || null,
                utm_term: attribution.utm_term || null,
                referrer: attribution.referrer || document.referrer || null,
                landing_path: attribution.landing_page || window.location.href,
                language: state.lang,
                timezone: (function () {
                    try { return Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) { return null; }
                })()
            };
        },

        send: function (event, extra) {
            if (state.preview) { return; }

            var payload = {
                funnel_slug: state.slug,
                event: event,
                context: this.context()
            };

            if (extra) {
                Object.keys(extra).forEach(function (k) { payload[k] = extra[k]; });
            }

            var body = JSON.stringify(payload);

            try {
                if (navigator.sendBeacon) {
                    // text/plain keeps the beacon a simple request: no preflight.
                    var blob = new Blob([body], { type: 'text/plain;charset=UTF-8' });
                    if (navigator.sendBeacon(this.endpoint, blob)) { return; }
                }

                fetch(this.endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    keepalive: true,
                    body: body
                }).catch(function () { /* tracking must never surface */ });
            } catch (e) { /* tracking must never surface */ }
        },

        view: function () {
            this.send('session_start');
            this.send('funnel_view');
        },

        stepView: function (step, position) {
            this.send('step_view', { step_key: step.key, step_position: position });
        },

        /** First interaction with a step, sent once per step per session. */
        stepStart: function (step) {
            if (this.started[step.key]) { return; }
            this.started[step.key] = true;
            this.send('step_start', { step_key: step.key });
        },

        stepComplete: function (step) {
            this.send('step_complete', { step_key: step.key });
        },

        stepBack: function (step) {
            this.send('step_back', { step_key: step.key });
        },

        /** Category only — never the value the visitor typed. */
        error: function (step, reason) {
            this.send('validation_error', {
                step_key: step.key,
                reason: reason || 'invalid'
            });
        },

        complete: function () {
            this.send('funnel_complete');
        }
    };

    /** Maps a client-side message to a bounded error category. */
    function errorCategory(step, message) {
        var text = String(message || '').toLowerCase();

        if (step.type === 'consent') { return 'consent_required'; }
        if (step.type === 'single_select' || step.type === 'multi_select' || step.type === 'dropdown') {
            return 'select_required';
        }
        if (text.indexOf('email') !== -1) { return 'invalid_email'; }
        if (text.indexOf('phone') !== -1) { return 'invalid_phone'; }
        if (text.indexOf('number') !== -1) { return 'invalid_number'; }
        if (text.indexOf('at least') !== -1) { return 'min_length'; }
        if (text.indexOf('no more than') !== -1) { return 'max_length'; }
        if (text.indexOf('format') !== -1) { return 'pattern'; }

        return 'required';
    }

    /* ------------------------------------------------------ step visibility */
    /**
     * Applies the optional conditional-logic rule of each step.
     * Steps without a condition are always visible.
     */
    function visibleSteps() {
        var visible = [];

        state.steps.forEach(function (step) {
            if (!step.condition || !step.condition.parent_key || !step.condition.operator) {
                visible.push(step);
                return;
            }

            var parentAnswer = state.answers[step.condition.parent_key];
            var expected = String(step.condition.value === undefined ? '' : step.condition.value);
            var actual = Array.isArray(parentAnswer) ? parentAnswer : [parentAnswer];
            var matches;

            switch (step.condition.operator) {
                case 'equals':
                    matches = actual.some(function (v) { return String(v) === expected; });
                    break;
                case 'not_equals':
                    matches = !actual.some(function (v) { return String(v) === expected; });
                    break;
                case 'contains':
                    matches = actual.some(function (v) { return String(v).indexOf(expected) !== -1; });
                    break;
                default:
                    matches = true;
            }

            if (matches) { visible.push(step); }
        });

        return visible;
    }

    /* ------------------------------------------------------------ rendering */
    function showOnly(target) {
        [el.loading, el.error, el.form, el.success].forEach(function (node) {
            if (node) { node.hidden = node !== target; }
        });
    }

    function applyDirection() {
        var rtl = state.lang === 'ar';
        document.documentElement.lang = state.lang;
        document.documentElement.dir = rtl ? 'rtl' : 'ltr';

        document.querySelectorAll('[data-i18n]').forEach(function (node) {
            var key = node.getAttribute('data-i18n');
            if (UI[state.lang] && UI[state.lang][key]) { node.textContent = UI[state.lang][key]; }
        });

        document.querySelectorAll('.lang-switch__btn').forEach(function (btn) {
            btn.setAttribute('aria-pressed', btn.dataset.language === state.lang ? 'true' : 'false');
        });
    }

    function updateProgress(steps) {
        var ui = (state.config && state.config.funnel && state.config.funnel.ui) || {};

        if (!ui.progress_bar && !ui.step_counter) {
            el.progress.hidden = true;
            return;
        }

        el.progress.hidden = false;

        var total = steps.length;
        var current = Math.min(state.index + 1, total);
        // Progress is always derived from the live step count.
        var percent = total > 0 ? Math.round((current / total) * 100) : 0;

        if (ui.progress_bar) {
            el.progressFill.parentElement.hidden = false;
            el.progressFill.style.width = percent + '%';
            el.progressFill.parentElement.setAttribute('aria-valuenow', String(percent));
        } else {
            el.progressFill.parentElement.hidden = true;
        }

        el.counter.textContent = ui.step_counter
            ? t('step_counter', { current: current, total: total })
            : '';
    }

    function render() {
        var steps = visibleSteps();

        if (steps.length === 0) {
            showError(t('error_text'));
            return;
        }

        if (state.index >= steps.length) { state.index = steps.length - 1; }
        if (state.index < 0) { state.index = 0; }

        var step = steps[state.index];

        showOnly(el.form);
        clear(el.container);
        hideStepError();

        // A step image is optional for every step type. When absent, nothing is
        // added to the DOM at all — no placeholder, no spacing change.
        var hasImage = typeof step.image === 'string' && step.image !== '';
        el.container.classList.toggle('step--has-image', hasImage);

        if (hasImage) {
            var figure = element('figure', 'step__media');
            var image = document.createElement('img');

            image.className = 'step__image';
            image.src = step.image;
            image.alt = '';                 // decorative: the question carries the meaning
            image.decoding = 'async';
            // The first step is above the fold; later ones can load lazily.
            image.loading = state.index === 0 ? 'eager' : 'lazy';

            figure.appendChild(image);
            el.container.appendChild(figure);
        }

        var content = element('div', 'step__content');

        var heading = element('h1', 'step__title', localized(step.title));

        if (step.required) {
            heading.appendChild(element('span', 'step__required-hint', '*'));
        }

        content.appendChild(heading);

        var description = localized(step.description);

        if (description) {
            content.appendChild(element('p', 'step__description', description));
        }

        el.container.appendChild(content);

        renderBody(step, content);

        var ui = (state.config.funnel && state.config.funnel.ui) || {};
        el.back.hidden = !(ui.back_button !== false && state.index > 0);
        el.back.textContent = t('back');

        var isLast = state.index === steps.length - 1;
        el.next.disabled = false;
        el.next.textContent = isLast
            ? (localized((state.config.funnel.labels || {}).submit) || t('submit'))
            : t('next');

        updateProgress(steps);

        track.stepView(step, state.index + 1);

        var focusable = el.container.querySelector('input, select, textarea, button');
        if (focusable && window.innerWidth > 700) { focusable.focus(); }
    }

    /**
     * Renders the answer controls into `host` — the text column, which sits
     * beside the step image on wide screens and below it on mobile.
     */
    function renderBody(step, host) {
        switch (step.type) {
            case 'single_select':
                renderOptions(step, false, host);
                break;
            case 'multi_select':
                renderOptions(step, true, host);
                break;
            case 'dropdown':
                renderDropdown(step, host);
                break;
            case 'contact_information':
                renderContact(step, host);
                break;
            case 'consent':
                renderConsent(step, host);
                break;
            case 'information':
                renderInformation(step, host);
                break;
            case 'email':
                renderInput(step, 'email', host);
                break;
            case 'phone':
                renderInput(step, 'tel', host);
                break;
            case 'number':
                renderInput(step, 'number', host);
                break;
            default:
                renderInput(step, 'text', host);
        }
    }

    function renderOptions(step, multi, host) {
        var options = step.options || [];
        var wrap = element('div', 'options' + (multi ? ' options--multi' : '') + (options.length > 3 ? ' options--grid' : ''));
        var current = state.answers[step.key];

        options.forEach(function (option) {
            var selected = multi
                ? Array.isArray(current) && current.indexOf(option.value) !== -1
                : current === option.value;

            var button = element('button', 'option' + (selected ? ' is-selected' : ''));
            button.type = 'button';
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
            button.dataset.value = option.value;

            button.appendChild(element('span', 'option__marker'));

            if (option.icon) {
                button.appendChild(element('span', 'option__icon', option.icon));
            }

            button.appendChild(element('span', 'option__label', localized(option.label)));

            button.addEventListener('click', function () {
                track.stepStart(step);

                if (multi) {
                    var list = Array.isArray(state.answers[step.key]) ? state.answers[step.key].slice() : [];
                    var at = list.indexOf(option.value);

                    if (at === -1) { list.push(option.value); } else { list.splice(at, 1); }

                    state.answers[step.key] = list;
                    persistAnswers();
                    render();
                    return;
                }

                state.answers[step.key] = option.value;
                persistAnswers();
                hideStepError();

                wrap.querySelectorAll('.option').forEach(function (node) {
                    var isMe = node === button;
                    node.classList.toggle('is-selected', isMe);
                    node.setAttribute('aria-pressed', isMe ? 'true' : 'false');
                });

                if (step.auto_advance) {
                    window.setTimeout(goNext, 220);
                }
            });

            wrap.appendChild(button);
        });

        host.appendChild(wrap);
    }

    function renderDropdown(step, host) {
        var field = element('div', 'field');
        var select = document.createElement('select');
        select.className = 'field__control';
        select.id = 'field-' + step.key;

        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = localized(step.placeholder) || t('select_placeholder');
        select.appendChild(placeholder);

        (step.options || []).forEach(function (option) {
            var node = document.createElement('option');
            node.value = option.value;
            node.textContent = localized(option.label);
            if (state.answers[step.key] === option.value) { node.selected = true; }
            select.appendChild(node);
        });

        select.addEventListener('change', function () {
            track.stepStart(step);
            state.answers[step.key] = select.value;
            persistAnswers();
            hideStepError();
            if (step.auto_advance && select.value) { window.setTimeout(goNext, 180); }
        });

        field.appendChild(select);
        host.appendChild(field);
    }

    function renderInput(step, inputType, host) {
        var field = element('div', 'field');
        var input = document.createElement('input');

        input.type = inputType;
        input.className = 'field__control';
        input.id = 'field-' + step.key;
        input.placeholder = localized(step.placeholder);
        input.value = state.answers[step.key] === undefined ? '' : state.answers[step.key];
        input.autocomplete = inputType === 'email' ? 'email' : (inputType === 'tel' ? 'tel' : 'off');

        var v = step.validation || {};
        if (v.max_length) { input.maxLength = v.max_length; }
        if (inputType === 'number') {
            if (v.min_value !== null && v.min_value !== undefined) { input.min = v.min_value; }
            if (v.max_value !== null && v.max_value !== undefined) { input.max = v.max_value; }
        }

        input.addEventListener('input', function () {
            track.stepStart(step);
            state.answers[step.key] = input.value;
            persistAnswers();
            hideStepError();
        });

        field.appendChild(input);
        host.appendChild(field);
    }

    function renderConsent(step, host) {
        var label = element('label', 'consent' + (state.answers[step.key] ? ' is-selected' : ''));
        var input = document.createElement('input');

        input.type = 'checkbox';
        input.checked = state.answers[step.key] === true;
        input.id = 'field-' + step.key;

        input.addEventListener('change', function () {
            track.stepStart(step);
            state.answers[step.key] = input.checked;
            label.classList.toggle('is-selected', input.checked);
            persistAnswers();
            hideStepError();
        });

        label.appendChild(input);
        // The consent wording itself is the step title, so use the description
        // when present and fall back to the title.
        label.appendChild(element('span', 'consent__text', localized(step.description) || localized(step.title)));

        host.appendChild(label);
    }

    function renderInformation(step, host) {
        var body = localized(step.description);

        if (body) {
            host.appendChild(element('div', 'information-body', body));
        }
    }

    function renderContact(step, host) {
        var fields = step.fields || [];
        var values = state.answers[step.key] || {};
        var wrap = element('div', 'contact-fields');

        // Country code + phone share a row when both are active.
        var hasCountry = fields.some(function (f) { return f.key === 'country_code'; });
        var pendingRow = null;

        fields.forEach(function (field) {
            var node = buildContactField(step, field, values);

            if (hasCountry && field.key === 'country_code') {
                pendingRow = element('div', 'field-row');
                pendingRow.appendChild(node);
                wrap.appendChild(pendingRow);
                return;
            }

            if (pendingRow && field.key === 'phone') {
                pendingRow.appendChild(node);
                pendingRow = null;
                return;
            }

            pendingRow = null;
            wrap.appendChild(node);
        });

        host.appendChild(wrap);
    }

    function buildContactField(step, field, values) {
        var wrapper = element('div', 'field');
        var id = 'contact-' + field.key;

        var label = element('label', 'field__label', localized(field.label));
        label.htmlFor = id;

        if (field.required) {
            label.appendChild(element('span', 'field__required', ' *'));
        }

        wrapper.appendChild(label);

        var control;

        if (field.type === 'select') {
            control = document.createElement('select');
            control.appendChild(new Option(t('select_placeholder'), ''));

            (field.choices || []).forEach(function (choice) {
                var option = new Option(
                    state.lang === 'ar' ? (choice.label_ar || choice.label_en) : choice.label_en,
                    choice.value
                );
                control.appendChild(option);
            });
        } else if (field.key === 'country_code') {
            control = document.createElement('select');
            var codes = COUNTRY_CODES.slice();
            var existing = values[field.key];

            if (existing && codes.indexOf(existing) === -1) { codes.unshift(existing); }

            codes.forEach(function (code) { control.appendChild(new Option(code, code)); });

            if (!values[field.key]) {
                values[field.key] = codes[0];
                state.answers[step.key] = values;
            }
        } else {
            control = document.createElement('input');
            control.type = field.type === 'email' ? 'email' : (field.type === 'tel' ? 'tel' : 'text');
            control.placeholder = localized(field.placeholder);
            control.autocomplete = field.key === 'full_name' ? 'name'
                : (field.key === 'email' ? 'email' : (field.key === 'phone' ? 'tel' : 'off'));

            if (field.max_length) { control.maxLength = field.max_length; }
        }

        control.className = 'field__control';
        control.id = id;
        control.dataset.fieldKey = field.key;

        if (values[field.key] !== undefined && values[field.key] !== null) {
            control.value = values[field.key];
        }

        control.addEventListener('input', function () { storeContact(step, field.key, control.value); });
        control.addEventListener('change', function () { storeContact(step, field.key, control.value); });

        wrapper.appendChild(control);
        wrapper.appendChild(element('p', 'field__error', ''));

        return wrapper;
    }

    function storeContact(step, key, value) {
        track.stepStart(step);
        var current = state.answers[step.key] || {};
        current[key] = value;
        state.answers[step.key] = current;
        persistAnswers();
        hideStepError();
    }

    /* ----------------------------------------------------------- validation */
    /** Mirrors the server rules; the server remains authoritative. */
    function validateStep(step) {
        clearFieldErrors();

        var value = state.answers[step.key];
        var v = step.validation || {};
        var custom = localized(v.message);

        if (step.type === 'information') { return null; }

        if (step.type === 'consent') {
            return (step.required && value !== true) ? (custom || t('consent_required')) : null;
        }

        if (step.type === 'single_select' || step.type === 'dropdown') {
            return (step.required && !value) ? (custom || t('select_required')) : null;
        }

        if (step.type === 'multi_select') {
            return (step.required && (!Array.isArray(value) || value.length === 0))
                ? (custom || t('select_required')) : null;
        }

        if (step.type === 'contact_information') {
            return validateContact(step);
        }

        var text = (value === undefined || value === null) ? '' : String(value).trim();

        if (text === '') { return step.required ? (custom || t('required')) : null; }

        if (step.type === 'email' && !isEmail(text)) { return custom || t('invalid_email'); }
        if (step.type === 'phone' && !/^[0-9+\-\s().]{6,20}$/.test(text)) { return custom || t('invalid_phone'); }

        if (step.type === 'number') {
            if (isNaN(Number(text))) { return custom || t('invalid_number'); }
            if (v.min_value !== null && v.min_value !== undefined && Number(text) < v.min_value) {
                return custom || t('min_value', { n: v.min_value });
            }
            if (v.max_value !== null && v.max_value !== undefined && Number(text) > v.max_value) {
                return custom || t('max_value', { n: v.max_value });
            }
            return null;
        }

        if (v.min_length && text.length < v.min_length) { return custom || t('min_length', { n: v.min_length }); }
        if (v.max_length && text.length > v.max_length) { return custom || t('max_length', { n: v.max_length }); }
        if (v.pattern && !safeTest(v.pattern, text)) { return custom || t('pattern'); }

        return null;
    }

    function validateContact(step) {
        var values = state.answers[step.key] || {};
        var firstError = null;

        (step.fields || []).forEach(function (field) {
            var raw = values[field.key];
            var text = (raw === undefined || raw === null) ? '' : String(raw).trim();
            var message = null;

            if (text === '') {
                if (field.required) { message = localized(field.label) + ' — ' + t('required'); }
            } else if (field.type === 'email' && !isEmail(text)) {
                message = t('invalid_email');
            } else if (field.type === 'tel' && !/^[0-9+\-\s().]{5,20}$/.test(text)) {
                message = t('invalid_phone');
            } else if (field.min_length && text.length < field.min_length) {
                message = t('min_length', { n: field.min_length });
            } else if (field.max_length && text.length > field.max_length) {
                message = t('max_length', { n: field.max_length });
            } else if (field.pattern && !safeTest(field.pattern, text)) {
                message = t('pattern');
            }

            if (message) {
                showFieldError(field.key, message);
                if (!firstError) { firstError = message; }
            }
        });

        return firstError;
    }

    function isEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value);
    }

    function safeTest(pattern, value) {
        try {
            return new RegExp(pattern).test(value);
        } catch (e) {
            return true; // a broken admin pattern must not block the visitor
        }
    }

    function showFieldError(fieldKey, message) {
        var control = el.container.querySelector('[data-field-key="' + fieldKey + '"]');
        if (!control) { return; }

        control.setAttribute('aria-invalid', 'true');
        var target = control.parentElement && control.parentElement.querySelector('.field__error');
        if (target) { target.textContent = message; }
    }

    function clearFieldErrors() {
        el.container.querySelectorAll('.field__error').forEach(function (node) { node.textContent = ''; });
        el.container.querySelectorAll('[aria-invalid]').forEach(function (node) { node.removeAttribute('aria-invalid'); });
    }

    function showStepError(message) {
        el.stepError.textContent = message;
        el.stepError.hidden = false;
    }

    function hideStepError() {
        el.stepError.textContent = '';
        el.stepError.hidden = true;
    }

    /* ------------------------------------------------------------ navigation */
    function goNext() {
        var steps = visibleSteps();
        var step = steps[state.index];
        if (!step) { return; }

        var error = validateStep(step);

        if (error) {
            track.error(step, errorCategory(step, error));
            showStepError(error);
            return;
        }

        hideStepError();
        track.stepComplete(step);

        if (state.index === steps.length - 1) {
            submit();
            return;
        }

        state.index += 1;
        persistAnswers();
        render();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function goBack() {
        if (state.index === 0) { return; }

        var steps = visibleSteps();
        if (steps[state.index]) { track.stepBack(steps[state.index]); }

        state.index -= 1;
        persistAnswers();
        render();
    }

    function persistAnswers() {
        var ui = (state.config && state.config.funnel && state.config.funnel.ui) || {};
        if (ui.save_progress === false) { return; }

        sessionWrite(storageKey('answers'), { index: state.index, answers: state.answers, version: state.config ? state.config.version : 0 });
    }

    function restoreAnswers() {
        var ui = (state.config.funnel && state.config.funnel.ui) || {};
        if (ui.save_progress === false) { return; }

        var saved = sessionRead(storageKey('answers'), null);
        if (!saved || typeof saved !== 'object') { return; }

        // A republished funnel invalidates saved progress.
        if (saved.version !== state.config.version) {
            sessionRemove(storageKey('answers'));
            return;
        }

        if (saved.answers && typeof saved.answers === 'object') { state.answers = saved.answers; }
        if (typeof saved.index === 'number' && saved.index >= 0) { state.index = saved.index; }
    }

    /* ---------------------------------------------------------------- submit */
    function submit() {
        if (state.submitting) { return; }

        if (state.preview) {
            showSuccess({
                title: localized((state.config.funnel.labels || {}).success_title) || 'Thank you',
                message: t('preview_note')
            });
            return;
        }

        state.submitting = true;
        el.next.disabled = true;
        el.next.textContent = t('submitting');

        var payload = {
            funnel_slug: state.slug,
            language: state.lang,
            answers: state.answers,
            attribution: captureAttribution(),
            meta: { screen_size: window.innerWidth + 'x' + window.innerHeight },
            submission_token: state.submissionToken,
            csrf_token: state.csrf,
            company_website: el.honeypot ? el.honeypot.value : ''
        };

        fetch('/api/public/submit-lead.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json()
                .catch(function () { return null; })
                .then(function (data) {
                    return { ok: response.ok, status: response.status, data: data };
                });
        }).then(function (result) {
            var data = result.data;
            var leadId = data ? Number(data.lead_id) : NaN;

            // The Thank You screen requires PROOF of persistence, never the
            // mere presence of a JSON body: a 2xx status, an explicit
            // success flag, and a real lead id the server committed.
            var persisted = result.ok
                && !!data
                && data.success === true
                && !isNaN(leadId)
                && leadId > 0;

            if (persisted) {
                // Only after the server confirms the lead was stored. The
                // authoritative completion is recorded server-side when the
                // lead is linked; this event records the client's view of it.
                track.complete();
                sessionRemove(storageKey('answers'));
                showSuccess(data.screen || {});
                return;
            }

            handleSubmitFailure(result);
        }).catch(function () {
            // Network or parse failure: nothing was confirmed, so nothing is
            // celebrated. The answers stay put and the visitor can retry.
            resetSubmitButton();
            showStepError(t('generic_error'));
        });
    }

    function handleSubmitFailure(result) {
        resetSubmitButton();

        var data = result.data || {};
        var currentSteps = visibleSteps();

        if (currentSteps[state.index]) {
            track.error(currentSteps[state.index], data.errors ? 'invalid' : 'server');
        }

        if (data.errors) {
            var steps = visibleSteps();
            var firstKey = Object.keys(data.errors)[0];
            var stepKey = firstKey.split('.')[0];
            var target = steps.findIndex(function (s) { return s.key === stepKey; });

            if (target !== -1) {
                state.index = target;
                render();
            }

            showStepError(data.errors[firstKey]);
            return;
        }

        showStepError(data.message || data.error || t('generic_error'));

        // An expired session or a consumed token needs fresh tokens before the
        // visitor can retry. The answers they already gave are untouched.
        if (result.status === 419 || result.status === 409 || result.status >= 500) {
            refreshSession().then(function () {
                // Re-arm the submit button with the refreshed token in place.
                resetSubmitButton();
            });
        }
    }

    function resetSubmitButton() {
        state.submitting = false;
        el.next.disabled = false;
        el.next.textContent = localized((state.config.funnel.labels || {}).submit) || t('submit');
    }

    function showSuccess(success) {
        showOnly(el.success);
        el.progress.hidden = true;

        var labels = (state.config.funnel.labels || {});

        el.successTitle.textContent = success.title
            || localized(labels.success_title)
            || 'Thank you';

        el.successMessage.textContent = success.message
            || localized(labels.success_message)
            || '';

        var whatsapp = success.whatsapp || state.config.whatsapp;

        if (whatsapp && whatsapp.number) {
            var text = whatsapp.message ? ('?text=' + encodeURIComponent(whatsapp.message)) : '';
            el.whatsapp.href = 'https://wa.me/' + whatsapp.number + text;
            el.whatsapp.textContent = localized(whatsapp.label) || 'WhatsApp';
            el.whatsapp.hidden = false;
        }

        // Optional redirect: the button appears immediately, the automatic jump
        // is announced and delayed so the visitor can read the confirmation.
        var redirect = success.redirect || (state.config.funnel.redirect && state.config.funnel.redirect.url
            ? state.config.funnel.redirect : null);
        var buttonLabel = success.button || localized(labels.success_button) || '';

        if (redirect && redirect.url) {
            el.successCta.href = redirect.url;
            el.successCta.textContent = buttonLabel || t('continue');
            el.successCta.rel = 'noopener';
            el.successCta.hidden = false;

            var delay = Math.max(0, parseInt(redirect.delay, 10) || 0);

            if (delay > 0 && !state.preview) {
                el.redirectNote.textContent = t('redirecting', { n: delay });
                el.redirectNote.hidden = false;

                window.setTimeout(function () {
                    window.location.href = redirect.url;
                }, delay * 1000);
            }
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    /* ----------------------------------------------------------------- boot */
    function showError(message) {
        showOnly(el.error);
        el.progress.hidden = true;
        el.errorText.textContent = message || t('error_text');
    }

    function refreshSession() {
        return fetch('/api/public/session.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok) {
                    state.csrf = data.csrf_token;
                    state.submissionToken = data.submission_token;
                }
            })
            .catch(function () { /* keep the server-rendered tokens */ });
    }

    function loadConfig() {
        showOnly(el.loading);

        var url = '/api/public/funnel.php?slug=' + encodeURIComponent(state.slug)
            + (state.preview ? '&preview=1' : '');

        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                return response.json().then(function (data) { return { status: response.status, data: data }; });
            })
            .then(function (result) {
                if (!result.data || !result.data.ok || !result.data.config) {
                    showError((result.data && result.data.error) || t('error_text'));
                    return;
                }

                state.config = result.data.config;
                state.steps = state.config.steps || [];

                if (state.steps.length === 0) {
                    showError(t('error_text'));
                    return;
                }

                var funnelLanguages = (state.config.funnel && state.config.funnel.languages) || state.languages;
                if (funnelLanguages.indexOf(state.lang) === -1) {
                    state.lang = funnelLanguages[0] || 'en';
                }

                applyBranding();
                restoreAnswers();
                applyDirection();

                // Before the first render, so the recorded timeline opens with
                // the visit rather than with the first step it happened to show.
                // sendBeacon only queues, so this costs the paint nothing.
                track.view();
                render();
            })
            .catch(function () {
                showError(t('error_text'));
            });
    }

    /**
     * Applies the funnel's own branding. Nothing here is hardcoded to a
     * particular company: colours, logo, favicon and company name all come from
     * the published configuration.
     */
    function applyBranding() {
        var funnel = state.config.funnel || {};
        var branding = state.config.branding || {};
        var theme = funnel.theme || {};
        var style = document.documentElement.style;

        if (theme.primary) { style.setProperty('--brand-primary', theme.primary); }
        if (theme.secondary || theme.accent) {
            style.setProperty('--brand-secondary', theme.secondary || theme.accent);
            style.setProperty('--brand-accent', theme.secondary || theme.accent);
        }
        if (theme.background) { style.setProperty('--brand-background', theme.background); }

        var company = branding.company_name || funnel.company_name || '';
        var logo = branding.company_logo || theme.logo || '';

        if (company) {
            document.title = (funnel.name ? funnel.name + ' — ' : '') + company;
            if (el.company) { el.company.textContent = company; }
            if (el.wordmark) { el.wordmark.textContent = company; }
            if (el.logo) { el.logo.alt = company; }
        }

        // The server already renders the logo; this keeps the preview in step
        // when the administrator changes it without reloading.
        if (logo && el.brand) {
            if (el.logo) {
                el.logo.src = logo;
            } else {
                var img = document.createElement('img');
                img.className = 'funnel__logo';
                img.src = logo;
                img.alt = company;
                clear(el.brand);
                el.brand.appendChild(img);
                el.logo = img;
            }
        }

        var favicon = branding.favicon || theme.favicon;

        if (favicon) {
            var link = document.querySelector('link[rel="icon"]');

            if (link) { link.href = favicon; }
        }

        var privacyUrl = branding.privacy_policy_url || funnel.privacy_policy_url;

        if (privacyUrl) {
            el.privacy.href = privacyUrl;
            el.privacy.hidden = false;
            el.privacy.rel = 'noopener noreferrer';
            el.privacy.target = '_blank';
        }
    }

    function bindEvents() {
        el.form.addEventListener('submit', function (event) {
            event.preventDefault();
            goNext();
        });

        el.back.addEventListener('click', goBack);
        el.retry.addEventListener('click', function () { loadConfig(); });

        document.querySelectorAll('.lang-switch__btn').forEach(function (button) {
            button.addEventListener('click', function () {
                state.lang = button.dataset.language;
                applyDirection();

                // Answers and attribution survive the language switch.
                if (state.config) { render(); }
            });
        });
    }

    captureAttribution();
    bindEvents();
    applyDirection();
    loadConfig();
})();
