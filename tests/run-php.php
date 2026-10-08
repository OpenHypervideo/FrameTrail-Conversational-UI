<?php

/*
 * Tests of the server part. No dependencies, PHP 7.4 or later:
 *
 *     php tests/run-php.php                  # the files in server/
 *     php tests/run-php.php --build          # the drop-in folder bash scripts/build.sh wrote
 *     php tests/run-php.php --list-fixtures  # the conformance cases it runs, one per line
 *
 * extension.php is read as FrameTrail's extension loader reads it
 * (_server/extensionloader.php), with stand-ins for the FrameTrail functions
 * it uses, and checked against the loader's rules. The library in lib/ (the
 * ports of FrameTrail's pure scripts, the operations, the lint rules) runs
 * FrameTrail's fixtures by the rules of FrameTrail's tests/README.md and the
 * add-on's conformance fixtures by the rules of shared/fixtures/README.md.
 * FrameTrail's fixtures come from a FrameTrail working copy (1.4.1 or later):
 * the environment variable FRAMETRAIL_DIR, or --frametrail=<dir>, or the
 * folder next to this repository's, ../frametrail.
 *
 * Prints TAP; exits with 1 when a test fails.
 */

$root  = dirname(__DIR__);
$built = in_array("--build", $argv, true);
$dir   = $built ? $root . "/build/server" : $root . "/server";
$only  = in_array("--list-fixtures", $argv, true);

require $root . "/scripts/vendor-schemas.php";

$frametrail = ftConversationalUiFrameTrailDir($argv, $root);

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

function done() {

    global $count, $failures;

    echo "1.." . $count . "\n";

    if ($failures > 0) {
        echo "# " . $failures . " of " . $count . " failed\n";
        exit(1);
    }

    echo "# all " . $count . " passed\n";
    exit(0);

}

