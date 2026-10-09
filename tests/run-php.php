<?php

/*
 * Tests of the server part. No dependencies, PHP 7.4 or later with curl:
 *
 *     php tests/run-php.php            # the files in server/
 *     php tests/run-php.php --build    # the drop-in folder bash scripts/build.sh wrote
 *
 * extension.php is read as FrameTrail's extension loader reads it
 * (_server/extensionloader.php), with stand-ins for the FrameTrail functions
 * it uses, and checked against the loader's rules. The relay's and
 * transcription's parts are checked one by one, then over HTTP: PHP's
 * built-in server runs a stand-in for Mistral's API and a Whisper server
 * (tests/relay/upstream.php) and a stand-in for FrameTrail's routers
 * (tests/relay/harness.php), and the answers are read as the browser reads
 * them. Stand-ins for ffmpeg are small shell scripts; where ffmpeg itself is
 * installed, the sound it takes out of a generated video is checked too
 * (skipped otherwise). Prints TAP; exits with 1 when a test fails.
 */

$root  = dirname(__DIR__);
$built = in_array("--build", $argv, true);
$dir   = $built ? $root . "/build/server" : $root . "/server";

$count    = 0;
$failures = 0;


function check($description, $ok, $detail = "") {

    global $count, $failures;

    $count++;
    echo ($ok ? "ok " : "not ok ") . $count . " - " . $description . "\n";

    if (!$ok) {
        $failures++;
        if ($detail !== "") {
            echo "  # " . str_replace("\n", "\n  # ", trim($detail)) . "\n";
        }
    }

}


function skip($description, $reason) {

    global $count;

    $count++;
    echo "ok " . $count . " - " . $description . " # SKIP " . $reason . "\n";

}


function finish() {

    global $count, $failures;

    echo "1.." . $count . "\n";

    if ($failures > 0) {
        echo "# " . $failures . " of " . $count . " failed\n";
        exit(1);
    }

    echo "# all " . $count . " passed\n";
    exit(0);

}


function phpFiles($dir) {

    $files = array();
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($items as $item) {
        if ($item->isFile() && substr($item->getFilename(), -4) === ".php") {
            $files[] = $item->getPathname();
        }
    }

    sort($files);

    return $files;

}


// Like FrameTrail's ftExtensionInclude(): a scope of its own.
function includeManifest($__file) {

    return include $__file;

}


// A folder for this run's files, removed at the end.
function scratch() {

    static $dir = null;

    if ($dir === null) {
        $dir = sys_get_temp_dir() . "/ft-cui-tests-" . getmypid() . "-" . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        register_shutdown_function(function() use ($dir) {
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($dir);
        });
    }

    return $dir;

}


/* ---------------------------------------------------------------------- */
/*  Files                                                                 */
/* ---------------------------------------------------------------------- */

if (!is_file($dir . "/extension.php")) {
    check("extension.php exists in " . substr($dir, strlen($root) + 1) . ($built ? " (run bash scripts/build.sh first)" : ""), false);
    finish();
}

foreach (array_merge(phpFiles($dir), phpFiles(__DIR__ . "/relay"), array(__FILE__)) as $file) {
    $output = array();
    exec(escapeshellarg(PHP_BINARY) . " -l " . escapeshellarg($file) . " 2>&1", $output, $status);
    check("syntax: " . substr($file, strlen($root) + 1), $status === 0, implode("\n", $output));
}

// Run on its own, by a web server that ignores .htaccess, no file does anything.
foreach (phpFiles($dir) as $file) {
    $output = array();
    exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($file) . " 2>&1", $output, $status);
    check(basename($file) . " does nothing when run on its own", $status === 0 && count($output) === 0, implode("\n", $output));
}

check("relay.php is there", is_file($dir . "/relay.php"));
check("transcribe.php is there", is_file($dir . "/transcribe.php"));


/* ---------------------------------------------------------------------- */
/*  Stand-ins for FrameTrail's functions                                  */
/* ---------------------------------------------------------------------- */

// Its presence tells extension.php that a FrameTrail router loaded it. The
// add-on's private folder: whatever a check puts into $GLOBALS["storage"].
function ftExtensionStorage($name) {

    return ($name === "conversational-ui" && isset($GLOBALS["storage"])) ? $GLOBALS["storage"] : false;

}

// The secrets in _data/.auth/<name>.php: whatever a check puts into $GLOBALS["secrets"].
function ftExtensionSecrets($name) {

    return ($name === "conversational-ui" && isset($GLOBALS["secrets"]) && is_array($GLOBALS["secrets"])) ? $GLOBALS["secrets"] : array();

}

// The signed-in user: $GLOBALS["login"] is a user record, "inactive", or unset (signed out).
function userCheckLogin($userRole = false) {

    if (!isset($GLOBALS["login"])) {
        return array("status" => "fail", "code" => 0, "string" => "User not logged in");
    }
    if ($GLOBALS["login"] === "inactive") {
        return array("status" => "success", "code" => 3, "string" => "User is logged in but not active", "response" => array("id" => "9", "active" => 0));
    }

    return array("status" => "success", "code" => 1, "string" => "User logged in", "response" => $GLOBALS["login"]);

}

function requireLogin($role = false) {

    $login = userCheckLogin($role);

    return ($login["code"] != 1) ? array("status" => "fail", "code" => 1, "string" => $login["string"]) : null;

}

function ftIsBearerRequest() {

    return !empty($GLOBALS["bearer"]);

}

function ftExternalAuthEnabled() {

    return !empty($GLOBALS["external"]);

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

// FrameTrail's (files.php) looks in the usual places: here whatever a check puts into $GLOBALS["detected"].
function detectFFmpegPath() {

    return isset($GLOBALS["detected"]) ? $GLOBALS["detected"] : null;

}


/* ---------------------------------------------------------------------- */
/*  Manifest                                                              */
/* ---------------------------------------------------------------------- */

$before   = get_defined_functions()["user"];
$manifest = includeManifest($dir . "/extension.php");
$declared = array_values(array_diff(get_defined_functions()["user"], $before));

check("extension.php returns an array", is_array($manifest));
if (!is_array($manifest)) {
    finish();
}

$unknown = array_diff(array_keys($manifest), array("actions", "routes", "requires"));
check("it declares only actions, routes and requires", count($unknown) === 0, implode(", ", $unknown));

// Two extensions declaring a function of the same name stop every request
// that loads both.
$unprefixed = array_filter($declared, function($name) { return strpos($name, "ftconversationalui") !== 0; });
check("every function it declares starts with ftConversationalUi", count($unprefixed) === 0, implode(", ", $unprefixed));

$actions = isset($manifest["actions"]) ? $manifest["actions"] : array();
$routes  = isset($manifest["routes"]) ? $manifest["routes"] : array();

check("actions and routes are arrays", is_array($actions) && is_array($routes));

foreach ($actions as $name => $handler) {
    check("action " . $name . ": a valid action name", is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $name) === 1);
    check("action " . $name . ": prefixed conversationalUi", is_string($name) && preg_match('/^conversationalUi[A-Z]/', $name) === 1);
    check("action " . $name . ": its handler can be called", is_callable($handler));
}

foreach ($routes as $name => $handler) {
    check("route " . $name . ": a valid route name", is_string($name) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $name) === 1);
    check("route " . $name . ": its handler can be called", is_callable($handler));
}

$requires = isset($manifest["requires"]) ? (array)$manifest["requires"] : array();
check("it requires json only (curl is checked by the relay when called)", $requires === array("json"), json_encode($requires));
foreach ($requires as $requirement) {
    check("requires " . $requirement . ": this PHP has it", is_string($requirement) && extension_loaded($requirement));
}

check("action conversationalUiStatus is offered", isset($actions["conversationalUiStatus"]));
check("action conversationalUiChat is offered", isset($actions["conversationalUiChat"]));
check("route relay is offered", isset($routes["relay"]));
check("route transcribe is offered", isset($routes["transcribe"]));

check("loading the manifest loads not the relay", !function_exists("ftConversationalUiRelayAdmit"));
check("loading the manifest loads not transcription", !function_exists("ftConversationalUiTranscribeAdmit"));


/* ---------------------------------------------------------------------- */
/*  Status and configuration                                              */
/* ---------------------------------------------------------------------- */

$context = array("name" => "conversational-ui", "settings" => array());
$curl    = function_exists("curl_init");

check("PHP's curl extension is there (the relay's tests need it)", $curl);

