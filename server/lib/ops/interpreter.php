<?php

/*
 * FrameTrail-Conversational-UI — the interpreter of the operations in
 * shared/operations.json, the PHP side of client/ops/interpreter.js: inputs
 * validated against the manifest's schemas, preconditions checked, the
 * operations carried out against a store; changesets applied all or none, with
 * each operation's inverse.
 *
 *     FtConversationalUiOps::run($store, "list_items", Js::obj(array("kind" => "chapters")));
 *     $applied = FtConversationalUiOps::apply($store, $changeset, array("generator" => $generator));
 *     FtConversationalUiOps::undo($store, $applied->changeset);
 *
 * Errors are FtConversationalUiOpError: errorCode invalid (errors with JSON
 * Pointers into the input), notFound, notAllowed or conflict. Inputs and
 * results are JSON values (objects as stdClass); an absent input is
 * Js::undef().
 */

if (!function_exists("ftExtensionStorage") && !defined("FT_CONVERSATIONAL_UI_LIB")) {
    http_response_code(404);
    exit;
}

use FtConversationalUiJs as Js;
use FtConversationalUiOpsUtil as Util;
use FtConversationalUiItems as Items;


class FtConversationalUiOps {

    // The $id of the schema document made from the manifest.
    const OPERATIONS_ID = "https://raw.githubusercontent.com/OpenHypervideo/FrameTrail-Conversational-UI/main/shared/operations.json";

    const ITEM_KINDS = array("overlays", "annotations", "chapters", "codeSnippets");
    const AREAS      = array("top", "bottom", "left", "right");
    const NAMES      = array("overlays" => "overlay", "annotations" => "annotation", "chapters" => "chapter", "codeSnippets" => "code snippet");
    const CALLS      = array("add", "update", "remove", "setLayout", "setSubtitles");

    // Time map segments without chapters: the shortest of these that makes at most 12 segments.
    const SEGMENT_LENGTHS = array(30, 60, 120, 300, 600, 900, 1800, 3600);

    const LIMITS = array("list_items" => 100, "read_transcript" => 200, "find_in_transcript" => 50);

    // The implementations, by operation: (store, input, context) → array("result" => …, "inverse" => …).
    const IMPLEMENTATIONS = array(
        "list_hypervideos"   => "opListHypervideos",
        "inspect_hypervideo" => "opInspectHypervideo",
        "list_items"         => "opListItems",
        "get_item"           => "opGetItem",
        "read_transcript"    => "opReadTranscript",
        "find_in_transcript" => "opFindInTranscript",
        "add_overlay"        => "opAddOverlay",
        "update_overlay"     => "opUpdateOverlay",
        "remove_overlay"     => "opRemoveOverlay",
        "add_annotation"     => "opAddAnnotation",
        "update_annotation"  => "opUpdateAnnotation",
        "remove_annotation"  => "opRemoveAnnotation",
        "add_chapter"        => "opAddChapter",
        "update_chapter"     => "opUpdateChapter",
        "remove_chapter"     => "opRemoveChapter",
        "set_subtitles"      => "opSetSubtitles",
        "set_layout_area"    => "opSetLayoutArea"
    );

    private static $validator = null;
    private static $byName    = null;


    /* ------------------------------------------------------------------ */
    /*  The manifest                                                      */
    /* ------------------------------------------------------------------ */

    public static function manifest() {
        return FtConversationalUiShared::json("operations.json");
    }

    public static function changesetSchema() {
        return FtConversationalUiShared::json("changeset.schema.json");
    }

    // An operation of the manifest by name, or null.
    public static function operation($name) {
        if (self::$byName === null) {
            self::$byName = array();
            foreach (self::manifest()->operations as $op) { self::$byName[$op->name] = $op; }
        }
        return (is_string($name) && array_key_exists($name, self::$byName)) ? self::$byName[$name] : null;
    }

    private static function requireOperation($name) {
        $op = self::operation($name);
        if (!$op) {
            throw new FtConversationalUiOpError("invalid", "Unknown operation " . Js::stringify($name) . "; one of "
                . implode(", ", array_map(function($o) { return $o->name; }, self::manifest()->operations)));
        }
        return $op;
    }

    /**
     * A validator that knows FrameTrail's schemas, the manifest's (as the
     * document OPERATIONS_ID: $defs/<name> for the shared ones,
     * $defs/<operation>-input and -output) and the changeset's.
     *
     * @method validator
     * @return FtConversationalUiSchema
     */
    public static function validator() {

        if (self::$validator === null) {

            $defs = clone self::manifest()->{'$defs'};

            foreach (self::manifest()->operations as $op) {
                $defs->{$op->name . "-input"}  = $op->input;
                $defs->{$op->name . "-output"} = $op->output;
            }

            self::$validator = FtConversationalUiSchema::create(array_merge(FtConversationalUiShared::frameTrailSchemas(), array(
                Js::obj(array('$id' => self::OPERATIONS_ID, '$defs' => $defs)),
                self::changesetSchema()
            )));

        }

        return self::$validator;

    }

