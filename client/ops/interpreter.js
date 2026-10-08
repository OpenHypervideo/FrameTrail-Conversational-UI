/*
 * FrameTrail-Conversational-UI — the interpreter of the operations in
 * shared/operations.json (FrameTrailConversationalUI.operations, embedded by
 * the build): their input is validated against the manifest's schemas, their
 * preconditions checked, and they are carried out against a store — the live
 * store (the editor) or the model store (a bundle). A changeset applies
 * several write operations as one: in one transaction, so all of them or
 * none, and comes back with each one's inverse.
 *
 *     ops.run(store, 'list_items', { kind: 'chapters' });
 *     var applied = ops.apply(store, { summary: 'Add chapters', ops: [{ op: 'add_chapter', input: { start: 0, title: 'Intro' } }] },
 *                             { generator: { type: 'Software', name: 'FrameTrail-Conversational-UI' } });
 *     ops.undo(store, applied.changeset);
 *
 * For a conversation in the editor, record() runs operations one at a time
 * inside a transaction the caller opens, and builds the changeset as it goes.
 *
 * Errors are op errors (ops.util.opError): code invalid (errors with JSON
 * Pointers into the input), notFound, notAllowed, conflict or stopped.
 */

(function(ConversationalUI) {

    var ops   = ConversationalUI.ops,
        util  = ops.util,
        items = ops.items;

    var clone    = util.clone,
        isObject = util.isObject,
        has      = util.has,
        opError  = util.opError;

    // The $id of the schema document made from the manifest; its $defs are the manifest's and each operation's input and output.
    var OPERATIONS_ID = 'https://raw.githubusercontent.com/OpenHypervideo/FrameTrail-Conversational-UI/main/shared/operations.json';

    var ITEM_KINDS  = ['overlays', 'annotations', 'chapters', 'codeSnippets'],
        AREAS       = ['top', 'bottom', 'left', 'right'],
        NAMES       = { overlays: 'overlay', annotations: 'annotation', chapters: 'chapter', codeSnippets: 'code snippet' },
        CALLS       = ['add', 'update', 'remove', 'setLayout', 'setSubtitles'];

    // Time map segments without chapters: the shortest of these that makes at most 12 segments.
    var SEGMENT_LENGTHS = [30, 60, 120, 300, 600, 900, 1800, 3600];

    var LIMITS = { list_items: 100, read_transcript: 200, find_in_transcript: 50 };

    var validatorInstance = null,
        byName            = null;


    /* ------------------------------------------------------------------ */
    /*  The manifest                                                      */
    /* ------------------------------------------------------------------ */

    function manifest() {
        if (!ConversationalUI.operations) {
            throw new Error('FrameTrail-Conversational-UI: the operations manifest is missing from the bundle');
        }
        return ConversationalUI.operations;
    }

    function operation(name) {
        if (!byName) {
            byName = {};
            manifest().operations.forEach(function(op) { byName[op.name] = op; });
        }
        return Object.prototype.hasOwnProperty.call(byName, name) ? byName[name] : null;
    }

    function requireOperation(name) {
        var op = (typeof name === 'string') ? operation(name) : null;
        if (!op) {
            throw opError('invalid', 'Unknown operation ' + JSON.stringify(name) + '; one of ' + manifest().operations.map(function(o) { return o.name; }).join(', '));
        }
        return op;
    }

    /**
     * I return a FrameTrailSchema validator that knows FrameTrail's schemas,
     * the manifest's (as the document OPERATIONS_ID: $defs/<name> for the
     * shared ones, $defs/<operation>-input and -output) and the changeset's.
     */
    function validator() {

        if (!validatorInstance) {

            var defs = Object.assign({}, manifest().$defs);

            manifest().operations.forEach(function(op) {
                defs[op.name + '-input']  = op.input;
                defs[op.name + '-output'] = op.output;
            });

            validatorInstance = window.FrameTrailSchema.create(window.FrameTrailSchemas.concat([
                { "$id": OPERATIONS_ID, "$defs": defs },
                ConversationalUI.changesetSchema
            ]));

        }

        return validatorInstance;

    }

    function validateInput(name, input) {
        return validator().validate(OPERATIONS_ID + '#/$defs/' + requireOperation(name).name + '-input', input);
    }

    function validateOutput(name, output) {
        return validator().validate(OPERATIONS_ID + '#/$defs/' + requireOperation(name).name + '-output', output);
    }


    /* ------------------------------------------------------------------ */
    /*  Errors from the store                                             */
    /* ------------------------------------------------------------------ */

    // Where the store's paths into an item point to in an operation's input.
    function itemPath(path, message) {
        var rules = [
            ['/target/selector/frametrail:keyframes', '/keyframes'],
            ['/target/selector/frametrail:rotation', '/rotation'],
            ['/frametrail:tags', '/tags'],
            ['/frametrail:events', '/events'],
            ['/body', '/body']
        ];
        for (var i = 0; i < rules.length; i++) {
            if (path === rules[i][0] || path.indexOf(rules[i][0] + '/') === 0) {
                return rules[i][1] + path.slice(rules[i][0].length);
            }
        }
        if (path === '/target/selector/value' && message === 'must not end before it starts') {
            return { path: '/end', message: 'must not be before start' };
        }
        return null;
    }

    /**
     * I call a store method and turn the edit API's errors into op errors,
     * with paths into the input: path maps a store path (and message) to a
     * path, or to { path, message }, or to null, and then the store's path
     * goes into the message.
     */
    function call(fn, path) {

        try {
            return fn();
        } catch (e) {

            if (!e || e.name !== 'FrameTrailEditError') { throw e; }

            if (e.code !== 'invalid' || !e.errors || !e.errors.length) {
                throw opError(e.code, e.message);
            }

            throw opError('invalid', 'Invalid input', e.errors.map(function(error) {
                var mapped = path ? path(error.path, error.message) : error.path;
                if (mapped === null) {
                    return { path: '', message: (error.path ? error.path + ': ' : '') + error.message };
                }
                return isObject(mapped) ? mapped : { path: mapped, message: error.message };
            }));

        }

    }


    /* ------------------------------------------------------------------ */
    /*  Preconditions                                                     */
    /* ------------------------------------------------------------------ */

    function itemRef(input) {
        return (input.kind === 'annotations' && has(input, 'creator')) ? { creator: input.creator, created: input.ref } : input.ref;
    }

    // The store's permission for a kind of thing, as an op error when it is refused.
    function requirePermission(store, kind) {
        var permission = call(function() { return store.permission(kind); });
        if (!permission.allowed) { throw opError(permission.code || 'notAllowed', permission.message); }
    }

    var PRECONDITIONS = {

        canEditHypervideo: function(store, op) {
            requirePermission(store, op.kind);
        },

        canAnnotate: function(store) {
            requirePermission(store, 'annotations');
        },

        itemExists: function(store, op, input) {
            var kind = has(input, 'kind') ? input.kind : op.kind;
            if (!call(function() { return store.get(kind, itemRef(Object.assign({ kind: kind }, input))); })) {
                throw opError('notFound', 'No ' + NAMES[kind] + ' ' + JSON.stringify(input.ref)
                    + ((kind === 'annotations' && !has(input, 'creator')) ? ' among your own annotations' : ''));
            }
        },

        // What the player never reaches is refused, by lint's rule item-outside-video (an error there): a span that starts at or after the video's end or lies wholly before its start, a chapter outside it. Partly outside is lint's warning and goes through.
        withinVideo: function(store, op, input) {

            var info    = infoOf(store),
                current = has(input, 'ref') ? spanOf(op.kind, store.get(op.kind, itemRef(Object.assign({ kind: op.kind }, input)))) : {},
                start   = has(input, 'start') ? input.start : current.start,
                end     = has(input, 'end') ? input.end : current.end;

            if (!has(input, 'start') && !has(input, 'end')) { return; }

            if (typeof info.end === 'number' && start >= info.end) {
                throw opError('invalid', 'Invalid input', [{ path: has(input, 'start') ? '/start' : '/end', message: 'must be before the end of the video (' + info.end + ')' }]);
            }
            if (op.kind === 'chapters' ? start < info.start : (end <= info.start && start < info.start)) {
                throw opError('invalid', 'Invalid input', [{ path: (op.kind === 'chapters' || !has(input, 'end')) ? '/start' : '/end', message: 'must be after the start of the video (' + info.start + ')' }]);
            }

        },

        chapterStartFree: function(store, op, input) {
            if (!has(input, 'start') || (has(input, 'ref') && input.ref === input.start)) { return; }
            if (store.get('chapters', input.start)) {
                throw opError('invalid', 'Invalid input', [{ path: '/start', message: 'is taken by another chapter' }]);
            }
        },

        subtitles: function(store, op, input) {
            var languages = subtitleLanguages(store);
            if (!languages.length) {
                throw opError('notFound', 'This hypervideo has no subtitles');
            }
            if (has(input, 'lang') && languages.indexOf(input.lang) < 0) {
                throw opError('notFound', 'No subtitles in ' + JSON.stringify(input.lang) + '; there are: ' + languages.join(', '));
            }
        }

    };


    /* ------------------------------------------------------------------ */
    /*  Reading                                                           */
    /* ------------------------------------------------------------------ */

    // The open hypervideo's id, video and time (the store's getInfo()).
    function infoOf(store) {
        return call(function() { return store.getInfo(); });
    }

    function userIdOf(store) {
        var user = store.getUser();
        return user ? String(user.id) : '';
    }

    function subtitleLanguages(store) {
        return store.list('subtitles').filter(isObject).map(function(file) { return String(file.srclang); });
    }

    // The time span of an item as stored (a chapter: its start).
    function spanOf(kind, item) {
        if (kind === 'chapters') { return { start: item.start, end: item.start }; }
        var selector = (isObject(item.target) && isObject(item.target.selector)) ? item.target.selector : {};
        return util.timeSpan(selector.value);
    }

    // The chapters sorted by start, each with its end: where the next one starts, or where the video ends.
    function chapterEnds(store) {
        var chapters = store.list('chapters'),
            end      = infoOf(store).end,
            ends     = {};
        chapters.forEach(function(chapter, i) {
            ends[chapter.start] = (i + 1 < chapters.length) ? chapters[i + 1].start : end;
        });
        return ends;
    }

    function summarize(store, kind, item, ends) {
        return items.summary(kind, item, {
            userId:     userIdOf(store),
            chapterEnd: (kind === 'chapters') ? (ends || chapterEnds(store))[item.start] : undefined
        });
    }

    function contentViewSummary(view) {
        var summary = { type: String(isObject(view) ? view.type : '') };
        if (isObject(view) && typeof view.name === 'string' && view.name !== '') { summary.name = view.name; }
        return summary;
    }

    /**
     * I cut the hypervideo into segments — its chapters (and the time before
     * the first one), or equal segments when it has none — and count the
     * overlays and annotations that start in each.
     */
    function timeMap(range, chapters, overlays, annotations) {

        var segments = [];

        if (chapters.length) {

            if (chapters[0].start > range.start) {
                segments.push({ start: range.start, end: chapters[0].start });
            }
            chapters.forEach(function(chapter, i) {
                segments.push({
                    start:   chapter.start,
                    end:     (i + 1 < chapters.length) ? chapters[i + 1].start : range.end,
                    chapter: (typeof chapter.title === 'string') ? chapter.title : ''
                });
            });

        } else {

            var end = range.end;

            if (end === null) {
                overlays.concat(annotations).forEach(function(item) {
                    var span = spanOf('overlays', item);
                    end = (end === null) ? span.end : Math.max(end, span.end);
                });
            }

            if (end === null || end <= range.start) { return []; }

            var length = end - range.start,
                step   = SEGMENT_LENGTHS.filter(function(s) { return length / s <= 12; })[0] || Math.ceil(length / 12 / 3600) * 3600;

            for (var k = 0; range.start + k * step < end; k++) {
                segments.push({ start: util.seconds(range.start + k * step), end: util.seconds(Math.min(range.start + (k + 1) * step, end)) });
            }

        }

        segments.forEach(function(segment) { segment.overlays = 0; segment.annotations = 0; });

        function count(list, key) {
            list.forEach(function(item) {
                var start = spanOf(key, item).start,
                    at    = 0;
                segments.forEach(function(segment, i) { if (segment.start <= start) { at = i; } });
                segments[at][key] += 1;
            });
        }

        if (segments.length) {
            count(overlays, 'overlays');
            count(annotations, 'annotations');
        }

        return segments;

    }

    function inspectHypervideo(store) {

        var hypervideo  = store.getHypervideo(),
            meta        = isObject(hypervideo.meta) ? hypervideo.meta : {},
            info        = infoOf(store),
            range       = { start: info.start, end: info.end, duration: info.duration },
            overlays    = store.list('overlays'),
            annotations = store.list('annotations'),
            chapters    = store.list('chapters'),
            user        = store.getUser(),
            userId      = user ? String(user.id) : '',
            overlayTypes = {},
            layout      = {};

        overlays.forEach(function(overlay) {
            var type = String((isObject(overlay.body) && overlay.body['frametrail:type']) || '');
            overlayTypes[type] = (overlayTypes[type] || 0) + 1;
        });

        AREAS.forEach(function(area) {
            layout[area] = store.list('contentViews', { area: area }).map(contentViewSummary);
        });

        var result = {
            id:          String(info.id),
            name:        (typeof meta.name === 'string') ? meta.name : '',
            video:       info.video,
            duration:    range.duration,
            timeRange:   { start: range.start, end: range.end },
            subtitles:   subtitleLanguages(store),
            layout:      layout,
            counts: {
                overlays:       overlays.length,
                annotations:    annotations.length,
                ownAnnotations: annotations.filter(function(a) { return isObject(a.creator) && String(a.creator.id) === userId; }).length,
                chapters:       chapters.length,
                codeSnippets:   store.list('codeSnippets').length
            },
            overlayTypes: overlayTypes,
            timeMap:     timeMap(range, chapters, overlays, annotations),
            user:        user,
            canEdit:     store.permission('overlays').allowed === true,
            canAnnotate: store.permission('annotations').allowed === true
        };

        if (typeof meta.description === 'string') { result.description = meta.description; }
        if (meta.creator !== undefined || meta.creatorId !== undefined) {
            result.creator = {};
            if (meta.creator !== undefined)   { result.creator.nickname = String(meta.creator); }
            if (meta.creatorId !== undefined) { result.creator.id = String(meta.creatorId); }
        }

        return result;

    }

    // The installation's hypervideos in short form; the open one's duration as the store knows it, the others' from their clip.
    function listHypervideos(store) {

        var entries = store.listHypervideos(),
            open    = entries.some(function(entry) { return entry.open; }) ? infoOf(store) : null;

        return entries.map(function(entry) {

            var meta   = isObject(entry.meta) ? entry.meta : {},
                result = {
                    id:        String(entry.id),
                    name:      (typeof meta.name === 'string') ? meta.name : '',
                    duration:  (entry.open && open) ? open.duration : util.clipSpan(entry.clips).duration,
                    subtitles: (Array.isArray(entry.subtitles) ? entry.subtitles : []).filter(isObject).map(function(file) { return String(file.srclang); }),
                    open:      entry.open === true
                };

            if (typeof meta.description === 'string') { result.description = meta.description; }
            if (meta.creator !== undefined || meta.creatorId !== undefined) {
                result.creator = {};
                if (meta.creator !== undefined)   { result.creator.nickname = String(meta.creator); }
                if (meta.creatorId !== undefined) { result.creator.id = String(meta.creatorId); }
            }

            return result;

        });

    }

    function listItems(store, input) {

        var kinds  = has(input, 'kind') ? [input.kind] : ITEM_KINDS,
            filter = {},
            ends   = chapterEnds(store),
            found  = [];

        ['from', 'to', 'type', 'creator'].forEach(function(key) {
            if (has(input, key)) { filter[key] = input[key]; }
        });

        kinds.forEach(function(kind, k) {
            store.list(kind, filter).forEach(function(item, i) {
                found.push({ summary: summarize(store, kind, item, ends), order: [k, i] });
            });
        });

        // By start; on a tie by kind (overlays, annotations, chapters, code snippets), then as stored.
        found.sort(function(a, b) {
            return (a.summary.start - b.summary.start) || (a.order[0] - b.order[0]) || (a.order[1] - b.order[1]);
        });

        var limit = has(input, 'limit') ? input.limit : LIMITS.list_items;

        return {
            items: found.slice(0, limit).map(function(entry) { return entry.summary; }),
            total: found.length
        };

    }

    function transcript(store, input) {

        var languages = subtitleLanguages(store),
            lang      = has(input, 'lang') ? input.lang : languages[0],
            entry     = store.get('subtitles', lang);

        if (!entry || typeof entry.vtt !== 'string') {
            throw opError('notFound', 'The subtitles in ' + JSON.stringify(lang) + ' could not be read');
        }

        return { lang: lang, languages: languages, cues: util.cues(entry.vtt) };

    }

    function readTranscript(store, input) {

        var text  = transcript(store, input),
            from  = has(input, 'from') ? input.from : -Infinity,
            to    = has(input, 'to') ? input.to : Infinity,
            cues  = text.cues.filter(function(cue) { return cue.end > from && cue.start < to; }),
            limit = has(input, 'limit') ? input.limit : LIMITS.read_transcript,
            result = { lang: text.lang, languages: text.languages, cues: cues.slice(0, limit), total: cues.length };

        if (cues.length > limit) { result.next = cues[limit].start; }

        return result;

    }

    function findInTranscript(store, input) {

        var text    = transcript(store, input),
            words   = input.query.toLowerCase().split(/\s+/).filter(function(word) { return word !== ''; }),
            matches = text.cues.filter(function(cue) {
                var lower = cue.text.toLowerCase();
                return words.every(function(word) { return lower.indexOf(word) >= 0; });
            }),
            limit   = has(input, 'limit') ? input.limit : LIMITS.find_in_transcript;

        return { lang: text.lang, matches: matches.slice(0, limit), total: matches.length };

    }


    /* ------------------------------------------------------------------ */
    /*  Types                                                             */
    /* ------------------------------------------------------------------ */

    // Types and attributes FrameTrail's schemas keep for old data only: never offered.
    var LEGACY_TYPES      = ['button'],
        LEGACY_ATTRIBUTES = ['animationIn', 'animationOut', 'animationDuration'];

    var SCHEMAS = 'https://frametrail.org/schemas/1/';

    function frameTrailSchemas() {
        var documents = {};
        window.FrameTrailSchemas.forEach(function(schema) { documents[schema.$id.split('#')[0]] = schema; });
        return documents;
    }

    // The frametrail:type values a body schema's alternatives allow.
    function bodyTypes(body) {
        return (Array.isArray(body.oneOf) ? body.oneOf : []).map(function(alternative) {
            var type = isObject(alternative.properties) ? alternative.properties['frametrail:type'] : null;
            return isObject(type) ? type.const : undefined;
        }).filter(function(type) {
            return typeof type === 'string' && LEGACY_TYPES.indexOf(type) < 0;
        });
    }

    /**
     * Where FrameTrail's serializer writes an item's src for a type: source,
     * value, or nowhere. Asked of the serializer itself (an overlay read with
     * its src in source, written again from its model), so it follows
     * FrameTrail's table rather than a copy of it.
     */
    function srcPlace(type) {

        var Serializer = window.FrameTrailSerializer,
            probe      = 'src',
            model      = Serializer.parseOverlay({
                type:    'Annotation',
                created: '1970-01-01T00:00:00.000Z',
                body:    { 'frametrail:type': type, source: probe },
                target:  { selector: { value: 't=0,1&xywh=percent:0,0,1,1' } }
            });

        delete model._stored;

        var body = Serializer.serializeOverlay(model, {}).body;

        return (body.source === probe) ? 'source' : (body.value === probe) ? 'value' : null;

    }

    function describeType(input) {

        var documents   = frameTrailSchemas(),
            overlays    = bodyTypes(documents[SCHEMAS + 'content-item.schema.json'].$defs.overlay.properties.body),
            annotations = bodyTypes(documents[SCHEMAS + 'annotation-file.schema.json'].$defs.annotation.properties.body),
            known       = overlays.concat(annotations.filter(function(type) { return overlays.indexOf(type) < 0; })),
            id          = SCHEMAS + 'attributes/' + input.type + '.schema.json';

        if (known.indexOf(input.type) < 0 || !documents[id]) {
            throw opError('invalid', 'Invalid input', [{ path: '/type', message: 'is not a type; one of ' + known.join(', ') }]);
        }

        var attributes = util.inlineSchema(documents[id], id, documents);

        if (isObject(attributes.properties)) {
            LEGACY_ATTRIBUTES.forEach(function(key) { delete attributes.properties[key]; });
        }

        return {
            type:       input.type,
            overlay:    overlays.indexOf(input.type) >= 0,
            annotation: annotations.indexOf(input.type) >= 0,
            src:        srcPlace(input.type),
            attributes: attributes
        };

    }


    /* ------------------------------------------------------------------ */
    /*  Writing                                                           */
    /* ------------------------------------------------------------------ */

    function addItem(store, kind, item) {
        var stored = call(function() { return store.add(kind, item); }, itemPath);
        return { result: summarize(store, kind, stored), inverse: { method: 'remove', args: [kind, stored.created] } };
    }

    function updateItem(store, kind, input, patch) {

        var current = store.get(kind, input.ref);

        // A change to what it is already changes nothing, and records no generator either.
        if (patch === undefined || util.sameJSON(util.mergePatch(current, Object.assign({}, patch, { generator: undefined })), current)) {
            return { result: summarize(store, kind, current), inverse: null };
        }

        var stored = call(function() { return store.update(kind, input.ref, patch); }, itemPath),
            undo   = util.diffPatch(stored, current);

        return {
            result:  summarize(store, kind, stored),
            inverse: (undo === undefined) ? null : { method: 'update', args: [kind, stored.created, undo] }
        };

    }

    function removeItem(store, kind, input) {
        var removed = call(function() { return store.remove(kind, input.ref); }, itemPath);
        return { result: summarize(store, kind, removed), inverse: { method: 'add', args: [kind, removed] } };
    }

    var chapterPath = function(path) { return path; };

    // The implementations, by operation: (store, input, context) → { result, inverse } (writes) or { result } (reads).
    var IMPLEMENTATIONS = {

        list_hypervideos: function(store) {
            return { result: { hypervideos: listHypervideos(store) } };
        },

        inspect_hypervideo: function(store) {
            return { result: inspectHypervideo(store) };
        },

        list_items: function(store, input) {
            return { result: listItems(store, input) };
        },

        get_item: function(store, input) {
            return { result: { item: store.get(input.kind, itemRef(input)) } };
        },

        read_transcript: function(store, input) {
            return { result: readTranscript(store, input) };
        },

        find_in_transcript: function(store, input) {
            return { result: findInTranscript(store, input) };
        },

        describe_type: function(store, input) {
            return { result: describeType(input) };
        },

        add_overlay: function(store, input, context) {
            return addItem(store, 'overlays', items.newOverlay(input, context.generator));
        },

        update_overlay: function(store, input, context) {
            return updateItem(store, 'overlays', input, items.overlayPatch(store.get('overlays', input.ref), input, context.generator));
        },

        remove_overlay: function(store, input) {
            return removeItem(store, 'overlays', input);
        },

        add_annotation: function(store, input, context) {
            return addItem(store, 'annotations', items.newAnnotation(input, context.generator));
        },

        update_annotation: function(store, input, context) {
            return updateItem(store, 'annotations', input, items.annotationPatch(store.get('annotations', input.ref), input, context.generator));
        },

        remove_annotation: function(store, input) {
            return removeItem(store, 'annotations', input);
        },

        add_chapter: function(store, input) {
            var added = call(function() { return store.add('chapters', { start: input.start, title: input.title }); }, chapterPath);
            return { result: summarize(store, 'chapters', added), inverse: { method: 'remove', args: ['chapters', added.start] } };
        },

        update_chapter: function(store, input) {

            var before = store.get('chapters', input.ref),
                patch  = {};

            if (has(input, 'start')) { patch.start = input.start; }
            if (has(input, 'title')) { patch.title = input.title; }

            var after = Object.keys(patch).length
                    ? call(function() { return store.update('chapters', input.ref, patch); }, chapterPath)
                    : before,
                undo  = util.diffPatch(after, before);

            return {
                result:  summarize(store, 'chapters', after),
                inverse: (undo === undefined) ? null : { method: 'update', args: ['chapters', after.start, undo] }
            };

        },

        remove_chapter: function(store, input) {
            var ends    = chapterEnds(store),
                removed = call(function() { return store.remove('chapters', input.ref); }, chapterPath);
            return { result: summarize(store, 'chapters', removed, ends), inverse: { method: 'add', args: ['chapters', removed] } };
        },

        set_subtitles: function(store, input) {

            var previous = store.get('subtitles', input.lang),
                before   = (previous && typeof previous.vtt === 'string') ? previous.vtt : null,
                entry    = call(function() { return store.setSubtitles(input.lang, input.vtt); }, function() { return '/vtt'; });

            return {
                result: {
                    lang: input.lang,
                    src:  entry ? entry.src : null,
                    cues: (typeof input.vtt === 'string') ? util.cues(input.vtt).length : 0
                },
                inverse: (before === input.vtt) ? null : { method: 'setSubtitles', args: [input.lang, before] }
            };

        },

        set_layout_area: function(store, input) {

            var previous = store.list('contentViews', { area: input.area }),
                views    = call(function() { return store.setLayout(input.area, input.contentViews); }, function(path) { return '/contentViews' + path; });

            return {
                result:  { area: input.area, contentViews: views },
                inverse: util.sameJSON(previous, views) ? null : { method: 'setLayout', args: [input.area, previous] }
            };

        }

    };


    /* ------------------------------------------------------------------ */
    /*  Running                                                           */
    /* ------------------------------------------------------------------ */

    function execute(store, op, input, context) {

        var given  = (input === undefined) ? {} : input,
            errors = validateInput(op.name, given);

        if (errors.length) {
            throw opError('invalid', 'Invalid input', errors);
        }

        if (has(given, 'start') && has(given, 'end') && given.end < given.start) {
            throw opError('invalid', 'Invalid input', [{ path: '/end', message: 'must not be before start' }]);
        }

        op.preconditions.forEach(function(name) {
            PRECONDITIONS[name](store, op, given);
        });

        return IMPLEMENTATIONS[op.name](store, clone(given), context || {});

    }

    /**
     * I run one operation and return its result. A write runs as a
     * transaction of its own (in the editor: one undo step).
     *
     * @param {Object} store
     * @param {String} name
     * @param {Object} input
     * @param {Object} [context] { generator }
     */
    function run(store, name, input, context) {

        var op = requireOperation(name);

        if (op.effect === 'read') {
            return execute(store, op, input, context).result;
        }

        return store.transaction(op.name, function(tx) {
            return execute(tx, op, input, context).result;
        });

    }

    // The file a write operation changes: the user's annotation file, or hypervideo.json.
    function partOf(op) {
        return (op.kind === 'annotations') ? 'annotations' : 'hypervideo';
    }

    function changesetId() {
        return 'cs-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
    }

    /**
     * I record a changeset: run(name, input) runs an operation against the
     * store (reads too), and changeset() returns the write operations so far
     * with their inverses. The store is usually a transaction's: the caller
     * makes it one undo step. An operation that fails changes nothing and
     * leaves the others in place.
     *
     * @param {Object} store
     * @param {Object} [options] { id, summary, generator }
     */
    function record(store, options) {

        options = options || {};

        var id       = options.id || changesetId(),
            info     = infoOf(store),
            before   = store.versions(),
            touched  = {},
            applied  = [];

        return {

            run: function(name, input) {

                var op  = requireOperation(name),
                    out = execute(store, op, input, { generator: options.generator });

                if (op.effect === 'write') {
                    applied.push({ op: op.name, input: clone(input === undefined ? {} : input), inverse: (out.inverse === undefined) ? null : clone(out.inverse) });
                    touched[partOf(op)] = true;
                }

                return out.result;

            },

            changeset: function() {

                var changeset = {
                        id:           id,
                        hypervideoId: String(info.id),
                        baseVersion:  {},
                        createdBy:    userIdOf(store),
                        summary:      options.summary || applied.map(function(entry) { return entry.op; }).join(', '),
                        ops:          clone(applied)
                    };

                Object.keys(touched).forEach(function(part) {
                    if (before[part] !== undefined) { changeset.baseVersion[part] = before[part]; }
                });

                if (options.generator !== undefined) { changeset.generator = clone(options.generator); }

                return changeset;

            }

        };

    }

    function prefixed(e, index, name) {

        if (!e || e.name !== 'ConversationalUiOpError') { throw e; }

        var prefix = '/ops/' + index + '/input',
            errors = e.errors.map(function(error) { return { path: prefix + error.path, message: error.message }; }),
            error  = opError(e.code, 'Operation ' + index + ' (' + name + '): ' + e.message.split(': ')[0], errors);

        if (!errors.length) { error.message = 'Operation ' + index + ' (' + name + '): ' + e.message; }

        return error;

    }

    /**
     * I apply a changeset to a store as one transaction: all its operations,
     * or, when one fails, none. I return { changeset, results }: the
     * changeset as applied (with id, hypervideoId, baseVersion, createdBy,
     * summary and every operation's inverse) and each operation's result.
     *
     * @param {Object} store
     * @param {Object} changeset
     * @param {Object} [context] { generator }, used when the changeset names none
     */
    function apply(store, changeset, context) {

        var errors = validator().validate(ConversationalUI.changesetSchema.$id, changeset);

        if (errors.length) {
            throw opError('invalid', 'Invalid changeset', errors);
        }

        changeset.ops.forEach(function(entry, i) {
            var op = operation(entry.op);
            if (!op) {
                errors.push({ path: '/ops/' + i + '/op', message: 'is not an operation' });
            } else if (op.effect !== 'write') {
                errors.push({ path: '/ops/' + i + '/op', message: 'only changes go into a changeset; ' + entry.op + ' reads' });
            }
        });

        var openId = String(infoOf(store).id);

        if (has(changeset, 'hypervideoId') && String(changeset.hypervideoId) !== openId) {
            errors.push({ path: '/hypervideoId', message: 'must be ' + JSON.stringify(openId) + ', the hypervideo it is applied to' });
        }

        if (errors.length) {
            throw opError('invalid', 'Invalid changeset', errors);
        }

        if (isObject(changeset.baseVersion)) {

            var now       = store.versions(),
                conflicts = [];

            Object.keys(changeset.baseVersion).forEach(function(part) {
                if (now[part] !== undefined && changeset.baseVersion[part] !== now[part]) {
                    conflicts.push({ path: '/baseVersion/' + part, message: 'is ' + changeset.baseVersion[part] + ', but the ' + ((part === 'annotations') ? 'annotation file' : 'hypervideo') + ' is at ' + now[part] });
                }
            });

            if (conflicts.length) {
                throw opError('conflict', 'Changed since the changeset was made', conflicts);
            }

        }

        var generator = has(changeset, 'generator') ? changeset.generator : (context || {}).generator,
            summary   = changeset.summary || changeset.ops.map(function(entry) { return entry.op; }).join(', ');

        return store.transaction(summary, function(tx) {

            var recorder = record(tx, { id: changeset.id, summary: summary, generator: generator }),
                results  = changeset.ops.map(function(entry, i) {
                    try {
                        return recorder.run(entry.op, entry.input);
                    } catch (e) {
                        throw prefixed(e, i, entry.op);
                    }
                });

            return { changeset: recorder.changeset(), results: results };

        });

    }

    /**
     * I take an applied changeset back: its inverses, last first, as one
     * transaction. In the editor the undo step of the changeset does the
     * same; this is for stores without one.
     *
     * @param {Object} store
     * @param {Object} changeset as apply() returned it
     */
    function undo(store, changeset) {

        var inverses = (isObject(changeset) && Array.isArray(changeset.ops) ? changeset.ops : []).map(function(entry, i) {
            var inverse = isObject(entry) ? entry.inverse : null;
            if (inverse === null || inverse === undefined) { return null; }
            if (!isObject(inverse) || CALLS.indexOf(inverse.method) < 0 || !Array.isArray(inverse.args)) {
                throw opError('invalid', 'Invalid changeset', [{ path: '/ops/' + i + '/inverse', message: 'is not a call of ' + CALLS.join(', ') }]);
            }
            return inverse;
        });

        var openId = String(infoOf(store).id);

        if (has(changeset, 'hypervideoId') && String(changeset.hypervideoId) !== openId) {
            throw opError('invalid', 'Invalid changeset', [{ path: '/hypervideoId', message: 'must be ' + JSON.stringify(openId) + ', the hypervideo it is undone in' }]);
        }

        return store.transaction('Undo: ' + (changeset.summary || ''), function(tx) {
            for (var i = inverses.length - 1; i >= 0; i--) {
                if (!inverses[i]) { continue; }
                var inverse = clone(inverses[i]);
                call(function() { return tx[inverse.method].apply(tx, inverse.args); });
            }
        });

    }


    Object.assign(ops, {
        OPERATIONS_ID:  OPERATIONS_ID,
        manifest:       manifest,
        operation:      operation,
        validator:      validator,
        validateInput:  validateInput,
        validateOutput: validateOutput,
        run:            run,
        record:         record,
        apply:          apply,
        undo:           undo
    });

})(window.FrameTrailConversationalUI);