if (isset($actions["conversationalUiStatus"]) && is_callable($actions["conversationalUiStatus"])) {

    $answer = call_user_func($actions["conversationalUiStatus"], $context);

    check("conversationalUiStatus answers with success",
        is_array($answer) && isset($answer["status"], $answer["code"]) && $answer["status"] === "success" && $answer["code"] === 0,
        json_encode($answer));

    $version = (is_array($answer) && isset($answer["response"]["version"])) ? $answer["response"]["version"] : null;

    check("conversationalUiStatus names the version" . ($built ? " the build wrote in" : ", \"dev\" before the build"),
        is_string($version) && ($built ? ($version !== "" && strpos($version, "__") === false) : $version === "dev"),
        var_export($version, true));

    // How the chat panel may reach Mistral, for the secrets given.
    $capabilities = function($secrets) use ($actions, $context) {
        $GLOBALS["secrets"] = $secrets;
        $answer = call_user_func($actions["conversationalUiStatus"], $context);
        unset($GLOBALS["secrets"]);
        return (is_array($answer) && isset($answer["response"]["capabilities"])) ? $answer["response"]["capabilities"] : null;
    };

    // No transcription unless the check says otherwise.
    $expect = function($description, $secrets, $expected) use ($capabilities) {
        $expected = array_merge($expected, array_key_exists("transcription", $expected) ? array() : array("transcription" => false));
        $got = $capabilities($secrets);
        check("conversationalUiStatus: " . $description, $got === $expected, "got " . json_encode($got) . "\nexpected " . json_encode($expected));
    };

    $expect("no relay, no own keys without secrets", array(), array("relay" => false, "ownKey" => false));
    $expect("own keys when allowOwnKey is true", array("allowOwnKey" => true), array("relay" => false, "ownKey" => true));
    $expect("only true allows own keys", array("allowOwnKey" => "yes"), array("relay" => false, "ownKey" => false));
    $expect("an empty key is no key", array("apiKey" => "  "), array("relay" => false, "ownKey" => false));

    $expect("the relay with a key: the default model, no limit", array("apiKey" => "sk-1"),
        $curl ? array("relay" => true, "ownKey" => false, "models" => array("ministral-14b-latest"), "defaultModel" => "ministral-14b-latest", "requestsPerDay" => null)
              : array("relay" => false, "ownKey" => false, "problem" => "curl"));

    if ($curl) {
        $expect("models listed once, the default among them, the limit a whole number",
            array("apiKey" => "sk-1", "allowOwnKey" => true, "allowedModels" => array("a", " b ", "a", "", 3), "defaultModel" => "b", "requestsPerDay" => 10.7),
            array("relay" => true, "ownKey" => true, "models" => array("a", "b"), "defaultModel" => "b", "requestsPerDay" => 10));
        $expect("a default model that is not allowed gives way to the first allowed one",
            array("apiKey" => "sk-1", "allowedModels" => array("a", "b"), "defaultModel" => "c"),
            array("relay" => true, "ownKey" => false, "models" => array("a", "b"), "defaultModel" => "a", "requestsPerDay" => null));
        $expect("a default model alone is the one allowed",
            array("apiKey" => "sk-1", "defaultModel" => "codestral-latest", "requestsPerDay" => 0),
            array("relay" => true, "ownKey" => false, "models" => array("codestral-latest"), "defaultModel" => "codestral-latest", "requestsPerDay" => null));
        $expect("a baseUrl that is not http(s) is a problem", array("apiKey" => "sk-1", "baseUrl" => "ftp://example.org/v1"),
            array("relay" => false, "ownKey" => false, "problem" => "baseUrl"));
        $expect("a baseUrl with credentials in it is a problem", array("apiKey" => "sk-1", "baseUrl" => "https://user:secret@example.org/v1"),
            array("relay" => false, "ownKey" => false, "problem" => "baseUrl"));
    }

    $GLOBALS["secrets"] = array("apiKey" => "sk-1", "baseUrl" => "https://gateway.example.org/v1/ ", "timeout" => 5, "instance" => " project-7 ");
    $config = ftConversationalUiConfig();
    check("config: baseUrl without the trailing slash", $config["baseUrl"] === "https://gateway.example.org/v1", $config["baseUrl"]);
    check("config: timeout at least 10 seconds", $config["timeout"] === 10, json_encode($config["timeout"]));
    check("config: instance trimmed", $config["instance"] === "project-7", json_encode($config["instance"]));
    $GLOBALS["secrets"] = array("timeout" => 99999);
    check("config: timeout at most an hour", ftConversationalUiConfig()["timeout"] === 3600);
    $GLOBALS["secrets"] = array();
    $config = ftConversationalUiConfig();
    check("config: defaults", $config["baseUrl"] === "https://api.mistral.ai/v1" && $config["timeout"] === 300 && $config["apiKey"] === null
        && $config["instance"] === null && $config["requestsPerDay"] === null && $config["allowOwnKey"] === false && $config["transcription"] === null, json_encode($config));
    unset($GLOBALS["secrets"]);

    // Transcription's block.
    $transcriptionConfig = function($settings) {
        $GLOBALS["secrets"] = array("transcription" => $settings);
        $config = ftConversationalUiConfig()["transcription"];
        unset($GLOBALS["secrets"]);
        return $config;
    };

    check("transcription config: none that is not an array", $transcriptionConfig("yes") === null);
    $config = $transcriptionConfig(array("baseUrl" => " http://127.0.0.1:8000/v1/ "));
    check("transcription config: defaults", $config === array("baseUrl" => "http://127.0.0.1:8000/v1", "baseUrlValid" => true, "apiKey" => null, "model" => "whisper-1",
        "maxBytes" => 104857600, "timeout" => 1800, "ffmpeg" => null, "audioFormat" => "mp3"), json_encode($config));
    $config = $transcriptionConfig(array("baseUrl" => "http://speech:9000", "apiKey" => " k ", "model" => "Systran/faster-whisper-small",
        "maxBytes" => 5000.7, "timeout" => 5, "ffmpeg" => false, "audioFormat" => "flac"));
    check("transcription config: given values, the timeout at least a minute", $config["apiKey"] === "k" && $config["model"] === "Systran/faster-whisper-small"
        && $config["maxBytes"] === 5000 && $config["timeout"] === 60 && $config["ffmpeg"] === false && $config["audioFormat"] === "flac", json_encode($config));
    $config = $transcriptionConfig(array("baseUrl" => "ftp://speech/v1", "maxBytes" => 10, "timeout" => 99999, "ffmpeg" => " /opt/ffmpeg ", "audioFormat" => "ogg"));
    check("transcription config: bounds and what is not allowed", $config["baseUrlValid"] === false && $config["maxBytes"] === 104857600
        && $config["timeout"] === 7200 && $config["ffmpeg"] === "/opt/ffmpeg" && $config["audioFormat"] === "mp3", json_encode($config));
    $config = $transcriptionConfig(array("ffmpeg" => 0));
    check("transcription config: no baseUrl, no valid one; ffmpeg neither path nor false: looked for", $config["baseUrl"] === null
        && $config["baseUrlValid"] === false && $config["ffmpeg"] === null, json_encode($config));

    $speech = array("transcription" => array("baseUrl" => "http://127.0.0.1:8000/v1", "maxBytes" => 5000));
    $expect("transcription set up: available", $speech,
        array("relay" => false, "ownKey" => false, "transcription" => $curl ? array("available" => true) : array("available" => false, "problem" => "curl")));
    $expect("transcription with a baseUrl that is not http(s): a problem", array("transcription" => array("baseUrl" => "file:///etc")),
        array("relay" => false, "ownKey" => false, "transcription" => array("available" => false, "problem" => $curl ? "baseUrl" : "curl")));

}


/* ---------------------------------------------------------------------- */
/*  The relay, part by part                                               */
/* ---------------------------------------------------------------------- */

if (!$curl || !is_file($dir . "/relay.php")) {
    finish();
}

$before = get_defined_functions()["user"];
require_once $dir . "/relay.php";
$declared = array_values(array_diff(get_defined_functions()["user"], $before));

check("relay.php declares functions, all starting with ftConversationalUi",
    count($declared) > 0 && count(array_filter($declared, function($name) { return strpos($name, "ftconversationalui") !== 0; })) === 0,
    implode(", ", $declared));


// Headers: identity only for a gateway.

$user = array("id" => "7", "name" => "Tester", "external" => array("provider" => "platform", "sub" => "4711"));

$headers = ftConversationalUiRelayHeaders(array("apiKey" => "sk-1", "baseUrl" => "https://api.mistral.ai/v1", "instance" => null), $user, true);
check("headers to Mistral: the key, the types, no 100-continue, nothing about the user",
    $headers === array("Authorization: Bearer sk-1", "Content-Type: application/json", "Accept: text/event-stream", "Expect:"), json_encode($headers));

$headers = ftConversationalUiRelayHeaders(array("apiKey" => "sk-1", "baseUrl" => "https://API.Mistral.AI/v1", "instance" => null), $user, false);
check("headers to Mistral, its host in capitals: nothing about the user",
    count(preg_grep('/^X-FrameTrail-/', $headers)) === 0 && in_array("Accept: application/json", $headers, true), json_encode($headers));

$headers = ftConversationalUiRelayHeaders(array("apiKey" => "sk-1", "baseUrl" => "https://gateway.example.org/v1", "instance" => "project-7"), $user, true);
check("headers to a gateway: the local user id and the instance",
    in_array("X-FrameTrail-User: 7", $headers, true) && in_array("X-FrameTrail-Instance: project-7", $headers, true), json_encode($headers));

$GLOBALS["external"] = true;
$headers = ftConversationalUiRelayHeaders(array("apiKey" => "sk-1", "baseUrl" => "https://gateway.example.org/v1", "instance" => "project-7"), $user, true);
check("headers to a gateway under external authentication: the platform's subject",
    in_array("X-FrameTrail-User: 4711", $headers, true), json_encode($headers));
$headers = ftConversationalUiRelayHeaders(array("apiKey" => "sk-1", "baseUrl" => "https://gateway.example.org/v1", "instance" => "project-7"), array("id" => "8"), true);
check("headers to a gateway under external authentication, an account without a subject: the local id",
    in_array("X-FrameTrail-User: 8", $headers, true), json_encode($headers));
unset($GLOBALS["external"]);

$headers = ftConversationalUiRelayHeaders(array("apiKey" => "sk-1\r\nX-Evil: 1", "baseUrl" => "https://gateway.example.org/v1", "instance" => "a\nb"), array("id" => "7\r\nX-Evil: 2"), true);
check("headers: line breaks in values cannot add headers",
    count(preg_grep('/^X-Evil/', $headers)) === 0 && count(preg_grep('/[\r\n]/', $headers)) === 0, json_encode($headers));

$_SERVER["HTTPS"] = "on";
$_SERVER["HTTP_HOST"] = "example.org";
$_SERVER["SCRIPT_NAME"] = "/frametrail/_server/extension.php";
check("the instance without a configured one: the installation's URL",
    ftConversationalUiRelayInstance(array("instance" => null)) === "https://example.org/frametrail/", ftConversationalUiRelayInstance(array("instance" => null)));
$_SERVER["SCRIPT_NAME"] = "/_server/extension.php";
check("the instance at the server's root", ftConversationalUiRelayInstance(array("instance" => null)) === "https://example.org/", ftConversationalUiRelayInstance(array("instance" => null)));
unset($_SERVER["HTTPS"], $_SERVER["HTTP_HOST"], $_SERVER["SCRIPT_NAME"]);


// The day's count.

$GLOBALS["storage"] = scratch() . "/count";
mkdir($GLOBALS["storage"]);
$usageFile = $GLOBALS["storage"] . "/usage.json";
$noon      = mktime(12, 0, 0, 10, 8, 2026);

check("count: no limit counts nothing", ftConversationalUiRelayCount("7", null, $noon) === null && !file_exists($usageFile));

$first  = ftConversationalUiRelayCount("7", 2, $noon);
$second = ftConversationalUiRelayCount("7", 2, $noon + 60);
$third  = ftConversationalUiRelayCount("7", 2, $noon + 120);
check("count: the limit's requests go", $first === null && $second === null);
check("count: the next one is refused with quota, 429, until midnight",
    is_array($third) && $third["code"] === "quota" && $third["status"] === 429 && $third["retryAfter"] === 12 * 3600 - 120, json_encode($third));
check("count: another user has a count of their own", ftConversationalUiRelayCount("0", 2, $noon) === null);

$usage = json_decode(file_get_contents($usageFile));
check("count: usage.json holds the day and the users by id, an object even for user 0",
    is_object($usage) && $usage->date === "2026-10-08" && is_object($usage->users) && $usage->users->{"7"} === 2 && $usage->users->{"0"} === 1,
    file_get_contents($usageFile));

check("count: a new day starts at nothing", ftConversationalUiRelayCount("7", 2, $noon + 86400) === null
    && json_decode(file_get_contents($usageFile))->date === "2026-10-09" && !isset(json_decode(file_get_contents($usageFile))->users->{"0"}));

