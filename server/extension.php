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
 *   which version it is, and how the chat panel reaches Mistral here
 *   (capabilities: relay, ownKey).
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
 * I return how the chat panel may reach Mistral on this server: relay (the
 * server holds a key and passes requests on; not yet there), ownKey (users
 * may use their own key directly from the browser: allowOwnKey in
 * _data/.auth/conversational-ui.php). Neither is a secret.
 *
 * @method ftConversationalUiCapabilities
 * @return Array
 */
function ftConversationalUiCapabilities() {

    $secrets = ftExtensionSecrets("conversational-ui");

    return array(
        "relay"  => false,
        "ownKey" => isset($secrets["allowOwnKey"]) && $secrets["allowOwnKey"] === true
    );

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
            "version"      => ftConversationalUiVersion(),
            "capabilities" => ftConversationalUiCapabilities()
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