    public static function validateInput($name, $input) {
        return self::validator()->validate(self::OPERATIONS_ID . "#/\$defs/" . self::requireOperation($name)->name . "-input", $input);
    }

    public static function validateOutput($name, $output) {
        return self::validator()->validate(self::OPERATIONS_ID . "#/\$defs/" . self::requireOperation($name)->name . "-output", $output);
    }


    /* ------------------------------------------------------------------ */
    /*  Errors from the store                                             */
    /* ------------------------------------------------------------------ */

    private static function error($path, $message) {
        return Js::obj(array("path" => $path, "message" => $message));
    }

    // Where the store's paths into an item point to in an operation's input.
    public static function itemPath($path, $message) {
        $rules = array(
            array("/target/selector/frametrail:keyframes", "/keyframes"),
            array("/target/selector/frametrail:rotation", "/rotation"),
            array("/frametrail:tags", "/tags"),
            array("/frametrail:events", "/events"),
            array("/body", "/body")
        );
        foreach ($rules as $rule) {
            if ($path === $rule[0] || strpos($path, $rule[0] . "/") === 0) {
                return $rule[1] . substr($path, strlen($rule[0]));
            }
        }
        if ($path === "/target/selector/value" && $message === "must not end before it starts") {
            return self::error("/end", "must not be before start");
        }
        return null;
    }

    /**
     * I call a store method and turn the edit API's errors into op errors,
     * with paths into the input ($path maps a store path and message to a
     * path, to { path, message }, or to null).
     */
    private static function call($fn, $path = null) {

        try {
            return $fn();
        } catch (FtConversationalUiEditError $e) {

            if ($e->errorCode !== "invalid" || !count($e->errors)) {
                throw new FtConversationalUiOpError($e->errorCode, $e->getMessage());
            }

            throw new FtConversationalUiOpError("invalid", "Invalid input", array_map(function($error) use ($path) {
                $mapped = $path ? $path($error->path, $error->message) : $error->path;
                if ($mapped === null) {
                    return self::error("", (Js::truthy($error->path) ? $error->path . ": " : "") . $error->message);
                }
                return Js::isObject($mapped) ? $mapped : self::error($mapped, $error->message);
            }, $e->errors));

        }

    }


    /* ------------------------------------------------------------------ */
    /*  Preconditions                                                     */
    /* ------------------------------------------------------------------ */

    private static function itemRef($input) {
        return (Js::get($input, "kind") === "annotations" && Js::has($input, "creator"))
            ? Js::obj(array("creator" => $input->creator, "created" => $input->ref))
            : Js::get($input, "ref");
    }

    // The store's permission for a kind of thing, as an op error when it is refused.
    private static function requirePermission($store, $kind) {
        $permission = self::call(function() use ($store, $kind) { return $store->permission($kind); });
        if ($permission->allowed !== true) {
            throw new FtConversationalUiOpError(Js::truthy(Js::get($permission, "code")) ? $permission->code : "notAllowed", Js::str(Js::get($permission, "message")));
        }
    }

    private static function precondition($name, $store, $op, $input) {

        switch ($name) {

            case "canEditHypervideo":
                self::requirePermission($store, $op->kind);
                return;

            case "canAnnotate":
                self::requirePermission($store, "annotations");
                return;

            case "itemExists":
                $kind  = Js::has($input, "kind") ? $input->kind : $op->kind;
                $query = clone $input;
                $query->kind = $kind;
                if (!self::call(function() use ($store, $kind, $query) { return $store->get($kind, self::itemRef($query)); })) {
                    throw new FtConversationalUiOpError("notFound", "No " . self::NAMES[$kind] . " " . Js::stringify(Js::get($input, "ref"))
                        . (($kind === "annotations" && !Js::has($input, "creator")) ? " among your own annotations" : ""));
                }
                return;

            case "chapterStartFree":
                if (!Js::has($input, "start") || (Js::has($input, "ref") && Js::strictEq($input->ref, $input->start))) { return; }
                if ($store->get("chapters", $input->start)) {
                    throw new FtConversationalUiOpError("invalid", "Invalid input", array(self::error("/start", "is taken by another chapter")));
                }
                return;

            case "subtitles":
                $languages = self::subtitleLanguages($store);
                if (!count($languages)) {
                    throw new FtConversationalUiOpError("notFound", "This hypervideo has no subtitles");
                }
                if (Js::has($input, "lang") && !in_array($input->lang, $languages, true)) {
                    throw new FtConversationalUiOpError("notFound", "No subtitles in " . Js::stringify($input->lang) . "; there are: " . implode(", ", $languages));
                }
                return;

        }

        throw new Exception("Unknown precondition " . $name);

    }


    /* ------------------------------------------------------------------ */
    /*  Reading                                                           */
    /* ------------------------------------------------------------------ */

    // The open hypervideo's id, video and time (the store's getInfo()).
    private static function infoOf($store) {
        return self::call(function() use ($store) { return $store->getInfo(); });
    }

