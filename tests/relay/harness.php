<?php

/*
 * Tests of the relay: a stand-in for FrameTrail's routers, run by
 * tests/run-php.php with PHP's built-in server. It loads the server part's
 * manifest (RELAY_SERVER_DIR/extension.php) and calls its handlers as
 * FrameTrail does:
 *
 *     POST /ajaxServer.php  a=<action>     the answer as JSON, like ajaxServer.php
 *     *    /extension.php   r=<route>      the handler writes its answer, like extension.php
 *
 * Request headers set the scene, in place of FrameTrail's functions:
 *
 *     X-Test-Secrets   base64 of the JSON the add-on's secrets file would return
 *     X-Test-User      a user id (signed in), "inactive" (an inactive account) or none (signed out)
 *     X-Test-Role      the user's role ("user" when none)
 *     X-Test-External  a platform subject: external authentication on, the account carries it
 *     X-Test-Bearer    "1": the request came with a personal API token
 *
 * The add-on's private folder is RELAY_STORAGE, FrameTrail's data folder
 * RELAY_DATA, the ffmpeg FrameTrail would find RELAY_FFMPEG (none when
 * empty), and RELAY_TICK the seconds transcription may go without progress.
 */

if (getenv("RELAY_TICK")) {
    define("FT_CONVERSATIONAL_UI_TICK", (float)getenv("RELAY_TICK"));
    // A gateway's job is looked at as often as progress is due.
    define("FT_CONVERSATIONAL_UI_POLL", (float)getenv("RELAY_TICK"));
}

$conf = array("dir" => array("data" => getenv("RELAY_DATA") ?: "/nonexistent"));

function ftExtensionStorage($name) {
    $dir = getenv("RELAY_STORAGE");
    return ($dir && (is_dir($dir) || @mkdir($dir, 0775, true))) ? $dir : false;
}

function ftExtensionSecrets($name) {
    $secrets = isset($_SERVER["HTTP_X_TEST_SECRETS"]) ? json_decode(base64_decode($_SERVER["HTTP_X_TEST_SECRETS"]), true) : null;
    return is_array($secrets) ? $secrets : array();
}

function userCheckLogin($userRole = false) {
    $user = isset($_SERVER["HTTP_X_TEST_USER"]) ? $_SERVER["HTTP_X_TEST_USER"] : "";
    if ($user === "") {
        return array("status" => "fail", "code" => 0, "string" => "User not logged in");
    }
    $role   = isset($_SERVER["HTTP_X_TEST_ROLE"]) ? $_SERVER["HTTP_X_TEST_ROLE"] : "user";
    $record = array("id" => $user, "name" => "Tester", "role" => $role, "active" => ($user === "inactive") ? 0 : 1);
    if (isset($_SERVER["HTTP_X_TEST_EXTERNAL"])) {
        $record["external"] = array("provider" => "platform", "sub" => $_SERVER["HTTP_X_TEST_EXTERNAL"]);
    }
    return array("status" => "success", "code" => ($user === "inactive") ? 3 : 1, "string" => "User logged in", "response" => $record);
}

function requireLogin($role = false) {
    $login = userCheckLogin($role);
    return ($login["code"] != 1) ? array("status" => "fail", "code" => 1, "string" => $login["string"]) : null;
}

function ftIsBearerRequest() {
    return isset($_SERVER["HTTP_X_TEST_BEARER"]) && $_SERVER["HTTP_X_TEST_BEARER"] === "1";
}

function ftExternalAuthEnabled() {
    return isset($_SERVER["HTTP_X_TEST_EXTERNAL"]);
}

// FrameTrail's (functions.incl.php): a plain file name, directly in resources/.
function ftResourceFilePath($name) {
    global $conf;
    if (!is_string($name) || $name === "" || $name !== basename($name)
        || strpos($name, "\\") !== false || $name[0] === "." || $name === "_index.json") {
        return null;
    }
    $dir  = realpath($conf["dir"]["data"] . "/resources");
    $path = ($dir === false) ? false : realpath($dir . "/" . $name);
    return ($path === false || dirname($path) !== $dir || !is_file($path)) ? null : $path;
}

// FrameTrail's (files.php) looks in the usual places.
function detectFFmpegPath() {
    return getenv("RELAY_FFMPEG") ?: null;
}

$manifest = (function($__file) { return include $__file; })(getenv("RELAY_SERVER_DIR") . "/extension.php");
$context  = array("name" => "conversational-ui", "settings" => array());
$path     = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$flags    = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

if ($path === "/ajaxServer.php") {

    header("Content-Type: application/json");
    $action = isset($_REQUEST["a"]) ? $_REQUEST["a"] : "";
    $answer = isset($manifest["actions"][$action])
        ? call_user_func($manifest["actions"][$action], $context)
        : array("status" => "fail", "code" => 404, "string" => "No such action.");
    echo json_encode($answer, $flags);

} elseif ($path === "/extension.php") {

    header("Cache-Control: no-cache, must-revalidate");
    header("X-Content-Type-Options: nosniff");
    $route = isset($_GET["r"]) ? $_GET["r"] : "";
    if (!isset($manifest["routes"][$route])) {
        http_response_code(404);
        header("Content-Type: application/json");
        echo json_encode(array("status" => "fail", "code" => 404, "string" => "No such route."), $flags);
        return;
    }
    $value = call_user_func($manifest["routes"][$route], $context);
    if (is_array($value)) {
        header("Content-Type: application/json");
        echo json_encode($value, $flags);
    }

} else {

    http_response_code(404);

}
