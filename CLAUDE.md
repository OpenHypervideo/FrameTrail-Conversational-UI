# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

FrameTrail-Conversational-UI is an add-on for [FrameTrail](https://github.com/OpenHypervideo/FrameTrail) that lets people edit a hypervideo in natural language. It has two surfaces that share one set of operations:

- **External agents:** an MCP endpoint, a command-line tool and skills (server part, PHP).
- **The editor:** a chat panel docked beside the player (browser part, JavaScript), talking to a model through a relay on the server (server mode) or directly from the browser (every other storage mode).

The operations are defined once, declaratively (`shared/operations.json`), and interpreted twice: in JavaScript for the live editor (which must also work without PHP) and in PHP for external agents. Shared conformance fixtures keep the two in step.

Work proceeds in phases A0–A9; A0 (this scaffold) is done. The phase plan is kept outside this repository.

## Relationship to FrameTrail

The add-on lives entirely outside FrameTrail and uses only its generic, documented building blocks:

- **Browser:** `FrameTrail.registerExtension()` and its slots (`sidePanel`, `titlebarAction`, `editPanel`), `edit` (the edit API: stored-format items, JSON Merge Patch updates, `transaction()` as one undo step, the busy editor and its Stop), `Localization.addLabels()`, `StorageManager.serverPost()` / `extensionURL()`, and the pure globals in FrameTrail's bundle: `FrameTrailSerializer`, `FrameTrailKeyframes`, `FrameTrailSchema` + `FrameTrailSchemas`.
- **Server:** the server extension manifest, `requireLogin()` / `userCheckLogin()`, `ftIsBearerRequest()` (personal API tokens), `ftExtensionStorage()`, `ftExtensionSecrets()`, `hypervideoChange()` with `baseVersion` and subtitle texts, `annotationfileSave()` with `baseVersion`, `sharedFile`.
- **Data:** the JSON Schemas in FrameTrail's `schemas/`, `docs/DATA-MODEL.md`, and the fixtures in `tests/fixtures/` with the rules in `tests/README.md`.

FrameTrail's own docs for these: `docs/EXTENDING.md` ("Writing an Extension", "Editing the Hypervideo", "Server Extensions"), `docs/DATA-MODEL.md`, `docs/INTEGRATION.md` ("Personal API Tokens"), `docs/DEPLOYMENT.md`.

When the add-on needs something FrameTrail lacks, the change goes into FrameTrail as a generic feature that stands on its own: nothing in the FrameTrail repository mentions this add-on, AI, models, providers, agents or MCP — not in code, docs, examples, schemas, labels, tests or configuration keys. Never reach into FrameTrail internals beyond the building blocks above.

Requires FrameTrail with extensions: `develop`, released after 1.4.0.

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
| `generator` on items it writes | `{ "type": "Software", "name": "FrameTrail-Conversational-UI", "model", "provider" }` |

## Repository Layout

What exists, and the phase that fills the rest:

```
client/                     → build/client/frametrail-conversational-ui.js + .css
├── namespace.js            the global FrameTrailConversationalUI (first in the build)
├── locale/                 en.js, de.js, fr.js
├── ui/                     style.css (the panel shell); panel, settings, messages (A7)
├── ops/                    (A1) operation interpreter, changesets, model and live store
├── lint/                   (A2) lint rules
├── agent/                  (A7) conversation loop, tool dispatch
├── models/                 (A7) adapters: openai-compatible, anthropic, relay
├── media/                  (A9) transcription client
└── module.js               the extension entry (last in the build)
server/                     → build/server/ = _server/extensions/conversational-ui/
├── extension.php           the manifest: actions, routes, requires
├── lib/                    (A2, A3) ports of serializer, validator, keyframes; ops interpreter, lint, history
├── mcp.php                 (A5) route mcp
├── relay.php               (A8) action conversationalUiChat, route relay
├── transcribe.php          (A9)
└── cli/ft.php              (A4)
shared/                     (A1) operations.json, prompts/, fixtures/
skills/                     (A6) SKILL.md folders for external agents
scripts/build.sh            concatenation build
tests/                      run-js.mjs, run-php.php
```

## Decisions

- Edits are applied directly. In the editor one chat turn is one `edit.transaction()`, so one undo step; MCP and CLI edits are undoable through a server-side history.
- The requesting user is the creator of what the add-on writes; the W3C `generator` records the add-on and the model.
- Model access follows the storage mode: server mode → the PHP relay; local folder, project file, static and in-memory → directly from the browser.
- Protocols: OpenAI-compatible Chat Completions and Anthropic Messages.
- Transcription (the first media capability) is server mode only.
- Conversations are not stored in `_data` (v1).
- Licence MIT.

## Stack Rules

- **Browser:** plain scripts in FrameTrail's style. No ES modules, no transpiler, no runtime dependencies; closures that return their public interface; plain DOM APIs; 4 spaces.
- **Server:** PHP 7.4 or later, no Composer, no autoloader. Nothing newer than 7.4 (`match`, `str_contains` / `str_starts_with`, named arguments, `?->`, union types, constructor promotion). FrameTrail's style: `array()`, doc comments with `@method` and `@param {Type}`.
- **Node** only for tests and development; the build is bash and zip.
- **Shapes and defaults come from FrameTrail's schemas,** not from code here. Reading and writing stored JSON goes through the serializer (`FrameTrailSerializer` in the browser, its PHP port on the server); never build stored JSON by hand.

## Client

- `namespace.js` creates the add-on's only global, `window.FrameTrailConversationalUI`. Every other file either fills it or wraps its code in an IIFE: the build concatenates all files into one script, so anything else at top level would become a global too.
- Parts that know no FrameTrail instance (operations, lint, model adapters) hang on the namespace. Per-instance state is created in the factory in `module.js`: several FrameTrail instances can share a page.
- `module.js` registers `conversational-ui`. The factory gets FrameTrail's internal instance (`module()`, `getState()`, `changeState()`, `edit`). The chat panel is a `sidePanel` with `when: 'edit'`.
- Load order is `JS_FILES` / `CSS_FILES` in `scripts/build.sh`. A new file goes there; the tests fail for a file that is not listed.
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
- Every PHP file starts with the guard `if (!function_exists("ftExtensionStorage")) { http_response_code(404); exit; }`: PHP's built-in server reads no `.htaccess` and would run it on its own.
- Every function is prefixed `ftConversationalUi` (or is a closure): two extensions declaring the same function name stop every request that loads both. The tests check.
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

Writes `build/client/` (the concatenated, unminified `.js` and `.css`, and `LICENSE`), `build/server/` (a copy of `server/` and `LICENSE`) and `build/frametrail-conversational-ui-<version>.zip`, which holds them as `extensions/conversational-ui/` and `_server/extensions/conversational-ui/`, the places they take in the FrameTrail code tree (for platforms that compose a FrameTrail release and an add-on release into one template).

## Tests

```bash
node tests/run-js.mjs            # Node 20+, no dependencies (node:test)
php tests/run-php.php            # PHP 7.4+, no dependencies (prints TAP)
node tests/run-js.mjs --build    # the same against build/
php tests/run-php.php --build
```

- `run-js.mjs` runs the client files in a `vm` context, in build order, against a stand-in for FrameTrail: the build lists, the labels, the registration and the slots.
- `run-php.php` checks the syntax of every PHP file, the guard, the manifest against the rules of FrameTrail's extension loader (names, callable handlers, requirements, prefixed functions) and the actions' answers.
- From A1 on, conformance fixtures in `shared/fixtures/` run in both runners, and a fixture that only one runner reads fails; the PHP ports also pass FrameTrail's `tests/fixtures/` under the rules of its `tests/README.md`.

CI (`.github/workflows/build.yml`): the JS tests on Node 20, the PHP tests on 7.4 and 8.4, and the build with both test runs against it. `release.yml` builds `v*` tags and attaches the zip to a GitHub release.

## Development Setup

Build, then try it in a FrameTrail working copy: extract the zip into a FrameTrail build, or in FrameTrail's `src/` point the `config.json` entry at `build/client/` with a relative path on the same origin and symlink `build/server` to `src/_server/extensions/conversational-ui` (FrameTrail git-ignores what is installed there). Rebuild after every change. A throwaway data folder (`src/_data-*`, git-ignored in FrameTrail) keeps tests away from real data.
