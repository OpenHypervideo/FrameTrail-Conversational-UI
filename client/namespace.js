/*
 * FrameTrail-Conversational-UI — the add-on's one global,
 * FrameTrailConversationalUI, which its other client files fill: the labels
 * (locale/), the operations (ops/), the lint rules (lint/), and later the
 * agent loop and model adapters. First in the build order (scripts/build.sh), which writes
 * the shared data in right after it.
 *
 * The parts in here know no FrameTrail instance; module.js creates what an
 * instance needs when FrameTrail loads the extension into it.
 */

window.FrameTrailConversationalUI = {

    // The release label the build writes in ("dev" for a build without one).
    version: '__CONVERSATIONAL_UI_VERSION__',

    // Label tables keyed by locale code, filled by locale/*.js and handed to
    // FrameTrail's Localization.addLabels().
    labels: {},

    // The operations manifest (shared/operations.json), the changeset schema
    // (shared/changeset.schema.json) and the lint rules (shared/lint.json),
    // written in by the build (SHARED_DATA in scripts/build.sh).
    operations:      null,
    changesetSchema: null,
    lintRules:       null,

    // The operations' interpreter, stores and helpers, filled by ops/*.js.
    ops: {},

    // The lint rules' implementation, set by lint/lint.js.
    lint: null

};