    private static function userIdOf($store) {
        $user = $store->getUser();
        return $user ? Js::str($user->id) : "";
    }

    private static function subtitleLanguages($store) {
        $languages = array();
        foreach ($store->list("subtitles") as $file) {
            if (Js::isObject($file)) { $languages[] = Js::str(Js::get($file, "srclang")); }
        }
        return $languages;
    }

    // The time span of an item as stored (a chapter: its start).
    private static function spanOf($kind, $item) {
        if ($kind === "chapters") { return Js::obj(array("start" => $item->start, "end" => $item->start)); }
        $target   = Js::get($item, "target");
        $selector = (Js::isObject($target) && Js::isObject(Js::get($target, "selector"))) ? $target->selector : new stdClass();
        return Util::timeSpan(Js::get($selector, "value"));
    }

    // The chapters' ends, by start: where the next one starts, or where the video ends.
    private static function chapterEnds($store) {
        $chapters = $store->list("chapters");
        $end      = self::infoOf($store)->end;
        $ends     = array();
        foreach ($chapters as $i => $chapter) {
            $ends[Js::str($chapter->start)] = ($i + 1 < count($chapters)) ? $chapters[$i + 1]->start : $end;
        }
        return $ends;
    }

    private static function summarize($store, $kind, $item, $ends = null) {
        $context = array("userId" => self::userIdOf($store));
        if ($kind === "chapters") {
            $ends = ($ends !== null) ? $ends : self::chapterEnds($store);
            $key  = Js::str($item->start);
            $context["chapterEnd"] = array_key_exists($key, $ends) ? $ends[$key] : Js::undef();
        }
        return Items::summary($kind, $item, $context);
    }

    private static function contentViewSummary($view) {
        $summary = Js::obj(array("type" => Js::str(Js::isObject($view) ? Js::get($view, "type") : "")));
        $name    = Js::get($view, "name");
        if (Js::isObject($view) && is_string($name) && $name !== "") { $summary->name = $name; }
        return $summary;
    }

    /**
     * The hypervideo in segments — its chapters (and the time before the
     * first one), or equal segments — with the overlays and annotations that
     * start in each.
     */
    private static function timeMap($range, $chapters, $overlays, $annotations) {

        $segments = array();

        if (count($chapters)) {

            if ($chapters[0]->start > $range->start) {
                $segments[] = Js::obj(array("start" => $range->start, "end" => $chapters[0]->start));
            }
            foreach ($chapters as $i => $chapter) {
                $title = Js::get($chapter, "title");
                $segments[] = Js::obj(array(
                    "start"   => $chapter->start,
                    "end"     => ($i + 1 < count($chapters)) ? $chapters[$i + 1]->start : $range->end,
                    "chapter" => is_string($title) ? $title : ""
                ));
            }

        } else {

            $end = $range->end;

            if ($end === null) {
                foreach (array_merge($overlays, $annotations) as $item) {
                    $span = self::spanOf("overlays", $item);
                    $end  = ($end === null) ? $span->end : max($end, $span->end);
                }
            }

            if ($end === null || $end <= $range->start) { return array(); }

            $length = $end - $range->start;
            $step   = null;
            foreach (self::SEGMENT_LENGTHS as $s) {
                if ($length / $s <= 12) { $step = $s; break; }
            }
            if ($step === null) { $step = ceil($length / 12 / 3600) * 3600; }

            for ($k = 0; $range->start + $k * $step < $end; $k++) {
                $segments[] = Js::obj(array(
                    "start" => Util::seconds($range->start + $k * $step),
                    "end"   => Util::seconds(min($range->start + ($k + 1) * $step, $end))
                ));
            }

        }

        foreach ($segments as $segment) {
            $segment->overlays    = 0;
            $segment->annotations = 0;
        }

        if (count($segments)) {
            foreach (array("overlays" => $overlays, "annotations" => $annotations) as $key => $list) {
                foreach ($list as $item) {
                    $start = self::spanOf($key, $item)->start;
                    $at    = 0;
                    foreach ($segments as $i => $segment) {
                        if ($segment->start <= $start) { $at = $i; }
                    }
                    $segments[$at]->{$key} += 1;
                }
            }
        }

        return $segments;

    }

    private static function creatorSummary($meta) {
        $creator   = Js::get($meta, "creator");
        $creatorId = Js::get($meta, "creatorId");
        if (Js::isUndef($creator) && Js::isUndef($creatorId)) { return Js::undef(); }
        $result = new stdClass();
        if (!Js::isUndef($creator))   { $result->nickname = Js::str($creator); }
        if (!Js::isUndef($creatorId)) { $result->id = Js::str($creatorId); }
        return $result;
    }

