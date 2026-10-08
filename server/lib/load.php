<?php

/*
 * FrameTrail-Conversational-UI — loads the server part's library: the ports of
 * FrameTrail's pure scripts (frametrail/), the operations (ops/) and the lint
 * rules. Handlers require it when they are called:
 *
 *     require_once __DIR__ . "/lib/load.php";
 *
 * Code that runs outside FrameTrail's routers (the command-line tool, the
 * tests) defines FT_CONVERSATIONAL_UI_LIB first.
 */

if (!function_exists("ftExtensionStorage") && !defined("FT_CONVERSATIONAL_UI_LIB")) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/js.php";
require_once __DIR__ . "/frametrail/keyframes.php";
require_once __DIR__ . "/frametrail/schema.php";
require_once __DIR__ . "/frametrail/serializer.php";
require_once __DIR__ . "/shared.php";
require_once __DIR__ . "/ops/util.php";
require_once __DIR__ . "/ops/items.php";
require_once __DIR__ . "/ops/store.php";
require_once __DIR__ . "/ops/model-store.php";
require_once __DIR__ . "/ops/interpreter.php";
require_once __DIR__ . "/lint.php";
