<?php

/*
 * Tests of the relay: a stand-in for Mistral's Chat Completions API
 * (POST /v1/chat/completions), run by tests/run-php.php with PHP's built-in
 * server. What it answers depends on the request's model:
 *
 *     stream-ok      a completion; streamed: three pieces 300 ms apart, then [DONE]
 *     echo           a completion whose text tells what arrived: a hash of the
 *                    Authorization header and of the body, the X-FrameTrail-*
 *                    headers, Accept, Expect
 *     long           twenty pieces 200 ms apart; writes how many it sent to the
 *                    file in RELAY_UPSTREAM_LOG, and stops when the reader is gone
 *     bad-key        401, as Mistral refuses a key
 *     rate           429 with Retry-After: 7
 *     zero           429 with x-ratelimit-limit-req-minute: 0 (a model the key may never use)
 *     gateway-quota  429 in the relay's own words ({ error: { code: "quota" } }), as a gateway may answer
 *     broken         500
 *     leak           400 whose message repeats the Authorization header it got
 *     html           502 with a proxy's HTML page
 *     anything else  400, an unknown model
 *
 * And a stand-in for a Whisper server's OpenAI-compatible transcription API
 * (POST /v1/audio/transcriptions, multipart), by the form's model:
 *
 *     whisper-ok          verbose_json: two segments (with tokens and scores)
 *                         and one that is not, language " en ", duration 5
 *
 * What arrived — the form's fields, the file's name, type, size and sha1, a
 * hash of Authorization, the X-FrameTrail-* headers, Accept, Expect — is
 * written to seen.json in RELAY_UPSTREAM_DIR, the file to upload.bin there,
 * when RELAY_UPSTREAM_DIR is set; and is the "text" of whisper-ok's answer.
 *     whisper-slow        as whisper-ok after 3 seconds (a space every 200 ms);
 *                         writes how many it sent to RELAY_UPSTREAM_LOG, and
 *                         stops when the reader is gone
 *     whisper-401         401, a key refused
 *     whisper-413         413, too large
 *     whisper-500         500 with { "detail": … }
 *     whisper-html        502 with a proxy's HTML page
 *     whisper-nosegments  200 with { "text" } only (a server without verbose_json)
 *     whisper-leak        400 whose detail repeats the Authorization header
 *     whisper-quota       429 in the relay's own words: { error: { code: "quota",
 *                         period: "month", resetsAt } }, Retry-After: 3600 — a
 *                         gateway whose allowance is used up
 *     whisper-notallowed  403 in the relay's own words (notAllowed)
 *
 * And a gateway's jobs (202 { id }, then GET/DELETE /v1/audio/transcriptions/{id}),
 * the job's id naming what it does:
 *
 *     whisper-job         queued (position 2, then 1), running, then completed
 *                         with whisper-ok's answer
 *     whisper-job-fail    failed: { message }
 *     whisper-job-quota   failed in the relay's own words (quota, month)
 *     whisper-job-wait    queued for ever
 *     whisper-job-badid   202 with an id that is not one ("../x")
 *
 * Every look at a job is counted in job-<id>.polls in RELAY_UPSTREAM_DIR, a
 * DELETE writes job-<id>.deleted there.
 */

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

if ($path === "/v1/audio/transcriptions" && $_SERVER["REQUEST_METHOD"] === "POST") {
    upstreamTranscription();
    return;
}

if (preg_match('#^/v1/audio/transcriptions/([A-Za-z0-9_-]+)$#', $path, $match)) {
    upstreamJob($match[1], $_SERVER["REQUEST_METHOD"]);
    return;
}

if ($path !== "/v1/chat/completions" || $_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(404);
    header("Content-Type: application/json");
    echo '{"message":"Not Found"}';
    return;
}

$raw     = file_get_contents("php://input");
$request = json_decode($raw, true);
$model   = (is_array($request) && isset($request["model"])) ? $request["model"] : "";
$stream  = is_array($request) && !empty($request["stream"]);


function upstreamError($status, $body, $headers = array()) {
    http_response_code($status);
    header("Content-Type: application/json");
    foreach ($headers as $header) {
        header($header);
    }
    echo json_encode($body);
}