    private static function inspectHypervideo($store) {

        $hypervideo   = $store->getHypervideo();
        $meta         = Js::isObject(Js::get($hypervideo, "meta")) ? $hypervideo->meta : new stdClass();
        $info         = self::infoOf($store);
        $range        = Js::obj(array("start" => $info->start, "end" => $info->end, "duration" => $info->duration));
        $overlays     = $store->list("overlays");
        $annotations  = $store->list("annotations");
        $chapters     = $store->list("chapters");
        $user         = $store->getUser();
        $userId       = $user ? Js::str($user->id) : "";
        $overlayTypes = new stdClass();
        $layout       = new stdClass();

        foreach ($overlays as $overlay) {
            $body = Js::get($overlay, "body");
            $type = Js::str(Js::either(Js::isObject($body) ? Js::get($body, "frametrail:type") : Js::undef(), ""));
            $overlayTypes->{$type} = Js::either(Js::get($overlayTypes, $type), 0) + 1;
        }

        foreach (self::AREAS as $area) {
            $layout->{$area} = array_map(array(__CLASS__, "contentViewSummary"), $store->list("contentViews", Js::obj(array("area" => $area))));
        }

        $own = 0;
        foreach ($annotations as $annotation) {
            $creator = Js::get($annotation, "creator");
            if (Js::isObject($creator) && Js::str(Js::get($creator, "id")) === $userId) { $own++; }
        }

        $name   = Js::get($meta, "name");
        $result = Js::obj(array(
            "id"           => Js::str($info->id),
            "name"         => is_string($name) ? $name : "",
            "video"        => $info->video,
            "duration"     => $range->duration,
            "timeRange"    => Js::obj(array("start" => $range->start, "end" => $range->end)),
            "subtitles"    => self::subtitleLanguages($store),
            "layout"       => $layout,
            "counts"       => Js::obj(array(
                "overlays"       => count($overlays),
                "annotations"    => count($annotations),
                "ownAnnotations" => $own,
                "chapters"       => count($chapters),
                "codeSnippets"   => count($store->list("codeSnippets"))
            )),
            "overlayTypes" => $overlayTypes,
            "timeMap"      => self::timeMap($range, $chapters, $overlays, $annotations),
            "user"         => $user,
            "canEdit"      => $store->permission("overlays")->allowed === true,
            "canAnnotate"  => $store->permission("annotations")->allowed === true
        ));

        $description = Js::get($meta, "description");
        if (is_string($description)) { $result->description = $description; }
        $creator = self::creatorSummary($meta);
        if (!Js::isUndef($creator)) { $result->creator = $creator; }

        return $result;

    }

    // The installation's hypervideos in short form.
    private static function listHypervideos($store) {

        $entries = $store->listHypervideos();
        $open    = null;
        foreach ($entries as $entry) {
            if (Js::get($entry, "open") === true) { $open = self::infoOf($store); break; }
        }

        return array_map(function($entry) use ($open) {

            $meta      = Js::isObject(Js::get($entry, "meta")) ? $entry->meta : new stdClass();
            $name      = Js::get($meta, "name");
            $subtitles = array();
            foreach ((is_array(Js::get($entry, "subtitles")) ? $entry->subtitles : array()) as $file) {
                if (Js::isObject($file)) { $subtitles[] = Js::str(Js::get($file, "srclang")); }
            }

            $result = Js::obj(array(
                "id"        => Js::str($entry->id),
                "name"      => is_string($name) ? $name : "",
                "duration"  => (Js::truthy(Js::get($entry, "open")) && $open) ? $open->duration : Util::clipSpan(Js::get($entry, "clips"))->duration,
                "subtitles" => $subtitles,
                "open"      => Js::get($entry, "open") === true
            ));

            $description = Js::get($meta, "description");
            if (is_string($description)) { $result->description = $description; }
            $creator = self::creatorSummary($meta);
            if (!Js::isUndef($creator)) { $result->creator = $creator; }

            return $result;

        }, $entries);

    }

    private static function listItems($store, $input) {

        $kinds  = Js::has($input, "kind") ? array($input->kind) : self::ITEM_KINDS;
        $filter = new stdClass();
        $ends   = self::chapterEnds($store);
        $found  = array();

        foreach (array("from", "to", "type", "creator") as $key) {
            if (Js::has($input, $key)) { $filter->{$key} = $input->{$key}; }
        }

        foreach ($kinds as $k => $kind) {
            foreach ($store->list($kind, $filter) as $i => $item) {
                $found[] = array("summary" => self::summarize($store, $kind, $item, $ends), "order" => array($k, $i));
            }
        }

        // By start; on a tie by kind (overlays, annotations, chapters, code snippets), then as stored.
        $found = Js::sort($found, function($a, $b) {
            $d = $a["summary"]->start - $b["summary"]->start;
            if (Js::truthy($d)) { return $d; }
            return ($a["order"][0] - $b["order"][0]) ?: ($a["order"][1] - $b["order"][1]);
        });

        $limit = Js::has($input, "limit") ? $input->limit : self::LIMITS["list_items"];

        return Js::obj(array(
            "items" => array_map(function($entry) { return $entry["summary"]; }, array_slice($found, 0, $limit)),
            "total" => count($found)
        ));

    }

