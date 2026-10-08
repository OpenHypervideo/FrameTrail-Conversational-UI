/*
 * FrameTrail-Conversational-UI — the extension's entry, last in the build
 * order. It registers the extension with FrameTrail as "conversational-ui",
 * brings the labels, and takes the add-on's place in the interface: a side
 * panel beside the player while editing.
 *
 * FrameTrail loads it from config.json → extensions, or a page that includes
 * the script names it in the extensions init option (see README.md).
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
            labels       = Localization.labels;

        Localization.addLabels(ConversationalUI.labels);

        return {

            slots: {

                // Docked beside the player and kept open across edit modes,
                // so a conversation can continue from overlays to chapters.
                sidePanel: {
                    label:  labels['ConversationalUiTitle'],
                    icon:   'icon-chat',
                    when:   'edit',
                    create: function(container, panel) {
                        var root = document.createElement('div');
                        root.className = 'conversationalUi';
                        container.append(root);
                    }
                }

            }

        };

    });

})();
