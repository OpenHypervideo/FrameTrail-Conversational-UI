# FrameTrail-Conversational-UI

An extension for [FrameTrail](https://github.com/OpenHypervideo/FrameTrail) that adds a conversational UI: editing a hypervideo in natural language, from a chat panel in the editor, with Mistral's models.

**Status: early development.** In the editor, a side panel ("AI Assistant") takes questions and requests about the open hypervideo and carries them out: it reads the hypervideo, its items and its transcript, and adds, changes and removes overlays, annotations, chapters, subtitles and content views ([`shared/operations.json`](shared/operations.json)), each request one step in the undo history; lint rules check its changes ([`shared/lint.json`](shared/lint.json)). On a FrameTrail server it reaches Mistral through the server, which holds the key (the relay); everywhere else, and on a server that allows it, directly from the browser with the user's own key. Transcription follows.

## Requirements

- FrameTrail 1.4.1 or later; the editing operations in the editor need the release after it (FrameTrail's `develop` until then), whose edit API tells the open hypervideo's time, the user and their permissions.
- For the server part: FrameTrail's PHP backend (server mode), PHP 7.4 or later. No Composer.

The browser part works wherever FrameTrail runs. The server part is used only in server mode: it answers a status action (which tells the panel how it reaches Mistral there) and relays requests to Mistral, so the server can hold the key (PHP's curl extension needed for that); later it will transcribe uploaded media.

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

4. On a server, give the assistant a Mistral API key, or let users bring their own, in `_data/.auth/conversational-ui.php` (see [The Relay](#the-relay)). The file is never served or exported.

   ```php
   <?php
   return array(
       "apiKey"      => "…",       // the relay: everyone signed in uses the assistant without a key of their own
       "allowOwnKey" => true       // users may also use their own key, directly from the browser
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

- **Reaching Mistral.** On a server the panel goes through the server's relay when it has a key, with no key in the browser, and needs the user to be signed in; when the server also allows own keys, the settings let each user choose ("Through this server" or "With my own Mistral key"). With a local folder, a project file, static hosting or in memory it always uses the user's own key, directly from the browser. A key is made in [Mistral's console](https://console.mistral.ai/api-keys); the panel keeps it in memory, or on the device when the user ticks "Remember the key on this device".
- **Models.** The default is `ministral-14b-latest`, the best of the models Mistral's free plan allows in the add-on's evaluation (`scripts/eval-models.mjs`). On the free plan some models (at present `mistral-medium-latest`, `mistral-small-latest`) allow no requests at all; the panel then reports a rate limit. The settings list the models the key offers.
- **What is sent.** The user's messages, a summary of the hypervideo and what the assistant reads (item texts, transcript passages) go to Mistral AI, hosted in the EU. On the free Experiment plan Mistral uses them for training unless the account opts out (Admin Console → Privacy); the settings say so.
- **Undo and Stop.** Everything the assistant changes in answer to one message is one undo step: "Undo this turn" under the answer, or the editor's own undo. Stop (or Esc in the message field, or the editor's own Stop) takes back what the running turn changed. While it changes things, the editor is busy and editing by hand waits.
- Conversations are kept per hypervideo until the page is closed; they are not saved.

## The Relay

In server mode the relay passes the panel's requests to Mistral's Chat Completions API and the answers back, streamed as they come, with the server's key: users need no key of their own, and the key never reaches a browser. It passes requests through as they are and decides only who may send them, for which model, and how many a day. Its configuration is `_data/.auth/conversational-ui.php`, a PHP file returning an array, which is never served (FrameTrail's `.htaccess`) or exported:

```php
<?php
return array(
    // The relay is on when there is a key (and PHP has the curl extension).
    "apiKey"         => "…",

    // The models users may choose, and the one the panel starts with.
    // Default: ministral-14b-latest, the best of the models Mistral's free plan
    // allows in the add-on's evaluation. A paid key may allow larger ones: run
    // scripts/eval-models.mjs with it before offering them.
    "allowedModels"  => array("ministral-14b-latest", "ministral-8b-latest"),
    "defaultModel"   => "ministral-14b-latest",

    // Requests to the model per person and day (calendar days, server time).
    // One message can take several: each round of reading and changing is one.
    // Leave it out for no limit.
    "requestsPerDay" => 200,

    // Users may also use a Mistral key of their own, directly from the browser
    // (the settings then let them choose). Default: false.
    "allowOwnKey"    => false,

    // Seconds one request to the model may take (10 to 3600). Default: 300.
    "timeout"        => 300,

    // Where the requests go: Mistral's API by default; a platform's gateway
    // that offers the same API (see below).
    "baseUrl"        => "https://api.mistral.ai/v1",
    "instance"       => null
);
```

- **Who may use it:** anyone signed in with an active account, in the editor. Not guests (editing without an account has no session on the server) and not requests with a personal API token (it is the editor's relay, not an API). Administrators see in the panel's settings what the server has set up, and what keeps the relay from working (no curl, an invalid `baseUrl`).
- **The limit:** counted per user and day in `_data/.extensions/conversational-ui/usage.json`; a request over it is refused, and the panel says that today's requests are used up. Requests the relay refuses are not counted.
- **Mistral's answers** go to the panel as they are, with two exceptions: when Mistral refuses the server's key, users are told the assistant is not set up (the key is the server's problem, not theirs); and a model the key may never use (on Mistral's free plan some have a limit of 0 requests a minute) is reported as a model to change rather than a rate limit to wait out.
- **Streaming** works without configuration under Apache with mod_php or PHP-FPM (checked with `mod_proxy_fcgi`, httpd 2.4.69) and with PHP's built-in server. The relay switches off PHP's output buffering and compression for its answer and sends `X-Accel-Buffering: no` for nginx. Behind PHP-FPM, `flushpackets=on` makes the pieces finer (without it they arrive a few times a second):

  ```apache
  <Proxy "fcgi://localhost:9000">
      ProxySet flushpackets=on
  </Proxy>
  ```

  A proxy that holds the answer back until it is complete is noticed by the panel, which then asks without streaming. PHP's built-in server answers one request at a time unless told otherwise: start it with `PHP_CLI_SERVER_WORKERS=4 php -S …`, or the editor's other requests wait while the assistant answers.
- **A platform's gateway:** with `baseUrl` set to anything but Mistral's API, the relay also sends `X-FrameTrail-User` (the user's id, or the platform's subject under external authentication) and `X-FrameTrail-Instance` (`instance`, or the installation's URL); Mistral itself gets neither. `apiKey` is then the gateway's credential for the instance. A gateway may refuse in the relay's own words, `{ "error": { "code": "quota" | "notAllowed", "message": "…" } }`, which reaches the panel as it is.

## Development

No build tools beyond bash and zip; no dependencies.

```bash
bash scripts/build.sh            # build/ (version "dev")
bash scripts/build.sh v0.1.0     # a release label

node tests/run-js.mjs            # the client part and the conformance fixtures (Node 20 or later)
php tests/run-php.php            # the server part (PHP 7.4 or later, with curl)
node tests/run-js.mjs --build    # the same tests against build/
php tests/run-php.php --build
```

The JavaScript tests use a FrameTrail working copy (1.4.1 or later) for its serializer, keyframe math, validator and schemas. A clone next to this repository named `frametrail` is found by itself, any other is named with `FRAMETRAIL_DIR=<path>` or `--frametrail=<path>`. The conformance fixtures and the rules for running them are in [`shared/fixtures/`](shared/fixtures/README.md). The PHP tests run the relay against a stand-in for Mistral's API, both on PHP's built-in server.

How well Mistral's models use the tools is measured by the evaluation, which needs a key and is not part of the tests:

```bash
node scripts/eval-models.mjs --key-file=<file> --models=ministral-14b-latest,ministral-8b-latest [--repeat=3]
```

To try it in a FrameTrail working copy, build it, then either extract the zip into a FrameTrail build (`bash scripts/build.sh` there), or, in FrameTrail's `src/`:

- point the `config.json` entry at the build with a relative path (e.g. `../../FrameTrail-Conversational-UI/build/client/frametrail-conversational-ui.js` when both working copies are served side by side; the path must stay on the page's origin);
- link the server part: `ln -s <this repository>/build/server src/_server/extensions/conversational-ui` (FrameTrail ignores what is installed there).

Rebuild after every change. See [CLAUDE.md](CLAUDE.md) for the conventions.

## License

MIT, see [LICENSE](LICENSE).
