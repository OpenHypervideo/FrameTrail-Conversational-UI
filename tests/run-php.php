<?php

/*
 * Tests of the server part. No dependencies, PHP 7.4 or later:
 *
 *     php tests/run-php.php            # the files in server/
 *     php tests/run-php.php --build    # the drop-in folder bash scripts/build.sh wrote
 *
 * extension.php is read as FrameTrail's extension loader reads it
 * (_server/extensionloader.php), with stand-ins for the FrameTrail functions
 * it uses, and checked against the loader's rules. Prints TAP; exits with 1
 * when a test fails.
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


/* ---------------------------------------------------------------------- */
/*  Files                                                                 */
/* ---------------------------------------------------------------------- */

if (!is_file($dir . "/extension.php")) {
    check("extension.php exists in " . substr($dir, strlen($root) + 1) . ($built ? " (run bash scripts/build.sh first)" : ""), false);
    echo "1.." . $count . "\n";
    exit(1);
}

foreach (array_merge(phpFiles($dir), array(__FILE__)) as $file) {
    $output = array();
    exec(escapeshellarg(PHP_BINARY) . " -l " . escapeshellarg($file) . " 2>&1", $output, $status);
    check("syntax: " . substr($file, strlen($root) + 1), $status === 0, implode("\n", $output));
}

// Run on its own, by a web server that ignores .htaccess, it does nothing.
$output = array();
exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($dir . "/extension.php") . " 2>&1", $output, $status);
check("extension.php does nothing when run on its own", $status === 0 && count($output) === 0, implode("\n", $output));


/* ---------------------------------------------------------------------- */
/*  Manifest                                                              */
/* ---------------------------------------------------------------------- */

// Stand-in for FrameTrail's function: its presence tells extension.php that a
// FrameTrail router loaded it.
function ftExtensionStorage($name) {

    return false;

}

$before   = get_defined_functions()["user"];
$manifest = includeManifest($dir . "/extension.php");
$declared = array_values(array_diff(get_defined_functions()["user"], $before));

check("extension.php returns an array", is_array($manifest));
if (!is_array($manifest)) {
    echo "1.." . $count . "\n";
    exit(1);
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
foreach ($requires as $requirement) {
    check("requires " . $requirement . ": this PHP has it", is_string($requirement) && extension_loaded($requirement));
}


/* ---------------------------------------------------------------------- */
/*  Actions                                                               */
/* ---------------------------------------------------------------------- */

$context = array("name" => "conversational-ui", "settings" => array());

check("action conversationalUiStatus is offered", isset($actions["conversationalUiStatus"]));

if (isset($actions["conversationalUiStatus"]) && is_callable($actions["conversationalUiStatus"])) {

    $answer = call_user_func($actions["conversationalUiStatus"], $context);

    check("conversationalUiStatus answers with success",
        is_array($answer) && isset($answer["status"], $answer["code"]) && $answer["status"] === "success" && $answer["code"] === 0,
        json_encode($answer));

    $version = (is_array($answer) && isset($answer["response"]["version"])) ? $answer["response"]["version"] : null;

    check("conversationalUiStatus names the version" . ($built ? " the build wrote in" : ", \"dev\" before the build"),
        is_string($version) && ($built ? ($version !== "" && strpos($version, "__") === false) : $version === "dev"),
        var_export($version, true));

}


echo "1.." . $count . "\n";

if ($failures > 0) {
    echo "# " . $failures . " of " . $count . " failed\n";
    exit(1);
}

echo "# all " . $count . " passed\n";
