/*
 * FrameTrail-Conversational-UI — the panel's settings (ConversationalUI.ui.settings):
 * how the assistant reaches Mistral (and, where the server offers both, the
 * user's choice between the relay and their own key), the user's key (direct
 * mode), the model, a connection test, and for administrators what the
 * server has set up (the relay, own keys, transcription). The panel
 * (ui/panel.js) owns the values; this only shows them and reports changes.
 *
 *     var form = ui.settings({
 *         labels:     Localization.labels,
 *         access:     function() { return { mode: 'direct' | 'relay' | 'none', reason, choice, limit }; },
 *         server:     function() { return the status action's capabilities, for administrators, or null; },
 *         managed:    { url, label } or null,       // a platform manages the instance's settings
 *         values:     function() { return { key, remember, model, models }; },
 *         onChange:   function(changes) { … },      // { key } | { remember } | { model } | { connection }
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

        var connection = element('p', 'conversationalUiConnection'),
            limit      = element('p', 'conversationalUiHint conversationalUiLimit');

        // Through the server, or with one's own key, where the server offers both.
        var choiceGroup  = element('div', 'conversationalUiField'),
            choiceLabel  = element('label', null, labels['ConversationalUiSettingsConnection']),
            choiceWrap   = element('div', 'custom-select'),
            choiceSelect = element('select');

        choiceSelect.id = id + 'Connection';
        choiceLabel.htmlFor = choiceSelect.id;
        [['relay', 'ConversationalUiSettingsConnectionRelay'], ['direct', 'ConversationalUiSettingsConnectionOwnKey']].forEach(function(pair) {
            var option = element('option', null, labels[pair[1]]);
            option.value = pair[0];
            choiceSelect.append(option);
        });
        choiceWrap.append(choiceSelect);
        choiceGroup.append(choiceLabel, choiceWrap);

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

        // What the server has set up, for administrators.
        var serverGroup = element('div', 'conversationalUiField conversationalUiServerInfo');

        root.append(connection, limit, choiceGroup, keyGroup, modelGroup, testGroup, managedNote, serverGroup);

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

        function megabytes(bytes) {
            var mb = bytes / 1048576;
            return (mb >= 10 ? Math.round(mb) : Math.round(mb * 10) / 10) + ' MB';
        }

        function format(template, values) {
            return String(template).replace(/\{([a-zA-Z]+)\}/g, function(match, name) {
                return (values[name] !== undefined && values[name] !== null) ? String(values[name]) : '';
            });
        }

        // The server's set-up in words: the relay, its models and limit or how to switch it on, own keys.
        function showServer() {

            var server = options.server ? options.server() : null;

            serverGroup.innerHTML = '';
            serverGroup.style.display = server ? '' : 'none';
            if (!server) { return; }

            var lines = [labels['ConversationalUiServerTitle']];

            if (server.relay === true) {
                lines.push(format(labels['ConversationalUiServerRelayOn'], {
                    models: (server.models || []).join(', '),
                    model:  server.defaultModel || '',
                    limit:  (typeof server.requestsPerDay === 'number')
                        ? format(labels['ConversationalUiServerLimit'], { count: server.requestsPerDay })
                        : labels['ConversationalUiServerNoLimit']
                }));
            } else if (server.problem === 'curl') {
                lines.push(labels['ConversationalUiServerCurl']);
            } else if (server.problem === 'baseUrl') {
                lines.push(labels['ConversationalUiServerBaseUrl']);
            } else {
                lines.push(labels['ConversationalUiServerRelayOff']);
            }

            lines.push(labels[server.ownKey === true ? 'ConversationalUiServerOwnKeyOn' : 'ConversationalUiServerOwnKeyOff']);

            var speech = server.transcription;
            if (speech && speech.available === true) {
                lines.push(format(labels[speech.audio === 'ffmpeg' ? 'ConversationalUiServerTranscriptionFfmpeg' : 'ConversationalUiServerTranscriptionFile'],
                    { size: (typeof speech.maxBytes === 'number') ? megabytes(speech.maxBytes) : '' }));
            } else if (speech && speech.problem === 'curl') {
                lines.push(labels['ConversationalUiServerTranscriptionCurl']);
            } else if (speech && speech.problem === 'baseUrl') {
                lines.push(labels['ConversationalUiServerTranscriptionBaseUrl']);
            } else if (speech === false) {
                lines.push(labels['ConversationalUiServerTranscriptionOff']);
            }

            lines.forEach(function(line, index) {
                var p = element('p', 'conversationalUiHint', line);
                if (index === 0) { p.className = 'conversationalUiServerTitle'; }
                serverGroup.append(p);
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

            var daily = access.mode === 'relay' && typeof access.limit === 'number';
            limit.textContent = daily ? format(labels['ConversationalUiSettingsRelayLimit'], { count: access.limit }) : '';
            limit.style.display = daily ? '' : 'none';

            choiceGroup.style.display = access.choice ? '' : 'none';
            choiceSelect.value = direct ? 'direct' : 'relay';

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

            showServer();

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

        choiceSelect.addEventListener('change', function() {
            testResult.className = 'message';
            options.onChange({ connection: choiceSelect.value });
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
            focus:      function() { (choiceGroup.style.display !== 'none' ? choiceSelect : keyGroup.style.display !== 'none' ? keyInput : modelSelect).focus(); }
        };

    }


    ui.settings = settings;
    ui.fill     = fill;

})(window.FrameTrailConversationalUI);
