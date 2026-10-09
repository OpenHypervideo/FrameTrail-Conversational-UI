<?php

/*
 * FrameTrail-Conversational-UI — transcription: the speech in the video of a
 * hypervideo turned into text by a self-hosted Whisper server (server mode).
 * Nothing is written here: the panel applies the result as subtitles through
 * FrameTrail's edit API, one undo step, saved by the editor's save.
 *
 * - Route transcribe (_server/extension.php?e=conversational-ui&r=transcribe):
 *   POST { "hypervideoId": "8", "language": "de" } as JSON; without a
 *   language the speech server detects it.
 * - What it refuses before it starts is answered with an HTTP status and
 *   { error: { code?, message } }, as the relay answers: notConfigured (503:
 *   no transcription block, no curl), login (401), notAllowed (403: an
 *   inactive account, a personal API token, a hypervideo the user may not
 *   change), notFound (404), noFile (422: no video, a video from another site
 *   or a stream, a missing file), tooLarge (413: no ffmpeg, and the video is
 *   larger than maxBytes); 400, 405 and 415 for requests it does not take.
 * - Then the answer is 200, server-sent events:
 *       event: progress  data: { stage: "extracting" | "sending" | "queued" | "transcribing", elapsed, sent?, total?, position? }
 *       event: result    data: { language, duration, offset, segments: [{ start, end, text }] }
 *       event: error     data: { error: { code?, message } }
 *   progress comes with every new stage and at least every ten seconds, so a
 *   proxy never sees a long silence, and a comment (": keep-alive") every two
 *   seconds between, by which a browser that has gone is noticed: ffmpeg and
 *   the request to the speech server are then stopped. Errors carry the codes
 *   timeout, tooLarge or notConfigured (the speech server refused the
 *   server's key), or none (ffmpeg or the speech server failed).
 *
 * The sound: with ffmpeg, only the clip's span [in, out], as compact mono
 * audio (16 kHz) in the configured format; offset is then the clip's in point,
 * which the panel adds to the times, so they are on the media's timeline, as
 * FrameTrail's are. Without ffmpeg the video file goes as it is (offset 0),
 * when it is no larger than maxBytes. Temporary files are in the add-on's
 * private folder (tmp/) and removed when the request ends.
 *
 * The speech server: {baseUrl}/audio/transcriptions, OpenAI's API for it
 * (whisper.cpp's whisper-server, faster-whisper behind speaches, a gateway),
 * asked for segments (response_format verbose_json). The configuration is
 * ftConversationalUiTranscriptionConfig() in extension.php. This file builds
 * on the relay's helpers (relay.php).
 *
 * A gateway may answer with a job instead of waiting: 202 { id, status,
 * position? }. The job is then polled, GET {baseUrl}/audio/transcriptions/{id}
 * every few seconds → { id, status: queued (position) | running | completed
 * (result: the verbose_json) | failed (error: { code?, message }) |
 * cancelled }, reported as progress (stage "queued" with its position, then
 * "transcribing"), and cancelled, DELETE …/{id}, when the browser has gone or
 * the time is up. A refusal in the relay's own words (code login, quota,
 * notConfigured, notAllowed, with period and resetsAt for a quota) reaches the
 * panel as it came, from the first answer and from a failed job alike.
 */

// Only ever run by FrameTrail's routers, through extension.php.
if (!function_exists("ftExtensionStorage")) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/relay.php";


/**
 * I return how many seconds may pass without progress being sent: ten, or
 * FT_CONVERSATIONAL_UI_TICK where the tests define it. A keep-alive comment
 * goes out after a fifth of it (two seconds).
 *
 * @method ftConversationalUiTranscribeInterval
 * @return Number
 */
function ftConversationalUiTranscribeInterval() {

    return defined("FT_CONVERSATIONAL_UI_TICK") ? (float)FT_CONVERSATIONAL_UI_TICK : 10.0;

}


/**
 * I return the ffmpeg to take the sound out of a video with, or null when
 * there is none: the configured path (a bare name is looked up in PATH),
 * none when configured false, else FrameTrail's own (detectFFmpegPath() in
 * its files.php, which FrameTrail's uploads use). ffmpeg is run with
 * proc_open(), without which there is none.
 *
 * @method ftConversationalUiTranscribeFfmpeg
 * @param {Array} $transcription  ftConversationalUiTranscriptionConfig()'s
 * @return String|null
 */
