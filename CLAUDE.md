# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

FrameTrail-Conversational-UI is an add-on for [FrameTrail](https://github.com/OpenHypervideo/FrameTrail) that lets people edit a hypervideo in natural language, in a chat panel docked beside the player in the editor. The panel talks to Mistral's API through a relay on the server (server mode) or directly from the browser (every other storage mode).

What the panel's model can do is defined once, declaratively (`shared/operations.json`), and carried out in the browser by the operations in `client/ops/`, against the open editor through FrameTrail's edit API. Lint rules (`shared/lint.json`, `client/lint/`) check a hypervideo after changes. Conformance fixtures (`shared/fixtures/`) say what both must do. The conversation (`client/agent/`) sends the user's messages, the system prompt (`shared/prompts/`) and the operations as tools to Mistral (`client/models/`) and carries out the tool calls; the panel (`client/ui/`) shows it.

The server part (PHP) is small: a status action (which also tells the panel how it may reach Mistral) and the model relay; transcription later. There is no server-side surface for agents outside the editor (an MCP endpoint, a command-line tool): it was planned and left out of v1, so the operations exist in JavaScript only.

Work proceeds in phases; A0 (scaffold), A1 (operations, changesets, the interpreter), A2 (lint), A7 (the chat panel) and A8 (the relay) are done, A3–A6 (the server side for external agents) were dropped, transcription (A9) follows. The phase plan is kept outside this repository.

## Relationship to FrameTrail

The add-on lives entirely outside FrameTrail and uses only its generic, documented building blocks:

- **Browser:** `FrameTrail.registerExtension()` and its slots (`sidePanel`, `titlebarAction`, `editPanel`) and hooks, `edit` (the edit API: stored-format items, JSON Merge Patch updates, `transaction()` as one undo step, the busy editor and its Stop, and the reads around the data: `getInfo()`, `getUser()`, `permission(kind)`, `listHypervideos()`), `Localization.addLabels()`, `StorageManager.serverPost()` / `extensionURL()`, the states `storageMode`, `viewMode`, `editMode`, `editBusy`, `UndoManager`'s `getUndoDescription()`, `getRedoDescription()`, `undo()`, `redo()` and its `undoStateChanged` event (the panel's "Undo this turn"), and the pure globals in FrameTrail's bundle: `FrameTrailSerializer`, `FrameTrailKeyframes`, `FrameTrailSchema` + `FrameTrailSchemas`.
- **Server:** the server extension manifest, `requireLogin()` / `userCheckLogin()` (the user record, with its `external` block under external authentication), `ftIsBearerRequest()`, `ftExternalAuthEnabled()`, `ftExtensionStorage()`, `ftExtensionSecrets()`.
- **Data:** the JSON Schemas in FrameTrail's `schemas/`, `docs/DATA-MODEL.md`, and the data sets in `tests/fixtures/data/` (the tests read and lint them).

FrameTrail's own docs for these: `docs/EXTENDING.md` ("Writing an Extension", "Editing the Hypervideo", "Server Extensions"), `docs/DATA-MODEL.md`, `docs/DEPLOYMENT.md`.

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
| Routes | `_server/extension.php?e=conversational-ui&r=<route>` (`relay`) |
| CSS | root `.conversationalUi`, scoped under `.sidePanelItem[data-extension="conversational-ui"]` |
| Private storage | `_data/.extensions/conversational-ui/` (`usage.json`: the relay's count of the day's requests; transcription jobs) |
| Secrets | `_data/.auth/conversational-ui.php` |
| Bundle | `frametrail-conversational-ui.js` / `.css`, `frametrail-conversational-ui-<version>.zip` |
| `generator` on items it writes | `{ "type": "Software", "name": "FrameTrail-Conversational-UI", "model": …, "provider": "mistral" }` |

## Repository Layout

What exists, and the phase that fills the rest:

