<?php

/*
 * FrameTrail-Conversational-UI — what the interpreter works against: the
 * interface of a store, and the errors of FrameTrail's edit API that stores
 * throw. The model store (model-store.php) is the one over a bundle; the
 * server's store over the files of an installation follows (A3).
 */

if (!function_exists("ftExtensionStorage") && !defined("FT_CONVERSATIONAL_UI_LIB")) {
    http_response_code(404);
    exit;
}

use FtConversationalUiJs as Js;


/**
 * The interface the interpreter works against: FrameTrail's edit API
 * (docs/EXTENDING.md, "Editing the Hypervideo"), plus versions() — the
 * compare-and-swap tokens of the files — and, where the store knows them,
 * resources() — the resources a lint can check references against.
 */
interface FtConversationalUiStore {
    public function getInfo();
    public function getUser();
    public function permission($kind);
    public function listHypervideos();
    public function versions();
    public function getHypervideo();
    public function list($kind, $filter = null);
    public function get($kind, $ref);
    public function add($kind, $data);
    public function update($kind, $ref, $patch);
    public function remove($kind, $ref);
    public function setLayout($area, $contentViews);
    public function setSubtitles($lang, $vtt);
    public function transaction($description, $fn);
}


/**
 * The errors of FrameTrail's edit API: errorCode "invalid", "notFound" or
 * "notAllowed", and for invalid data its { path, message } errors.
 */
class FtConversationalUiEditError extends Exception {

    public $errorCode;
    public $errors;

    public function __construct($code, $message, $errors = array()) {

        $details = count($errors)
            ? ": " . implode("; ", array_map(function($e) {
                return (Js::truthy($e->path) ? $e->path : "(data)") . ": " . $e->message;
            }, $errors))
            : "";

        parent::__construct($message . $details);

        $this->errorCode = $code;
        $this->errors    = $errors;

    }

}