    private static function transcript($store, $input) {

        $languages = self::subtitleLanguages($store);
        $lang      = Js::has($input, "lang") ? $input->lang : (count($languages) ? $languages[0] : Js::undef());
        $entry     = $store->get("subtitles", $lang);

        if (!$entry || !is_string(Js::get($entry, "vtt"))) {
            throw new FtConversationalUiOpError("notFound", "The subtitles in " . Js::stringify($lang) . " could not be read");
        }

        return array("lang" => $lang, "languages" => $languages, "cues" => Util::cues($entry->vtt));

    }

    private static function readTranscript($store, $input) {

        $text  = self::transcript($store, $input);
        $from  = Js::has($input, "from") ? $input->from : -INF;
        $to    = Js::has($input, "to") ? $input->to : INF;
        $cues  = Js::filter($text["cues"], function($cue) use ($from, $to) { return $cue->end > $from && $cue->start < $to; });
        $limit = Js::has($input, "limit") ? $input->limit : self::LIMITS["read_transcript"];

        $result = Js::obj(array("lang" => $text["lang"], "languages" => $text["languages"], "cues" => array_slice($cues, 0, $limit), "total" => count($cues)));

        if (count($cues) > $limit) { $result->next = $cues[$limit]->start; }

        return $result;

    }

    private static function findInTranscript($store, $input) {

        $text  = self::transcript($store, $input);
        $words = Js::filter(preg_split('/[' . Js::SPACE . ']+/u', Js::lower($input->query)), function($word) { return $word !== ""; });

        $matches = Js::filter($text["cues"], function($cue) use ($words) {
            $lower = Js::lower($cue->text);
            foreach ($words as $word) {
                if (strpos($lower, $word) === false) { return false; }
            }
            return true;
        });

        $limit = Js::has($input, "limit") ? $input->limit : self::LIMITS["find_in_transcript"];

        return Js::obj(array("lang" => $text["lang"], "matches" => array_slice($matches, 0, $limit), "total" => count($matches)));

    }


    /* ------------------------------------------------------------------ */
    /*  Writing                                                           */
    /* ------------------------------------------------------------------ */

    private static function addItem($store, $kind, $item) {
        $stored = self::call(function() use ($store, $kind, $item) { return $store->add($kind, $item); }, array(__CLASS__, "itemPath"));
        return array(
            "result"  => self::summarize($store, $kind, $stored),
            "inverse" => Js::obj(array("method" => "remove", "args" => array($kind, $stored->created)))
        );
    }

    private static function updateItem($store, $kind, $input, $patch) {

        $current = $store->get($kind, $input->ref);

        // A change to what it is already changes nothing, and records no generator either.
        if (Js::isUndef($patch)) {
            return array("result" => self::summarize($store, $kind, $current), "inverse" => null);
        }
        $withoutGenerator = clone $patch;
        unset($withoutGenerator->generator);
        if (Js::same(Util::mergePatch($current, $withoutGenerator), $current)) {
            return array("result" => self::summarize($store, $kind, $current), "inverse" => null);
        }

        $stored = self::call(function() use ($store, $kind, $input, $patch) { return $store->update($kind, $input->ref, $patch); }, array(__CLASS__, "itemPath"));
        $undo   = Util::diffPatch($stored, $current);

        return array(
            "result"  => self::summarize($store, $kind, $stored),
            "inverse" => Js::isUndef($undo) ? null : Js::obj(array("method" => "update", "args" => array($kind, $stored->created, $undo)))
        );

    }

    private static function removeItem($store, $kind, $input) {
        $removed = self::call(function() use ($store, $kind, $input) { return $store->remove($kind, $input->ref); }, array(__CLASS__, "itemPath"));
        return array(
            "result"  => self::summarize($store, $kind, $removed),
            "inverse" => Js::obj(array("method" => "add", "args" => array($kind, $removed)))
        );
    }

    private static function chapterPath($path) {
        return $path;
    }

    private static function opListHypervideos($store, $input, $context) {
        return array("result" => Js::obj(array("hypervideos" => self::listHypervideos($store))));
    }

    private static function opInspectHypervideo($store, $input, $context) {
        return array("result" => self::inspectHypervideo($store));
    }

    private static function opListItems($store, $input, $context) {
        return array("result" => self::listItems($store, $input));
    }

    private static function opGetItem($store, $input, $context) {
        return array("result" => Js::obj(array("item" => $store->get($input->kind, self::itemRef($input)))));
    }

    private static function opReadTranscript($store, $input, $context) {
        return array("result" => self::readTranscript($store, $input));
    }

    private static function opFindInTranscript($store, $input, $context) {
        return array("result" => self::findInTranscript($store, $input));
    }

    private static function opAddOverlay($store, $input, $context) {
        return self::addItem($store, "overlays", Items::newOverlay($input, self::generatorOf($context)));
    }

    private static function opUpdateOverlay($store, $input, $context) {
        return self::updateItem($store, "overlays", $input, Items::overlayPatch($store->get("overlays", $input->ref), $input, self::generatorOf($context)));
    }

