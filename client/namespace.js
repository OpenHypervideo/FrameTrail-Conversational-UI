/*
 * FrameTrail-Conversational-UI — the add-on's one global,
 * FrameTrailConversationalUI, which its other client files fill: the labels
 * (locale/), and later the operations, lint rules, agent loop and model
 * adapters. First in the build order (scripts/build.sh).
 *
 * The parts in here know no FrameTrail instance; module.js creates what an
 * instance needs when FrameTrail loads the extension into it.
 */

window.FrameTrailConversationalUI = {

    // The release label the build writes in ("dev" for a build without one).
    version: '__CONVERSATIONAL_UI_VERSION__',

    // Label tables keyed by locale code, filled by locale/*.js and handed to
    // FrameTrail's Localization.addLabels().
    labels: {}

};