function ftConversationalUiTranscribeFfmpeg($transcription) {

    if ($transcription["ffmpeg"] === false || !function_exists("proc_open")) {
        return null;
    }

    if (is_string($transcription["ffmpeg"])) {
        $path = $transcription["ffmpeg"];
        $bare = strpos($path, "/") === false && strpos($path, "\\") === false;
        return ($bare || is_executable($path)) ? $path : null;
    }

    if (!function_exists("detectFFmpegPath")) {
        // _server/files.php, next to the router that runs this.
        $candidates = array(dirname(__DIR__, 2) . "/files.php");
        if (isset($_SERVER["SCRIPT_FILENAME"]) && is_string($_SERVER["SCRIPT_FILENAME"])) {
            $candidates[] = dirname($_SERVER["SCRIPT_FILENAME"]) . "/files.php";
        }
        foreach ($candidates as $file) {
            if (is_file($file)) {
                require_once $file;
                break;
            }
        }
    }

    // detectFFmpegPath() runs ffmpeg with exec(), which a server may have switched off.
    if (!function_exists("detectFFmpegPath") || !function_exists("exec")) {
        return null;
    }

    $path = detectFFmpegPath();

    return (is_string($path) && $path !== "") ? $path : null;

}


/**
 * I return the stored hypervideo.json of a hypervideo, decoded, or null when
 * there is no such hypervideo. Its folder is the one hypervideos/_index.json
 * names, and must be inside hypervideos/.
 *
 * @method ftConversationalUiTranscribeHypervideo
 * @param {String} $id
 * @return Array|null
 */
function ftConversationalUiTranscribeHypervideo($id) {

    global $conf;

    if (!isset($conf["dir"]["data"]) || !is_string($conf["dir"]["data"])) {
        return null;
    }

    $base  = realpath($conf["dir"]["data"] . "/hypervideos");
    $index = ($base !== false) ? json_decode((string)@file_get_contents($base . "/_index.json"), true) : null;

    if (!is_array($index) || !isset($index["hypervideos"]) || !is_array($index["hypervideos"])
        || !array_key_exists($id, $index["hypervideos"]) || !is_string($index["hypervideos"][$id])) {
        return null;
    }

    $folder = realpath($base . "/" . $index["hypervideos"][$id]);
    if ($folder === false || dirname($folder) !== $base) {
        return null;
    }

    $hypervideo = json_decode((string)@file_get_contents($folder . "/hypervideo.json"), true);

    return is_array($hypervideo) ? $hypervideo : null;

}


/**
 * I find the file of a hypervideo's video: its first clip's src, or the src
 * of the resource the clip names, as FrameTrail finds it
 * (Database.sourcePathOf()), as a file in resources/
 * (ftResourceFilePath()). Videos from other sites and streams have no file.
 *
 * @method ftConversationalUiTranscribeSource
 * @param {Array} $hypervideo  hypervideo.json, decoded
 * @return Array  a failure, or array("path", "name", "bytes", "in", "out") (out 0: to the end)
 */
function ftConversationalUiTranscribeSource($hypervideo) {

    global $conf;

    $clip = (isset($hypervideo["clips"][0]) && is_array($hypervideo["clips"][0])) ? $hypervideo["clips"][0] : array();
    $src  = (isset($clip["src"]) && is_string($clip["src"]) && strlen($clip["src"]) > 3) ? $clip["src"] : null;

    if ($src === null && isset($clip["resourceId"]) && (is_string($clip["resourceId"]) || is_int($clip["resourceId"])) && $clip["resourceId"] !== "") {
        $resources = json_decode((string)@file_get_contents($conf["dir"]["data"] . "/resources/_index.json"), true);
        $id        = (string)$clip["resourceId"];
        if (is_array($resources) && isset($resources["resources"][$id]["src"]) && is_string($resources["resources"][$id]["src"])) {
            $src = $resources["resources"][$id]["src"];
        }
    }

    if ($src === null || trim($src) === "") {
        return ftConversationalUiRelayFailure(422, "This hypervideo has no video to transcribe.", "noFile");
    }
    if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $src)) {
        return ftConversationalUiRelayFailure(422, "Only uploaded video files can be transcribed, not videos from other sites or streams.", "noFile");
    }

    $path = ftResourceFilePath($src);
    if ($path === null) {
        return ftConversationalUiRelayFailure(422, "The video file of this hypervideo is missing.", "noFile");
    }

    $in  = (isset($clip["in"]) && (is_int($clip["in"]) || is_float($clip["in"])) && $clip["in"] > 0) ? (float)$clip["in"] : 0.0;
    $out = (isset($clip["out"]) && (is_int($clip["out"]) || is_float($clip["out"])) && $clip["out"] > $in) ? (float)$clip["out"] : 0.0;

    return array("path" => $path, "name" => basename($path), "bytes" => (int)filesize($path), "in" => $in, "out" => $out);

}


/**
 * A size for people: megabytes with one decimal, kilobytes below a megabyte.
 *
 * @method ftConversationalUiTranscribeMegabytes
 * @param {Number} $bytes
 * @return String
 */
function ftConversationalUiTranscribeMegabytes($bytes) {

    if ($bytes < 1048576) {
        return max(1, (int)round($bytes / 1024)) . " KB";
    }

    return rtrim(rtrim(number_format($bytes / 1048576, 1, ".", ""), "0"), ".") . " MB";

}


