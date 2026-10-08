/*
 * FrameTrail-Conversational-UI — the relay adapter
 * (ConversationalUI.models.relay): Mistral's API through the add-on's server
 * part, which holds the key (server mode). The relay passes requests and
 * answers through unchanged; the client and the relay speak the same API.
 *
 *     var adapter = models.relay({ url: …, post: …, models: [...] });
 *
 * - Streaming: POST the request as JSON to the route `relay`
 *   (_server/extension.php?e=conversational-ui&r=relay); the answer is
 *   Mistral's stream of server-sent events, or an error with Mistral's status
 *   and body.
 * - Without streaming: the action conversationalUiChat with the request as
 *   JSON text in `request`; it answers { status: 'success', response: <the
 *   completion> } or { status: 'fail', code: <HTTP status>, string, error }.
 * - The relay's own refusals come as { error: { code, message } }, code one
 *   of login, quota, notConfigured, notAllowed (models/chat.js).
 */

(function(ConversationalUI) {

    var models = ConversationalUI.models;

    function isObject(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    // An action's answer as the part of a fetch Response that chat() reads.
    function asResponse(answer) {

        var ok     = isObject(answer) && answer.status === 'success',
            status = ok ? 200 : (isObject(answer) && typeof answer.code === 'number' && answer.code >= 400) ? answer.code : 502,
            body   = ok ? answer.response
                : (isObject(answer) && isObject(answer.error)) ? { error: answer.error }
                : { message: (isObject(answer) && typeof answer.string === 'string') ? answer.string : 'The relay did not answer' };

        return {
            ok:      ok,
            status:  status,
            headers: { get: function(name) { return (/^retry-after$/i.test(name) && isObject(answer) && answer.retryAfter !== undefined) ? String(answer.retryAfter) : null; } },
            body:    null,
            text:    function() { return Promise.resolve(JSON.stringify(body)); }
        };

    }

    /**
     * @param {Object} options
     * @param {String} options.url     the relay route's URL (StorageManager.extensionURL())
     * @param {Function} options.post  sends an action: (URLSearchParams) → promise of its answer (StorageManager.serverPost())
     * @param {Array} [options.models] the models the relay allows, as listModels() gives them
     */
    function relay(options) {

        return {

            id: 'relay',

            post: function(body, signal) {

                if (body.stream) {
                    return fetch(options.url, {
                        method:      'POST',
                        headers:     { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
                        body:        JSON.stringify(body),
                        credentials: 'same-origin',
                        signal:      signal
                    });
                }

                return Promise.resolve(options.post(new URLSearchParams({
                    a:       'conversationalUiChat',
                    request: JSON.stringify(body)
                }))).then(asResponse);

            },

            listModels: function() {
                return Promise.resolve((options.models || []).slice());
            }

        };

    }


    models.relay = relay;

})(window.FrameTrailConversationalUI);
