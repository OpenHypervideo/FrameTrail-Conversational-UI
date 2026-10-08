/*
 * FrameTrail-Conversational-UI — the model store (ops.modelStore): what
 * FrameTrail's edit API does to the open hypervideo, done headless to a
 * bundle. It reads and writes the stored format through FrameTrail's
 * serializer and validates with FrameTrail's schemas, with the edit API's
 * rules: the same envelope for new items, the same identities (created,
 * a chapter's start), merge patches, checks, permissions and errors.
 *
 * It runs the conformance fixtures (shared/fixtures/ops/) and is what the PHP
 * interpreter's store (A2, A3) does on the server, which writes the files
 * without an editor.
 *
 *     var store = ops.modelStore(bundle, { user: { id: '1', name: 'Ada', role: 'admin' } });
 *     store.add('chapters', { start: 60, title: 'Part two' });
 *     store.data();   // the bundle as a save would write it
 *
 * The bundle is a hypervideo bundle or a project bundle (FrameTrail's
 * schemas/), whose hypervideo options.hypervideoId picks (default: the
 * first). Options: user ({ id, name, role }; changes are made as them), now (the clock for new
 * items' created: milliseconds or a function, default Date.now), duration
 * (the video's length in seconds as the media tells it, for a hypervideo
 * whose clip does not say).
 *
 * Its reads around the data are the edit API's too: getInfo(), getUser(),
 * permission(kind), listHypervideos(). Beyond the edit API: versions() (the
 * compare-and-swap tokens of the files) and resources() (the resources the
 * bundle carries, for the lint rules).
 *
 * Needs window.FrameTrailSerializer, FrameTrailSchema and FrameTrailSchemas,
 * which FrameTrail loads before any extension.
 */

