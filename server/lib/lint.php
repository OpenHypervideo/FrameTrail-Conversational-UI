<?php

/*
 * FrameTrail-Conversational-UI — the lint rules, the PHP side of
 * client/lint/lint.js: the rules of shared/lint.json, with the same findings
 * and messages.
 *
 *     $result = FtConversationalUiLint::run($store);
 *     FtConversationalUiLint::run($store, array("rules" => array("chapter-order")));
 *     // → { errors, warnings, findings: [{ rule, severity, kind, ref, creator?, related?, message }] }
 */

if (!function_exists("ftExtensionStorage") && !defined("FT_CONVERSATIONAL_UI_LIB")) {
    http_response_code(404);
    exit;
}

use FtConversationalUiJs as Js;
use FtConversationalUiOpsUtil as Util;


class FtConversationalUiLint {

    // The $id of the schema document made from lint.json's $defs.
    const LINT_ID = "https://raw.githubusercontent.com/OpenHypervideo/FrameTrail-Conversational-UI/main/shared/lint.json";

    // Overlays that are meant to lie over others.
    const OVERLAPPING_TYPES = array("hotspot", "cursor");

    // Body types that show a source: a URL, a page, a file.
    const SOURCE_TYPES = array("image", "video", "audio", "pdf", "youtube", "vimeo", "wistia", "loom", "twitch", "soundcloud",
                               "spotify", "webpage", "wikipedia", "mastodon", "codepen", "figma", "urlpreview");

    // Body types that show a media file.
    const MEDIA_TYPES = array("image", "video", "audio", "pdf");

    // Hotspot actions that need a target.
    const TARGETED_ACTIONS = array("openUrl", "jumpToTime", "jumpToHypervideo");

    // The implementations, by rule.
    const RULES = array(
        "item-outside-video"  => "itemOutsideVideo",
        "item-partly-outside" => "itemPartlyOutside",
        "overlay-overlap"     => "overlayOverlap",
        "unknown-resource"    => "unknownResource",
        "empty-required"      => "emptyRequired",
        "missing-license"     => "missingLicense",
        "chapter-order"       => "chapterOrder",
        "cue-outside-video"   => "cueOutsideVideo"
    );

    private static $validator = null;


    public static function manifest() {
        return FtConversationalUiShared::json("lint.json");
    }

    // The errors of a lint result against $defs/result.
    public static function validateResult($result) {
        if (self::$validator === null) {
            self::$validator = FtConversationalUiSchema::create(array_merge(FtConversationalUiShared::frameTrailSchemas(), array(
                Js::obj(array('$id' => self::LINT_ID, '$defs' => self::manifest()->{'$defs'}))
            )));
        }
        return self::$validator->validate(self::LINT_ID . "#/\$defs/result", $result);
    }


    /* ------------------------------------------------------------------ */
    /*  Helpers                                                           */
    /* ------------------------------------------------------------------ */

    private static function bodyOf($item) {
        $body = Js::get($item, "body");
        $body = is_array($body) ? Js::get($body, 0) : $body;
        return Js::isObject($body) ? $body : new stdClass();
    }

    private static function attributesOf($body) {
        $attributes = Js::get($body, "frametrail:attributes");
        return Js::isObject($attributes) ? $attributes : new stdClass();
    }

    private static function selectorValue($item) {
        $target = Js::get($item, "target");
        return (Js::isObject($target) && Js::isObject(Js::get($target, "selector"))) ? Js::get($target->selector, "value") : Js::undef();
    }

    private static function nonEmpty($value) {
        return is_string($value) && Js::trim($value) !== "";
    }

    private static function named($id) {
        return !Js::isUndef($id) && $id !== null && $id !== "";
    }

    private static function n($value) {
        return Js::str($value);
    }

    private static function rangeText($info) {
        return ($info["end"] !== null) ? self::n($info["start"]) . " s to " . self::n($info["end"]) . " s" : "from " . self::n($info["start"]) . " s";
    }

    // Who an item is: kind and ref, and for annotations their creator.
    private static function about($kind, $item) {
        $where   = array("kind" => $kind, "ref" => ($kind === "chapters") ? Js::get($item, "start") : Js::get($item, "created"));
        $creator = Js::get($item, "creator");
        if ($kind === "annotations" && Js::isObject($creator) && !Js::isUndef(Js::get($creator, "id"))) {
            $where["creator"] = Js::str($creator->id);
        }
        return $where;
    }