function upstreamCompletion($model, $text) {
    header("Content-Type: application/json");
    echo json_encode(array(
        "id"       => "cmpl-1",
        "object"   => "chat.completion",
        "model"    => $model,
        "created"  => 1791393436,
        "choices"  => array(array("index" => 0, "message" => array("role" => "assistant", "content" => $text, "tool_calls" => null), "finish_reason" => "stop")),
        "usage"    => array("prompt_tokens" => 3, "completion_tokens" => 2, "total_tokens" => 5),
        "metadata" => new stdClass()
    ));
}

function upstreamStream($model, $pieces, $pause, $log = null) {
    ignore_user_abort(true);
    // php.ini's output_buffering would hold the pieces back until the end.
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    header("Content-Type: text/event-stream");
    header("Cache-Control: no-cache");
    $sent = 0;
    foreach ($pieces as $index => $piece) {
        echo "data: " . json_encode(array(
            "id"      => "cmpl-1",
            "object"  => "chat.completion.chunk",
            "model"   => $model,
            "choices" => array(array("index" => 0, "delta" => array("content" => $piece), "finish_reason" => ($index === count($pieces) - 1) ? "stop" : null))
        )) . "\n\n";
        flush();
        $sent++;
        if ($log !== null) {
            file_put_contents($log, (string)$sent);
        }
        if (connection_aborted()) {
            return;
        }
        usleep($pause);
    }
    echo "data: [DONE]\n\n";
    flush();
}

$mistralError = function($message, $type, $code) {
    return array("object" => "error", "message" => $message, "type" => $type, "param" => null, "code" => $code);
};

switch ($model) {

    case "stream-ok":
        if ($stream) {
            upstreamStream($model, array("Hel", "lo", " there"), 300000);
        } else {
            upstreamCompletion($model, "Hello there");
        }
        break;

    case "echo":
        $seen = json_encode(array(
            "authorization" => isset($_SERVER["HTTP_AUTHORIZATION"]) ? sha1($_SERVER["HTTP_AUTHORIZATION"]) : null,
            "body"          => sha1($raw),
            "user"          => isset($_SERVER["HTTP_X_FRAMETRAIL_USER"]) ? $_SERVER["HTTP_X_FRAMETRAIL_USER"] : null,
            "instance"      => isset($_SERVER["HTTP_X_FRAMETRAIL_INSTANCE"]) ? $_SERVER["HTTP_X_FRAMETRAIL_INSTANCE"] : null,
            "accept"        => isset($_SERVER["HTTP_ACCEPT"]) ? $_SERVER["HTTP_ACCEPT"] : null,
            "expect"        => isset($_SERVER["HTTP_EXPECT"]) ? $_SERVER["HTTP_EXPECT"] : null
        ));
        if ($stream) {
            upstreamStream($model, array($seen), 0);
        } else {
            upstreamCompletion($model, $seen);
        }
        break;

    case "long":
        $pieces = array();
        for ($i = 1; $i <= 20; $i++) {
            $pieces[] = "piece " . $i . " ";
        }
        upstreamStream($model, $pieces, 200000, getenv("RELAY_UPSTREAM_LOG") ?: null);
        break;

    case "bad-key":
        upstreamError(401, array("message" => "Unauthorized", "request_id" => "r1"));
        break;

    case "rate":
        upstreamError(429, $mistralError("Rate limit exceeded", "rate_limited", "1300"), array("Retry-After: 7"));
        break;

    case "zero":
        upstreamError(429, $mistralError("Rate limit exceeded", "rate_limited", "1300"), array("x-ratelimit-limit-req-minute: 0", "x-ratelimit-remaining-req-minute: 0"));
        break;

    case "gateway-quota":
        upstreamError(429, array("error" => array("code" => "quota", "message" => "The project's monthly credits are used up.")), array("Retry-After: 3600"));
        break;

    case "broken":
        upstreamError(500, $mistralError("Internal server error", "internal_error", "1000"));
        break;

    case "leak":
        upstreamError(400, array("message" => "Refused: " . (isset($_SERVER["HTTP_AUTHORIZATION"]) ? $_SERVER["HTTP_AUTHORIZATION"] : "")));
        break;

    case "html":
        http_response_code(502);
        header("Content-Type: text/html; charset=utf-8");
        echo "<html><body><h1>502 Bad Gateway – proxy</h1></body></html>";
        break;

    default:
        upstreamError(400, $mistralError("Invalid model: " . $model, "invalid_model", "1500"));

}