(function(ConversationalUI) {

    var ops  = ConversationalUI.ops,
        util = ops.util;

    var clone    = util.clone,
        isObject = util.isObject,
        sameJSON = util.sameJSON;

    var MEDIA_FRAGMENTS = 'http://www.w3.org/TR/media-frags/';

    // Where a new annotation comes from, as the editor records it.
    var ANNOTATION_SOURCE = { frametrail: true, url: '_data/hypervideos/' };

    // The layout areas by the edit API's names and their keys in config.layoutArea.
    var AREAS     = { 'top': 'top', 'bottom': 'bottom', 'left': 'left', 'right': 'right', 'areaTop': 'top', 'areaBottom': 'bottom', 'areaLeft': 'left', 'areaRight': 'right' },
        AREA_KEYS = { 'top': 'areaTop', 'bottom': 'areaBottom', 'left': 'areaLeft', 'right': 'areaRight' };

    // Body types the schemas still accept (legacy) that FrameTrail has no renderer for: the edit API refuses to add them.
    var UNSHOWABLE_TYPES = ['button'];

    var CHAPTER_SCHEMA = 'hypervideo.schema.json#/properties/chapters/items';

    var validator = null;

    function validate(schema, data) {
        if (!validator) {
            validator = window.FrameTrailSchema.create(window.FrameTrailSchemas);
        }
        return validator.validate(schema, data);
    }

    // The errors of FrameTrail's edit API: an Error with code 'invalid', 'notFound' or 'notAllowed', and for invalid data its { path, message } errors.
    function editError(code, message, errors) {

        var details = (errors && errors.length)
                ? ': ' + errors.map(function(e) { return (e.path || '(data)') + ': ' + e.message; }).join('; ')
                : '';

        var error = new Error(message + details);

        error.name   = 'FrameTrailEditError';
        error.code   = code;
        error.errors = errors || [];

        return error;

    }

    // created as ISO text or milliseconds → milliseconds (NaN for anything else).
    function toMillis(created) {
        if (typeof created === 'number') { return created; }
        if (typeof created === 'string' && created !== '') { return (new Date(created)).getTime(); }
        return NaN;
    }

    function modelStore(data, options) {

        options = options || {};

        var Serializer = window.FrameTrailSerializer;

        var source  = clone(data),
            project = (isObject(source) && source.bundle === 'project') ? source : null,
            id      = project
                ? String(options.hypervideoId !== undefined ? options.hypervideoId : Object.keys(project.hypervideos || {})[0])
                : String((isObject(source) && source.id !== undefined) ? source.id : (options.hypervideoId !== undefined ? options.hypervideoId : '')),
            bundle  = project ? (project.hypervideos || {})[id] : source;

        if (!isObject(bundle) || !isObject(bundle.hypervideo)) {
            throw new Error('No hypervideo ' + JSON.stringify(id) + ' in this bundle');
        }

        var given = isObject(options.user) ? options.user : {},
            user  = {
                id:   String(given.id !== undefined ? given.id : ''),
                name: String(given.name !== undefined ? given.name : ''),
                role: (given.role === 'admin') ? 'admin' : 'user'
            };

        var clock = (typeof options.now === 'function') ? options.now
                  : (typeof options.now === 'number') ? function() { return options.now; }
                  : function() { return Date.now(); };

        var files = (isObject(bundle.annotations) && isObject(bundle.annotations.files)) ? bundle.annotations.files : {},
            index = Serializer.parseAnnotationIndex(isObject(bundle.annotations) ? bundle.annotations.index : undefined);

        // The working state. Writes replace the objects in it rather than change them, so a transaction can keep shallow copies.
        var model       = Serializer.parseHypervideo(bundle.hypervideo),
            annotations = [],
            subtitles   = isObject(bundle.subtitles) ? clone(bundle.subtitles) : {},
            dirty       = { hypervideo: false, annotations: false },
            transacting = false;

        Object.keys(files).forEach(function(fileId) {
            Array.prototype.push.apply(annotations, Serializer.parseAnnotationFile(files[fileId], clone(ANNOTATION_SOURCE)));
        });
        Serializer.dedupeCreated(annotations, function(annotation) { return annotation.creatorId; });

        if (!Array.isArray(model.chapters)) { model.chapters = []; }

        // The three kinds of W3C items, as the edit API has them.
        var ITEM_KINDS = {
            overlays: {
                name:       'overlay',
                itemType:   'Overlay',
                schema:     'content-item.schema.json#/$defs/overlay',
                hypervideo: true,
                timeSpan:   true,
                list:       function() { return model.overlays; },
                set:        function(list) { model.overlays = list; },
                parse:      function(item) { return Serializer.parseOverlay(item); },
                serialize:  function(item) { return Serializer.serializeOverlay(item, itemContext()); }
            },
            codeSnippets: {
                name:       'code snippet',
                itemType:   'CodeSnippet',
                schema:     'content-item.schema.json#/$defs/codeSnippet',
                hypervideo: true,
                timeSpan:   false,
                list:       function() { return model.codeSnippets; },
                set:        function(list) { model.codeSnippets = list; },
                parse:      function(item) { return Serializer.parseCodeSnippet(item); },
                serialize:  function(item) { return Serializer.serializeCodeSnippet(item, itemContext()); }
            },
            annotations: {
                name:       'annotation',
                itemType:   'Annotation',
                schema:     'annotation-file.schema.json#/$defs/annotation',
                hypervideo: false,
                timeSpan:   true,
                list:       function() { return annotations; },
                set:        function(list) { annotations = list; },
                parse:      function(item) { return Serializer.parseAnnotation(item, clone(ANNOTATION_SOURCE)); },
                serialize:  function(item) { return Serializer.serializeAnnotation(item, itemContext()); }
            }
        };

        function markDirty(kind) {
            if (kind === 'annotations') {
                dirty.annotations = true;
            } else {
                dirty.hypervideo = true;
            }
        }


        /* -------------------------------------------------------------- */
        /*  The hypervideo                                                */
        /* -------------------------------------------------------------- */

        function resources() {
            if (project) {
                return (isObject(project.resources) && isObject(project.resources.resources)) ? project.resources.resources : {};
            }
            return isObject(bundle.resources) ? bundle.resources : {};
        }

        // The video every item targets, by FrameTrail's rule (Database.sourcePathOf).
        function sourcePath() {

            var clip = (Array.isArray(model.clips) && isObject(model.clips[0])) ? model.clips[0] : {};

            if (clip.src && clip.src.length > 3) { return clip.src; }
            if (!clip.resourceId) { return ''; }

            var resource = resources()[clip.resourceId];
            return resource ? resource.src : undefined;

        }

        function itemContext() {
            return { sourcePath: sourcePath() };
        }

        // The resources the bundle carries, by id; null when it carries none (then nothing can be said about a reference).
        function knownResources() {
            if (project) {
                return (isObject(project.resources) && isObject(project.resources.resources)) ? clone(project.resources.resources) : null;
            }
            return isObject(bundle.resources) ? clone(bundle.resources) : null;
        }

        function creatorId() {
            return (model.meta && model.meta.creatorId !== undefined) ? String(model.meta.creatorId) : '';
        }

        // The kinds of things, and whether changing them needs the hypervideo's creator or an admin (as the edit API has them).
        var KINDS = { overlays: true, codeSnippets: true, annotations: false, chapters: true, contentViews: true, subtitles: true, config: true };

        // Why the user may not change a kind of thing, or null when they may.
        function refusalOf(kind) {
            if (user.id === '') {
                return editError('notAllowed', 'Changes can only be made by a signed-in user');
            }
            if (KINDS[kind] && user.role !== 'admin' && creatorId() !== user.id) {
                return editError('notAllowed', 'Only an admin or the creator of this hypervideo can change its ' + kind);
            }
            return null;
        }

        /**
         * I tell whether the user may change a kind of thing: { allowed: true },
         * or { allowed: false, code, message } with what a write would throw.
         */
        function permission(kind) {

            if (!Object.prototype.hasOwnProperty.call(KINDS, kind)) {
                throw editError('invalid', 'Unknown kind "' + kind + '"; one of ' + Object.keys(KINDS).join(', '));
            }

            var refusal = refusalOf(kind);

            return refusal ? { allowed: false, code: refusal.code, message: refusal.message } : { allowed: true };

        }

        // Who changes are made as, or null when nobody is.
        function getUser() {
            return (user.id === '') ? null : { id: user.id, name: user.name, role: user.role, guest: false };
        }

        // The hypervideo's id, video and time: item times run from start (the clip's in point) to end.
        function getInfo() {
            var span = util.clipSpan(model.clips, options.duration),
                path = sourcePath();
            return {
                id:       id,
                video:    (typeof path === 'string' && path !== '') ? path : null,
                start:    span.start,
                end:      (span.duration !== null) ? util.seconds(span.start + span.duration) : null,
                duration: span.duration
            };
        }

        function versions() {

            var result = {},
                entry  = index.annotationfiles[user.id];

            if (model.meta && typeof model.meta.lastchanged === 'number') {
                result.hypervideo = model.meta.lastchanged;
            }
            result.annotations = (isObject(entry) && typeof entry.lastchanged === 'number') ? entry.lastchanged : 0;

            return result;

        }

        function getHypervideo() {
            return Serializer.serializeHypervideo(model, itemContext());
        }

        // The hypervideos of the project (or this one) as their hypervideo.json describes them, as the edit API lists them.
        function listHypervideos() {

            var ids = project
                ? Object.keys((isObject(project.hypervideosIndex) && isObject(project.hypervideosIndex.hypervideos))
                    ? project.hypervideosIndex.hypervideos
                    : (project.hypervideos || {}))
                : [id];

            return ids.filter(function(hypervideoId) {
                return hypervideoId === id || (project && isObject(project.hypervideos) && isObject(project.hypervideos[hypervideoId]));
            }).map(function(hypervideoId) {

                var open = (hypervideoId === id),
                    json = open ? model : (project.hypervideos[hypervideoId].hypervideo || {});

                return {
                    id:        hypervideoId,
                    open:      open,
                    meta:      clone(isObject(json.meta) ? json.meta : {}),
                    clips:     clone(Array.isArray(json.clips) ? json.clips : []),
                    subtitles: clone(Array.isArray(json.subtitles) ? json.subtitles : [])
                };

            });

        }


        /* -------------------------------------------------------------- */
        /*  Checks                                                        */
        /* -------------------------------------------------------------- */

        function requireEditing(kind) {

            var refusal = refusalOf(kind);

            if (refusal) { throw refusal; }

        }

        function itemKind(kind) {
            if (!ITEM_KINDS[kind]) {
                throw editError('invalid', 'Unknown kind of item "' + kind + '"; one of ' + Object.keys(ITEM_KINDS).join(', '));
            }
            return ITEM_KINDS[kind];
        }

        // Checks the schemas cannot express.
        function itemErrors(kind, item) {

            var spec   = ITEM_KINDS[kind],
                errors = [],
                type   = isObject(item.body) ? item.body['frametrail:type'] : undefined;

            if (kind !== 'codeSnippets' && UNSHOWABLE_TYPES.indexOf(type) >= 0) {
                errors.push({ path: '/body/frametrail:type', message: 'is not a type this player can show' });
            }

            if (spec.timeSpan && isObject(item.target) && isObject(item.target.selector)) {
                var span = /^t=([^,&]+),([^&]+)/.exec(item.target.selector.value || '');
                if (span && parseFloat(span[2]) < parseFloat(span[1])) {
                    errors.push({ path: '/target/selector/value', message: 'must not end before it starts' });
                }
            }

            return errors;

        }


        /* -------------------------------------------------------------- */
        /*  Reading                                                       */
        /* -------------------------------------------------------------- */

        function findData(kind, millis, creator) {

            var list = ITEM_KINDS[kind].list();

            for (var i = 0; i < list.length; i++) {
                if (list[i].created === millis
                        && (creator === undefined || String(list[i].creatorId) === String(creator))) {
                    return list[i];
                }
            }

            return null;

        }

        // An item reference: its created (ISO text, or milliseconds); for anyone's annotation { creator, created }, a plain created finds one of the user's own.
        function findItem(kind, ref) {

            if (kind === 'annotations') {
                if (isObject(ref)) {
                    return findData(kind, toMillis(ref.created), ref.creator);
                }
                return findData(kind, toMillis(ref), user.id);
            }

            return findData(kind, toMillis(ref));

        }

        function requireItem(kind, ref) {

            var data = findItem(kind, ref);

            if (!data) {
                throw editError('notFound', 'No ' + ITEM_KINDS[kind].name + ' ' + JSON.stringify(ref));
            }

            return data;

        }

        function findChapter(start) {

            var value = (typeof start === 'string') ? parseFloat(start) : start;

            for (var i = 0; i < model.chapters.length; i++) {
                if (model.chapters[i].start === value) { return model.chapters[i]; }
            }

            return null;

        }

        function sortedChapters() {
            return model.chapters.slice().sort(function(a, b) { return a.start - b.start; });
        }

        function contentViewsOf(whichArea) {
            var layout = isObject(model.layout) ? model.layout : {};
            return clone(Array.isArray(layout[AREA_KEYS[whichArea]]) ? layout[AREA_KEYS[whichArea]] : []);
        }

        function matches(filter, info) {

            if (!isObject(filter)) { return true; }

            if (filter.from !== undefined && info.end !== undefined && info.end < filter.from) { return false; }
            if (filter.to !== undefined && info.start !== undefined && info.start > filter.to) { return false; }
            if (filter.type !== undefined && info.type !== filter.type) { return false; }
            if (filter.creator !== undefined && String(info.creator) !== String(filter.creator)) { return false; }

            return true;

        }

        function list(kind, filter) {

            var result;

            if (ITEM_KINDS[kind]) {

                result = ITEM_KINDS[kind].list().filter(function(data) {
                    return matches(filter, {
                        start:   data.start,
                        end:     (data.end !== undefined) ? data.end : data.start,
                        type:    (kind === 'codeSnippets') ? 'codesnippet' : data.type,
                        creator: data.creatorId
                    });
                }).map(function(data) {
                    return ITEM_KINDS[kind].serialize(data);
                });

            } else if (kind === 'chapters') {

                result = sortedChapters().filter(function(chapter) {
                    return matches(filter, { start: chapter.start, end: chapter.start });
                }).map(clone);

            } else if (kind === 'contentViews') {

                var areas = (isObject(filter) && filter.area !== undefined) ? [AREAS[filter.area]] : ['top', 'bottom', 'left', 'right'];

                if (!areas[0]) {
                    throw editError('invalid', 'Unknown layout area "' + filter.area + '"; one of top, bottom, left, right');
                }

                result = [];
                areas.forEach(function(whichArea) {
                    Array.prototype.push.apply(result, contentViewsOf(whichArea));
                });

            } else if (kind === 'subtitles') {

                result = Array.isArray(model.subtitles) ? clone(model.subtitles) : [];

            } else {

                throw editError('invalid', 'Unknown kind "' + kind + '"; one of overlays, codeSnippets, annotations, chapters, contentViews, subtitles');

            }

            return (typeof filter === 'function') ? result.filter(filter) : result;

        }

        function get(kind, ref) {

            if (ITEM_KINDS[kind]) {
                var data = findItem(kind, ref);
                return data ? ITEM_KINDS[kind].serialize(data) : null;
            }

            if (kind === 'chapters') {
                var chapter = findChapter(ref);
                return chapter ? clone(chapter) : null;
            }

            if (kind === 'subtitles') {
                var entry = list('subtitles').filter(function(file) { return file.srclang === ref; })[0];
                if (!entry) { return null; }
                entry.vtt = (typeof subtitles[ref] === 'string') ? subtitles[ref] : null;
                return entry;
            }

            if (kind === 'contentViews') {
                throw editError('invalid', 'Content views have no identity; list them with list(\'contentViews\', { area })');
            }

            throw editError('invalid', 'Unknown kind "' + kind + '"');

        }


        /* -------------------------------------------------------------- */
        /*  Writing items                                                 */
        /* -------------------------------------------------------------- */

        // The latest created in a collection (annotations: the user's own).
        function lastCreated(kind) {
            return ITEM_KINDS[kind].list().reduce(function(last, data) {
                if (kind === 'annotations' && String(data.creatorId) !== user.id) { return last; }
                return Math.max(last, data.created || 0);
            }, 0);
        }

        function completeItem(kind, data, errors) {

            var spec = ITEM_KINDS[kind],
                item = clone(data);

            if (item.type === undefined)               { item.type = 'Annotation'; }
            if (item['frametrail:type'] === undefined) { item['frametrail:type'] = spec.itemType; }

            if (kind === 'annotations' && item.creator !== undefined
                    && !(isObject(item.creator) && String(item.creator.id) === user.id)) {
                errors.push({ path: '/creator', message: 'must be the signed-in user' });
            }

            if (kind === 'annotations' || item.creator === undefined) {
                item.creator = { "nickname": user.name, "type": "Person", "id": user.id };
            }

            if (item.created === undefined) {
                item.created = (new Date(Math.max(clock(), lastCreated(kind) + 1))).toISOString();
            } else if (isFinite(toMillis(item.created))
                    && findData(kind, toMillis(item.created), (kind === 'annotations') ? user.id : undefined)) {
                errors.push({ path: '/created', message: 'is taken by another ' + spec.name });
            }

            if (isObject(item.target)) {
                if (item.target.type === undefined) { item.target.type = 'Video'; }
                var path = sourcePath();
                if (path !== undefined) { item.target.source = path; }
                if (isObject(item.target.selector)) {
                    if (item.target.selector.type === undefined)       { item.target.selector.type = 'FragmentSelector'; }
                    if (item.target.selector.conformsTo === undefined) { item.target.selector.conformsTo = MEDIA_FRAGMENTS; }
                }
            }

            return item;

        }

        function addItem(kind, data) {

            var spec = itemKind(kind);

            requireEditing(kind);

            if (!isObject(data)) {
                throw editError('invalid', 'Invalid ' + spec.name, [{ path: '', message: 'must be object, is ' + util.typeName(data) }]);
            }

            var errors = [],
                item   = completeItem(kind, data, errors);

            errors = errors.concat(validate(spec.schema, item));
            if (!errors.length) { errors = itemErrors(kind, item); }
            if (errors.length) {
                throw editError('invalid', 'Invalid ' + spec.name, errors);
            }

            var parsed = spec.parse(clone(item));

            spec.set(spec.list().concat([parsed]));
            markDirty(kind);

            return spec.serialize(parsed);

        }

        function updateItem(kind, ref, patch) {

            var spec = itemKind(kind);

            requireEditing(kind);

            var before = requireItem(kind, ref);

            if (kind === 'annotations' && String(before.creatorId) !== user.id) {
                throw editError('notAllowed', 'Only your own annotations can be changed');
            }

            if (!isObject(patch)) {
                throw editError('invalid', 'Invalid change of ' + spec.name, [{ path: '', message: 'must be object, is ' + util.typeName(patch) }]);
            }

            var current = spec.serialize(before),
                next    = util.mergePatch(current, patch),
                errors  = [];

            if (!sameJSON(next.created, current.created)) {
                errors.push({ path: '/created', message: 'cannot be changed' });
            }
            if (!sameJSON(next.creator, current.creator)) {
                errors.push({ path: '/creator', message: 'cannot be changed' });
            }
            if (isObject(next.body) && isObject(current.body) && next.body['frametrail:type'] !== current.body['frametrail:type']) {
                errors.push({ path: '/body/frametrail:type', message: 'cannot be changed; remove the ' + spec.name + ' and add a new one' });
            }

            if (isObject(next.target) && sourcePath() !== undefined) {
                next.target.source = sourcePath();
            }

            errors = errors.concat(validate(spec.schema, next));
            if (!errors.length) { errors = itemErrors(kind, next); }
            if (errors.length) {
                throw editError('invalid', 'Invalid change of ' + spec.name, errors);
            }

            if (sameJSON(next, current)) {
                return current;
            }

            var after = spec.parse(clone(next));

            // The same item: identity and where it was loaded from stay.
            after.created = before.created;
            if (kind === 'annotations') { after.source = clone(before.source); }

            spec.set(spec.list().map(function(data) { return (data === before) ? after : data; }));
            markDirty(kind);

            return spec.serialize(after);

        }

        function removeItem(kind, ref) {

            var spec = itemKind(kind);

            requireEditing(kind);

            var data = requireItem(kind, ref);

            if (kind === 'annotations' && String(data.creatorId) !== user.id) {
                throw editError('notAllowed', 'Only your own annotations can be changed');
            }

            var stored = spec.serialize(data);

            spec.set(spec.list().filter(function(other) { return other !== data; }));
            markDirty(kind);

            return stored;

        }


        /* -------------------------------------------------------------- */
        /*  Writing chapters                                              */
        /* -------------------------------------------------------------- */

        function setChapters(chapters) {
            model.chapters = chapters.slice().sort(function(a, b) { return a.start - b.start; });
            markDirty('chapters');
        }

        function addChapter(data) {

            requireEditing('chapters');

            if (!isObject(data)) {
                throw editError('invalid', 'Invalid chapter', [{ path: '', message: 'must be object, is ' + util.typeName(data) }]);
            }

            var item   = clone(data),
                errors = validate(CHAPTER_SCHEMA, item);

            if (!errors.length && findChapter(item.start)) {
                errors.push({ path: '/start', message: 'is taken by another chapter' });
            }
            if (errors.length) {
                throw editError('invalid', 'Invalid chapter', errors);
            }

            setChapters(model.chapters.concat([item]));

            return clone(item);

        }

        function updateChapter(start, patch) {

            requireEditing('chapters');

            var chapter = findChapter(start);

            if (!chapter) {
                throw editError('notFound', 'No chapter starts at ' + JSON.stringify(start));
            }

            if (!isObject(patch)) {
                throw editError('invalid', 'Invalid change of chapter', [{ path: '', message: 'must be object, is ' + util.typeName(patch) }]);
            }

            var next   = util.mergePatch(chapter, patch),
                errors = validate(CHAPTER_SCHEMA, next),
                other  = errors.length ? null : findChapter(next.start);

            if (other && other !== chapter) {
                errors.push({ path: '/start', message: 'is taken by another chapter' });
            }
            if (errors.length) {
                throw editError('invalid', 'Invalid change of chapter', errors);
            }

            if (sameJSON(next, chapter)) {
                return clone(chapter);
            }

            setChapters(model.chapters.map(function(data) { return (data === chapter) ? next : data; }));

            return clone(next);

        }

        function removeChapter(start) {

            requireEditing('chapters');

            var chapter = findChapter(start);

            if (!chapter) {
                throw editError('notFound', 'No chapter starts at ' + JSON.stringify(start));
            }

            setChapters(model.chapters.filter(function(data) { return data !== chapter; }));

            return clone(chapter);

        }


        /* -------------------------------------------------------------- */
        /*  add, update, remove, layout, subtitles                        */
        /* -------------------------------------------------------------- */

        function noIdentity(kind) {

            if (kind === 'contentViews') {
                return editError('invalid', 'Content views are set per layout area, with setLayout(area, contentViews)');
            }
            if (kind === 'subtitles') {
                return editError('invalid', 'Subtitles are set per language, with setSubtitles(lang, vttText)');
            }

            return editError('invalid', 'Unknown kind "' + kind + '"; one of overlays, codeSnippets, annotations, chapters');

        }

        function add(kind, data) {
            if (kind === 'chapters') { return addChapter(data); }
            if (!ITEM_KINDS[kind]) { throw noIdentity(kind); }
            return addItem(kind, data);
        }

        function update(kind, ref, patch) {
            if (kind === 'chapters') { return updateChapter(ref, patch); }
            if (!ITEM_KINDS[kind]) { throw noIdentity(kind); }
            return updateItem(kind, ref, patch);
        }

        function remove(kind, ref) {
            if (kind === 'chapters') { return removeChapter(ref); }
            if (!ITEM_KINDS[kind]) { throw noIdentity(kind); }
            return removeItem(kind, ref);
        }

        function setLayout(area, contentViews) {

            requireEditing('contentViews');

            var whichArea = AREAS[area];

            if (!whichArea) {
                throw editError('invalid', 'Unknown layout area "' + area + '"; one of top, bottom, left, right');
            }

            if (!Array.isArray(contentViews)) {
                throw editError('invalid', 'Invalid content views', [{ path: '', message: 'must be array, is ' + util.typeName(contentViews) }]);
            }

            var errors = [];

            contentViews.forEach(function(contentView, i) {
                validate('hypervideo.schema.json#/$defs/contentView', contentView).forEach(function(e) {
                    errors.push({ path: '/' + i + e.path, message: e.message });
                });
            });

            if (errors.length) {
                throw editError('invalid', 'Invalid content views', errors);
            }

            // The editor keeps all four areas, and saves them all.
            var old    = isObject(model.layout) ? model.layout : {},
                layout = {};

            ['areaTop', 'areaBottom', 'areaLeft', 'areaRight'].forEach(function(key) {
                layout[key] = Array.isArray(old[key]) ? old[key] : [];
            });
            Object.keys(old).forEach(function(key) {
                if (!Object.prototype.hasOwnProperty.call(layout, key)) { layout[key] = old[key]; }
            });

            layout[AREA_KEYS[whichArea]] = clone(contentViews);

            model.layout = layout;
            markDirty('contentViews');

            return contentViewsOf(whichArea);

        }

        function setSubtitles(lang, vttText) {

            requireEditing('subtitles');

            if (typeof lang !== 'string' || !/^[A-Za-z0-9_-]{1,32}$/.test(lang)) {
                throw editError('invalid', 'Invalid language "' + lang + '": letters, digits, _ and - (at most 32)');
            }

            if (vttText !== null) {
                if (typeof vttText !== 'string') {
                    throw editError('invalid', 'Invalid subtitles', [{ path: '', message: 'must be string or null, is ' + util.typeName(vttText) }]);
                }
                if (!/^﻿?WEBVTT(?:[ \t]|\r?\n|$)/.test(vttText)) {
                    throw editError('invalid', 'Invalid subtitles', [{ path: '', message: 'must begin with WEBVTT' }]);
                }
            }

            var files    = Array.isArray(model.subtitles) ? model.subtitles : [],
                listed   = files.some(function(file) { return isObject(file) && file.srclang === lang; }),
                previous = (listed && typeof subtitles[lang] === 'string') ? subtitles[lang] : null;

            if (previous !== vttText) {

                var entry = { "src": lang + '.vtt', "srclang": lang },
                    texts = Object.assign({}, subtitles);

                if (vttText === null) {
                    model.subtitles = files.filter(function(file) { return !(isObject(file) && file.srclang === lang); });
                    delete texts[lang];
                } else {
                    // The file is always written as <lang>.vtt.
                    model.subtitles = listed
                        ? files.map(function(file) { return (isObject(file) && file.srclang === lang) ? entry : file; })
                        : files.concat([entry]);
                    texts[lang] = vttText;
                }

                subtitles = texts;
                markDirty('subtitles');

            }

            var current = list('subtitles').filter(function(file) { return file.srclang === lang; })[0];

            return current || null;

        }


        /* -------------------------------------------------------------- */
        /*  Transactions                                                  */
        /* -------------------------------------------------------------- */

        function snapshot() {
            return {
                overlays:     model.overlays,
                codeSnippets: model.codeSnippets,
                chapters:     model.chapters,
                layout:       model.layout,
                files:        model.subtitles,
                annotations:  annotations,
                subtitles:    subtitles,
                dirty:        clone(dirty)
            };
        }

        function restore(saved) {
            model.overlays     = saved.overlays;
            model.codeSnippets = saved.codeSnippets;
            model.chapters     = saved.chapters;
            model.layout       = saved.layout;
            model.subtitles    = saved.files;
            annotations        = saved.annotations;
            subtitles          = saved.subtitles;
            dirty              = saved.dirty;
        }

        /**
         * I run fn with this store and keep what it changes only when it
         * returns, or its promise resolves; when it throws or rejects,
         * everything is as before. Inside a transaction, a transaction is part
         * of it.
         */
        function transaction(description, fn) {

            if (transacting) { return fn(store); }

            var saved = snapshot(),
                result;

            transacting = true;

            try {
                result = fn(store);
            } catch (e) {
                restore(saved);
                transacting = false;
                throw e;
            }

            if (!result || typeof result.then !== 'function') {
                transacting = false;
                return result;
            }

            return result.then(function(value) {
                transacting = false;
                return value;
            }, function(e) {
                restore(saved);
                transacting = false;
                throw e;
            });

        }


        /* -------------------------------------------------------------- */
        /*  The bundle                                                    */
        /* -------------------------------------------------------------- */

        /**
         * I return the bundle as a save would leave it: hypervideo.json and
         * the user's annotation file written anew when they changed (with
         * options.all: always), every other file as it was given. The
         * annotations index is not touched: the server keeps it.
         */
        function bundleData(dataOptions) {

            var all = !!(dataOptions && dataOptions.all),
                out = clone(bundle);

            if (dirty.hypervideo || all) {
                out.hypervideo = getHypervideo();
            }

            var own   = annotations.filter(function(annotation) { return String(annotation.creatorId) === user.id; }),
                write = dirty.annotations || (all && (own.length > 0 || Object.prototype.hasOwnProperty.call(files, user.id)));

            if (write && user.id !== '') {
                if (!isObject(out.annotations)) { out.annotations = {}; }
                if (!isObject(out.annotations.files)) { out.annotations.files = {}; }
                out.annotations.files[user.id] = Serializer.serializeAnnotationFile(own, itemContext());
            }

            if (Object.keys(subtitles).length || isObject(bundle.subtitles)) {
                out.subtitles = clone(subtitles);
            }

            if (!project) { return out; }

            var whole = clone(project);
            whole.hypervideos[id] = out;
            return whole;

        }


        var store = {

            getInfo:         getInfo,
            getUser:         getUser,
            permission:      permission,
            listHypervideos: listHypervideos,
            versions:        versions,
            resources:       knownResources,

            getHypervideo:   getHypervideo,
            list:            list,
            get:             get,

            add:             add,
            update:          update,
            remove:          remove,
            setLayout:       setLayout,
            setSubtitles:    setSubtitles,

            transaction:     transaction,
            data:            bundleData

        };

        return store;

    }


    ops.modelStore = modelStore;

})(window.FrameTrailConversationalUI);
