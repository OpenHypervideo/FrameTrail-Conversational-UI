/*
 * FrameTrail-Conversational-UI — the live store (ops.liveStore): the
 * hypervideo open in a FrameTrail instance, read and changed through
 * FrameTrail's edit API (FrameTrail.edit) and nothing else. Its changes are
 * the editor's: the player shows them at once, the normal save writes them,
 * and a transaction (one changeset, or one chat turn) is one undo step.
 *
 *     var store = ops.liveStore(FrameTrail);   // the instance an extension gets
 *     ops.apply(store, changeset, { generator: … });
 *
 * Needs the edit API's reads around the data (getInfo, getUser, permission,
 * listHypervideos), which FrameTrail has since the release after 1.4.1.
 */

(function(ConversationalUI) {

    var ops = ConversationalUI.ops;

    function liveStore(FrameTrail) {

        if (!FrameTrail.edit || typeof FrameTrail.edit.getInfo !== 'function') {
            throw new Error('FrameTrail-Conversational-UI: the editor\'s operations need a FrameTrail whose edit API has getInfo(), getUser(), permission() and listHypervideos() (after 1.4.1)');
        }

        // A store over edit, or over the edit object of a transaction.
        function over(edit) {

            return {

                getInfo:         function() { return edit.getInfo(); },
                getUser:         function() { return edit.getUser(); },
                permission:      function(kind) { return edit.permission(kind); },
                listHypervideos: function() { return edit.listHypervideos(); },

                // The version of hypervideo.json the editor's data was read from; the annotation file's is not told.
                versions: function() {
                    var meta = edit.getHypervideo().meta || {};
                    return (typeof meta.lastchanged === 'number') ? { hypervideo: meta.lastchanged } : {};
                },

                getHypervideo:   function() { return edit.getHypervideo(); },
                list:            function(kind, filter) { return edit.list(kind, filter); },
                get:             function(kind, ref) { return edit.get(kind, ref); },

                add:             function(kind, data) { return edit.add(kind, data); },
                update:          function(kind, ref, patch) { return edit.update(kind, ref, patch); },
                remove:          function(kind, ref) { return edit.remove(kind, ref); },
                setLayout:       function(area, contentViews) { return edit.setLayout(area, contentViews); },
                setSubtitles:    function(lang, vtt) { return edit.setSubtitles(lang, vtt); },

                /**
                 * One undo step for what fn changes; fn may be async (the
                 * editor is busy until it settles, and the user can stop it:
                 * the promise then rejects with code 'stopped'). Inside a
                 * transaction, a transaction is part of it.
                 */
                transaction: function(description, fn) {
                    return edit.transaction(description, function(tx) {
                        return fn(over(tx), tx.signal);
                    });
                }

            };

        }

        return over(FrameTrail.edit);

    }


    ops.liveStore = liveStore;

})(window.FrameTrailConversationalUI);