file_put_contents($usageFile, "not json");
check("count: a broken usage.json starts over", ftConversationalUiRelayCount("7", 2, $noon) === null && json_decode(file_get_contents($usageFile))->users->{"7"} === 1);

$GLOBALS["storage"] = false;
$refused = ftConversationalUiRelayCount("7", 2, $noon);
check("count: without a private folder a limit cannot be kept: refused (notConfigured)",
    is_array($refused) && $refused["code"] === "notConfigured" && $refused["status"] === 503, json_encode($refused));
$GLOBALS["storage"] = scratch() . "/count";


// Admission: in this order, configured, signed in, active, no token, the request, the count.

$admit = function($secrets, $login, $body, $stream, $bearer = false) {
    $GLOBALS["secrets"] = $secrets;
    if ($login === null) { unset($GLOBALS["login"]); } else { $GLOBALS["login"] = $login; }
    $GLOBALS["bearer"] = $bearer;
    $result = ftConversationalUiRelayAdmit($body, $stream, mktime(12, 0, 0, 10, 8, 2026));
    unset($GLOBALS["secrets"], $GLOBALS["login"], $GLOBALS["bearer"]);
    return $result;
};

$refusal = function($description, $result, $status, $code, $words = null) {
    $ok = is_array($result) && isset($result["failure"]) && $result["status"] === $status && $result["code"] === $code
        && ($words === null || stripos($result["message"], $words) !== false);
    check("admit: " . $description, $ok, json_encode($result));
};

$relaySecrets = array("apiKey" => "sk-1", "allowedModels" => array("ministral-14b-latest", "ministral-8b-latest"), "requestsPerDay" => 1);
$tester       = array("id" => "5", "name" => "Tester", "role" => "user", "active" => 1);
$streamBody   = '{"model":"ministral-8b-latest","stream":true,"messages":[{"role":"user","content":"Hi"}],"tools":[],"x":{}}';

$refusal("no key: not set up (before anything else)", $admit(array(), null, $streamBody, true), 503, "notConfigured");
$refusal("an invalid baseUrl: not set up", $admit(array("apiKey" => "sk-1", "baseUrl" => "javascript:x"), $tester, $streamBody, true), 503, "notConfigured");
$refusal("signed out: login, 401", $admit($relaySecrets, null, $streamBody, true), 401, "login");
$refusal("an inactive account: notAllowed, 403", $admit($relaySecrets, "inactive", $streamBody, true), 403, "notAllowed");
$refusal("a personal API token: notAllowed, 403", $admit($relaySecrets, $tester, $streamBody, true, true), 403, "notAllowed", "token");
$refusal("an empty request: 400", $admit($relaySecrets, $tester, "", true), 400, null);
$refusal("over 2 MB: 413", $admit($relaySecrets, $tester, '{"model":"ministral-8b-latest","stream":true,"x":"' . str_repeat("a", 2 * 1024 * 1024) . '"}', true), 413, null);
$refusal("not a JSON object: 400", $admit($relaySecrets, $tester, '[1,2]', true), 400, null, "JSON object");
$refusal("the route without stream: true: 400", $admit($relaySecrets, $tester, '{"model":"ministral-8b-latest"}', true), 400, null, "stream");
$refusal("the action with stream: true: 400", $admit($relaySecrets, $tester, $streamBody, false), 400, null, "stream");
$refusal("a model the server does not allow: 400, in words that name the model",
    $admit($relaySecrets, $tester, '{"model":"mistral-large-latest","stream":true}', true), 400, null, "model mistral-large-latest is not available");
$refusal("no model: 400", $admit($relaySecrets, $tester, '{"stream":true}', true), 400, null, "model");

@unlink($GLOBALS["storage"] . "/usage.json");
$admitted = $admit($relaySecrets, $tester, $streamBody, true);
check("admit: a request that may go keeps its bytes, the user and the config",
    is_array($admitted) && !isset($admitted["failure"]) && $admitted["body"] === $streamBody && $admitted["user"] === $tester
    && $admitted["request"]->model === "ministral-8b-latest" && $admitted["config"]["apiKey"] === "sk-1", json_encode($admitted));
$refusal("the day's requests used up: quota, 429", $admit($relaySecrets, $tester, $streamBody, true), 429, "quota", "today");
$refusal("the same user stays refused on the next request",
    $admit($relaySecrets, $tester, $streamBody, true), 429, "quota");
check("admit: the count is per user", !isset($admit($relaySecrets, array("id" => "6", "active" => 1), $streamBody, true)["failure"]));


// What the service's failures become.

$config  = array("apiKey" => "sk-SECRET", "baseUrl" => "https://api.mistral.ai/v1");
$failure = function($result) use ($config) {
    return ftConversationalUiRelayUpstreamFailure(array_merge(array("status" => 0, "headers" => array(), "body" => "", "streamed" => false, "error" => 0, "stopped" => false), $result), $config, "m-1");
};

$f = $failure(array("error" => 7));
check("upstream: no answer: 502", isset($f["failure"]) && $f["status"] === 502 && $f["code"] === null, json_encode($f));
$f = $failure(array("error" => 28));
check("upstream: no answer in time: 504", isset($f["failure"]) && $f["status"] === 504, json_encode($f));
$f = $failure(array("status" => 401, "body" => '{"message":"Unauthorized"}'));
check("upstream: the server's key refused (401): notConfigured, 502", isset($f["failure"]) && $f["status"] === 502 && $f["code"] === "notConfigured", json_encode($f));
$f = $failure(array("status" => 403, "body" => ""));
check("upstream: 403: notConfigured too", isset($f["failure"]) && $f["code"] === "notConfigured", json_encode($f));
$f = $failure(array("status" => 403, "body" => '{"error":{"code":"notAllowed","message":"Your plan has no assistant."}}'));
check("upstream: a gateway's refusal in the relay's words goes through", !isset($f["failure"]) && $f["status"] === 403 && strpos($f["body"], "Your plan") !== false, json_encode($f));
$f = $failure(array("status" => 429, "headers" => array("x-ratelimit-limit-req-minute" => "0"), "body" => '{"message":"Rate limit exceeded"}'));
check("upstream: 429 with a limit of 0: a model error, 400", isset($f["failure"]) && $f["status"] === 400 && strpos($f["message"], "model m-1") !== false, json_encode($f));
$f = $failure(array("status" => 429, "headers" => array("x-ratelimit-remaining-req-minute" => "0", "retry-after" => "7"), "body" => '{"message":"Rate limit exceeded"}'));
check("upstream: a passing rate limit goes through with Retry-After", !isset($f["failure"]) && $f["status"] === 429 && $f["retryAfter"] === 7 && $f["contentType"] === "application/json", json_encode($f));
$f = $failure(array("status" => 400, "body" => '{"message":"Bearer sk-SECRET is odd"}'));
check("upstream: the key is taken out of what goes through", !isset($f["failure"]) && strpos($f["body"], "sk-SECRET") === false && strpos($f["body"], "[key]") !== false, json_encode($f));
$f = $failure(array("status" => 500, "body" => ""));
check("upstream: an empty error body gets words", !isset($f["failure"]) && $f["status"] === 500 && json_decode($f["body"], true)["message"] === "The model service answered with status 500.", json_encode($f));
$f = $failure(array("status" => 502, "body" => "<html>Bad gateway</html>"));
check("upstream: an error page that is not JSON goes as plain text", !isset($f["failure"]) && $f["contentType"] === "text/plain; charset=utf-8", json_encode($f));
$f = $failure(array("status" => 302, "body" => ""));
check("upstream: a redirect (never followed) is a failure, 502", !isset($f["failure"]) && $f["status"] === 502, json_encode($f));


/* ---------------------------------------------------------------------- */
/*  Transcription, part by part                                           */
/* ---------------------------------------------------------------------- */

$before = get_defined_functions()["user"];
require_once $dir . "/transcribe.php";
$declared = array_values(array_diff(get_defined_functions()["user"], $before));

check("transcribe.php declares functions, all starting with ftConversationalUi",
    count($declared) > 0 && count(array_filter($declared, function($name) { return strpos($name, "ftconversationalui") !== 0; })) === 0,
    implode(", ", $declared));


// ffmpeg: where it is installed, a short video to take the sound out of; stand-ins as shell scripts.

exec("command -v ffmpeg 2>/dev/null", $found);
$realFfmpeg  = (isset($found[0]) && is_executable(trim($found[0]))) ? trim($found[0]) : null;
exec("command -v ffprobe 2>/dev/null", $found2);
$realFfprobe = (isset($found2[0]) && is_executable(trim($found2[0]))) ? trim($found2[0]) : null;

// A FrameTrail data folder: hypervideos with their clips, the resource index, one video.
$data = scratch() . "/data";
$conf = array("dir" => array("data" => $data));
mkdir($data . "/resources", 0775, true);

$video = $data . "/resources/1_lecture.mp4";
if ($realFfmpeg !== null) {
    exec(escapeshellarg($realFfmpeg) . " -nostdin -hide_banner -loglevel error -y -f lavfi -i sine=frequency=440:duration=6 -f lavfi -i color=c=black:s=64x64:d=6 "
        . "-shortest -c:v mpeg4 -c:a aac " . escapeshellarg($video) . " 2>&1", $made, $status);
}
if (!is_file($video) || filesize($video) === 0) {
    file_put_contents($video, random_bytes(3000));
}
file_put_contents($data . "/resources/2_big.mp4", str_repeat("v", 1536 * 1024));

$hypervideos = array(
    "1" => array("creatorId" => "7", "clip" => array("resourceId" => "5", "src" => "1_lecture.mp4", "in" => 0, "out" => 0)),
    "2" => array("creatorId" => "8", "clip" => array("src" => "1_lecture.mp4")),
    "3" => array("creatorId" => 7,   "clip" => array("resourceId" => 5, "src" => "", "in" => 2, "out" => 4)),
    "4" => array("creatorId" => "7", "clip" => array("src" => "https://www.youtube.com/watch?v=abc")),
    "5" => array("creatorId" => "7", "clip" => array("resourceId" => null, "src" => null, "duration" => 600)),
    "8" => array("creatorId" => "7", "clip" => array("src" => "2_big.mp4")),
    "9" => array("creatorId" => "7", "clip" => array("src" => "9_missing.mp4")),
    "10" => array("creatorId" => "7", "clip" => array("src" => "../resources/1_lecture.mp4"))
);
$index = array("hypervideo-increment" => 10, "hypervideos" => array("6" => "../../outside"));
foreach ($hypervideos as $id => $hypervideo) {
    mkdir($data . "/hypervideos/" . $id, 0775, true);
    file_put_contents($data . "/hypervideos/" . $id . "/hypervideo.json", json_encode(array(
        "meta"  => array("name" => "HV " . $id, "creator" => "Someone", "creatorId" => $hypervideo["creatorId"]),
        "clips" => array($hypervideo["clip"])
    )));
    $index["hypervideos"][$id] = "./" . $id;
}
mkdir(scratch() . "/outside", 0775, true);
file_put_contents(scratch() . "/outside/hypervideo.json", json_encode(array("meta" => array("creatorId" => "7"), "clips" => array(array("src" => "1_lecture.mp4")))));
file_put_contents($data . "/hypervideos/_index.json", json_encode($index));
file_put_contents($data . "/resources/_index.json", json_encode(array("resources-increment" => 5, "resources" => array("5" => array("name" => "Lecture", "type" => "video", "src" => "1_lecture.mp4")))));

