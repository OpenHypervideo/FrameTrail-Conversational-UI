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
 *   (capabilities: relay, ownKey, and the relay's models and limit).
 *
 *       curl -d a=conversationalUiStatus https://example.org/frametrail/_server/ajaxServer.php
 *
 * - Action conversationalUiChat and route relay: the model relay (relay.php),
 *   Mistral's Chat Completions API through this server, which holds the key.
 *
 * The configuration is the array _data/.auth/conversational-ui.php returns
 * (ftConversationalUiConfig() reads it); see the README.
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
 * I return the add-on's configuration, the array
 * _data/.auth/conversational-ui.php returns, with every key checked and its
 * default filled in:
 *
 * - apiKey: the key the relay sends upstream (Mistral's, or a gateway's
 *   token), or null: no relay;
 * - baseUrl: the API the relay talks to, without a trailing slash; default
 *   Mistral's (https://api.mistral.ai/v1). baseUrlValid: http(s) and a host;
 * - allowedModels: the models the relay passes requests for; defaultModel: the
 *   one the panel starts with (one of them). Without either, ministral-14b-latest;
 * - requestsPerDay: requests to the model per person and day, or null for no
 *   limit;
 * - timeout: seconds one request to the model may take (10 to 3600, default 300);
 * - instance: what the relay sends a gateway as X-FrameTrail-Instance, or null
 *   for the installation's URL;
 * - allowOwnKey: whether users may use their own key directly from the browser.
 *
 * @method ftConversationalUiConfig
 * @return Array
 */
function ftConversationalUiConfig() {

    $secrets = ftExtensionSecrets("conversational-ui");

    $text = function($key) use ($secrets) {
        return (isset($secrets[$key]) && is_string($secrets[$key]) && trim($secrets[$key]) !== "") ? trim($secrets[$key]) : null;
    };

    $baseUrl = $text("baseUrl");
    $baseUrl = ($baseUrl !== null) ? rtrim($baseUrl, "/") : "https://api.mistral.ai/v1";

    $models = array();
    if (isset($secrets["allowedModels"]) && is_array($secrets["allowedModels"])) {
        foreach ($secrets["allowedModels"] as $model) {
            if (is_string($model) && trim($model) !== "" && !in_array(trim($model), $models, true)) {
                $models[] = trim($model);
            }
        }
    }

    $defaultModel = $text("defaultModel");
    if (count($models) === 0) {
        $models = array($defaultModel !== null ? $defaultModel : "ministral-14b-latest");
    }
    if ($defaultModel === null || !in_array($defaultModel, $models, true)) {
        $defaultModel = $models[0];
    }

    $limit = (isset($secrets["requestsPerDay"]) && (is_int($secrets["requestsPerDay"]) || is_float($secrets["requestsPerDay"])) && $secrets["requestsPerDay"] >= 1)
        ? (int)floor($secrets["requestsPerDay"]) : null;

    $timeout = (isset($secrets["timeout"]) && (is_int($secrets["timeout"]) || is_float($secrets["timeout"])))
        ? (int)max(10, min(3600, $secrets["timeout"])) : 300;

    return array(
        "apiKey"         => $text("apiKey"),
        "baseUrl"        => $baseUrl,
        "baseUrlValid"   => preg_match('#^https?://[^/\s?\#@]+(/[^\s?\#]*)?$#i', $baseUrl) === 1,
        "allowedModels"  => $models,
        "defaultModel"   => $defaultModel,
        "requestsPerDay" => $limit,
        "timeout"        => $timeout,
        "instance"       => $text("instance"),
        "allowOwnKey"    => isset($secrets["allowOwnKey"]) && $secrets["allowOwnKey"] === true
    );

}


/**
 * I return how the chat panel may reach Mistral on this server:
 *
 * - relay: the server holds a key and passes requests on (apiKey set, a valid
 *   baseUrl, PHP's curl extension); then also models (the allowed ones),
 *   defaultModel and requestsPerDay (null: no limit);
 * - ownKey: users may use their own key directly from the browser (allowOwnKey);
 * - problem: why a configured relay cannot work: "curl" or "baseUrl".
 *
 * None of it is a secret: the key stays here.
 *
 * @method ftConversationalUiCapabilities
 * @return Array
 */
function ftConversationalUiCapabilities() {

    $config = ftConversationalUiConfig();
    $curl   = function_exists("curl_init");

    $capabilities = array(
        "relay"  => $config["apiKey"] !== null && $config["baseUrlValid"] && $curl,
        "ownKey" => $config["allowOwnKey"]
    );

    if ($capabilities["relay"]) {
        $capabilities["models"]         = $config["allowedModels"];
        $capabilities["defaultModel"]   = $config["defaultModel"];
        $capabilities["requestsPerDay"] = $config["requestsPerDay"];
    } elseif ($config["apiKey"] !== null) {
        $capabilities["problem"] = $curl ? "baseUrl" : "curl";
    }

    return $capabilities;

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


/**
 * Action conversationalUiChat: one request to the model without streaming,
 * through the relay (relay.php).
 *
 * @method ftConversationalUiChatAction
 * @param {Array} $ext
 * @return Array
 */
function ftConversationalUiChatAction($ext) {

    require_once __DIR__ . "/relay.php";

    return ftConversationalUiChat($ext);

}


/**
 * Route relay: one request to the model, its answer streamed (relay.php).
 *
 * @method ftConversationalUiRelayRoute
 * @param {Array} $ext
 * @return null
 */
function ftConversationalUiRelayRoute($ext) {

    require_once __DIR__ . "/relay.php";

    return ftConversationalUiRelay($ext);

}


// "requires" lists only what every part needs: a missing requirement takes
// away all actions and routes of the extension. A feature that needs more
// (curl for the model relay) checks for it when it is called.
return array(
    "actions"  => array(
        "conversationalUiStatus" => "ftConversationalUiStatus",
        "conversationalUiChat"   => "ftConversationalUiChatAction"
    ),
    "routes"   => array(
        "relay" => "ftConversationalUiRelayRoute"
    ),
    "requires" => array("json")
);
