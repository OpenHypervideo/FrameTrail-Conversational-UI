/*
 * FrameTrail-Conversational-UI — transcription (ConversationalUI.media): the
 * client of the server's route transcribe (server/transcribe.php), and the
 * WebVTT the panel makes of its result. Server mode only.
 *
 *     media.transcribe({ url, hypervideoId, language, signal, onProgress })
 *         .then(function(result) { result.language; result.duration; result.offset; result.segments; });
 *     media.toVtt(result.segments, { offset: result.offset, start, end });   // { vtt, cues }
 *     media.languageCode('german');                                           // 'de'
 *
 * The route answers a refusal with an HTTP status and { error: { code,
 * message } }, otherwise with server-sent events: progress ({ stage:
 * extracting | sending | transcribing, elapsed, sent?, total? }), then result
 * or error. Errors are media errors (mediaError) with a code the panel puts
 * into words: login, notAllowed, notConfigured, notFound, noFile, tooLarge,
 * timeout, request, service, network, stopped.
 */

(function(ConversationalUI) {

    var media = ConversationalUI.media;

    var CODES = ['login', 'notAllowed', 'notConfigured', 'notFound', 'noFile', 'tooLarge', 'timeout', 'quota'];

    // Whisper's languages by the names OpenAI's API and whisper.cpp give (speaches gives codes).
    var LANGUAGES = {
        afrikaans: 'af', albanian: 'sq', amharic: 'am', arabic: 'ar', armenian: 'hy', assamese: 'as', azerbaijani: 'az',
        bashkir: 'ba', basque: 'eu', belarusian: 'be', bengali: 'bn', bosnian: 'bs', breton: 'br', bulgarian: 'bg',
        burmese: 'my', cantonese: 'yue', castilian: 'es', catalan: 'ca', chinese: 'zh', croatian: 'hr', czech: 'cs',
        danish: 'da', dutch: 'nl', english: 'en', estonian: 'et', faroese: 'fo', finnish: 'fi', flemish: 'nl',
        french: 'fr', galician: 'gl', georgian: 'ka', german: 'de', greek: 'el', gujarati: 'gu', haitian: 'ht',
        'haitian creole': 'ht', hausa: 'ha', hawaiian: 'haw', hebrew: 'he', hindi: 'hi', hungarian: 'hu',
        icelandic: 'is', indonesian: 'id', italian: 'it', japanese: 'ja', javanese: 'jw', kannada: 'kn', kazakh: 'kk',
        khmer: 'km', korean: 'ko', lao: 'lo', latin: 'la', latvian: 'lv', letzeburgesch: 'lb', lingala: 'ln',
        lithuanian: 'lt', luxembourgish: 'lb', macedonian: 'mk', malagasy: 'mg', malay: 'ms', malayalam: 'ml',
        maltese: 'mt', maori: 'mi', marathi: 'mr', moldavian: 'ro', moldovan: 'ro', mongolian: 'mn', myanmar: 'my',
        nepali: 'ne', norwegian: 'no', nynorsk: 'nn', occitan: 'oc', panjabi: 'pa', pashto: 'ps', persian: 'fa',
        polish: 'pl', portuguese: 'pt', punjabi: 'pa', pushto: 'ps', romanian: 'ro', russian: 'ru', sanskrit: 'sa',
        serbian: 'sr', shona: 'sn', sindhi: 'sd', sinhala: 'si', sinhalese: 'si', slovak: 'sk', slovenian: 'sl',
        somali: 'so', spanish: 'es', sundanese: 'su', swahili: 'sw', swedish: 'sv', tagalog: 'tl', tajik: 'tg',
        tamil: 'ta', tatar: 'tt', telugu: 'te', thai: 'th', tibetan: 'bo', turkish: 'tr', turkmen: 'tk',
        ukrainian: 'uk', urdu: 'ur', uzbek: 'uz', valencian: 'ca', vietnamese: 'vi', welsh: 'cy', yiddish: 'yi',
        yoruba: 'yo'
    };

    function isObject(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    /**
     * I make the error transcribe() rejects with: code (see above), message
     * (the server's words where there are any), status.
     */
    function mediaError(code, message, extra) {
        var error = new Error(message || code);
        error.name = 'ConversationalUiMediaError';
        error.code = code;
        Object.keys(extra || {}).forEach(function(key) { error[key] = extra[key]; });
        return error;
    }

    // An error the server sent, as { error: { code?, message, period?, resetsAt? } }, with the HTTP status it came with.
    function serverError(body, status) {
        var error   = isObject(body) && isObject(body.error) ? body.error : {},
            message = (typeof error.message === 'string') ? error.message : '',
            code    = (CODES.indexOf(error.code) >= 0) ? error.code
                    : (status >= 400 && status < 500 && status !== 408 && status !== 429) ? 'request' : 'service',
            extra   = { status: status };
        // A gateway's quota refusal says for how long and until when.
        if (typeof error.period === 'string') { extra.period = error.period; }
        if (typeof error.resetsAt === 'string') { extra.resetsAt = error.resetsAt; }
        return mediaError(code, message || ('The server answered with status ' + status), extra);
    }

    function parseJson(text) {
        try { return JSON.parse(text); } catch (e) { return null; }
    }

    /**
     * I read server-sent events from a fetch Response, as they arrive, and
     * hand each to onEvent(name, data) (data parsed as JSON, null when it is
     * not). Comments and events without data are left out.
     *
     * @param {Response} response
     * @param {Function} onEvent
     * @return {Promise}  resolved when the stream ends
     */
    function readEvents(response, onEvent) {

        var buffer = '';

        function handle(block) {
            var name = 'message', data = [];
            block.split(/\r\n|\r|\n/).forEach(function(line) {
                if (line === '' || line.charAt(0) === ':') { return; }
                var colon = line.indexOf(':'),
                    field = (colon < 0) ? line : line.slice(0, colon),
                    value = (colon < 0) ? '' : line.slice(colon + 1).replace(/^ /, '');
                if (field === 'event') { name = value; }
                if (field === 'data') { data.push(value); }
            });
            if (data.length) { onEvent(name, parseJson(data.join('\n'))); }
        }

        function take(text, last) {
            buffer += text;
            var parts = buffer.split(/\r\n\r\n|\n\n|\r\r/);
            buffer = last ? '' : parts.pop();
            parts.forEach(handle);
        }

        if (!response.body || typeof response.body.getReader !== 'function') {
            return response.text().then(function(text) { take(text, true); });
        }

        var reader  = response.body.getReader(),
            decoder = new TextDecoder();

        function pump() {
            return reader.read().then(function(step) {
                if (step.done) {
                    take(decoder.decode(), true);
                    return;
                }
                take(decoder.decode(step.value, { stream: true }), false);
                return pump();
            });
        }

        return pump();

    }

    /**
     * I transcribe the video of a hypervideo on the server.
     *
     * @param {Object} options
     * @param {String} options.url            the route's URL (StorageManager.extensionURL('conversational-ui', 'transcribe'))
     * @param {String} options.hypervideoId
     * @param {String} [options.language]     a language code; none: the speech server detects it
     * @param {AbortSignal} [options.signal]  stops it, on the server too
     * @param {Function} [options.onProgress] ({ stage, elapsed, sent?, total? })
     * @param {Function} [options.fetch]      (tests)
     * @return {Promise}  of { language, duration, offset, segments: [{ start, end, text }] }
     */
    function transcribe(options) {

        var send   = options.fetch || window.fetch.bind(window),
            signal = options.signal || null,
            body   = { hypervideoId: String(options.hypervideoId) };

        if (options.language) { body.language = options.language; }

        function stopped() { return mediaError('stopped', 'Stopped'); }

        return Promise.resolve().then(function() {
            if (signal && signal.aborted) { throw stopped(); }
            return send(options.url, {
                method:      'POST',
                headers:     { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
                body:        JSON.stringify(body),
                credentials: 'same-origin',
                signal:      signal || undefined
            });
        }).then(function(response) {

            if (!response.ok) {
                return response.text().then(function(text) { throw serverError(parseJson(text), response.status); });
            }

            var result = null, failure = null;

            return readEvents(response, function(name, data) {
                if (name === 'progress' && isObject(data) && options.onProgress) {
                    try { options.onProgress(data); } catch (e) { console.error(e); }
                } else if (name === 'result' && isObject(data)) {
                    result = data;
                } else if (name === 'error') {
                    failure = serverError(data, 502);
                }
            }).then(function() {
                if (failure) { throw failure; }
                if (!result || !Array.isArray(result.segments)) { throw mediaError('service', 'The transcription broke off.'); }
                return {
                    language: (typeof result.language === 'string') ? result.language : null,
                    duration: (typeof result.duration === 'number') ? result.duration : null,
                    offset:   (typeof result.offset === 'number') ? result.offset : 0,
                    segments: result.segments
                };
            });

        }).catch(function(e) {
            if ((signal && signal.aborted) || (e && e.name === 'AbortError')) { throw stopped(); }
            if (e && e.name === 'ConversationalUiMediaError') { throw e; }
            throw mediaError('network', (e && e.message) ? e.message : String(e));
        });

    }

    // Seconds as hh:mm:ss.mmm.
    function timestamp(seconds) {
        var ms  = Math.max(0, Math.round(seconds * 1000)),
            pad = function(n, size) { n = String(n); while (n.length < size) { n = '0' + n; } return n; };
        return pad(Math.floor(ms / 3600000), 2) + ':' + pad(Math.floor(ms / 60000) % 60, 2) + ':'
            + pad(Math.floor(ms / 1000) % 60, 2) + '.' + pad(ms % 1000, 3);
    }

    // Cue text as WebVTT needs it: no "<", no "&" that reads as a reference, no "-->".
    function cueText(text) {
        return text.replace(/&(?=[A-Za-z0-9#])/g, '&amp;').replace(/</g, '&lt;').replace(/-->/g, '--&gt;');
    }

    /**
     * I make WebVTT of a transcription's segments: their times moved by the
     * offset (the clip's in point, when only its span was transcribed),
     * those outside [start, end] left out and those across an edge cut at
     * it, in order of time; the text on one line, Whisper's markers of
     * silence and empty segments left out.
     *
     * @param {Array} segments  [{ start, end, text }]
     * @param {Object} [options] { offset, start, end } in seconds (start and end: the span the player shows)
     * @return {Object}  { vtt, cues: the number of cues }
     */
    function toVtt(segments, options) {

        options = options || {};

        var offset = (typeof options.offset === 'number') ? options.offset : 0,
            from   = (typeof options.start === 'number') ? options.start : null,
            to     = (typeof options.end === 'number') ? options.end : null,
            cues   = [];

        (Array.isArray(segments) ? segments : []).forEach(function(segment, index) {

            if (!isObject(segment) || typeof segment.start !== 'number' || typeof segment.end !== 'number' || typeof segment.text !== 'string') { return; }

            var text = segment.text.replace(/\[BLANK_AUDIO\]/gi, ' ').replace(/\s+/g, ' ').trim(),
                s    = segment.start + offset,
                e    = Math.max(segment.end + offset, s);

            if (text === '' || !isFinite(s) || !isFinite(e)) { return; }
            if (e === s) { e = s + 0.5; }
            if ((to !== null && s >= to) || (from !== null && e <= from)) { return; }

            if (from !== null) { s = Math.max(s, from); }
            if (to !== null) { e = Math.min(e, to); }

            cues.push({ start: s, end: e, text: text, index: index });

        });

        cues.sort(function(a, b) { return (a.start - b.start) || (a.index - b.index); });

        return {
            vtt:  'WEBVTT\n' + cues.map(function(cue) {
                return '\n' + timestamp(cue.start) + ' --> ' + timestamp(cue.end) + '\n' + cueText(cue.text) + '\n';
            }).join(''),
            cues: cues.length
        };

    }

    /**
     * I return the language code for what a speech server says it heard:
     * a code as it is (lower case), a name as its code, or null.
     *
     * @param {String} value
     * @return {String|null}
     */
    function languageCode(value) {
        if (typeof value !== 'string') { return null; }
        var text = value.trim().toLowerCase();
        if (/^[a-z]{2,3}$/.test(text)) { return text; }
        return LANGUAGES[text] || null;
    }


    media.transcribe   = transcribe;
    media.readEvents   = readEvents;
    media.toVtt        = toVtt;
    media.languageCode = languageCode;
    media.mediaError   = mediaError;
    media.LANGUAGES    = LANGUAGES;

})(window.FrameTrailConversationalUI);