    private static function opRemoveOverlay($store, $input, $context) {
        return self::removeItem($store, "overlays", $input);
    }

    private static function opAddAnnotation($store, $input, $context) {
        return self::addItem($store, "annotations", Items::newAnnotation($input, self::generatorOf($context)));
    }

    private static function opUpdateAnnotation($store, $input, $context) {
        return self::updateItem($store, "annotations", $input, Items::annotationPatch($store->get("annotations", $input->ref), $input, self::generatorOf($context)));
    }

    private static function opRemoveAnnotation($store, $input, $context) {
        return self::removeItem($store, "annotations", $input);
    }

    private static function opAddChapter($store, $input, $context) {
        $added = self::call(function() use ($store, $input) {
            return $store->add("chapters", Js::obj(array("start" => $input->start, "title" => $input->title)));
        }, array(__CLASS__, "chapterPath"));
        return array(
            "result"  => self::summarize($store, "chapters", $added),
            "inverse" => Js::obj(array("method" => "remove", "args" => array("chapters", $added->start)))
        );
    }

    private static function opUpdateChapter($store, $input, $context) {

        $before = $store->get("chapters", $input->ref);
        $patch  = new stdClass();

        if (Js::has($input, "start")) { $patch->start = $input->start; }
        if (Js::has($input, "title")) { $patch->title = $input->title; }

        $after = count(Js::ownKeys($patch))
            ? self::call(function() use ($store, $input, $patch) { return $store->update("chapters", $input->ref, $patch); }, array(__CLASS__, "chapterPath"))
            : $before;
        $undo  = Util::diffPatch($after, $before);

        return array(
            "result"  => self::summarize($store, "chapters", $after),
            "inverse" => Js::isUndef($undo) ? null : Js::obj(array("method" => "update", "args" => array("chapters", $after->start, $undo)))
        );

    }

    private static function opRemoveChapter($store, $input, $context) {
        $ends    = self::chapterEnds($store);
        $removed = self::call(function() use ($store, $input) { return $store->remove("chapters", $input->ref); }, array(__CLASS__, "chapterPath"));
        return array(
            "result"  => self::summarize($store, "chapters", $removed, $ends),
            "inverse" => Js::obj(array("method" => "add", "args" => array("chapters", $removed)))
        );
    }

    private static function opSetSubtitles($store, $input, $context) {

        $previous = $store->get("subtitles", $input->lang);
        $before   = ($previous && is_string(Js::get($previous, "vtt"))) ? $previous->vtt : null;
        $entry    = self::call(function() use ($store, $input) { return $store->setSubtitles($input->lang, $input->vtt); }, function() { return "/vtt"; });

        return array(
            "result"  => Js::obj(array(
                "lang" => $input->lang,
                "src"  => $entry ? $entry->src : null,
                "cues" => is_string($input->vtt) ? count(Util::cues($input->vtt)) : 0
            )),
            "inverse" => ($before === $input->vtt) ? null : Js::obj(array("method" => "setSubtitles", "args" => array($input->lang, $before)))
        );

    }

    private static function opSetLayoutArea($store, $input, $context) {

        $previous = $store->list("contentViews", Js::obj(array("area" => $input->area)));
        $views    = self::call(function() use ($store, $input) { return $store->setLayout($input->area, $input->contentViews); }, function($path) { return "/contentViews" . $path; });

        return array(
            "result"  => Js::obj(array("area" => $input->area, "contentViews" => $views)),
            "inverse" => Js::same($previous, $views) ? null : Js::obj(array("method" => "setLayout", "args" => array($input->area, $previous)))
        );

    }

    private static function generatorOf($context) {
        return (is_array($context) && array_key_exists("generator", $context)) ? $context["generator"] : Js::undef();
    }


    /* ------------------------------------------------------------------ */
    /*  Running                                                           */
    /* ------------------------------------------------------------------ */

    private static function execute($store, $op, $input, $context) {

        $given  = Js::isUndef($input) ? new stdClass() : $input;
        $errors = self::validateInput($op->name, $given);

        if (count($errors)) {
            throw new FtConversationalUiOpError("invalid", "Invalid input", $errors);
        }

        if (Js::has($given, "start") && Js::has($given, "end") && $given->end < $given->start) {
            throw new FtConversationalUiOpError("invalid", "Invalid input", array(self::error("/end", "must not be before start")));
        }

        foreach ($op->preconditions as $name) {
            self::precondition($name, $store, $op, $given);
        }

        return call_user_func(array(__CLASS__, self::IMPLEMENTATIONS[$op->name]), $store, Js::copy($given), is_array($context) ? $context : array());

    }

    /**
     * I run one operation and return its result. A write runs as a
     * transaction of its own.
     *
     * @method run
     * @param {FtConversationalUiStore} $store
     * @param {String} $name
     * @param {Mixed} $input Js::undef() for none
     * @param {Array} $context array("generator" => …)
     * @return Mixed
     */
    public static function run($store, $name, $input, $context = array()) {

        $op = self::requireOperation($name);

        if ($op->effect === "read") {
            return self::execute($store, $op, $input, $context)["result"];
        }

        return $store->transaction($op->name, function($tx) use ($op, $input, $context) {
            return self::execute($tx, $op, $input, $context)["result"];
        });

    }

