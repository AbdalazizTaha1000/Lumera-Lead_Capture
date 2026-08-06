/* =========================================================================
 * Lumera — Funnel Builder
 *
 * Every step, option and contact field is edited here and written straight to
 * the draft tables. Nothing reaches the public funnel until "Publish Changes"
 * writes an immutable snapshot.
 * ========================================================================= */
(function () {
    'use strict';

    var L = window.Lumera;
    if (!L) { return; }

    var $ = L.$, $$ = L.$$, el = L.el, clear = L.clear, api = L.api, toast = L.toast;

    var state = {
        funnel: null,
        steps: [],
        contactFields: [],
        status: null,
        meta: null,
        selectedStepId: null,
        dirty: false
    };

    /* ============================================================== view */
    L.views.builder = {
        title: 'Funnel Builder',
        subtitle: 'Create, order and publish the questions visitors see',
        actions: function () {
            return [
                L.button('Preview', 'btn--ghost btn--sm', openPreview),
                L.button('Publish Changes', 'btn--gold btn--sm', publish)
            ];
        },
        render: load
    };

    function load() {
        return api('/api/admin/funnel.php?funnel_id=' + L.funnelId).then(function (data) {
            state.funnel = data.funnel;
            state.steps = data.steps || [];
            state.contactFields = data.contact_fields || [];
            state.status = data.status || {};
            state.meta = data.meta || {};

            if (state.selectedStepId === null && state.steps.length > 0) {
                state.selectedStepId = parseInt(state.steps[0].id, 10);
            }

            renderPublishBar();
            renderStepList();
            renderEditor();
            renderContactFields();
            renderFunnelSettings();
        });
    }

    function reload() {
        return load().catch(function (error) { toast(error.message, 'error'); });
    }

    /* ======================================================== publish bar */
    function renderPublishBar() {
        var host = $('#publish-bar');
        clear(host);

        var meta = el('div', 'publish-bar__meta');

        function metaItem(label, value, variant) {
            var wrap = el('span');
            wrap.appendChild(document.createTextNode(label + ': '));

            if (variant) {
                wrap.appendChild(L.badge(value, variant));
            } else {
                wrap.appendChild(el('strong', null, value));
            }

            return wrap;
        }

        var version = state.status.published_version > 0 ? 'v' + state.status.published_version : 'not published';
        meta.appendChild(metaItem('Published version', version));
        meta.appendChild(metaItem('Last published', L.formatDate(state.status.published_at)));
        meta.appendChild(metaItem(
            'Draft',
            state.status.has_unpublished ? 'unpublished changes' : 'in sync',
            state.status.has_unpublished ? 'warning' : 'success'
        ));
        meta.appendChild(metaItem('Next version', 'v' + state.status.next_version));

        host.appendChild(meta);

        var actions = el('div', 'publish-bar__actions');
        actions.appendChild(L.button('Preview draft', 'btn--ghost btn--sm', openPreview));
        actions.appendChild(L.button('Publish Changes', 'btn--gold btn--sm', publish));
        host.appendChild(actions);

        if ((state.status.publish_blockers || []).length > 0) {
            var blockers = el('ul', 'publish-blockers');

            state.status.publish_blockers.forEach(function (message) {
                blockers.appendChild(el('li', null, message));
            });

            host.appendChild(blockers);
        }
    }

    function openPreview() {
        window.open('/admin/preview.php?slug=' + encodeURIComponent(L.funnelSlug), '_blank', 'noopener');
    }

    function publish() {
        L.confirmAction(
            'Publish changes',
            'This makes the current draft live for every visitor. A new immutable version is recorded and the previous version is kept.',
            'Publish'
        ).then(function (confirmed) {
            if (!confirmed) { return; }

            api('/api/admin/publish.php', { method: 'POST', body: { funnel_id: L.funnelId } })
                .then(function (data) {
                    toast('Published version ' + data.published.version + '.', 'success');
                    state.dirty = false;
                    reload();
                })
                .catch(function (error) {
                    if (error.data && error.data.blockers) {
                        toast(error.data.blockers[0], 'error');
                        reload();
                        return;
                    }
                    toast(error.message, 'error');
                });
        });
    }

    /* ========================================================== step list */
    function renderStepList() {
        var host = $('#step-list');
        clear(host);

        if (state.steps.length === 0) {
            var empty = el('li');
            empty.appendChild(L.emptyState('No steps yet', 'Add your first step to start building the funnel.'));
            host.appendChild(empty);
            return;
        }

        state.steps.forEach(function (step, index) {
            host.appendChild(stepCard(step, index));
        });

        makeSortable(host, '.step-card', function (orderedIds) {
            api('/api/admin/steps.php', {
                method: 'POST',
                body: { action: 'reorder', funnel_id: L.funnelId, order: orderedIds }
            }).then(function () {
                toast('Step order saved.', 'success');
                reload();
            }).catch(function (error) {
                toast(error.message, 'error');
                reload();
            });
        });
    }

    function stepCard(step, index) {
        var item = el('li');
        var card = el('div', 'step-card'
            + (parseInt(step.id, 10) === state.selectedStepId ? ' is-selected' : '')
            + (parseInt(step.is_active, 10) === 1 ? '' : ' is-inactive'));

        card.dataset.id = step.id;
        card.draggable = true;

        var handle = el('span', 'step-card__handle', '⠿');
        handle.title = 'Drag to reorder';
        card.appendChild(handle);

        var main = el('div', 'step-card__main');

        var titleRow = el('div', 'step-card__title');
        titleRow.appendChild(el('span', 'step-card__index', (index + 1) + '. '));
        titleRow.appendChild(document.createTextNode(step.title_en || step.step_key));
        main.appendChild(titleRow);

        var meta = el('div', 'step-card__meta');
        meta.appendChild(L.badge(step.type_label || step.step_type, 'muted'));

        if (parseInt(step.is_required, 10) === 1) { meta.appendChild(L.badge('required', 'info')); }

        meta.appendChild(L.badge(
            parseInt(step.is_active, 10) === 1 ? 'active' : 'inactive',
            parseInt(step.is_active, 10) === 1 ? 'success' : 'muted'
        ));

        main.appendChild(meta);
        card.appendChild(main);

        var tools = el('div', 'step-card__tools');

        tools.appendChild(L.miniButton('▲', 'Move up', null, function (event) {
            event.stopPropagation();
            moveStep(step.id, 'up');
        }));

        tools.appendChild(L.miniButton('▼', 'Move down', null, function (event) {
            event.stopPropagation();
            moveStep(step.id, 'down');
        }));

        tools.appendChild(L.miniButton('⧉', 'Duplicate', null, function (event) {
            event.stopPropagation();
            duplicateStep(step.id);
        }));

        tools.appendChild(L.miniButton('✕', 'Delete', 'mini-button--danger', function (event) {
            event.stopPropagation();
            deleteStep(step);
        }));

        card.appendChild(tools);

        card.addEventListener('click', function () {
            state.selectedStepId = parseInt(step.id, 10);
            renderStepList();
            renderEditor();
        });

        item.appendChild(card);

        return item;
    }

    function moveStep(stepId, direction) {
        api('/api/admin/steps.php', {
            method: 'POST',
            body: { action: 'move', funnel_id: L.funnelId, step_id: stepId, direction: direction }
        }).then(reload).catch(function (error) { toast(error.message, 'error'); });
    }

    function duplicateStep(stepId) {
        api('/api/admin/steps.php', {
            method: 'POST',
            body: { action: 'duplicate', funnel_id: L.funnelId, step_id: stepId }
        }).then(function (data) {
            state.selectedStepId = parseInt(data.step.id, 10);
            toast('Step duplicated (created inactive).', 'success');
            reload();
        }).catch(function (error) { toast(error.message, 'error'); });
    }

    function deleteStep(step) {
        L.confirmAction(
            'Delete step',
            'The step "' + (step.title_en || step.step_key) + '" and its options will be removed from the draft. '
            + 'Existing leads keep their recorded answers and labels.',
            'Delete step'
        ).then(function (confirmed) {
            if (!confirmed) { return; }

            api('/api/admin/steps.php', {
                method: 'POST',
                body: { action: 'delete', funnel_id: L.funnelId, step_id: step.id }
            }).then(function () {
                if (state.selectedStepId === parseInt(step.id, 10)) { state.selectedStepId = null; }
                toast('Step deleted from the draft.', 'success');
                reload();
            }).catch(function (error) { toast(error.message, 'error'); });
        });
    }

    /* ============================================================= editor */
    function selectedStep() {
        return state.steps.filter(function (s) { return parseInt(s.id, 10) === state.selectedStepId; })[0] || null;
    }

    function renderEditor() {
        var host = $('#step-editor');
        clear(host);

        var step = selectedStep();

        if (!step) {
            host.appendChild(L.emptyState('Select a step', 'Choose a step on the left to edit its content, settings and options.'));
            return;
        }

        var head = el('div', 'editor__head');
        var titleWrap = el('div');
        titleWrap.appendChild(el('h2', 'panel__title', step.title_en || step.step_key));
        titleWrap.appendChild(el('p', 'editor__key', 'key: ' + step.step_key + ' · type: ' + step.step_type));
        head.appendChild(titleWrap);

        var headActions = el('div', 'filters__actions');
        headActions.appendChild(L.button(
            parseInt(step.is_active, 10) === 1 ? 'Deactivate' : 'Activate',
            'btn--ghost btn--sm',
            function () { toggleStep(step); }
        ));
        head.appendChild(headActions);
        host.appendChild(head);

        var form = el('div');
        var fields = {};

        /* --- language tabs --- */
        var tabs = el('div', 'tabs');
        var panels = el('div');

        [['en', 'English content'], ['ar', 'Arabic content']].forEach(function (pair, index) {
            var tab = el('button', 'tab' + (index === 0 ? ' is-active' : ''), pair[1]);
            tab.type = 'button';

            tab.addEventListener('click', function () {
                $$('.tab', tabs).forEach(function (t) { t.classList.toggle('is-active', t === tab); });
                $$('.tab-panel', panels).forEach(function (p) { p.hidden = p.dataset.panel !== pair[0]; });
            });

            tabs.appendChild(tab);
        });

        form.appendChild(tabs);

        ['en', 'ar'].forEach(function (lang) {
            var panel = el('div', 'tab-panel');
            panel.dataset.panel = lang;
            panel.hidden = lang !== 'en';

            fields['title_' + lang] = L.input('step-title-' + lang, step['title_' + lang]);
            panel.appendChild(L.formGroup(lang === 'en' ? 'Title (English)' : 'Title (Arabic)', fields['title_' + lang]));

            fields['description_' + lang] = L.textarea('step-desc-' + lang, step['description_' + lang], 3);
            panel.appendChild(L.formGroup('Description', fields['description_' + lang]));

            fields['placeholder_' + lang] = L.input('step-ph-' + lang, step['placeholder_' + lang]);
            panel.appendChild(L.formGroup('Placeholder', fields['placeholder_' + lang]));

            fields['validation_message_' + lang] = L.input('step-vm-' + lang, step['validation_message_' + lang]);
            panel.appendChild(L.formGroup('Custom validation message', fields['validation_message_' + lang]));

            panels.appendChild(panel);
        });

        form.appendChild(panels);

        /* --- step settings --- */
        var settings = el('div', 'editor__section');
        settings.appendChild(el('p', 'editor__section-title', 'Step settings'));

        var settingsRow = el('div', 'form-row form-row--2');

        fields.step_key = L.input('step-key', step.step_key);
        settingsRow.appendChild(L.formGroup('Internal key', fields.step_key,
            'Language independent. Used by the API, exports and lead records.'));

        var typeOptions = Object.keys(state.meta.step_types || {}).map(function (value) {
            return { value: value, label: state.meta.step_types[value] };
        });

        fields.step_type = L.select('step-type', typeOptions, step.step_type);
        settingsRow.appendChild(L.formGroup('Step type', fields.step_type));

        settings.appendChild(settingsRow);

        var flags = el('div', 'form-row form-row--3');
        var requiredBox = L.checkbox('step-required', 'Required', parseInt(step.is_required, 10) === 1);
        var activeBox = L.checkbox('step-active', 'Active', parseInt(step.is_active, 10) === 1);
        var advanceBox = L.checkbox('step-advance', 'Auto-advance on selection', parseInt(step.auto_advance, 10) === 1);

        flags.appendChild(requiredBox);
        flags.appendChild(activeBox);
        flags.appendChild(advanceBox);
        settings.appendChild(flags);

        fields.is_required = requiredBox.querySelector('input');
        fields.is_active = activeBox.querySelector('input');
        fields.auto_advance = advanceBox.querySelector('input');

        form.appendChild(settings);

        /* --- validation --- */
        var validation = el('div', 'editor__section');
        validation.appendChild(el('p', 'editor__section-title', 'Validation'));

        var lengths = el('div', 'form-row form-row--2');
        fields.min_length = L.input('step-minlen', step.min_length, 'number');
        fields.max_length = L.input('step-maxlen', step.max_length, 'number');
        lengths.appendChild(L.formGroup('Minimum length', fields.min_length));
        lengths.appendChild(L.formGroup('Maximum length', fields.max_length));
        validation.appendChild(lengths);

        var values = el('div', 'form-row form-row--2');
        fields.min_value = L.input('step-minval', step.min_value, 'number');
        fields.max_value = L.input('step-maxval', step.max_value, 'number');
        values.appendChild(L.formGroup('Minimum value', fields.min_value));
        values.appendChild(L.formGroup('Maximum value', fields.max_value));
        validation.appendChild(values);

        fields.validation_pattern = L.input('step-pattern', step.validation_pattern);
        validation.appendChild(L.formGroup('Validation pattern', fields.validation_pattern,
            'Regular expression body without delimiters, e.g. ^[A-Za-z ]+$'));

        form.appendChild(validation);

        /* --- conditional logic --- */
        var conditions = el('div', 'editor__section');
        conditions.appendChild(el('p', 'editor__section-title', 'Conditional display (optional)'));

        var conditionRow = el('div', 'form-row form-row--3');

        var parentOptions = [{ value: '', label: 'Always show' }].concat(
            state.steps
                .filter(function (s) { return parseInt(s.id, 10) !== parseInt(step.id, 10); })
                .map(function (s) { return { value: s.step_key, label: s.step_key }; })
        );

        fields.condition_parent_key = L.select('cond-parent', parentOptions, step.condition_parent_key || '');
        conditionRow.appendChild(L.formGroup('Depends on step', fields.condition_parent_key));

        var operatorOptions = [{ value: '', label: '—' }].concat(
            (state.meta.condition_operators || []).map(function (op) { return { value: op, label: op }; })
        );

        fields.condition_operator = L.select('cond-operator', operatorOptions, step.condition_operator || '');
        conditionRow.appendChild(L.formGroup('Operator', fields.condition_operator));

        fields.condition_value = L.input('cond-value', step.condition_value);
        conditionRow.appendChild(L.formGroup('Expected value', fields.condition_value));

        conditions.appendChild(conditionRow);
        form.appendChild(conditions);

        /* --- save --- */
        var saveRow = el('div', 'editor__section');
        var saveActions = el('div', 'filters__actions');

        saveActions.appendChild(L.button('Save Draft', 'btn--primary', function () {
            saveStep(step, fields);
        }));

        saveActions.appendChild(L.button('Discard changes', 'btn--ghost', function () { renderEditor(); }));
        saveRow.appendChild(saveActions);
        form.appendChild(saveRow);

        host.appendChild(form);

        /* --- options manager --- */
        if (state.meta.types_with_options && state.meta.types_with_options.indexOf(step.step_type) !== -1) {
            host.appendChild(renderOptionsManager(step));
        }

        if (step.step_type === 'contact_information') {
            var note = el('div', 'editor__section');
            note.appendChild(el('p', 'editor__section-title', 'Contact fields'));
            note.appendChild(el('p', 'form-help',
                'The fields shown on this step are managed in the "Contact fields" panel below.'));
            host.appendChild(note);
        }
    }

    function toggleStep(step) {
        api('/api/admin/steps.php', {
            method: 'POST',
            body: {
                action: 'toggle',
                funnel_id: L.funnelId,
                step_id: step.id,
                is_active: parseInt(step.is_active, 10) !== 1
            }
        }).then(function () {
            toast('Step visibility updated.', 'success');
            reload();
        }).catch(function (error) { toast(error.message, 'error'); });
    }

    function numberOrNull(value) {
        var trimmed = String(value === null || value === undefined ? '' : value).trim();
        return trimmed === '' ? null : trimmed;
    }

    function saveStep(step, fields) {
        var payload = {
            action: 'update',
            funnel_id: L.funnelId,
            step_id: step.id,
            step_key: fields.step_key.value,
            step_type: fields.step_type.value,
            title_en: fields.title_en.value,
            title_ar: fields.title_ar.value,
            description_en: fields.description_en.value,
            description_ar: fields.description_ar.value,
            placeholder_en: fields.placeholder_en.value,
            placeholder_ar: fields.placeholder_ar.value,
            validation_message_en: fields.validation_message_en.value,
            validation_message_ar: fields.validation_message_ar.value,
            is_required: fields.is_required.checked,
            is_active: fields.is_active.checked,
            auto_advance: fields.auto_advance.checked,
            min_length: numberOrNull(fields.min_length.value),
            max_length: numberOrNull(fields.max_length.value),
            min_value: numberOrNull(fields.min_value.value),
            max_value: numberOrNull(fields.max_value.value),
            validation_pattern: fields.validation_pattern.value,
            condition_parent_key: fields.condition_parent_key.value,
            condition_operator: fields.condition_operator.value,
            condition_value: fields.condition_value.value
        };

        api('/api/admin/steps.php', { method: 'POST', body: payload })
            .then(function () {
                toast('Draft saved.', 'success');
                reload();
            })
            .catch(function (error) { showErrors(error); });
    }

    function showErrors(error) {
        if (error.data && error.data.errors) {
            var messages = Object.keys(error.data.errors).map(function (key) { return error.data.errors[key]; });
            toast(messages[0], 'error');
            return;
        }

        toast(error.message, 'error');
    }

    /* ============================================================ options */
    function renderOptionsManager(step) {
        var section = el('div', 'editor__section');

        var head = el('div', 'editor__head');
        head.appendChild(el('p', 'editor__section-title', 'Options & scoring'));
        head.appendChild(L.button('Add option', 'btn--ghost btn--sm', function () { openOptionModal(step, null); }));
        section.appendChild(head);

        var options = step.options || [];

        if (options.length === 0) {
            section.appendChild(L.emptyState('No options yet', 'A selection step needs at least one active option before it can be published.'));
            return section;
        }

        var list = el('ul', 'option-list');

        options.forEach(function (option) {
            var row = el('li', 'option-row' + (parseInt(option.is_active, 10) === 1 ? '' : ' is-inactive'));
            row.dataset.id = option.id;
            row.draggable = true;

            var handle = el('span', 'step-card__handle', '⠿');
            handle.title = 'Drag to reorder';
            row.appendChild(handle);

            var labels = el('div', 'option-row__labels');
            labels.appendChild(el('div', 'option-row__en', option.label_en));

            if (option.label_ar) { labels.appendChild(el('div', 'option-row__ar', option.label_ar)); }

            labels.appendChild(el('div', 'option-row__value', option.option_value));
            row.appendChild(labels);

            row.appendChild(el('span', 'option-row__score', 'score ' + option.score));

            var tools = el('div', 'option-row__tools');

            tools.appendChild(L.miniButton('▲', 'Move up', null, function () { moveOption(option.id, 'up'); }));
            tools.appendChild(L.miniButton('▼', 'Move down', null, function () { moveOption(option.id, 'down'); }));
            tools.appendChild(L.miniButton('✎', 'Edit', null, function () { openOptionModal(step, option); }));
            tools.appendChild(L.miniButton('⧉', 'Duplicate', null, function () { duplicateOption(option.id); }));
            tools.appendChild(L.miniButton(
                parseInt(option.is_active, 10) === 1 ? '◉' : '○',
                parseInt(option.is_active, 10) === 1 ? 'Deactivate' : 'Activate',
                null,
                function () { toggleOption(option); }
            ));
            tools.appendChild(L.miniButton('✕', 'Delete', 'mini-button--danger', function () { deleteOption(option); }));

            row.appendChild(tools);
            list.appendChild(row);
        });

        section.appendChild(list);

        makeSortable(list, '.option-row', function (orderedIds) {
            api('/api/admin/options.php', {
                method: 'POST',
                body: { action: 'reorder', funnel_id: L.funnelId, step_id: step.id, order: orderedIds }
            }).then(function () {
                toast('Option order saved.', 'success');
                reload();
            }).catch(function (error) {
                toast(error.message, 'error');
                reload();
            });
        });

        return section;
    }

    function openOptionModal(step, option) {
        var isNew = !option;
        var wrap = el('div');

        var value = L.input('opt-value', option ? option.option_value : '');
        wrap.appendChild(L.formGroup('Internal value', value, 'Language independent, e.g. invest. Must be unique within the step.'));

        var row = el('div', 'form-row form-row--2');
        var labelEn = L.input('opt-en', option ? option.label_en : '');
        var labelAr = L.input('opt-ar', option ? option.label_ar : '');
        row.appendChild(L.formGroup('English label', labelEn));
        row.appendChild(L.formGroup('Arabic label', labelAr));
        wrap.appendChild(row);

        var row2 = el('div', 'form-row form-row--2');
        var icon = L.input('opt-icon', option ? option.icon : '', 'text', 'optional, e.g. 🏢');
        var score = L.input('opt-score', option ? option.score : 0, 'number');
        row2.appendChild(L.formGroup('Icon identifier', icon));
        row2.appendChild(L.formGroup('Score', score, 'Added to the lead score. Never shown to visitors.'));
        wrap.appendChild(row2);

        var metadata = L.textarea('opt-meta', option && option.metadata ? option.metadata : '', 2);
        wrap.appendChild(L.formGroup('Metadata (JSON, optional)', metadata));

        var activeBox = L.checkbox('opt-active', 'Active', option ? parseInt(option.is_active, 10) === 1 : true);
        wrap.appendChild(activeBox);

        var cancel = L.button('Cancel', 'btn--ghost', function () { L.modal.close(); });

        var save = L.button(isNew ? 'Add option' : 'Save option', 'btn--primary', function () {
            var payload = {
                action: isNew ? 'create' : 'update',
                funnel_id: L.funnelId,
                step_id: step.id,
                option_value: value.value,
                label_en: labelEn.value,
                label_ar: labelAr.value,
                icon: icon.value,
                score: score.value,
                metadata: metadata.value,
                is_active: activeBox.querySelector('input').checked
            };

            if (!isNew) { payload.option_id = option.id; }

            api('/api/admin/options.php', { method: 'POST', body: payload })
                .then(function () {
                    L.modal.close();
                    toast(isNew ? 'Option added.' : 'Option saved.', 'success');
                    reload();
                })
                .catch(showErrors);
        });

        L.modal.open(isNew ? 'Add option' : 'Edit option', wrap, [cancel, save]);
    }

    function moveOption(optionId, direction) {
        api('/api/admin/options.php', {
            method: 'POST',
            body: { action: 'move', funnel_id: L.funnelId, option_id: optionId, direction: direction }
        }).then(reload).catch(function (error) { toast(error.message, 'error'); });
    }

    function duplicateOption(optionId) {
        api('/api/admin/options.php', {
            method: 'POST',
            body: { action: 'duplicate', funnel_id: L.funnelId, option_id: optionId }
        }).then(function () {
            toast('Option duplicated.', 'success');
            reload();
        }).catch(function (error) { toast(error.message, 'error'); });
    }

    function toggleOption(option) {
        api('/api/admin/options.php', {
            method: 'POST',
            body: {
                action: 'toggle',
                funnel_id: L.funnelId,
                option_id: option.id,
                is_active: parseInt(option.is_active, 10) !== 1
            }
        }).then(reload).catch(function (error) { toast(error.message, 'error'); });
    }

    function deleteOption(option) {
        L.confirmAction(
            'Delete option',
            'Remove "' + option.label_en + '" from this step? Leads that already selected it keep their stored label.',
            'Delete option'
        ).then(function (confirmed) {
            if (!confirmed) { return; }

            api('/api/admin/options.php', {
                method: 'POST',
                body: { action: 'delete', funnel_id: L.funnelId, option_id: option.id }
            }).then(function () {
                toast('Option deleted.', 'success');
                reload();
            }).catch(function (error) { toast(error.message, 'error'); });
        });
    }

    /* ===================================================== contact fields */
    function renderContactFields() {
        var panel = $('#contact-fields-panel');
        var host = $('#contact-fields');
        clear(host);

        var hasContactStep = state.steps.some(function (s) { return s.step_type === 'contact_information'; });
        panel.hidden = !hasContactStep && state.contactFields.length === 0;

        if (state.contactFields.length === 0) {
            host.appendChild(L.emptyState('No contact fields', 'Run the seed to create the default contact fields.'));
            return;
        }

        var systemKeys = state.meta.system_contact_keys || [];

        state.contactFields.forEach(function (field) {
            var row = el('div', 'contact-field-row');

            var info = el('div');
            info.appendChild(el('div', null, field.label_en || field.field_key));
            info.appendChild(el('div', 'contact-field-row__key', field.field_key + ' · ' + field.field_type));
            row.appendChild(info);

            var badges = el('div', 'step-card__meta');
            badges.appendChild(L.badge(
                parseInt(field.is_active, 10) === 1 ? 'visible' : 'hidden',
                parseInt(field.is_active, 10) === 1 ? 'success' : 'muted'
            ));

            if (parseInt(field.is_required, 10) === 1) { badges.appendChild(L.badge('required', 'info')); }
            if (systemKeys.indexOf(field.field_key) !== -1) { badges.appendChild(L.badge('system', 'gold')); }

            row.appendChild(badges);

            var tools = el('div', 'option-row__tools');
            tools.appendChild(L.button('Edit', 'btn--ghost btn--sm', function () { openContactFieldModal(field); }));
            tools.appendChild(L.button(
                parseInt(field.is_active, 10) === 1 ? 'Hide' : 'Show',
                'btn--ghost btn--sm',
                function () { toggleContactField(field); }
            ));
            row.appendChild(tools);

            host.appendChild(row);
        });

        host.appendChild(el('p', 'form-help',
            'System fields can be hidden where optional, but are never deleted — this keeps existing leads readable.'));
    }

    function toggleContactField(field) {
        api('/api/admin/contact-fields.php', {
            method: 'POST',
            body: {
                action: 'toggle',
                funnel_id: L.funnelId,
                field_id: field.id,
                is_active: parseInt(field.is_active, 10) !== 1
            }
        }).then(function () {
            toast('Contact field updated.', 'success');
            reload();
        }).catch(showErrors);
    }

    function openContactFieldModal(field) {
        var wrap = el('div');

        var row = el('div', 'form-row form-row--2');
        var labelEn = L.input('cf-en', field.label_en);
        var labelAr = L.input('cf-ar', field.label_ar);
        row.appendChild(L.formGroup('English label', labelEn));
        row.appendChild(L.formGroup('Arabic label', labelAr));
        wrap.appendChild(row);

        var row2 = el('div', 'form-row form-row--2');
        var phEn = L.input('cf-ph-en', field.placeholder_en);
        var phAr = L.input('cf-ph-ar', field.placeholder_ar);
        row2.appendChild(L.formGroup('English placeholder', phEn));
        row2.appendChild(L.formGroup('Arabic placeholder', phAr));
        wrap.appendChild(row2);

        var row3 = el('div', 'form-row form-row--2');
        var minLength = L.input('cf-min', field.min_length, 'number');
        var maxLength = L.input('cf-max', field.max_length, 'number');
        row3.appendChild(L.formGroup('Minimum length', minLength));
        row3.appendChild(L.formGroup('Maximum length', maxLength));
        wrap.appendChild(row3);

        var pattern = L.input('cf-pattern', field.validation_pattern);
        wrap.appendChild(L.formGroup('Validation pattern', pattern, 'Regular expression body without delimiters.'));

        var requiredBox = L.checkbox('cf-required', 'Required', parseInt(field.is_required, 10) === 1);
        var activeBox = L.checkbox('cf-active', 'Visible on the funnel', parseInt(field.is_active, 10) === 1);
        wrap.appendChild(requiredBox);
        wrap.appendChild(activeBox);

        var choices = null;

        if (field.field_type === 'select') {
            choices = L.textarea('cf-choices', field.choices || '[]', 4);
            wrap.appendChild(L.formGroup('Choices (JSON)', choices,
                'Array of {"value","label_en","label_ar"} — values stay language independent.'));
        }

        var cancel = L.button('Cancel', 'btn--ghost', function () { L.modal.close(); });

        var save = L.button('Save field', 'btn--primary', function () {
            var payload = {
                action: 'update',
                funnel_id: L.funnelId,
                field_id: field.id,
                label_en: labelEn.value,
                label_ar: labelAr.value,
                placeholder_en: phEn.value,
                placeholder_ar: phAr.value,
                min_length: numberOrNull(minLength.value),
                max_length: numberOrNull(maxLength.value),
                validation_pattern: pattern.value,
                is_required: requiredBox.querySelector('input').checked,
                is_active: activeBox.querySelector('input').checked
            };

            if (choices) {
                try {
                    payload.choices = JSON.parse(choices.value);
                } catch (e) {
                    toast('Choices must be valid JSON.', 'error');
                    return;
                }
            }

            api('/api/admin/contact-fields.php', { method: 'POST', body: payload })
                .then(function () {
                    L.modal.close();
                    toast('Contact field saved.', 'success');
                    reload();
                })
                .catch(showErrors);
        });

        L.modal.open('Edit contact field — ' + field.field_key, wrap, [cancel, save]);
    }

    /* ===================================================== funnel settings */
    function renderFunnelSettings() {
        var host = $('#funnel-settings');
        clear(host);

        var funnel = state.funnel;
        if (!funnel) { return; }

        var f = {};

        var row1 = el('div', 'form-row form-row--2');
        f.name = L.input('fn-name', funnel.name);
        row1.appendChild(L.formGroup('Funnel name', f.name));

        f.status = L.select('fn-status', [
            { value: 'active', label: 'Active' },
            { value: 'paused', label: 'Paused' },
            { value: 'draft', label: 'Draft' }
        ], funnel.status);
        row1.appendChild(L.formGroup('Funnel status', f.status));
        host.appendChild(row1);

        var row2 = el('div', 'form-row form-row--2');
        f.default_language = L.select('fn-lang', [
            { value: 'en', label: 'English' }, { value: 'ar', label: 'Arabic' }
        ], funnel.default_language);
        row2.appendChild(L.formGroup('Default language', f.default_language));

        f.enabled_languages = L.input('fn-langs', funnel.enabled_languages);
        row2.appendChild(L.formGroup('Enabled languages', f.enabled_languages, 'Comma separated, e.g. en,ar'));
        host.appendChild(row2);

        var colors = el('div', 'form-row form-row--3');

        [['primary_color', 'Primary colour'], ['accent_color', 'Accent colour'], ['background_color', 'Background colour']]
            .forEach(function (pair) {
                var group = el('div', 'form-group');
                group.appendChild(el('label', 'form-label', pair[1]));

                var wrap = el('div', 'color-input');
                var picker = document.createElement('input');
                picker.type = 'color';
                picker.value = (funnel[pair[0]] || '#000000').slice(0, 7);

                var text = L.input('fn-' + pair[0], funnel[pair[0]]);
                picker.addEventListener('input', function () { text.value = picker.value; });
                text.addEventListener('input', function () {
                    if (/^#[0-9a-fA-F]{6}$/.test(text.value)) { picker.value = text.value; }
                });

                wrap.appendChild(picker);
                wrap.appendChild(text);
                group.appendChild(wrap);
                colors.appendChild(group);

                f[pair[0]] = text;
            });

        host.appendChild(colors);

        var labelsRow = el('div', 'form-row form-row--2');
        f.submit_label_en = L.input('fn-submit-en', funnel.submit_label_en);
        f.submit_label_ar = L.input('fn-submit-ar', funnel.submit_label_ar);
        labelsRow.appendChild(L.formGroup('Submit button (English)', f.submit_label_en));
        labelsRow.appendChild(L.formGroup('Submit button (Arabic)', f.submit_label_ar));
        host.appendChild(labelsRow);

        var successRow = el('div', 'form-row form-row--2');
        f.success_title_en = L.input('fn-st-en', funnel.success_title_en);
        f.success_title_ar = L.input('fn-st-ar', funnel.success_title_ar);
        successRow.appendChild(L.formGroup('Success title (English)', f.success_title_en));
        successRow.appendChild(L.formGroup('Success title (Arabic)', f.success_title_ar));
        host.appendChild(successRow);

        var messageRow = el('div', 'form-row form-row--2');
        f.success_message_en = L.textarea('fn-sm-en', funnel.success_message_en, 3);
        f.success_message_ar = L.textarea('fn-sm-ar', funnel.success_message_ar, 3);
        messageRow.appendChild(L.formGroup('Success message (English)', f.success_message_en));
        messageRow.appendChild(L.formGroup('Success message (Arabic)', f.success_message_ar));
        host.appendChild(messageRow);

        var waRow = el('div', 'form-row form-row--2');
        f.whatsapp_label_en = L.input('fn-wa-en', funnel.whatsapp_label_en);
        f.whatsapp_label_ar = L.input('fn-wa-ar', funnel.whatsapp_label_ar);
        waRow.appendChild(L.formGroup('WhatsApp button (English)', f.whatsapp_label_en));
        waRow.appendChild(L.formGroup('WhatsApp button (Arabic)', f.whatsapp_label_ar));
        host.appendChild(waRow);

        var urlRow = el('div', 'form-row form-row--2');
        f.privacy_policy_url = L.input('fn-privacy', funnel.privacy_policy_url, 'url');
        urlRow.appendChild(L.formGroup('Privacy policy URL', f.privacy_policy_url));

        f.min_completion_seconds = L.input('fn-min-seconds', funnel.min_completion_seconds, 'number');
        urlRow.appendChild(L.formGroup('Minimum completion time (seconds)', f.min_completion_seconds,
            'Submissions faster than this are rejected as automated.'));
        host.appendChild(urlRow);

        var toggles = el('div', 'form-row form-row--3');
        var toggleBoxes = {};

        [
            ['whatsapp_enabled', 'WhatsApp CTA'],
            ['progress_bar_enabled', 'Progress bar'],
            ['step_counter_enabled', 'Step counter'],
            ['back_button_enabled', 'Back button'],
            ['save_progress_enabled', 'Save answers in sessionStorage']
        ].forEach(function (pair) {
            var box = L.checkbox('fn-' + pair[0], pair[1], parseInt(funnel[pair[0]], 10) === 1);
            toggleBoxes[pair[0]] = box.querySelector('input');
            toggles.appendChild(box);
        });

        host.appendChild(toggles);

        /* logo + background upload */
        var uploadRow = el('div', 'form-row form-row--2');

        [['logo_path', 'Funnel logo', 'logo'], ['background_image_path', 'Background image', 'background']]
            .forEach(function (spec) {
                var group = el('div', 'form-group');
                group.appendChild(el('label', 'form-label', spec[1]));

                var path = L.input('fn-' + spec[0], funnel[spec[0]] || '');
                path.readOnly = true;
                group.appendChild(path);

                var file = document.createElement('input');
                file.type = 'file';
                file.className = 'form-control';
                file.accept = '.png,.jpg,.jpeg,.webp';

                file.addEventListener('change', function () {
                    if (!file.files || !file.files[0]) { return; }

                    var formData = new FormData();
                    formData.append('file', file.files[0]);
                    formData.append('purpose', spec[2]);

                    api('/api/admin/upload.php', { method: 'POST', body: formData })
                        .then(function (response) {
                            path.value = response.path;
                            toast('Image uploaded. Remember to save.', 'success');
                        })
                        .catch(showErrors);
                });

                group.appendChild(file);
                uploadRow.appendChild(group);
                f[spec[0]] = path;
            });

        host.appendChild(uploadRow);

        host.appendChild(L.button('Save funnel settings', 'btn--primary', function () {
            var payload = { funnel_id: L.funnelId };

            Object.keys(f).forEach(function (key) { payload[key] = f[key].value; });
            Object.keys(toggleBoxes).forEach(function (key) { payload[key] = toggleBoxes[key].checked; });

            api('/api/admin/funnel.php', { method: 'POST', body: payload })
                .then(function () {
                    toast('Funnel settings saved to draft.', 'success');
                    reload();
                })
                .catch(showErrors);
        }));
    }

    /* ============================================================ sorting */
    /**
     * Native HTML5 drag-and-drop — no third-party dependency.
     * The up/down buttons remain available as an accessible fallback.
     */
    function makeSortable(container, itemSelector, onReorder) {
        var dragged = null;

        container.addEventListener('dragstart', function (event) {
            var item = event.target.closest(itemSelector);
            if (!item) { return; }

            dragged = item;
            item.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', item.dataset.id || '');
        });

        container.addEventListener('dragend', function () {
            if (dragged) { dragged.classList.remove('is-dragging'); }

            $$(itemSelector, container).forEach(function (node) {
                node.classList.remove('is-drop-target');
            });

            dragged = null;
        });

        container.addEventListener('dragover', function (event) {
            if (!dragged) { return; }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';

            var target = event.target.closest(itemSelector);
            if (!target || target === dragged) { return; }

            $$(itemSelector, container).forEach(function (node) {
                node.classList.toggle('is-drop-target', node === target);
            });
        });

        container.addEventListener('drop', function (event) {
            if (!dragged) { return; }

            event.preventDefault();

            var target = event.target.closest(itemSelector);
            if (!target || target === dragged) { return; }

            var draggedHost = dragged.parentNode === container ? dragged : dragged.closest('li');
            var targetHost = target.parentNode === container ? target : target.closest('li');

            if (!draggedHost || !targetHost) { return; }

            var items = Array.prototype.slice.call(container.children);
            var draggedIndex = items.indexOf(draggedHost);
            var targetIndex = items.indexOf(targetHost);

            if (draggedIndex < targetIndex) {
                container.insertBefore(draggedHost, targetHost.nextSibling);
            } else {
                container.insertBefore(draggedHost, targetHost);
            }

            var orderedIds = $$(itemSelector, container).map(function (node) {
                return parseInt(node.dataset.id, 10);
            });

            onReorder(orderedIds);
        });
    }
})();