```
client/                     → build/client/frametrail-conversational-ui.js + .css
├── namespace.js            the global FrameTrailConversationalUI (first in the build)
├── locale/                 en.js, de.js, fr.js
├── ops/                    the operations: util.js (JSON, merge patches, schema references, errors,
│                           Media Fragments, plain text, WebVTT), items.js (short forms; items and
│                           patches from input), model-store.js, live-store.js, interpreter.js
├── lint/                   lint.js: the rules of shared/lint.json
├── models/                 chat.js (Mistral's Chat Completions: streaming, tool calls, errors),
│                           mistral.js (direct adapter), relay.js (through the server, A8)
├── agent/                  tools.js (the operations as tools), agent.js (the conversation)
├── ui/                     markdown.js, settings.js, panel.js (the chat panel), style.css
├── media/                  (A9) transcription client
└── module.js               the extension entry (last in the build)
server/                     → build/server/ = _server/extensions/conversational-ui/
├── extension.php           the manifest: actions, routes, requires; the configuration and the status
├── relay.php               the model relay: action conversationalUiChat, route relay
└── transcribe.php          (A9)
shared/
├── operations.json         the operations manifest (embedded in the bundle by the build)
├── operations.schema.json  its meta-schema
├── changeset.schema.json   the changeset format (embedded too)
├── lint.json               the lint rules and the shape of their result (embedded too)
├── fixtures/               conformance fixtures: README.md (the rules), data/, ops/, lint/
├── prompts/                the system prompt: system.md, conversation.md, types.md (embedded too)
└── eval/                   tasks.json: the tool-calling evaluation's requests and checks
scripts/                    build.sh (concatenation build), eval-models.mjs (the evaluation)
tests/                      run-js.mjs (client, operations, lint), run-php.php (server part),
                            relay/ (stand-ins for Mistral's API and FrameTrail's routers, for run-php.php)
```

## Decisions