    // The file a write operation changes: the user's annotation file, or hypervideo.json.
    public static function partOf($op) {
        return ($op->kind === "annotations") ? "annotations" : "hypervideo";
    }

    private static function changesetId() {
        return "cs-" . base_convert((string)(int)round(microtime(true) * 1000), 10, 36) . "-" . substr(base_convert(bin2hex(random_bytes(5)), 16, 36), 0, 6);
    }

    /**
     * I record a changeset: run(name, input) runs an operation against the
     * store (reads too), changeset() returns the writes so far with their
     * inverses. An operation that fails changes nothing and leaves the others
     * in place.
     *
     * @method record
     * @param {FtConversationalUiStore} $store
     * @param {Array} $options id, summary, generator
     * @return FtConversationalUiRecorder
     */
    public static function record($store, $options = array()) {
        return new FtConversationalUiRecorder($store, is_array($options) ? $options : array());
    }

    /**
     * For FtConversationalUiRecorder: an operation run against a store,
     * array(operation, array("result" => …, "inverse" => …)).
     *
     * @method executeOperation
     */
    public static function executeOperation($store, $name, $input, $context) {
        $op = self::requireOperation($name);
        return array($op, self::execute($store, $op, $input, $context));
    }

    /**
     * For FtConversationalUiRecorder: what a changeset records of the store
     * before its first operation, array(info, versions, user id).
     *
     * @method storeBefore
     */
    public static function storeBefore($store) {
        return array(self::infoOf($store), $store->versions(), self::userIdOf($store));
    }

    /**
     * For FtConversationalUiRecorder: an id for a changeset given none.
     *
     * @method newChangesetId
     */
    public static function newChangesetId() {
        return self::changesetId();
    }

    private static function prefixed($e, $index, $name) {

        if (!($e instanceof FtConversationalUiOpError)) { throw $e; }

        $prefix = "/ops/" . $index . "/input";
        $errors = array_map(function($error) use ($prefix) { return self::error($prefix . $error->path, $error->message); }, $e->errors);
        $parts  = explode(": ", $e->getMessage());
        $error  = new FtConversationalUiOpError($e->errorCode, "Operation " . $index . " (" . $name . "): " . $parts[0], $errors);

        if (!count($errors)) { $error = new FtConversationalUiOpError($e->errorCode, "Operation " . $index . " (" . $name . "): " . $e->getMessage()); }

        return $error;

    }

    /**
     * I apply a changeset to a store as one transaction: all its operations,
     * or none. I return { changeset, results }: the changeset as applied and
     * each operation's result.
     *
     * @method apply
     * @param {FtConversationalUiStore} $store
     * @param {stdClass} $changeset
     * @param {Array} $context array("generator" => …), used when the changeset names none
     * @return stdClass
     */
    public static function apply($store, $changeset, $context = array()) {

        $errors = self::validator()->validate(self::changesetSchema()->{'$id'}, $changeset);

        if (count($errors)) {
            throw new FtConversationalUiOpError("invalid", "Invalid changeset", $errors);
        }

        foreach ($changeset->ops as $i => $entry) {
            $op = self::operation($entry->op);
            if (!$op) {
                $errors[] = self::error("/ops/" . $i . "/op", "is not an operation");
            } else if ($op->effect !== "write") {
                $errors[] = self::error("/ops/" . $i . "/op", "only changes go into a changeset; " . $entry->op . " reads");
            }
        }

        $openId = Js::str(self::infoOf($store)->id);

        if (Js::has($changeset, "hypervideoId") && Js::str($changeset->hypervideoId) !== $openId) {
            $errors[] = self::error("/hypervideoId", "must be " . Js::stringify($openId) . ", the hypervideo it is applied to");
        }

        if (count($errors)) {
            throw new FtConversationalUiOpError("invalid", "Invalid changeset", $errors);
        }

        if (Js::isObject(Js::get($changeset, "baseVersion"))) {

            $now       = $store->versions();
            $conflicts = array();

            foreach (Js::keys($changeset->baseVersion) as $part) {
                $current = Js::get($now, $part);
                if (!Js::isUndef($current) && !Js::strictEq($changeset->baseVersion->{$part}, $current)) {
                    $conflicts[] = self::error("/baseVersion/" . $part, "is " . Js::str($changeset->baseVersion->{$part}) . ", but the "
                        . (($part === "annotations") ? "annotation file" : "hypervideo") . " is at " . Js::str($current));
                }
            }

            if (count($conflicts)) {
                throw new FtConversationalUiOpError("conflict", "Changed since the changeset was made", $conflicts);
            }

        }

        $generator = Js::has($changeset, "generator") ? $changeset->generator : self::generatorOf($context);
        $summary   = Js::truthy(Js::get($changeset, "summary"))
            ? $changeset->summary
            : implode(", ", array_map(function($entry) { return $entry->op; }, $changeset->ops));

        return $store->transaction($summary, function($tx) use ($changeset, $summary, $generator) {

            $recorder = self::record($tx, array("id" => Js::get($changeset, "id"), "summary" => $summary, "generator" => $generator));
            $results  = array();

            foreach ($changeset->ops as $i => $entry) {
                try {
                    $results[] = $recorder->run($entry->op, Js::get($entry, "input"));
                } catch (Exception $e) {
                    throw self::prefixed($e, $i, $entry->op);
                }
            }

            return Js::obj(array("changeset" => $recorder->changeset(), "results" => $results));

        });

    }

