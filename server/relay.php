<?php

/*
 * FrameTrail-Conversational-UI — the model relay: Mistral's Chat Completions
 * API through this server, which holds the key (server mode). The chat panel
 * and the relay speak the same API; the relay passes requests and answers
 * through as they are, and decides only who may send them, for which model,
 * and how many a day.
 *
 * - Route relay (_server/extension.php?e=conversational-ui&r=relay): POST the
 *   request as JSON ("stream": true); the answer is the model's stream of
 *   server-sent events, or an error with the model service's status and body.
 * - Action conversationalUiChat: the request as JSON text in "request"
 *   ("stream": false or none); the answer is
 *       { status: "success", code: 0, response: <the completion> }
 *       { status: "fail", code: <HTTP status>, string, upstream: <the service's error body>, retryAfter? }
 *       { status: "fail", code: <HTTP status>, string, error: { code?, message }, retryAfter? }
 * - The relay's own refusals carry error.code: login (401: not signed in),
 *   notAllowed (403: an inactive account, a personal API token), quota (429:
 *   the day's requests used up, Retry-After until midnight), notConfigured
 *   (503: no key, no curl, an invalid baseUrl; 502: the service refused the
 *   server's key). Requests it cannot pass on: 400 (not a JSON object, the
 *   wrong "stream", a model it does not allow), 405, 413, 415.
 *
 * The configuration (key, baseUrl, models, limit, timeout) is
 * ftConversationalUiConfig() in extension.php. The count of the day's
 * requests is _data/.extensions/conversational-ui/usage.json.
 */

// Only ever run by FrameTrail's routers, through extension.php.
if (!function_exists("ftExtensionStorage")) {
    http_response_code(404);
    exit;
}


/**
 * I make a failure of the relay: an HTTP status, the words for it, and, for
 * the relay's own refusals, a code the chat panel puts into words (login,
 * notAllowed, quota, notConfigured).
 *
 * @method ftConversationalUiRelayFailure
 * @param {Number} $status
 * @param {String} $message
 * @param {String|null} $code
 * @param {Number|null} $retryAfter  seconds
 * @return Array
 */
function ftConversationalUiRelayFailure($status, $message, $code = null, $retryAfter = null) {

    return array(
        "failure"    => true,
        "status"     => $status,
        "message"    => $message,
        "code"       => $code,
        "retryAfter" => $retryAfter
    );

}


/**
 * I return the body a failure is sent with: { "error": { "code"?, "message" } }.
 *
 * @method ftConversationalUiRelayFailureBody
 * @param {Array} $failure
 * @return Array
 */
function ftConversationalUiRelayFailureBody($failure) {

    $error = array("message" => $failure["message"]);
    if ($failure["code"] !== null) {
        $error = array("code" => $failure["code"], "message" => $failure["message"]);
    }

    return array("error" => $error);

}


/**
 * I return the id the relay tells a gateway who is asking by: the platform's
 * subject when the instance uses external authentication and the account
 * carries one, else the local user id.
 *
 * @method ftConversationalUiRelayUserId
 * @param {Array} $user  the user record userCheckLogin() gives
 * @return String
 */
function ftConversationalUiRelayUserId($user) {

    $external = function_exists("ftExternalAuthEnabled") && ftExternalAuthEnabled();

    if ($external && isset($user["external"]["sub"]) && (is_string($user["external"]["sub"]) || is_int($user["external"]["sub"]))) {
        return (string)$user["external"]["sub"];
    }

    return isset($user["id"]) ? (string)$user["id"] : "";

}


/**
 * I return what the relay tells a gateway the instance is: the configured
 * "instance", else the URL of the FrameTrail installation.
 *
 * @method ftConversationalUiRelayInstance
 * @param {Array} $config
 * @return String
 */
