/*
 * FrameTrail-Conversational-UI — the lint rules (FrameTrailConversationalUI.lint):
 * checks of the hypervideo in a store that its schemas cannot express. The
 * rules, their ids and severities are in shared/lint.json
 * (FrameTrailConversationalUI.lintRules, embedded by the build); the PHP side
 * (server/lib/lint.php) has the same rules and messages, which
 * shared/fixtures/lint/ holds both to.
 *
 *     var result = ConversationalUI.lint.run(store);              // every rule
 *     ConversationalUI.lint.run(store, { rules: ['chapter-order'] });
 *     // → { errors, warnings, findings: [{ rule, severity, kind, ref, creator?, related?, message }] }
 *
 * It reads the store's items as stored, its chapters in stored order, its
 * time (getInfo) and subtitles, and the resources where the store knows them
 * (resources(); the editor's store does not, so unknown-resource is skipped
 * there).
 */

(function(ConversationalUI) {

    var ops  = ConversationalUI.ops,
        util = ops.util;

    var isObject = util.isObject;

    // The $id of the schema document made from lint.json's $defs.
    var LINT_ID = 'https://raw.githubusercontent.com/OpenHypervideo/FrameTrail-Conversational-UI/main/shared/lint.json';

    // Overlays that are meant to lie over others.
    var OVERLAPPING_TYPES = ['hotspot', 'cursor'];

    // Body types that show a source: a URL, a page, a file.
    var SOURCE_TYPES = ['image', 'video', 'audio', 'pdf', 'youtube', 'vimeo', 'wistia', 'loom', 'twitch', 'soundcloud',
                        'spotify', 'webpage', 'wikipedia', 'mastodon', 'codepen', 'figma', 'urlpreview'];

    // Body types that show a media file.
    var MEDIA_TYPES = ['image', 'video', 'audio', 'pdf'];

    // Hotspot actions that need a target.
    var TARGETED_ACTIONS = ['openUrl', 'jumpToTime', 'jumpToHypervideo'];

    var validatorInstance = null;


    function manifest() {
        if (!ConversationalUI.lintRules) {
            throw new Error('FrameTrail-Conversational-UI: the lint rules are missing from the bundle');
        }
        return ConversationalUI.lintRules;
    }

    function validator() {
        if (!validatorInstance) {
            validatorInstance = window.FrameTrailSchema.create(window.FrameTrailSchemas.concat([{ "$id": LINT_ID, "$defs": manifest().$defs }]));
        }
        return validatorInstance;
    }

    // The errors of a lint result against $defs/result.
    function validateResult(result) {
        return validator().validate(LINT_ID + '#/$defs/result', result);
    }


    /* ------------------------------------------------------------------ */
    /*  Helpers                                                           */
    /* ------------------------------------------------------------------ */

    function bodyOf(item) {
        var body = Array.isArray(item.body) ? item.body[0] : item.body;
        return isObject(body) ? body : {};
    }

    function attributesOf(body) {
        return isObject(body['frametrail:attributes']) ? body['frametrail:attributes'] : {};
    }

    function selectorValue(item) {
        return (isObject(item.target) && isObject(item.target.selector)) ? item.target.selector.value : undefined;
    }

    function nonEmpty(value) {
        return typeof value === 'string' && value.trim() !== '';
    }

    // A number as messages show it: seconds as stored.
    function n(value) {
        return String(value);
    }

    // Where the video's time lies, as messages show it.
    function rangeText(info) {
        return (info.end !== null) ? n(info.start) + ' s to ' + n(info.end) + ' s' : 'from ' + n(info.start) + ' s';
    }

    // Who an item is: kind and ref, and for annotations their creator.
    function about(kind, item) {
        var where = { kind: kind, ref: (kind === 'chapters') ? item.start : item.created };
        if (kind === 'annotations' && isObject(item.creator) && item.creator.id !== undefined) {
            where.creator = String(item.creator.id);
        }
        return where;
    }


    /* ------------------------------------------------------------------ */
    /*  The rules                                                         */
    /* ------------------------------------------------------------------ */

    // Each rule: (data, report) where report(where, message) adds a finding.
    var RULES = {

        'item-outside-video': function(data, report) {

            var info = data.info;

            data.spans.forEach(function(entry) {
                var span = entry.span;
                if ((info.end !== null && span.start >= info.end) || (span.end <= info.start && span.start < info.start)) {
                    report(entry.where, 'Runs from ' + n(span.start) + ' s to ' + n(span.end) + ' s, outside the video (' + rangeText(info) + '); the player never shows it.');
                }
            });

            data.points.forEach(function(entry) {
                if ((info.end !== null && entry.start >= info.end) || entry.start < info.start) {
                    report(entry.where, 'Is at ' + n(entry.start) + ' s, outside the video (' + rangeText(info) + '); the player never reaches it.');
                }
            });

        },

        'item-partly-outside': function(data, report) {

            var info = data.info;

            data.spans.forEach(function(entry) {
                var span    = entry.span,
                    outside = (info.end !== null && span.start >= info.end) || (span.end <= info.start && span.start < info.start);
                if (!outside && ((info.end !== null && span.end > info.end) || span.start < info.start)) {
                    report(entry.where, 'Runs from ' + n(span.start) + ' s to ' + n(span.end) + ' s, partly outside the video (' + rangeText(info) + '); that part is never shown.');
                }
            });

        },

        'overlay-overlap': function(data, report) {

            var placed = data.overlays.filter(function(overlay) {
                var area = util.box(selectorValue(overlay));
                return OVERLAPPING_TYPES.indexOf(bodyOf(overlay)['frametrail:type']) < 0 && area && area.width > 0 && area.height > 0;
            }).map(function(overlay) {
                return { overlay: overlay, area: util.box(selectorValue(overlay)), span: util.timeSpan(selectorValue(overlay)) };
            });

            placed.forEach(function(b, j) {
                for (var i = 0; i < j; i++) {
                    var a = placed[i];
                    if (a.span.start < b.span.end && b.span.start < a.span.end
                            && a.area.left < b.area.left + b.area.width && b.area.left < a.area.left + a.area.width
                            && a.area.top < b.area.top + b.area.height && b.area.top < a.area.top + a.area.height) {
                        var where = about('overlays', b.overlay);
                        where.related = { kind: 'overlays', ref: a.overlay.created };
                        report(where, 'Covers the same area as overlay ' + a.overlay.created + ' from '
                            + n(Math.max(a.span.start, b.span.start)) + ' s to ' + n(Math.min(a.span.end, b.span.end)) + ' s.');
                    }
                }
            });

        },

        'unknown-resource': function(data, report) {

            if (!data.resources) { return; }

            var known = function(id) { return Object.prototype.hasOwnProperty.call(data.resources, String(id)); },
                named = function(id) { return id !== undefined && id !== null && id !== ''; };

            var clip = (Array.isArray(data.hypervideo.clips) && isObject(data.hypervideo.clips[0])) ? data.hypervideo.clips[0] : {};
            if (named(clip.resourceId) && !known(clip.resourceId)) {
                report({ kind: 'hypervideo' }, 'Its video is resource ' + JSON.stringify(clip.resourceId) + ', which is not in the resources index'
                    + (nonEmpty(clip.src) ? '.' : '; without a src of its own the clip has no video.'));
            }

            data.items.forEach(function(entry) {
                var id = bodyOf(entry.item)['frametrail:resourceId'];
                if (named(id) && !known(id)) {
                    report(entry.where, 'Made from resource ' + JSON.stringify(id) + ', which is not in the resources index.');
                }
            });

        },

        'empty-required': function(data, report) {

            data.items.forEach(function(entry) {

                var body    = bodyOf(entry.item),
                    type    = body['frametrail:type'],
                    attrs   = attributesOf(body),
                    missing = [];

                if (entry.where.kind === 'codeSnippets') {
                    if (!nonEmpty(body.value)) { missing.push('no code'); }
                } else if (SOURCE_TYPES.indexOf(type) >= 0) {
                    if (!nonEmpty(body.source) && !nonEmpty(body.value)) { missing.push('no source'); }
                } else if (type === 'entity') {
                    if (!nonEmpty(body.source) && !nonEmpty(body.value) && !nonEmpty(entry.item['frametrail:uri'])) { missing.push('no source'); }
                } else if (type === 'location') {
                    if (!isFinite(parseFloat(attrs.lat)) || !isFinite(parseFloat(attrs.lon))) { missing.push('no position (lat, lon)'); }
                } else if (type === 'text') {
                    if (util.plainText(attrs.text) === '' && util.plainText(attrs.title) === '') { missing.push('no text'); }
                } else if (type === 'html') {
                    if (!nonEmpty(attrs.text)) { missing.push('no HTML'); }
                } else if (type === 'quiz') {
                    if (util.plainText(attrs.question) === '') { missing.push('no question'); }
                    if (attrs.questionType === undefined || attrs.questionType === 'multipleChoice' || attrs.questionType === 'multiSelect') {
                        var answers = Array.isArray(attrs.answers) ? attrs.answers : [];
                        if (!answers.length) {
                            missing.push('no answers');
                        } else if (answers.some(function(answer) { return !isObject(answer) || util.plainText(answer.text) === ''; })) {
                            missing.push('an answer without text');
                        }
                    }
                } else if (type === 'chart') {
                    if (!nonEmpty(attrs.data)) { missing.push('no data'); }
                } else if (type === 'hotspot') {
                    if (TARGETED_ACTIONS.indexOf(attrs.action) >= 0
                            && (attrs.actionTarget === undefined || attrs.actionTarget === null || (typeof attrs.actionTarget === 'string' && attrs.actionTarget.trim() === ''))) {
                        missing.push('no target for its action ' + attrs.action);
                    }
                }

                if (missing.length) {
                    report(entry.where, 'Is incomplete: ' + missing.join(', ') + '.');
                }

            });

        },

        'missing-license': function(data, report) {

            data.overlays.forEach(function(overlay) {
                var body       = bodyOf(overlay),
                    resourceId = body['frametrail:resourceId'],
                    backed     = (resourceId !== undefined && resourceId !== null && resourceId !== '')
                              || (MEDIA_TYPES.indexOf(body['frametrail:type']) >= 0 && nonEmpty(body.source));
                if (backed && !nonEmpty(body['frametrail:licenseType'])) {
                    report(about('overlays', overlay), 'Has no license type (body frametrail:licenseType) for the '
                        + ((resourceId !== undefined && resourceId !== null && resourceId !== '') ? 'resource it is made from.' : 'media it shows.'));
                }
            });

        },

        'chapter-order': function(data, report) {

            var chapters = (Array.isArray(data.hypervideo.chapters) ? data.hypervideo.chapters : []).filter(function(chapter) {
                return isObject(chapter) && typeof chapter.start === 'number';
            });

            chapters.forEach(function(chapter, i) {
                if (i === 0) { return; }
                var previous = chapters[i - 1].start;
                if (chapter.start === previous) {
                    report({ kind: 'chapters', ref: chapter.start }, 'Two chapters start at ' + n(chapter.start) + ' s; each start may be used once.');
                } else if (chapter.start < previous) {
                    report({ kind: 'chapters', ref: chapter.start }, 'The chapter at ' + n(chapter.start) + ' s is stored after the one at ' + n(previous) + ' s; chapters are kept in order of their start.');
                }
            });

        },

        'cue-outside-video': function(data, report) {

            var clip = (Array.isArray(data.hypervideo.clips) && isObject(data.hypervideo.clips[0])) ? data.hypervideo.clips[0] : {};

            if (data.info.end === null || (typeof clip.out === 'number' && clip.out > 0)) { return; }

            data.subtitles.forEach(function(entry) {
                var late = util.cues(entry.vtt).filter(function(cue) { return cue.start >= data.info.end; });
                if (late.length) {
                    report({ kind: 'subtitles', ref: entry.lang }, ((late.length === 1) ? '1 cue starts' : late.length + ' cues start')
                        + ' at or after the end of the video (' + n(data.info.end) + ' s), the first at ' + n(late[0].start) + ' s.');
                }
            });

        }

    };


    /* ------------------------------------------------------------------ */
    /*  Running                                                           */
    /* ------------------------------------------------------------------ */

    // What the rules read, once.
    function collect(store) {

        var hypervideo  = store.getHypervideo(),
            info        = store.getInfo(),
            overlays    = store.list('overlays'),
            annotations = store.list('annotations'),
            snippets    = store.list('codeSnippets'),
            chapters    = store.list('chapters'),
            items       = [];

        overlays.forEach(function(item) { items.push({ item: item, where: about('overlays', item) }); });
        annotations.forEach(function(item) { items.push({ item: item, where: about('annotations', item) }); });
        snippets.forEach(function(item) { items.push({ item: item, where: about('codeSnippets', item) }); });

        var subtitles = store.list('subtitles').filter(isObject).map(function(file) {
            var entry = store.get('subtitles', file.srclang);
            return { lang: String(file.srclang), vtt: (entry && typeof entry.vtt === 'string') ? entry.vtt : null };
        });

        return {
            hypervideo: isObject(hypervideo) ? hypervideo : {},
            info:       { start: (typeof info.start === 'number') ? info.start : 0, end: (typeof info.end === 'number') ? info.end : null },
            overlays:   overlays,
            items:      items,
            spans:      items.filter(function(entry) { return entry.where.kind !== 'codeSnippets'; }).map(function(entry) {
                            return { where: entry.where, span: util.timeSpan(selectorValue(entry.item)) };
                        }),
            points:     snippets.map(function(item) { return { where: about('codeSnippets', item), start: util.timeSpan(selectorValue(item)).start }; })
                            .concat(chapters.map(function(chapter) { return { where: about('chapters', chapter), start: chapter.start }; })),
            subtitles:  subtitles,
            resources:  (typeof store.resources === 'function') ? store.resources() : null
        };

    }

    /**
     * I lint the hypervideo of a store: { errors, warnings, findings }.
     *
     * @param {Object} store a store of the operations (ops.modelStore, ops.liveStore)
     * @param {Object} [options] { rules: [ids] } to run only these
     */
    function run(store, options) {

        var only     = (options && Array.isArray(options.rules)) ? options.rules : null,
            data     = collect(store),
            findings = [];

        manifest().rules.forEach(function(rule) {

            if (only && only.indexOf(rule.id) < 0) { return; }
            if (!RULES[rule.id]) { throw new Error('FrameTrail-Conversational-UI: no implementation of the lint rule ' + rule.id); }

            RULES[rule.id](data, function(where, message) {
                var finding = { rule: rule.id, severity: rule.severity, kind: where.kind };
                if (where.ref !== undefined)     { finding.ref = where.ref; }
                if (where.creator !== undefined) { finding.creator = where.creator; }
                if (where.related !== undefined) { finding.related = where.related; }
                finding.message = message;
                findings.push(finding);
            });

        });

        return {
            errors:   findings.filter(function(finding) { return finding.severity === 'error'; }).length,
            warnings: findings.filter(function(finding) { return finding.severity === 'warning'; }).length,
            findings: findings
        };

    }


    ConversationalUI.lint = {
        LINT_ID:        LINT_ID,
        RULES:          RULES,
        run:            run,
        validateResult: validateResult
    };

})(window.FrameTrailConversationalUI);