    private static function outside($span, $info) {
        return ($info["end"] !== null && $span->start >= $info["end"]) || ($span->end <= $info["start"] && $span->start < $info["start"]);
    }


    /* ------------------------------------------------------------------ */
    /*  The rules: (data, report) where report(where, message)            */
    /* ------------------------------------------------------------------ */

    private static function itemOutsideVideo($data, $report) {

        $info = $data["info"];

        foreach ($data["spans"] as $entry) {
            $span = $entry["span"];
            if (self::outside($span, $info)) {
                $report($entry["where"], "Runs from " . self::n($span->start) . " s to " . self::n($span->end) . " s, outside the video (" . self::rangeText($info) . "); the player never shows it.");
            }
        }

        foreach ($data["points"] as $entry) {
            if (($info["end"] !== null && $entry["start"] >= $info["end"]) || $entry["start"] < $info["start"]) {
                $report($entry["where"], "Is at " . self::n($entry["start"]) . " s, outside the video (" . self::rangeText($info) . "); the player never reaches it.");
            }
        }

    }

    private static function itemPartlyOutside($data, $report) {

        $info = $data["info"];

        foreach ($data["spans"] as $entry) {
            $span = $entry["span"];
            if (!self::outside($span, $info) && (($info["end"] !== null && $span->end > $info["end"]) || $span->start < $info["start"])) {
                $report($entry["where"], "Runs from " . self::n($span->start) . " s to " . self::n($span->end) . " s, partly outside the video (" . self::rangeText($info) . "); that part is never shown.");
            }
        }

    }

    private static function overlayOverlap($data, $report) {

        $placed = array();

        foreach ($data["overlays"] as $overlay) {
            $area = Util::box(self::selectorValue($overlay));
            if (in_array(Js::get(self::bodyOf($overlay), "frametrail:type"), self::OVERLAPPING_TYPES, true) || !$area || !($area->width > 0) || !($area->height > 0)) { continue; }
            $placed[] = array("overlay" => $overlay, "area" => $area, "span" => Util::timeSpan(self::selectorValue($overlay)));
        }

        foreach ($placed as $j => $b) {
            for ($i = 0; $i < $j; $i++) {
                $a = $placed[$i];
                if ($a["span"]->start < $b["span"]->end && $b["span"]->start < $a["span"]->end
                        && $a["area"]->left < $b["area"]->left + $b["area"]->width && $b["area"]->left < $a["area"]->left + $a["area"]->width
                        && $a["area"]->top < $b["area"]->top + $b["area"]->height && $b["area"]->top < $a["area"]->top + $a["area"]->height) {
                    $where = self::about("overlays", $b["overlay"]);
                    $where["related"] = Js::obj(array("kind" => "overlays", "ref" => Js::get($a["overlay"], "created")));
                    $report($where, "Covers the same area as overlay " . Js::str(Js::get($a["overlay"], "created")) . " from "
                        . self::n(max($a["span"]->start, $b["span"]->start)) . " s to " . self::n(min($a["span"]->end, $b["span"]->end)) . " s.");
                }
            }
        }

    }

    private static function unknownResource($data, $report) {

        $resources = $data["resources"];

        if (!$resources) { return; }

        $known = function($id) use ($resources) { return property_exists($resources, Js::str($id)); };

        $clips = Js::get($data["hypervideo"], "clips");
        $clip  = (is_array($clips) && count($clips) && Js::isObject($clips[0])) ? $clips[0] : new stdClass();
        $id    = Js::get($clip, "resourceId");

        if (self::named($id) && !$known($id)) {
            $report(array("kind" => "hypervideo"), "Its video is resource " . Js::stringify($id) . ", which is not in the resources index"
                . (self::nonEmpty(Js::get($clip, "src")) ? "." : "; without a src of its own the clip has no video."));
        }

        foreach ($data["items"] as $entry) {
            $id = Js::get(self::bodyOf($entry["item"]), "frametrail:resourceId");
            if (self::named($id) && !$known($id)) {
                $report($entry["where"], "Made from resource " . Js::stringify($id) . ", which is not in the resources index.");
            }
        }

    }

