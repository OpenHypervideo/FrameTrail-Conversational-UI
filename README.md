# FrameTrail-Conversational-UI

An extension for [FrameTrail](https://github.com/OpenHypervideo/FrameTrail) that adds a conversational UI: editing a hypervideo in natural language, from a chat panel in the editor, with Mistral's models.

**Status: early development.** In the editor, a side panel ("AI Assistant") takes questions and requests about the open hypervideo and carries them out: it reads the hypervideo, its items and its transcript, and adds, changes and removes overlays, annotations, chapters, subtitles and content views ([`shared/operations.json`](shared/operations.json)), each request one step in the undo history; lint rules check its changes ([`shared/lint.json`](shared/lint.json)). On a FrameTrail server it reaches Mistral through the server, which holds the key (the relay); everywhere else, and on a server that allows it, directly from the browser with the user's own key. On a server with a self-hosted Whisper server it also transcribes the uploaded video into subtitles, which the assistant then reads (topics, chapters, quotes).

## Requirements

- FrameTrail 1.4.1 or later; the editing operations in the editor need the release after it (FrameTrail's `develop` until then), whose edit API tells the open hypervideo's time, the user and their permissions.
- For the server part: FrameTrail's PHP backend (server mode), PHP 7.4 or later. No Composer.

The browser part works wherever FrameTrail runs. The server part is used only in server mode: it answers a status action (which tells the panel how it reaches Mistral there), relays requests to Mistral, so the server can hold the key, and sends the sound of uploaded videos to a Whisper server for transcription (PHP's curl extension needed for both; ffmpeg on the server helps transcription).

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
   {"status":"success","code":0,"response":{"version":"…","capabilities":{"relay":false,"ownKey":false,"transcription":false}}}
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
- **Transcription** (on a server where it is set up, see [Transcription](#transcription)): the panel's "Transcribe the video" button, or a request ("Transcribe the video, then add a chapter at each topic change"). The spoken language is detected, or chosen. The result becomes the video's subtitles in that language (replacing any there were), one undo step, saved with the hypervideo; the assistant reads them from then on. It takes a while for a long video (on a CPU, roughly a fifth to a half of the video's length); the editor stays free meanwhile. Only uploaded videos can be transcribed, not videos from other sites or streams; and only by an admin or the hypervideo's creator, who may change its subtitles. In the other storage modes the panel says that transcription needs FrameTrail on a server.
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
- **A platform's gateway:** with `baseUrl` set to anything but Mistral's API, the relay also sends `X-FrameTrail-User` (the user's id, or the platform's subject under external authentication) and `X-FrameTrail-Instance` (`instance`, or the installation's URL); Mistral itself gets neither. `apiKey` is then the gateway's credential for the instance. A gateway may refuse in the relay's own words, `{ "error": { "code": "quota" | "notAllowed", "message": "…" } }`, which reaches the panel as it is. A quota refusal may add `"period": "month"` and `"resetsAt"` (ISO 8601): the panel then says the month's requests are used up and when they come back, instead of "today's requests on this server", and links to the platform's `manageUrl` when the extension's settings name one.

## Transcription

On a server, the panel can have the video of the open hypervideo transcribed by a self-hosted speech recognition server (Whisper): the server takes the sound out of the uploaded video and sends it there; the text comes back as segments with times, and the panel makes the video's subtitles of them. Audio and transcripts stay on servers of your own; what the assistant later reads of the transcript goes to Mistral like everything else it reads.

It is switched on by a `transcription` block in `_data/.auth/conversational-ui.php`; without it there is no button and no tool for the model (a platform that offers transcription only in some plans writes the block only for those):

```php
<?php
return array(
    // … the relay's keys …

    "transcription" => array(
        // The speech server's OpenAI-compatible API: the requests go to
        // {baseUrl}/audio/transcriptions, asking for segments (verbose_json).
        "baseUrl"     => "http://127.0.0.1:8000/v1",

        // Sent as "model": the server's name for its model.
        "model"       => "Systran/faster-whisper-small",

        // Optional: a bearer token, when the speech server wants one.
        "apiKey"      => null,

        // The most that is sent: the sound taken out of the video, or the
        // video itself without ffmpeg. Default 100 MB.
        "maxBytes"    => 104857600,

        // Seconds a transcription may take, all in all (60 to 7200; default 1800).
        "timeout"     => 1800,

        // ffmpeg: a path, false never to use it; default: found as FrameTrail
        // finds it for uploads (/usr/bin, /usr/local/bin, /opt/homebrew/bin, PATH).
        // "ffmpeg"   => "/usr/bin/ffmpeg",

        // What the sound is sent as: mp3 (default; 16 kHz mono, about 0.36 MB a
        // minute), flac or wav, for a speech server that cannot read mp3.
        "audioFormat" => "mp3"
    )
);
```

- **A speech server.** Anything with OpenAI's `/audio/transcriptions` and `response_format=verbose_json`:
  - [speaches](https://speaches.ai/) (faster-whisper) in Docker, on CPU: `docker run -d -p 127.0.0.1:8000:8000 -v hf-hub-cache:/home/ubuntu/.cache/huggingface/hub ghcr.io/speaches-ai/speaches:0.8.3-cpu`, then once `curl -X POST http://127.0.0.1:8000/v1/models/Systran/faster-whisper-small` to download the model; `baseUrl` `http://127.0.0.1:8000/v1`, `model` the model's id. It reads mp3 and video files. Checked with 0.8.3 and `Systran/faster-whisper-small`: about five times faster than real time on 12 CPU cores.
  - [whisper.cpp](https://github.com/ggml-org/whisper.cpp)'s `whisper-server -m <model> --inference-path /v1/audio/transcriptions` (`baseUrl` `http://127.0.0.1:8080/v1`; it ignores `model`). Add `--convert` (which needs ffmpeg where whisper.cpp runs) if the FrameTrail server has no ffmpeg, or choose `audioFormat` `wav`.
  - Keep it on the local machine or the internal network: `baseUrl` is reached from the FrameTrail server, never from browsers.
- **ffmpeg on the FrameTrail server** (as FrameTrail uses it for uploads; it needs PHP's `proc_open`): only the clip's span (its in and out points) is transcribed, as compact mono audio, and the subtitles' times are on the video's timeline. Without ffmpeg the video file is sent as it is, if it is no larger than `maxBytes`, and the speech server has to read the video format.
- **Who may use it:** a signed-in, active user who may change the hypervideo's subtitles (an admin or its creator), in the editor; not personal API tokens. Administrators see in the panel's settings whether it is set up, with or without ffmpeg, and what keeps it from working.
- **Long transcriptions.** The answer is streamed (route `transcribe`): progress at least every ten seconds and a keep-alive every two, so proxies do not close the connection and the server notices when the panel stops it (ffmpeg and the request to the speech server are then ended). Behind PHP-FPM with Apache's `mod_proxy_fcgi`, set `flushpackets=on` (as for the relay): without it Apache holds the progress back until the end, and the panel shows only the time counting. PHP's own `max_execution_time` is raised for the request; a proxy with a fixed limit on the whole answer (a CDN in front, say) must allow the longest transcription. Temporary sound files are kept in `_data/.extensions/conversational-ui/tmp/` while they are needed.
- **A platform's gateway:** like the relay, transcription sends `X-FrameTrail-User` and `X-FrameTrail-Instance`, so the platform can count and limit. A gateway may answer the upload with a **job** instead of the transcription: `202 { "id", "status": "queued", "position" }`. The route then looks at `GET {baseUrl}/audio/transcriptions/{id}` every three seconds — `{ status: "queued", position }`, `"running"`, `"completed"` with `result` (the `verbose_json`), `"failed"` with `error` (`{ code?, message }`), `"cancelled"` — tells the panel its place in line, and sends `DELETE {baseUrl}/audio/transcriptions/{id}` when the panel goes away or the time is up. The job's URL is made from `baseUrl` and the id (letters, digits, `-`, `_`), never taken from the answer. A plain Whisper server answers within the request, as before. Refusals in the relay's own words (`login`, `quota`, `notConfigured`, `notAllowed`, a quota with `period` and `resetsAt`) reach the panel as they came, from the upload and from a failed job alike; a gateway's 403 `notAllowed` is not read as "the key was refused".

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

The JavaScript tests use a FrameTrail working copy (1.4.1 or later) for its serializer, keyframe math, validator and schemas. A clone next to this repository named `frametrail` is found by itself, any other is named with `FRAMETRAIL_DIR=<path>` or `--frametrail=<path>`. The conformance fixtures and the rules for running them are in [`shared/fixtures/`](shared/fixtures/README.md). The PHP tests run the relay and transcription against stand-ins for Mistral's API and a Whisper server, on PHP's built-in server; where ffmpeg is installed they also check the sound it takes out of a generated video (skipped otherwise).

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
