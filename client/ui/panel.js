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
 *   their own key directly, or both, when the user chooses (remembered);
 *   every other mode talks to Mistral directly. The relay needs a signed-in
 *   user. Administrators see in the settings what the server has.
 * - **Conversations** are kept in memory per hypervideo, so switching back
 *   brings the conversation back; they are not saved.
 * - **Turns** are one undo step each: "Undo this turn" works while the turn's
 *   step is the latest in FrameTrail's undo history (UndoManager), and
 *   becomes "Redo this turn" once undone.
 * - The key is kept in memory, or in localStorage when the user chooses to
 *   remember it; the model is remembered in localStorage.
 * - **Transcription** (server mode, where the server has it set up): the bar's
 *   button, and the model's tool transcribe_video, have the server transcribe
 *   the video (media/transcribe.js); the result becomes subtitles through the
 *   edit API, one undo step.
 */

(function(ConversationalUI) {

    var ui     = ConversationalUI.ui,
        agent  = ConversationalUI.agent,
        models = ConversationalUI.models,
        media  = ConversationalUI.media,
        ops    = ConversationalUI.ops;

    // Chosen by the evaluation (scripts/eval-models.mjs) among the models a free key may use.
    var DEFAULT_MODEL = 'ministral-14b-latest';

    var STORAGE_KEY        = 'frametrail-conversational-ui-key',
        STORAGE_MODEL      = 'frametrail-conversational-ui-model',
        STORAGE_CONNECTION = 'frametrail-conversational-ui-connection';

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
        set_layout_area:    'ConversationalUiToolSetLayoutArea',
        transcribe_video:   'ConversationalUiToolTranscribeVideo'
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

    // A transcription error's code → the words for it (the server's words fill {detail}).
    var TRANSCRIBE_ERROR_LABELS = {
        login:      'ConversationalUiTranscribeErrorLogin',
        notAllowed: 'ConversationalUiTranscribeErrorNotAllowed',
        quota:      'ConversationalUiTranscribeErrorQuota',
        tooLarge:   'ConversationalUiTranscribeErrorTooLarge',
        timeout:    'ConversationalUiTranscribeErrorTimeout',
        network:    'ConversationalUiTranscribeErrorNetwork'
    };

    // Why the video cannot be transcribed → the words for it.
    var TRANSCRIBE_REASON_LABELS = {
        login:      'ConversationalUiTranscribeErrorLogin',
        noVideo:    'ConversationalUiTranscribeNoVideo',
        notFile:    'ConversationalUiTranscribeNotFile',
        permission: 'ConversationalUiTranscribePermission'
    };

    // The model's tool for it.
    var TRANSCRIBE_TOOL = {
        name:        'transcribe_video',
        description: 'Transcribe the speech in the hypervideo\'s video into subtitles, with the speech recognition of this server. '
                   + 'It takes minutes for a long video. Use it only when the user asks for a transcript or subtitles, or agrees to make one: '
                   + 'offer it when a request needs the transcript (topics, chapters, quotes) and the hypervideo has no subtitles. '
                   + 'Call it before other changes. It replaces subtitles in the same language. Afterwards read_transcript and find_in_transcript read them.',
        parameters:  {
            type:       'object',
            properties: {
                language: { type: 'string', description: 'The spoken language as a code like en or de. Omit it to let the speech recognition detect it.' }
            }
        }
    };

    // Why the panel cannot be used yet → the words for it.
    var UNAVAILABLE_LABELS = {
        checking:   'ConversationalUiChecking',
        hypervideo: 'ConversationalUiNeedsHypervideo',
        key:        'ConversationalUiNeedsKey',
        login:      'ConversationalUiErrorLogin',
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

    // When a gateway's allowance comes back (its quota refusal's resetsAt), as a date in the interface's language:
    // the calendar day the gateway wrote, not that moment in the viewer's time zone (midnight +01:00 is still October 31 in UTC).
    function renewalDate(error, language) {
        var day = (error && typeof error.resetsAt === 'string') ? /^(\d{4})-(\d{2})-(\d{2})/.exec(error.resetsAt) : null;
        var date = day ? new Date(Date.UTC(+day[1], day[2] - 1, +day[3])) : null;
        if (!date || isNaN(date.getTime())) { return ''; }
        try { return date.toLocaleDateString(language || undefined, { day: 'numeric', month: 'long', timeZone: 'UTC' }); } catch (e) { return date.toUTCString().slice(5, 11); }
    }

    // A refusal for a month's allowance (a platform's gateway), rather than the relay's own day.
    function monthlyQuota(error) {
        return !!error && error.code === 'quota' && error.period === 'month';
    }

    /**
     * I put a chat error (or an op error) into words for the panel.
     *
     * @param {Object} labels
     * @param {Error} error
     * @param {String} [model]
     * @param {String} [language]  the interface's, for dates
     * @return {String}
     */
    function errorText(labels, error, model, language) {
        var code   = error && error.code,
            detail = (error && error.message && error.message !== code) ? error.message : '';
        if (monthlyQuota(error)) {
            return format(labels['ConversationalUiErrorQuotaMonth'], { date: renewalDate(error, language) }).trim();
        }
        return format(labels[ERROR_LABELS[code] || 'ConversationalUiErrorOther'], { model: model || '', detail: detail }).trim();
    }

    /**
     * I put a transcription error (media.transcribe()'s) into words.
     *
     * @param {Object} labels
     * @param {Error} error
     * @param {String} [language]  the interface's, for dates
     * @return {String}
     */
    function transcribeErrorText(labels, error, language) {
        var code   = error && error.code,
            detail = (error && error.message && error.message !== code) ? error.message : '';
        if (code === 'stopped') { return labels['ConversationalUiStopped']; }
        if (monthlyQuota(error)) {
            return format(labels['ConversationalUiTranscribeErrorQuotaMonth'], { date: renewalDate(error, language) }).trim();
        }
        return format(labels[TRANSCRIBE_ERROR_LABELS[code] || 'ConversationalUiTranscribeFailed'], { detail: detail }).trim();
    }

    // The line for a tool call.
    function toolText(labels, entry) {

        if (entry.state === 'running') {
            return format(labels['ConversationalUiToolRunning'], { tool: entry.name });
        }
        if (entry.name === 'transcribe_video' && isObject(entry.result) && entry.result.cues === 0) {
            return labels['ConversationalUiTranscribeNoSpeech'];
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
            area:  input.area || '',
            count: (typeof result.cues === 'number') ? result.cues : ''
        });

    }


    function panel(FrameTrail, container, settings) {

        var Localization   = FrameTrail.module('Localization'),
            labels         = Localization.labels,
            language       = (typeof Localization.language === 'string') ? Localization.language : 'en',
            StorageManager = FrameTrail.module('StorageManager'),
            UndoManager    = FrameTrail.module('UndoManager');

        settings = isObject(settings) ? settings : {};

        var managed = (typeof settings.manageUrl === 'string' && settings.manageUrl !== '')
                ? { url: settings.manageUrl, label: (typeof settings.label === 'string') ? settings.label : '' }
                : null;

        // An error message, followed by the platform's link where an allowance
        // is what ran out: the platform is where it can be raised.
        function errorMessage(className, text, error) {
            var message = element('p', className, text);
            if (managed && error && error.code === 'quota') {
                var manage = element('a', null, labels['ConversationalUiSettingsManage']);
                manage.href = managed.url;
                manage.target = '_blank';
                manage.rel = 'noopener';
                message.append(' ', manage);
            }
            return message;
        }

        var found        = { mode: 'none', reason: 'checking' },
            access       = found,
            chosen       = storageGet(STORAGE_MODEL),
            connection   = storageGet(STORAGE_CONNECTION) === 'direct' ? 'direct' : 'relay',
            values       = { key: '', remember: false, model: chosen || DEFAULT_MODEL, models: [] },
            sessions     = {},
            session      = null,
            hypervideoId = null,
            descriptions = {},
            direct       = null,
            relay        = null,
            transcribing = null,
            fieldIds     = 0,
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
            transcribeButton = button('conversationalUiTranscribe', '', 'icon-captions-on'),
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

        transcribeButton.setAttribute('aria-label', labels['ConversationalUiTranscribe']);
        transcribeButton.setAttribute('data-tooltip-bottom-right', labels['ConversationalUiTranscribe']);
        transcribeButton.hidden = true;
        newButton.setAttribute('aria-label', labels['ConversationalUiNewConversation']);
        newButton.setAttribute('data-tooltip-bottom-right', labels['ConversationalUiNewConversation']);
        gearButton.setAttribute('aria-label', labels['ConversationalUiSettings']);
        gearButton.setAttribute('data-tooltip-bottom-right', labels['ConversationalUiSettings']);
        gearButton.setAttribute('aria-expanded', 'false');

        input.rows = 3;
        input.placeholder = labels['ConversationalUiPlaceholder'];
        input.setAttribute('aria-label', labels['ConversationalUiMessageLabel']);

        bar.append(status, transcribeButton, newButton, gearButton);
        row.append(hint, sendButton);
        composer.append(input, row);
        root.append(bar, settingsBox, logs, notice, composer);
        container.append(root);

        var form = ui.settings({
            labels:     labels,
            access:     function() { return access; },
            server:     serverInfo,
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
                    capabilities = isObject(response.capabilities) ? response.capabilities : null,
                    server       = capabilities || {};
                if (server.relay === true) {
                    return {
                        mode:         'relay',
                        models:       Array.isArray(server.models) ? server.models.filter(function(m) { return typeof m === 'string'; }) : [],
                        defaultModel: (typeof server.defaultModel === 'string') ? server.defaultModel : null,
                        limit:        (typeof server.requestsPerDay === 'number') ? server.requestsPerDay : null,
                        ownKey:       server.ownKey === true,
                        server:       capabilities
                    };
                }
                if (server.ownKey === true) {
                    return { mode: 'direct', server: capabilities };
                }
                return { mode: 'none', reason: 'notEnabled', server: capabilities };
            }, function() {
                return { mode: 'none', reason: 'notEnabled', server: null };
            });

        }

        // What the server and the user's choice make of it: with both the relay
        // and own keys, the user chooses (the relay unless they chose otherwise).
        function effective() {
            var result = Object.assign({}, found);
            if (found.mode === 'relay' && found.ownKey) {
                result.choice = true;
                if (connection === 'direct') { result.mode = 'direct'; }
            }
            return result;
        }

        function applyAccess(result) {
            found = result;
            applyConnection();
        }

        function applyConnection() {
            access = effective();
            if (access.mode === 'relay') {
                // The server's default, unless the user chose a model it allows.
                values.models = access.models.map(function(id) { return { id: id, name: id }; });
                values.model  = (chosen && access.models.indexOf(chosen) >= 0) ? chosen
                    : access.defaultModel || access.models[0] || values.model;
            } else if (access.choice) {
                // Away from the relay: the key's models, once listed.
                values.models = [];
                values.model  = chosen || DEFAULT_MODEL;
            }
            form.refresh();
            if (access.mode === 'direct' && values.key) { form.loadModels(); }
            update();
        }

        // What administrators see of the server's set-up in the settings, or null.
        function serverInfo() {
            var user = (FrameTrail.edit && typeof FrameTrail.edit.getUser === 'function') ? FrameTrail.edit.getUser() : null;
            if (FrameTrail.getState('storageMode') !== 'server' || !found.server || !user || user.guest || user.role !== 'admin') {
                return null;
            }
            return found.server;
        }

        // Whether someone is signed in to the server (the relay needs it).
        function signedIn() {
            var user = (FrameTrail.edit && typeof FrameTrail.edit.getUser === 'function') ? FrameTrail.edit.getUser() : null;
            return !!user && !user.guest;
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
            if (changes.connection !== undefined) {
                connection = (changes.connection === 'direct') ? 'direct' : 'relay';
                storageSet(STORAGE_CONNECTION, connection === 'direct' ? 'direct' : null);
                applyConnection();
                return;
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
            if (access.mode === 'relay' && !signedIn()) { return 'login'; }
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
                if (reason === 'key' || (reason === 'login' && access.choice)) {
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

            var offered = session ? transcription() : null;
            transcribeButton.hidden = !offered;
            transcribeButton.disabled = busy;

            if (session) {
                session.intro.privacy.textContent = labels[access.mode === 'relay' ? 'ConversationalUiPrivacyRelay' : 'ConversationalUiPrivacy'];
                showTranscriptHint(session, offered);
            }

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

            var transcript = element('p', 'conversationalUiTranscriptHint');
            transcript.hidden = true;

            box.append(text, examples, transcript, privacy);
            box.privacy    = privacy;
            box.transcript = transcript;
            return box;

        }

        function newSession() {
            var log = element('div', 'conversationalUiLog'),
                box = intro();
            log.setAttribute('role', 'log');
            log.setAttribute('aria-live', 'polite');
            log.append(box);
            return { log: log, intro: box, conversation: null, views: [], notes: [] };
        }

        // Something the model should know with the next message; kept until there is a conversation.
        function tellModel(current, text) {
            if (current.conversation) { current.conversation.note(text); } else { current.notes.push(text); }
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
                    describe: describe,
                    actions:  [transcribeAction()]
                });
                current.notes.splice(0).forEach(function(note) { current.conversation.note(note); });
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
                tickers: {},
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

        function stopTickers(view) {
            Object.keys(view.tickers || {}).forEach(function(id) { view.tickers[id].stop(); });
            view.tickers = {};
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
                    var summary = el.querySelector('summary');
                    if (entry.name === 'transcribe_video' && entry.state === 'running') {
                        if (!view.tickers[entry.id]) {
                            view.tickers[entry.id] = progressTicker(function(text) {
                                summary.textContent = format(labels['ConversationalUiToolTranscribeVideoRunning'], { progress: text });
                            });
                        }
                        view.tickers[entry.id].set(entry.progress || null);
                    } else {
                        if (view.tickers[entry.id]) { view.tickers[entry.id].stop(); delete view.tickers[entry.id]; }
                        summary.textContent = toolText(labels, entry);
                    }
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
            stopTickers(view);
            view.pending.remove();
            view.element.removeAttribute('aria-busy');
            view.turn = turn;
            view.footer.innerHTML = '';

            if (turn.state === 'stopped') {
                view.footer.append(element('p', 'conversationalUiState', labels[turn.changes.length ? 'ConversationalUiStoppedTakenBack' : 'ConversationalUiStopped']));
            }

            if (turn.error) {
                var error = errorMessage('message active error', errorText(labels, turn.error, current.conversation.model, language), turn.error);
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
                if (reason === 'key' || (reason === 'login' && access.choice)) { toggleSettings(true); }
                update();
                return;
            }

            input.value = '';
            run(session, text);

        }

        function stop() {
            if (transcribing) { transcribing.abort(); }
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
        /*  Transcription                                                 */
        /* -------------------------------------------------------------- */

        // The open hypervideo's video, as the edit API tells it ('' for none), or null.
        function videoOf() {
            try {
                var info = FrameTrail.edit.getInfo();
                return (typeof info.video === 'string') ? info.video : '';
            } catch (e) {
                return null;
            }
        }

        function hasSubtitles() {
            try { return FrameTrail.edit.list('subtitles').length > 0; } catch (e) { return false; }
        }

        // An uploaded file rather than a video from another site or a stream.
        function isFile(video) {
            return typeof video === 'string' && video !== '' && !/^([a-z][a-z0-9+.-]*:|\/\/)/i.test(video);
        }

        /**
         * Whether transcription is offered here: null when it is not (not
         * server mode, not set up on the server, no hypervideo), else { ok }
         * or { reason } why this video cannot be transcribed now.
         */
        function transcription() {

            var server = found.server;

            if (FrameTrail.getState('storageMode') !== 'server' || !isObject(server) || !isObject(server.transcription)
                || server.transcription.available !== true || hypervideoId === null || FrameTrail.getState('viewMode') !== 'video'
                || !FrameTrail.edit || typeof FrameTrail.edit.getInfo !== 'function') {
                return null;
            }

            var video = videoOf();
            if (video === null) { return null; }
            if (!signedIn()) { return { reason: 'login' }; }
            if (video === '') { return { reason: 'noVideo' }; }
            if (!isFile(video)) { return { reason: 'notFile' }; }

            var permission = FrameTrail.edit.permission('subtitles');
            if (!permission || permission.allowed !== true) { return { reason: 'permission' }; }

            return { ok: true };

        }

        // The intro's line about a missing transcript: transcribe it, or where transcription is possible.
        function showTranscriptHint(current, offered) {

            var line = current.intro.transcript;

            line.innerHTML = '';
            line.hidden = true;

            if (!isFile(videoOf()) || hasSubtitles()) { return; }

            if (offered && offered.ok) {
                var start = button('conversationalUiTranscribeIntro', labels['ConversationalUiTranscribe'], 'icon-captions-on');
                start.addEventListener('click', function() { openTranscription(current); });
                line.append(document.createTextNode(labels['ConversationalUiTranscriptMissing'] + ' '), start);
                line.hidden = false;
            } else if (DIRECT_MODES.indexOf(FrameTrail.getState('storageMode')) >= 0) {
                line.textContent = labels['ConversationalUiTranscriptMissing'] + ' ' + labels['ConversationalUiTranscribeNeedsServer'];
                line.hidden = false;
            }

        }

        function languageName(code) {
            try {
                var name = new Intl.DisplayNames([language], { type: 'language' }).of(code);
                return (name && name !== code) ? name : code;
            } catch (e) {
                return code;
            }
        }

        // The languages Whisper knows, by name in the interface's language.
        function languages() {
            var codes = [];
            Object.keys(media.LANGUAGES).forEach(function(name) {
                var code = media.LANGUAGES[name];
                if (codes.indexOf(code) < 0) { codes.push(code); }
            });
            return codes.map(function(code) { return { code: code, name: languageName(code) }; })
                .sort(function(a, b) { return a.name.localeCompare(b.name, language); });
        }

        // Where the transcription is, in words: the stage, its time, how much is sent.
        function progressText(progress, since) {
            if (!isObject(progress)) { return labels['ConversationalUiTranscribeStarting']; }
            var time = clock((typeof progress.elapsed === 'number' ? progress.elapsed : 0) + since);
            if (progress.stage === 'extracting') { return format(labels['ConversationalUiTranscribeExtracting'], { time: time }); }
            if (progress.stage === 'queued') {
                return (typeof progress.position === 'number')
                    ? format(labels['ConversationalUiTranscribeQueued'], { position: progress.position, time: time })
                    : format(labels['ConversationalUiTranscribeWaiting'], { time: time });
            }
            if (progress.stage === 'sending') {
                var percent = (progress.total > 0) ? Math.min(100, Math.round(100 * progress.sent / progress.total)) : 0;
                return format(labels['ConversationalUiTranscribeSending'], { percent: percent });
            }
            return format(labels['ConversationalUiTranscribeTranscribing'], { time: time });
        }

        // The progress shown by render(text), its time counted on between the server's messages.
        function progressTicker(render) {
            var progress = null,
                at       = Date.now(),
                timer    = setInterval(show, 1000);
            function show() { render(progressText(progress, Math.floor((Date.now() - at) / 1000))); }
            show();
            return {
                set:  function(value) { progress = value; at = Date.now(); show(); },
                stop: function() { clearInterval(timer); }
            };
        }

        /**
         * I have the server transcribe the open hypervideo's video, and make
         * the WebVTT of it: { lang, vtt, cues, duration, replaced }. The
         * language is the one asked for, or what the speech server heard.
         */
        function transcribeVideo(asked, signal, onProgress) {

            var id = hypervideoId;

            return media.transcribe({
                url:          StorageManager.extensionURL('conversational-ui', 'transcribe'),
                hypervideoId: id,
                language:     asked || null,
                signal:       signal,
                onProgress:   onProgress
            }).then(function(result) {
                if (hypervideoId !== id) { throw media.mediaError('stopped', 'Stopped'); }
                var info = FrameTrail.edit.getInfo(),
                    lang = asked || media.languageCode(result.language) || 'und',
                    made = media.toVtt(result.segments, { offset: result.offset, start: info.start, end: info.end });
                return {
                    lang:     lang,
                    vtt:      made.vtt,
                    cues:     made.cues,
                    duration: result.duration,
                    replaced: FrameTrail.edit.list('subtitles').some(function(entry) { return entry.srclang === lang; })
                };
            });

        }

        // The transcription entry in the log: the language, then its progress, then what came of it.
        function openTranscription(current) {

            if (busy || !current || current !== session) { return; }

            var offered = transcription();
            if (!offered) { return; }

            var view = {
                element:    element('div', 'conversationalUiTurn conversationalUiTranscription'),
                parts:      element('div', 'conversationalUiParts'),
                footer:     element('div', 'conversationalUiFooter'),
                texts:      {},
                tools:      {},
                tickers:    {},
                waiting:    null,
                turn:       null,
                undoLabels: ['ConversationalUiUndoTranscription', 'ConversationalUiRedoTranscription']
            };

            view.element.append(element('div', 'conversationalUiMessage conversationalUiUser', labels['ConversationalUiTranscribe']), view.parts, view.footer);
            current.log.append(view.element);
            current.views.push(view);
            current.intro.hidden = true;

            function close() {
                view.element.remove();
                current.views.splice(current.views.indexOf(view), 1);
                current.intro.hidden = current.views.length > 0;
            }

            if (!offered.ok) {
                var reason = element('p', 'message active', labels[TRANSCRIBE_REASON_LABELS[offered.reason]]),
                    okRow  = element('div', 'conversationalUiActions'),
                    okay   = button('conversationalUiTranscribeClose', labels['ConversationalUiTranscribeClose']);
                okay.addEventListener('click', close);
                okRow.append(okay);
                view.parts.append(reason, okRow);
                scrollDown(true);
                okay.focus();
                return;
            }

            var form    = element('div', 'conversationalUiTranscribeForm'),
                hint    = element('p', 'conversationalUiHint', labels['ConversationalUiTranscribeHint']),
                field   = element('div', 'conversationalUiField'),
                label   = element('label', null, labels['ConversationalUiTranscribeLanguage']),
                wrap    = element('div', 'custom-select'),
                select  = element('select'),
                actions = element('div', 'conversationalUiActions'),
                start   = button('conversationalUiTranscribeStart', labels['ConversationalUiTranscribeStart']),
                cancel  = button('conversationalUiTranscribeCancel', labels['ConversationalUiTranscribeCancel']);

            select.id = 'conversationalUiTranscribeLanguage' + (++fieldIds);
            label.htmlFor = select.id;
            [{ code: '', name: labels['ConversationalUiTranscribeAutomatic'] }].concat(languages()).forEach(function(entry) {
                var option = element('option', null, entry.name);
                option.value = entry.code;
                select.append(option);
            });

            wrap.append(select);
            field.append(label, wrap);
            actions.append(start, cancel);
            form.append(hint, field, actions);
            view.parts.append(form);
            scrollDown(true);
            select.focus();

            cancel.addEventListener('click', close);
            start.addEventListener('click', function() {
                form.remove();
                runTranscription(current, view, select.value || null);
            });

        }

        function runTranscription(current, view, asked) {

            var controller = new AbortController(),
                line       = element('div', 'conversationalUiPending'),
                ticker     = progressTicker(function(text) { line.textContent = text; });

            view.parts.append(line);
            view.element.setAttribute('aria-busy', 'true');
            transcribing = controller;
            busy = true;
            update();

            transcribeVideo(asked, controller.signal, ticker.set).then(function(made) {

                if (!made.cues) { return { made: made, description: null }; }

                var description = describe(format(labels['ConversationalUiTranscriptionStep'], { language: languageName(made.lang) })),
                    store       = ops.liveStore(FrameTrail);

                return Promise.resolve().then(function() {
                    return store.transaction(description, function(tx) {
                        return ops.record(tx, { summary: description }).run('set_subtitles', { lang: made.lang, vtt: made.vtt });
                    });
                }).then(function() {
                    return { made: made, description: description };
                });

            }).then(function(outcome) {

                var made = outcome.made;

                if (!outcome.description) {
                    view.footer.append(element('p', 'conversationalUiState', labels['ConversationalUiTranscribeNoSpeech']));
                    return;
                }

                view.turn  = { description: outcome.description, changed: true, request: labels['ConversationalUiTranscribe'] };
                view.notes = {
                    undo: 'The user undid the transcription: the subtitles in ' + made.lang + ' are as they were before it.',
                    redo: 'The user redid the transcription: the subtitles in ' + made.lang + ' (' + made.cues + ' cues) are there again.'
                };

                view.footer.append(element('p', 'conversationalUiState', format(labels[made.replaced ? 'ConversationalUiTranscribeReplaced' : 'ConversationalUiTranscribeDone'],
                    { count: made.cues, language: languageName(made.lang) })));

                var actions = element('div', 'conversationalUiActions'),
                    undo    = button('conversationalUiUndo', labels['ConversationalUiUndoTranscription'], 'icon-ccw'),
                    next    = button('conversationalUiExample', labels['ConversationalUiExampleChaptersAll']);

                undo.addEventListener('click', function() { undoTurn(current, view); });
                next.addEventListener('click', function() {
                    input.value = labels['ConversationalUiExampleChaptersAll'];
                    input.focus();
                });
                view.undo = undo;
                actions.append(undo, next);
                view.footer.append(actions);

                view.message = element('p', 'conversationalUiState');
                view.message.hidden = true;
                view.footer.append(view.message);

                tellModel(current, 'The user transcribed the video: subtitles in ' + made.lang + ' (' + made.cues + ' cues) are there now'
                    + (made.replaced ? ', replacing the ones before' : '') + '; read_transcript reads them.');

            }, function(e) {

                var stopped = e && e.code === 'stopped';
                view.footer.append(errorMessage(stopped ? 'conversationalUiState' : 'message active error', transcribeErrorText(labels, e, language), e));

            }).then(function() {

                ticker.stop();
                line.remove();
                view.element.removeAttribute('aria-busy');
                transcribing = null;
                busy = false;
                refreshUndo();
                update();
                scrollDown(false);

            });

        }

        // The model's tool: the same transcription, its subtitles written in the turn's transaction.
        function transcribeAction() {

            return {

                definition: TRANSCRIBE_TOOL,

                available: function() {
                    var offered = transcription();
                    return !!offered && offered.ok === true && !transcribing;
                },

                run: function(toolInput, context) {

                    var asked = toolInput.language;
                    if (asked !== undefined && asked !== null && (typeof asked !== 'string' || !/^[a-z]{2,3}$/.test(asked))) {
                        throw ops.util.opError('invalid', 'Invalid input', [{ path: '/language', message: 'must be a language code like en or de' }]);
                    }

                    return transcribeVideo(asked || null, context.signal, context.progress).then(function(made) {
                        if (!made.cues) {
                            return { result: { cues: 0, note: 'No speech was found in the video; nothing was changed.' } };
                        }
                        return {
                            result: { lang: made.lang, cues: made.cues, duration: made.duration, replaced: made.replaced,
                                      note: 'The subtitles are set; read_transcript and find_in_transcript read them. Go on with the rest of the user\'s request.' },
                            write:  { op: 'set_subtitles', input: { lang: made.lang, vtt: made.vtt } }
                        };
                    });

                }

            };

        }

        transcribeButton.addEventListener('click', function() { openTranscription(session); });


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
                var words = view.undoLabels || ['ConversationalUiUndoTurn', 'ConversationalUiRedoTurn'];
                view.undo.lastChild.textContent = labels[redo ? words[1] : words[0]];
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

            var notes = view.notes || {
                undo: 'The user undid the changes of your turn about: "' + excerpt(view.turn.request, 80) + '".',
                redo: 'The user redid the changes of your turn about: "' + excerpt(view.turn.request, 80) + '".'
            };

            if (UndoManager.getUndoDescription() === description) {
                if (UndoManager.undo()) {
                    tellModel(current, notes.undo);
                    view.message.hidden = true;
                }
            } else if (UndoManager.getRedoDescription() === description) {
                if (UndoManager.redo()) {
                    tellModel(current, notes.redo);
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


    ui.panel                    = panel;
    ui.errorText                = errorText;
    ui.transcribeErrorText      = transcribeErrorText;
    ui.TRANSCRIBE_TOOL          = TRANSCRIBE_TOOL;
    ui.TRANSCRIBE_ERROR_LABELS  = TRANSCRIBE_ERROR_LABELS;
    ui.TRANSCRIBE_REASON_LABELS = TRANSCRIBE_REASON_LABELS;
    ui.toolText                 = toolText;
    ui.TOOL_LABELS              = TOOL_LABELS;
    ui.ERROR_LABELS             = ERROR_LABELS;
    ui.UNAVAILABLE_LABELS       = UNAVAILABLE_LABELS;
    ui.DEFAULT_MODEL            = DEFAULT_MODEL;

})(window.FrameTrailConversationalUI);
