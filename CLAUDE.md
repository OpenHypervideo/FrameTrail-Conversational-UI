# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

FrameTrail-Conversational-UI is an add-on for [FrameTrail](https://github.com/OpenHypervideo/FrameTrail) that lets people edit a hypervideo in natural language. It has two surfaces that share one set of operations:

- **External agents:** an MCP endpoint, a command-line tool and skills (server part, PHP).
- **The editor:** a chat panel docked beside the player (browser part, JavaScript), talking to Mistral's API through a relay on the server (server mode) or directly from the browser (every other storage mode).

The operations are defined once, declaratively (`shared/operations.json`), and interpreted twice: in JavaScript for the live editor (which must also work without PHP) and in PHP for external agents. So are the lint rules (`shared/lint.json`). Shared conformance fixtures keep the two in step.

Work proceeds in phases A0–A9; A0 (scaffold), A1 (operations, changesets, the JavaScript interpreter) and A2 (the PHP core: ports of FrameTrail's serializer, validator and keyframe math, the PHP interpreter, the lint rules in both languages) are done. The phase plan is kept outside this repository.

## Relationship to FrameTrail

The add-on lives entirely outside FrameTrail and uses only its generic, documented building blocks:

- **Browser:** `FrameTrail.registerExtension()` and its slots (`sidePanel`, `titlebarAction`, `editPanel`), `edit` (the edit API: stored-format items, JSON Merge Patch updates, `transaction()` as one undo step, the busy editor and its Stop, and the reads around the data: `getInfo()`, `getUser()`, `permission(kind)`, `listHypervideos()`), `Localization.addLabels()`, `StorageManager.serverPost()` / `extensionURL()`, and the pure globals in FrameTrail's bundle: `FrameTrailSerializer`, `FrameTrailKeyframes`, `FrameTrailSchema` + `FrameTrailSchemas`.
- **Server:** the server extension manifest, `requireLogin()` / `userCheckLogin()`, `ftIsBearerRequest()` (personal API tokens), `ftExtensionStorage()`, `ftExtensionSecrets()`, `hypervideoChange()` with `baseVersion` and subtitle texts, `annotationfileSave()` with `baseVersion`, `sharedFile`. FrameTrail's serializer, validator and keyframe math exist only in JavaScript, and a FrameTrail build carries its schemas only inside its JavaScript bundle, so the server part has PHP ports of the three (`server/lib/frametrail/`) and a copy of the schemas (see "PHP Ports").
- **Data:** the JSON Schemas in FrameTrail's `schemas/`, `docs/DATA-MODEL.md`, and the fixtures in `tests/fixtures/` with the rules in `tests/README.md`.

FrameTrail's own docs for these: `docs/EXTENDING.md` ("Writing an Extension", "Editing the Hypervideo", "Server Extensions"), `docs/DATA-MODEL.md`, `docs/INTEGRATION.md` ("Personal API Tokens"), `docs/DEPLOYMENT.md`.

When the add-on needs something FrameTrail lacks, the change goes into FrameTrail as a generic feature that stands on its own: nothing in the FrameTrail repository mentions this add-on, AI, models, providers, agents or MCP — not in code, docs, examples, schemas, labels, tests or configuration keys. Never reach into FrameTrail internals beyond the building blocks above.

Requires FrameTrail 1.4.1 or later, the first release with all of these but the edit API's reads around the data, which came after it (`develop` until the next release): the operations in the editor (`ops.liveStore`) need them, and say so when they are missing.

## Naming

| What | Name |
|---|---|
| Extension id (folders, `config.json` entry, routes, private storage, secrets) | `conversational-ui` |
| Browser global | `window.FrameTrailConversationalUI` |
| Labels | `ConversationalUi…` |
| Server actions | `conversationalUi…` (via `ajaxServer.php`) |
| PHP functions | `ftConversationalUi…` |
| Routes | `_server/extension.php?e=conversational-ui&r=<route>` (`mcp`, `relay`) |
| CSS | root `.conversationalUi`, scoped under `.sidePanelItem[data-extension="conversational-ui"]` |
| Private storage | `_data/.extensions/conversational-ui/` (history, jobs) |
| Secrets | `_data/.auth/conversational-ui.php` |
| Bundle | `frametrail-conversational-ui.js` / `.css`, `frametrail-conversational-ui-<version>.zip` |
| `generator` on items it writes | `{ "type": "Software", "name": "FrameTrail-Conversational-UI", "model": …, "provider": "mistral" }` |

## Repository Layout

What exists, and the phase that fills the rest:

```
client/                     → build/client/frametrail-conversational-ui.js + .css
├── namespace.js            the global FrameTrailConversationalUI (first in the build)
├── locale/                 en.js, de.js, fr.js
├── ui/                     style.css (the panel shell); panel, settings, messages (A7)
├── ops/                    the operations: util.js (JSON, merge patches, errors, Media Fragments,
│                           plain text, WebVTT), items.js (short forms; items and patches from
│                           input), model-store.js, live-store.js, interpreter.js
├── lint/                   lint.js: the rules of shared/lint.json
├── agent/                  (A7) conversation loop, tool dispatch
├── models/                 (A7) adapters: mistral (direct), relay
├── media/                  (A9) transcription client
└── module.js               the extension entry (last in the build)
server/                     → build/server/ = _server/extensions/conversational-ui/
├── extension.php           the manifest: actions, routes, requires
├── lib/                    the library, loaded by load.php:
│   ├── js.php              JavaScript's semantics (JSON values, numbers, strings, dates, sorting)
│   ├── shared.php          the files of shared/ at run time, FrameTrail's schemas
│   ├── frametrail/         ports of FrameTrailKeyframes, FrameTrailSchema, FrameTrailSerializer;
│   │                       schemas.json (FrameTrail's schemas, from scripts/vendor-schemas.php)
│   ├── ops/                util.php, items.php, store.php (the store interface, edit errors),
│   │                       model-store.php, interpreter.php: the PHP side of client/ops/
│   └── lint.php            the rules of shared/lint.json
│                           (A3) the server's store over an installation's files, history
├── mcp.php                 (A5) route mcp
├── relay.php               (A8) action conversationalUiChat, route relay
├── transcribe.php          (A9)
└── cli/ft.php              (A4)
shared/
├── operations.json         the operations manifest (embedded in the bundle by the build)
├── operations.schema.json  its meta-schema
├── changeset.schema.json   the changeset format (embedded too)
├── lint.json               the lint rules and the shape of their result (embedded too)
├── fixtures/               conformance fixtures: README.md (the rules), data/, ops/, lint/
└── prompts/                (A7) system prompt fragments
skills/                     (A6) SKILL.md folders for external agents
scripts/                    build.sh (concatenation build), vendor-schemas.php (FrameTrail's schemas for PHP)
tests/                      run-js.mjs, run-php.php
```

## Decisions

- Edits are applied directly. In the editor one chat turn is one `edit.transaction()`, so one undo step; MCP and CLI edits are undoable through a server-side history.
- The requesting user is the creator of what the add-on writes; the W3C `generator` records the add-on and the model.
- Model access follows the storage mode: server mode → the PHP relay; local folder, project file, static and in-memory → directly from the browser.
- Model provider: Mistral only, for chat. Its API is hosted in the EU by default and accepts requests from every origin (also `file://` pages), so direct mode needs no setup. Its free plan is for trying it; on it Mistral trains on inputs and outputs unless the account opts out, and the settings say so. No other providers or local chat models in v1; external agents over MCP bring their own model.
- The panel speaks Mistral's Chat Completions API, directly or through the relay; under LinkedVideo the gateway offers the same API.
- Transcription (the first media capability) is server mode only, on a self-hosted Whisper server (OpenAI-compatible `/audio/transcriptions`), never on Mistral.
- Conversations are not stored in `_data` (v1).
- Licence MIT.

## Operations

What an agent can do is defined once, in `shared/operations.json`, and carried out by two interpreters: `client/ops/` in the browser, `server/lib/ops/` on the server (`FtConversationalUiOps`, same functions, same results). The fixtures in `shared/fixtures/` hold both to the same results.

- **The manifest:** each operation has a `name` (the tool name), a `description` written for models, `kind` (what it touches), `effect` (`read` / `write`), `scope` (`instance` or `hypervideo`: surfaces that serve several hypervideos, MCP, add a hypervideo id), `preconditions` (checked in order after the input: `canEditHypervideo`, `canAnnotate`, `itemExists`, `chapterStartFree`, `subtitles`), and `input` / `output` JSON Schemas in FrameTrail's schema subset. References into FrameTrail's schemas are absolute (`https://frametrail.org/schemas/1/…`), shared parts of the manifest are its `$defs` (`#/$defs/item`, `box`, `person`). `operations.schema.json` checks the manifest; the validator gets the input and output schemas as one document (`ops.OPERATIONS_ID`, `#/$defs/<operation>-input`).
- **Inputs** are friendly where the stored form is not: time as `start` / `end` seconds, the box as `{ left, top, width, height }` percent, rotation and keyframes as numbers; the body (and events) in FrameTrail's stored form, a JSON Merge Patch when updating. Overlays, annotations and code snippets are referred to by `created` (`ref`, the user's own annotations, or `{ creator, created }` for reading others'), chapters by `start`. Results of writes are the item's short form (`$defs/item`).
- **Stores** have the edit API's interface (`getHypervideo`, `list`, `get`, `getInfo`, `getUser`, `permission`, `listHypervideos`, `add`, `update`, `remove`, `setLayout`, `setSubtitles`, `transaction`) plus `versions()` (the compare-and-swap tokens of the files: `hypervideo`, and `annotations` where the store knows it) and, where the store knows them, `resources()` (the resources by id, or `null`; for the lint rules). `ops.liveStore(FrameTrail)` is the editor: it passes everything to `edit` and reads nothing else of FrameTrail (no `resources()`); `ops.modelStore(bundle, { user: { id, name, role }, now, duration, hypervideoId })` does the same headless to a hypervideo or project bundle, through FrameTrail's serializer and validator, and gives the bundle back as a save would write it (`data()`). `FtConversationalUiModelStore` is its PHP side (`server/lib/ops/model-store.php`, implementing `FtConversationalUiStore`); a PHP transaction is synchronous, `fn($store)`.
- **Changesets** (`shared/changeset.schema.json`): `{ id, hypervideoId, baseVersion, createdBy, generator, summary, ops: [{ op, input, inverse }] }`. `ops.apply(store, changeset, { generator })` runs all its operations in one transaction (in the editor: one undo step), or none; a given `baseVersion` that no longer holds is refused (`conflict`). Inverses are store calls (`{ method, args }`), recorded from the state before each operation: an add's is a remove, a remove's an add of the item as it was (its `created` kept), an update's the merge patch back. `ops.undo(store, changeset)` replays them, last first.
- **A conversation turn** (A7) opens the transaction itself, `store.transaction(summary, async (tx, signal) => …)`, and runs operations one by one with `ops.record(tx, { summary, generator })`: an operation that fails changes nothing and the turn goes on; the turn is one undo step, and Stop (FrameTrail's, or `signal`) takes it back. `store.permission(kind)` tells which write tools to offer.
- **Errors** are op errors (`ops.util.opError`): `code` `invalid` (with `errors`, JSON Pointers into the input; in a changeset prefixed `/ops/<i>/input`), `notFound`, `notAllowed`, `conflict`, or `stopped` from the editor.
- **generator:** every overlay and annotation an operation adds or changes gets the changeset's `generator`, replacing another tool's; an update that changes nothing writes nothing.

Adding an operation: its entry in `operations.json` (keep the subset; `node tests/run-js.mjs` checks), its implementation in both interpreters (`IMPLEMENTATIONS` in `client/ops/interpreter.js` and in `server/lib/ops/interpreter.php`; both runners fail for an operation without one), any helper rule both must follow in `shared/fixtures/README.md`, and fixture cases (the tests fail for an operation no case uses).

## Lint

Checks of a hypervideo that its schemas cannot express, run after changes (the chat panel after a turn, MCP receipts, the command-line tool): `shared/lint.json` lists the rules (id, severity, description) and the shape of a result; `client/lint/lint.js` (`FrameTrailConversationalUI.lint.run(store, { rules })`) and `server/lib/lint.php` (`FtConversationalUiLint::run($store, array("rules" => …))`) implement them with the same findings and messages.

- **Rules:** `item-outside-video` (error), `item-partly-outside`, `overlay-overlap`, `unknown-resource`, `empty-required` (error), `missing-license`, `chapter-order`, `cue-outside-video` (warnings); `lint.json` says what each checks. Rules that need the video's end skip that part while it is unknown; `unknown-resource` needs `store.resources()`, which the live store does not have.
- **Result:** `{ errors, warnings, findings: [{ rule, severity, kind, ref, creator?, related?, message }] }`, findings by rule in the order of `lint.json`, within a rule as the items are listed. `kind` is a kind of item, `subtitles` or `hypervideo`; `ref` is what `get_item` takes (an annotation's `creator` beside it). Messages are written for a person or a model, numbers as JavaScript writes them.
- **Adding a rule:** its entry in `lint.json`, its implementation in `RULES` of both files (both runners fail for a rule without one), cases in `shared/fixtures/lint/` (the tests fail for a rule no case finds).

## PHP Ports

`server/lib/frametrail/` ports FrameTrail's pure scripts: `FtConversationalUiKeyframes` (`FrameTrailKeyframes`: eases, `normalizeKeyframes`, `sampleKeyframes`, `sampleRotation`, `unionBox`), `FtConversationalUiSchema` (`FrameTrailSchema`: the subset, the same errors), `FtConversationalUiSerializer` (`FrameTrailSerializer`: models, the three-way merge, annotation files and index, `dedupeCreated`, the `folder` bundle format). FrameTrail's JavaScript is the reference: port a change to its pure scripts when the add-on moves to a FrameTrail release that has one (FrameTrail's `tests/fixtures/` and `plans/a2-validation/` show the differences). `schemas.json` is the copy of FrameTrail's schemas the player loads (`FrameTrailSchemas.js`); `php scripts/vendor-schemas.php` writes it from a FrameTrail working copy, and the PHP tests fail while it differs.

The ports and the PHP interpreter give what the JavaScript gives, through `FtConversationalUiJs` (`server/lib/js.php`, imported as `use FtConversationalUiJs as Js;`):

- **JSON values** are what `json_decode()` gives without its second argument: objects are `stdClass` (so `{}` and `[]` stay apart), arrays are lists. JavaScript's `undefined` is `Js::undef()`: `Js::get()` gives it for a missing property, `Js::copy()` (a deep copy, JSON semantics), `Js::obj()` and `Js::same()` (equality as JSON sees it, `5` equals `5.0`) leave it out, `Js::stringify()` refuses it. `Js::has()` is a property that is there and not undefined.
- **Numbers:** `Js::number()` / `Js::str()` write them as JavaScript does (`12.5`, `0.30000000000000004`, `1e+21`, `120`), never PHP's casts; `Js::round()` is `Math.round()` (halves go up); `Js::parseFloat()`, `Js::parseInt()`, `Js::truthy()`, `Js::either()` (`a || b`); compare numbers with `Js::numEq()` / `Js::strictEq()`, never `===` (`60` and `60.0`).
- **Order:** `Js::sort()` is stable (`usort` is not before PHP 8.0) and reads a comparator's number by its sign. Objects written keep the order their keys were stored in; where JavaScript's order decides something (the first hypervideo of a project, the order annotation files are read in), `Js::keys()` gives it (array indices first).
- **Text:** JavaScript's white space is `Js::SPACE` (PCRE's `\s` is ASCII only), `Js::trim()`, `Js::codePoints()` for `Array.from()`, `Js::length()` for `.length` (UTF-16 units), `Js::lower()` for `toLowerCase()` (mbstring's when there is one, else A–Z only). Patterns are PCRE with `u` and `D`.
- **Dates:** `Js::dateParse()` reads what `new Date()` reads in FrameTrail's data (ISO 8601, `Date.prototype.toString()` and `toUTCString()` text; anything else is NaN), `Js::isoString()` writes `toISOString()`.
- No loose comparisons (`==`, `switch`) between values of unknown type: PHP 7.4 compares `0 == "Overlay"` as true.

## Stack Rules

- **Browser:** plain scripts in FrameTrail's style. No ES modules, no transpiler, no runtime dependencies; closures that return their public interface; plain DOM APIs; 4 spaces.
- **Server:** PHP 7.4 or later, no Composer, no autoloader. Nothing newer than 7.4 (`match`, `str_contains` / `str_starts_with`, named arguments, `?->`, union types, constructor promotion). FrameTrail's style: `array()`, doc comments with `@method` and `@param {Type}`.
- **Node** only for tests and development; the build is bash and zip.
- **Shapes and defaults come from FrameTrail's schemas,** not from code here. Reading and writing stored JSON goes through the serializer (`FrameTrailSerializer` in the browser, its PHP port on the server); never build stored JSON by hand.

## Client

- `namespace.js` creates the add-on's only global, `window.FrameTrailConversationalUI`. Every other file either fills it or wraps its code in an IIFE: the build concatenates all files into one script, so anything else at top level would become a global too.
- Parts that know no FrameTrail instance (operations, lint, model adapters) hang on the namespace. Per-instance state is created in the factory in `module.js`: several FrameTrail instances can share a page.
- `module.js` registers `conversational-ui`. The factory gets FrameTrail's internal instance (`module()`, `getState()`, `changeState()`, `edit`). The chat panel is a `sidePanel` with `when: 'edit'`.
- Load order is `JS_FILES` / `CSS_FILES` in `scripts/build.sh`. A new file goes there; the tests fail for a file that is not listed. Data from `shared/` that the client needs is listed in `SHARED_DATA` there and written into the bundle right after `namespace.js` as a property of the namespace (declared there as `null`).
- Code that needs FrameTrail's pure globals (`FrameTrailSerializer`, `FrameTrailKeyframes`, `FrameTrailSchema`, `FrameTrailSchemas`) reads them from `window` when it runs, not when it loads.
- FrameTrail clears every timer on the page when it switches hypervideos: restart timers in `onHypervideoChange`.

## Styling

- FrameTrail's CSS custom properties (`--primary-bg-color`, `--primary-fg-color`, `--secondary-bg-color`, `--highlight-color`, `--editing-bg-color` / `--editing-fg-color`, `--input-border-radius`, …), so the built-in and custom themes apply. The side panel takes the theme in view mode and the editor's palette while editing, through the same variables.
- FrameTrail's `generic.css` classes for buttons, inputs, `custom-select` (wrapping each `<select>`), `message` and `layoutRow` / `column-*` (every direct child of a `layoutRow` needs a column class).
- Own CSS only for the panel shell, in `client/ui/style.css`, every selector scoped under `.sidePanelItem[data-extension="conversational-ui"]`.

## Localization

- `client/locale/en.js`, `de.js`, `fr.js`, always updated together. Keys start with `ConversationalUi` and are in alphabetical order; the tests check that, that all three have the same keys, and that every label the client reads exists.
- `module.js` merges them into FrameTrail's tables with `Localization.addLabels()`; code reads them through `Localization.labels`, which falls back to English per key.

## Server

- `server/extension.php` is the manifest FrameTrail reads: `{ actions, routes, requires }`. FrameTrail loads it for every action it does not know and on every admin's heartbeat, so it only declares and returns; handlers `require_once` their implementation (`__DIR__ . "/…"`) when called.
- Every PHP file starts with the guard `if (!function_exists("ftExtensionStorage")) { http_response_code(404); exit; }`: PHP's built-in server reads no `.htaccess` and would run it on its own. The files of `lib/` also run outside FrameTrail's routers (the command-line tool, the tests), which define `FT_CONVERSATIONAL_UI_LIB` first: their guard is `if (!function_exists("ftExtensionStorage") && !defined("FT_CONVERSATIONAL_UI_LIB"))`.
- Every function is prefixed `ftConversationalUi` (or is a closure), every class and interface `FtConversationalUi`: two extensions declaring the same name stop every request that loads both. The library (`lib/`) declares classes only; handlers `require_once __DIR__ . "/lib/load.php"` when they need it. The tests check.
- Actions answer like FrameTrail's: `{ status, code, string?, response }`. Routes write their own answer (MCP's JSON-RPC, the relay's server-sent events); a streaming route calls `session_write_close()` once it knows who is asking. From the browser: `StorageManager.serverPost(new URLSearchParams({ a: 'conversationalUi…' }))`, `StorageManager.extensionURL('conversational-ui', route)`.
- Handlers check access themselves (`requireLogin()`). A request with a personal API token has the token owner as its session user; `ftIsBearerRequest()` tells.
- `requires` lists only what every part needs (`json`): a missing requirement takes away all of the extension's actions and routes. A feature that needs more (curl for the relay and transcription) checks when it is called and says what is missing.
- State in `ftExtensionStorage("conversational-ui")`, never served or exported but copied with `_data/`, so never secrets. Secrets in `_data/.auth/conversational-ui.php` (`ftExtensionSecrets()`). The config entry's `settings` reach both parts and are public on public instances.
- JSON: decode so that `{}` stays an object (`json_decode($json, true)` turns it into `[]`); encode with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. Writes to `_data/` go through FrameTrail's own functions, or its `sharedFile` (in-place `flock`) for the CLI, with the compare-and-swap tokens (`meta.lastchanged`, the annotation file's `baseVersion`), so open editors notice them.

## Version

Sources carry `__CONVERSATIONAL_UI_VERSION__` (`client/namespace.js`, `server/extension.php`). The build writes the release label in (`dev` by default); PHP that did not go through the build reports `dev`.

## Build

```bash
bash scripts/build.sh            # version "dev"
bash scripts/build.sh v0.1.0     # a release label: letters, digits, '.', '_', '-'
```

Writes `build/client/` (the concatenated, unminified `.js` and `.css` — the JS with the shared data of `SHARED_DATA` — and `LICENSE`), `build/server/` (a copy of `server/`, the files of `SHARED_DATA` in `shared/`, which `lib/shared.php` reads, and `LICENSE`) and `build/frametrail-conversational-ui-<version>.zip`, which holds them as `extensions/conversational-ui/` and `_server/extensions/conversational-ui/`, the places they take in the FrameTrail code tree (for platforms that compose a FrameTrail release and an add-on release into one template).

## Tests

```bash
node tests/run-js.mjs            # Node 20+, no dependencies (node:test)
php tests/run-php.php            # PHP 7.4+, no dependencies (prints TAP)
node tests/run-js.mjs --build    # the same against build/
php tests/run-php.php --build
```

- Both runners need a FrameTrail working copy: `FRAMETRAIL_DIR`, or `--frametrail=<dir>`, or the sibling `../frametrail`. CI checks out `OpenHypervideo/FrameTrail` at `v1.4.1` (`FRAMETRAIL_REF` in both workflows), the oldest release whose pure scripts, schemas and fixtures are as the add-on uses them.
- `run-js.mjs` runs the client files in a `vm` context, in build order (with the shared data embedded as the build does), against a stand-in for FrameTrail: the build lists, the labels, the registration and the slots. For the operations and lint it first runs FrameTrail's pure scripts in the same context (`FrameTrailKeyframes`, `FrameTrailSerializer`, `FrameTrailSchema`, `FrameTrailSchemas`), as FrameTrail loads them; the live store's tests use a stand-in for the edit API. Then: the manifests (meta-schema, schemas in the subset, an implementation per operation and per lint rule, preconditions that fit the inputs), every conformance fixture against the model store, every read and lint over FrameTrail's own `tests/fixtures/data/`, the helpers, and the live store against a stand-in edit API.
- `run-php.php` checks the syntax of every PHP file, the guard, the manifest against the rules of FrameTrail's extension loader (names, callable handlers, requirements, prefixed functions) and the actions' answers; that the library declares only classes, prefixed `FtConversationalUi`; that `schemas.json` is the working copy's; the ports against FrameTrail's `tests/fixtures/` under the rules of its `tests/README.md` (each file against its schema, the cases' exact errors, round trips including key order, folder bundles); then the same as the JavaScript runner: an implementation per operation and per rule, every conformance fixture against the PHP model store, every read and lint over FrameTrail's data, the helpers.
- The conformance fixtures in `shared/fixtures/` (rules in its README) run in both runners. `--list-fixtures` makes each runner print the cases it runs, one per line; CI compares the two lists, so a case only one runner reads fails, and each runner fails on a folder in `shared/fixtures/` it does not know.
- To write a fixture case, give the input, then let `plans/a1-validation/fill-fixtures.mjs` (operations) or `plans/a2-validation/fill-lint.mjs` (lint; both git-ignored) fill in what the JavaScript does, and check every filled value by hand before keeping it: the expectations must say what is right. `plans/a2-validation/` also has the parity checks of the PHP side against the JavaScript beyond the fixtures (validator and serializer over every data set and random mutations; random operations, their errors, bundles, undo and lint).

CI (`.github/workflows/build.yml`): the JS tests on Node 20, the PHP tests on 7.4 and 8.4, the comparison of the fixture lists, and the build with both test runs against it. `release.yml` builds `v*` tags and attaches the zip to a GitHub release.

## Development Setup

Build, then try it in a FrameTrail working copy: extract the zip into a FrameTrail build, or in FrameTrail's `src/` point the `config.json` entry at `build/client/` with a relative path on the same origin and symlink `build/server` to `src/_server/extensions/conversational-ui` (FrameTrail git-ignores what is installed there). Rebuild after every change. A throwaway data folder (`src/_data-*`, git-ignored in FrameTrail) keeps tests away from real data.

The tests need a FrameTrail working copy too (see Tests); a clone next to this repository, named `frametrail`, is found without setting anything. When the add-on moves to a newer FrameTrail release: `php scripts/vendor-schemas.php` for the schemas, the changes of FrameTrail's pure scripts into `server/lib/frametrail/`, `FRAMETRAIL_REF` in both workflows.