// Stand-ins for ffmpeg, each a shell script: it writes its arguments to $log.
$standins = scratch() . "/ffmpeg";
mkdir($standins);
$ffmpegLog = $standins . "/args.log";
$ffmpegPid = $standins . "/pid";
$standin = function($name, $body) use ($standins, $ffmpegLog) {
    $file = $standins . "/" . $name;
    file_put_contents($file, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . escapeshellarg($ffmpegLog) . "\nfor last; do :; done\n" . $body . "\n");
    chmod($file, 0755);
    return $file;
};
$fakeOk    = $standin("ffmpeg-ok", "printf 'ID3fake-audio' > \"\$last\"");
$fakeBig   = $standin("ffmpeg-big", "head -c 6000 /dev/zero > \"\$last\"");
$fakeFail  = $standin("ffmpeg-fail", "echo 'Invalid data found when processing input' >&2\nexit 1");
$fakeEmpty = $standin("ffmpeg-empty", "exit 0");
$fakeSlow  = $standin("ffmpeg-slow", "echo \$\$ > " . escapeshellarg($ffmpegPid) . "\nexec sleep 30");

$alive = function($pid) {
    exec("kill -0 " . (int)$pid . " 2>/dev/null", $out, $status);
    return $status === 0;
};


// Which ffmpeg.

check("ffmpeg: none when configured false", ftConversationalUiTranscribeFfmpeg(array("ffmpeg" => false)) === null);
check("ffmpeg: a bare name, looked up in PATH when run", ftConversationalUiTranscribeFfmpeg(array("ffmpeg" => "ffmpeg")) === "ffmpeg");
check("ffmpeg: a configured path that does not exist: none", ftConversationalUiTranscribeFfmpeg(array("ffmpeg" => "/nonexistent/ffmpeg")) === null);
check("ffmpeg: a configured executable", ftConversationalUiTranscribeFfmpeg(array("ffmpeg" => $fakeOk)) === $fakeOk);
check("ffmpeg: not configured, none found: none", ftConversationalUiTranscribeFfmpeg(array("ffmpeg" => null)) === null);
$GLOBALS["detected"] = "/opt/found/ffmpeg";
check("ffmpeg: not configured: FrameTrail's", ftConversationalUiTranscribeFfmpeg(array("ffmpeg" => null)) === "/opt/found/ffmpeg");
unset($GLOBALS["detected"]);


// Administrators see how the sound is sent.

$speech = array("transcription" => array("baseUrl" => "http://127.0.0.1:8000/v1", "maxBytes" => 5000));
$statusAs = function($secrets, $login, $bearer = false) use ($actions, $context) {
    $GLOBALS["secrets"] = $secrets;
    $GLOBALS["login"]   = $login;
    $GLOBALS["bearer"]  = $bearer;
    $answer = call_user_func($actions["conversationalUiStatus"], $context);
    unset($GLOBALS["secrets"], $GLOBALS["login"], $GLOBALS["bearer"]);
    return $answer["response"]["capabilities"]["transcription"];
};
$admin = array("id" => "1", "name" => "Admin", "role" => "admin", "active" => 1);
$got = $statusAs($speech, $admin);
check("status, an administrator: the video sent as it is without ffmpeg, the limit", $got === array("available" => true, "audio" => "file", "maxBytes" => 5000), json_encode($got));
$GLOBALS["detected"] = "/opt/found/ffmpeg";
$got = $statusAs($speech, $admin);
check("status, an administrator: the sound taken out with ffmpeg", $got["audio"] === "ffmpeg", json_encode($got));
$got = $statusAs(array("transcription" => array("baseUrl" => "http://127.0.0.1:8000/v1", "ffmpeg" => false)), $admin);
check("status, an administrator: ffmpeg switched off", $got["audio"] === "file", json_encode($got));
$got = $statusAs($speech, array("id" => "7", "role" => "user", "active" => 1));
check("status, a user: nothing about the server's set-up", $got === array("available" => true), json_encode($got));
$got = $statusAs($speech, $admin, true);
check("status, an administrator's personal API token: nothing about the set-up", $got === array("available" => true), json_encode($got));
unset($GLOBALS["detected"]);


// The command.

$command = ftConversationalUiTranscribeCommand("/x/ffmpeg", array("path" => "/d/v.mp4", "in" => 2.5, "out" => 64), "mp3", "/t/a.mp3");
check("command: the clip's span as mono 16 kHz mp3, the video and subtitles left out",
    $command === array("/x/ffmpeg", "-nostdin", "-hide_banner", "-loglevel", "error", "-y", "-ss", "2.500", "-i", "/d/v.mp4", "-t", "61.500",
        "-vn", "-sn", "-dn", "-ac", "1", "-ar", "16000", "-c:a", "libmp3lame", "-b:a", "48k", "-f", "mp3", "/t/a.mp3"), json_encode($command));
$command = ftConversationalUiTranscribeCommand("ffmpeg", array("path" => "/d/v.mp4", "in" => 0, "out" => 0), "flac", "/t/a.flac");
check("command: the whole video, flac", !in_array("-ss", $command, true) && !in_array("-t", $command, true)
    && array_slice($command, -5) === array("-c:a", "flac", "-f", "flac", "/t/a.flac"), json_encode($command));
$command = ftConversationalUiTranscribeCommand("ffmpeg", array("path" => "/d/v.mp4", "in" => 0, "out" => 0), "wav", "/t/a.wav");
check("command: wav", array_slice($command, -5) === array("-c:a", "pcm_s16le", "-f", "wav", "/t/a.wav"), json_encode($command));


// Admission: in this order, set up, signed in, active, no token, the request, the hypervideo, its video, its size.

$tAdmit = function($secrets, $login, $body, $bearer = false) {
    $GLOBALS["secrets"] = $secrets;
    if ($login === null) { unset($GLOBALS["login"]); } else { $GLOBALS["login"] = $login; }
    $GLOBALS["bearer"] = $bearer;
    $result = ftConversationalUiTranscribeAdmit($body);
    unset($GLOBALS["secrets"], $GLOBALS["login"], $GLOBALS["bearer"]);
    return $result;
};
$tRefusal = function($description, $result, $status, $code, $words = null) {
    $ok = is_array($result) && isset($result["failure"]) && $result["status"] === $status && $result["code"] === $code
        && ($words === null || stripos($result["message"], $words) !== false);
    check("transcription admit: " . $description, $ok, json_encode($result));
};
$tSecrets = array("transcription" => array("baseUrl" => "http://127.0.0.1:9/v1", "ffmpeg" => false, "maxBytes" => 1048576));
$creator  = array("id" => "7", "name" => "Tester", "role" => "user", "active" => 1);
$ask      = function($id, $language = null) { return json_encode(array("hypervideoId" => $id, "language" => $language)); };

$tRefusal("not set up: notConfigured, 503 (before anything else)", $tAdmit(array(), null, $ask("1")), 503, "notConfigured");
$tRefusal("an invalid baseUrl: notConfigured", $tAdmit(array("transcription" => array("baseUrl" => "javascript:x")), $creator, $ask("1")), 503, "notConfigured");
$tRefusal("signed out: login, 401", $tAdmit($tSecrets, null, $ask("1")), 401, "login");
$tRefusal("an inactive account: notAllowed, 403", $tAdmit($tSecrets, "inactive", $ask("1")), 403, "notAllowed");
$tRefusal("a personal API token: notAllowed, 403", $tAdmit($tSecrets, $creator, $ask("1"), true), 403, "notAllowed", "token");
$tRefusal("an empty request: 400", $tAdmit($tSecrets, $creator, ""), 400, null);
$tRefusal("over 64 KB: 413", $tAdmit($tSecrets, $creator, json_encode(array("hypervideoId" => "1", "x" => str_repeat("a", 70000)))), 413, null);
$tRefusal("not a JSON object: 400", $tAdmit($tSecrets, $creator, "[1]"), 400, null, "JSON object");
$tRefusal("no hypervideo: 400", $tAdmit($tSecrets, $creator, "{}"), 400, null, "no hypervideo");
$tRefusal("a hypervideo id that is a path: 400", $tAdmit($tSecrets, $creator, $ask("../1")), 400, null, "no hypervideo");
$tRefusal("a language that is no code: 400", $tAdmit($tSecrets, $creator, $ask("1", "German")), 400, null, "language");
$tRefusal("a hypervideo that does not exist: notFound, 404", $tAdmit($tSecrets, $creator, $ask("99")), 404, "notFound");
$tRefusal("a hypervideo whose folder is outside hypervideos/: notFound", $tAdmit($tSecrets, $creator, $ask("6")), 404, "notFound");
$tRefusal("someone else's hypervideo: notAllowed, 403", $tAdmit($tSecrets, $creator, $ask("2")), 403, "notAllowed", "creator");
$tRefusal("a video from another site: noFile, 422", $tAdmit($tSecrets, $creator, $ask("4")), 422, "noFile", "other sites");
$tRefusal("no video: noFile", $tAdmit($tSecrets, $creator, $ask("5")), 422, "noFile", "no video");
$tRefusal("a missing file: noFile", $tAdmit($tSecrets, $creator, $ask("9")), 422, "noFile", "missing");
$tRefusal("a src that is a path: noFile", $tAdmit($tSecrets, $creator, $ask("10")), 422, "noFile", "missing");
$tRefusal("without ffmpeg, a video over maxBytes: tooLarge, 413, in megabytes", $tAdmit($tSecrets, $creator, $ask("8")), 413, "tooLarge", "(1.5 MB) is larger than the 1 MB that may be sent, and this server has no ffmpeg");

$admitted = $tAdmit($tSecrets, $creator, $ask("1", "de"));
check("transcription admit: the creator's own hypervideo, its file, its span, the language",
    !isset($admitted["failure"]) && $admitted["hypervideoId"] === "1" && $admitted["language"] === "de" && $admitted["ffmpeg"] === null
    && $admitted["source"] === array("path" => realpath($video), "name" => "1_lecture.mp4", "bytes" => filesize($video), "in" => 0.0, "out" => 0.0)
    && $admitted["user"] === $creator && $admitted["transcription"]["maxBytes"] === 1048576, json_encode($admitted));
