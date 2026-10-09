# Conformance Fixtures

These fixtures say what the operations of [`operations.json`](../operations.json) (`client/ops/`) must do. They are plain JSON; the rules below are what a runner applies, and they are the specification of the behaviour that any other implementation would have to follow too. `tests/run-js.mjs` is the runner:

```bash
node tests/run-js.mjs                     # needs a FrameTrail working copy, see the runner
```

It runs every case, and fails on a folder here it does not know.

The lint rules that check a turn's changes are FrameTrail's (`FrameTrailLint`): their cases are in FrameTrail's `tests/fixtures/lint/`, their rules in its `tests/README.md` ("Lint rules"). `tests/run-js.mjs` runs those cases on a model store, through `client/lint/`.

## Files

| Path | What |
|------|------|
| `data/lecture.json` | A hypervideo bundle in the current form: text, hotspot, rotated image and moving overlays, a code snippet, three chapters, annotations of two users (one `created` shared between them), English subtitles, two content views. Its video's duration comes from the media (`clips[0].duration` is 0), so cases give it. |
| `data/project.json` | A project bundle with two hypervideos: an empty timeline of another user (90 s) and a trimmed video (in 12, out 132), hidden, with German subtitles. |
| `data/legacy.json` | A hypervideo bundle in older shapes: `created` as `Date.toString()` text, two overlays made in the same second, the prefix-only `@context`, PHP's `[]` for `{}`, the clip's video named by its resource, an annotations index of local-folder mode, keys FrameTrail does not know. |
| `ops/*.json` | Cases of the operations, one file per topic. |

Every data file is a valid bundle by FrameTrail's schemas (`hypervideo-bundle.schema.json`, `project-bundle.schema.json`).

## Cases

```json
{
    "description": "What the cases in this file have in common.",
    "data": "lecture",
    "user": { "id": "1", "name": "Ada", "role": "user" },
    "generator": { "type": "Software", "name": "FrameTrail-Conversational-UI", "model": "mistral-medium-latest", "provider": "mistral" },
    "now": 1792000000000,
    "duration": 600,
    "cases": [
        { "name": "…", "op": { "op": "list_items", "input": { "kind": "chapters" } }, "result": { … } },
        { "name": "…", "op": { "op": "add_chapter", "input": { … } }, "result": { … }, "inverse": { … }, "after": [ … ] },
        { "name": "…", "changeset": { "ops": [ … ] }, "results": [ … ], "inverses": [ … ], "applied": { … }, "after": [ … ] },
        { "name": "…", "op": { … }, "error": { "code": "invalid", "errors": [{ "path": "/start", "message": "is taken by another chapter" }] } }
    ]
}
```

The file's `data`, `user`, `now`, `duration`, `hypervideoId` and `generator` hold for every case; a case may give its own `data`, `user`, `now`, `duration` and `hypervideoId`.