/**
 * I decide whether a transcription may start: transcription set up, a
 * signed-in, active user who is not a personal API token, a request naming a
 * hypervideo (and maybe a language), a hypervideo the user may change (an
 * administrator, or its creator: the editor's rule for its subtitles), whose
 * video is an uploaded file, which, without ffmpeg, is small enough to be sent
 * as it is.
 *
 * @method ftConversationalUiTranscribeAdmit
 * @param {String|null} $body  the request as JSON text
 * @return Array  a failure, or array("config", "transcription", "user", "hypervideoId", "language", "source", "ffmpeg")
 */
function ftConversationalUiTranscribeAdmit($body) {

    $config        = ftConversationalUiConfig();
    $transcription = $config["transcription"];

    if ($transcription === null || !$transcription["baseUrlValid"]) {
        return ftConversationalUiRelayFailure(503, "Transcription is not set up on this server.", "notConfigured");
    }
    if (!function_exists("curl_init")) {
        return ftConversationalUiRelayFailure(503, "Transcription needs PHP's curl extension, which this server does not have.", "notConfigured");
    }

    $login = userCheckLogin();
    if (!is_array($login) || !isset($login["code"]) || ($login["code"] != 1 && $login["code"] != 3)) {
        return ftConversationalUiRelayFailure(401, "Sign in to transcribe.", "login");
    }
    if ($login["code"] == 3) {
        return ftConversationalUiRelayFailure(403, "This account is not active.", "notAllowed");
    }
    if (ftIsBearerRequest()) {
        return ftConversationalUiRelayFailure(403, "Transcription serves the editor; personal API tokens cannot use it.", "notAllowed");
    }

    $user = isset($login["response"]) && is_array($login["response"]) ? $login["response"] : array();

    if (!is_string($body) || $body === "") {
        return ftConversationalUiRelayFailure(400, "The request is empty.");
    }
    if (strlen($body) > 65536) {
        return ftConversationalUiRelayFailure(413, "The request is larger than 64 KB.");
    }

    $request = json_decode($body);
    if (!is_object($request)) {
        return ftConversationalUiRelayFailure(400, "The request is not a JSON object.");
    }

    $id = (isset($request->hypervideoId) && (is_string($request->hypervideoId) || is_int($request->hypervideoId))) ? (string)$request->hypervideoId : "";
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
        return ftConversationalUiRelayFailure(400, "The request names no hypervideo.");
    }

    $language = null;
    if (isset($request->language) && $request->language !== "") {
        if (!is_string($request->language) || !preg_match('/^[a-z]{2,3}$/', $request->language)) {
            return ftConversationalUiRelayFailure(400, "The language is not a language code like en or de.");
        }
        $language = $request->language;
    }

    $hypervideo = ftConversationalUiTranscribeHypervideo($id);
    if ($hypervideo === null) {
        return ftConversationalUiRelayFailure(404, "There is no hypervideo " . $id . ".", "notFound");
    }

    $creator = isset($hypervideo["meta"]["creatorId"]) && (is_string($hypervideo["meta"]["creatorId"]) || is_int($hypervideo["meta"]["creatorId"]))
        ? (string)$hypervideo["meta"]["creatorId"] : null;
    $admin   = isset($user["role"]) && $user["role"] === "admin";
    if (!$admin && ($creator === null || !isset($user["id"]) || $creator !== (string)$user["id"])) {
        return ftConversationalUiRelayFailure(403, "Only an admin or the creator of this hypervideo can transcribe it.", "notAllowed");
    }

    $source = ftConversationalUiTranscribeSource($hypervideo);
    if (isset($source["failure"])) {
        return $source;
    }

    $ffmpeg = ftConversationalUiTranscribeFfmpeg($transcription);
    if ($ffmpeg === null && $source["bytes"] > $transcription["maxBytes"]) {
        return ftConversationalUiRelayFailure(413, "The video (" . ftConversationalUiTranscribeMegabytes($source["bytes"]) . ") is larger than the "
            . ftConversationalUiTranscribeMegabytes($transcription["maxBytes"]) . " that may be sent, and this server has no ffmpeg to take the sound out of it.", "tooLarge");
    }

    return array(
        "config"        => $config,
        "transcription" => $transcription,
        "user"          => $user,
        "hypervideoId"  => $id,
        "language"      => $language,
        "source"        => $source,
        "ffmpeg"        => $ffmpeg
    );

}


/**
 * I return a new temporary file in the add-on's private folder (tmp/, or the
 * system's when that cannot be written), removed when the request ends at the
 * latest. Leftovers of requests that never ended are removed after a day. The
 * path is absolute: Apache runs shutdown functions in another working
 * directory, and FrameTrail's data folder may be given relative to _server/.
 *
 * @method ftConversationalUiTranscribeTempFile
 * @param {String} $extension
 * @return String
 */