$admitted = $tAdmit($tSecrets, $creator, json_encode(array("hypervideoId" => 3, "language" => "")));
check("transcription admit: a number as id, an empty language (none), the video through the clip's resource, in and out",
    !isset($admitted["failure"]) && $admitted["hypervideoId"] === "3" && $admitted["language"] === null
    && $admitted["source"]["name"] === "1_lecture.mp4" && $admitted["source"]["in"] === 2.0 && $admitted["source"]["out"] === 4.0, json_encode($admitted));
$admitted = $tAdmit($tSecrets, $admin, $ask("2"));
check("transcription admit: an administrator, someone else's hypervideo", !isset($admitted["failure"]), json_encode($admitted));
$admitted = $tAdmit(array("transcription" => array("baseUrl" => "http://127.0.0.1:9/v1", "ffmpeg" => $fakeOk, "maxBytes" => 1048576)), $creator, $ask("8"));
check("transcription admit: with ffmpeg, a video over maxBytes may go (its sound is smaller)", !isset($admitted["failure"]) && $admitted["ffmpeg"] === $fakeOk, json_encode($admitted));


// Taking the sound out, with the stand-ins.

$target  = scratch() . "/sound.mp3";
$source3 = array("path" => realpath($video), "in" => 2.0, "out" => 4.0);
$never   = function() { return true; };

$failure = ftConversationalUiTranscribeExtract(ftConversationalUiTranscribeCommand($fakeOk, $source3, "mp3", $target), $target, microtime(true) + 30, 1800, $never);
check("extract: ffmpeg run with the command, the sound written", $failure === null && file_get_contents($target) === "ID3fake-audio"
    && file($ffmpegLog, FILE_IGNORE_NEW_LINES) === array_slice(ftConversationalUiTranscribeCommand($fakeOk, $source3, "mp3", $target), 1), json_encode($failure));
$failure = ftConversationalUiTranscribeExtract(ftConversationalUiTranscribeCommand($fakeFail, $source3, "mp3", $target), $target, microtime(true) + 30, 1800, $never);
check("extract: ffmpeg failing: its words", is_array($failure) && $failure["status"] === 500 && strpos($failure["message"], "Invalid data found") !== false, json_encode($failure));
@unlink($target);
$failure = ftConversationalUiTranscribeExtract(ftConversationalUiTranscribeCommand($fakeEmpty, $source3, "mp3", $target), $target, microtime(true) + 30, 1800, $never);
check("extract: no sound written: a failure", is_array($failure) && strpos($failure["message"], "no sound") !== false, json_encode($failure));

@unlink($ffmpegPid);
$started = microtime(true);
$failure = ftConversationalUiTranscribeExtract(ftConversationalUiTranscribeCommand($fakeSlow, $source3, "mp3", $target), $target, microtime(true) + 30, 1800,
    function() use ($started) { return microtime(true) - $started < 0.6; });
$pid = (int)@file_get_contents($ffmpegPid);
usleep(200000);
check("extract: the browser gone: ffmpeg stopped at once, code stopped", is_array($failure) && $failure["code"] === "stopped"
    && microtime(true) - $started < 3 && $pid > 0 && !$alive($pid), json_encode($failure) . " pid " . $pid);
@unlink($ffmpegPid);
$failure = ftConversationalUiTranscribeExtract(ftConversationalUiTranscribeCommand($fakeSlow, $source3, "mp3", $target), $target, microtime(true) + 0.6, 1800, $never);
$pid = (int)@file_get_contents($ffmpegPid);
usleep(200000);
check("extract: past the deadline: stopped, timeout, 504, in minutes", is_array($failure) && $failure["code"] === "timeout" && $failure["status"] === 504
    && strpos($failure["message"], "30 minutes") !== false && $pid > 0 && !$alive($pid), json_encode($failure));

if ($realFfmpeg !== null && $realFfprobe !== null) {
    foreach (array("mp3" => "mp3", "flac" => "flac", "wav" => "pcm_s16le") as $format => $codec) {
        $target  = scratch() . "/real." . $format;
        $failure = ftConversationalUiTranscribeExtract(ftConversationalUiTranscribeCommand($realFfmpeg, $source3, $format, $target), $target, microtime(true) + 60, 1800, $never);
        $probe   = json_decode(shell_exec(escapeshellarg($realFfprobe) . " -v error -show_entries format=duration:stream=codec_name,channels,sample_rate -of json " . escapeshellarg($target)), true);
        $stream  = isset($probe["streams"][0]) ? $probe["streams"][0] : array();
        check("extract with ffmpeg, " . $format . ": the clip's 2 seconds, mono, 16 kHz",
            $failure === null && isset($stream["codec_name"]) && $stream["codec_name"] === $codec && (int)$stream["channels"] === 1
            && (int)$stream["sample_rate"] === 16000 && abs((float)$probe["format"]["duration"] - 2.0) < 0.15, json_encode($failure) . json_encode($probe));
    }
} else {
    skip("extract with ffmpeg: the clip's span, mono, 16 kHz", "no ffmpeg/ffprobe on this machine");
}


// What the speech server's answers become.

$transcription = array("apiKey" => "sk-SPEECH", "timeout" => 1800);
$read = function($result, $offset = 0) use ($transcription) {
    return ftConversationalUiTranscribeResult(array_merge(array("status" => 0, "body" => "", "error" => 0, "stopped" => false), $result), $transcription, $offset);
};

$r = $read(array("error" => 7));
check("speech answer: none: 502", isset($r["failure"]) && $r["status"] === 502 && $r["code"] === null, json_encode($r));
$r = $read(array("error" => 28));
check("speech answer: none in time: timeout, 504, in minutes", isset($r["failure"]) && $r["status"] === 504 && $r["code"] === "timeout" && strpos($r["message"], "30 minutes") !== false, json_encode($r));
$r = $read(array("status" => 401, "body" => '{"detail":"Unauthorized"}'));
check("speech answer: the key refused: notConfigured, 502", isset($r["failure"]) && $r["status"] === 502 && $r["code"] === "notConfigured", json_encode($r));
$r = $read(array("status" => 413, "body" => ""));
check("speech answer: too large: tooLarge, 413", isset($r["failure"]) && $r["status"] === 413 && $r["code"] === "tooLarge", json_encode($r));
$r = $read(array("status" => 500, "body" => '{"detail":"The model is not loaded"}'));
check("speech answer: an error: its status and words", isset($r["failure"]) && $r["status"] === 500 && $r["code"] === null
    && $r["message"] === "The speech server answered with status 500: The model is not loaded", json_encode($r));
$r = $read(array("status" => 400, "body" => '{"error":{"message":"Bearer sk-SPEECH is no good"}}'));
check("speech answer: OpenAI's error shape, the key taken out", isset($r["failure"]) && strpos($r["message"], "sk-SPEECH") === false && strpos($r["message"], "[key] is no good") !== false, json_encode($r));
$r = $read(array("status" => 502, "body" => "<html><body><h1>502 Bad Gateway – proxy</h1></body></html>"));
check("speech answer: an error page: its text in plain ASCII", isset($r["failure"]) && $r["message"] === "The speech server answered with status 502: 502 Bad Gateway ? proxy", json_encode($r));
$r = $read(array("status" => 302, "body" => ""));
check("speech answer: a redirect (never followed): 502", isset($r["failure"]) && $r["status"] === 502, json_encode($r));
$r = $read(array("status" => 200, "body" => '{"text":"Hello"}'));
check("speech answer: no segments: a server without verbose_json", isset($r["failure"]) && $r["status"] === 502 && strpos($r["message"], "verbose_json") !== false, json_encode($r));
$r = $read(array("status" => 200, "body" => json_encode(array("language" => " german ", "duration" => 5, "text" => "x", "segments" => array(
    array("id" => 0, "start" => 0, "end" => 2.5, "text" => " Hallo.", "tokens" => array(1, 2), "avg_logprob" => -0.2),
    array("start" => "1", "end" => 2, "text" => "a string start"),
    array("start" => 3, "end" => 4),
    "not a segment",
    array("start" => 2.5, "end" => 5, "text" => " Welt.")
)))), 2.0);
check("speech answer: segments with times and text only, the language trimmed, the offset",
    $r === array("language" => "german", "duration" => 5.0, "offset" => 2.0, "segments" => array(
        array("start" => 0.0, "end" => 2.5, "text" => " Hallo."), array("start" => 2.5, "end" => 5.0, "text" => " Welt."))), json_encode($r));
$r = $read(array("status" => 200, "body" => '{"segments":[]}'));
check("speech answer: no speech: no segments, no language", $r === array("language" => null, "duration" => null, "offset" => 0.0, "segments" => array()), json_encode($r));


// Headers.

$speechConfig = array("instance" => "project-7", "transcription" => array("apiKey" => "sk-SPEECH\r\nX-Evil: 1"));
$headers = ftConversationalUiTranscribeHeaders($speechConfig, array("id" => "7"));
check("speech headers: the key, JSON, no 100-continue, who is asking from where, no line breaks",
    $headers === array("Authorization: Bearer sk-SPEECH X-Evil: 1", "Accept: application/json", "Expect:", "X-FrameTrail-User: 7", "X-FrameTrail-Instance: project-7"), json_encode($headers));
$headers = ftConversationalUiTranscribeHeaders(array("instance" => "project-7", "transcription" => array("apiKey" => null)), array("id" => "7"));
check("speech headers: no key, no Authorization", count(preg_grep('/^Authorization/', $headers)) === 0, json_encode($headers));

check("types: by the file's extension", ftConversationalUiTranscribeMime("1_x.MP4") === "video/mp4" && ftConversationalUiTranscribeMime("a.webm") === "video/webm"
    && ftConversationalUiTranscribeMime("a.m4a") === "audio/mp4" && ftConversationalUiTranscribeMime("a.xyz") === "application/octet-stream");
check("sizes: megabytes with one decimal, kilobytes below a megabyte", ftConversationalUiTranscribeMegabytes(104857600) === "100 MB"
    && ftConversationalUiTranscribeMegabytes(1572864) === "1.5 MB" && ftConversationalUiTranscribeMegabytes(5000) === "5 KB");


// Temporary files.

$GLOBALS["storage"] = scratch() . "/temp-storage";
mkdir($GLOBALS["storage"] . "/tmp", 0775, true);
file_put_contents($GLOBALS["storage"] . "/tmp/transcribe-old.mp3", "x");
touch($GLOBALS["storage"] . "/tmp/transcribe-old.mp3", time() - 2 * 86400);
file_put_contents($GLOBALS["storage"] . "/tmp/transcribe-recent.mp3", "x");
file_put_contents($GLOBALS["storage"] . "/tmp/other.txt", "x");
touch($GLOBALS["storage"] . "/tmp/other.txt", time() - 2 * 86400);
$file = ftConversationalUiTranscribeTempFile("mp3");
check("temporary file: an absolute path in the private folder's tmp/, leftovers older than a day removed, nothing else",
    dirname($file) === realpath($GLOBALS["storage"] . "/tmp") && substr($file, -4) === ".mp3" && !file_exists($GLOBALS["storage"] . "/tmp/transcribe-old.mp3")
    && file_exists($GLOBALS["storage"] . "/tmp/transcribe-recent.mp3") && file_exists($GLOBALS["storage"] . "/tmp/other.txt"), $file);