// Runs a test body; an exception is a failure with its message.
function attempt($description, $body) {
    try {
        $result = $body();
        if (is_array($result)) {
            check($description, $result[0], isset($result[1]) ? $result[1] : "");
        } else {
            check($description, $result !== false);
        }
    } catch (Throwable $e) {
        check($description, false, get_class($e) . ": " . $e->getMessage() . "\n" . $e->getFile() . ":" . $e->getLine());
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

function jsonFiles($dir) {
    $files = is_dir($dir) ? array_values(array_filter(scandir($dir), function($name) { return substr($name, -5) === ".json"; })) : array();
    sort($files);
    return $files;
}

// Like FrameTrail's ftExtensionInclude(): a scope of its own.
function includeManifest($__file) {

    return include $__file;

}


/* ---------------------------------------------------------------------- */
/*  The conformance fixtures this runner reads                            */
/* ---------------------------------------------------------------------- */

// The folders of shared/fixtures/ and what each holds; anything else there is an error.
const FIXTURE_FOLDERS = array("data", "ops", "lint");

/**
 * Every case of the conformance fixtures, as "<folder>/<file>: <name>". Both
 * runners list them (--list-fixtures); CI compares the lists.
 */
function fixtureCases($root) {
    $cases = array();
    foreach (array("ops", "lint") as $folder) {
        foreach (jsonFiles($root . "/shared/fixtures/" . $folder) as $file) {
            $fixture = json_decode(file_get_contents($root . "/shared/fixtures/" . $folder . "/" . $file));
            foreach ((isset($fixture->cases) ? $fixture->cases : array()) as $c) {
                $cases[] = $folder . "/" . $file . ": " . $c->name;
            }
        }
    }
    return $cases;
}

if ($only) {
    foreach (fixtureCases($root) as $case) { echo $case . "\n"; }
    exit(0);
}


/* ---------------------------------------------------------------------- */
/*  Files                                                                 */
/* ---------------------------------------------------------------------- */

if (!is_file($dir . "/extension.php")) {
    check("extension.php exists in " . substr($dir, strlen($root) + 1) . ($built ? " (run bash scripts/build.sh first)" : ""), false);
    done();
}

foreach (array_merge(phpFiles($dir), array(__FILE__, $root . "/scripts/vendor-schemas.php")) as $file) {
    $output = array();
    exec(escapeshellarg(PHP_BINARY) . " -l " . escapeshellarg($file) . " 2>&1", $output, $status);
    check("syntax: " . substr($file, strlen($root) + 1), $status === 0, implode("\n", $output));
}

// Run on its own, by a web server that ignores .htaccess, no file does anything.
foreach (phpFiles($dir) as $file) {
    $source = file_get_contents($file);
    $guard  = preg_match('/^<\?php(?:\s+|\/\*.*?\*\/|\/\/[^\n]*)*if \(!function_exists\("ftExtensionStorage"\)(?: && !defined\("FT_CONVERSATIONAL_UI_LIB"\))?\) \{\s*http_response_code\(404\);\s*exit;\s*\}/s', $source) === 1;
    check("guarded: " . substr($file, strlen($root) + 1), $guard);
}

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
    done();
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


/* ---------------------------------------------------------------------- */
/*  The library                                                           */
/* ---------------------------------------------------------------------- */

$functionsBefore = get_defined_functions()["user"];
$classesBefore   = array_merge(get_declared_classes(), get_declared_interfaces());

require $dir . "/lib/load.php";

$libFunctions = array_values(array_diff(get_defined_functions()["user"], $functionsBefore));
$libClasses   = array_values(array_diff(array_merge(get_declared_classes(), get_declared_interfaces()), $classesBefore));

check("the library declares no functions (classes only)", count($libFunctions) === 0, implode(", ", $libFunctions));
check("every class and interface of the library starts with FtConversationalUi",
    count(array_filter($libClasses, function($name) { return stripos($name, "FtConversationalUi") !== 0; })) === 0,
    implode(", ", $libClasses));

if ($built) {
    foreach (array("operations.json", "changeset.schema.json", "lint.json") as $file) {
        check("the build carries shared/" . $file . " as it is", is_file($dir . "/shared/" . $file)
            && json_decode(file_get_contents($dir . "/shared/" . $file)) == json_decode(file_get_contents($root . "/shared/" . $file)));
    }
}

use FtConversationalUiJs as Js;
use FtConversationalUiOpsUtil as Util;
use FtConversationalUiSerializer as Serializer;
use FtConversationalUiOps as Ops;

$validator = FtConversationalUiShared::validator();

attempt("server/lib/frametrail/schemas.json is FrameTrail's (" . $frametrail . "); php scripts/vendor-schemas.php copies it", function() use ($frametrail, $dir) {
    $theirs = ftConversationalUiFrameTrailSchemasText($frametrail);
    $ours   = file_get_contents($dir . "/lib/frametrail/schemas.json");
    return array($theirs === $ours, "out of date");
});


/* ---------------------------------------------------------------------- */
/*  JSON helpers for the tests                                            */
/* ---------------------------------------------------------------------- */

function readJSON($file) {
    return Js::decode(file_get_contents($file));
}

function formatErrors($errors, $indent = "    ") {
    return implode("\n", array_map(function($e) use ($indent) {
        return $indent . (($e->path !== "") ? $e->path : "(document)") . ": " . $e->message;
    }, $errors));
}

function sortErrors($errors) {
    $list = array_values($errors);
    usort($list, function($a, $b) { return strcmp($a->path . "\0" . $a->message, $b->path . "\0" . $b->message); });
    return $list;
}

// Where two JSON values differ, as readable lines (at most 8).
function differences($expected, $actual) {

    $lines = array();

    $walk = function($a, $b, $where) use (&$walk, &$lines) {
        if (count($lines) >= 8 || Js::stringify($a) === Js::stringify($b)) { return; }
        if (Js::isObject($a) && Js::isObject($b)) {
            $keys = array_values(array_unique(array_merge(Js::ownKeys($a), Js::ownKeys($b))));
            foreach ($keys as $key) { $walk(Js::get($a, $key), Js::get($b, $key), $where . "/" . $key); }
            if (!count($lines) && Js::ownKeys($a) !== Js::ownKeys($b)) {
                $lines[] = (($where !== "") ? $where : "(document)") . ": key order " . Js::stringify(Js::ownKeys($b)) . ", expected " . Js::stringify(Js::ownKeys($a));
            }
            return;
        }
        if (is_array($a) && is_array($b) && count($a) === count($b)) {
            foreach ($a as $i => $item) { $walk($item, $b[$i], $where . "/" . $i); }
            return;
        }
        $show = function($value) { return Js::isUndef($value) ? "(missing)" : substr(Js::stringify($value), 0, 100); };
        $lines[] = (($where !== "") ? $where : "(document)") . ": " . $show($b) . ", expected " . $show($a);
    };

    $walk($expected, $actual, "");

    return implode("\n", $lines);

}


/* ---------------------------------------------------------------------- */
/*  FrameTrail's fixtures (tests/README.md, "Rules for Runners")          */
/* ---------------------------------------------------------------------- */

const NOW = 1999999999999;

function schemaOfDataFile($rel) {
    if ($rel === "config.json") { return "config.schema.json"; }
    if ($rel === "tagdefinitions.json") { return "tagdefinitions.schema.json"; }
    if ($rel === "resources/_index.json") { return "resources-index.schema.json"; }
    if ($rel === "hypervideos/_index.json") { return "hypervideos-index.schema.json"; }
    if (preg_match('/^hypervideos\/[^\/]+\/hypervideo\.json$/', $rel)) { return "hypervideo.schema.json"; }
    if (preg_match('/^hypervideos\/[^\/]+\/annotations\/_index\.json$/', $rel)) { return "annotations-index.schema.json"; }
    if (preg_match('/^hypervideos\/[^\/]+\/annotations\/[^\/]+\.json$/', $rel)) { return "annotation-file.schema.json"; }
    return null;
}

// Items as a round trip writes them: the current @context, created as ISO, unique per group.
function normalizedItems($items, $groupOf = null) {
    $taken = array();
    return array_map(function($stored) use (&$taken, $groupOf) {
        $item  = Js::copy($stored);
        $group = $groupOf ? Js::str($groupOf($item)) : "";
        if (!isset($taken[$group])) { $taken[$group] = array(); }
        $created = Js::get($item, "created");
        $ms = ($created === null || Js::isUndef($created) || $created === "") ? NAN : Js::dateParse($created);
        if (!Js::isFinite($ms)) { $ms = 0; }
        while (isset($taken[$group][Js::number($ms)])) { $ms += 1; }
        $taken[$group][Js::number($ms)] = true;
        $item->{"@context"} = Serializer::CONTEXT;
        $item->created = Js::isoString($ms);
        return $item;
    }, $items);
}

function expectedHypervideo($stored, $now) {

    $expected = Js::copy($stored);
    $contents = is_array(Js::get($stored, "contents")) ? $stored->contents : array();
    $ofType   = function($type) use ($contents) {
        return Js::filter($contents, function($item) use ($type) { return Js::isObject($item) && Js::get($item, "frametrail:type") === $type; });
    };

    if (Js::isObject(Js::get($expected, "meta"))) { $expected->meta->lastchanged = $now; }

    $expected->contents = array_merge(
        normalizedItems($ofType("Overlay")),
        normalizedItems($ofType("CodeSnippet")),
        Js::copy(Js::filter($contents, function($item) { return !Js::isObject($item) || !in_array(Js::get($item, "frametrail:type"), array("Overlay", "CodeSnippet"), true); }))
    );

    return $expected;

}

function expectedAnnotationFile($stored) {
    return normalizedItems($stored, function($item) { return Js::isObject(Js::get($item, "creator")) ? Js::get($item->creator, "id") : Js::undef(); });
}

function expectedItem($stored) {
    $item = Js::copy($stored);
    $ms   = Js::dateParse(Js::get($item, "created"));
    $item->{"@context"} = Serializer::CONTEXT;
    if (Js::isFinite($ms)) { $item->created = Js::isoString($ms); }
    return $item;
}

function mismatch($expected, $actual, $what) {
    if (Js::stringify($expected) === Js::stringify($actual)) { return null; }
    return $what . "\n" . differences($expected, $actual);
}

function roundTrip($schema, $stored) {

    global $validator;

    $problems = array();

    if ($schema === "hypervideo.schema.json") {
        $model   = Serializer::parseHypervideo($stored);
        $written = Serializer::serializeHypervideo($model, array("now" => NOW));
        $problems[] = mismatch(expectedHypervideo($stored, NOW), $written, "serialize(parse(x)) differs from x:");
        $problems[] = mismatch($written, Serializer::serializeHypervideo(Serializer::parseHypervideo($written), array("now" => NOW)), "a second round changes it:");
        $invalid  = $validator->validate("hypervideo.schema.json", $written);
        $exported = Serializer::serializeHypervideo($model, array("now" => NOW, "purpose" => "export", "sourcePath" => "video.mp4", "subtitles" => new stdClass()));
        $invalidExport = $validator->validate("hypervideo.schema.json", $exported);
        if (count($invalid)) { $problems[] = "the written hypervideo is invalid:\n" . formatErrors($invalid); }
        if (count($invalidExport)) { $problems[] = "the exported hypervideo is invalid:\n" . formatErrors($invalidExport); }
    } else if ($schema === "annotation-file.schema.json") {
        $written = Serializer::serializeAnnotationFile(Serializer::parseAnnotationFile($stored), array());
        $problems[] = mismatch(expectedAnnotationFile($stored), $written, "serialize(parse(x)) differs from x:");
        $problems[] = mismatch($written, Serializer::serializeAnnotationFile(Serializer::parseAnnotationFile($written), array()), "a second round changes it:");
        $invalid = $validator->validate("annotation-file.schema.json", $written);
        if (count($invalid)) { $problems[] = "the written annotation file is invalid:\n" . formatErrors($invalid); }
    } else if ($schema === "content-item.schema.json") {
        $overlay = Js::get($stored, "frametrail:type") === "Overlay";
        $write   = function($item) use ($overlay) {
            return $overlay ? Serializer::serializeOverlay(Serializer::parseOverlay($item), array()) : Serializer::serializeCodeSnippet(Serializer::parseCodeSnippet($item), array());
        };
        $written    = $write($stored);
        $problems[] = mismatch(expectedItem($stored), $written, "serialize(parse(x)) differs from x:");
        $problems[] = mismatch($written, $write($written), "a second round changes it:");
    }

    return array_values(array_filter($problems));

}

// The files of a _data folder the tests read (tests/README.md): JSON parsed, subtitles and CSS as text.
function readDataFolder($dir) {

    $files = array();

    $walk = function($rel) use (&$walk, &$files, $dir) {
        $names = scandir($dir . ($rel !== "" ? "/" . $rel : ""));
        sort($names);
        foreach ($names as $name) {
            if ($name === "." || $name === "..") { continue; }
            $file = ($rel !== "") ? $rel . "/" . $name : $name;
            if ($name[0] === "." || $file === "users.json") { continue; }
            $full = $dir . "/" . $file;
            if (is_dir($full)) {
                if ($file === "resources") {
                    if (is_file($full . "/_index.json")) { $files["resources/_index.json"] = readJSON($full . "/_index.json"); }
                } else {
                    $walk($file);
                }
            } else if (substr($name, -5) === ".json") {
                $files[$file] = readJSON($full);
            } else if (preg_match('/\.(vtt|css)$/', $name)) {
                $files[$file] = file_get_contents($full);
            }
        }
    };

    $walk("");

    return $files;

}

$frametrailFixtures = $frametrail . "/tests/fixtures";

check("FrameTrail's fixtures are at " . $frametrailFixtures . " (set FRAMETRAIL_DIR or pass --frametrail=<dir>)", is_dir($frametrailFixtures . "/data"));

foreach (is_dir($frametrailFixtures . "/data") ? array_values(array_diff(scandir($frametrailFixtures . "/data"), array(".", ".."))) : array() as $name) {

    if ($name[0] === ".") { continue; }

    $files    = readDataFolder($frametrailFixtures . "/data/" . $name);
    $problems = array();

    foreach ($files as $file => $content) {
        if (substr($file, -5) !== ".json") { continue; }
        $schema = schemaOfDataFile($file);
        if (!$schema) { $problems[$file] = $file . ": no schema for this file"; continue; }
        $errors = $validator->validate($schema, $content);
        if (count($errors)) { $problems[$file] = $file . " (" . $schema . ")\n" . formatErrors($errors); }
    }

    check("FrameTrail data/" . $name . ": every file follows its schema", !count($problems), implode("\n", $problems));

    attempt("FrameTrail data/" . $name . ": hypervideos and annotation files round-trip through the serializer", function() use ($files, $problems) {
        $failed = array();
        foreach ($files as $file => $content) {
            $schema = schemaOfDataFile($file);
            if (isset($problems[$file]) || ($schema !== "hypervideo.schema.json" && $schema !== "annotation-file.schema.json")) { continue; }
            foreach (roundTrip($schema, $content) as $problem) { $failed[] = $file . ": " . $problem; }
        }
        return array(!count($failed), implode("\n", $failed));
    });

    attempt("FrameTrail data/" . $name . ": read as bundles, they follow the bundle schemas and survive writing", function() use ($files, $problems, $validator) {
        if (count($problems)) { return array(false, "the folder has invalid files"); }
        $project = Serializer::readBundle($files, "folder");
        $errors  = $validator->validate("project-bundle.schema.json", $project);
        if (count($errors)) { return array(false, "project bundle:\n" . formatErrors($errors)); }
        if (!Js::same(Serializer::readBundle(Serializer::writeBundle($project, "folder"), "folder"), $project)) { return array(false, "the project bundle changes when written and read"); }
        foreach (Js::keys($project->hypervideos) as $id) {
            $bundle = Serializer::readBundle($files, "folder", array("bundle" => "hypervideo", "id" => $id));
            $errors = $validator->validate("hypervideo-bundle.schema.json", $bundle);
            if (count($errors)) { return array(false, "hypervideo bundle " . $id . ":\n" . formatErrors($errors)); }
            if (!Js::same(Serializer::readBundle(Serializer::writeBundle($bundle, "folder"), "folder", array("bundle" => "hypervideo", "id" => $id)), $bundle)) {
                return array(false, "hypervideo bundle " . $id . " changes when written and read");
            }
        }
        return true;
    });

}

// Case files: each case validates with exactly the expected errors; valid hypervideos, annotation files and content items round-trip.
foreach (array("cases", "examples") as $folder) {
    foreach (jsonFiles($frametrailFixtures . "/" . $folder) as $name) {
        $fixture = readJSON($frametrailFixtures . "/" . $folder . "/" . $name);
        foreach ($fixture->cases as $c) {
            attempt("FrameTrail " . $folder . "/" . $name . ": " . $c->name, function() use ($c, $validator) {
                $actual   = sortErrors($validator->validate($c->schema, $c->data));
                $expected = sortErrors(isset($c->errors) ? $c->errors : array());
                if (Js::stringify($actual) !== Js::stringify($expected)) {
                    return array(false, (count($expected) ? "other errors than expected:\n" : $c->schema . " rejects it:\n") . formatErrors($actual)
                        . (count($expected) ? "\n  expected:\n" . formatErrors($expected) : ""));
                }
                if (!count($expected)) {
                    $problems = roundTrip($c->schema, $c->data);
                    if (count($problems)) { return array(false, implode("\n", $problems)); }
                }
                return true;
            });
        }
    }
}


/* ---------------------------------------------------------------------- */
/*  The operations manifest                                               */
/* ---------------------------------------------------------------------- */

$operations = Ops::manifest()->operations;

attempt("every operation's input and output schema resolves (with FrameTrail's schemas)", function() use ($operations) {
    foreach ($operations as $op) {
        Ops::validateInput($op->name, new stdClass());
        Ops::validateOutput($op->name, new stdClass());
    }
    return true;
});

attempt("every operation has a PHP implementation, and every implementation an operation", function() use ($operations) {
    $names = array_map(function($op) { return $op->name; }, $operations);
    $impl  = array_keys(Ops::IMPLEMENTATIONS);
    sort($names);
    sort($impl);
    return array($names === $impl, "operations: " . implode(", ", $names) . "\nimplemented: " . implode(", ", $impl));
});

attempt("every lint rule has a PHP implementation, and every implementation a rule", function() {
    $ids  = array_map(function($rule) { return $rule->id; }, FtConversationalUiLint::manifest()->rules);
    $impl = array_keys(FtConversationalUiLint::RULES);
    sort($ids);
    sort($impl);
    return array($ids === $impl, "rules: " . implode(", ", $ids) . "\nimplemented: " . implode(", ", $impl));
});


/* ---------------------------------------------------------------------- */
/*  The conformance fixtures (shared/fixtures/README.md)                  */
/* ---------------------------------------------------------------------- */

$fixturesDir = $root . "/shared/fixtures";

$entries = array_values(array_diff(scandir($fixturesDir), array(".", "..", "README.md", ".DS_Store")));
$folders = FIXTURE_FOLDERS;
sort($entries);
sort($folders);
check("shared/fixtures/ holds " . implode(", ", FIXTURE_FOLDERS) . " and README.md, and nothing else", $entries === $folders, implode(", ", $entries));

function fixtureData($name) {
    global $fixturesDir;
    return readJSON($fixturesDir . "/data/" . $name . ".json");
}

foreach (jsonFiles($fixturesDir . "/data") as $name) {
    $data = readJSON($fixturesDir . "/data/" . $name);
    $errors = $validator->validate((Js::get($data, "bundle") === "project") ? "project-bundle.schema.json" : "hypervideo-bundle.schema.json", $data);
    check("fixture data " . $name . " is a valid bundle (FrameTrail's schemas)", !count($errors), formatErrors($errors));
}

// Applies a JSON Patch (RFC 6902): add, remove and replace.
function applyPatch($document, $patch) {

    if (!is_array($patch)) { throw new Exception("after is a JSON Patch: a list of operations"); }

    foreach ($patch as $operation) {

        $tokens = array_map(function($token) { return str_replace(array("~1", "~0"), array("/", "~"), $token); }, array_slice(explode("/", $operation->path), 1));
        $last   = array_pop($tokens);
        $where  = $operation->op . " " . $operation->path;

        // Walk by reference: lists are values in PHP.
        $node = &$document;
        foreach ($tokens as $token) {
            if (is_array($node)) {
                if (!preg_match('/^(0|[1-9][0-9]*)$/', $token) || (int)$token >= count($node)) { throw new Exception("after: no " . $where); }
                $node = &$node[(int)$token];
            } else if (Js::isObject($node) && property_exists($node, $token)) {
                $node = &$node->{$token};
            } else {
                throw new Exception("after: no " . $where);
            }
        }

        if (is_array($node)) {
            $index = ($last === "-") ? count($node) : (preg_match('/^(0|[1-9][0-9]*)$/', $last) ? (int)$last : -1);
            if ($index < 0 || $index > count($node) - ($operation->op === "add" ? 0 : 1)) { throw new Exception("after: no " . $where); }
            if ($operation->op === "add")          { array_splice($node, $index, 0, array(Js::copy($operation->value))); }
            else if ($operation->op === "remove")  { array_splice($node, $index, 1); }
            else if ($operation->op === "replace") { $node[$index] = Js::copy($operation->value); }
            else { throw new Exception("after: " . $operation->op . " is not add, remove or replace"); }
        } else if (Js::isObject($node)) {
            if ($operation->op !== "add" && !property_exists($node, $last)) { throw new Exception("after: no " . $where); }
            if ($operation->op === "remove") { unset($node->{$last}); }
            else if ($operation->op === "add" || $operation->op === "replace") { $node->{$last} = Js::copy($operation->value); }
            else { throw new Exception("after: " . $operation->op . " is not add, remove or replace"); }
        } else {
            throw new Exception("after: no " . $where);
        }

        unset($node);

    }

    return $document;

}

// A bundle as an undo is compared with the original: items in any order, null like missing, an empty annotation file like none.
function undoComparable($bundle) {

    $out = Js::copy($bundle);

    $withoutNulls = function($value) use (&$withoutNulls) {
        if (is_array($value)) { return array_map($withoutNulls, $value); }
        if (!Js::isObject($value)) { return $value; }
        $result = new stdClass();
        foreach (get_object_vars($value) as $key => $item) {
            if ($item !== null) { $result->{(string)$key} = $withoutNulls($item); }
        }
        return $result;
    };
    $byIdentity = function($a, $b) {
        return strcmp(Js::str(Js::get($a, "frametrail:type")) . " " . Js::str(Js::get($a, "created")), Js::str(Js::get($b, "frametrail:type")) . " " . Js::str(Js::get($b, "created")));
    };

    $hypervideos = (Js::get($out, "bundle") === "project") ? array_map(function($id) use ($out) { return $out->hypervideos->{$id}; }, Js::keys($out->hypervideos)) : array($out);

    foreach ($hypervideos as $hypervideo) {
        $json = $hypervideo->hypervideo;
        if (is_array(Js::get($json, "contents"))) {
            $contents = array_map($withoutNulls, $json->contents);
            usort($contents, $byIdentity);
            $json->contents = $contents;
        }
        if (is_array(Js::get($json, "subtitles"))) {
            $subtitles = $json->subtitles;
            usort($subtitles, function($a, $b) { return strcmp(Js::str(Js::get($a, "srclang")), Js::str(Js::get($b, "srclang"))); });
            $json->subtitles = $subtitles;
        }
        $annotations = Js::get($hypervideo, "annotations");
        $files = Js::isObject($annotations) && Js::isObject(Js::get($annotations, "files")) ? $annotations->files : new stdClass();
        foreach (Js::ownKeys($files) as $id) {
            if (is_array($files->{$id}) && !count($files->{$id})) { unset($files->{$id}); continue; }
            if (is_array($files->{$id})) {
                $list = array_map($withoutNulls, $files->{$id});
                usort($list, $byIdentity);
                $files->{$id} = $list;
            }
        }
    }

    return $out;

}

function caseOption($c, $fixture, $key) {
    $value = Js::get($c, $key);
    return Js::isUndef($value) ? Js::get($fixture, $key) : $value;
}

function storeOptions($fixture, $c) {
    $options = array();
    $user    = Js::get($c, "user");
    $user    = Js::truthy($user) ? $user : Js::get($fixture, "user");
    if (!Js::isUndef($user)) { $options["user"] = $user; }
    foreach (array("now", "duration", "hypervideoId") as $key) {
        $value = caseOption($c, $fixture, $key);
        if (!Js::isUndef($value)) { $options[$key] = $value; }
    }
    return $options;
}

function same($a, $b, $what) {
    if (Js::same($a, $b)) { return null; }
    return $what . "\n" . differences($b, $a);
}

/**
 * Runs one operations case against a fresh model store by the rules of
 * shared/fixtures/README.md: null when it passes, otherwise what is wrong.
 * Adds the operations it used to $used.
 */
function runOpsCase($fixture, $c, &$used) {

    $dataName = Js::truthy(Js::get($c, "data")) ? $c->data : $fixture->data;
    $data     = fixtureData($dataName);
    $options  = storeOptions($fixture, $c);
    $context  = array("generator" => Js::get($fixture, "generator"));
    $store    = new FtConversationalUiModelStore($data, $options);

    if ((Js::has($c, "op") ? 1 : 0) + (Js::has($c, "changeset") ? 1 : 0) !== 1) { return "a case has either op or changeset"; }

    $op    = Js::has($c, "op") ? Ops::operation($c->op->op) : null;
    $write = Js::has($c, "changeset") || ($op && $op->effect === "write");

    if (Js::has($c, "changeset")) {
        foreach ((is_array(Js::get($c->changeset, "ops")) ? $c->changeset->ops : array()) as $entry) { $used[Js::str(Js::get($entry, "op"))] = true; }
    } else {
        $used[$c->op->op] = true;
    }

    $result  = null;
    $applied = null;

    try {
        if (Js::has($c, "changeset")) {
            $applied = Ops::apply($store, $c->changeset, $context);
        } else if ($write) {
            // One operation, as a changeset of its own, its errors pointing into its input.
            $applied = $store->transaction($c->op->op, function($tx) use ($c, $context) {
                $recorder = Ops::record($tx, array("generator" => $context["generator"]));
                $results  = array($recorder->run($c->op->op, Js::get($c->op, "input")));
                return Js::obj(array("results" => $results, "changeset" => $recorder->changeset()));
            });
        } else {
            $result = Ops::run($store, $c->op->op, Js::get($c->op, "input"), $context);
        }
    } catch (FtConversationalUiOpError $e) {
        if (!Js::has($c, "error")) { return "refused: " . $e->errorCode . ": " . $e->getMessage(); }
        if ($e->errorCode !== $c->error->code) { return "code " . $e->errorCode . ", expected " . $c->error->code . ": " . $e->getMessage(); }
        if (Js::has($c->error, "errors")) {
            $actual   = sortErrors(Js::copy($e->errors));
            $expected = sortErrors($c->error->errors);
            if (!Js::same($actual, $expected)) { return "errors " . Js::stringify($actual) . "\n  expected " . Js::stringify($expected); }
        }
        return same($store->data(), $data, "a refused operation changes nothing:");
    }

    if (Js::has($c, "error")) { return "expected " . Js::stringify($c->error); }

    if ($applied) {

        $changeset = Js::copy($applied->changeset);
        $results   = Js::copy($applied->results);

        $errors = Ops::validator()->validate(Ops::changesetSchema()->{'$id'}, $changeset);
        if (count($errors)) { return "the applied changeset does not follow its schema:\n" . formatErrors($errors); }
        foreach ($changeset->ops as $i => $entry) {
            $errors = Ops::validateOutput($entry->op, $results[$i]);
            if (count($errors)) { return $entry->op . ": its result does not follow its output schema:\n" . formatErrors($errors); }
        }

        $checks = array();
        if (Js::has($c, "result"))   { $checks[] = same($results[0], $c->result, "result:"); }
        if (Js::has($c, "results"))  { $checks[] = same($results, $c->results, "results:"); }
        if (Js::has($c, "inverse"))  { $checks[] = same($changeset->ops[0]->inverse, $c->inverse, "inverse:"); }
        if (Js::has($c, "inverses")) { $checks[] = same(array_map(function($entry) { return $entry->inverse; }, $changeset->ops), $c->inverses, "inverses:"); }
        if (Js::has($c, "applied")) {
            foreach (Js::keys($c->applied) as $key) { $checks[] = same(Js::get($changeset, $key), $c->applied->{$key}, "applied " . $key . ":"); }
        }
        $checks[] = same($store->data(), applyPatch(Js::copy($data), Js::has($c, "after") ? $c->after : array()), "the bundle after:");

        $checks = array_values(array_filter($checks));
        if (count($checks)) { return implode("\n", $checks); }

        // Undo gives back what was there.
        $reference = (new FtConversationalUiModelStore($data, $options))->data(array("all" => true));
        Ops::undo($store, $applied->changeset);
        return same(undoComparable($store->data(array("all" => true))), undoComparable($reference), "undone:");

    }

    $errors = Ops::validateOutput($c->op->op, $result);
    if (count($errors)) { return "the result does not follow the output schema:\n" . formatErrors($errors); }

    if (Js::has($c, "result")) {
        $problem = same($result, $c->result, "result:");
        if ($problem !== null) { return $problem; }
    }

    return same($store->data(), $data, "a read changes nothing:");

}

$usedOperations = array();

foreach (jsonFiles($fixturesDir . "/ops") as $file) {
    $fixture = readJSON($fixturesDir . "/ops/" . $file);
    foreach ($fixture->cases as $c) {
        attempt("ops/" . $file . ": " . $c->name, function() use ($fixture, $c, &$usedOperations) {
            $problem = runOpsCase($fixture, $c, $usedOperations);
            return array($problem === null, (string)$problem);
        });
    }
}

check("the fixtures cover every operation", count(array_diff(array_map(function($op) { return $op->name; }, $operations), array_keys($usedOperations))) === 0,
    implode(", ", array_diff(array_map(function($op) { return $op->name; }, $operations), array_keys($usedOperations))));

/**
 * Runs one lint case: the case's patch applied to its data, the store made,
 * the rules run; null when the findings are those expected.
 */
function runLintCase($fixture, $c, &$rules) {

    $dataName = Js::truthy(Js::get($c, "data")) ? $c->data : $fixture->data;
    $data     = applyPatch(fixtureData($dataName), Js::has($c, "patch") ? $c->patch : array());
    $store    = new FtConversationalUiModelStore($data, storeOptions($fixture, $c));
    $options  = Js::has($c, "rules") ? array("rules" => $c->rules) : array();
    $result   = FtConversationalUiLint::run($store, $options);

    $errors = FtConversationalUiLint::validateResult($result);
    if (count($errors)) { return "the result does not follow \$defs/result:\n" . formatErrors($errors); }

    foreach ($result->findings as $finding) { $rules[$finding->rule] = true; }

    return same($result->findings, $c->findings, "findings:");

}

$usedRules = array();

foreach (jsonFiles($fixturesDir . "/lint") as $file) {
    $fixture = readJSON($fixturesDir . "/lint/" . $file);
    foreach ($fixture->cases as $c) {
        attempt("lint/" . $file . ": " . $c->name, function() use ($fixture, $c, &$usedRules) {
            $problem = runLintCase($fixture, $c, $usedRules);
            return array($problem === null, (string)$problem);
        });
    }
}

$ruleIds = array_map(function($rule) { return $rule->id; }, FtConversationalUiLint::manifest()->rules);
check("the fixtures find something for every lint rule", count(array_diff($ruleIds, array_keys($usedRules))) === 0,
    implode(", ", array_diff($ruleIds, array_keys($usedRules))));


/* ---------------------------------------------------------------------- */
/*  Reading FrameTrail's fixtures                                         */
/* ---------------------------------------------------------------------- */

foreach (is_dir($frametrailFixtures . "/data") ? array_values(array_diff(scandir($frametrailFixtures . "/data"), array(".", ".."))) : array() as $name) {

    if ($name[0] === ".") { continue; }

    attempt("FrameTrail data/" . $name . ": every read gives a result that follows its output schema, and lint one that follows its own", function() use ($frametrailFixtures, $name, $operations) {

        $project = Serializer::readBundle(readDataFolder($frametrailFixtures . "/data/" . $name), "folder", array("bundle" => "project"));

        foreach (Js::keys($project->hypervideos) as $id) {

            $store = new FtConversationalUiModelStore($project, array("hypervideoId" => $id, "user" => array("id" => "1", "name" => "demo", "role" => "admin")));

            foreach ($operations as $op) {
                if ($op->effect !== "read") { continue; }
                $input = ($op->name === "find_in_transcript") ? Js::obj(array("query" => "the"))
                       : (($op->name === "get_item") ? Js::obj(array("kind" => "chapters", "ref" => 0)) : new stdClass());
                try {
                    $output = Ops::run($store, $op->name, $input);
                } catch (FtConversationalUiOpError $e) {
                    if ($e->errorCode !== "notFound") { return array(false, $id . " " . $op->name . ": " . $e->getMessage()); }
                    continue;
                }
                $errors = Ops::validateOutput($op->name, $output);
                if (count($errors)) { return array(false, $id . " " . $op->name . ":\n" . formatErrors($errors)); }
            }

            $errors = FtConversationalUiLint::validateResult(FtConversationalUiLint::run($store));
            if (count($errors)) { return array(false, $id . " lint:\n" . formatErrors($errors)); }

        }

        return true;

    });

}


/* ---------------------------------------------------------------------- */
/*  Helpers                                                               */
/* ---------------------------------------------------------------------- */

attempt("numbers are written as JavaScript writes them", function() {
    $cases = array(array(0.1 + 0.2, "0.30000000000000004"), array(1e21, "1e+21"), array(1e-7, "1e-7"), array(0.000001, "0.000001"),
                   array(120.0, "120"), array(12.5, "12.5"), array(-0.0, "0"), array(5e-324, "5e-324"), array(123456789012345680000.0, "123456789012345680000"),
                   array(-1.5e-10, "-1.5e-10"), array(NAN, "NaN"), array(INF, "Infinity"), array(42, "42"));
    foreach ($cases as $case) {
        if (Js::number($case[0]) !== $case[1]) { return array(false, var_export($case[0], true) . " → " . Js::number($case[0]) . ", expected " . $case[1]); }
    }
    return true;
});

attempt("dates are read and written as JavaScript reads and writes them", function() {
    $cases = array(
        array("2026-10-07T12:03:12.345Z", 1791374592345),
        array("2026-10-07T12:03:12Z", 1791374592000),
        array("2026-10-07", 1791331200000),
        array("2026-10-07T14:03:12.345+02:00", 1791374592345),
        array("Wed Oct 07 2026 14:03:12 GMT+0200 (Central European Summer Time)", 1791374592000),
        array("Wed, 07 Oct 2026 12:03:12 GMT", 1791374592000)
    );
    foreach ($cases as $case) {
        if (Js::dateParse($case[0]) !== $case[1]) { return array(false, $case[0] . " → " . Js::number(Js::dateParse($case[0]))); }
    }
    foreach (array("today", "", "2026-13-01", "Wed Oct 32 2026 14:03:12 GMT+0200") as $text) {
        if (!Js::isNaN(Js::dateParse($text))) { return array(false, $text . " is read"); }
    }
    return array(Js::isoString(1791374592345) === "2026-10-07T12:03:12.345Z" && Js::isoString(0) === "1970-01-01T00:00:00.000Z"
        && Js::isoString(-1) === "1969-12-31T23:59:59.999Z" && Js::isoString(NAN) === null, "toISOString");
});

attempt("mergePatch follows RFC 7386, diffPatch makes the patch from one value to another", function() {
    $o = function($json) { return Js::decode($json); };
    if (!Js::same(Util::mergePatch($o('{"a":"b","c":{"d":"e","f":"g"}}'), $o('{"a":"z","c":{"f":null}}')), $o('{"a":"z","c":{"d":"e"}}'))) { return false; }
    if (!Js::same(Util::mergePatch($o('{"a":1}'), $o('{"b":{"c":null}}')), $o('{"a":1,"b":{}}'))) { return false; }
    foreach (array(array('{"a":1,"b":{"c":2,"d":3},"e":[1]}', '{"a":1,"b":{"c":4},"e":[1,2],"f":"x"}'), array('{"a":{"b":1}}', '{"a":5}'), array('{"a":null}', '{}')) as $pair) {
        if (!Js::same(Util::mergePatch($o($pair[0]), Util::diffPatch($o($pair[0]), $o($pair[1]))), $o($pair[1]))) { return array(false, implode(" → ", $pair)); }
    }
    return Js::isUndef(Util::diffPatch($o('{"a":[1,{"b":2}]}'), $o('{"a":[1,{"b":2}]}')));
});

attempt("plainText, excerpt and cues follow the rules of shared/fixtures/README.md", function() {
    $checks = array(
        Util::plainText("&lt;p&gt;Fish &amp;amp; chips&lt;/p&gt;") === "Fish & chips",
        Util::plainText("<p>One</p>\n<p>two&nbsp;&#8211; &#x2014;</p>") === "One two – —",
        Util::plainText("a < b, c > d") === "a < b, c > d",
        Util::plainText("&unknown; &#0; &#xD800;") === "&unknown; &#0; &#xD800;",
        Util::plainText("a\u{00A0}\u{2003}b") === "a b",
        Util::excerpt("ab 😀de", 4) === "ab 😀…",
        Util::excerpt("abc", 3) === "abc"
    );
    $vtt  = "\u{FEFF}WEBVTT - a title\r\n\r\nSTYLE\r\n::cue { color: red }\r\n\r\nNOTE a note\r\n\r\nintro\r\n00:01.500 --> 00:04.000 align:start\r\n<v Ada>Hello <b>there</b></v> &amp; welcome\r\nsecond line\r\n\r\n01:00:00.000 --> 01:00:02,25\r\nAn hour in\r\n\r\n00:05.000 --> 00:06.000\r\n<i></i>\r\n";
    $cues = Util::cues($vtt);
    $checks[] = Js::same($cues, Js::decode('[{"start":1.5,"end":4,"text":"Hello there & welcome second line"},{"start":3600,"end":3602.25,"text":"An hour in"}]'));
    $checks[] = Util::fragment(0.1 + 0.2, 1e21, Js::obj(array("left" => 1, "top" => 2, "width" => 3, "height" => 4))) === "t=0.30000000000000004,1e+21&xywh=percent:1,2,3,4";
    $checks[] = Js::same(Util::clipSpan(Js::decode('[{"in":12,"out":132,"duration":0}]')), Js::decode('{"start":12,"duration":120}'));
    return !in_array(false, $checks, true);
});


done();
