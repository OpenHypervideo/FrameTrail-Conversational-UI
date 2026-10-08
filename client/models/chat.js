/*
 * FrameTrail-Conversational-UI — Mistral's Chat Completions API
 * (ConversationalUI.models.chat): one request, streamed or not, read into the
 * assistant's message, whichever adapter carries it (models/mistral.js
 * directly, models/relay.js through the server).
 *
 *     models.chat(adapter, { model, messages, tools, tool_choice: 'auto' }, { signal, onText })
 *         .then(function(answer) { answer.message; answer.finishReason; answer.usage; });
 *
 * An adapter has post(body, signal) → a promise of a fetch Response (or an
 * object with ok, status, headers.get(), text() and, when streaming, body);
 * chat() writes the request body's stream field. Errors are chat errors
 * (chatError) with a code the panel puts into words: key, model, rateLimit
 * (with retryAfter in seconds when the answer said), request, service,
 * network, stopped, or what the relay names (login, quota, notConfigured,
 * notAllowed).
 */

(function(ConversationalUI) {

    var models = ConversationalUI.models;

    // A stream that sends nothing for this long is given up for one request without streaming.
    var FIRST_CHUNK_TIMEOUT = 30000;

    var RELAY_CODES = ['login', 'quota', 'notConfigured', 'notAllowed'];


    /* ------------------------------------------------------------------ */
    /*  Errors                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * I make the error chat() throws: code (see above), message (the
     * provider's words where there are any), and what else is known
     * (status, retryAfter).
     */
    function chatError(code, message, extra) {
        var error = new Error(message || code);
        error.name = 'ConversationalUiChatError';
        error.code = code;
        Object.keys(extra || {}).forEach(function(key) { error[key] = extra[key]; });
        return error;
    }

    function isObject(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    // Seconds to wait from a Retry-After header: seconds, or an HTTP date.
    function retryAfter(value) {
        if (value === null || value === undefined || value === '') { return undefined; }
        var seconds = Number(value);
        if (isFinite(seconds)) { return Math.max(0, seconds); }
        var date = Date.parse(value);
        return isFinite(date) ? Math.max(0, Math.round((date - Date.now()) / 1000)) : undefined;
    }

    // What an error answer says: Mistral's { message }, { error: { message } }, or a list of validation details.
    function detailOf(text) {
        var json = null;
        try { json = JSON.parse(text); } catch (e) { return { message: String(text || '').slice(0, 500) }; }
        if (!isObject(json)) { return { message: String(text).slice(0, 500) }; }
        var error = isObject(json.error) ? json.error : null,
            message = (error && typeof error.message === 'string') ? error.message
                : (typeof json.message === 'string') ? json.message
                : Array.isArray(json.detail) ? json.detail.map(function(d) {
                    return (isObject(d) ? (d.msg || '') + (Array.isArray(d.loc) ? ' (' + d.loc.join('.') + ')' : '') : String(d));
                }).join('; ')
                : (typeof json.detail === 'string') ? json.detail
                : String(text).slice(0, 500);
        return { message: message, code: (error && typeof error.code === 'string') ? error.code : undefined };
    }

    /**
     * I turn an answer that is not a success into a chat error.
     *
     * @param {Object} response
     * @return {Promise} rejected with the error
     */
    function failed(response) {

        return Promise.resolve(response.text()).catch(function() { return ''; }).then(function(text) {

            var detail = detailOf(text),
                status = response.status,
                extra  = { status: status };

            if (detail.code && RELAY_CODES.indexOf(detail.code) >= 0) {
                if (status === 429) { extra.retryAfter = retryAfter(response.headers && response.headers.get('Retry-After')); }
                throw chatError(detail.code, detail.message, extra);
            }

            if (status === 401 || status === 403) {
                throw chatError('key', detail.message, extra);
            }
            if (status === 429) {
                extra.retryAfter = retryAfter(response.headers && response.headers.get('Retry-After'));
                throw chatError('rateLimit', detail.message, extra);
            }
            if ((status === 400 || status === 404 || status === 422) && /\bmodel\b/i.test(detail.message)) {
                throw chatError('model', detail.message, extra);
            }
            if (status >= 500) {
                throw chatError('service', detail.message, extra);
            }
            throw chatError('request', detail.message, extra);

        });

    }


    /* ------------------------------------------------------------------ */
    /*  The answer                                                        */
    /* ------------------------------------------------------------------ */

    // Text out of content: a string, or a list of chunks of which the text ones count (reasoning models also send "thinking").
    function textOf(content) {
        if (typeof content === 'string') { return content; }
        if (!Array.isArray(content)) { return ''; }
        return content.map(function(part) {
            return (isObject(part) && part.type === 'text' && typeof part.text === 'string') ? part.text : '';
        }).join('');
    }

    /**
     * I collect an assistant message from the deltas of a stream (or from one
     * complete message): its text, and its tool calls by index, whether they
     * come whole or in pieces. A call that brings another id under an index
     * already taken is a new call.
     */
    function collector() {

        var text  = '',
            calls = [],
            byIndex = {};

        function addCalls(list) {

            (Array.isArray(list) ? list : []).forEach(function(delta, position) {

                if (!isObject(delta)) { return; }

                var index = (typeof delta.index === 'number') ? delta.index : null,
                    call  = (index !== null) ? byIndex[index] : null;

                if (index === null && typeof delta.id !== 'string') { call = calls[calls.length - 1] || null; }
                if (call && typeof delta.id === 'string' && delta.id !== '' && call.id !== '' && call.id !== delta.id) { call = null; }

                if (!call) {
                    call = { id: '', type: 'function', 'function': { name: '', arguments: '' } };
                    calls.push(call);
                    if (index !== null) { byIndex[index] = call; }
                }

                if (typeof delta.id === 'string' && delta.id !== '') { call.id = delta.id; }

                var fn = isObject(delta['function']) ? delta['function'] : {};
                if (typeof fn.name === 'string' && fn.name !== '') { call['function'].name = fn.name; }
                if (typeof fn.arguments === 'string') {
                    call['function'].arguments += fn.arguments;
                } else if (fn.arguments !== undefined && fn.arguments !== null) {
                    call['function'].arguments = JSON.stringify(fn.arguments);
                }

            });

        }

        return {
            add: function(delta) {
                var piece = textOf(delta.content);
                text += piece;
                addCalls(delta.tool_calls);
                return piece;
            },
            get text() { return text; },
            message: function() {
                var message = { role: 'assistant', content: text };
                if (calls.length) { message.tool_calls = calls; }
                return message;
            }
        };

    }

    /**
     * I read a stream of server-sent events into the answer. onChunk is
     * called for every event, onText with every piece of text.
     */
    function readStream(response, onChunk, onText) {

        var reader  = response.body.getReader(),
            decoder = new TextDecoder(),
            buffer  = '',
            collect = collector(),
            finish  = null,
            usage   = null,
            model   = null,
            done    = false;

        function handle(event) {

            var data = event.split(/\r?\n/).filter(function(line) {
                return line.indexOf('data:') === 0;
            }).map(function(line) {
                return line.slice(5).replace(/^ /, '');
            }).join('\n');

            if (data === '') { return; }
            if (data === '[DONE]') { done = true; return; }

            var chunk;
            try { chunk = JSON.parse(data); } catch (e) { throw chatError('service', 'Unreadable answer: ' + data.slice(0, 200)); }

            if (isObject(chunk.error) || chunk.object === 'error') {
                throw chatError('service', detailOf(data).message);
            }

            onChunk();

            if (typeof chunk.model === 'string') { model = chunk.model; }
            if (isObject(chunk.usage)) { usage = chunk.usage; }

            var choice = Array.isArray(chunk.choices) ? chunk.choices[0] : null;
            if (!isObject(choice)) { return; }

            var piece = collect.add(isObject(choice.delta) ? choice.delta : {});
            if (piece && onText) { onText(piece, collect.text); }
            if (choice.finish_reason) { finish = choice.finish_reason; }

        }

        function pump() {
            return reader.read().then(function(step) {
                if (step.done) {
                    buffer += decoder.decode();
                    if (buffer.trim() !== '') { handle(buffer); }
                    return;
                }
                buffer += decoder.decode(step.value, { stream: true });
                var events = buffer.split(/\r?\n\r?\n/);
                buffer = events.pop();
                events.forEach(handle);
                return done ? reader.cancel().catch(function() {}) : pump();
            });
        }

        return pump().then(function() {
            return { message: collect.message(), finishReason: finish, usage: usage, model: model };
        });

    }

    // The answer of a request without streaming.
    function readCompletion(completion) {
        var choice  = (isObject(completion) && Array.isArray(completion.choices)) ? completion.choices[0] : null,
            collect = collector();
        if (!isObject(choice) || !isObject(choice.message)) {
            throw chatError('service', 'The answer holds no message');
        }
        collect.add(choice.message);
        return {
            message:      collect.message(),
            finishReason: choice.finish_reason || null,
            usage:        isObject(completion.usage) ? completion.usage : null,
            model:        (typeof completion.model === 'string') ? completion.model : null
        };
    }


    /* ------------------------------------------------------------------ */
    /*  Requests                                                          */
    /* ------------------------------------------------------------------ */

    function stopped() {
        return chatError('stopped', 'Stopped');
    }

    // One request through the adapter; network failures and stops as chat errors.
    function post(adapter, body, signal) {
        if (signal && signal.aborted) { return Promise.reject(stopped()); }
        return Promise.resolve().then(function() {
            if (signal && signal.aborted) { throw stopped(); }
            return adapter.post(body, signal);
        }).catch(function(e) {
            if (signal && signal.aborted) { throw stopped(); }
            if (e && e.name === 'ConversationalUiChatError') { throw e; }
            throw chatError('network', (e && e.message) || 'The request did not go through');
        });
    }

    function complete(adapter, body, options) {

        var request = Object.assign({}, body, { stream: false });

        return post(adapter, request, options.signal).then(function(response) {
            if (!response.ok) { return failed(response); }
            return Promise.resolve(response.text()).then(function(text) {
                var completion;
                try { completion = JSON.parse(text); } catch (e) { throw chatError('service', 'Unreadable answer: ' + String(text).slice(0, 200)); }
                var answer = readCompletion(completion);
                if (answer.message.content && options.onText) { options.onText(answer.message.content, answer.message.content); }
                return answer;
            });
        }).catch(function(e) {
            if (options.signal && options.signal.aborted) { throw stopped(); }
            throw e;
        });

    }

    function stream(adapter, body, options) {

        var controller = new AbortController(),
            timedOut   = false,
            timer      = null,
            outer      = options.signal;

        function abort() { controller.abort(); }
        if (outer) { outer.addEventListener('abort', abort); }

        function arm() {
            timer = setTimeout(function() { timedOut = true; controller.abort(); }, options.firstChunkTimeout || FIRST_CHUNK_TIMEOUT);
        }
        function disarm() {
            if (timer !== null) { clearTimeout(timer); timer = null; }
        }

        arm();

        return post(adapter, Object.assign({}, body, { stream: true }), controller.signal).then(function(response) {
            if (!response.ok) { disarm(); return failed(response); }
            if (!response.body || typeof response.body.getReader !== 'function') {
                disarm();
                return Promise.resolve(response.text()).then(function(text) { return readCompletion(JSON.parse(text)); });
            }
            return readStream(response, disarm, options.onText);
        }).then(function(answer) {
            disarm();
            if (outer) { outer.removeEventListener('abort', abort); }
            return answer;
        }, function(e) {
            disarm();
            if (outer) { outer.removeEventListener('abort', abort); }
            if (outer && outer.aborted) { throw stopped(); }
            if (timedOut) {
                // Nothing came: a server or proxy that holds the stream back. The rest of the conversation goes without.
                adapter.streaming = false;
                return complete(adapter, body, options);
            }
            if (controller.signal.aborted) { throw stopped(); }
            if (e && e.name === 'ConversationalUiChatError') { throw e; }
            throw chatError('network', (e && e.message) || 'The answer broke off');
        });

    }

    /**
     * I send a request for the assistant's next message and return a promise
     * of { message: { role, content, tool_calls? }, finishReason, usage,
     * model }. The answer is streamed unless the adapter says it cannot
     * stream (adapter.streaming === false), which it learns when a stream
     * sends nothing in time.
     *
     * @param {Object} adapter
     * @param {Object} body the request: model, messages, tools, tool_choice, …
     * @param {Object} [options] { signal, onText(piece, text), firstChunkTimeout (ms) }
     * @return {Promise}
     */
    function chat(adapter, body, options) {
        options = options || {};
        return (adapter.streaming === false) ? complete(adapter, body, options) : stream(adapter, body, options);
    }


    models.chat       = chat;
    models.chatError  = chatError;
    models.failure    = failed;
    models.retryAfter = retryAfter;

})(window.FrameTrailConversationalUI);
