/*
 * FrameTrail-Conversational-UI — items (ops.items): the short form of items
 * that operations return, and the stored items and merge patches they make
 * from an operation's input (time and box as numbers, the body in FrameTrail's
 * stored form). Same rules in the PHP interpreter (shared/fixtures/README.md).
 */

(function(ConversationalUI) {

    var ops  = ConversationalUI.ops,
        util = ops.util;

    var isObject = util.isObject,
        has      = util.has,
        clone    = util.clone;

    // At most this many characters of an item's text in its short form.
    var EXCERPT_LENGTH = 160;

    // Body types whose text the short form shows, and where it is.
    var TEXT_OF = {
        'text':    function(attributes) {
            var text  = util.plainText(attributes.text),
                title = util.plainText(attributes.title);
            return (title && text) ? title + ': ' + text : (title || text);
        },
        'html':    function(attributes) { return util.plainText(attributes.text); },
        'entity':  function(attributes) { return util.plainText(attributes.text); },
        'quiz':    function(attributes) { return util.plainText(attributes.question); },
        'hotspot': function(attributes) { return util.plainText(attributes.text); }
    };

    // Body types that keep their src in value (the others in source; text and quiz keep nothing there).
    var SRC_IN_VALUE = ['webpage', 'wikipedia', 'entity'];

    function attributesOf(body) {
        var attributes = body['frametrail:attributes'];
        return isObject(attributes) ? attributes : {};
    }

    function bodyOf(item) {
        var body = Array.isArray(item.body) ? item.body[0] : item.body;
        return isObject(body) ? body : {};
    }

    function selectorOf(item) {
        return (isObject(item.target) && isObject(item.target.selector)) ? item.target.selector : {};
    }

    // What overlays and annotations have in common in short form: type, name, text, src, tags.
    function describe(summary, item) {

        var body = bodyOf(item),
            type = body['frametrail:type'];

        if (typeof type === 'string') { summary.type = type; }
        if (typeof body['frametrail:name'] === 'string' && body['frametrail:name'] !== '') { summary.name = body['frametrail:name']; }

        return function finish() {

            var text = TEXT_OF[type] ? TEXT_OF[type](attributesOf(body)) : '',
                src  = (typeof body.source === 'string' && body.source !== '')
                    ? body.source
                    : ((SRC_IN_VALUE.indexOf(type) >= 0 && typeof body.value === 'string' && body.value !== '') ? body.value : undefined);

            if (text !== '') { summary.text = util.excerpt(text, EXCERPT_LENGTH); }
            if (src !== undefined) { summary.src = src; }

            var tags = item['frametrail:tags'];
            if (Array.isArray(tags) && tags.length) { summary.tags = clone(tags); }

            return summary;

        };

    }

    /**
     * I return the short form of an item as stored: an overlay, annotation or
     * code snippet (W3C), or a chapter ({ start, title }). context: userId
     * (to mark one's own annotations), chapterEnd (a chapter's end: where the
     * next one starts, or where the video ends).
     */
    function summary(kind, item, context) {

        context = context || {};

        if (kind === 'chapters') {
            return {
                kind:  'chapters',
                ref:   item.start,
                title: (typeof item.title === 'string') ? item.title : '',
                start: item.start,
                end:   (context.chapterEnd !== undefined) ? context.chapterEnd : null
            };
        }

        var selector = selectorOf(item),
            span     = util.timeSpan(selector.value),
            short    = { kind: kind, ref: item.created };

        if (kind === 'codeSnippets') {
            var snippetBody = bodyOf(item);
            if (typeof snippetBody['frametrail:name'] === 'string' && snippetBody['frametrail:name'] !== '') { short.name = snippetBody['frametrail:name']; }
            short.start = span.start;
            return short;
        }

        if (kind === 'annotations') {
            var creator = isObject(item.creator) ? item.creator : {};
            short.creator = {};
            if (creator.nickname !== undefined) { short.creator.nickname = String(creator.nickname); }
            if (creator.id !== undefined)       { short.creator.id = String(creator.id); }
            short.own = (creator.id !== undefined && String(creator.id) === String(context.userId));
        }

        var finish = describe(short, item);

        short.start = span.start;
        short.end   = span.end;

        if (kind === 'overlays') {
            var area = util.box(selector.value);
            if (area) { short.box = area; }
            if (typeof selector['frametrail:rotation'] === 'number' && selector['frametrail:rotation'] !== 0) {
                short.rotation = selector['frametrail:rotation'];
            }
            if (Array.isArray(selector['frametrail:keyframes']) && selector['frametrail:keyframes'].length) {
                short.moving = true;
            }
        }

        return finish();

    }


    /* ------------------------------------------------------------------ */
    /*  From an operation's input                                         */
    /* ------------------------------------------------------------------ */

    function keyframesOf(raw) {
        return window.FrameTrailKeyframes.normalizeKeyframes(raw);
    }

    /**
     * I make the target selector of an overlay from its time, box, rotation
     * and keyframes, as FrameTrail writes it: with keyframes the box is the
     * area the box moves in (FrameTrailKeyframes.unionBox) and a rotation
     * lives in the keyframes.
     */
    function overlaySelector(start, end, area, rotation, keyframes) {

        var selector = {};

        if (keyframes) {
            selector.value = util.fragment(start, end, window.FrameTrailKeyframes.unionBox(keyframes, start, end));
            selector['frametrail:keyframes'] = keyframes;
        } else {
            selector.value = util.fragment(start, end, area);
            if (typeof rotation === 'number' && rotation !== 0) {
                selector['frametrail:rotation'] = rotation;
            }
        }

        return selector;

    }

    /**
     * I make a new overlay (for the store's add) from add_overlay's input, or
     * throw an op error.
     */
    function newOverlay(input, generator) {

        var keyframes = Array.isArray(input.keyframes) ? keyframesOf(input.keyframes) : undefined;

        if (!keyframes && !isObject(input.box)) {
            throw util.opError('invalid', 'Invalid input', [{ path: '/box', message: 'is required without keyframes' }]);
        }
        if (keyframes && typeof input.rotation === 'number' && input.rotation !== 0) {
            throw util.opError('invalid', 'Invalid input', [{ path: '/rotation', message: 'cannot be given with keyframes, which carry their own rotation (r)' }]);
        }

        var item = {
            target: { selector: overlaySelector(input.start, input.end, input.box, input.rotation, keyframes) },
            body:   clone(input.body)
        };

        if (has(input, 'tags'))   { item['frametrail:tags'] = clone(input.tags); }
        if (has(input, 'events')) { item['frametrail:events'] = clone(input.events); }
        if (generator !== undefined) { item.generator = clone(generator); }

        return item;

    }

    /**
     * I make the merge patch (for the store's update) that update_overlay's
     * input makes of an overlay as stored, or throw an op error. undefined
     * when nothing changes.
     */
    function overlayPatch(current, input, generator) {

        var patch = {};

        // The selector is written anew only when the input changes time or place.
        if (['start', 'end', 'box', 'rotation', 'keyframes'].some(function(key) { return has(input, key); })) {

            var selector = selectorOf(current),
                span     = util.timeSpan(selector.value),
                start    = has(input, 'start') ? input.start : span.start,
                end      = has(input, 'end') ? input.end : span.end,
                given    = has(input, 'keyframes'),
                moving   = keyframesOf(selector['frametrail:keyframes']),
                keyframes = given ? (Array.isArray(input.keyframes) ? keyframesOf(input.keyframes) : undefined) : moving,
                area, rotation;

            if (keyframes) {
                if (has(input, 'box')) {
                    throw util.opError('invalid', 'Invalid input', [{ path: '/box', message: 'cannot be set while the overlay moves; change its keyframes, or stop the motion with keyframes: null' }]);
                }
                if (has(input, 'rotation') && input.rotation !== null && input.rotation !== 0) {
                    throw util.opError('invalid', 'Invalid input', [{ path: '/rotation', message: 'cannot be set while the overlay moves: its keyframes carry the rotation (r)' }]);
                }
            } else {
                area     = has(input, 'box') ? input.box : (util.box(selector.value) || { left: 0, top: 0, width: 0, height: 0 });
                rotation = has(input, 'rotation') ? input.rotation : (moving ? undefined : selector['frametrail:rotation']);
            }

            var next = overlaySelector(start, end, area, rotation, keyframes),
                was  = { value: selector.value };

            // Keyframes the input leaves alone stay as they are stored.
            if (keyframes && !given) { next['frametrail:keyframes'] = clone(selector['frametrail:keyframes']); }

            if (selector['frametrail:keyframes'] !== undefined) { was['frametrail:keyframes'] = selector['frametrail:keyframes']; }
            if (selector['frametrail:rotation'] !== undefined)  { was['frametrail:rotation'] = selector['frametrail:rotation']; }

            var selectorChange = util.diffPatch(was, next);

            if (selectorChange !== undefined) { patch.target = { selector: selectorChange }; }

        }

        if (has(input, 'body'))   { patch.body = clone(input.body); }
        if (has(input, 'tags'))   { patch['frametrail:tags'] = clone(input.tags); }
        if (has(input, 'events')) { patch['frametrail:events'] = clone(input.events); }

        return finishPatch(patch, generator);

    }

    /**
     * I make a new annotation (for the store's add) from add_annotation's input.
     */
    function newAnnotation(input, generator) {

        var item = {
            target: { selector: { value: util.fragment(input.start, input.end) } },
            body:   clone(input.body)
        };

        if (has(input, 'tags')) { item['frametrail:tags'] = clone(input.tags); }
        if (generator !== undefined) { item.generator = clone(generator); }

        return item;

    }

    /**
     * I make the merge patch that update_annotation's input makes of an
     * annotation as stored; undefined when nothing changes.
     */
    function annotationPatch(current, input, generator) {

        var selector = selectorOf(current),
            span     = util.timeSpan(selector.value),
            value    = util.fragment(has(input, 'start') ? input.start : span.start, has(input, 'end') ? input.end : span.end),
            patch    = {};

        if (value !== selector.value) { patch.target = { selector: { value: value } }; }
        if (has(input, 'body')) { patch.body = clone(input.body); }
        if (has(input, 'tags')) { patch['frametrail:tags'] = clone(input.tags); }

        return finishPatch(patch, generator);

    }

    // A patch that changes something also records the generator; one that changes nothing is undefined.
    function finishPatch(patch, generator) {
        if (!Object.keys(patch).length) { return undefined; }
        if (generator !== undefined) { patch.generator = clone(generator); }
        return patch;
    }


    ops.items = {
        summary:         summary,
        newOverlay:      newOverlay,
        overlayPatch:    overlayPatch,
        newAnnotation:   newAnnotation,
        annotationPatch: annotationPatch
    };

})(window.FrameTrailConversationalUI);