- Edits are applied directly. One chat turn is one `edit.transaction()`, so one undo step; Stop (the panel's or FrameTrail's) takes the turn's changes back.
- The requesting user is the creator of what the add-on writes; the W3C `generator` records the add-on and the model.
- Model access follows the storage mode: server mode → the PHP relay (or the user's own key, where the server allows it and the user chooses it); local folder, project file, static and in-memory → directly from the browser.
- Model provider: Mistral only, for chat. Its API is hosted in the EU by default and accepts requests from every origin (also `file://` pages), so direct mode needs no setup. Its free plan is for trying it; on it Mistral trains on inputs and outputs unless the account opts out, and the settings say so. No other providers or local chat models in v1.
- No surface for agents outside the editor in v1 (no MCP endpoint, command-line tool or skills): the operations run in the browser only. A later surface would reuse `client/ops/` rather than a second implementation.
- The panel speaks Mistral's Chat Completions API, directly or through the relay; under LinkedVideo the gateway offers the same API.
- Transcription (the first media capability) is server mode only, on a self-hosted Whisper server (OpenAI-compatible `/audio/transcriptions`), never on Mistral.
- Conversations are not stored in `_data` (v1).
- Licence MIT.

## Operations

What an agent can do is defined once, in `shared/operations.json`, and carried out by the interpreter in `client/ops/`. The fixtures in `shared/fixtures/` say what each operation gives and changes.

- **The manifest:** each operation has a `name` (the tool name), a `description` written for models, `kind` (what it touches), `effect` (`read` / `write`), `scope` (`instance` or `hypervideo`: a surface that serves several hypervideos would add a hypervideo id), `preconditions` (checked in order after the input: `canEditHypervideo`, `canAnnotate`, `itemExists`, `chapterStartFree`, `subtitles`), and `input` / `output` JSON Schemas in FrameTrail's schema subset. References into FrameTrail's schemas are absolute (`https://frametrail.org/schemas/1/…`), shared parts of the manifest are its `$defs` (`#/$defs/item`, `box`, `person`). `operations.schema.json` checks the manifest; the validator gets the input and output schemas as one document (`ops.OPERATIONS_ID`, `#/$defs/<operation>-input`).
- **Inputs** are friendly where the stored form is not: time as `start` / `end` seconds, the box as `{ left, top, width, height }` percent, rotation and keyframes as numbers; the body (and events) in FrameTrail's stored form, a JSON Merge Patch when updating. Overlays, annotations and code snippets are referred to by `created` (`ref`, the user's own annotations, or `{ creator, created }` for reading others'), chapters by `start`. Results of writes are the item's short form (`$defs/item`).
- **Stores** have the edit API's interface (`getHypervideo`, `list`, `get`, `getInfo`, `getUser`, `permission`, `listHypervideos`, `add`, `update`, `remove`, `setLayout`, `setSubtitles`, `transaction`) plus `versions()` (the compare-and-swap tokens of the files: `hypervideo`, and `annotations` where the store knows it) and, where the store knows them, `resources()` (the resources by id, or `null`; for the lint rules). `ops.liveStore(FrameTrail)` is the editor: it passes everything to `edit` and reads nothing else of FrameTrail (no `resources()`); `ops.modelStore(bundle, { user: { id, name, role }, now, duration, hypervideoId })` does the same headless to a hypervideo or project bundle, through FrameTrail's serializer and validator, and gives the bundle back as a save would write it (`data()`); it is the reference the fixtures run against.
- **Changesets** (`shared/changeset.schema.json`): `{ id, hypervideoId, baseVersion, createdBy, generator, summary, ops: [{ op, input, inverse }] }`. `ops.apply(store, changeset, { generator })` runs all its operations in one transaction (in the editor: one undo step), or none; a given `baseVersion` that no longer holds is refused (`conflict`). Inverses are store calls (`{ method, args }`), recorded from the state before each operation: an add's is a remove, a remove's an add of the item as it was (its `created` kept), an update's the merge patch back. `ops.undo(store, changeset)` replays them, last first.
- **A conversation turn** (`client/agent/agent.js`) opens the transaction itself, `store.transaction(summary, async (tx, signal) => …)`, and runs operations one by one with `ops.record(tx, { summary, generator })`: an operation that fails changes nothing and the turn goes on; the turn is one undo step, and Stop (FrameTrail's, or `signal`) takes it back. `store.permission(kind)` tells which write tools to offer (`agent.tools()`).
- **Preconditions:** `withinVideo` refuses what the player never reaches (lint's `item-outside-video`): a start at or after the video's end, a span wholly before its start, a chapter before it. Partly outside goes through (lint warns).
- **describe_type** tells a model a type's attributes from FrameTrail's schemas (`FrameTrailSchemas`, references inlined, legacy keys left out) and where its src goes (asked of FrameTrail's serializer, not a copy of its table). Its results are FrameTrail's data, so the fixtures hold only its refusals; `tests/run-js.mjs` checks the rest against the schemas.
- **Errors** are op errors (`ops.util.opError`): `code` `invalid` (with `errors`, JSON Pointers into the input; in a changeset prefixed `/ops/<i>/input`), `notFound`, `notAllowed`, `conflict`, or `stopped` from the editor.
- **generator:** every overlay and annotation an operation adds or changes gets the changeset's `generator`, replacing another tool's; an update that changes nothing writes nothing.

Adding an operation: its entry in `operations.json` (keep the subset; `node tests/run-js.mjs` checks), its implementation (`IMPLEMENTATIONS` in `client/ops/interpreter.js`; the tests fail for an operation without one), any helper rule it follows in `shared/fixtures/README.md`, and fixture cases (the tests fail for an operation no case uses).

## The Conversation

`client/agent/agent.js`: `agent.conversation({ store, adapter, model, … })` → `send(text, handlers)`, `resume()`, `stop()`, `note(text)`, `configure()`. It knows no DOM; Node runs it too (`scripts/eval-models.mjs`, the tests).

- **A turn** is the user's message and everything the model does about it, in rounds (at most 20): a request, then the tool calls it asks for, in order, each answered with its result or its op error as JSON (cut at 20,000 characters, saying so). Reads go through the store; the first write opens the turn's transaction (`store.transaction(description, …)`, so a question never makes the editor busy), and the writes go through `ops.record()` in it. The turn ends when the model answers without tools; then its transaction ends: kept (one undo step) unless stopped.
- **Context:** the system prompt is the fragments of `shared/prompts/` (`system`, `conversation`, `types`); a user message starts with `[Hypervideo: <inspect_hypervideo>]` only when that summary changed since the model last saw it, and with notes (`[The user stopped your previous turn; …]`, an undo). The prefix stays the same from request to request, and `prompt_cache_key` is the conversation's id. Earlier turns' tool results are dropped when the conversation passes 200,000 characters.
- **Invalid input:** reported to the model; after three invalid calls in a row the next request goes with `tool_choice: 'none'`, so it answers in words.
- **Lint:** after a turn with changes, findings about what it touched (its refs, a related item, subtitles, chapters) go back to the model once, as a user message `[Automatic check of your changes]`, in the same transaction; what remains is `turn.lint`.
- **Rate limits** (429, and 502–504) are waited out: `Retry-After` when the answer is readable (Mistral's CORS answer exposes no headers, so in browsers the schedule 2, 4, 8, 16, 30 s applies), at most 60 s and 6 attempts; then the turn ends `limited`, keeping its changes, and `resume()` sends the conversation again without a new message. Other failures end it `failed` (also resumable when the last message is the user's or a tool's).
- **Stop:** `stop()` or FrameTrail's Stop (the transaction's signal) aborts the request and takes the turn's changes back; open tool calls are answered, and the next message tells the model.
- **generator:** `{ type: 'Software', name: 'FrameTrail-Conversational-UI', model, provider: 'mistral' }` on what the turn writes.

## Model Access

`client/models/`: one Chat Completions client and two adapters, both speaking Mistral's API.

- `models.chat(adapter, body, { signal, onText })` streams (server-sent events, text and tool calls collected across any chunking: by index, a new id under a taken index is a new call; content lists' text kept, thinking left out) and falls back to one request without streaming when nothing arrives within 30 s, after which the adapter goes without (`adapter.streaming = false`). Errors are chat errors with a `code`: `key` (401/403), `model` (400/404/422 naming the model), `rateLimit` (429, `retryAfter`), `request`, `service` (5xx), `network`, `stopped`, or the relay's `login`, `quota`, `notConfigured`, `notAllowed`.
- `models.mistral({ key })`: `https://api.mistral.ai/v1` from the browser (Mistral's CORS allows `Authorization` and `Content-Type` from every origin); `listModels()` (chat models with tools, aliases folded in), `test(model)`.
- `models.relay({ url, post, models })`: streaming through the route `relay`, without streaming through the action `conversationalUiChat` (the contract is in `relay.js`; the server side under "Server"). The action's failures carry Mistral's error body as `upstream`, read like a direct answer; the relay's own refusals carry `error.code`.
- **Which one:** server mode asks `conversationalUiStatus` → `capabilities`: `relay` → the relay (its `models`, `defaultModel`, `requestsPerDay`), and with `ownKey` as well the user chooses in the settings (`frametrail-conversational-ui-connection` in localStorage, the relay unless it says `direct`); `ownKey` alone (`allowOwnKey` true in `_data/.auth/conversational-ui.php`) → direct; else none. The relay needs a signed-in user who is not a guest (`edit.getUser()`), otherwise the panel says to sign in. Local folder, project file, static and in-memory modes go direct.
- **Models:** on Mistral's free plan some models allow no requests at all (a per-minute limit of 0: every request is a 429, which a browser cannot tell from a passing limit). The default (`ui.DEFAULT_MODEL`) is chosen by the evaluation among the models a free key may use.

## The Panel

`client/ui/panel.js` (one per FrameTrail instance, made in the side panel's `create`), `settings.js`, `markdown.js` (the model's text as DOM, never parsed as HTML).

- Conversations are kept in memory per hypervideo (switching back brings one back), never saved. A new conversation from the bar.
- **Undo this turn:** each turn's undo description is `Assistant: <request>`, made unique among the panel's turns; the button undoes through `UndoManager` while that description is the latest step, becomes "Redo this turn" when it is the next redo, and otherwise says why not. The model gets a note.
- **Settings:** the connection (the relay or one's own key, where the server offers both), the relay's daily limit, the key (in memory; in `localStorage` only with "Remember the key on this device", with its warning), the model (from the key's models, or the relay's), a connection test, a link to Mistral's console and the note on training under the free plan. Administrators (server mode, `edit.getUser().role === 'admin'`) also see what the server has set up, from the status action's `capabilities`: the relay with its models and limit, or how to switch it on, or what keeps it from working (`problem`: `curl`, `baseUrl`), and whether own keys are allowed. The relay is configured only in its file; there is no settings form that writes it. A platform that manages the instance's settings names itself in the extension's `settings` (`label`, `manageUrl`); the model is then not the user's to choose.
- Keyboard: Enter sends, Shift+Enter a new line, Esc stops; buttons are buttons, the log is `role="log"`.

## Prompts and the Evaluation

- `shared/prompts/*.md` are Markdown without tabs or control characters, embedded by the build as strings (`SHARED_TEXT` in `build.sh`; the tests embed them the same way). Written for the model: what a hypervideo holds, how to work with the tools, "talk before you build", the types.
- `scripts/eval-models.mjs` sends each request of `shared/eval/tasks.json` as a new conversation about a model store over a fixture, through the direct adapter, and checks the result (declarative checks, described in the file). It needs a key (`--key-file`, `MISTRAL_API_KEY`) and the build, and is not run in CI. Run it when the prompts, the tools or Mistral's models change; the default model follows from it.

## Lint

Checks of a hypervideo that its schemas cannot express, run after changes (the chat panel after a turn): `shared/lint.json` lists the rules (id, severity, description) and the shape of a result; `client/lint/lint.js` (`FrameTrailConversationalUI.lint.run(store, { rules })`) implements them, and `shared/fixtures/lint/` holds it to the findings and messages.

- **Rules:** `item-outside-video` (error), `item-partly-outside`, `overlay-overlap`, `unknown-resource`, `empty-required` (error), `missing-license`, `chapter-order`, `cue-outside-video` (warnings); `lint.json` says what each checks. Rules that need the video's end skip that part while it is unknown; `unknown-resource` needs `store.resources()`, which the live store does not have.
- **Result:** `{ errors, warnings, findings: [{ rule, severity, kind, ref, creator?, related?, message }] }`, findings by rule in the order of `lint.json`, within a rule as the items are listed. `kind` is a kind of item, `subtitles` or `hypervideo`; `ref` is what `get_item` takes (an annotation's `creator` beside it). Messages are written for a person or a model, numbers as JavaScript writes them.
- **Adding a rule:** its entry in `lint.json`, its implementation in `RULES` of `client/lint/lint.js` (the tests fail for a rule without one), cases in `shared/fixtures/lint/` (the tests fail for a rule no case finds).

## Stack Rules

- **Browser:** plain scripts in FrameTrail's style. No ES modules, no transpiler, no runtime dependencies; closures that return their public interface; plain DOM APIs; 4 spaces.
- **Server:** PHP 7.4 or later, no Composer, no autoloader. Nothing newer than 7.4 (`match`, `str_contains` / `str_starts_with`, named arguments, `?->`, union types, constructor promotion). FrameTrail's style: `array()`, doc comments with `@method` and `@param {Type}`.
- **Node** only for tests and development; the build is bash and zip.
- **Shapes and defaults come from FrameTrail's schemas,** not from code here. Reading and writing stored JSON goes through FrameTrail's serializer (`FrameTrailSerializer`) or its edit API; never build stored JSON by hand.

## Client

- `namespace.js` creates the add-on's only global, `window.FrameTrailConversationalUI`. Every other file either fills it or wraps its code in an IIFE: the build concatenates all files into one script, so anything else at top level would become a global too.
- Parts that know no FrameTrail instance (operations, lint, model adapters) hang on the namespace. Per-instance state is created in the factory in `module.js`: several FrameTrail instances can share a page.
- `module.js` registers `conversational-ui`. The factory gets FrameTrail's internal instance (`module()`, `getState()`, `changeState()`, `edit`). The chat panel is a `sidePanel` with `when: 'edit'`.
- Load order is `JS_FILES` / `CSS_FILES` in `scripts/build.sh`. A new file goes there; the tests fail for a file that is not listed. Data from `shared/` that the client needs is listed in `SHARED_DATA` there and written into the bundle right after `namespace.js` as a property of the namespace (declared there as `null`); the prompts likewise in `SHARED_TEXT`, as `prompts.<name>` strings.
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
- Every PHP file starts with the guard `if (!function_exists("ftExtensionStorage")) { http_response_code(404); exit; }`: PHP's built-in server reads no `.htaccess` and would run it on its own.
- Every function is prefixed `ftConversationalUi` (or is a closure), every class and interface `FtConversationalUi`: two extensions declaring the same name stop every request that loads both. The tests check the functions.
- Actions answer like FrameTrail's: `{ status, code, string?, response }`. Routes write their own answer (the relay's server-sent events); a streaming route calls `session_write_close()` once it knows who is asking. From the browser: `StorageManager.serverPost(new URLSearchParams({ a: 'conversationalUi…' }))`, `StorageManager.extensionURL('conversational-ui', route)`.
- Handlers check access themselves (`requireLogin()`). A request with a personal API token has the token owner as its session user; `ftIsBearerRequest()` tells.
- `requires` lists only what every part needs (`json`): a missing requirement takes away all of the extension's actions and routes. A feature that needs more (curl for the relay and transcription) checks when it is called and says what is missing.
- State in `ftExtensionStorage("conversational-ui")`, never served or exported but copied with `_data/`, so never secrets. Secrets in `_data/.auth/conversational-ui.php` (`ftExtensionSecrets()`). The config entry's `settings` reach both parts and are public on public instances.
- JSON: decode so that `{}` stays an object (`json_decode($json, true)` turns it into `[]`); encode with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.

### The Relay

`server/relay.php`, loaded by the manifest's handlers when they are called. The configuration is `ftConversationalUiConfig()` in `extension.php` (every key of `_data/.auth/conversational-ui.php` checked, defaults filled in; the README lists them), which the status action reads too: `capabilities` = `{ relay, ownKey }`, with `models`, `defaultModel`, `requestsPerDay` when the relay is on, `problem` (`curl`, `baseUrl`) when a key is there but the relay cannot work.

- **Pass-through:** the request's bytes go to `{baseUrl}/chat/completions` unchanged and the answer comes back as it is; the relay decodes the request only to check it. Nothing is translated, no tool runs on the server.
- **Admission** (`ftConversationalUiRelayAdmit()`), in this order: configured (key, valid `baseUrl`, curl: `notConfigured` 503), signed in (`login` 401), active and not a personal API token (`notAllowed` 403), the request (≤ 2 MB: 413; a JSON object, `stream` as the endpoint has it, an allowed model: 400, the model named), the day's count (`quota` 429 with `Retry-After` until midnight). Refusals are `{ error: { code?, message } }`; only the relay's own carry a code. Then `session_write_close()`.
- **The count** (`ftConversationalUiRelayCount()`): model requests (not turns) per user id and calendar day in server time, in `usage.json` under `flock`, counted before the request goes; refused requests are not counted. Without the private folder a configured limit refuses (`notConfigured`) rather than relaying uncounted.
- **Upstream** (`ftConversationalUiRelayExchange()`): curl, no redirects, http(s) only, no `100-continue`, a total timeout, and while streaming a minute without a byte ends it. Headers: the key, the types, and only for a gateway (any `baseUrl` but Mistral's) `X-FrameTrail-User` (the platform's subject under external authentication, else the user id) and `X-FrameTrail-Instance`. Control characters never reach a header.
- **Upstream failures** (`ftConversationalUiRelayUpstreamFailure()`): status and body passed on with `Retry-After`, the key taken out of the body; no answer → 502 (504 when it timed out); 401/403 → `notConfigured` 502 (the server's key), unless the body is already a refusal in the relay's words (a gateway's); a 429 with an `x-ratelimit-limit-*` of 0 → a model error (400).
- **The route** streams: POST and `Content-Type: application/json` only (405, 415), output buffers closed, compression off, `X-Accel-Buffering: no`, the status and headers sent with the first piece of a successful answer, a flush after every piece; when the browser has gone, the request to Mistral stops at the next piece. An answer that breaks off after it started ends with an SSE `error` event.
- **The action** answers `{ status: 'success', response: <completion> }` (decoded as objects, so `{}` stays `{}`), or `{ status: 'fail', code: <HTTP status>, string, upstream | error, retryAfter? }`.
- The key never reaches an answer: messages are generic, and the tests look for it in every answer they get.

## Version

Sources carry `__CONVERSATIONAL_UI_VERSION__` (`client/namespace.js`, `server/extension.php`). The build writes the release label in (`dev` by default); PHP that did not go through the build reports `dev`.

## Build

```bash
bash scripts/build.sh            # version "dev"
bash scripts/build.sh v0.1.0     # a release label: letters, digits, '.', '_', '-'
```

Writes `build/client/` (the concatenated, unminified `.js` and `.css` — the JS with the shared data of `SHARED_DATA` — and `LICENSE`), `build/server/` (a copy of `server/` and `LICENSE`) and `build/frametrail-conversational-ui-<version>.zip`, which holds them as `extensions/conversational-ui/` and `_server/extensions/conversational-ui/`, the places they take in the FrameTrail code tree (for platforms that compose a FrameTrail release and an add-on release into one template).

## Tests

```bash
node tests/run-js.mjs            # Node 20+, no dependencies (node:test)
php tests/run-php.php            # PHP 7.4+, no dependencies (prints TAP)
node tests/run-js.mjs --build    # the same against build/
php tests/run-php.php --build
```

- `run-js.mjs` needs a FrameTrail working copy: `FRAMETRAIL_DIR`, or `--frametrail=<dir>`, or the sibling `../frametrail`. CI checks out `OpenHypervideo/FrameTrail` at `v1.4.1` (`FRAMETRAIL_REF` in both workflows), the oldest release whose pure scripts and schemas are as the add-on uses them.
- `run-js.mjs` runs the client files in a `vm` context, in build order (with the shared data and prompts embedded as the build does), against a stand-in for FrameTrail (a small DOM, the state, StorageManager, UndoManager, edit over a model store): the build lists, the labels, the registration and the panel (access by storage mode, a turn with its undo). For the operations and lint it first runs FrameTrail's pure scripts in the same context (`FrameTrailKeyframes`, `FrameTrailSerializer`, `FrameTrailSchema`, `FrameTrailSchemas`), as FrameTrail loads them; the live store's tests use a stand-in for the edit API. Then: the manifests (meta-schema, schemas in the subset, an implementation per operation and per lint rule, preconditions that fit the inputs), every conformance fixture against the model store, every read and lint over FrameTrail's own `tests/fixtures/data/`, the helpers, the live store against a stand-in edit API, `describe_type` against FrameTrail's schemas, the tools, the chat client (streams cut anywhere, errors, the fallback), and the conversation against a scripted model (questions, changes, invalid input, rate limits, Stop, lint).
- `run-php.php` checks the syntax of every PHP file, the guard, the manifest against the rules of FrameTrail's extension loader (names, callable handlers, requirements, prefixed functions), the status action, and the relay: its parts one by one (configuration, headers, the count, admission, upstream failures) with stand-ins for FrameTrail's functions, then over HTTP: it starts PHP's built-in server twice, with `tests/relay/upstream.php` (a stand-in for Mistral's API that answers by model name) and `tests/relay/harness.php` (a stand-in for FrameTrail's routers, the scene set by request headers, the relay's host with `output_buffering` on as php.ini-development has it), and checks streaming piece by piece, what reaches Mistral, every failure and refusal, Stop, and that the key appears in no answer. It needs PHP's curl extension and no FrameTrail working copy.
- The conformance fixtures in `shared/fixtures/` (rules in its README) run in `run-js.mjs`, which fails on a folder there it does not know.
- To write a fixture case, give the input, then let `plans/a1-validation/fill-fixtures.mjs` (operations) or `plans/a2-validation/fill-lint.mjs` (lint; both git-ignored) fill in what the JavaScript does, and check every filled value by hand before keeping it: the expectations must say what is right.

CI (`.github/workflows/build.yml`): the JS tests on Node 20, the PHP tests on 7.4 and 8.4, and the build with both test runs against it. `release.yml` builds `v*` tags and attaches the zip to a GitHub release.

## Development Setup

Build, then try it in a FrameTrail working copy: extract the zip into a FrameTrail build, or in FrameTrail's `src/` point the `config.json` entry at `build/client/` with a relative path on the same origin and symlink `build/server` to `src/_server/extensions/conversational-ui` (FrameTrail git-ignores what is installed there). Rebuild after every change. A throwaway data folder (`src/_data-*`, git-ignored in FrameTrail) keeps tests away from real data.

The JavaScript tests need a FrameTrail working copy too (see Tests); a clone next to this repository, named `frametrail`, is found without setting anything. When the add-on moves to a newer FrameTrail release: `FRAMETRAIL_REF` in both workflows.
