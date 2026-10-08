/*
 * FrameTrail-Conversational-UI — the direct adapter
 * (ConversationalUI.models.mistral): Mistral's API straight from the browser,
 * with the user's own key. Mistral answers requests from every origin (also
 * file:// pages), so this needs no setup; the endpoint is Mistral's, not a
 * setting.
 *
 *     var adapter = models.mistral({ key: '…' });
 *     models.chat(adapter, { model: 'mistral-medium-latest', messages: […] });
 *     adapter.listModels().then(function(list) { … });   // [{ id, name, aliases, maxContext }]
 *     adapter.test('mistral-medium-latest');             // resolves, or rejects with a chat error
 */

(function(ConversationalUI) {

    var models = ConversationalUI.models;

    var BASE = 'https://api.mistral.ai/v1';

    function isObject(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    /**
     * The models of a /v1/models answer that can hold a conversation with
     * tools, each once (aliases folded into the model they name), sorted by
     * id. Mistral lists base models and the user's fine-tuned ones alike.
     *
     * @param {Object} answer { data: [{ id, name, aliases, capabilities, archived, max_context_length, … }] }
     * @return {Array} [{ id, name, aliases, maxContext }]
     */
    function chatModels(answer) {

        var list = (isObject(answer) && Array.isArray(answer.data)) ? answer.data : [],
            seen = {},
            out  = [];

        list.forEach(function(entry) {

            var capabilities = isObject(entry && entry.capabilities) ? entry.capabilities : {};

            if (!isObject(entry) || typeof entry.id !== 'string' || entry.archived === true
                || capabilities.completion_chat !== true || capabilities.function_calling !== true) {
                return;
            }
            if (seen[entry.id]) { return; }

            var aliases = Array.isArray(entry.aliases) ? entry.aliases.filter(function(alias) { return typeof alias === 'string'; }) : [];
            aliases.concat(entry.id).forEach(function(id) { seen[id] = true; });

            out.push({
                id:         entry.id,
                name:       (typeof entry.name === 'string' && entry.name !== '') ? entry.name : entry.id,
                aliases:    aliases,
                maxContext: (typeof entry.max_context_length === 'number') ? entry.max_context_length : null
            });

        });

        return out.sort(function(a, b) { return (a.id < b.id) ? -1 : (a.id > b.id) ? 1 : 0; });

    }

    /**
     * @param {Object} options { key }
     */
    function mistral(options) {

        var key = String((options && options.key) || '').trim();

        function headers() {
            // Only what Mistral's CORS answer allows (Authorization, Content-Type).
            return {
                'Content-Type':  'application/json',
                'Authorization': 'Bearer ' + key
            };
        }

        var adapter = {

            id: 'mistral',

            post: function(body, signal) {
                return fetch(BASE + '/chat/completions', {
                    method:  'POST',
                    headers: headers(),
                    body:    JSON.stringify(body),
                    signal:  signal
                });
            },

            listModels: function(signal) {
                return fetch(BASE + '/models', { headers: headers(), signal: signal }).catch(function(e) {
                    throw models.chatError('network', (e && e.message) || 'The request did not go through');
                }).then(function(response) {
                    if (!response.ok) { return models.failure(response); }
                    return response.json().then(chatModels);
                });
            },

            /**
             * I check key and model with the smallest possible request, and
             * reject with the chat error that tells what is wrong.
             */
            test: function(model, signal) {
                return models.chat({ post: adapter.post, streaming: false }, {
                    model:      model,
                    messages:   [{ role: 'user', content: 'Reply with: OK' }],
                    max_tokens: 3
                }, { signal: signal });
            }

        };

        return adapter;

    }


    models.mistral    = mistral;
    models.chatModels = chatModels;

})(window.FrameTrailConversationalUI);
