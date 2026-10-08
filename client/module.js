/*
 * FrameTrail-Conversational-UI — the extension's entry, last in the build
 * order. It registers the extension with FrameTrail as "conversational-ui",
 * brings the labels, and takes the add-on's place in the interface: the chat
 * panel (ui/panel.js) in a side panel beside the player while editing.
 *
 * FrameTrail loads it from config.json → extensions, or a page that includes
 * the script names it in the extensions init option (see README.md). The
 * entry's settings are public; the panel reads manageUrl and label from them
 * (a platform that manages the instance's settings).
 */

(function() {

    var ConversationalUI = window.FrameTrailConversationalUI;

    // FrameTrail before the extension API (1.4.0 and earlier) would never call
    // the factory; say why nothing happens.
    if (typeof FrameTrail === 'undefined' || typeof FrameTrail.registerExtension !== 'function') {
        console.warn('FrameTrail-Conversational-UI ' + ConversationalUI.version + ' needs FrameTrail 1.4.1 or later; not loaded.');
        return;
    }

    FrameTrail.registerExtension('conversational-ui', function(FrameTrail) {

        var Localization = FrameTrail.module('Localization'),
            labels       = Localization.labels,
            settings     = {},
            chat         = null;

        Localization.addLabels(ConversationalUI.labels);

        return {

            init: function(entrySettings) {
                settings = entrySettings || {};
            },

            onHypervideoChange: function(hypervideoID) {
                if (chat) { chat.hypervideoChanged(hypervideoID); }
            },

            onChange: {
                editMode: function() { if (chat) { chat.refresh(); } },
                viewMode: function() { if (chat) { chat.refresh(); } }
            },

            onUnload: function() {
                if (chat) { chat.destroy(); }
                chat = null;
            },

            slots: {

                // Docked beside the player and kept open across edit modes,
                // so a conversation can continue from overlays to chapters.
                sidePanel: {
                    label:   labels['ConversationalUiTitle'],
                    icon:    'icon-ai',
                    width:   380,
                    when:    'edit',
                    create:  function(container) {
                        chat = ConversationalUI.ui.panel(FrameTrail, container, settings);
                        // A hypervideo already open (the hook told nobody yet).
                        try {
                            var info = FrameTrail.edit ? FrameTrail.edit.getInfo() : null;
                            if (info && info.id !== null && info.id !== undefined) { chat.hypervideoChanged(info.id); }
                        } catch (e) { /* nothing open */ }
                    },
                    onOpen:  function() {
                        if (chat) { chat.refresh(); chat.focus(); }
                    }
                }

            }

        };

    });

})();
