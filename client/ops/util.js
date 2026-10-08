/*
 * FrameTrail-Conversational-UI — helpers of the operations (ops.util): JSON,
 * merge patches, errors, Media Fragments, plain text and WebVTT cues. They
 * know no FrameTrail instance; the PHP interpreter has the same rules
 * (shared/fixtures/README.md).
 */

(function(ConversationalUI) {

    var ops = ConversationalUI.ops;


    /* ------------------------------------------------------------------ */
    /*  JSON                                                              */
    /* ------------------------------------------------------------------ */

    function isObject(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    function has(obj, key) {
        return isObject(obj) && Object.prototype.hasOwnProperty.call(obj, key) && obj[key] !== undefined;
    }

    // A deep copy with JSON semantics.
    function clone(value) {
        return (value === undefined) ? undefined : JSON.parse(JSON.stringify(value));
    }

    // Equality as JSON sees it: key order does not matter, undefined properties do not exist.
    function sameJSON(a, b) {

        if (a === b) { return true; }
        if (a === null || b === null || typeof a !== 'object' || typeof b !== 'object') { return false; }
        if (Array.isArray(a) !== Array.isArray(b)) { return false; }

        if (Array.isArray(a)) {
            if (a.length !== b.length) { return false; }
            for (var i = 0; i < a.length; i++) {
                if (!sameJSON(a[i], b[i])) { return false; }
            }
            return true;
        }

        var keysA = Object.keys(a).filter(function(key) { return a[key] !== undefined; }),
            keysB = Object.keys(b).filter(function(key) { return b[key] !== undefined; });

        if (keysA.length !== keysB.length) { return false; }

        return keysA.every(function(key) { return has(b, key) && sameJSON(a[key], b[key]); });

    }

    function typeName(value) {
        return (value === null) ? 'null' : (Array.isArray(value) ? 'array' : typeof value);
    }

    // RFC 7386 JSON Merge Patch: objects are merged key by key, null removes a key, anything else replaces.
    function mergePatch(target, patch) {

        if (!isObject(patch)) { return clone(patch); }

        var result = isObject(target) ? clone(target) : {};

        Object.keys(patch).forEach(function(key) {
            if (patch[key] === undefined) { return; }
            if (patch[key] === null) {
                delete result[key];
            } else {
                result[key] = mergePatch(result[key], patch[key]);
            }
        });

        return result;

    }

    /**
     * I return the merge patch that turns from into to, or undefined when they
     * are the same. Objects are compared key by key, everything else is
     * replaced as a whole; a key to lacks becomes null. A merge patch cannot
     * set a property to null, so a null in to comes out as a removal.
     */
    function diffPatch(from, to) {

        if (sameJSON(from, to)) { return undefined; }
        if (!isObject(from) || !isObject(to)) { return (to === null) ? null : clone(to); }

        var patch = {};

        Object.keys(from).forEach(function(key) {
            if (from[key] === undefined) { return; }
            if (!has(to, key) || (to[key] === null && from[key] !== null)) {
                patch[key] = null;
            }
        });

        Object.keys(to).forEach(function(key) {
            if (to[key] === undefined || to[key] === null) { return; }
            var change = diffPatch(has(from, key) ? from[key] : undefined, to[key]);
            if (change !== undefined) { patch[key] = change; }
        });

        return patch;

    }

    // A key as a JSON Pointer token (RFC 6901).
    function pointerToken(key) {
        return String(key).replace(/~/g, '~0').replace(/\//g, '~1');
    }


    /* ------------------------------------------------------------------ */
    /*  Errors                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * I make the error operations throw: code 'invalid' (with errors, each
     * { path, message }, the path a JSON Pointer into the input), 'notFound',
     * 'notAllowed', 'conflict', or 'stopped' (the editor's transaction was
     * stopped). Its message says it all, for a model as for a person.
     */
    function opError(code, message, errors) {

        var list    = errors || [],
            details = list.length
                ? ': ' + list.map(function(e) { return (e.path || '(input)') + ': ' + e.message; }).join('; ')
                : '';

        var error = new Error(message + details);

        error.name   = 'ConversationalUiOpError';
        error.code   = code;
        error.errors = list;

        return error;

    }


    /* ------------------------------------------------------------------ */
    /*  Numbers and Media Fragments                                       */
    /* ------------------------------------------------------------------ */

    // Seconds as written into selectors and shown: rounded to the millisecond.
    function seconds(value) {
        return Math.round(value * 1000) / 1000;
    }

    // "t=12.5,20&…" → { start: 12.5, end: 20 }; end is start for a point ("t=12.5").
    function timeSpan(value) {
        var m = /(?:^|&)t=([0-9.eE+-]+)(?:,([0-9.eE+-]+))?/.exec(value || '');
        if (!m) { return { start: 0, end: 0 }; }
        var start = parseFloat(m[1]),
            end   = (m[2] !== undefined) ? parseFloat(m[2]) : start;
        return { start: isFinite(start) ? start : 0, end: isFinite(end) ? end : 0 };
    }

    // "…&xywh=percent:10,20,30,40" → { left, top, width, height }, or null.
    function box(value) {
        var n = '(-?[0-9.]+(?:[eE][-+]?[0-9]+)?)',
            m = new RegExp('xywh=percent:' + n + ',' + n + ',' + n + ',' + n).exec(value || '');
        return m
            ? { left: parseFloat(m[1]), top: parseFloat(m[2]), width: parseFloat(m[3]), height: parseFloat(m[4]) }
            : null;
    }

    // The Media Fragments value of an overlay or annotation; numbers as JavaScript writes them.
    function fragment(start, end, area) {
        var value = 't=' + String(start) + ',' + String(end);
        if (area) {
            value += '&xywh=percent:' + [area.left, area.top, area.width, area.height].map(String).join(',');
        }
        return value;
    }


    /**
     * I return where a hypervideo's time begins (its clip's in point) and how
     * long it is: from the clip's out point, else the media's duration as
     * given (mediaDuration), else the clip's duration; null when none says.
     */
    function clipSpan(clips, mediaDuration) {

        var clip   = (Array.isArray(clips) && isObject(clips[0])) ? clips[0] : {},
            inTime = (typeof clip['in'] === 'number') ? clip['in'] : 0,
            media  = (typeof mediaDuration === 'number' && mediaDuration > 0)
                ? mediaDuration
                : ((typeof clip.duration === 'number' && clip.duration > 0) ? clip.duration : null),
            out    = (typeof clip.out === 'number' && clip.out > 0) ? clip.out : media;

        return {
            start:    inTime,
            duration: (out !== null && out > inTime) ? seconds(out - inTime) : null
        };

    }


    /* ------------------------------------------------------------------ */
    /*  Text                                                              */
    /* ------------------------------------------------------------------ */

    var ENTITIES = { 'amp': '&', 'lt': '<', 'gt': '>', 'quot': '"', 'apos': '\'', 'nbsp': ' ' };

    // A tag (a letter after < or </) or a comment; "a < b" is text.
    var TAG = /<!--[\s\S]*?-->|<\/?[A-Za-z][^>]*>/g;

    // These character references only, so the PHP side decodes alike.
    function decodeEntities(text) {
        return text.replace(/&(#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[a-zA-Z]+);/g, function(all, name) {
            if (name.charAt(0) === '#') {
                var code = (name.charAt(1) === 'x' || name.charAt(1) === 'X') ? parseInt(name.slice(2), 16) : parseInt(name.slice(1), 10);
                return (code > 0 && code <= 0x10FFFF && !(code >= 0xD800 && code <= 0xDFFF)) ? String.fromCodePoint(code) : all;
            }
            var lower = name.toLowerCase();
            return Object.prototype.hasOwnProperty.call(ENTITIES, lower) ? ENTITIES[lower] : all;
        });
    }

    /**
     * I make plain text of HTML, also of HTML stored escaped (FrameTrail's
     * text attributes): character references decoded, tags and comments
     * removed, decoded again, white space collapsed.
     */
    function plainText(html) {
        if (typeof html !== 'string') { return ''; }
        var text = decodeEntities(decodeEntities(html).replace(TAG, ' '));
        return text.replace(/\s+/g, ' ').trim();
    }

    // The first max characters (code points) of a text, with … when cut.
    function excerpt(text, max) {
        var chars = Array.from(text);
        return (chars.length > max) ? chars.slice(0, max).join('').replace(/\s+$/, '') + '…' : text;
    }


    /* ------------------------------------------------------------------ */
    /*  WebVTT                                                            */
    /* ------------------------------------------------------------------ */

    var TIMESTAMP = '((?:[0-9]+:)?[0-9]{1,2}:[0-9]{2}[.,][0-9]{1,3})',
        TIMING    = new RegExp('^\\s*' + TIMESTAMP + '\\s+-->\\s+' + TIMESTAMP);

    function vttSeconds(stamp) {
        var parts = stamp.replace(',', '.').split(':'),
            total = 0;
        parts.forEach(function(part) { total = total * 60 + parseFloat(part); });
        return seconds(total);
    }

    /**
     * I read the cues of a WebVTT text: { start, end, text } in seconds, text
     * as plain text on one line. Blocks are separated by empty lines; a block
     * whose first or second line is a timing line is a cue, everything else
     * (header, NOTE, STYLE, REGION) is skipped, and so is a cue without text.
     */
    function cues(vtt) {

        if (typeof vtt !== 'string') { return []; }

        var lines  = vtt.replace(/^﻿/, '').replace(/\r\n?/g, '\n').split('\n'),
            blocks = [],
            block  = [];

        lines.forEach(function(line) {
            if (line.trim() === '') {
                if (block.length) { blocks.push(block); block = []; }
            } else {
                block.push(line);
            }
        });
        if (block.length) { blocks.push(block); }

        var result = [];

        blocks.forEach(function(lines) {
            var at = TIMING.test(lines[0]) ? 0 : ((lines.length > 1 && TIMING.test(lines[1])) ? 1 : -1);
            if (at < 0) { return; }
            var timing = TIMING.exec(lines[at]),
                text   = plainText(lines.slice(at + 1).join('\n'));
            if (text === '') { return; }
            result.push({ start: vttSeconds(timing[1]), end: vttSeconds(timing[2]), text: text });
        });

        return result;

    }


    ops.util = {
        isObject:       isObject,
        has:            has,
        clone:          clone,
        sameJSON:       sameJSON,
        typeName:       typeName,
        mergePatch:     mergePatch,
        diffPatch:      diffPatch,
        pointerToken:   pointerToken,
        opError:        opError,
        seconds:        seconds,
        timeSpan:       timeSpan,
        box:            box,
        fragment:       fragment,
        clipSpan:       clipSpan,
        decodeEntities: decodeEntities,
        plainText:      plainText,
        excerpt:        excerpt,
        cues:           cues
    };

})(window.FrameTrailConversationalUI);