| Key | Meaning |
|-----|---------|
| `data` | A file in `data/`, without `.json`. |
| `hypervideoId` | In a project bundle, the hypervideo the store works on (default: the first in `hypervideos`). |
| `user` | `{ id, name, role }`: the user the store acts for, as FrameTrail's `edit.getUser()` tells it. `role` `admin` may change any hypervideo; others only their own (`meta.creatorId`). An empty `id` is nobody signed in. |
| `now` | Milliseconds: the clock new items' `created` comes from. |
| `duration` | Seconds: the video's duration as the media tells it, for a clip that does not say; `null` or absent: unknown. |
| `generator` | The generator the operations write when a changeset names none. |
| `op` | One operation: `{ op, input }` (`input` may be absent: `{}`). |
| `changeset` | A changeset as given to the interpreter ([`changeset.schema.json`](../changeset.schema.json)). |
| `result` / `results` | The operation's result, or each operation's result. |
| `inverse` / `inverses` | The inverse recorded for the operation, or for each. |
| `applied` | Properties of the changeset as applied, compared one by one (`id` is made up when the changeset has none, so it is listed only when given). |
| `after` | The bundle afterwards, as a [JSON Patch (RFC 6902)](https://www.rfc-editor.org/rfc/rfc6902) of the data, with `add`, `remove` and `replace` only. |
| `error` | The operation is refused: `code`, and when listed, exactly these `errors` (in any order). |

## Rules for Runners

### Running a case

1. Make a store over the case's data, user, now and duration (in a project bundle, the hypervideo picked).
2. Run it:
   - a read `op`: run the operation;
   - a write `op`: run it as a changeset of that one operation, in a transaction; its errors point into its input (no `/ops/0/input` prefix);
   - a `changeset`: apply it, as one transaction.
3. A case with `error`: the error has that code and, if listed, exactly those errors (path and message; the error's own message is not compared). The bundle is unchanged: the store's data equals the data as given.
4. Otherwise:
   - `result`, `results`, `inverse`, `inverses` and `applied` equal what was given back. Values are compared as JSON: key order does not matter, array order does.
   - Every result follows its operation's `output` schema, and an applied changeset follows `changeset.schema.json`.
   - A read leaves the bundle unchanged.
   - After a write the store's data equals the data with `after` applied.
5. After a write, undo the applied changeset (its inverses, last first). The bundle then equals the data as it would be written with nothing changed, except that items may come in another order, a property that was `null` may be missing (a merge patch cannot set `null`), and an empty annotation file counts as none.

`tests/run-js.mjs` does this in `runCase()`.

### The store's data

What a store gives back as its data is the bundle as a save would leave it: `hypervideo.json` written anew when anything in it changed (and only then), the user's annotation file written anew when their annotations changed, every other file as given, subtitles keyed by language, the annotations index untouched (the server keeps it). Written anew means through FrameTrail's serializer (`serializeHypervideo`, `serializeAnnotationFile`): an item's `@context` and `created` in the current form, every item's `target.source` the hypervideo's video, `contents` ordered overlays, code snippets, others. For the undo check, "with nothing changed" means both files written anew.

The store follows FrameTrail's edit API (FrameTrail's `docs/EXTENDING.md`, "Editing the Hypervideo"), its reads around the data included (`getInfo`, `getUser`, `permission`, `listHypervideos`): what `add` fills in, unique `created` (`max(now, latest in the collection + 1)`; annotations per creator), merge patches, chapters kept sorted by start, a new chapter refused on a taken start, subtitles entries `{ src: "<lang>.vtt", srclang }`, the content views of all four areas written once one is set, validation with FrameTrail's schemas and the same error paths and messages. One difference is FrameTrail's: the editor fills in a content view's default properties, so the live store returns (and saves) more keys than a store over files, which writes the views as given; FrameTrail fills them in when it loads them.

### The operations

What the interpreter does beyond the schemas:

- **Short form of items** (`list_items` and the write operations' results): `kind`, `ref`, then what applies — `type`, `name` (when not empty), `start`, `end`, `box` and `rotation` (when not 0) and `moving` (with keyframes) for overlays, `creator` and `own` for annotations, `title` and `end` (the next chapter's start, else the video's end, else `null`) for chapters, `text`, `src`, `tags` (when not empty). `text` is the plain text of `frametrail:attributes.text` (text, html, entity; for text `"<title>: <text>"` when there is a title), `.question` (quiz) or `.text` (hotspot), cut after 160 characters (code points) with `…`. `src` is the body's `source` when not empty, else its `value` for webpage, wikipedia and entity.
- **Plain text:** decode character references (`&amp; &lt; &gt; &quot; &apos; &nbsp;`, case-insensitive, `&nbsp;` as a space, and numeric ones `&#…;` `&#x…;` of valid code points; nothing else), remove tags (`<` and a letter, or `</` and a letter, up to `>`) and comments, decode again, collapse white space to single spaces, trim.
- **Sorting** (`list_items`): by start; on a tie overlays, annotations, chapters, code snippets; then as stored. The sort is stable.
- **Time map** (`inspect_hypervideo`): with chapters, one segment per chapter (to the next one's start, the last to the video's end or `null`), and one before the first chapter when it starts after the time range's start; without, equal segments from the time range's start of the shortest of 30, 60, 120, 300, 600, 900, 1800, 3600 seconds that makes at most 12 (else whole hours), the end being the video's end or, when unknown, the latest item end (no segments when there is none), segment bounds rounded to the millisecond. Overlays and annotations are counted in the last segment that starts at or before their start (the first when none does).
- **Transcripts:** cues by the WebVTT rules in `client/ops/util.js` (`cues()`): blocks separated by empty lines; a cue's timing line is its first or second line (`[h:]mm:ss.ttt --> …`, also with `,`); its text is the following lines as plain text joined by a space; cues without text are left out; times in seconds rounded to the millisecond. `read_transcript` takes the cues that end after `from` and start before `to`; `next` is the start of the first one left out. `find_in_transcript` matches cues whose lower-cased text contains every word of the lower-cased query.
- **Media Fragments written:** `t=<start>,<end>&xywh=percent:<left>,<top>,<width>,<height>`, numbers as JavaScript writes them (`12.5`, `0.30000000000000004`, `1e+21`). With keyframes the box is `FrameTrailKeyframes.unionBox()` of the normalised keyframes (`normalizeKeyframes()`).
- **Inverses** are calls of the store: `add(kind, item)`, `update(kind, ref, mergePatch)`, `remove(kind, ref)`, `setLayout(area, contentViews)`, `setSubtitles(lang, vttOrNull)`. An update's inverse is the merge patch from the item after to the item before (`ops.util.diffPatch`: objects key by key, a key the item before lacks set to `null`, everything else replaced whole); `null` when nothing changed.
- **Generator:** every overlay and annotation an operation adds or changes gets the changeset's `generator` (else the context's), replacing any other. An update that changes nothing writes nothing, generator included.
- **Dates** (`created`): what `new Date()` reads in FrameTrail's data — ISO 8601 (a date alone is UTC, a time without offset local time) and the text of `Date.prototype.toString()` and `toUTCString()`; anything else counts as no date. Written as `toISOString()` does.
- **Numbers in text** (messages, Media Fragments, short forms): as JavaScript's `String(number)` writes them. Equality of JSON values: every number is a number, `60` equals `60.0`.
