/*
 * FrameTrail-Conversational-UI — the chat panel (ConversationalUI.ui.panel):
 * the conversation about the open hypervideo, in the side panel module.js
 * takes. One per FrameTrail instance.
 *
 *     var chat = ui.panel(FrameTrail, container, settings);   // settings: the config entry's
 *     chat.hypervideoChanged(id);  chat.stop();  chat.destroy();
 *
 * - **Access** follows the storage mode: on a server the add-on's status
 *   action tells whether the relay is there (no key needed) or users may use
 *   their own key directly; every other mode talks to Mistral directly.
 * - **Conversations** are kept in memory per hypervideo, so switching back
 *   brings the conversation back; they are not saved.
 * - **Turns** are one undo step each: "Undo this turn" works while the turn's
 *   step is the latest in FrameTrail's undo history (UndoManager), and
 *   becomes "Redo this turn" once undone.
 * - The key is kept in memory, or in localStorage when the user chooses to
 *   remember it; the model is remembered in localStorage.
 */

(function(ConversationalUI) {

    var ui     = ConversationalUI.ui,
        agent  = ConversationalUI.agent,
        models = ConversationalUI.models,
        ops    = ConversationalUI.ops;

    // Chosen by the evaluation (scripts/eval-models.mjs) among the models a free key may use.
    var DEFAULT_MODEL = 'ministral-14b-latest';

    var STORAGE_KEY   = 'frametrail-conversational-ui-key',
        STORAGE_MODEL = 'frametrail-conversational-ui-model';

    var DIRECT_MODES = ['local', 'file', 'download', 'static'];

    // What a tool did, in the panel's words.
    var TOOL_LABELS = {
        list_hypervideos:   'ConversationalUiToolListHypervideos',
        inspect_hypervideo: 'ConversationalUiToolInspectHypervideo',
        list_items:         'ConversationalUiToolListItems',
        get_item:           'ConversationalUiToolGetItem',
        read_transcript:    'ConversationalUiToolReadTranscript',
        find_in_transcript: 'ConversationalUiToolFindInTranscript',
        describe_type:      'ConversationalUiToolDescribeType',
        add_overlay:        'ConversationalUiToolAddOverlay',
        update_overlay:     'ConversationalUiToolUpdateOverlay',
        remove_overlay:     'ConversationalUiToolRemoveOverlay',
        add_annotation:     'ConversationalUiToolAddAnnotation',
        update_annotation:  'ConversationalUiToolUpdateAnnotation',
        remove_annotation:  'ConversationalUiToolRemoveAnnotation',
        add_chapter:        'ConversationalUiToolAddChapter',
        update_chapter:     'ConversationalUiToolUpdateChapter',
        remove_chapter:     'ConversationalUiToolRemoveChapter',
        set_subtitles:      'ConversationalUiToolSetSubtitles',
        set_layout_area:    'ConversationalUiToolSetLayoutArea'
    };

    // A chat error's code → the words for it.
    var ERROR_LABELS = {
        key:           'ConversationalUiErrorKey',
        model:         'ConversationalUiErrorModel',
        rateLimit:     'ConversationalUiErrorRateLimit',
        request:       'ConversationalUiErrorRequest',
        service:       'ConversationalUiErrorService',
        network:       'ConversationalUiErrorNetwork',
        rounds:        'ConversationalUiErrorRounds',
        login:         'ConversationalUiErrorLogin',
        quota:         'ConversationalUiErrorQuota',
        notConfigured: 'ConversationalUiErrorNotConfigured',
        notAllowed:    'ConversationalUiErrorNotAllowed'
    };

    // Why the panel cannot be used yet → the words for it.
    var UNAVAILABLE_LABELS = {
        checking:   'ConversationalUiChecking',
        hypervideo: 'ConversationalUiNeedsHypervideo',
        key:        'ConversationalUiNeedsKey',
        notEnabled: 'ConversationalUiNotEnabled',
        storage:    'ConversationalUiNeedsStorage',
        frameTrail: 'ConversationalUiNeedsFrameTrail'
    };

    function isObject(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    function element(tag, className, text) {
        var el = document.createElement(tag);
        if (className) { el.className = className; }
        if (text !== undefined) { el.textContent = text; }
        return el;
    }

    function button(className, text, icon) {
        var el = element('button', className);
        el.type = 'button';
        if (icon) {
            var span = element('span', icon);
            span.setAttribute('aria-hidden', 'true');
            el.append(span);
        }
        if (text) { el.append(document.createTextNode(text)); }
        return el;
    }

    function storageGet(key) {
        try { return window.localStorage.getItem(key); } catch (e) { return null; }
    }

    function storageSet(key, value) {
        try {
            if (value === null || value === '') { window.localStorage.removeItem(key); } else { window.localStorage.setItem(key, value); }
        } catch (e) { /* private window, blocked storage: kept in memory only */ }
    }

    // Seconds as m:ss (h:mm:ss from an hour).
    function clock(seconds) {
        if (typeof seconds !== 'number' || !isFinite(seconds)) { return ''; }
        var total = Math.max(0, Math.round(seconds)),
            h = Math.floor(total / 3600),
            m = Math.floor((total % 3600) / 60),
            s = total % 60,
            pad = function(n) { return (n < 10 ? '0' : '') + n; };
        return h ? h + ':' + pad(m) + ':' + pad(s) : m + ':' + pad(s);
    }

    function excerpt(text, max) {
        text = String(text || '').replace(/\s+/g, ' ').trim();
        return (text.length > max) ? text.slice(0, max - 1) + '…' : text;
    }

    function format(template, values) {
        return String(template).replace(/\{([a-zA-Z]+)\}/g, function(match, name) {
            return (values[name] !== undefined && values[name] !== null) ? String(values[name]) : '';
        });
    }

    /**
     * I put a chat error (or an op error) into words for the panel.
     *
     * @param {Object} labels
     * @param {Error} error
     * @param {String} [model]
     * @return {String}
     */
    function errorText(labels, error, model) {
        var code   = error && error.code,
            detail = (error && error.message && error.message !== code) ? error.message : '';
        return format(labels[ERROR_LABELS[code] || 'ConversationalUiErrorOther'], { model: model || '', detail: detail }).trim();
    }

    // The line for a tool call.
    function toolText(labels, entry) {

        if (entry.state === 'running') {
            return format(labels['ConversationalUiToolRunning'], { tool: entry.name });
        }
        if (entry.state === 'failed') {
            return format(labels['ConversationalUiToolFailed'], { tool: entry.name, message: excerpt(entry.error.message, 200) });
        }

        var input  = isObject(entry.input) ? entry.input : {},
            result = isObject(entry.result) ? entry.result : {},
            body   = isObject(input.body) ? input.body : {},
            name   = result.name || body['frametrail:name'] || '',
            type   = result.type || body['frametrail:type'] || '',
            start  = (typeof result.start === 'number') ? result.start : input.start,
            end    = (typeof result.end === 'number') ? result.end : input.end;

        return format(labels[TOOL_LABELS[entry.name]] || entry.name, {
            item:  name ? name + (type ? ' (' + type + ')' : '') : type,
            title: result.title || input.title || '',
            time:  (typeof end === 'number' && entry.name.indexOf('chapter') < 0) ? clock(start) + '–' + clock(end) : clock(start),
            query: input.query || '',
            type:  input.type || '',
            lang:  input.lang || result.lang || '',
            area:  input.area || ''
        });

    }


    function panel(FrameTrail, container, settings) {

        var Localization   = FrameTrail.module('Localization'),
            labels         = Localization.labels,
            StorageManager = FrameTrail.module('StorageManager'),
            UndoManager    = FrameTrail.module('UndoManager');

        settings = isObject(settings) ? settings : {};

        var managed = (typeof settings.manageUrl === 'string' && settings.manageUrl !== '')
                ? { url: settings.manageUrl, label: (typeof settings.label === 'string') ? settings.label : '' }
                : null;

        var access       = { mode: 'none', reason: 'checking' },
            chosen       = storageGet(STORAGE_MODEL),
            values       = { key: '', remember: false, model: chosen || DEFAULT_MODEL, models: [] },
            sessions     = {},
            session      = null,
            hypervideoId = null,
            descriptions = {},
            direct       = null,
            relay        = null,
            busy         = false;

        var remembered = storageGet(STORAGE_KEY);
        if (remembered) {
            values.key = remembered;
            values.remember = true;
        }


        /* -------------------------------------------------------------- */
        /*  The DOM                                                       */
        /* -------------------------------------------------------------- */

        var root        = element('div', 'conversationalUi'),
            bar         = element('div', 'conversationalUiBar'),
            status      = element('span', 'conversationalUiStatus'),
            newButton   = button('conversationalUiNew', '', 'icon-doc-new'),
            gearButton  = button('conversationalUiGear', '', 'icon-cog'),
            settingsBox = element('div', 'conversationalUiSettingsBox'),
            logs        = element('div', 'conversationalUiLogs'),
            notice      = element('div', 'message conversationalUiNotice'),
            composer    = element('div', 'conversationalUiComposer'),
            input       = element('textarea'),
            row         = element('div', 'conversationalUiComposerRow'),
            hint        = element('span', 'conversationalUiHint', labels['ConversationalUiComposerHint']),
            sendButton  = button('conversationalUiSend', labels['ConversationalUiSend']);

        newButton.setAttribute('aria-label', labels['ConversationalUiNewConversation']);
        newButton.setAttribute('data-tooltip-bottom-right', labels['ConversationalUiNewConversation']);
        gearButton.setAttribute('aria-label', labels['ConversationalUiSettings']);
        gearButton.setAttribute('data-tooltip-bottom-right', labels['ConversationalUiSettings']);
        gearButton.setAttribute('aria-expanded', 'false');

        input.rows = 3;
        input.placeholder = labels['ConversationalUiPlaceholder'];
        input.setAttribute('aria-label', labels['ConversationalUiMessageLabel']);

        bar.append(status, newButton, gearButton);
        row.append(hint, sendButton);
        composer.append(input, row);
        root.append(bar, settingsBox, logs, notice, composer);
        container.append(root);

        var form = ui.settings({
            labels:     labels,
            access:     function() { return access; },
            managed:    managed,
            values:     function() { return values; },
            onChange:   changeSettings,
            loadModels: loadModels,
            test:       function() {
                var adapter = currentAdapter();
                if (!adapter) { return Promise.reject(models.chatError('key', '')); }
                if (adapter.test) { return adapter.test(values.model); }
                return models.chat(adapter, { model: values.model, messages: [{ role: 'user', content: 'Reply with: OK' }], max_tokens: 3 });
            }
        });

        settingsBox.append(form.element);
        settingsBox.hidden = true;
        settingsBox.id = form.element.id + 'Box';
        gearButton.setAttribute('aria-controls', settingsBox.id);


        /* -------------------------------------------------------------- */
        /*  Access and settings                                           */
        /* -------------------------------------------------------------- */

        function checkAccess() {

            var mode = FrameTrail.getState('storageMode');

            if (DIRECT_MODES.indexOf(mode) >= 0) {
                return Promise.resolve({ mode: 'direct' });
            }
            if (mode !== 'server') {
                return Promise.resolve({ mode: 'none', reason: 'storage' });
            }

            return Promise.resolve(StorageManager.serverPost(new URLSearchParams({ a: 'conversationalUiStatus' }))).then(function(answer) {
                var response     = (isObject(answer) && answer.status === 'success' && isObject(answer.response)) ? answer.response : {},
                    capabilities = isObject(response.capabilities) ? response.capabilities : {};
                if (capabilities.relay === true) {
                    return {
                        mode:         'relay',
                        models:       Array.isArray(capabilities.models) ? capabilities.models.filter(function(m) { return typeof m === 'string'; }) : [],
                        defaultModel: (typeof capabilities.defaultModel === 'string') ? capabilities.defaultModel : null
                    };
                }
                if (capabilities.ownKey === true) {
                    return { mode: 'direct' };
                }
                return { mode: 'none', reason: 'notEnabled' };
            }, function() {
                return { mode: 'none', reason: 'notEnabled' };
            });

        }

        function applyAccess(found) {
            access = found;
            if (access.mode === 'relay') {
                // The server's default, unless the user chose a model it allows.
                values.models = access.models.map(function(id) { return { id: id, name: id }; });
                values.model  = (chosen && access.models.indexOf(chosen) >= 0) ? chosen
                    : access.defaultModel || access.models[0] || values.model;
            }
            form.refresh();
            if (access.mode === 'direct' && values.key) { form.loadModels(); }
            update();
        }

        function currentAdapter() {
            if (access.mode === 'relay') {
                if (!relay) {
                    relay = models.relay({
                        url:    StorageManager.extensionURL('conversational-ui', 'relay'),
                        post:   StorageManager.serverPost,
                        models: values.models
                    });
                }
                return relay;
            }
            if (access.mode === 'direct' && values.key) {
                if (!direct || direct.key !== values.key) {
                    direct = models.mistral({ key: values.key });
                    direct.key = values.key;
                }
                return direct;
            }
            return null;
        }

        function changeSettings(changes) {
            if (changes.key !== undefined) {
                values.key = changes.key;
                if (values.remember) { storageSet(STORAGE_KEY, values.key); }
            }
            if (changes.remember !== undefined) {
                values.remember = changes.remember;
                storageSet(STORAGE_KEY, values.remember ? values.key : null);
            }
            if (changes.model !== undefined) {
                values.model = chosen = changes.model;
                storageSet(STORAGE_MODEL, values.model);
            }
            update();
        }

        function loadModels() {
            var adapter = currentAdapter();
            if (!adapter || !adapter.listModels) { return Promise.resolve([]); }
            return adapter.listModels().then(function(list) {
                values.models = list;
                return list;
            });
        }

        function toggleSettings(open) {
            settingsBox.hidden = !open;
            gearButton.classList.toggle('active', open);
            gearButton.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) { form.refresh(); form.focus(); }
        }

        gearButton.addEventListener('click', function() { toggleSettings(settingsBox.hidden); });


        /* -------------------------------------------------------------- */
        /*  Availability                                                  */
        /* -------------------------------------------------------------- */

        // Why a message cannot be sent now, or null.
        function unavailable() {
            if (access.reason === 'checking') { return 'checking'; }
            if (!FrameTrail.edit || typeof FrameTrail.edit.getInfo !== 'function') { return 'frameTrail'; }
            if (access.mode === 'none') { return access.reason || 'notEnabled'; }
            if (hypervideoId === null || FrameTrail.getState('viewMode') !== 'video') { return 'hypervideo'; }
            if (!currentAdapter()) { return 'key'; }
            return null;
        }

        function update() {

            var reason = unavailable();

            status.textContent = (access.mode === 'none') ? '' : values.model + (access.mode === 'relay' ? ' · ' + labels['ConversationalUiViaServer'] : '');

            notice.innerHTML = '';
            notice.classList.toggle('active', !!reason && !busy);
            if (reason) {
                notice.append(document.createTextNode(labels[UNAVAILABLE_LABELS[reason]] || labels['ConversationalUiNotEnabled']));
                if (reason === 'key') {
                    var open = button('conversationalUiOpenSettings', labels['ConversationalUiSettings']);
                    open.addEventListener('click', function() { toggleSettings(true); });
                    notice.append(document.createTextNode(' '), open);
                }
            }

            input.disabled = !!reason && reason !== 'checking' && reason !== 'key';
            sendButton.textContent = busy ? labels['ConversationalUiStop'] : labels['ConversationalUiSend'];
            sendButton.classList.toggle('conversationalUiStop', busy);
            sendButton.disabled = !busy && !!reason;
            newButton.hidden = !session || !session.conversation;
            newButton.disabled = busy;
            root.classList.toggle('busy', busy);

            if (session) { session.intro.privacy.textContent = labels[access.mode === 'relay' ? 'ConversationalUiPrivacyRelay' : 'ConversationalUiPrivacy']; }

        }


        /* -------------------------------------------------------------- */
        /*  Conversations                                                 */
        /* -------------------------------------------------------------- */

        // The undo step's description: the request, made unique among this panel's turns.
        function describe(request) {
            var base        = format(labels['ConversationalUiUndoStep'], { request: excerpt(request, 50) }),
                description = base,
                n           = 2;
            while (descriptions[description]) { description = base + ' (' + (n++) + ')'; }
            descriptions[description] = true;
            return description;
        }

        function intro() {

            var box      = element('div', 'conversationalUiIntro'),
                text     = element('p', null, labels['ConversationalUiIntro']),
                privacy  = element('p', 'conversationalUiHint'),
                examples = element('div', 'conversationalUiExamples');

            ['ConversationalUiExampleQuestion', 'ConversationalUiExampleChapters', 'ConversationalUiExampleOverlay'].forEach(function(key) {
                var example = button('conversationalUiExample', labels[key]);
                example.addEventListener('click', function() {
                    input.value = labels[key];
                    input.focus();
                });
                examples.append(example);
            });

            box.append(text, examples, privacy);
            box.privacy = privacy;
            return box;

        }

        function newSession() {
            var log = element('div', 'conversationalUiLog'),
                box = intro();
            log.setAttribute('role', 'log');
            log.setAttribute('aria-live', 'polite');
            log.append(box);
            return { log: log, intro: box, conversation: null, views: [] };
        }

        function showSession(id) {
            if (session) { session.log.remove(); }
            hypervideoId = id;
            if (id === null) { session = null; update(); return; }
            session = sessions[id] || (sessions[id] = newSession());
            logs.append(session.log);
            refreshUndo();
            update();
            scrollDown(true);
        }

        function conversationOf(current) {
            if (!current.conversation) {
                current.conversation = agent.conversation({
                    store:    ops.liveStore(FrameTrail),
                    adapter:  currentAdapter(),
                    model:    values.model,
                    describe: describe
                });
            } else {
                current.conversation.configure({ adapter: currentAdapter(), model: values.model });
            }
            return current.conversation;
        }

        newButton.addEventListener('click', function() {
            if (busy || hypervideoId === null) { return; }
            session.log.remove();
            sessions[hypervideoId] = null;
            showSession(hypervideoId);
            input.focus();
        });


        /* -------------------------------------------------------------- */
        /*  Turns                                                         */
        /* -------------------------------------------------------------- */

        function nearBottom() {
            return logs.scrollHeight - logs.scrollTop - logs.clientHeight < 60;
        }

        function scrollDown(force) {
            if (force || nearBottom()) { logs.scrollTop = logs.scrollHeight; }
        }

        function turnView(current, request) {

            var view = {
                element: element('div', 'conversationalUiTurn'),
                parts:   element('div', 'conversationalUiParts'),
                footer:  element('div', 'conversationalUiFooter'),
                pending: element('div', 'conversationalUiPending', labels['ConversationalUiThinking']),
                texts:   {},
                tools:   {},
                waiting: null,
                turn:    null
            };

            if (request !== null) {
                view.element.append(element('div', 'conversationalUiMessage conversationalUiUser', request));
            } else {
                view.element.append(element('div', 'conversationalUiContinued', labels['ConversationalUiContinued']));
            }

            view.element.setAttribute('aria-busy', 'true');
            view.parts.append(view.pending);
            view.element.append(view.parts, view.footer);
            current.log.append(view.element);
            current.views.push(view);
            current.intro.hidden = true;
            scrollDown(true);

            return view;

        }

        function add(view, part) {
            var follow = nearBottom();
            view.parts.insertBefore(part, view.pending);
            if (follow) { scrollDown(true); }
        }

        function stopWaiting(view) {
            if (view.waiting) {
                clearInterval(view.waiting.timer);
                view.waiting.element.remove();
                view.waiting = null;
            }
        }

        function handlers(view) {

            return {

                text: function(text, round) {
                    stopWaiting(view);
                    var el = view.texts[round];
                    if (!el) {
                        el = view.texts[round] = element('div', 'conversationalUiMessage conversationalUiAssistant');
                        add(view, el);
                    }
                    var follow = nearBottom();
                    el.innerHTML = '';
                    el.append(ui.markdown(text));
                    if (follow) { scrollDown(true); }
                },

                tool: function(entry) {
                    stopWaiting(view);
                    var el = view.tools[entry.id];
                    if (!el) {
                        el = view.tools[entry.id] = element('details', 'conversationalUiTool');
                        el.append(element('summary'), element('pre'));
                        add(view, el);
                    }
                    el.className = 'conversationalUiTool ' + entry.state;
                    el.querySelector('summary').textContent = toolText(labels, entry);
                    var details = { input: entry.input };
                    if (entry.state === 'done') { details.result = entry.result; }
                    if (entry.state === 'failed') { details.error = entry.error; }
                    var json = JSON.stringify(details, null, 2);
                    el.querySelector('pre').textContent = (json.length > 4000) ? json.slice(0, 4000) + '\n…' : json;
                },

                wait: function(seconds, error) {
                    stopWaiting(view);
                    var el   = element('div', 'conversationalUiWaiting'),
                        left = Math.ceil(seconds);
                    function show() {
                        el.textContent = format(labels['ConversationalUiWaiting'], { seconds: left });
                    }
                    show();
                    view.waiting = {
                        element: el,
                        timer:   setInterval(function() { left = Math.max(0, left - 1); show(); }, 1000)
                    };
                    add(view, el);
                },

                check: function(findings) {
                    add(view, element('div', 'conversationalUiCheck', format(labels['ConversationalUiLintSent'], { count: findings.length })));
                }

            };

        }

        function finish(current, view, turn) {

            stopWaiting(view);
            view.pending.remove();
            view.element.removeAttribute('aria-busy');
            view.turn = turn;
            view.footer.innerHTML = '';

            if (turn.state === 'stopped') {
                view.footer.append(element('p', 'conversationalUiState', labels[turn.changes.length ? 'ConversationalUiStoppedTakenBack' : 'ConversationalUiStopped']));
            }

            if (turn.error) {
                var error = element('p', 'message active error', errorText(labels, turn.error, current.conversation.model));
                view.footer.append(error);
            }

            if (turn.lint && turn.lint.length) {
                var check = element('details', 'conversationalUiFindings'),
                    list  = element('ul');
                check.append(element('summary', null, format(labels['ConversationalUiLintRemaining'], { count: turn.lint.length })));
                turn.lint.forEach(function(finding) {
                    list.append(element('li', finding.severity, finding.message));
                });
                check.append(list);
                view.footer.append(check);
            }

            var actions = element('div', 'conversationalUiActions');

            if (turn.changed) {
                var undo = button('conversationalUiUndo', labels['ConversationalUiUndoTurn'], 'icon-ccw');
                undo.addEventListener('click', function() { undoTurn(current, view); });
                view.undo = undo;
                actions.append(undo);
            }

            if ((turn.state === 'limited' || turn.state === 'failed') && current.conversation.canResume()) {
                var resume = button('conversationalUiResume', labels['ConversationalUiContinue']);
                resume.addEventListener('click', function() {
                    resume.remove();
                    run(current, null);
                });
                actions.append(resume);
            }

            if (actions.childNodes.length) { view.footer.append(actions); }

            view.message = element('p', 'conversationalUiState');
            view.message.hidden = true;
            view.footer.append(view.message);

            refreshUndo();
            scrollDown(false);

        }

        function run(current, text) {

            var conversation = conversationOf(current),
                view         = turnView(current, text);

            busy = true;
            update();

            var turn = (text === null) ? conversation.resume(handlers(view)) : conversation.send(text, handlers(view));

            return turn.then(function(result) {
                busy = false;
                finish(current, view, result);
                update();
            }, function(e) {
                busy = false;
                finish(current, view, { state: 'failed', error: e, changes: [], changed: false, lint: null });
                update();
            });

        }

        function send() {

            if (busy) { stop(); return; }

            var text   = input.value.trim(),
                reason = unavailable();

            if (text === '') { return; }
            if (reason) {
                if (reason === 'key') { toggleSettings(true); }
                update();
                return;
            }

            input.value = '';
            run(session, text);

        }

        function stop() {
            if (session && session.conversation) { session.conversation.stop(); }
        }

        sendButton.addEventListener('click', send);

        input.addEventListener('keydown', function(evt) {
            if (evt.key === 'Enter' && !evt.shiftKey && !evt.isComposing) {
                evt.preventDefault();
                send();
            } else if (evt.key === 'Escape' && busy) {
                evt.preventDefault();
                stop();
            }
        });


        /* -------------------------------------------------------------- */
        /*  Undo                                                          */
        /* -------------------------------------------------------------- */

        function refreshUndo() {
            if (!session || !UndoManager) { return; }
            var undoTop = UndoManager.getUndoDescription(),
                redoTop = UndoManager.getRedoDescription();
            session.views.forEach(function(view) {
                if (!view.undo) { return; }
                var description = view.turn.description,
                    redo        = redoTop === description && undoTop !== description;
                view.undo.lastChild.textContent = labels[redo ? 'ConversationalUiRedoTurn' : 'ConversationalUiUndoTurn'];
                view.undo.firstChild.className = redo ? 'icon-cw' : 'icon-ccw';
                view.undo.classList.toggle('stale', undoTop !== description && !redo);
            });
        }

        function say(view, key) {
            view.message.textContent = labels[key];
            view.message.hidden = false;
        }

        function undoTurn(current, view) {

            var description = view.turn.description;

            if (busy || FrameTrail.getState('editBusy')) { say(view, 'ConversationalUiUndoBusy'); return; }

            if (UndoManager.getUndoDescription() === description) {
                if (UndoManager.undo()) {
                    current.conversation.note('The user undid the changes of your turn about: "' + excerpt(view.turn.request, 80) + '".');
                    view.message.hidden = true;
                }
            } else if (UndoManager.getRedoDescription() === description) {
                if (UndoManager.redo()) {
                    current.conversation.note('The user redid the changes of your turn about: "' + excerpt(view.turn.request, 80) + '".');
                    view.message.hidden = true;
                }
            } else {
                say(view, 'ConversationalUiUndoNotOnTop');
            }

            refreshUndo();

        }

        function onUndoState() { refreshUndo(); }

        FrameTrail.addEventListener('undoStateChanged', onUndoState);


        /* -------------------------------------------------------------- */
        /*  Start                                                         */
        /* -------------------------------------------------------------- */

        update();
        checkAccess().then(applyAccess);

        return {

            element: root,

            // A hypervideo was loaded (or reloaded): its conversation, or a new one.
            hypervideoChanged: function(id) {
                stop();
                showSession((id === undefined || id === null) ? null : String(id));
            },

            // The view changed (overview ⇄ video), or editing started or ended.
            refresh: function() {
                if (!FrameTrail.getState('editMode')) { stop(); }
                update();
            },

            focus: function() { if (!input.disabled) { input.focus(); } },

            stop: stop,

            destroy: function() {
                stop();
                FrameTrail.removeEventListener('undoStateChanged', onUndoState);
                root.remove();
            }

        };

    }


    ui.panel              = panel;
    ui.errorText          = errorText;
    ui.toolText           = toolText;
    ui.TOOL_LABELS        = TOOL_LABELS;
    ui.ERROR_LABELS       = ERROR_LABELS;
    ui.UNAVAILABLE_LABELS = UNAVAILABLE_LABELS;
    ui.DEFAULT_MODEL      = DEFAULT_MODEL;

})(window.FrameTrailConversationalUI);
