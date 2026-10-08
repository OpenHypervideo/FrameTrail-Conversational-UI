# FrameTrail-Conversational-UI

An extension for [FrameTrail](https://github.com/OpenHypervideo/FrameTrail) that adds a conversational UI: editing a hypervideo in natural language, from a chat panel in the editor, with Mistral's models.

**Status: early development.** The add-on loads into FrameTrail, adds an empty panel to the editor and installs its server part. What the panel's model can do with a hypervideo is defined ([`shared/operations.json`](shared/operations.json): reading it, its items and its transcript; adding, changing and removing overlays, annotations, chapters, subtitles and content views) and carried out in the browser, as changesets that are one undo step in the editor; lint rules check a hypervideo after changes ([`shared/lint.json`](shared/lint.json)). The chat panel, the model relay and transcription follow.

## Requirements

- FrameTrail 1.4.1 or later; the editing operations in the editor need the release after it (FrameTrail's `develop` until then), whose edit API tells the open hypervideo's time, the user and their permissions.
- For the server part: FrameTrail's PHP backend (server mode), PHP 7.4 or later. No Composer.

The browser part works wherever FrameTrail runs. The server part is used only in server mode: it answers a status action now, and will relay requests to Mistral (so the server can hold the key) and transcribe uploaded media.

## Installing

1. Extract `frametrail-conversational-ui-<version>.zip` (from the [releases](https://github.com/OpenHypervideo/FrameTrail-Conversational-UI/releases), or built as described below) into the FrameTrail installation, the folder that holds `index.html`. It adds two folders:

   ```
   extensions/conversational-ui/            the browser part
   _server/extensions/conversational-ui/    the server part
   ```

   Or copy `build/client/` and `build/server/` to those two places.

2. Switch it on in `_data/config.json`:

   ```json
   "extensions": [
       {
           "name": "conversational-ui",
           "script": "extensions/conversational-ui/frametrail-conversational-ui.js",
           "style": "extensions/conversational-ui/frametrail-conversational-ui.css"
       }
   ]
   ```

   The entry switches on both parts. FrameTrail reads it in every storage mode that has a `config.json` (server, local folder, project file, static hosting).

3. Check: in the editor, the title bar has a chat button that opens the panel. The server part answers its status action:

   ```
   curl -d a=conversationalUiStatus https://example.org/frametrail/_server/ajaxServer.php
   {"status":"success","code":0,"response":{"version":"…"}}
   ```

The two folders are not part of FrameTrail: copy them again after replacing FrameTrail's code with a new release.

A page that embeds FrameTrail itself can load the browser part without `config.json`: include the stylesheet and the script after FrameTrail's, and name the extension in the `extensions` init option:

```html
<link rel="stylesheet" href="frametrail-conversational-ui.css">
<script src="frametrail-conversational-ui.js"></script>
<script>
    FrameTrail.init({
        target: '#player',
        // …
        extensions: ['conversational-ui']
    }, 'PlayerLauncher');
</script>
```

Hosting platforms that replace all code outside `_data/` when they upgrade an installation compose the zip with a FrameTrail release into one template: the zip mirrors the FrameTrail code tree.

## Development

No build tools beyond bash and zip; no dependencies.

```bash
bash scripts/build.sh            # build/ (version "dev")
bash scripts/build.sh v0.1.0     # a release label

node tests/run-js.mjs            # the client part and the conformance fixtures (Node 20 or later)
php tests/run-php.php            # the server part (PHP 7.4 or later)
node tests/run-js.mjs --build    # the same tests against build/
php tests/run-php.php --build
```

The JavaScript tests use a FrameTrail working copy (1.4.1 or later) for its serializer, keyframe math, validator and schemas. A clone next to this repository named `frametrail` is found by itself, any other is named with `FRAMETRAIL_DIR=<path>` or `--frametrail=<path>`. The conformance fixtures and the rules for running them are in [`shared/fixtures/`](shared/fixtures/README.md).

To try it in a FrameTrail working copy, build it, then either extract the zip into a FrameTrail build (`bash scripts/build.sh` there), or, in FrameTrail's `src/`:

- point the `config.json` entry at the build with a relative path (e.g. `../../FrameTrail-Conversational-UI/build/client/frametrail-conversational-ui.js` when both working copies are served side by side; the path must stay on the page's origin);
- link the server part: `ln -s <this repository>/build/server src/_server/extensions/conversational-ui` (FrameTrail ignores what is installed there).

Rebuild after every change. See [CLAUDE.md](CLAUDE.md) for the conventions.

## License

MIT, see [LICENSE](LICENSE).
