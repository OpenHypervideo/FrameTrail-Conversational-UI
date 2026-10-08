<?php

/*
 * Tests of the server part. No dependencies, PHP 7.4 or later with curl:
 *
 *     php tests/run-php.php            # the files in server/
 *     php tests/run-php.php --build    # the drop-in folder bash scripts/build.sh wrote
 *
 * extension.php is read as FrameTrail's extension loader reads it
 * (_server/extensionloader.php), with stand-ins for the FrameTrail functions
 * it uses, and checked against the loader's rules. The relay's parts are
 * checked one by one, then over HTTP: PHP's built-in server runs a stand-in
 * for Mistral's API (tests/relay/upstream.php) and a stand-in for FrameTrail's
 * routers (tests/relay/harness.php), and the relay's answers are read as the
 * browser reads them. Prints TAP; exits with 1 when a test fails.
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

check("loading the manifest loads not the relay", !function_exists("ftConversationalUiRelayAdmit"));


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

    $expect = function($description, $secrets, $expected) use ($capabilities) {
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
        && $config["instance"] === null && $config["requestsPerDay"] === null && $config["allowOwnKey"] === false, json_encode($config));
    unset($GLOBALS["secrets"]);

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
$upstream    = startServer(__DIR__ . "/relay/upstream.php", array("RELAY_UPSTREAM_LOG" => $upstreamLog), array("output_buffering=0"));
// The relay's host as php.ini-development sets it up: its output buffer must not hold the stream back.
$relay       = startServer(__DIR__ . "/relay/harness.php",
    array("RELAY_SERVER_DIR" => $dir, "RELAY_STORAGE" => scratch() . "/http-storage"),
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

// The key.
$leak = $send("leak");
check("route: the key is taken out of an error the service repeats it in", $leak["status"] === 400 && strpos($leak["body"], "[key]") !== false, $leak["body"]);
$everything = implode("\n", array_map(function($answer) { return $answer["raw"] . $answer["body"]; }, $answers));
check("the key appears in no answer, header or body (" . count($answers) . " answers)", strpos($everything, $key) === false && strlen($everything) > 1000);


finish();