function ftConversationalUiRelayInstance($config) {

    if ($config["instance"] !== null) {
        return $config["instance"];
    }

    $https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
          || (isset($_SERVER["HTTP_X_FORWARDED_PROTO"]) && $_SERVER["HTTP_X_FORWARDED_PROTO"] === "https");
    $host  = isset($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : "localhost";
    $path  = isset($_SERVER["SCRIPT_NAME"]) ? str_replace("\\", "/", dirname(dirname($_SERVER["SCRIPT_NAME"]))) : "";

    return ($https ? "https" : "http") . "://" . $host . rtrim($path, "/") . "/";

}


/**
 * I say whether a base URL is Mistral's own API, which gets no identity
 * headers: it has no use for them.
 *
 * @method ftConversationalUiRelayIsMistral
 * @param {String} $baseUrl
 * @return Boolean
 */
function ftConversationalUiRelayIsMistral($baseUrl) {

    $host = parse_url($baseUrl, PHP_URL_HOST);

    return is_string($host) && strtolower($host) === "api.mistral.ai";

}


/**
 * I return the headers of a request to the model service: the key, the
 * types, and for a gateway (any baseUrl but Mistral's) who is asking
 * (X-FrameTrail-User) from which instance (X-FrameTrail-Instance).
 *
 * @method ftConversationalUiRelayHeaders
 * @param {Array} $config
 * @param {Array} $user
 * @param {Boolean} $stream
 * @return Array  lines for CURLOPT_HTTPHEADER
 */
function ftConversationalUiRelayHeaders($config, $user, $stream) {

    $line = function($value) {
        return preg_replace('/[\x00-\x1F\x7F]+/', " ", (string)$value);
    };

    $headers = array(
        "Authorization: Bearer " . $line($config["apiKey"]),
        "Content-Type: application/json",
        "Accept: " . ($stream ? "text/event-stream" : "application/json"),
        // curl would ask for "100 Continue" before a body over 1 KB.
        "Expect:"
    );

    if (!ftConversationalUiRelayIsMistral($config["baseUrl"])) {
        $headers[] = "X-FrameTrail-User: " . $line(ftConversationalUiRelayUserId($user));
        $headers[] = "X-FrameTrail-Instance: " . $line(ftConversationalUiRelayInstance($config));
    }

    return $headers;

}


/**
 * I count a request to the model against the user's limit for the day
 * (calendar days in the server's time zone), or refuse it when the day's
 * requests are used up. The counts are in usage.json in the add-on's private
 * folder: { "date": "2026-10-08", "users": { "<user id>": <requests> } }.
 *
 * @method ftConversationalUiRelayCount
 * @param {String} $userId
 * @param {Number|null} $limit  null: no limit, nothing counted
 * @param {Number} $now  a Unix time (the clock by default)
 * @return Array|null  a failure, or null when the request may go
 */
function ftConversationalUiRelayCount($userId, $limit, $now = null) {

    if ($limit === null) {
        return null;
    }

    $now = ($now === null) ? time() : $now;
    $dir = ftExtensionStorage("conversational-ui");

    // A limit that cannot be kept is not a limit: refuse rather than relay uncounted.
    $handle = ($dir !== false) ? @fopen($dir . "/usage.json", "c+") : false;
    if ($handle === false) {
        return ftConversationalUiRelayFailure(503, "The relay cannot keep count of the requests on this server.", "notConfigured");
    }

    flock($handle, LOCK_EX);

    $usage = json_decode(stream_get_contents($handle), true);
    $today = date("Y-m-d", $now);

    if (!is_array($usage) || !isset($usage["date"], $usage["users"]) || $usage["date"] !== $today || !is_array($usage["users"])) {
        $usage = array("date" => $today, "users" => array());
    }

    $used = isset($usage["users"][$userId]) ? (int)$usage["users"][$userId] : 0;

    if ($used >= $limit) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return ftConversationalUiRelayFailure(429,
            "You have used today's " . $limit . " requests to the assistant.",
            "quota",
            max(1, strtotime("tomorrow", $now) - $now));
    }

    $usage["users"][$userId] = $used + 1;

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode(array("date" => $usage["date"], "users" => (object)$usage["users"]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return null;

}


/**
 * I decide whether a request may go to the model: the relay configured, a
 * signed-in, active user who is not a personal API token, a request that is
 * a JSON object with the endpoint's "stream" and an allowed model, and the
 * day's limit not reached (the request is counted then).
 *
 * @method ftConversationalUiRelayAdmit
 * @param {String|null} $body    the request as JSON text
 * @param {Boolean} $stream      whether the endpoint streams
 * @param {Number|null} $now     for the day's count (the clock by default)
 * @return Array  a failure, or array("config", "user", "request" (decoded), "body")
 */
function ftConversationalUiRelayAdmit($body, $stream, $now = null) {

    $config = ftConversationalUiConfig();

    if ($config["apiKey"] === null || !$config["baseUrlValid"]) {
        return ftConversationalUiRelayFailure(503, "The assistant is not set up on this server.", "notConfigured");
    }
    if (!function_exists("curl_init")) {
        return ftConversationalUiRelayFailure(503, "The relay needs PHP's curl extension, which this server does not have.", "notConfigured");
    }

    $login = userCheckLogin();
    if (!is_array($login) || !isset($login["code"]) || ($login["code"] != 1 && $login["code"] != 3)) {
        return ftConversationalUiRelayFailure(401, "Sign in to use the assistant.", "login");
    }
    if ($login["code"] == 3) {
        return ftConversationalUiRelayFailure(403, "This account is not active.", "notAllowed");
    }
    if (ftIsBearerRequest()) {
        return ftConversationalUiRelayFailure(403, "The relay serves the editor; personal API tokens cannot use it.", "notAllowed");
    }

    $user = isset($login["response"]) && is_array($login["response"]) ? $login["response"] : array();

    if (!is_string($body) || $body === "") {
        return ftConversationalUiRelayFailure(400, "The request is empty.");
    }
    if (strlen($body) > 2 * 1024 * 1024) {
        return ftConversationalUiRelayFailure(413, "The request is larger than 2 MB.");
    }

    $request = json_decode($body);
    if (!is_object($request)) {
        return ftConversationalUiRelayFailure(400, "The request is not a JSON object.");
    }

    $streams = isset($request->stream) && $request->stream === true;
    if ($streams !== $stream) {
        return ftConversationalUiRelayFailure(400, $stream
            ? "This endpoint streams: the request needs \"stream\": true."
            : "This endpoint does not stream: the request needs \"stream\": false.");
    }

    if (!isset($request->model) || !is_string($request->model) || !in_array($request->model, $config["allowedModels"], true)) {
        return ftConversationalUiRelayFailure(400, "The model " . ((isset($request->model) && is_string($request->model)) ? $request->model . " " : "")
            . "is not available on this server; it allows " . implode(", ", $config["allowedModels"]) . ".");
    }

    $count = ftConversationalUiRelayCount(isset($user["id"]) ? (string)$user["id"] : "", $config["requestsPerDay"], $now);
    if ($count !== null) {
        return $count;
    }

    return array("config" => $config, "user" => $user, "request" => $request, "body" => $body);

}


/**
 * I send a request to the model service and hand its answer over:
 * onChunk($chunk) gets every piece of a successful (2xx) answer as it
 * arrives, when given, and stops the request by returning false; everything
 * else is collected (an error's body up to 64 KB).
 *
 * Returns array("status" => the HTTP status, 0 when none came, "headers" =>
 * array(lower-case name => value), "body" => what was collected, "streamed" =>
 * whether onChunk got anything, "error" => curl's error number or 0,
 * "stopped" => whether onChunk stopped it).
 *
 * @method ftConversationalUiRelayExchange
 * @param {Array} $config
 * @param {Array} $headers
 * @param {String} $body
 * @param {Boolean} $stream
 * @param {Callable|null} $onChunk
 * @return Array
 */
function ftConversationalUiRelayExchange($config, $headers, $body, $stream, $onChunk = null) {

    $result = array("status" => 0, "headers" => array(), "body" => "", "streamed" => false, "error" => 0, "stopped" => false);

    $curl = curl_init($config["baseUrl"] . "/chat/completions");

    curl_setopt_array($curl, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $config["timeout"],
        CURLOPT_HEADERFUNCTION => function($curl, $line) use (&$result) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) {
                // A new status line (after "100 Continue", say) starts the headers anew.
                $result["status"]  = (int)$match[1];
                $result["headers"] = array();
            } elseif (strpos($line, ":") !== false) {
                list($name, $value) = explode(":", $line, 2);
                $result["headers"][strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function($curl, $chunk) use (&$result, $onChunk) {
            $ok = $result["status"] >= 200 && $result["status"] < 300;
            if ($ok && $onChunk !== null) {
                $result["streamed"] = true;
                if (call_user_func($onChunk, $chunk) === false) {
                    $result["stopped"] = true;
                    return 0;
                }
            } elseif ($ok || strlen($result["body"]) < 65536) {
                $result["body"] .= $chunk;
            }
            return strlen($chunk);
        }
    ));

    if ($stream) {
        // A stream may take long; one that falls silent for a minute has failed.
        curl_setopt($curl, CURLOPT_LOW_SPEED_LIMIT, 1);
        curl_setopt($curl, CURLOPT_LOW_SPEED_TIME, 60);
    }

    curl_exec($curl);

    $result["error"] = $result["stopped"] ? 0 : curl_errno($curl);
    if ($result["error"] !== 0 && !$result["streamed"] && $result["status"] >= 200 && $result["status"] < 300) {
        // A successful answer that broke off before it was whole is no answer.
        $result["status"] = 0;
    }

    curl_close($curl);

    return $result;

}


/**
 * I turn an answer of the model service that is not a success into what the
 * relay answers: the service's own status and body, except
 *
 * - no answer at all: 502 (504 when it took too long);
 * - 401/403: the service refused the server's key, which is the server's
 *   problem, not the user's (notConfigured), unless the answer is a refusal in
 *   the relay's own words (a gateway's quota, say), which goes through;
 * - 429 with a limit of 0 (x-ratelimit-limit-…: 0): the key may never use
 *   the model, so the panel says to choose another one rather than wait.
 *
 * The key is taken out of any body passed on.
 *
 * @method ftConversationalUiRelayUpstreamFailure
 * @param {Array} $result  ftConversationalUiRelayExchange()'s
 * @param {Array} $config
 * @param {String} $model
 * @return Array  a failure, or array("status", "body" (text), "contentType", "retryAfter") to pass on
 */
function ftConversationalUiRelayUpstreamFailure($result, $config, $model) {

    if ($result["status"] === 0) {
        return ($result["error"] === 28)
            ? ftConversationalUiRelayFailure(504, "The model service did not answer in time.")
            : ftConversationalUiRelayFailure(502, "The model service could not be reached.");
    }

    $status     = $result["status"];
    $text       = str_replace($config["apiKey"], "[key]", $result["body"]);
    $json       = json_decode($text, true);
    $retryAfter = (isset($result["headers"]["retry-after"]) && preg_match('/^\d+$/', $result["headers"]["retry-after"]))
        ? (int)$result["headers"]["retry-after"] : null;

    $relayCode = is_array($json) && isset($json["error"]) && is_array($json["error"]) && isset($json["error"]["code"])
        && in_array($json["error"]["code"], array("login", "quota", "notConfigured", "notAllowed"), true);

    if (!$relayCode && ($status === 401 || $status === 403)) {
        return ftConversationalUiRelayFailure(502, "The model service refused the server's key.", "notConfigured");
    }

    if (!$relayCode && $status === 429) {
        foreach ($result["headers"] as $name => $value) {
            if (strpos($name, "x-ratelimit-limit") === 0 && trim($value) === "0") {
                return ftConversationalUiRelayFailure(400, "The server's key allows no requests to the model " . $model . ".");
            }
        }
    }

    if ($status < 400) {
        $status = 502;
    }

    if (trim($text) === "") {
        $text = json_encode(array("message" => "The model service answered with status " . $result["status"] . "."));
    }

    return array(
        "status"      => $status,
        "body"        => $text,
        "contentType" => is_array($json) ? "application/json" : "text/plain; charset=utf-8",
        "retryAfter"  => $retryAfter
    );

}


/**
 * I let go of the session, so a long answer does not hold up the same
 * person's heartbeat and saves (PHP locks a session for as long as a request
 * holds it).
 *
 * @method ftConversationalUiRelayReleaseSession
 */
function ftConversationalUiRelayReleaseSession() {

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

}


/**
 * I send an answer of the route that is not a stream.
 *
 * @method ftConversationalUiRelaySend
 * @param {Number} $status
 * @param {String} $body
 * @param {String} $contentType
 * @param {Number|null} $retryAfter
 */
function ftConversationalUiRelaySend($status, $body, $contentType = "application/json", $retryAfter = null) {

    http_response_code($status);
    header("Content-Type: " . $contentType);
    if ($retryAfter !== null) {
        header("Retry-After: " . (int)$retryAfter);
    }
    if ($status === 405) {
        header("Allow: POST");
    }

    echo $body;

}


/**
 * I send a failure from the route.
 *
 * @method ftConversationalUiRelaySendFailure
 * @param {Array} $failure
 */
function ftConversationalUiRelaySendFailure($failure) {

    ftConversationalUiRelaySend($failure["status"],
        json_encode(ftConversationalUiRelayFailureBody($failure), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        "application/json",
        $failure["retryAfter"]);

}


/**
 * Route relay: one request to the model, its answer streamed through as it
 * arrives (server-sent events). See the top of this file.
 *
 * @method ftConversationalUiRelay
 * @param {Array} $ext
 * @return null  the answer is written here
 */
function ftConversationalUiRelay($ext) {

    if (!isset($_SERVER["REQUEST_METHOD"]) || $_SERVER["REQUEST_METHOD"] !== "POST") {
        ftConversationalUiRelaySendFailure(ftConversationalUiRelayFailure(405, "The relay takes POST requests."));
        return null;
    }

    // JSON only: a page on another origin cannot send it without asking first,
    // and FrameTrail answers no such question.
    $type = isset($_SERVER["CONTENT_TYPE"]) ? $_SERVER["CONTENT_TYPE"] : (isset($_SERVER["HTTP_CONTENT_TYPE"]) ? $_SERVER["HTTP_CONTENT_TYPE"] : "");
    if (!preg_match('#^\s*application/json\s*(;|$)#i', $type)) {
        ftConversationalUiRelaySendFailure(ftConversationalUiRelayFailure(415, "The relay takes JSON (Content-Type: application/json)."));
        return null;
    }

    $admitted = ftConversationalUiRelayAdmit(file_get_contents("php://input"), true);
    if (isset($admitted["failure"])) {
        ftConversationalUiRelaySendFailure($admitted);
        return null;
    }

    ftConversationalUiRelayReleaseSession();

    $config = $admitted["config"];

    // Every piece goes out as it comes: no output buffer, no compression.
    // Stop in the panel ends the request to the model at the next piece.
    ignore_user_abort(true);
    @set_time_limit($config["timeout"] + 30);
    @ini_set("zlib.output_compression", "0");
    if (function_exists("apache_setenv")) {
        @apache_setenv("no-gzip", "1");
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $started = false;

    $result = ftConversationalUiRelayExchange($config,
        ftConversationalUiRelayHeaders($config, $admitted["user"], true),
        $admitted["body"],
        true,
        function($chunk) use (&$started) {
            if (!$started) {
                $started = true;
                http_response_code(200);
                header("Content-Type: text/event-stream; charset=utf-8");
                header("Cache-Control: no-cache, no-transform");
                header("X-Accel-Buffering: no");
            }
            echo $chunk;
            flush();
            return !connection_aborted();
        });

    if ($started) {
        if ($result["error"] !== 0) {
            echo "data: " . json_encode(array("error" => array("message" => "The answer broke off."))) . "\n\n";
            flush();
        }
        return null;
    }

    $failure = ftConversationalUiRelayUpstreamFailure($result, $config, $admitted["request"]->model);

    if (isset($failure["failure"])) {
        ftConversationalUiRelaySendFailure($failure);
    } else {
        ftConversationalUiRelaySend($failure["status"], $failure["body"], $failure["contentType"], $failure["retryAfter"]);
    }

    return null;

}


/**
 * I turn a failure into the action's answer.
 *
 * @method ftConversationalUiChatFailure
 * @param {Array} $failure
 * @return Array
 */
function ftConversationalUiChatFailure($failure) {

    $answer = array(
        "status" => "fail",
        "code"   => $failure["status"],
        "string" => $failure["message"],
        "error"  => ftConversationalUiRelayFailureBody($failure)["error"]
    );

    if ($failure["retryAfter"] !== null) {
        $answer["retryAfter"] = $failure["retryAfter"];
    }

    return $answer;

}


/**
 * Action conversationalUiChat: one request to the model without streaming.
 * See the top of this file.
 *
 * @method ftConversationalUiChat
 * @param {Array} $ext
 * @return Array
 */
function ftConversationalUiChat($ext) {

    $admitted = ftConversationalUiRelayAdmit(isset($_POST["request"]) && is_string($_POST["request"]) ? $_POST["request"] : null, false);
    if (isset($admitted["failure"])) {
        return ftConversationalUiChatFailure($admitted);
    }

    ftConversationalUiRelayReleaseSession();

    $config = $admitted["config"];
    @set_time_limit($config["timeout"] + 30);

    $result = ftConversationalUiRelayExchange($config,
        ftConversationalUiRelayHeaders($config, $admitted["user"], false),
        $admitted["body"],
        false);

    if ($result["status"] >= 200 && $result["status"] < 300) {
        // Objects stay objects ({} is not []), so the completion goes on as it came.
        $completion = json_decode($result["body"]);
        if (!is_object($completion)) {
            return ftConversationalUiChatFailure(ftConversationalUiRelayFailure(502, "The model service's answer is not readable."));
        }
        return array("status" => "success", "code" => 0, "response" => $completion);
    }

    $failure = ftConversationalUiRelayUpstreamFailure($result, $config, $admitted["request"]->model);
    if (isset($failure["failure"])) {
        return ftConversationalUiChatFailure($failure);
    }

    $upstream = json_decode($failure["body"]);
    $answer = array(
        "status"   => "fail",
        "code"     => $failure["status"],
        "string"   => "The model service answered with status " . $failure["status"] . ".",
        // Not JSON (a proxy's error page, say): an excerpt in plain ASCII, which any JSON encoder takes.
        "upstream" => is_object($upstream) ? $upstream : (object)array("message" => preg_replace('/[^\x09\x0A\x0D\x20-\x7E]+/', "?", substr($failure["body"], 0, 2000)))
    );
    if ($failure["retryAfter"] !== null) {
        $answer["retryAfter"] = $failure["retryAfter"];
    }

    return $answer;

}
