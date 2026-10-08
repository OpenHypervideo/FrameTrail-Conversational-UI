/*
 * FrameTrail-Conversational-UI — the model's tools (ConversationalUI.agent.tools):
 * the operations of shared/operations.json as Mistral function tools, each
 * with its input schema made to stand alone.
 *
 *     agent.tools(store);   // [{ type: 'function', function: { name, description, parameters } }]
 *
 * Only what the user may do now is offered: a write operation whose
 * preconditions need a permission the store refuses is left out.
 *
 * FrameTrail's own schemas would make the tools far too large to send with
 * every request (the overlay body alone is over 100 KB): references into them
 * are inlined when the result is small (a keyframe, tags, events), otherwise
 * replaced by their type and the description the manifest writes beside the
 * reference. The operation describe_type and the type guide in the prompt
 * tell the rest, and the interpreter validates every input against the full
 * schemas.
 */

(function(ConversationalUI) {

    var agent = ConversationalUI.agent,
        ops   = ConversationalUI.ops,
        util  = ops.util;

    var FRAMETRAIL_SCHEMAS = 'https://frametrail.org/schemas/';

    // A reference into FrameTrail's schemas larger than this (as JSON) is replaced, not inlined.
    var INLINE_LIMIT = 1500;

    // The keywords a tool's parameters keep.
    var KEYWORDS = ['type', 'description', 'properties', 'required', 'items', 'enum', 'minimum', 'maximum', 'minItems', 'maxItems', 'anyOf', 'default'];

    // Which permission a precondition needs, as the store tells it.
    var PERMISSIONS = { canEditHypervideo: 'overlays', canAnnotate: 'annotations' };

    function isObject(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    function documents() {
        var docs = {};
        window.FrameTrailSchemas.forEach(function(schema) { docs[schema.$id.split('#')[0]] = schema; });
        docs[ops.OPERATIONS_ID] = { $id: ops.OPERATIONS_ID, $defs: ops.manifest().$defs };
        return docs;
    }

    // The JSON type a schema stands for, to keep when it is replaced.
    function typeOf(schema) {
        if (isObject(schema) && schema.type !== undefined) { return schema.type; }
        var alternatives = isObject(schema) ? (schema.oneOf || schema.anyOf) : null;
        if (Array.isArray(alternatives)) {
            var types = [];
            alternatives.forEach(function(alternative) {
                var type = typeOf(alternative);
                (Array.isArray(type) ? type : [type]).forEach(function(t) {
                    if (typeof t === 'string' && types.indexOf(t) < 0) { types.push(t); }
                });
            });
            return (types.length === 1) ? types[0] : (types.length ? types : 'object');
        }
        return 'object';
    }

    // PHP's [] for an empty object, which FrameTrail's schemas allow beside objects: nothing a model should write.
    function isEmptyListOnly(schema) {
        return isObject(schema) && Array.isArray(schema.const) && schema.const.length === 0;
    }

    /**
     * I bring an inlined schema down to the keywords tools keep: const
     * becomes a one-value enum, oneOf an anyOf (an alternative that only
     * allows PHP's empty list is dropped, one left is merged in), anything
     * else (pattern, $comment, …) goes.
     */
    function simplify(schema) {

        if (Array.isArray(schema)) { return schema.map(simplify); }
        if (!isObject(schema)) { return schema; }

        var source = Object.assign({}, schema),
            out    = {};

        if (source.const !== undefined && source.enum === undefined) {
            source.enum = [source.const];
        }

        var alternatives = source.oneOf || source.anyOf;
        if (Array.isArray(alternatives)) {
            alternatives = alternatives.filter(function(alternative) { return !isEmptyListOnly(alternative); });
            if (alternatives.length === 1) {
                var only = alternatives[0];
                delete source.oneOf;
                delete source.anyOf;
                source = Object.assign({}, only, source);
            } else {
                source.anyOf = alternatives;
            }
        }

        KEYWORDS.forEach(function(key) {
            if (source[key] === undefined) { return; }
            if (key === 'properties') {
                out.properties = {};
                Object.keys(source.properties).forEach(function(name) { out.properties[name] = simplify(source.properties[name]); });
            } else if (key === 'items' || key === 'anyOf') {
                out[key] = simplify(source[key]);
            } else {
                out[key] = util.clone(source[key]);
            }
        });

        return out;

    }

    /**
     * I return an operation's input schema as a tool's parameters.
     *
     * @param {Object} operation an entry of the manifest
     * @param {Object} [docs] the schema documents (made when not given)
     */
    function parameters(operation, docs) {

        docs = docs || documents();

        var inlined = util.inlineSchema(operation.input, ops.OPERATIONS_ID, docs, function(uri) {
            if (uri.indexOf(FRAMETRAIL_SCHEMAS) !== 0) { return undefined; }
            var full = util.inlineSchema({ $ref: uri }, uri, docs);
            return (JSON.stringify(full).length > INLINE_LIMIT) ? { type: typeOf(full) } : full;
        });

        var out = simplify(inlined);
        if (!isObject(out.properties)) { out.properties = {}; }
        return out;

    }

    // Whether the store lets the user do what an operation needs.
    function allowed(store, operation, answers) {
        return operation.preconditions.every(function(name) {
            var kind = PERMISSIONS[name];
            if (!kind) { return true; }
            if (answers[kind] === undefined) {
                var permission = store.permission(kind);
                answers[kind] = !!(permission && permission.allowed === true);
            }
            return answers[kind];
        });
    }

    /**
     * I return the tools for a conversation about the store's hypervideo:
     * every operation the user may use now, as a Mistral function tool.
     *
     * @param {Object} store
     * @return {Array}
     */
    function tools(store) {

        var docs    = documents(),
            answers = {};

        return ops.manifest().operations.filter(function(operation) {
            return operation.effect === 'read' || allowed(store, operation, answers);
        }).map(function(operation) {
            return {
                type: 'function',
                'function': {
                    name:        operation.name,
                    description: operation.description,
                    parameters:  parameters(operation, docs)
                }
            };
        });

    }


    agent.tools          = tools;
    agent.toolParameters = parameters;

})(window.FrameTrailConversationalUI);