    private static function emptyRequired($data, $report) {

        foreach ($data["items"] as $entry) {

            $body    = self::bodyOf($entry["item"]);
            $type    = Js::get($body, "frametrail:type");
            $attrs   = self::attributesOf($body);
            $missing = array();

            if ($entry["where"]["kind"] === "codeSnippets") {
                if (!self::nonEmpty(Js::get($body, "value"))) { $missing[] = "no code"; }
            } else if (in_array($type, self::SOURCE_TYPES, true)) {
                if (!self::nonEmpty(Js::get($body, "source")) && !self::nonEmpty(Js::get($body, "value"))) { $missing[] = "no source"; }
            } else if ($type === "entity") {
                if (!self::nonEmpty(Js::get($body, "source")) && !self::nonEmpty(Js::get($body, "value")) && !self::nonEmpty(Js::get($entry["item"], "frametrail:uri"))) { $missing[] = "no source"; }
            } else if ($type === "location") {
                if (!Js::isFinite(Js::parseFloat(Js::get($attrs, "lat"))) || !Js::isFinite(Js::parseFloat(Js::get($attrs, "lon")))) { $missing[] = "no position (lat, lon)"; }
            } else if ($type === "text") {
                if (Util::plainText(Js::get($attrs, "text")) === "" && Util::plainText(Js::get($attrs, "title")) === "") { $missing[] = "no text"; }
            } else if ($type === "html") {
                if (!self::nonEmpty(Js::get($attrs, "text"))) { $missing[] = "no HTML"; }
            } else if ($type === "quiz") {
                if (Util::plainText(Js::get($attrs, "question")) === "") { $missing[] = "no question"; }
                $questionType = Js::get($attrs, "questionType");
                if (Js::isUndef($questionType) || $questionType === "multipleChoice" || $questionType === "multiSelect") {
                    $answers = is_array(Js::get($attrs, "answers")) ? $attrs->answers : array();
                    if (!count($answers)) {
                        $missing[] = "no answers";
                    } else {
                        foreach ($answers as $answer) {
                            if (!Js::isObject($answer) || Util::plainText(Js::get($answer, "text")) === "") {
                                $missing[] = "an answer without text";
                                break;
                            }
                        }
                    }
                }
            } else if ($type === "chart") {
                if (!self::nonEmpty(Js::get($attrs, "data"))) { $missing[] = "no data"; }
            } else if ($type === "hotspot") {
                $action = Js::get($attrs, "action");
                $target = Js::get($attrs, "actionTarget");
                if (in_array($action, self::TARGETED_ACTIONS, true)
                        && (Js::isUndef($target) || $target === null || (is_string($target) && Js::trim($target) === ""))) {
                    $missing[] = "no target for its action " . $action;
                }
            }

            if (count($missing)) {
                $report($entry["where"], "Is incomplete: " . implode(", ", $missing) . ".");
            }

        }

    }

    private static function missingLicense($data, $report) {

        foreach ($data["overlays"] as $overlay) {
            $body       = self::bodyOf($overlay);
            $resourceId = Js::get($body, "frametrail:resourceId");
            $fromResource = self::named($resourceId);
            $backed     = $fromResource || (in_array(Js::get($body, "frametrail:type"), self::MEDIA_TYPES, true) && self::nonEmpty(Js::get($body, "source")));
            if ($backed && !self::nonEmpty(Js::get($body, "frametrail:licenseType"))) {
                $report(self::about("overlays", $overlay), "Has no license type (body frametrail:licenseType) for the "
                    . ($fromResource ? "resource it is made from." : "media it shows."));
            }
        }

    }

    private static function chapterOrder($data, $report) {

        $stored   = Js::get($data["hypervideo"], "chapters");
        $chapters = Js::filter(is_array($stored) ? $stored : array(), function($chapter) {
            return Js::isObject($chapter) && Js::isNumber(Js::get($chapter, "start"));
        });

        foreach ($chapters as $i => $chapter) {
            if ($i === 0) { continue; }
            $previous = $chapters[$i - 1]->start;
            if (Js::numEq($chapter->start, $previous)) {
                $report(array("kind" => "chapters", "ref" => $chapter->start), "Two chapters start at " . self::n($chapter->start) . " s; each start may be used once.");
            } else if ($chapter->start < $previous) {
                $report(array("kind" => "chapters", "ref" => $chapter->start), "The chapter at " . self::n($chapter->start) . " s is stored after the one at " . self::n($previous) . " s; chapters are kept in order of their start.");
            }
        }

    }