    /**
     * I take an applied changeset back: its inverses, last first, as one
     * transaction.
     *
     * @method undo
     * @param {FtConversationalUiStore} $store
     * @param {stdClass} $changeset as apply() returned it
     */
    public static function undo($store, $changeset) {

        $ops      = (Js::isObject($changeset) && is_array(Js::get($changeset, "ops"))) ? $changeset->ops : array();
        $inverses = array();

        foreach ($ops as $i => $entry) {
            $inverse = Js::isObject($entry) ? Js::get($entry, "inverse") : null;
            if ($inverse === null || Js::isUndef($inverse)) { $inverses[] = null; continue; }
            if (!Js::isObject($inverse) || !in_array(Js::get($inverse, "method"), self::CALLS, true) || !is_array(Js::get($inverse, "args"))) {
                throw new FtConversationalUiOpError("invalid", "Invalid changeset", array(self::error("/ops/" . $i . "/inverse", "is not a call of " . implode(", ", self::CALLS))));
            }
            $inverses[] = $inverse;
        }

        $openId = Js::str(self::infoOf($store)->id);

        if (Js::has($changeset, "hypervideoId") && Js::str($changeset->hypervideoId) !== $openId) {
            throw new FtConversationalUiOpError("invalid", "Invalid changeset", array(self::error("/hypervideoId", "must be " . Js::stringify($openId) . ", the hypervideo it is undone in")));
        }

        return $store->transaction("Undo: " . Js::str(Js::either(Js::get($changeset, "summary"), "")), function($tx) use ($inverses) {
            for ($i = count($inverses) - 1; $i >= 0; $i--) {
                if (!$inverses[$i]) { continue; }
                $inverse = Js::copy($inverses[$i]);
                self::call(function() use ($tx, $inverse) { return call_user_func_array(array($tx, $inverse->method), $inverse->args); });
            }
        });

    }

}


/**
 * A changeset being recorded (FtConversationalUiOps::record()).
 */
class FtConversationalUiRecorder {

    private $store;
    private $options;
    private $id;
    private $info;
    private $before;
    private $userId;
    private $touched = array();
    private $applied = array();

    public function __construct($store, $options) {
        $this->store   = $store;
        $this->options = $options;
        $this->id      = (isset($options["id"]) && is_string($options["id"]) && $options["id"] !== "") ? $options["id"] : FtConversationalUiOps::newChangesetId();
        list($this->info, $this->before, $this->userId) = FtConversationalUiOps::storeBefore($store);
    }

    private function generator() {
        return array_key_exists("generator", $this->options) ? $this->options["generator"] : Js::undef();
    }

    /**
     * I run an operation against the store (reads too) and return its result.
     *
     * @method run
     * @param {String} $name
     * @param {Mixed} $input
     * @return Mixed
     */
    public function run($name, $input) {

        list($op, $out) = FtConversationalUiOps::executeOperation($this->store, $name, $input, array("generator" => $this->generator()));

        if ($op->effect === "write") {
            $this->applied[] = Js::obj(array(
                "op"      => $op->name,
                "input"   => Js::copy(Js::isUndef($input) ? new stdClass() : $input),
                "inverse" => (array_key_exists("inverse", $out) && !Js::isUndef($out["inverse"])) ? Js::copy($out["inverse"]) : null
            ));
            $this->touched[FtConversationalUiOps::partOf($op)] = true;
        }

        return $out["result"];

    }

    /**
     * The changeset of the writes so far.
     *
     * @method changeset
     * @return stdClass
     */
    public function changeset() {

        $summary = (isset($this->options["summary"]) && Js::truthy($this->options["summary"]))
            ? $this->options["summary"]
            : implode(", ", array_map(function($entry) { return $entry->op; }, $this->applied));

        $changeset = Js::obj(array(
            "id"           => $this->id,
            "hypervideoId" => Js::str($this->info->id),
            "baseVersion"  => new stdClass(),
            "createdBy"    => $this->userId,
            "summary"      => $summary,
            "ops"          => Js::copy($this->applied)
        ));

        foreach (array_keys($this->touched) as $part) {
            $version = Js::get($this->before, $part);
            if (!Js::isUndef($version)) { $changeset->baseVersion->{$part} = $version; }
        }

        if (!Js::isUndef($this->generator())) { $changeset->generator = Js::copy($this->generator()); }

        return $changeset;

    }

}
