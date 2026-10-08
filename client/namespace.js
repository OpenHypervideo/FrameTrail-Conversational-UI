/*
 * FrameTrail-Conversational-UI — the add-on's one global,
 * FrameTrailConversationalUI, which its other client files fill: the labels
 * (locale/), the operations (ops/), the lint rules (lint/), model access
 * (models/), the conversation (agent/) and the panel (ui/). First in the
 * build order (scripts/build.sh), which writes the shared data and the
 * prompts in right after it.
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

    // The system prompt's fragments (shared/prompts/*.md) as text, by name,
    // written in by the build (SHARED_TEXT in scripts/build.sh).
    prompts: null,

    // The operations' interpreter, stores and helpers, filled by ops/*.js.
    ops: {},

    // The lint rules' implementation, set by lint/lint.js.
    lint: null,

    // Mistral's Chat Completions API and the adapters that reach it, filled by models/*.js.
    models: {},

    // The conversation with the model and its tools, filled by agent/*.js.
    agent: {},

    // The panel's parts, filled by ui/*.js.
    ui: {}

};