// A gateway's job, looked at (GET) or cancelled (DELETE).
function upstreamJob($id, $method) {

    $dir   = getenv("RELAY_UPSTREAM_DIR") ?: sys_get_temp_dir();
    $kind  = preg_replace('/_\d+$/', "", $id);
    $polls = (int)@file_get_contents($dir . "/job-" . $id . ".polls");

    header("Content-Type: application/json");

    if ($method === "DELETE") {
        file_put_contents($dir . "/job-" . $id . ".deleted", "1");
        echo json_encode(array("id" => $id, "status" => "cancelled"));
        return;
    }

    file_put_contents($dir . "/job-" . $id . ".polls", (string)($polls + 1));

    switch ($kind) {
        case "whisper-job":
            if ($polls < 2) {
                echo json_encode(array("id" => $id, "status" => "queued", "position" => 2 - $polls));
            } elseif ($polls < 3) {
                echo json_encode(array("id" => $id, "status" => "running", "startedAt" => "2026-10-09T12:00:00+02:00"));
            } else {
                echo json_encode(array("id" => $id, "status" => "completed", "result" => json_decode((string)@file_get_contents($dir . "/job-" . $id . ".result"), true)));
            }
            return;
        case "whisper-job-fail":
            echo json_encode(array("id" => $id, "status" => "failed", "error" => array("message" => "The speech service answered with status 422: Unsupported audio")));
            return;
        case "whisper-job-quota":
            echo json_encode(array("id" => $id, "status" => "failed", "error" => array("code" => "quota", "message" => "This month's minutes are used up.", "period" => "month", "resetsAt" => "2026-11-01T00:00:00+01:00")));
            return;
        case "whisper-job-wait":
            echo json_encode(array("id" => $id, "status" => "queued", "position" => 4));
            return;
    }

    http_response_code(404);
    echo json_encode(array("error" => array("message" => "There is no such transcription.")));

}

