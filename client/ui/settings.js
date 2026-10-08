/*
 * FrameTrail-Conversational-UI — the panel's settings (ConversationalUI.ui.settings):
 * how the assistant reaches Mistral, the user's key (direct mode), the model,
 * and a connection test. The panel (ui/panel.js) owns the values; this only
 * shows them and reports changes.
 *
 *     var form = ui.settings({
 *         labels:     Localization.labels,
 *         access:     { mode: 'direct' | 'relay' | 'none', reason },
 *         managed:    { url, label } or null,       // a platform manages the instance's settings
 *         values:     function() { return { key, remember, model, models }; },
 *         onChange:   function(changes) { … },      // { key } | { remember } | { model }
 *         loadModels: function() { return promise of [{ id, name }]; },
 *         test:       function() { return promise; }
 *     });
 *     container.append(form.element);
 *     form.refresh();
 */

(function(ConversationalUI) {

    var ui = ConversationalUI.ui;

    var CONSOLE_URL = 'https://console.mistral.ai/api-keys';

    var counter = 0;

    function element(tag, className, text) {
        var el = document.createElement(tag);
        if (className) { el.className = className; }
        if (text !== undefined) { el.textContent = text; }
        return el;
    }

    // A label's text with {name} filled in, as text nodes and links: parts maps a name to a node or a string.
    function fill(template, parts) {
        var fragment = document.createDocumentFragment();
        String(template).split(/(\{[a-zA-Z]+\})/).forEach(function(piece) {
            var name = /^\{([a-zA-Z]+)\}$/.exec(piece);
            if (name && parts[name[1]] !== undefined) {
                var part = parts[name[1]];
                fragment.append((typeof part === 'string') ? document.createTextNode(part) : part);
            } else if (piece !== '') {
                fragment.append(document.createTextNode(piece));
            }
        });
        return fragment;
    }

    function link(href, text) {
        var a = element('a', null, text);
        a.href = href;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        return a;
    }

    function settings(options) {

        var labels = options.labels,
            id     = 'conversationalUiSettings' + (++counter),
            root   = element('div', 'conversationalUiSettings');

        root.id = id;

        var connection = element('p', 'conversationalUiConnection');

        // The key (direct mode).
        var keyGroup  = element('div', 'conversationalUiField'),
            keyLabel  = element('label', null, labels['ConversationalUiSettingsKey']),
            keyInput  = element('input'),
            keyHint   = element('p', 'conversationalUiHint'),
            privacy   = element('p', 'conversationalUiHint');

        keyInput.type = 'password';
        keyInput.id = id + 'Key';
        keyInput.autocomplete = 'off';
        keyInput.spellcheck = false;
        keyLabel.htmlFor = keyInput.id;
        keyHint.append(fill(labels['ConversationalUiSettingsKeyHint'], { console: link(CONSOLE_URL, labels['ConversationalUiSettingsConsole']) }));
        privacy.textContent = labels['ConversationalUiSettingsTraining'];

        var rememberRow   = element('div', 'checkboxRow'),
            rememberSwitch = element('label', 'switch'),
            rememberInput = element('input'),
            rememberLabel = element('label', null, labels['ConversationalUiSettingsRemember']),
            rememberWarn  = element('p', 'message', labels['ConversationalUiSettingsRememberWarning']);

        rememberInput.type = 'checkbox';
        rememberInput.id = id + 'Remember';
        rememberLabel.htmlFor = rememberInput.id;
        rememberSwitch.append(rememberInput, element('span', 'slider round'));
        rememberRow.append(rememberSwitch, rememberLabel);

        keyGroup.append(keyLabel, keyInput, keyHint, privacy, rememberRow, rememberWarn);

        // The model.
        var modelGroup  = element('div', 'conversationalUiField'),
            modelLabel  = element('label', null, labels['ConversationalUiSettingsModel']),
            modelWrap   = element('div', 'custom-select'),
            modelSelect = element('select'),
            modelHint   = element('p', 'conversationalUiHint');

        modelSelect.id = id + 'Model';
        modelLabel.htmlFor = modelSelect.id;
        modelWrap.append(modelSelect);
        modelGroup.append(modelLabel, modelWrap, modelHint);

        // The test.
        var testGroup  = element('div', 'conversationalUiField'),
            testButton = element('button', null, labels['ConversationalUiSettingsTest']),
            testResult = element('p', 'message');

        testButton.type = 'button';
        testGroup.append(testButton, testResult);

        var managedNote = element('p', 'conversationalUiHint');

        root.append(connection, keyGroup, modelGroup, testGroup, managedNote);

        function values() { return options.values(); }

        function setModels(list, current) {
            modelSelect.innerHTML = '';
            var ids = list.map(function(model) { return model.id; });
            if (current && ids.indexOf(current) < 0) {
                list = [{ id: current, name: current }].concat(list);
            }
            list.forEach(function(model) {
                var option = element('option', null, model.id);
                option.value = model.id;
                if (model.id === current) { option.selected = true; }
                modelSelect.append(option);
            });
        }

        function showResult(kind, text) {
            testResult.className = 'message active' + (kind ? ' ' + kind : '');
            testResult.textContent = text;
        }

        function refresh() {

            var access = options.access(),
                state  = values(),
                direct = access.mode === 'direct';

            connection.textContent = labels[{
                direct: 'ConversationalUiSettingsDirect',
                relay:  'ConversationalUiSettingsRelay',
                none:   'ConversationalUiSettingsNone'
            }[access.mode] || 'ConversationalUiSettingsNone'];

            keyGroup.style.display = direct ? '' : 'none';
            if (document.activeElement !== keyInput) { keyInput.value = state.key || ''; }
            rememberInput.checked = !!state.remember;
            rememberWarn.classList.toggle('active', !!state.remember);

            modelGroup.style.display = (access.mode === 'none') ? 'none' : '';
            setModels(state.models || [], state.model);
            modelSelect.disabled = !!options.managed;
            modelHint.textContent = (state.models && state.models.length) ? '' : labels['ConversationalUiSettingsModelHint'];

            testGroup.style.display = (access.mode === 'none') ? 'none' : '';
            testButton.disabled = direct && !state.key;

            managedNote.innerHTML = '';
            managedNote.style.display = options.managed ? '' : 'none';
            if (options.managed) {
                managedNote.append(fill(labels['ConversationalUiSettingsManaged'], {
                    platform: options.managed.label || '',
                    link:     options.managed.url ? link(options.managed.url, labels['ConversationalUiSettingsManage']) : ''
                }));
            }

        }

        keyInput.addEventListener('change', function() {
            options.onChange({ key: keyInput.value.trim() });
            testResult.className = 'message';
            loadModels();
        });

        rememberInput.addEventListener('change', function() {
            options.onChange({ remember: rememberInput.checked });
            rememberWarn.classList.toggle('active', rememberInput.checked);
        });

        modelSelect.addEventListener('change', function() {
            options.onChange({ model: modelSelect.value });
        });

        function loadModels() {
            if (options.access().mode !== 'direct' || !values().key) { refresh(); return; }
            options.loadModels().then(refresh, function() { refresh(); });
        }

        testButton.addEventListener('click', function() {
            if (keyInput.value.trim() !== (values().key || '')) {
                options.onChange({ key: keyInput.value.trim() });
            }
            testButton.disabled = true;
            showResult('', labels['ConversationalUiSettingsTesting']);
            options.test().then(function() {
                showResult('success', labels['ConversationalUiSettingsTestOk'].replace('{model}', values().model));
                loadModels();
            }, function(e) {
                showResult('error', (e && e.code === 'rateLimit')
                    ? labels['ConversationalUiSettingsTestRateLimit'].replace('{model}', values().model)
                    : ui.errorText(labels, e, values().model));
            }).then(function() {
                testButton.disabled = false;
            });
        });

        return {
            element:    root,
            refresh:    refresh,
            loadModels: loadModels,
            focus:      function() { (keyGroup.style.display !== 'none' ? keyInput : modelSelect).focus(); }
        };

    }


    ui.settings = settings;
    ui.fill     = fill;

})(window.FrameTrailConversationalUI);
