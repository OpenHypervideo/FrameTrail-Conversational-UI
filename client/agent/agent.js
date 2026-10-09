/*
 * FrameTrail-Conversational-UI — the conversation (ConversationalUI.agent.conversation):
 * the loop between the user, the model and the operations. It knows no DOM;
 * the panel listens to its events.
 *
 *     var talk = agent.conversation({ store: ops.liveStore(FrameTrail), adapter: models.mistral({ key }), model: 'ministral-14b-latest' });
 *     talk.send('Add a chapter at each topic change in the first 5 minutes', {
 *         text: function(text, round) { … },     // the model's text so far in this round
 *         tool: function(entry) { … },           // a tool call: { id, round, name, input, state, result | error }
 *         wait: function(seconds, error) { … },  // a rate limit: trying again in seconds
 *         check: function(findings) { … }        // lint findings sent back to the model
 *     }).then(function(turn) { turn.state; turn.changes; turn.lint; });
 *
 * A turn is the user's message and everything the model does about it. Its
 * changes are one transaction of the store (in the editor: one undo step,
 * named turn.description), opened when the model first changes something,
 * so a question leaves the editor free. Stop takes the turn's changes back;
 * so does FrameTrail's own Stop. Any other end keeps them: a rate limit that
 * outlasts the retries ends the turn as "limited", and resume() goes on from
 * where it stopped.
 *
 * Besides the operations, the caller may offer tools of its own (actions):
 * work that is not a change of the store, such as transcribing the video on
 * the server, which may hand back one operation to write with its result. It
 * runs before the turn's transaction is opened for that write, so a long wait
 * leaves the editor free.
 */

