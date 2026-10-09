/*
 * FrameTrail-Conversational-UI — lint (FrameTrailConversationalUI.lint):
 * FrameTrail's checks of a hypervideo beyond its schemas (FrameTrailLint,
 * which FrameTrail loads with its serializer), run on a store. The rules,
 * their ids, severities and messages are FrameTrail's: FrameTrailLint.RULES,
 * its tests/fixtures/lint/ and the rules in its tests/README.md.
 *
 *     var result = ConversationalUI.lint.run(store);              // every rule
 *     ConversationalUI.lint.run(store, { rules: ['chapter-order'] });
 *     // → { errors, warnings, findings: [{ rule, severity, kind, ref, creator?, related?, message }] }
 *
 * It reads the store's items as stored, its chapters, its time (getInfo) and
 * subtitles, and the resources where the store knows them (resources(); the
 * editor's store does not, so unknown-resource finds nothing there).
 */

(function(ConversationalUI) {

    var isObject = ConversationalUI.ops.util.isObject;

    var validatorInstance = null;


    // FrameTrail's lint, read when it is needed: FrameTrail loads it before any extension.
    function frameTrailLint() {
        if (!window.FrameTrailLint) {
            throw new Error('FrameTrail-Conversational-UI: the check of changes needs FrameTrail\'s FrameTrailLint (the release after 1.4.1)');
        }
        return window.FrameTrailLint;
    }

    /**
     * I read what FrameTrailLint.run() reads from a store: the hypervideo,
     * its time, items, chapters (sorted), subtitles and, where the store has
     * resources(), the resources.
     */
    function collect(store) {

        var info = store.getInfo() || {};

        return {
            hypervideo:   store.getHypervideo(),
            info:         { start: info.start, end: info.end },
            overlays:     store.list('overlays'),
            annotations:  store.list('annotations'),
            codeSnippets: store.list('codeSnippets'),
            chapters:     store.list('chapters'),
            subtitles:    store.list('subtitles').filter(isObject).map(function(file) {
                              var entry = store.get('subtitles', file.srclang);
                              return { lang: String(file.srclang), vtt: (entry && typeof entry.vtt === 'string') ? entry.vtt : null };
                          }),
            resources:    (typeof store.resources === 'function') ? store.resources() : null
        };

    }

    /**
     * I lint the hypervideo of a store: { errors, warnings, findings }.
     *
     * @param {Object} store a store of the operations (ops.modelStore, ops.liveStore)
     * @param {Object} [options] { rules: [ids] } to run only these
     */
    function run(store, options) {
        return frameTrailLint().run(collect(store), options);
    }

    // The errors of a lint result against FrameTrailLint.RESULT_SCHEMA.
    function validateResult(result) {
        var schema = frameTrailLint().RESULT_SCHEMA;
        if (!validatorInstance) {
            validatorInstance = window.FrameTrailSchema.create([schema]);
        }
        return validatorInstance.validate(schema.$id, result);
    }


    ConversationalUI.lint = {
        collect:        collect,
        run:            run,
        validateResult: validateResult
    };

})(window.FrameTrailConversationalUI);