function ftConversationalUiTranscribeTempFile($extension) {

    $storage = ftExtensionStorage("conversational-ui");
    $dir     = ($storage !== false) ? $storage . "/tmp" : null;

    if ($dir !== null && !is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    if ($dir !== null && is_dir($dir) && is_writable($dir)) {
        $dir = realpath($dir);
        foreach ((array)glob($dir . "/transcribe-*") as $old) {
            if (is_file($old) && filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
    } else {
        $dir = realpath(sys_get_temp_dir());
    }

    $file = $dir . "/transcribe-" . bin2hex(random_bytes(8)) . "." . $extension;

    register_shutdown_function(function() use ($file) {
        if (is_file($file)) {
            @unlink($file);
        }
    });

    return $file;

}


/**
 * I return the ffmpeg command that takes the sound of a clip's span out of a
 * video: mono, 16 kHz (what Whisper works with), in the format given.
 *
 * @method ftConversationalUiTranscribeCommand
 * @param {String} $ffmpeg
 * @param {Array} $source  ftConversationalUiTranscribeSource()'s
 * @param {String} $format  mp3, flac or wav
 * @param {String} $target
 * @return Array  for proc_open()
 */
function ftConversationalUiTranscribeCommand($ffmpeg, $source, $format, $target) {

    $codecs = array(
        "mp3"  => array("-c:a", "libmp3lame", "-b:a", "48k", "-f", "mp3"),
        "flac" => array("-c:a", "flac", "-f", "flac"),
        "wav"  => array("-c:a", "pcm_s16le", "-f", "wav")
    );

    $command = array($ffmpeg, "-nostdin", "-hide_banner", "-loglevel", "error", "-y");

    if ($source["in"] > 0) {
        array_push($command, "-ss", sprintf("%.3F", $source["in"]));
    }

    array_push($command, "-i", $source["path"]);

    if ($source["out"] > $source["in"]) {
        array_push($command, "-t", sprintf("%.3F", $source["out"] - $source["in"]));
    }

    return array_merge($command, array("-vn", "-sn", "-dn", "-ac", "1", "-ar", "16000"), $codecs[$format], array($target));

}


/**
 * I run ffmpeg to take the sound out of the video into $target. $tick() is
 * called about five times a second and stops ffmpeg by returning false (the
 * browser has gone); so does the deadline.
 *
 * @method ftConversationalUiTranscribeExtract
 * @param {Array} $command  ftConversationalUiTranscribeCommand()'s
 * @param {String} $target
 * @param {Number} $deadline  microtime(true) by which it must be done
 * @param {Number} $timeout  the configured timeout, for the words
 * @param {Callable} $tick
 * @return Array|null  a failure (code "stopped" when $tick stopped it), or null when the sound is there
 */
function ftConversationalUiTranscribeExtract($command, $target, $deadline, $timeout, $tick) {

    $process = @proc_open($command, array(0 => array("pipe", "r"), 1 => array("pipe", "w"), 2 => array("pipe", "w")), $pipes);

    if (!is_resource($process)) {
        return ftConversationalUiRelayFailure(500, "ffmpeg could not be started on this server.");
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $errors  = "";
    $exit    = null;
    $stopped = null;

    while (true) {
        $errors .= (string)stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);
        if (strlen($errors) > 65536) {
            $errors = substr($errors, -65536);
        }
        $status = proc_get_status($process);
        if (!$status["running"]) {
            // Only the first call after the end tells the exit code.
            $exit = $status["exitcode"];
            break;
        }
        if (call_user_func($tick) === false) {
            $stopped = "stopped";
            break;
        }
        if (microtime(true) > $deadline) {
            $stopped = "timeout";
            break;
        }
        usleep(200000);
    }

    if ($stopped !== null) {
        proc_terminate($process);
        for ($i = 0; $i < 25 && proc_get_status($process)["running"]; $i++) {
            usleep(20000);
        }
        if (proc_get_status($process)["running"]) {
            proc_terminate($process, 9);
        }
    } else {
        $errors .= (string)stream_get_contents($pipes[2]);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if ($stopped === "stopped") {
        return ftConversationalUiRelayFailure(499, "Stopped.", "stopped");
    }
    if ($stopped === "timeout") {
        return ftConversationalUiRelayFailure(504, "The transcription took longer than the " . round($timeout / 60) . " minutes this server allows.", "timeout");
    }
    if ($exit !== 0) {
        $detail = trim(preg_replace('/[^\x09\x0A\x0D\x20-\x7E]+/', "?", substr(trim($errors), -500)));
        return ftConversationalUiRelayFailure(500, "ffmpeg could not take the sound out of the video" . ($detail !== "" ? ": " . $detail : "."));
    }
    if (!is_file($target) || filesize($target) === 0) {
        return ftConversationalUiRelayFailure(500, "ffmpeg found no sound in the video.");
    }

    return null;

}


/**
 * I return the type a file is sent as, by its extension.
 *
 * @method ftConversationalUiTranscribeMime
 * @param {String} $name
 * @return String
 */
function ftConversationalUiTranscribeMime($name) {

    $types = array(
        "mp4" => "video/mp4", "m4v" => "video/mp4", "mov" => "video/quicktime", "webm" => "video/webm",
        "mkv" => "video/x-matroska", "ogv" => "video/ogg", "ogg" => "audio/ogg", "oga" => "audio/ogg",
        "mp3" => "audio/mpeg", "m4a" => "audio/mp4", "aac" => "audio/aac", "wav" => "audio/wav", "flac" => "audio/flac"
    );
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    return isset($types[$extension]) ? $types[$extension] : "application/octet-stream";

}


/**
 * I return the headers of a request to the speech server: its key when it
 * has one, and who is asking (X-FrameTrail-User) from which instance
 * (X-FrameTrail-Instance), which a platform's gateway reads.
 *
 * @method ftConversationalUiTranscribeHeaders
 * @param {Array} $config  ftConversationalUiConfig()'s
 * @param {Array} $user
 * @return Array  lines for CURLOPT_HTTPHEADER
 */
function ftConversationalUiTranscribeHeaders($config, $user) {

    $line = function($value) {
        return preg_replace('/[\x00-\x1F\x7F]+/', " ", (string)$value);
    };

    $headers = array(
        "Accept: application/json",
        // curl would ask for "100 Continue" before the body.
        "Expect:",
        "X-FrameTrail-User: " . $line(ftConversationalUiRelayUserId($user)),
        "X-FrameTrail-Instance: " . $line(ftConversationalUiRelayInstance($config))
    );

    if ($config["transcription"]["apiKey"] !== null) {
        array_unshift($headers, "Authorization: Bearer " . $line($config["transcription"]["apiKey"]));
    }

    return $headers;

}


/**
 * I send the sound to the speech server and collect its answer.
 * $onProgress($sent, $total) is called while it goes and while the server
 * works (about once a second), and stops the request by returning false.
 *
 * Returns array("status" => the HTTP status, 0 when none came, "body",
 * "error" => curl's error number or 0, "stopped" => whether $onProgress
 * stopped it).
 *
 * @method ftConversationalUiTranscribeExchange
 * @param {Array} $transcription
 * @param {Array} $headers
 * @param {Array} $file  array("path", "type", "name")
 * @param {String|null} $language
 * @param {Number} $deadline  microtime(true)
 * @param {Callable} $onProgress
 * @return Array
 */
function ftConversationalUiTranscribeExchange($transcription, $headers, $file, $language, $deadline, $onProgress) {

    $result = array("status" => 0, "body" => "", "error" => 0, "stopped" => false, "retryAfter" => null);

    $fields = array(
        "file"                      => new CURLFile($file["path"], $file["type"], $file["name"]),
        "model"                     => $transcription["model"],
        "response_format"           => "verbose_json",
        "timestamp_granularities[]" => "segment"
    );
    if ($language !== null) {
        $fields["language"] = $language;
    }

    $curl = curl_init($transcription["baseUrl"] . "/audio/transcriptions");

    curl_setopt_array($curl, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => max(1, (int)ceil($deadline - microtime(true))),
        CURLOPT_NOPROGRESS     => false,
        // CURLOPT_XFERINFOFUNCTION is PHP 8.2's name for it.
        CURLOPT_PROGRESSFUNCTION => function($curl, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use (&$result, $onProgress) {
            if (call_user_func($onProgress, (int)$uploaded, (int)$uploadTotal) === false) {
                $result["stopped"] = true;
                return 1;
            }
            return 0;
        },
        CURLOPT_HEADERFUNCTION => function($curl, $line) use (&$result) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) {
                $result["status"] = (int)$match[1];
            } elseif (preg_match('/^retry-after:\s*(\d{1,9})\s*$/i', $line, $match)) {
                $result["retryAfter"] = (int)$match[1];
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function($curl, $chunk) use (&$result) {
            if (strlen($result["body"]) < 64 * 1024 * 1024) {
                $result["body"] .= $chunk;
            }
            return strlen($chunk);
        }
    ));

    curl_exec($curl);

    $result["error"] = $result["stopped"] ? 0 : curl_errno($curl);
    if ($result["error"] !== 0 && $result["status"] >= 200 && $result["status"] < 300) {
        // An answer that broke off before it was whole is no answer.
        $result["status"] = 0;
    }

    curl_close($curl);

    return $result;

}


/**
 * I read the speech server's answer: the language it heard, the duration,
 * and the segments with their times and text (what else a segment carries,
 * tokens and scores, is left out), or a failure:
 *
 * - no answer: 502 (504 "timeout" when it took too long);
 * - 401/403: the server's key refused (notConfigured);
 * - 413: tooLarge;
 * - another status: its words, the key taken out;
 * - an answer without segments: a server that does not do verbose_json.
 *
 * @method ftConversationalUiTranscribeResult
 * @param {Array} $result  ftConversationalUiTranscribeExchange()'s
 * @param {Array} $transcription
 * @param {Number} $offset  the clip's in point when only its span was sent
 * @return Array  a failure, or array("language", "duration", "offset", "segments")
 */
function ftConversationalUiTranscribeResult($result, $transcription, $offset) {

    if ($result["status"] === 0) {
        return ($result["error"] === 28)
            ? ftConversationalUiRelayFailure(504, "The transcription took longer than the " . round($transcription["timeout"] / 60) . " minutes this server allows.", "timeout")
            : ftConversationalUiRelayFailure(502, "The speech server could not be reached.");
    }

    $status = $result["status"];

    // A refusal in the relay's own words (a gateway's quota, say) goes through
    // as it came, whatever its status.
    if ($status < 200 || $status >= 300) {
        $text  = ($transcription["apiKey"] !== null) ? str_replace($transcription["apiKey"], "[key]", (string)$result["body"]) : (string)$result["body"];
        $coded = ftConversationalUiRelayCodedError(json_decode($text, true));
        if ($coded !== null) {
            return ftConversationalUiRelayFailure($status >= 400 ? $status : 502, $coded["message"], $coded["code"],
                isset($result["retryAfter"]) ? $result["retryAfter"] : null, $coded["extra"]);
        }
    }

    if ($status === 401 || $status === 403) {
        return ftConversationalUiRelayFailure(502, "The speech server refused the server's key.", "notConfigured");
    }
    if ($status === 413) {
        return ftConversationalUiRelayFailure(413, "The speech server refused the sound as too large.", "tooLarge");
    }

    if ($status < 200 || $status >= 300) {
        $text = (string)$result["body"];
        if ($transcription["apiKey"] !== null) {
            $text = str_replace($transcription["apiKey"], "[key]", $text);
        }
        $json   = json_decode($text, true);
        $words  = (is_array($json) && isset($json["error"]["message"]) && is_string($json["error"]["message"])) ? $json["error"]["message"]
                : ((is_array($json) && isset($json["detail"]) && is_string($json["detail"])) ? $json["detail"]
                : ((is_array($json) && isset($json["message"]) && is_string($json["message"])) ? $json["message"] : strip_tags($text)));
        $detail = trim(preg_replace('/\s+/', " ", preg_replace('/[^\x09\x0A\x0D\x20-\x7E]+/', "?", substr($words, 0, 300))));
        return ftConversationalUiRelayFailure($status >= 400 ? $status : 502,
            "The speech server answered with status " . $status . ($detail !== "" ? ": " . $detail : "."));
    }

    $answer = json_decode($result["body"], true);
    if (!is_array($answer) || !isset($answer["segments"]) || !is_array($answer["segments"])) {
        return ftConversationalUiRelayFailure(502, "The speech server's answer has no segments; it needs to support response_format verbose_json.");
    }

    $segments = array();
    foreach ($answer["segments"] as $segment) {
        if (is_array($segment) && isset($segment["start"], $segment["end"], $segment["text"])
            && (is_int($segment["start"]) || is_float($segment["start"])) && (is_int($segment["end"]) || is_float($segment["end"]))
            && is_string($segment["text"])) {
            $segments[] = array("start" => (float)$segment["start"], "end" => (float)$segment["end"], "text" => $segment["text"]);
        }
    }

    return array(
        "language" => (isset($answer["language"]) && is_string($answer["language"]) && trim($answer["language"]) !== "") ? trim($answer["language"]) : null,
        "duration" => (isset($answer["duration"]) && (is_int($answer["duration"]) || is_float($answer["duration"]))) ? (float)$answer["duration"] : null,
        "offset"   => (float)$offset,
        "segments" => $segments
    );

}


/**
 * I return the id of the job a gateway answered with (202 { id }), or null
 * for an answer that is not one. Only ids of letters, digits, "-" and "_":
 * the id becomes part of the URL asked next.
 *
 * @method ftConversationalUiTranscribeJobId
 * @param {Array} $result  ftConversationalUiTranscribeExchange()'s
 * @return String|null
 */
function ftConversationalUiTranscribeJobId($result) {

    if ($result["status"] !== 202) {
        return null;
    }

    $answer = json_decode($result["body"], true);

    return (is_array($answer) && isset($answer["id"]) && is_string($answer["id"]) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $answer["id"]))
        ? $answer["id"] : null;

}


/**
 * I ask the speech server something short about a job (GET) or cancel it
 * (DELETE). Returns array("status" => 0 when no answer came, "body",
 * "error" => curl's error number, "retryAfter").
 *
 * @method ftConversationalUiTranscribeCall
 * @param {String} $method
 * @param {String} $url
 * @param {Array} $headers
 * @param {Number} $timeout  seconds
 * @return Array
 */
function ftConversationalUiTranscribeCall($method, $url, $headers, $timeout) {

    $result = array("status" => 0, "body" => "", "error" => 0, "retryAfter" => null);

    $curl = curl_init($url);
    curl_setopt_array($curl, array(
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => max(1, (int)$timeout),
        CURLOPT_HEADERFUNCTION => function($curl, $line) use (&$result) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) {
                $result["status"] = (int)$match[1];
            } elseif (preg_match('/^retry-after:\s*(\d{1,9})\s*$/i', $line, $match)) {
                $result["retryAfter"] = (int)$match[1];
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function($curl, $chunk) use (&$result) {
            if (strlen($result["body"]) < 64 * 1024 * 1024) {
                $result["body"] .= $chunk;
            }
            return strlen($chunk);
        }
    ));

    curl_exec($curl);
    $result["error"] = curl_errno($curl);
    curl_close($curl);

    return $result;

}


/**
 * I follow a gateway's job until it is done, reporting its place in the queue
 * and then its work through $onProgress($stage, $extra, $gap), which returns
 * false once the browser has gone; the job is then cancelled, as it is when
 * the deadline passes. The URL is made of baseUrl and the id, never taken
 * from the answer.
 *
 * Returns what ftConversationalUiTranscribeResult() reads — the job's result
 * as a 200, a refusal as its status and body — or a failure, or
 * array("stopped" => true).
 *
 * @method ftConversationalUiTranscribeFollow
 * @param {Array} $transcription
 * @param {Array} $headers
 * @param {String} $id
 * @param {Number} $deadline  microtime(true)
 * @param {Callable} $onProgress
 * @param {Number} $every  seconds between two looks at the job
 * @return Array
 */
function ftConversationalUiTranscribeFollow($transcription, $headers, $id, $deadline, $onProgress, $every = 3) {

    $url      = $transcription["baseUrl"] . "/audio/transcriptions/" . rawurlencode($id);
    $position = null;
    $misses   = 0;

    $cancel = function() use ($url, $headers) {
        ftConversationalUiTranscribeCall("DELETE", $url, $headers, 10);
    };

    while (true) {

        if (microtime(true) >= $deadline) {
            $cancel();
            return array("status" => 0, "body" => "", "error" => 28, "stopped" => false);
        }

        $poll = ftConversationalUiTranscribeCall("GET", $url, $headers, 30);

        if ($poll["status"] === 0 || $poll["status"] >= 500) {
            // The gateway may be restarting; the job is still there.
            if (++$misses >= 5) {
                $cancel();
                return ftConversationalUiRelayFailure(502, "The speech server could not be reached.");
            }
        } elseif ($poll["status"] !== 200) {
            return array("status" => $poll["status"], "body" => $poll["body"], "error" => 0, "stopped" => false, "retryAfter" => $poll["retryAfter"]);
        } else {
            $misses = 0;
            $job    = json_decode($poll["body"], true);
            $state  = (is_array($job) && isset($job["status"]) && is_string($job["status"])) ? $job["status"] : "";

            if ($state === "completed") {
                $answer = (isset($job["result"]) && is_array($job["result"])) ? $job["result"] : array();
                return array("status" => 200, "body" => json_encode($answer), "error" => 0, "stopped" => false);
            }
            if ($state === "failed") {
                $error = (isset($job["error"]) && is_array($job["error"])) ? $job["error"] : array();
                $coded = ftConversationalUiRelayCodedError(array("error" => $error));
                if ($coded !== null) {
                    return ftConversationalUiRelayFailure(502, $coded["message"], $coded["code"], null, $coded["extra"]);
                }
                return ftConversationalUiRelayFailure(502, (isset($error["message"]) && is_string($error["message"]) && $error["message"] !== "")
                    ? substr($error["message"], 0, 500) : "The transcription failed on the speech server.");
            }
            if ($state === "cancelled") {
                return ftConversationalUiRelayFailure(502, "The transcription was cancelled on the speech server.");
            }

            if ($state === "queued") {
                $now = (isset($job["position"]) && is_int($job["position"])) ? $job["position"] : null;
                if ($onProgress("queued", ($now !== null) ? array("position" => $now) : array(), ($now !== $position) ? 0 : null) === false) {
                    $cancel();
                    return array("stopped" => true);
                }
                $position = $now;
            } elseif ($onProgress("transcribing", array(), null) === false) {
                $cancel();
                return array("stopped" => true);
            }
        }

        // Until the next look, the browser is kept informed (and checked on).
        $until = microtime(true) + $every;
        while (microtime(true) < $until) {
            usleep(250000);
            if ($onProgress(null, array(), null) === false) {
                $cancel();
                return array("stopped" => true);
            }
        }

    }

}


/**
 * I send one server-sent event.
 *
 * @method ftConversationalUiTranscribeEvent
 * @param {String} $name
 * @param {Array} $data
 */
function ftConversationalUiTranscribeEvent($name, $data) {

    echo "event: " . $name . "\ndata: " . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n\n";
    flush();

}


/**
 * Route transcribe: the video of a hypervideo transcribed, its progress and
 * result streamed. See the top of this file.
 *
 * @method ftConversationalUiTranscribe
 * @param {Array} $ext
 * @return null  the answer is written here
 */
function ftConversationalUiTranscribe($ext) {

    $refused = ftConversationalUiRouteRefusal();
    if ($refused !== null) {
        ftConversationalUiRelaySendFailure($refused);
        return null;
    }

    $admitted = ftConversationalUiTranscribeAdmit(file_get_contents("php://input"));
    if (isset($admitted["failure"])) {
        ftConversationalUiRelaySendFailure($admitted);
        return null;
    }

    ftConversationalUiRelayReleaseSession();

    $transcription = $admitted["transcription"];
    $source        = $admitted["source"];
    $deadline      = microtime(true) + $transcription["timeout"];
    $interval      = ftConversationalUiTranscribeInterval();

    ftConversationalUiStreamOpen($transcription["timeout"] + 60);

    http_response_code(200);
    header("Content-Type: text/event-stream; charset=utf-8");
    header("Cache-Control: no-cache, no-transform");
    header("X-Accel-Buffering: no");

    // Progress: with every new stage, and then at least every $interval
    // seconds ($gap: more often); between, a comment, which is how a browser
    // that has gone is noticed (connection_aborted() knows only after
    // something was sent).
    // A stage of null keeps the stage and what was told with it (a job's place in line).
    $state = array("stage" => null, "extra" => array(), "since" => 0.0, "last" => 0.0, "sent" => 0.0, "gone" => false);

    $progress = function($stage, $extra = array(), $gap = null) use (&$state, $interval) {
        $now = microtime(true);
        if ($stage === null) {
            $stage = $state["stage"];
            $extra = $state["extra"];
        }
        $state["extra"] = $extra;
        $new = $state["stage"] !== $stage;
        if ($new) {
            $state["stage"] = $stage;
            $state["since"] = $now;
        }
        if ($new || $now - $state["last"] >= ($gap !== null ? $gap : $interval)) {
            $state["last"] = $state["sent"] = $now;
            ftConversationalUiTranscribeEvent("progress", array_merge(array("stage" => $stage, "elapsed" => (int)floor($now - $state["since"])), $extra));
        } elseif ($now - $state["sent"] >= $interval / 5) {
            $state["sent"] = $now;
            echo ": keep-alive\n\n";
            flush();
        } else {
            return !$state["gone"];
        }
        if (connection_aborted()) {
            $state["gone"] = true;
        }
        return !$state["gone"];
    };

    $fail = function($failure) {
        if ($failure["code"] !== "stopped") {
            ftConversationalUiTranscribeEvent("error", ftConversationalUiRelayFailureBody($failure));
        }
        return null;
    };

    $offset = 0.0;

    if ($admitted["ffmpeg"] !== null) {

        $format = $transcription["audioFormat"];
        $target = ftConversationalUiTranscribeTempFile($format);

        $progress("extracting");

        $failure = ftConversationalUiTranscribeExtract(ftConversationalUiTranscribeCommand($admitted["ffmpeg"], $source, $format, $target),
            $target, $deadline, $transcription["timeout"], function() use ($progress) { return $progress("extracting"); });
        if ($failure !== null) {
            @unlink($target);
            return $fail($failure);
        }

        clearstatcache(true, $target);
        if (filesize($target) > $transcription["maxBytes"]) {
            $size = filesize($target);
            @unlink($target);
            return $fail(ftConversationalUiRelayFailure(413, "The sound of the video (" . ftConversationalUiTranscribeMegabytes($size) . ") is larger than the "
                . ftConversationalUiTranscribeMegabytes($transcription["maxBytes"]) . " that may be sent.", "tooLarge"));
        }

        $types  = array("mp3" => "audio/mpeg", "flac" => "audio/flac", "wav" => "audio/wav");
        $file   = array("path" => $target, "type" => $types[$format], "name" => "audio." . $format);
        $offset = $source["in"];

    } else {

        $file = array("path" => $source["path"], "type" => ftConversationalUiTranscribeMime($source["name"]), "name" => $source["name"]);

    }

    $progress("sending", array("sent" => 0, "total" => (int)filesize($file["path"])));

    $result = ftConversationalUiTranscribeExchange($transcription,
        ftConversationalUiTranscribeHeaders($admitted["config"], $admitted["user"]),
        $file,
        $admitted["language"],
        $deadline,
        function($sent, $total) use ($progress) {
            if ($total > 0 && $sent >= $total) {
                return $progress("transcribing");
            }
            return $progress("sending", array("sent" => $sent, "total" => $total), 1);
        });

    // The sound is no longer needed, however it went.
    if ($admitted["ffmpeg"] !== null) {
        @unlink($file["path"]);
    }

    if ($result["stopped"]) {
        return null;
    }

    // A gateway's job: followed until it is done.
    $job = ftConversationalUiTranscribeJobId($result);
    if ($job !== null) {
        $result = ftConversationalUiTranscribeFollow($transcription,
            ftConversationalUiTranscribeHeaders($admitted["config"], $admitted["user"]),
            $job, $deadline, $progress,
            defined("FT_CONVERSATIONAL_UI_POLL") ? FT_CONVERSATIONAL_UI_POLL : 3);
        if (isset($result["failure"])) {
            return $fail($result);
        }
        if (!empty($result["stopped"])) {
            return null;
        }
    }

    $answer = ftConversationalUiTranscribeResult($result, $transcription, $offset);
    if (isset($answer["failure"])) {
        return $fail($answer);
    }

    ftConversationalUiTranscribeEvent("result", $answer);

    return null;

}