(function(ConversationalUI) {

    var agent  = ConversationalUI.agent,
        ops    = ConversationalUI.ops,
        models = ConversationalUI.models,
        util   = ops.util;

    var MAX_ROUNDS    = 20,                 // model requests in one turn
        MAX_INVALID   = 3,                  // invalid inputs in a row (the first and two retries) before the model must answer in words
        RESULT_LIMIT  = 20000,              // characters of one tool result
        HISTORY_LIMIT = 200000,             // characters of the conversation before earlier tool results are dropped
        RETRY_DELAYS  = [2, 4, 8, 16, 30],  // seconds before another attempt, when a rate limit names no time
        RETRY_BUDGET  = 60,                 // seconds a turn waits for rate limits at most
        MAX_RETRIES   = 6;                  // attempts after a rate limit, however short the waits

    // The prompt fragments, in the order they make the system prompt.
    var PROMPT_ORDER = ['system', 'conversation', 'types'];

    var GENERATOR_NAME = 'FrameTrail-Conversational-UI';

    // Whether the console has been told that the check of changes cannot run (once per page).
    var lintWarned = false;

    function isObject(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    function systemPrompt(prompts) {
        return PROMPT_ORDER.map(function(name) { return prompts[name]; }).filter(function(text) {
            return typeof text === 'string' && text !== '';
        }).join('\n\n');
    }

    function randomId(length) {
        var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
            out   = '';
        for (var i = 0; i < length; i++) { out += chars.charAt(Math.floor(Math.random() * chars.length)); }
        return out;
    }

    function stoppedError() {
        return models.chatError('stopped', 'Stopped');
    }

    function sleep(seconds, signal) {
        return new Promise(function(resolve, reject) {
            if (signal.aborted) { reject(stoppedError()); return; }
            var timer = setTimeout(function() { signal.removeEventListener('abort', abort); resolve(); }, seconds * 1000);
            function abort() { clearTimeout(timer); reject(stoppedError()); }
            signal.addEventListener('abort', abort);
        });
    }

    // A tool's error as the model and the panel see it.
    function toolError(e) {
        var error = {
            code:    (e && typeof e.code === 'string') ? e.code : 'failed',
            message: (e && e.message) ? String(e.message) : String(e)
        };
        if (e && Array.isArray(e.errors) && e.errors.length) { error.errors = util.clone(e.errors); }
        return error;
    }

    // A tool result as the content of a tool message: JSON, cut where it would crowd the conversation.
    function resultText(value) {
        var text = JSON.stringify(value === undefined ? null : value);
        if (text.length <= RESULT_LIMIT) { return text; }
        return JSON.stringify({
            truncated: true,
            note:      'The result has ' + text.length + ' characters; only the first ' + RESULT_LIMIT + ' are given. Ask for less (a time span, a kind, a limit) to see the rest.',
            result:    text.slice(0, RESULT_LIMIT)
        });
    }


    /**
     * I start a conversation about the hypervideo of a store.
     *
     * @param {Object} options
     * @param {Object} options.store       the live store (or a model store)
     * @param {Object} options.adapter     models.mistral() or models.relay()
     * @param {String} options.model       the model's id
     * @param {String} [options.reasoningEffort]
     * @param {Object} [options.prompts]   the prompt fragments by name (default: the bundle's)
     * @param {Boolean} [options.lint]     check the turn's changes (default true)
     * @param {Function} [options.describe] (request) → the undo step's description
     * @param {Object} [options.retry]     { delays, budget } in seconds: how rate limits are waited out (defaults above)
     * @param {Array} [options.actions]    tools beyond the operations: { definition: { name, description, parameters },
     *                                     available: () → Boolean (offered when true; default always), run: (input,
     *                                     { signal, progress(value) }) → promise of { result, write?: { op, input } } }
     * @return {Object}
     */
    function conversation(options) {

        var id          = 'ftcui-' + Date.now().toString(36) + '-' + randomId(6),
            messages    = [{ role: 'system', content: systemPrompt(options.prompts || ConversationalUI.prompts || {}) }],
            turns       = [],
            notes       = [],
            lastContext = null,
            running     = null;

        function generator() {
            return { type: 'Software', name: GENERATOR_NAME, model: options.model, provider: 'mistral' };
        }

        function describe(request) {
            var text = (typeof request === 'string') ? request.replace(/\s+/g, ' ').trim() : '';
            if (options.describe) { return options.describe(text); }
            return 'Conversational UI: ' + ((text.length > 60) ? text.slice(0, 59) + '…' : text);
        }

        // The hypervideo's summary, when it changed since the model last saw it.
        function context(store) {
            var summary;
            try { summary = JSON.stringify(ops.run(store, 'inspect_hypervideo', {})); } catch (e) { return null; }
            if (summary === lastContext) { return null; }
            lastContext = summary;
            return summary;
        }

        // Earlier turns' tool results go first when the conversation grows too long; the current turn's stay.
        function compact(keepFrom) {
            var size = JSON.stringify(messages).length;
            for (var i = 1; i < keepFrom && size > HISTORY_LIMIT; i++) {
                var message = messages[i];
                if (message.role === 'tool' && message.content.length > 200) {
                    size -= message.content.length;
                    message.content = '{"omitted":"An earlier result, left out to save space. Read again what you need."}';
                    size += message.content.length;
                }
            }
        }

        // Every tool call of the last assistant message needs an answer before the next request.
        function answerOpenCalls(content) {
            for (var i = messages.length - 1; i >= 0; i--) {
                var message = messages[i];
                if (message.role !== 'assistant') { continue; }
                (message.tool_calls || []).forEach(function(call) {
                    var answered = messages.slice(i + 1).some(function(m) { return m.role === 'tool' && m.tool_call_id === call.id; });
                    if (!answered) {
                        messages.push({ role: 'tool', tool_call_id: call.id, name: call['function'].name, content: content });
                    }
                });
                return;
            }
        }

        // The caller's tools that are offered now.
        function actions() {
            return (options.actions || []).filter(function(action) {
                return isObject(action) && isObject(action.definition) && typeof action.run === 'function'
                    && (typeof action.available !== 'function' || action.available() === true);
            });
        }

        function actionNamed(name) {
            return actions().filter(function(action) { return action.definition.name === name; })[0] || null;
        }

        function canResume() {
            var last = messages[messages.length - 1];
            return !running && (last.role === 'user' || last.role === 'tool');
        }

        function run(text, handlers) {

            if (running) { return Promise.reject(new Error('A turn is running')); }

            handlers = handlers || {};

            var store      = options.store,
                adapter    = options.adapter,
                controller = new AbortController(),
                signal     = controller.signal,
                previous   = turns[turns.length - 1],
                turn       = {
                    id:          turns.length + 1,
                    request:     (text === null) ? (previous ? previous.request : '') : text,
                    resumed:     text === null,
                    description: describe((text === null) ? (previous ? previous.request : '') : text),
                    state:       'running',
                    changed:     false,
                    changes:     [],
                    lint:        null,
                    error:       null,
                    usage:       { prompt_tokens: 0, completion_tokens: 0 }
                },
                tx         = null,
                touched    = { refs: [], kinds: {} },
                stopped    = false,
                round      = 0;

            turns.push(turn);

            running = {
                stop: function() { stopped = true; controller.abort(); }
            };

            function emit(name) {
                var fn = handlers[name];
                if (typeof fn !== 'function') { return; }
                try { fn.apply(null, Array.prototype.slice.call(arguments, 1)); } catch (e) { console.error(e); }
            }

            function current() {
                return (tx && tx.store) ? tx.store : store;
            }

            // The turn's transaction, opened by its first change and kept open until the turn ends.
            function openTransaction() {

                if (tx) { return tx.ready; }

                tx = {};
                tx.ready = new Promise(function(resolve, reject) {
                    var answer;
                    try {
                        answer = store.transaction(turn.description, function(txStore, txSignal) {
                            tx.store    = txStore;
                            tx.recorder = ops.record(txStore, { summary: turn.description, generator: generator() });
                            if (txSignal) {
                                txSignal.addEventListener('abort', function() { stopped = true; controller.abort(); });
                            }
                            resolve();
                            return new Promise(function(finish, fail) { tx.finish = finish; tx.fail = fail; });
                        });
                    } catch (e) {
                        reject(e);
                        return;
                    }
                    tx.promise = Promise.resolve(answer);
                    tx.promise.catch(function(e) { reject(e); });
                });

                return tx.ready;

            }

            // Ends the transaction: kept (one undo step) or taken back.
            function closeTransaction(keep) {
                if (!tx || !tx.promise || !tx.finish) { return Promise.resolve(); }
                if (keep) { tx.finish(); } else { tx.fail(stoppedError()); }
                return tx.promise.then(function() {
                    turn.changed = keep && turn.changes.length > 0;
                }, function() {
                    turn.changed = false;
                });
            }

            function body(tools, toolChoice) {
                var request = {
                    model:               options.model,
                    messages:            util.clone(messages),
                    tools:               tools,
                    tool_choice:         toolChoice,
                    parallel_tool_calls: true,
                    prompt_cache_key:    id
                };
                if (options.reasoningEffort) { request.reasoning_effort = options.reasoningEffort; }
                return request;
            }

            async function ask(request) {
                var retry   = options.retry || {},
                    delays  = retry.delays || RETRY_DELAYS,
                    budget  = (typeof retry.budget === 'number') ? retry.budget : RETRY_BUDGET,
                    waited  = 0,
                    attempt = 0;
                for (;;) {
                    try {
                        return await models.chat(adapter, request, {
                            signal: signal,
                            onText: function(piece, all) { emit('text', all, round); }
                        });
                    } catch (e) {
                        var transient = e.code === 'rateLimit' || (e.code === 'service' && [502, 503, 504].indexOf(e.status) >= 0);
                        if (!transient) { throw e; }
                        var delay = (typeof e.retryAfter === 'number') ? e.retryAfter : delays[Math.min(attempt, delays.length - 1)];
                        if (attempt >= MAX_RETRIES || waited + delay > budget) { throw e; }
                        attempt++;
                        waited += delay;
                        emit('wait', delay, e);
                        await sleep(delay, signal);
                    }
                }
            }

            function parseArguments(text) {
                if (text === undefined || text === null || String(text).trim() === '') { return {}; }
                try {
                    var input = JSON.parse(text);
                    if (!isObject(input)) { throw new Error('not an object'); }
                    return input;
                } catch (e) {
                    throw util.opError('invalid', 'The arguments are not a JSON object (' + e.message + ')');
                }
            }

            function remember(op, result) {
                touched.kinds[op.kind] = true;
                if (isObject(result) && result.ref !== undefined && typeof result.kind === 'string') {
                    touched.refs.push(result.kind + ' ' + JSON.stringify(result.ref));
                }
            }

            var invalid = 0;

            async function call(toolCall) {

                var name  = toolCall['function'].name,
                    entry = { id: toolCall.id, round: round, name: name, input: null, state: 'running' },
                    content;

                emit('tool', entry);

                try {

                    var input  = parseArguments(toolCall['function'].arguments),
                        op     = ops.operation(name),
                        action = op ? null : actionNamed(name);

                    entry.input = input;

                    if (!op && !action) { throw util.opError('invalid', 'There is no tool ' + JSON.stringify(name)); }

                    var result;
                    if (action) {
                        var outcome = await action.run(input, {
                            signal:   signal,
                            progress: function(value) { entry.progress = value; emit('tool', entry); }
                        });
                        if (signal.aborted) { throw stoppedError(); }
                        result = isObject(outcome) ? outcome.result : undefined;
                        if (isObject(outcome) && isObject(outcome.write)) {
                            await openTransaction();
                            if (signal.aborted) { throw stoppedError(); }
                            var written = tx.recorder.run(outcome.write.op, outcome.write.input);
                            turn.changes.push({ name: outcome.write.op, input: outcome.write.input, result: written, action: name });
                            remember(ops.operation(outcome.write.op), written);
                        }
                    } else if (op.effect === 'write') {
                        await openTransaction();
                        if (signal.aborted) { throw stoppedError(); }
                        result = tx.recorder.run(name, input);
                        turn.changes.push({ name: name, input: input, result: result });
                        remember(op, result);
                    } else {
                        result = ops.run(current(), name, input);
                    }

                    invalid = 0;
                    entry.state  = 'done';
                    entry.result = result;
                    content = resultText(result);

                } catch (e) {

                    if (signal.aborted) { throw stoppedError(); }

                    entry.state = 'failed';
                    entry.error = toolError(e);
                    if (entry.error.code === 'invalid') { invalid++; }
                    content = JSON.stringify({ error: entry.error });

                }

                messages.push({ role: 'tool', tool_call_id: toolCall.id, name: name, content: content });
                emit('tool', entry);

            }

            // Findings about what this turn touched.
            function findings() {

                if (!ConversationalUI.lint || options.lint === false || !turn.changes.length) { return []; }

                // A check that cannot run (an older FrameTrail without FrameTrailLint) leaves the turn as it is, and says so once.
                var result;
                try {
                    result = ConversationalUI.lint.run(current());
                } catch (e) {
                    if (!lintWarned) {
                        lintWarned = true;
                        console.warn('FrameTrail-Conversational-UI: changes are not checked: ' + e.message);
                    }
                    return [];
                }

                function hit(kind, ref) {
                    return ref !== undefined && touched.refs.indexOf(kind + ' ' + JSON.stringify(ref)) >= 0;
                }

                return result.findings.filter(function(finding) {
                    return hit(finding.kind, finding.ref)
                        || (isObject(finding.related) && hit(finding.related.kind, finding.related.ref))
                        || (finding.kind === 'subtitles' && touched.kinds.subtitles)
                        || ((finding.kind === 'chapters' || finding.kind === 'hypervideo') && touched.kinds.chapters);
                });

            }

            function checkMessage(list) {
                return '[Automatic check of your changes]\n' + list.map(function(finding) {
                    return '- ' + finding.severity + ' (' + finding.rule + ', ' + finding.kind
                        + ((finding.ref !== undefined) ? ' ' + JSON.stringify(finding.ref) : '') + '): ' + finding.message;
                }).join('\n') + '\nFix what you caused, or say why it is right as it is.';
            }

            async function loop() {

                if (text !== null) {
                    var summary = context(store),
                        prefix  = notes.splice(0).map(function(note) { return '[' + note + ']\n'; }).join('')
                                + (summary ? '[Hypervideo: ' + summary + ']\n' : '');
                    messages.push({ role: 'user', content: prefix ? prefix + '\n' + text : text });
                }

                compact(messages.length - 1);

                var tools      = agent.tools(store).concat(actions().map(function(action) {
                        return { type: 'function', 'function': util.clone(action.definition) };
                    })),
                    toolChoice = 'auto',
                    checked    = false;

                for (;;) {

                    if (round >= MAX_ROUNDS) {
                        throw models.chatError('rounds', 'The model kept calling tools for ' + MAX_ROUNDS + ' rounds');
                    }
                    round++;

                    var answer = await ask(body(tools, toolChoice));

                    if (isObject(answer.usage)) {
                        turn.usage.prompt_tokens += answer.usage.prompt_tokens || 0;
                        turn.usage.completion_tokens += answer.usage.completion_tokens || 0;
                    }

                    var calls = answer.message.tool_calls || [];
                    calls.forEach(function(toolCall) { if (!toolCall.id) { toolCall.id = randomId(9); } });

                    messages.push(answer.message);
                    if (answer.message.content) { emit('text', answer.message.content, round); }

                    if (!calls.length) {
                        if (!checked) {
                            checked = true;
                            var found = findings();
                            if (found.length) {
                                emit('check', found);
                                messages.push({ role: 'user', content: checkMessage(found) });
                                continue;
                            }
                        }
                        break;
                    }

                    for (var i = 0; i < calls.length; i++) {
                        await call(calls[i]);
                    }

                    toolChoice = (invalid >= MAX_INVALID) ? 'none' : 'auto';

                }

            }

            return loop().then(function() {
                turn.state = 'done';
                turn.lint  = findings();
                return closeTransaction(true);
            }, function(e) {
                answerOpenCalls(JSON.stringify({ error: { code: 'stopped', message: 'The turn ended before this ran.' } }));
                if (stopped || (e && e.code === 'stopped')) {
                    turn.state = 'stopped';
                    if (turn.changes.length) { notes.push('The user stopped your previous turn; its changes were taken back.'); }
                    return closeTransaction(false);
                }
                turn.state = (e && e.code === 'rateLimit') ? 'limited' : 'failed';
                turn.error = e;
                turn.lint  = findings();
                return closeTransaction(true);
            }).then(function() {
                running = null;
                return turn;
            });

        }

        return {
            id: id,
            get messages() { return messages; },
            get turns() { return turns; },
            get busy() { return !!running; },
            get model() { return options.model; },

            /**
             * I send the user's message and return a promise of the turn,
             * which never rejects: turn.state is done, stopped, limited or
             * failed (turn.error).
             */
            send: function(text, handlers) { return run(String(text), handlers); },

            // Whether the conversation can go on without a new message (after a turn that did not finish).
            canResume: canResume,

            resume: function(handlers) {
                if (!canResume()) { return Promise.reject(new Error('Nothing to resume')); }
                return run(null, handlers);
            },

            stop: function() { if (running) { running.stop(); } },

            // Something the model should know with the next message (e.g. that the user undid a turn).
            note: function(text) { notes.push(String(text)); },

            // Another adapter or model for the next turns (settings changed).
            configure: function(changes) {
                if (changes.adapter) { options.adapter = changes.adapter; }
                if (changes.model) { options.model = changes.model; }
                if (changes.reasoningEffort !== undefined) { options.reasoningEffort = changes.reasoningEffort; }
            }
        };

    }


    agent.conversation = conversation;
    agent.systemPrompt = systemPrompt;

})(window.FrameTrailConversationalUI);