function upstreamTranscription() {

    $model = isset($_POST["model"]) ? $_POST["model"] : "";
    $file  = isset($_FILES["file"]) ? $_FILES["file"] : null;
    $auth  = isset($_SERVER["HTTP_AUTHORIZATION"]) ? $_SERVER["HTTP_AUTHORIZATION"] : null;

    $seen = array(
        "model"                   => $model,
        "response_format"         => isset($_POST["response_format"]) ? $_POST["response_format"] : null,
        "timestamp_granularities" => isset($_POST["timestamp_granularities"]) ? $_POST["timestamp_granularities"] : null,
        "language"                => isset($_POST["language"]) ? $_POST["language"] : null,
        "fields"                  => array_keys($_POST),
        "name"                    => $file ? $file["name"] : null,
        "type"                    => $file ? $file["type"] : null,
        "size"                    => ($file && $file["error"] === UPLOAD_ERR_OK) ? filesize($file["tmp_name"]) : null,
        "sha1"                    => ($file && $file["error"] === UPLOAD_ERR_OK) ? sha1_file($file["tmp_name"]) : null,
        "error"                   => $file ? $file["error"] : null,
        "authorization"           => ($auth !== null) ? sha1($auth) : null,
        "user"                    => isset($_SERVER["HTTP_X_FRAMETRAIL_USER"]) ? $_SERVER["HTTP_X_FRAMETRAIL_USER"] : null,
        "instance"                => isset($_SERVER["HTTP_X_FRAMETRAIL_INSTANCE"]) ? $_SERVER["HTTP_X_FRAMETRAIL_INSTANCE"] : null,
        "accept"                  => isset($_SERVER["HTTP_ACCEPT"]) ? $_SERVER["HTTP_ACCEPT"] : null,
        "expect"                  => isset($_SERVER["HTTP_EXPECT"]) ? $_SERVER["HTTP_EXPECT"] : null
    );

    if (getenv("RELAY_UPSTREAM_DIR")) {
        file_put_contents(getenv("RELAY_UPSTREAM_DIR") . "/seen.json", json_encode($seen));
        if ($file && $file["error"] === UPLOAD_ERR_OK) {
            copy($file["tmp_name"], getenv("RELAY_UPSTREAM_DIR") . "/upload.bin");
        }
    }

    $verbose = array(
        "task"     => "transcribe",
        "language" => " en ",
        "duration" => 5,
        "text"     => json_encode($seen),
        "words"    => null,
        "segments" => array(
            array("id" => 0, "seek" => 0, "start" => 0, "end" => 2.5, "text" => " Hello.", "tokens" => array(50364, 2425, 13), "temperature" => 0, "avg_logprob" => -0.2, "compression_ratio" => 0.8, "no_speech_prob" => 0.01, "words" => null),
            array("id" => 1, "seek" => 0, "start" => 2.5, "end" => 5, "text" => " World & <more>.", "tokens" => array(3937), "temperature" => 0, "avg_logprob" => -0.3, "compression_ratio" => 0.8, "no_speech_prob" => 0.02, "words" => null),
            array("id" => 2, "start" => "x", "end" => 6, "text" => "not a segment")
        )
    );

    switch ($model) {

        case "whisper-ok":
            header("Content-Type: application/json");
            echo json_encode($verbose);
            break;

        case "whisper-slow":
            ignore_user_abort(true);
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            header("Content-Type: application/json");
            $log = getenv("RELAY_UPSTREAM_LOG") ?: null;
            for ($sent = 1; $sent <= 15; $sent++) {
                echo " ";
                flush();
                if ($log !== null) {
                    file_put_contents($log, (string)$sent);
                }
                if (connection_aborted()) {
                    return;
                }
                usleep(200000);
            }
            echo json_encode($verbose);
            break;

        case "whisper-401":
            upstreamError(401, array("detail" => "Unauthorized"));
            break;

        case "whisper-413":
            upstreamError(413, array("detail" => "Request entity too large"));
            break;

        case "whisper-500":
            upstreamError(500, array("detail" => "The model is not loaded"));
            break;

        case "whisper-html":
            http_response_code(502);
            header("Content-Type: text/html; charset=utf-8");
            echo "<html><body><h1>502 Bad Gateway – proxy</h1></body></html>";
            break;

        case "whisper-nosegments":
            header("Content-Type: application/json");
            echo json_encode(array("text" => "Hello"));
            break;

        case "whisper-leak":
            upstreamError(400, array("detail" => "Refused: " . (string)$auth));
            break;

        case "whisper-quota":
            upstreamError(429, array("error" => array("code" => "quota", "message" => "This month's minutes are used up.", "period" => "month", "resetsAt" => "2026-11-01T00:00:00+01:00")), array("Retry-After: 3600"));
            break;

        case "whisper-notallowed":
            upstreamError(403, array("error" => array("code" => "notAllowed", "message" => "Transcription is not part of this project's plan.")));
            break;

        case "whisper-job":
        case "whisper-job-fail":
        case "whisper-job-quota":
        case "whisper-job-wait":
            $id  = $model . "_" . mt_rand(1000, 999999);
            $dir = getenv("RELAY_UPSTREAM_DIR") ?: sys_get_temp_dir();
            file_put_contents($dir . "/job-" . $id . ".result", json_encode($verbose));
            file_put_contents($dir . "/last-job", $id);
            http_response_code(202);
            header("Content-Type: application/json");
            echo json_encode(array("id" => $id, "status" => "queued", "position" => 2));
            break;

        case "whisper-job-badid":
            http_response_code(202);
            header("Content-Type: application/json");
            echo json_encode(array("id" => "../x", "status" => "queued"));
            break;

        default:
            upstreamError(404, array("detail" => "Model " . $model . " is not installed"));

    }

}