    private static function cueOutsideVideo($data, $report) {

        $clips = Js::get($data["hypervideo"], "clips");
        $clip  = (is_array($clips) && count($clips) && Js::isObject($clips[0])) ? $clips[0] : new stdClass();
        $out   = Js::get($clip, "out");
        $end   = $data["info"]["end"];

        if ($end === null || (Js::isNumber($out) && $out > 0)) { return; }

        foreach ($data["subtitles"] as $entry) {
            $late = Js::filter(Util::cues($entry["vtt"]), function($cue) use ($end) { return $cue->start >= $end; });
            if (count($late)) {
                $report(array("kind" => "subtitles", "ref" => $entry["lang"]), ((count($late) === 1) ? "1 cue starts" : count($late) . " cues start")
                    . " at or after the end of the video (" . self::n($end) . " s), the first at " . self::n($late[0]->start) . " s.");
            }
        }

    }


    /* ------------------------------------------------------------------ */
    /*  Running                                                           */
    /* ------------------------------------------------------------------ */

    // What the rules read, once.
    private static function collect($store) {

        $hypervideo  = $store->getHypervideo();
        $info        = $store->getInfo();
        $overlays    = $store->list("overlays");
        $annotations = $store->list("annotations");
        $snippets    = $store->list("codeSnippets");
        $chapters    = $store->list("chapters");
        $items       = array();

        foreach ($overlays as $item)    { $items[] = array("item" => $item, "where" => self::about("overlays", $item)); }
        foreach ($annotations as $item) { $items[] = array("item" => $item, "where" => self::about("annotations", $item)); }
        foreach ($snippets as $item)    { $items[] = array("item" => $item, "where" => self::about("codeSnippets", $item)); }

        $subtitles = array();
        foreach ($store->list("subtitles") as $file) {
            if (!Js::isObject($file)) { continue; }
            $entry       = $store->get("subtitles", Js::get($file, "srclang"));
            $subtitles[] = array("lang" => Js::str(Js::get($file, "srclang")), "vtt" => ($entry && is_string(Js::get($entry, "vtt"))) ? $entry->vtt : null);
        }

        $spans = array();
        foreach ($items as $entry) {
            if ($entry["where"]["kind"] !== "codeSnippets") {
                $spans[] = array("where" => $entry["where"], "span" => Util::timeSpan(self::selectorValue($entry["item"])));
            }
        }

        $points = array();
        foreach ($snippets as $item) { $points[] = array("where" => self::about("codeSnippets", $item), "start" => Util::timeSpan(self::selectorValue($item))->start); }
        foreach ($chapters as $item) { $points[] = array("where" => self::about("chapters", $item), "start" => Js::get($item, "start")); }

        return array(
            "hypervideo" => Js::isObject($hypervideo) ? $hypervideo : new stdClass(),
            "info"       => array(
                "start" => Js::isNumber(Js::get($info, "start")) ? $info->start : 0,
                "end"   => Js::isNumber(Js::get($info, "end")) ? $info->end : null
            ),
            "overlays"   => $overlays,
            "items"      => $items,
            "spans"      => $spans,
            "points"     => $points,
            "subtitles"  => $subtitles,
            "resources"  => method_exists($store, "resources") ? $store->resources() : null
        );

    }

    /**
     * I lint the hypervideo of a store: { errors, warnings, findings }.
     *
     * @method run
     * @param {FtConversationalUiStore} $store
     * @param {Array} $options array("rules" => ids) to run only these
     * @return stdClass
     */
    public static function run($store, $options = array()) {

        $only     = (is_array($options) && isset($options["rules"]) && is_array($options["rules"])) ? $options["rules"] : null;
        $data     = self::collect($store);
        $findings = array();

        foreach (self::manifest()->rules as $rule) {

            if ($only !== null && !in_array($rule->id, $only, true)) { continue; }
            if (!array_key_exists($rule->id, self::RULES)) { throw new Exception("FrameTrail-Conversational-UI: no implementation of the lint rule " . $rule->id); }

            $report = function($where, $message) use ($rule, &$findings) {
                $finding = Js::obj(array("rule" => $rule->id, "severity" => $rule->severity, "kind" => $where["kind"]));
                if (array_key_exists("ref", $where) && !Js::isUndef($where["ref"])) { $finding->ref = $where["ref"]; }
                if (array_key_exists("creator", $where)) { $finding->creator = $where["creator"]; }
                if (array_key_exists("related", $where)) { $finding->related = $where["related"]; }
                $finding->message = $message;
                $findings[] = $finding;
            };

            call_user_func(array(__CLASS__, self::RULES[$rule->id]), $data, $report);

        }

        $errors   = count(array_filter($findings, function($finding) { return $finding->severity === "error"; }));
        $warnings = count(array_filter($findings, function($finding) { return $finding->severity === "warning"; }));

        return Js::obj(array("errors" => $errors, "warnings" => $warnings, "findings" => $findings));

    }

}
