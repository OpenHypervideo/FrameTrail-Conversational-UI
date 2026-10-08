# FrameTrail-Conversational-UI

An extension for [FrameTrail](https://github.com/OpenHypervideo/FrameTrail) that adds a conversational UI: editing a hypervideo in natural language, from a chat panel in the editor, with Mistral's models.

**Status: early development.** In the editor, a side panel ("AI Assistant") takes questions and requests about the open hypervideo and carries them out: it reads the hypervideo, its items and its transcript, and adds, changes and removes overlays, annotations, chapters, subtitles and content views ([`shared/operations.json`](shared/operations.json)), each request one step in the undo history; lint rules check its changes ([`shared/lint.json`](shared/lint.json)). It talks to Mistral directly from the browser with the user's own key. The model relay on the server (no key in the browser) and transcription follow.

## Requirements

- FrameTrail 1.4.1 or later; the editing operations in the editor need the release after it (FrameTrail's `develop` until then), whose edit API tells the open hypervideo's time, the user and their permissions.
- For the server part: FrameTrail's PHP backend (server mode), PHP 7.4 or later. No Composer.

The browser part works wherever FrameTrail runs. The server part is used only in server mode: it answers a status action (which tells the panel whether users may use their own key), and will relay requests to Mistral (so the server can hold the key) and transcribe uploaded media.

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

3. Check: in the editor, the title bar has an "AI" button that opens the panel. The server part answers its status action:

   ```
   curl -d a=conversationalUiStatus https://example.org/frametrail/_server/ajaxServer.php
   {"status":"success","code":0,"response":{"version":"…","capabilities":{"relay":false,"ownKey":false}}}
   ```

4. On a server, allow users their own Mistral key (until the relay comes, the only way there) in `_data/.auth/conversational-ui.php`, which is never served:

   ```php
   <?php
   return array(
       "allowOwnKey" => true
   );
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

## Using It

Start editing a hypervideo and open the panel from the title bar. Ask about the hypervideo ("What is said about osmosis?"), or say what to change ("Add a chapter at each topic change in the first 5 minutes"); for a vague idea the assistant proposes first and asks.

- **Reaching Mistral.** On a server the panel uses the user's own key when `allowOwnKey` is set (later: the server's relay, with no key in the browser); with a local folder, a project file, static hosting or in memory it always uses the user's own key, directly from the browser. A key is made in [Mistral's console](https://console.mistral.ai/api-keys); the panel keeps it in memory, or on the device when the user ticks "Remember the key on this device".
- **Models.** The default is `ministral-14b-latest`, the best of the models Mistral's free plan allows in the add-on's evaluation (`scripts/eval-models.mjs`). On the free plan some models (at present `mistral-medium-latest`, `mistral-small-latest`) allow no requests at all; the panel then reports a rate limit. The settings list the models the key offers.
- **What is sent.** The user's messages, a summary of the hypervideo and what the assistant reads (item texts, transcript passages) go to Mistral AI, hosted in the EU. On the free Experiment plan Mistral uses them for training unless the account opts out (Admin Console → Privacy); the settings say so.
- **Undo and Stop.** Everything the assistant changes in answer to one message is one undo step: "Undo this turn" under the answer, or the editor's own undo. Stop (or Esc in the message field, or the editor's own Stop) takes back what the running turn changed. While it changes things, the editor is busy and editing by hand waits.
- Conversations are kept per hypervideo until the page is closed; they are not saved.

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

The JavaScript tests use a FrameTrail working copy (1.4.1 or later) for its serializer, keyframe math, validator and schemas.

How well Mistral's models use the tools is measured by the evaluation, which needs a key and is not part of the tests:

```bash
node scripts/eval-models.mjs --key-file=<file> --models=ministral-14b-latest,ministral-8b-latest [--repeat=3]
``` A clone next to this repository named `frametrail` is found by itself, any other is named with `FRAMETRAIL_DIR=<path>` or `--frametrail=<path>`. The conformance fixtures and the rules for running them are in [`shared/fixtures/`](shared/fixtures/README.md).

To try it in a FrameTrail working copy, build it, then either extract the zip into a FrameTrail build (`bash scripts/build.sh` there), or, in FrameTrail's `src/`:

- point the `config.json` entry at the build with a relative path (e.g. `../../FrameTrail-Conversational-UI/build/client/frametrail-conversational-ui.js` when both working copies are served side by side; the path must stay on the page's origin);
- link the server part: `ln -s <this repository>/build/server src/_server/extensions/conversational-ui` (FrameTrail ignores what is installed there).

Rebuild after every change. See [CLAUDE.md](CLAUDE.md) for the conventions.

## License

MIT, see [LICENSE](LICENSE).