$GLOBALS["storage"] = false;
$file = ftConversationalUiTranscribeTempFile("wav");
check("temporary file: without a private folder, the system's", dirname($file) === realpath(sys_get_temp_dir()), $file);
$GLOBALS["storage"] = "relative-" . getmypid();
mkdir(getcwd() . "/" . $GLOBALS["storage"] . "/tmp", 0775, true);
$file = ftConversationalUiTranscribeTempFile("mp3");
check("temporary file: absolute even when the private folder is given relative to the working directory", $file[0] === "/" && dirname($file) === realpath($GLOBALS["storage"] . "/tmp"), $file);
rmdir($GLOBALS["storage"] . "/tmp");
rmdir($GLOBALS["storage"]);
$GLOBALS["storage"] = scratch() . "/count";


/* ---------------------------------------------------------------------- */
/*  The relay over HTTP                                                   */
/* ---------------------------------------------------------------------- */

function freePort() {

    $server = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
    $name   = stream_socket_get_name($server, false);
    fclose($server);

    return (int)substr(strrchr($name, ":"), 1);

}

// PHP's built-in server with a router script; null when it does not come up.
function startServer($router, $env, $ini = array()) {

    $port    = freePort();
    $command = array(PHP_BINARY);
    foreach ($ini as $setting) {
        $command[] = "-d";
        $command[] = $setting;
    }
    $command = array_merge($command, array("-S", "127.0.0.1:" . $port, $router));

    $log     = scratch() . "/server-" . $port . ".log";
    $process = proc_open($command, array(0 => array("file", "/dev/null", "r"), 1 => array("file", $log, "a"), 2 => array("file", $log, "a")),
        $pipes, dirname($router), array_merge(getenv(), $env));

    if (!is_resource($process)) {
        return null;
    }

    register_shutdown_function(function() use ($process) {
        proc_terminate($process);
        proc_close($process);
    });

    for ($i = 0; $i < 100; $i++) {
        $socket = @fsockopen("127.0.0.1", $port, $errno, $error, 0.1);
        if ($socket) {
            fclose($socket);
            return array("port" => $port, "log" => $log);
        }
        usleep(50000);
    }

    return null;

}

