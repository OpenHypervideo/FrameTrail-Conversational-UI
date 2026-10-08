<?php

/*
 * FrameTrail-Conversational-UI — the server part: the actions and routes it
 * adds to FrameTrail's backend, declared as FrameTrail's server extensions
 * declare them (FrameTrail's docs/EXTENDING.md, "Server Extensions").
 *
 * Installed as _server/extensions/conversational-ui/ in the FrameTrail code
 * tree, and switched on by the extension's entry in _data/config.json →
 * extensions, the same entry that loads the browser part.
 *
 * FrameTrail loads this file whenever it meets an action it does not know and
 * on every admin's heartbeat, so it declares and returns, and does nothing
 * else. Handlers that need more code include it when they are called.
 *
 * - Action conversationalUiStatus: answers that the server part is installed,
 *   and which version it is.
 *
 *       curl -d a=conversationalUiStatus https://example.org/frametrail/_server/ajaxServer.php
 */

// Only ever run by FrameTrail's routers, which load this file to read what it
// returns. A web server that would run it on its own (PHP's built-in server
// reads no .htaccess) finds nothing to do.
if (!function_exists("ftExtensionStorage")) {
    http_response_code(404);
    exit;
}


/**
 * I return the add-on's version: the release label the build writes in, or
 * "dev" in a copy that did not go through the build.
 *
 * @method ftConversationalUiVersion
 * @return String
 */
function ftConversationalUiVersion() {

    $version = "__CONVERSATIONAL_UI_VERSION__";

    return (substr($version, 0, 2) === "__") ? "dev" : $version;

}


/**
 * Action conversationalUiStatus.
 *
 * @method ftConversationalUiStatus
 * @param {Array} $ext  array("name" => "conversational-ui", "settings" => …)
 * @return Array
 */
function ftConversationalUiStatus($ext) {

    return array(
        "status"   => "success",
        "code"     => 0,
        "response" => array(
            "version" => ftConversationalUiVersion()
        )
    );

}


// "requires" lists only what every part needs: a missing requirement takes
// away all actions and routes of the extension. A feature that needs more
// (curl for the model relay) checks for it when it is called.
return array(
    "actions"  => array(
        "conversationalUiStatus" => "ftConversationalUiStatus"
    ),
    "routes"   => array(),
    "requires" => array("json")
);
