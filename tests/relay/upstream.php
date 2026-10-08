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
 */

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

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