// A request as the browser sends it; the answer with the time each piece arrived.
function request($url, $headers, $body = null, $method = "POST", $abortAfter = null) {

    $answer = array("status" => 0, "headers" => array(), "raw" => "", "body" => "", "times" => array(), "total" => 0);
    $start  = microtime(true);
    $curl   = curl_init($url);

    curl_setopt_array($curl, array(
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADERFUNCTION => function($curl, $line) use (&$answer) {
            $answer["raw"] .= $line;
            if (strpos($line, ":") !== false) {
                list($name, $value) = explode(":", $line, 2);
                $answer["headers"][strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function($curl, $chunk) use (&$answer, $start, $abortAfter) {
            $answer["body"]   .= $chunk;
            $answer["times"][] = microtime(true) - $start;
            return ($abortAfter !== null && microtime(true) - $start > $abortAfter) ? 0 : strlen($chunk);
        }
    ));

    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    curl_exec($curl);
    $answer["status"] = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $answer["total"]  = microtime(true) - $start;
    curl_close($curl);

    return $answer;

}

$upstreamLog = scratch() . "/long.log";
$upstreamDir = scratch() . "/upstream";
mkdir($upstreamDir);
$upstream    = startServer(__DIR__ . "/relay/upstream.php", array("RELAY_UPSTREAM_LOG" => $upstreamLog, "RELAY_UPSTREAM_DIR" => $upstreamDir),
    array("output_buffering=0", "upload_max_filesize=64M", "post_max_size=64M"));
// The relay's host as php.ini-development sets it up: its output buffer must not hold the stream back.
// Transcription's progress every half second here, ffmpeg found where it is installed.
$relay       = startServer(__DIR__ . "/relay/harness.php",
    array("RELAY_SERVER_DIR" => $dir, "RELAY_STORAGE" => scratch() . "/http-storage", "RELAY_DATA" => $data,
          "RELAY_TICK" => "0.5", "RELAY_FFMPEG" => ($realFfmpeg !== null) ? $realFfmpeg : ""),
    array("output_buffering=4096"));

check("the stand-in for Mistral's API runs", $upstream !== null);
check("the stand-in for FrameTrail's routers runs", $relay !== null);

if ($upstream === null || $relay === null) {
    finish();
}

$key     = "sk-test-" . bin2hex(random_bytes(12));
$route   = "http://127.0.0.1:" . $relay["port"] . "/extension.php?e=conversational-ui&r=relay";
$action  = "http://127.0.0.1:" . $relay["port"] . "/ajaxServer.php";
$models  = array("stream-ok", "echo", "long", "bad-key", "rate", "zero", "gateway-quota", "broken", "leak", "html");
$secrets = array("apiKey" => $key, "baseUrl" => "http://127.0.0.1:" . $upstream["port"] . "/v1", "allowedModels" => $models, "instance" => "test-instance");
$answers = array();

// The scene's headers; a user given in $more replaces user 7 (two headers of one name are read differently by PHP versions).
$scene = function($secrets, $more = array()) {
    $user = count(preg_grep('/^X-Test-User:/i', $more)) ? array() : array("X-Test-User: 7");
    return array_merge(array("X-Test-Secrets: " . base64_encode(json_encode($secrets))), $user, $more);
};
$json = array("Content-Type: application/json");

$send = function($model, $more = array(), $secretsOverride = null, $abortAfter = null) use (&$answers, $scene, $route, $secrets, $json) {
    $answer = request($route, array_merge($scene($secretsOverride !== null ? $secretsOverride : $secrets, $more), $json),
        json_encode(array("model" => $model, "stream" => true, "messages" => array(array("role" => "user", "content" => "Hi")))), "POST", $abortAfter);
    $answers[] = $answer;
    return $answer;
};

$chat = function($model, $more = array()) use (&$answers, $scene, $action, $secrets) {
    $answer = request($action, $scene($secrets, $more),
        http_build_query(array("a" => "conversationalUiChat", "request" => json_encode(array("model" => $model, "messages" => array(array("role" => "user", "content" => "Hi")))))));
    $answers[] = $answer;
    $answer["json"] = json_decode($answer["body"], true);
    return $answer;
};

// The stream: piece by piece, as the service sends it.
$direct = request("http://127.0.0.1:" . $upstream["port"] . "/v1/chat/completions", $json, json_encode(array("model" => "stream-ok", "stream" => true)));
$a = $send("stream-ok");
check("route: a stream answers 200 as server-sent events, not buffered by proxies",
    $a["status"] === 200 && strpos($a["headers"]["content-type"], "text/event-stream") === 0 && $a["headers"]["x-accel-buffering"] === "no"
    && strpos($a["headers"]["cache-control"], "no-transform") !== false, $a["raw"]);
check("route: the stream's bytes are the service's", $a["body"] === $direct["body"] && $a["body"] !== "", $a["body"]);
check("route: the stream arrives piece by piece (the first piece well before the last)",
    count($a["times"]) >= 3 && end($a["times"]) - $a["times"][0] > 0.4, json_encode($a["times"]));

// What reaches the service.
$body = json_encode(array("model" => "echo", "stream" => true, "messages" => array(array("role" => "user", "content" => str_repeat("é", 2000)))), JSON_UNESCAPED_UNICODE);
$a = request($route, array_merge($scene($secrets), $json), $body);
$answers[] = $a;
preg_match('/^data: (.*)$/m', $a["body"], $match);
$seen = json_decode(json_decode($match[1], true)["choices"][0]["delta"]["content"], true);
check("route: the service gets the key, the request's bytes unchanged, no 100-continue",
    is_array($seen) && $seen["authorization"] === sha1("Bearer " . $key) && $seen["body"] === sha1($body) && $seen["expect"] === null
    && $seen["accept"] === "text/event-stream", json_encode($seen));
check("route: a gateway gets who is asking and from where", is_array($seen) && $seen["user"] === "7" && $seen["instance"] === "test-instance", json_encode($seen));

$a = $chat("echo", array("X-Test-External: 4711"));
$seen = json_decode($a["json"]["response"]["choices"][0]["message"]["content"], true);
check("action: a gateway gets the platform's subject under external authentication", is_array($seen) && $seen["user"] === "4711" && $seen["accept"] === "application/json", $a["body"]);

// The action.
$a = $chat("stream-ok");
check("action: success with the completion as it came ({} stays {})",
    $a["json"]["status"] === "success" && $a["json"]["code"] === 0 && $a["json"]["response"]["choices"][0]["message"]["content"] === "Hello there"
    && strpos($a["body"], '"metadata": {}') !== false, $a["body"]);
$a = $chat("rate");
check("action: a rate limit: fail, 429, the service's error as upstream, retryAfter",
    $a["json"]["status"] === "fail" && $a["json"]["code"] === 429 && $a["json"]["upstream"]["message"] === "Rate limit exceeded" && $a["json"]["retryAfter"] === 7, $a["body"]);
$a = $chat("html");
check("action: an error page that is not JSON: upstream { message } in plain ASCII",
    $a["json"]["code"] === 502 && is_string($a["json"]["upstream"]["message"]) && strpos($a["json"]["upstream"]["message"], "Bad Gateway ? proxy") !== false, $a["body"]);
$a = $chat("bad-key");
check("action: the server's key refused: error.code notConfigured", $a["json"]["status"] === "fail" && $a["json"]["error"]["code"] === "notConfigured", $a["body"]);
$a = request($action, array("X-Test-Secrets: " . base64_encode(json_encode($secrets))), http_build_query(array("a" => "conversationalUiChat", "request" => '{"model":"echo"}')));
$answers[] = $a;
$a["json"] = json_decode($a["body"], true);
check("action: signed out: fail, 401, error.code login", $a["json"]["status"] === "fail" && $a["json"]["code"] === 401 && $a["json"]["error"]["code"] === "login", $a["body"]);

// What the service's failures become on the route.
$a = $send("rate");
check("route: a rate limit: 429 with Retry-After and the service's body", $a["status"] === 429 && $a["headers"]["retry-after"] === "7" && json_decode($a["body"], true)["message"] === "Rate limit exceeded", $a["raw"] . $a["body"]);
$a = $send("zero");
check("route: a model the key may never use: 400 in words that name the model", $a["status"] === 400 && strpos(json_decode($a["body"], true)["error"]["message"], "model zero") !== false, $a["body"]);
$a = $send("bad-key");
check("route: the server's key refused: 502 notConfigured", $a["status"] === 502 && json_decode($a["body"], true)["error"]["code"] === "notConfigured", $a["body"]);
$a = $send("gateway-quota");
check("route: a gateway's quota goes through as it is", $a["status"] === 429 && $a["headers"]["retry-after"] === "3600" && json_decode($a["body"], true)["error"]["code"] === "quota", $a["raw"] . $a["body"]);
$a = $send("broken");
check("route: the service failing: its 500 and body", $a["status"] === 500 && json_decode($a["body"], true)["message"] === "Internal server error", $a["body"]);
$a = $send("stream-ok", array(), array_merge($secrets, array("baseUrl" => "http://127.0.0.1:" . freePort() . "/v1")));
check("route: the service unreachable: 502", $a["status"] === 502 && isset(json_decode($a["body"], true)["error"]["message"]), $a["body"]);

// Refusals on the route.
$a = request($route, $scene($secrets), null, "GET");
$answers[] = $a;
check("route: GET is refused, 405, Allow: POST", $a["status"] === 405 && $a["headers"]["allow"] === "POST", $a["raw"]);
$a = request($route, array_merge($scene($secrets), array("Content-Type: text/plain")), '{"model":"echo","stream":true}');
$answers[] = $a;
check("route: a body that is not declared JSON is refused, 415", $a["status"] === 415, $a["raw"]);
$a = request($route, array_merge(array("X-Test-Secrets: " . base64_encode(json_encode($secrets))), $json), '{"model":"echo","stream":true}');
$answers[] = $a;
check("route: signed out: 401 login", $a["status"] === 401 && json_decode($a["body"], true)["error"]["code"] === "login", $a["body"]);
$a = $send("echo", array("X-Test-Bearer: 1"));
check("route: a personal API token: 403 notAllowed", $a["status"] === 403 && json_decode($a["body"], true)["error"]["code"] === "notAllowed", $a["body"]);
$limited = array_merge($secrets, array("requestsPerDay" => 2));
$send("stream-ok", array("X-Test-User: 8"), $limited);
$send("stream-ok", array("X-Test-User: 8"), $limited);
$a = $send("stream-ok", array("X-Test-User: 8"), $limited);
check("route: the day's requests used up: 429 quota with Retry-After",
    $a["status"] === 429 && json_decode($a["body"], true)["error"]["code"] === "quota" && (int)$a["headers"]["retry-after"] > 0, $a["raw"] . $a["body"]);
$a = $send("stream-ok", array("X-Test-User: 9"), $limited);
check("route: another user is not held up by it", $a["status"] === 200, $a["raw"]);

// Stop: the reader goes away, the request to the service ends.
@unlink($upstreamLog);
$a = $send("long", array(), null, 0.5);
usleep(1500000);
$sent = (int)@file_get_contents($upstreamLog);
check("route: when the panel stops reading, the request to the service ends too", $sent > 0 && $sent < 20, "the service sent " . $sent . " of 20 pieces");

/* ---------------------------------------------------------------------- */
/*  Transcription over HTTP                                               */
/* ---------------------------------------------------------------------- */

// Server-sent events as the panel reads them: array of { event, data }.
function events($body) {

    $events = array();

    foreach (preg_split('/\n\n+/', trim($body)) as $block) {
        $name = "message";
        $data = "";
        foreach (explode("\n", $block) as $line) {
            if (strpos($line, "event: ") === 0) {
                $name = substr($line, 7);
            } elseif (strpos($line, "data: ") === 0) {
                $data .= substr($line, 6);
            }
        }
        if ($data !== "") {
            $events[] = array("event" => $name, "data" => json_decode($data, true));
        }
    }

    return $events;

}

$speechKey   = "sk-speech-" . bin2hex(random_bytes(12));
$transcribe  = "http://127.0.0.1:" . $relay["port"] . "/extension.php?e=conversational-ui&r=transcribe";
$speechBlock = array("baseUrl" => "http://127.0.0.1:" . $upstream["port"] . "/v1", "apiKey" => $speechKey, "model" => "whisper-ok", "ffmpeg" => false, "timeout" => 60);

$transcribeAs = function($block, $body, $more = array(), $abortAfter = null) use (&$answers, $scene, $transcribe, $json, $upstreamDir) {
    @unlink($upstreamDir . "/seen.json");
    @unlink($upstreamDir . "/upload.bin");
    $answer = request($transcribe, array_merge($scene(array("instance" => "test-instance", "transcription" => $block), $more), $json),
        is_string($body) ? $body : json_encode($body), "POST", $abortAfter);
    $answers[] = $answer;
    $answer["events"] = events($answer["body"]);
    $answer["last"]   = count($answer["events"]) ? end($answer["events"]) : null;
    $answer["seen"]   = json_decode((string)@file_get_contents($upstreamDir . "/seen.json"), true);
    return $answer;
};

$stages = function($answer) {
    return array_values(array_unique(array_map(function($event) { return $event["data"]["stage"]; },
        array_filter($answer["events"], function($event) { return $event["event"] === "progress"; }))));
};

$errorOf = function($answer) {
    return ($answer["last"] !== null && $answer["last"]["event"] === "error") ? $answer["last"]["data"]["error"] : null;
};

// Refusals, before anything is streamed.
$a = request($transcribe, $scene(array("transcription" => $speechBlock)), null, "GET");
$answers[] = $a;
check("transcribe: GET is refused, 405", $a["status"] === 405 && $a["headers"]["allow"] === "POST", $a["raw"]);
$a = request($transcribe, array_merge($scene(array("transcription" => $speechBlock)), array("Content-Type: text/plain")), '{"hypervideoId":"1"}');
$answers[] = $a;
check("transcribe: a body not declared JSON is refused, 415", $a["status"] === 415, $a["raw"]);
$a = request($transcribe, array_merge($scene(array()), $json), '{"hypervideoId":"1"}');
$answers[] = $a;
check("transcribe: not set up: 503 notConfigured, as JSON", $a["status"] === 503 && json_decode($a["body"], true)["error"]["code"] === "notConfigured", $a["body"]);
$a = request($transcribe, array_merge(array("X-Test-Secrets: " . base64_encode(json_encode(array("transcription" => $speechBlock)))), $json), '{"hypervideoId":"1"}');
$answers[] = $a;
check("transcribe: signed out: 401 login", $a["status"] === 401 && json_decode($a["body"], true)["error"]["code"] === "login", $a["body"]);
$a = $transcribeAs($speechBlock, array("hypervideoId" => "1"), array("X-Test-Bearer: 1"));
check("transcribe: a personal API token: 403 notAllowed", $a["status"] === 403 && json_decode($a["body"], true)["error"]["code"] === "notAllowed", $a["body"]);
$a = $transcribeAs($speechBlock, array("hypervideoId" => "2"));
check("transcribe: someone else's hypervideo: 403 notAllowed", $a["status"] === 403 && json_decode($a["body"], true)["error"]["code"] === "notAllowed", $a["body"]);
$a = $transcribeAs($speechBlock, array("hypervideoId" => "2"), array("X-Test-Role: admin"));
check("transcribe: an administrator may transcribe someone else's", $a["status"] === 200 && $a["last"]["event"] === "result", $a["body"]);
$a = $transcribeAs($speechBlock, array("hypervideoId" => "4"));
check("transcribe: a video from another site: 422 noFile", $a["status"] === 422 && json_decode($a["body"], true)["error"]["code"] === "noFile", $a["body"]);
$a = $transcribeAs($speechBlock, array("hypervideoId" => "99"));
check("transcribe: no such hypervideo: 404 notFound", $a["status"] === 404 && json_decode($a["body"], true)["error"]["code"] === "notFound", $a["body"]);

// The video as it is (no ffmpeg).
$a = $transcribeAs($speechBlock, array("hypervideoId" => "1", "language" => "de"));
check("transcribe: 200 as server-sent events, not buffered by proxies",
    $a["status"] === 200 && strpos($a["headers"]["content-type"], "text/event-stream") === 0 && $a["headers"]["x-accel-buffering"] === "no"
    && strpos($a["headers"]["cache-control"], "no-transform") !== false, $a["raw"]);
check("transcribe: progress (sending, then transcribing), then the result", $stages($a) === array("sending", "transcribing") && $a["last"]["event"] === "result", $a["body"]);
check("transcribe: the result: language, duration, offset 0, segments with times and text only",
    $a["last"]["data"] === array("language" => "en", "duration" => 5.0, "offset" => 0.0, "segments" => array(
        array("start" => 0.0, "end" => 2.5, "text" => " Hello."), array("start" => 2.5, "end" => 5.0, "text" => " World & <more>."))), json_encode($a["last"]));
$seen = $a["seen"];
check("transcribe: the speech server gets the video as it is, with its name and type",
    is_array($seen) && $seen["name"] === "1_lecture.mp4" && $seen["type"] === "video/mp4" && $seen["size"] === filesize($video) && $seen["sha1"] === sha1_file($video), json_encode($seen));
check("transcribe: the speech server gets the model, verbose_json, segments, the language, its key, who asks from where, no 100-continue",
    is_array($seen) && $seen["model"] === "whisper-ok" && $seen["response_format"] === "verbose_json" && $seen["timestamp_granularities"] === array("segment")
    && $seen["language"] === "de" && $seen["authorization"] === sha1("Bearer " . $speechKey) && $seen["user"] === "7" && $seen["instance"] === "test-instance"
    && $seen["expect"] === null && $seen["accept"] === "application/json", json_encode($seen));
$a = $transcribeAs(array_merge($speechBlock, array("apiKey" => null)), array("hypervideoId" => "1"));
check("transcribe: without a key no Authorization, without a language none sent",
    is_array($a["seen"]) && $a["seen"]["authorization"] === null && $a["seen"]["language"] === null && !in_array("language", $a["seen"]["fields"], true), json_encode($a["seen"]));

// With ffmpeg (a stand-in): the sound of the clip's span, the clip's in point as offset.
$a = $transcribeAs(array_merge($speechBlock, array("ffmpeg" => $fakeOk)), array("hypervideoId" => "3"));
check("transcribe with ffmpeg: extracting, sending, transcribing, the result with the clip's in point as offset",
    $stages($a) === array("extracting", "sending", "transcribing") && $a["last"]["event"] === "result" && $a["last"]["data"]["offset"] === 2.0, $a["body"]);
check("transcribe with ffmpeg: the speech server gets the sound, as mp3", is_array($a["seen"]) && $a["seen"]["name"] === "audio.mp3"
    && $a["seen"]["type"] === "audio/mpeg" && $a["seen"]["size"] === strlen("ID3fake-audio"), json_encode($a["seen"]));
$args = file($ffmpegLog, FILE_IGNORE_NEW_LINES);
check("transcribe with ffmpeg: only the clip's span", array_slice($args, 5, 5) === array("-ss", "2.000", "-i", realpath($video), "-t") && in_array("2.000", $args, true), json_encode($args));
$a = $transcribeAs(array_merge($speechBlock, array("ffmpeg" => $fakeOk, "audioFormat" => "flac")), array("hypervideoId" => "1"));
check("transcribe with ffmpeg: flac when configured", is_array($a["seen"]) && $a["seen"]["name"] === "audio.flac" && $a["seen"]["type"] === "audio/flac", json_encode($a["seen"]));
$a = $transcribeAs(array_merge($speechBlock, array("ffmpeg" => $fakeFail)), array("hypervideoId" => "1"));
$e = $errorOf($a);
check("transcribe with ffmpeg failing: an error event with its words, nothing sent", $e !== null && strpos($e["message"], "Invalid data found") !== false && $a["seen"] === null, $a["body"]);
$a = $transcribeAs(array_merge($speechBlock, array("ffmpeg" => $fakeBig, "maxBytes" => 4096)), array("hypervideoId" => "1"));
$e = $errorOf($a);
check("transcribe with ffmpeg: a sound over maxBytes: tooLarge, nothing sent", $e !== null && $e["code"] === "tooLarge" && $a["seen"] === null, $a["body"]);

// Progress while the speech server works, so a proxy never sees a long silence.
$a = $transcribeAs(array_merge($speechBlock, array("model" => "whisper-slow")), array("hypervideoId" => "1"));
$waiting = array_values(array_filter($a["events"], function($event) { return $event["event"] === "progress" && $event["data"]["stage"] === "transcribing"; }));
$elapsed = array_map(function($event) { return $event["data"]["elapsed"]; }, $waiting);
$sorted  = $elapsed;
sort($sorted);
check("transcribe: while the speech server works (3 s), progress at least every tick (0.5 s here), its elapsed time growing",
    count($waiting) >= 5 && $elapsed === $sorted && $elapsed[0] === 0 && end($elapsed) >= 2, json_encode($elapsed));
check("transcribe: the slow answer arrives", $a["last"]["event"] === "result", $a["body"]);
check("transcribe: keep-alive comments between the progress events", substr_count($a["body"], ": keep-alive\n\n") >= 5, $a["body"]);

// The speech server's failures, as error events.
$speechFailures = array(
    "whisper-401"        => array("notConfigured", "refused the server's key"),
    "whisper-413"        => array("tooLarge", "too large"),
    "whisper-500"        => array(null, "status 500: The model is not loaded"),
    "whisper-html"       => array(null, "status 502: 502 Bad Gateway ? proxy"),
    "whisper-nosegments" => array(null, "verbose_json"),
    "whisper-leak"       => array(null, "Refused: Bearer [key]")
);
foreach ($speechFailures as $model => $expected) {
    $a = $transcribeAs(array_merge($speechBlock, array("model" => $model)), array("hypervideoId" => "1"));
    $e = $errorOf($a);
    check("transcribe: " . $model . ": an error event" . ($expected[0] ? " with code " . $expected[0] : "") . ", in words",
        $a["status"] === 200 && $e !== null && (isset($e["code"]) ? $e["code"] : null) === $expected[0] && strpos($e["message"], $expected[1]) !== false, $a["body"]);
}
$a = $transcribeAs(array_merge($speechBlock, array("baseUrl" => "http://127.0.0.1:" . freePort() . "/v1")), array("hypervideoId" => "1"));
$e = $errorOf($a);
check("transcribe: the speech server unreachable: an error event", $e !== null && strpos($e["message"], "could not be reached") !== false, $a["body"]);

// A gateway's refusals in the relay's own words go through as they came,
// whatever their status (a 403 is not "the key was refused" then).
$a = $transcribeAs(array_merge($speechBlock, array("model" => "whisper-quota")), array("hypervideoId" => "1"));
$e = $errorOf($a);
check("transcribe: a gateway's quota: an error event with its code, period and renewal, in its words",
    $e !== null && $e["code"] === "quota" && $e["period"] === "month" && $e["resetsAt"] === "2026-11-01T00:00:00+01:00"
    && $e["message"] === "This month's minutes are used up.", $a["body"]);
$a = $transcribeAs(array_merge($speechBlock, array("model" => "whisper-notallowed")), array("hypervideoId" => "1"));
$e = $errorOf($a);
check("transcribe: a gateway's 403 notAllowed stays notAllowed", $e !== null && $e["code"] === "notAllowed" && strpos($e["message"], "plan") !== false, $a["body"]);

// A gateway's job: followed until it is done.
$lastJob = function() use ($upstreamDir) { return trim((string)@file_get_contents($upstreamDir . "/last-job")); };
$a = $transcribeAs(array_merge($speechBlock, array("model" => "whisper-job")), array("hypervideoId" => "1"));
$job = $lastJob();
$positions = array();
foreach ($a["events"] as $event) {
    if ($event["event"] === "progress" && $event["data"]["stage"] === "queued" && end($positions) !== $event["data"]["position"]) {
        $positions[] = $event["data"]["position"];
    }
}
check("transcribe: a gateway's job: queued with its place in line (2, then 1), then transcribing, then the result",
    $positions === array(2, 1) && in_array("transcribing", $stages($a), true) && $a["last"]["event"] === "result"
    && count($a["last"]["data"]["segments"]) === 2 && $a["last"]["data"]["language"] === "en", $a["body"]);
check("transcribe: a gateway's job: looked at until done, not cancelled",
    (int)@file_get_contents($upstreamDir . "/job-" . $job . ".polls") === 4 && !is_file($upstreamDir . "/job-" . $job . ".deleted"), $job);
$a = $transcribeAs(array_merge($speechBlock, array("model" => "whisper-job-fail")), array("hypervideoId" => "1"));
$e = $errorOf($a);
check("transcribe: a failed job: an error event in the gateway's words", $e !== null && !isset($e["code"]) && strpos($e["message"], "Unsupported audio") !== false, $a["body"]);
$a = $transcribeAs(array_merge($speechBlock, array("model" => "whisper-job-quota")), array("hypervideoId" => "1"));
$e = $errorOf($a);
check("transcribe: a job refused in the relay's own words: its code, period and renewal",
    $e !== null && $e["code"] === "quota" && $e["period"] === "month" && $e["resetsAt"] === "2026-11-01T00:00:00+01:00", $a["body"]);
$before = $lastJob();
$a = $transcribeAs(array_merge($speechBlock, array("model" => "whisper-job-badid")), array("hypervideoId" => "1"));
$e = $errorOf($a);
check("transcribe: a job id that is not one is not followed", $e !== null && strpos($e["message"], "verbose_json") !== false && $lastJob() === $before, $a["body"]);
$a = $transcribeAs(array_merge($speechBlock, array("model" => "whisper-job-wait")), array("hypervideoId" => "1"), array(), 1.5);
usleep(2000000);
check("transcribe: when the panel stops reading, the gateway's job is cancelled", is_file($upstreamDir . "/job-" . $lastJob() . ".deleted"), $lastJob());

// Stop: the panel goes away, the work ends.
@unlink($upstreamLog);
$a = $transcribeAs(array_merge($speechBlock, array("model" => "whisper-slow")), array("hypervideoId" => "1"), array(), 1.0);
usleep(1500000);
$sent = (int)@file_get_contents($upstreamLog);
check("transcribe: when the panel stops reading, the request to the speech server ends too", $sent > 0 && $sent < 15, "the speech server waited " . $sent . " of 15 steps");
@unlink($ffmpegPid);
$a = $transcribeAs(array_merge($speechBlock, array("ffmpeg" => $fakeSlow)), array("hypervideoId" => "1"), array(), 1.0);
usleep(1500000);
$pid = (int)@file_get_contents($ffmpegPid);
check("transcribe: when the panel stops reading, ffmpeg is stopped", $pid > 0 && !$alive($pid), "pid " . $pid);

$left = glob(scratch() . "/http-storage/tmp/transcribe-*");
check("transcribe: no temporary file is left", is_dir(scratch() . "/http-storage/tmp") && count($left) === 0, json_encode($left));

// With ffmpeg itself, found as FrameTrail finds it.
if ($realFfmpeg !== null && $realFfprobe !== null) {
    $a = $transcribeAs(array_merge($speechBlock, array("ffmpeg" => null)), array("hypervideoId" => "3"));
    $probe  = json_decode((string)shell_exec(escapeshellarg($realFfprobe) . " -v error -show_entries format=duration:stream=codec_name,channels,sample_rate -of json " . escapeshellarg($upstreamDir . "/upload.bin")), true);
    $stream = isset($probe["streams"][0]) ? $probe["streams"][0] : array();
    check("transcribe with ffmpeg (FrameTrail's): the speech server gets 2 seconds of mono 16 kHz mp3",
        $a["last"]["event"] === "result" && isset($stream["codec_name"]) && $stream["codec_name"] === "mp3" && (int)$stream["channels"] === 1
        && (int)$stream["sample_rate"] === 16000 && abs((float)$probe["format"]["duration"] - 2.0) < 0.15, $a["body"] . json_encode($probe));
    $a = request($action, $scene(array("transcription" => $speechBlock), array("X-Test-Role: admin")), http_build_query(array("a" => "conversationalUiStatus")));
    $answers[] = $a;
    $got = json_decode($a["body"], true)["response"]["capabilities"]["transcription"];
    check("status over HTTP, an administrator, ffmpeg switched off: the video sent as it is", $got["audio"] === "file" && $got["maxBytes"] === 104857600, $a["body"]);
    $a = request($action, $scene(array("transcription" => array_merge($speechBlock, array("ffmpeg" => null))), array("X-Test-Role: admin")), http_build_query(array("a" => "conversationalUiStatus")));
    $answers[] = $a;
    check("status over HTTP, an administrator: ffmpeg found", json_decode($a["body"], true)["response"]["capabilities"]["transcription"]["audio"] === "ffmpeg", $a["body"]);
} else {
    skip("transcribe with ffmpeg (FrameTrail's): mono 16 kHz mp3 of the clip's span", "no ffmpeg/ffprobe on this machine");
}


// The key.
$leak = $send("leak");
check("route: the key is taken out of an error the service repeats it in", $leak["status"] === 400 && strpos($leak["body"], "[key]") !== false, $leak["body"]);
$everything = implode("\n", array_map(function($answer) { return $answer["raw"] . $answer["body"]; }, $answers));
check("the keys appear in no answer, header or body (" . count($answers) . " answers)", strpos($everything, $key) === false && strpos($everything, $speechKey) === false && strlen($everything) > 1000);


finish();
