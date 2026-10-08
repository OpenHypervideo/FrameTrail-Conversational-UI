<?php

/*
 * FrameTrail-Conversational-UI — the model store, the PHP side of
 * client/ops/model-store.js: what FrameTrail's edit API does to the open
 * hypervideo, done headless to a bundle, through the ports of FrameTrail's
 * serializer and validator. Same interface, rules, errors and results as the
 * JavaScript store; the conformance fixtures (shared/fixtures/) hold both to
 * them.
 *
 *     $store = new FtConversationalUiModelStore($bundle, array("user" => array("id" => "1", "name" => "Ada", "role" => "admin")));
 *     $store->add("chapters", Js::obj(array("start" => 60, "title" => "Part two")));
 *     $store->data();   // the bundle as a save would write it
 *
 * Options: user (id, name, role), now (the clock for new items' created:
 * milliseconds or a callable), duration (the video's length in seconds, for a
 * clip that does not say), hypervideoId (in a project bundle; default: the
 * first).
 */

if (!function_exists("ftExtensionStorage") && !defined("FT_CONVERSATIONAL_UI_LIB")) {
    http_response_code(404);
    exit;
}

use FtConversationalUiJs as Js;
use FtConversationalUiOpsUtil as Util;
use FtConversationalUiSerializer as Serializer;


class FtConversationalUiModelStore implements FtConversationalUiStore {

    const MEDIA_FRAGMENTS = "http://www.w3.org/TR/media-frags/";

    // The layout areas by the edit API's names and their keys in config.layoutArea.
    const AREAS     = array("top" => "top", "bottom" => "bottom", "left" => "left", "right" => "right",
                            "areaTop" => "top", "areaBottom" => "bottom", "areaLeft" => "left", "areaRight" => "right");
    const AREA_KEYS = array("top" => "areaTop", "bottom" => "areaBottom", "left" => "areaLeft", "right" => "areaRight");

    // Body types the schemas still accept (legacy) that FrameTrail has no renderer for.
    const UNSHOWABLE_TYPES = array("button");

    const CHAPTER_SCHEMA = "hypervideo.schema.json#/properties/chapters/items";

    // The kinds of things, and whether changing them needs the hypervideo's creator or an admin.
    const KINDS = array("overlays" => true, "codeSnippets" => true, "annotations" => false, "chapters" => true,
                        "contentViews" => true, "subtitles" => true, "config" => true);

    // The three kinds of W3C items, as the edit API has them.
    const ITEM_KINDS = array(
        "overlays"     => array("name" => "overlay", "itemType" => "Overlay", "schema" => "content-item.schema.json#/\$defs/overlay", "timeSpan" => true),
        "codeSnippets" => array("name" => "code snippet", "itemType" => "CodeSnippet", "schema" => "content-item.schema.json#/\$defs/codeSnippet", "timeSpan" => false),
        "annotations"  => array("name" => "annotation", "itemType" => "Annotation", "schema" => "annotation-file.schema.json#/\$defs/annotation", "timeSpan" => true)
    );

    private $options;
    private $project;
    private $id;
    private $bundle;
    private $user;
    private $clock;
    private $files;
    private $index;

    // The working state. Writes replace the objects in it rather than change them, so a transaction can keep shallow copies.
    private $model;
    private $annotations = array();
    private $subtitles;
    private $dirty = array("hypervideo" => false, "annotations" => false);
    private $transacting = false;


    /**
     * @param {stdClass} $data a hypervideo or project bundle
     * @param {Array} $options user, now, duration, hypervideoId
     */
    public function __construct($data, $options = array()) {

        $this->options = is_array($options) ? $options : array();

        $source        = Js::copy($data);
        $this->project = (Js::isObject($source) && Js::get($source, "bundle") === "project") ? $source : null;

        $hypervideoId = $this->option("hypervideoId");

        if ($this->project) {
            $hypervideos = Js::either(Js::get($this->project, "hypervideos"), new stdClass());
            $keys        = Js::keys($hypervideos);
            $this->id    = Js::str(!Js::isUndef($hypervideoId) ? $hypervideoId : (count($keys) ? $keys[0] : Js::undef()));
            $this->bundle = Js::get($hypervideos, $this->id);
        } else {
            $sourceId     = Js::get($source, "id");
            $this->id     = Js::str(Js::isObject($source) && !Js::isUndef($sourceId) ? $sourceId : (!Js::isUndef($hypervideoId) ? $hypervideoId : ""));
            $this->bundle = $source;
        }

        if (!Js::isObject($this->bundle) || !Js::isObject(Js::get($this->bundle, "hypervideo"))) {
            throw new Exception("No hypervideo " . Js::stringify($this->id) . " in this bundle");
        }

        $given = $this->option("user");
        $id    = Js::isUndef($given) ? Js::undef() : $this->field($given, "id");
        $name  = Js::isUndef($given) ? Js::undef() : $this->field($given, "name");

        $this->user = array(
            "id"   => Js::str(!Js::isUndef($id) ? $id : ""),
            "name" => Js::str(!Js::isUndef($name) ? $name : ""),
            "role" => (!Js::isUndef($given) && $this->field($given, "role") === "admin") ? "admin" : "user"
        );

        $now = $this->option("now");
        if ($now instanceof Closure) {
            $this->clock = $now;
        } else if (Js::isNumber($now)) {
            $this->clock = function() use ($now) { return $now; };
        } else {
            $this->clock = function() { return (int)round(microtime(true) * 1000); };
        }

        $annotations = Js::get($this->bundle, "annotations");
        $this->files = (Js::isObject($annotations) && Js::isObject(Js::get($annotations, "files"))) ? $annotations->files : new stdClass();
        $this->index = Serializer::parseAnnotationIndex(Js::isObject($annotations) ? Js::get($annotations, "index") : Js::undef());

        $this->model     = Serializer::parseHypervideo($this->bundle->hypervideo);
        $this->subtitles = Js::isObject(Js::get($this->bundle, "subtitles")) ? Js::copy($this->bundle->subtitles) : new stdClass();

        foreach (Js::keys($this->files) as $fileId) {
            foreach (Serializer::parseAnnotationFile($this->files->{$fileId}, self::annotationSource()) as $annotation) {
                $this->annotations[] = $annotation;
            }
        }
        Serializer::dedupeCreated($this->annotations, function($annotation) { return Js::get($annotation, "creatorId"); });

        if (!is_array(Js::get($this->model, "chapters"))) { $this->model->chapters = array(); }

    }

    // Where a new annotation comes from, as the editor records it.
    private static function annotationSource() {
        return Js::obj(array("frametrail" => true, "url" => "_data/hypervideos/"));
    }

    private function option($key) {
        return array_key_exists($key, $this->options) ? $this->options[$key] : Js::undef();
    }

    private function field($value, $key) {
        if (is_array($value)) { return array_key_exists($key, $value) ? $value[$key] : Js::undef(); }
        return Js::get($value, $key);
    }

    private static function validate($schema, $data) {
        return FtConversationalUiShared::validator()->validate($schema, $data);
    }

    private static function editError($code, $message, $errors = array()) {
        return new FtConversationalUiEditError($code, $message, $errors);
    }

    private static function error($path, $message) {
        return Js::obj(array("path" => $path, "message" => $message));
    }

    // created as ISO text or milliseconds → milliseconds (NaN for anything else).
    private static function toMillis($created) {
        if (Js::isNumber($created)) { return $created; }
        if (is_string($created) && $created !== "") { return Js::dateParse($created); }
        return NAN;
    }

    private function markDirty($kind) {
        $this->dirty[($kind === "annotations") ? "annotations" : "hypervideo"] = true;
    }


    /* ------------------------------------------------------------------ */
    /*  The items                                                         */
    /* ------------------------------------------------------------------ */

    private function itemList($kind) {
        return ($kind === "annotations") ? $this->annotations : $this->model->{$kind};
    }

    private function setItemList($kind, $list) {
        if ($kind === "annotations") {
            $this->annotations = array_values($list);
        } else {
            $this->model->{$kind} = array_values($list);
        }
    }

    private function parseItem($kind, $item) {
        if ($kind === "overlays")     { return Serializer::parseOverlay($item); }
        if ($kind === "codeSnippets") { return Serializer::parseCodeSnippet($item); }
        return Serializer::parseAnnotation($item, self::annotationSource());
    }

    private function serializeItem($kind, $data) {
        if ($kind === "overlays")     { return Serializer::serializeOverlay($data, $this->itemContext()); }
        if ($kind === "codeSnippets") { return Serializer::serializeCodeSnippet($data, $this->itemContext()); }
        return Serializer::serializeAnnotation($data, $this->itemContext());
    }


    /* ------------------------------------------------------------------ */
    /*  The hypervideo                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * The resources the bundle carries, by id; null when it carries none
     * (then nothing can be said about a reference).
     *
     * @method resources
     * @return stdClass|null
     */
    public function resources() {
        $resources = $this->resourceMap();
        return ($resources === null) ? null : Js::copy($resources);
    }

    private function resourceMap() {
        if ($this->project) {
            $resources = Js::get($this->project, "resources");
            return (Js::isObject($resources) && Js::isObject(Js::get($resources, "resources"))) ? $resources->resources : null;
        }
        $resources = Js::get($this->bundle, "resources");
        return Js::isObject($resources) ? $resources : null;
    }

    // The video every item targets, by FrameTrail's rule (Database.sourcePathOf); undefined for a missing resource.
    private function sourcePath() {

        $clips = Js::get($this->model, "clips");
        $clip  = (is_array($clips) && count($clips) && Js::isObject($clips[0])) ? $clips[0] : new stdClass();
        $src   = Js::get($clip, "src");

        if (Js::truthy($src) && Js::isNumber(self::length($src)) && self::length($src) > 3) { return $src; }
        if (!Js::truthy(Js::get($clip, "resourceId"))) { return ""; }

        $resource = Js::get(Js::either($this->resourceMap(), new stdClass()), Js::str($clip->resourceId));
        return Js::truthy($resource) ? Js::get($resource, "src") : Js::undef();

    }

    // value.length: of a string in UTF-16 code units, of a list its count.
    private static function length($value) {
        if (is_string($value)) { return Js::length($value); }
        if (is_array($value))  { return count($value); }
        return Js::get($value, "length");
    }

    private function itemContext() {
        return array("sourcePath" => $this->sourcePath());
    }

    private function creatorId() {
        $creatorId = Js::get(Js::get($this->model, "meta"), "creatorId");
        return !Js::isUndef($creatorId) ? Js::str($creatorId) : "";
    }

    // Why the user may not change a kind of thing, or null when they may.
    private function refusalOf($kind) {
        if ($this->user["id"] === "") {
            return self::editError("notAllowed", "Changes can only be made by a signed-in user");
        }
        if (!empty(self::KINDS[$kind]) && $this->user["role"] !== "admin" && $this->creatorId() !== $this->user["id"]) {
            return self::editError("notAllowed", "Only an admin or the creator of this hypervideo can change its " . $kind);
        }
        return null;
    }

    /**
     * Whether the user may change a kind of thing: { allowed: true }, or
     * { allowed: false, code, message } with what a write would throw.
     *
     * @method permission
     * @param {String} $kind
     * @return stdClass
     */
    public function permission($kind) {

        if (!is_string($kind) || !array_key_exists($kind, self::KINDS)) {
            throw self::editError("invalid", "Unknown kind \"" . Js::str($kind) . "\"; one of " . implode(", ", array_keys(self::KINDS)));
        }

        $refusal = $this->refusalOf($kind);

        return $refusal
            ? Js::obj(array("allowed" => false, "code" => $refusal->errorCode, "message" => $refusal->getMessage()))
            : Js::obj(array("allowed" => true));

    }

    // Who changes are made as, or null when nobody is.
    public function getUser() {
        return ($this->user["id"] === "") ? null : Js::obj(array("id" => $this->user["id"], "name" => $this->user["name"], "role" => $this->user["role"], "guest" => false));
    }

    // The hypervideo's id, video and time: item times run from start (the clip's in point) to end.
    public function getInfo() {
        $span = Util::clipSpan(Js::get($this->model, "clips"), Js::orDefault($this->option("duration"), null));
        $path = $this->sourcePath();
        return Js::obj(array(
            "id"       => $this->id,
            "video"    => (is_string($path) && $path !== "") ? $path : null,
            "start"    => $span->start,
            "end"      => ($span->duration !== null) ? Util::seconds($span->start + $span->duration) : null,
            "duration" => $span->duration
        ));
    }

    public function versions() {

        $result      = new stdClass();
        $entry       = Js::get($this->index->annotationfiles, $this->user["id"]);
        $lastchanged = Js::get(Js::get($this->model, "meta"), "lastchanged");

        if (Js::isNumber($lastchanged)) { $result->hypervideo = $lastchanged; }
        $entryChanged = Js::get($entry, "lastchanged");
        $result->annotations = (Js::isObject($entry) && Js::isNumber($entryChanged)) ? $entryChanged : 0;

        return $result;

    }

    public function getHypervideo() {
        return Serializer::serializeHypervideo($this->model, $this->itemContext());
    }

    // The hypervideos of the project (or this one) as their hypervideo.json describes them.
    public function listHypervideos() {

        if ($this->project) {
            $index   = Js::get($this->project, "hypervideosIndex");
            $entries = (Js::isObject($index) && Js::isObject(Js::get($index, "hypervideos"))) ? $index->hypervideos : Js::either(Js::get($this->project, "hypervideos"), new stdClass());
            $ids     = Js::keys($entries);
        } else {
            $ids = array($this->id);
        }

        $result = array();

        foreach ($ids as $hypervideoId) {

            $hypervideos = $this->project ? Js::get($this->project, "hypervideos") : null;
            if ($hypervideoId !== $this->id && !($this->project && Js::isObject($hypervideos) && Js::isObject(Js::get($hypervideos, $hypervideoId)))) {
                continue;
            }

            $open = ($hypervideoId === $this->id);
            $json = $open ? $this->model : Js::either(Js::get($hypervideos->{$hypervideoId}, "hypervideo"), new stdClass());

            $meta      = Js::get($json, "meta");
            $clips     = Js::get($json, "clips");
            $subtitles = Js::get($json, "subtitles");

            $result[] = Js::obj(array(
                "id"        => $hypervideoId,
                "open"      => $open,
                "meta"      => Js::copy(Js::isObject($meta) ? $meta : new stdClass()),
                "clips"     => Js::copy(is_array($clips) ? $clips : array()),
                "subtitles" => Js::copy(is_array($subtitles) ? $subtitles : array())
            ));

        }

        return $result;

    }


    /* ------------------------------------------------------------------ */
    /*  Checks                                                            */
    /* ------------------------------------------------------------------ */

    private function requireEditing($kind) {
        $refusal = $this->refusalOf($kind);
        if ($refusal) { throw $refusal; }
    }

    private function itemKind($kind) {
        if (!is_string($kind) || !array_key_exists($kind, self::ITEM_KINDS)) {
            throw self::editError("invalid", "Unknown kind of item \"" . Js::str($kind) . "\"; one of " . implode(", ", array_keys(self::ITEM_KINDS)));
        }
        return self::ITEM_KINDS[$kind];
    }

    // Checks the schemas cannot express.
    private function itemErrors($kind, $item) {

        $spec   = self::ITEM_KINDS[$kind];
        $errors = array();
        $body   = Js::get($item, "body");
        $type   = Js::isObject($body) ? Js::get($body, "frametrail:type") : Js::undef();

        if ($kind !== "codeSnippets" && in_array($type, self::UNSHOWABLE_TYPES, true)) {
            $errors[] = self::error("/body/frametrail:type", "is not a type this player can show");
        }

        $target = Js::get($item, "target");
        if ($spec["timeSpan"] && Js::isObject($target) && Js::isObject(Js::get($target, "selector"))) {
            if (preg_match('/^t=([^,&]+),([^&]+)/', Js::str(Js::either(Js::get($target->selector, "value"), "")), $m)
                    && Js::parseFloat($m[2]) < Js::parseFloat($m[1])) {
                $errors[] = self::error("/target/selector/value", "must not end before it starts");
            }
        }

        return $errors;

    }


    /* ------------------------------------------------------------------ */
    /*  Reading                                                           */
    /* ------------------------------------------------------------------ */

    private function findData($kind, $millis, $creator = null) {
        foreach ($this->itemList($kind) as $data) {
            if (Js::numEq(Js::get($data, "created"), $millis)
                    && ($creator === null || Js::str(Js::get($data, "creatorId")) === Js::str($creator))) {
                return $data;
            }
        }
        return null;
    }

    // An item reference: its created; for anyone's annotation { creator, created }, a plain created finds one of the user's own.
    private function findItem($kind, $ref) {
        if ($kind === "annotations") {
            if (Js::isObject($ref)) {
                return $this->findData($kind, self::toMillis(Js::get($ref, "created")), Js::get($ref, "creator"));
            }
            return $this->findData($kind, self::toMillis($ref), $this->user["id"]);
        }
        return $this->findData($kind, self::toMillis($ref));
    }

    private function requireItem($kind, $ref) {
        $data = $this->findItem($kind, $ref);
        if (!$data) {
            throw self::editError("notFound", "No " . self::ITEM_KINDS[$kind]["name"] . " " . Js::stringify($ref));
        }
        return $data;
    }

    private function findChapter($start) {
        $value = is_string($start) ? Js::parseFloat($start) : $start;
        foreach ($this->model->chapters as $chapter) {
            if (Js::numEq(Js::get($chapter, "start"), $value)) { return $chapter; }
        }
        return null;
    }

    private function sortedChapters() {
        return Js::sort($this->model->chapters, function($a, $b) { return Js::get($a, "start") - Js::get($b, "start"); });
    }

    private function contentViewsOf($area) {
        $layout = Js::get($this->model, "layout");
        $views  = Js::isObject($layout) ? Js::get($layout, self::AREA_KEYS[$area]) : Js::undef();
        return Js::copy(is_array($views) ? $views : array());
    }

    private static function matches($filter, $info) {

        if (!Js::isObject($filter)) { return true; }

        $from    = Js::get($filter, "from");
        $to      = Js::get($filter, "to");
        $type    = Js::get($filter, "type");
        $creator = Js::get($filter, "creator");

        if (!Js::isUndef($from) && !Js::isUndef($info["end"]) && $info["end"] < $from) { return false; }
        if (!Js::isUndef($to) && !Js::isUndef($info["start"]) && $info["start"] > $to) { return false; }
        if (!Js::isUndef($type) && !Js::strictEq($info["type"], $type)) { return false; }
        if (!Js::isUndef($creator) && Js::str($info["creator"]) !== Js::str($creator)) { return false; }

        return true;

    }

    /**
     * @method list
     * @param {String} $kind overlays, codeSnippets, annotations, chapters, contentViews, subtitles
     * @param {Mixed} $filter { from, to, type, creator, area }, or a callable
     * @return Array
     */
    public function list($kind, $filter = null) {

        if (is_string($kind) && array_key_exists($kind, self::ITEM_KINDS)) {

            $result = array();
            foreach ($this->itemList($kind) as $data) {
                $start = Js::get($data, "start");
                $end   = Js::get($data, "end");
                $info  = array(
                    "start"   => $start,
                    "end"     => !Js::isUndef($end) ? $end : $start,
                    "type"    => ($kind === "codeSnippets") ? "codesnippet" : Js::get($data, "type"),
                    "creator" => Js::get($data, "creatorId")
                );
                if (self::matches($filter, $info)) { $result[] = $this->serializeItem($kind, $data); }
            }

        } else if ($kind === "chapters") {

            $result = array();
            foreach ($this->sortedChapters() as $chapter) {
                $start = Js::get($chapter, "start");
                if (self::matches($filter, array("start" => $start, "end" => $start, "type" => Js::undef(), "creator" => Js::undef()))) {
                    $result[] = Js::copy($chapter);
                }
            }

        } else if ($kind === "contentViews") {

            $area = (Js::isObject($filter) && !Js::isUndef(Js::get($filter, "area"))) ? $filter->area : Js::undef();

            if (!Js::isUndef($area) && !(is_string($area) && array_key_exists($area, self::AREAS))) {
                throw self::editError("invalid", "Unknown layout area \"" . Js::str($area) . "\"; one of top, bottom, left, right");
            }

            $result = array();
            foreach (Js::isUndef($area) ? array("top", "bottom", "left", "right") : array(self::AREAS[$area]) as $which) {
                foreach ($this->contentViewsOf($which) as $view) { $result[] = $view; }
            }

        } else if ($kind === "subtitles") {

            $files  = Js::get($this->model, "subtitles");
            $result = is_array($files) ? Js::copy($files) : array();

        } else {

            throw self::editError("invalid", "Unknown kind \"" . Js::str($kind) . "\"; one of overlays, codeSnippets, annotations, chapters, contentViews, subtitles");

        }

        return ($filter instanceof Closure) ? Js::filter($result, $filter) : $result;

    }

    public function get($kind, $ref) {

        if (is_string($kind) && array_key_exists($kind, self::ITEM_KINDS)) {
            $data = $this->findItem($kind, $ref);
            return $data ? $this->serializeItem($kind, $data) : null;
        }

        if ($kind === "chapters") {
            $chapter = $this->findChapter($ref);
            return $chapter ? Js::copy($chapter) : null;
        }

        if ($kind === "subtitles") {
            $entry = null;
            foreach ($this->list("subtitles") as $file) {
                if (Js::strictEq(Js::get($file, "srclang"), $ref)) { $entry = $file; break; }
            }
            if (!$entry) { return null; }
            $text = Js::get($this->subtitles, Js::str($ref));
            $entry->vtt = is_string($text) ? $text : null;
            return $entry;
        }

        if ($kind === "contentViews") {
            throw self::editError("invalid", "Content views have no identity; list them with list('contentViews', { area })");
        }

        throw self::editError("invalid", "Unknown kind \"" . Js::str($kind) . "\"");

    }


    /* ------------------------------------------------------------------ */
    /*  Writing items                                                     */
    /* ------------------------------------------------------------------ */

    // The latest created in a collection (annotations: the user's own).
    private function lastCreated($kind) {
        $last = 0;
        foreach ($this->itemList($kind) as $data) {
            if ($kind === "annotations" && Js::str(Js::get($data, "creatorId")) !== $this->user["id"]) { continue; }
            $created = Js::get($data, "created");
            $last    = Js::max($last, Js::truthy($created) ? $created : 0);
        }
        return $last;
    }

    private function completeItem($kind, $data, &$errors) {

        $spec = self::ITEM_KINDS[$kind];
        $item = Js::copy($data);

        if (Js::isUndef(Js::get($item, "type")))            { $item->type = "Annotation"; }
        if (Js::isUndef(Js::get($item, "frametrail:type"))) { $item->{"frametrail:type"} = $spec["itemType"]; }

        $creator = Js::get($item, "creator");

        if ($kind === "annotations" && !Js::isUndef($creator)
                && !(Js::isObject($creator) && Js::str(Js::get($creator, "id")) === $this->user["id"])) {
            $errors[] = self::error("/creator", "must be the signed-in user");
        }

        if ($kind === "annotations" || Js::isUndef($creator)) {
            $item->creator = Js::obj(array("nickname" => $this->user["name"], "type" => "Person", "id" => $this->user["id"]));
        }

        $created = Js::get($item, "created");

        if (Js::isUndef($created)) {
            $clock = $this->clock;
            $item->created = Js::isoString(Js::max($clock(), $this->lastCreated($kind) + 1));
        } else if (Js::isFinite(self::toMillis($created))
                && $this->findData($kind, self::toMillis($created), ($kind === "annotations") ? $this->user["id"] : null)) {
            $errors[] = self::error("/created", "is taken by another " . $spec["name"]);
        }

        $target = Js::get($item, "target");

        if (Js::isObject($target)) {
            if (Js::isUndef(Js::get($target, "type"))) { $target->type = "Video"; }
            $path = $this->sourcePath();
            if (!Js::isUndef($path)) { $target->source = $path; }
            $selector = Js::get($target, "selector");
            if (Js::isObject($selector)) {
                if (Js::isUndef(Js::get($selector, "type")))       { $selector->type = "FragmentSelector"; }
                if (Js::isUndef(Js::get($selector, "conformsTo"))) { $selector->conformsTo = self::MEDIA_FRAGMENTS; }
            }
        }

        return $item;

    }

    private function addItem($kind, $data) {

        $spec = $this->itemKind($kind);

        $this->requireEditing($kind);

        if (!Js::isObject($data)) {
            throw self::editError("invalid", "Invalid " . $spec["name"], array(self::error("", "must be object, is " . Js::typeOf($data))));
        }

        $errors = array();
        $item   = $this->completeItem($kind, $data, $errors);

        $errors = array_merge($errors, self::validate($spec["schema"], $item));
        if (!count($errors)) { $errors = $this->itemErrors($kind, $item); }
        if (count($errors)) {
            throw self::editError("invalid", "Invalid " . $spec["name"], $errors);
        }

        $parsed = $this->parseItem($kind, Js::copy($item));
        $list   = $this->itemList($kind);
        $list[] = $parsed;

        $this->setItemList($kind, $list);
        $this->markDirty($kind);

        return $this->serializeItem($kind, $parsed);

    }

    private function updateItem($kind, $ref, $patch) {

        $spec = $this->itemKind($kind);

        $this->requireEditing($kind);

        $before = $this->requireItem($kind, $ref);

        if ($kind === "annotations" && Js::str(Js::get($before, "creatorId")) !== $this->user["id"]) {
            throw self::editError("notAllowed", "Only your own annotations can be changed");
        }

        if (!Js::isObject($patch)) {
            throw self::editError("invalid", "Invalid change of " . $spec["name"], array(self::error("", "must be object, is " . Js::typeOf($patch))));
        }

        $current = $this->serializeItem($kind, $before);
        $next    = Util::mergePatch($current, $patch);
        $errors  = array();

        if (!Js::same(Js::get($next, "created"), Js::get($current, "created"))) {
            $errors[] = self::error("/created", "cannot be changed");
        }
        if (!Js::same(Js::get($next, "creator"), Js::get($current, "creator"))) {
            $errors[] = self::error("/creator", "cannot be changed");
        }
        $nextBody    = Js::get($next, "body");
        $currentBody = Js::get($current, "body");
        if (Js::isObject($nextBody) && Js::isObject($currentBody)
                && !Js::strictEq(Js::get($nextBody, "frametrail:type"), Js::get($currentBody, "frametrail:type"))) {
            $errors[] = self::error("/body/frametrail:type", "cannot be changed; remove the " . $spec["name"] . " and add a new one");
        }

        if (Js::isObject(Js::get($next, "target")) && !Js::isUndef($this->sourcePath())) {
            $next->target->source = $this->sourcePath();
        }

        $errors = array_merge($errors, self::validate($spec["schema"], $next));
        if (!count($errors)) { $errors = $this->itemErrors($kind, $next); }
        if (count($errors)) {
            throw self::editError("invalid", "Invalid change of " . $spec["name"], $errors);
        }

        if (Js::same($next, $current)) {
            return $current;
        }

        $after = $this->parseItem($kind, Js::copy($next));

        // The same item: identity and where it was loaded from stay.
        $after->created = $before->created;
        if ($kind === "annotations") { $after->source = Js::copy(Js::get($before, "source")); }

        $list = array_map(function($data) use ($before, $after) { return ($data === $before) ? $after : $data; }, $this->itemList($kind));

        $this->setItemList($kind, $list);
        $this->markDirty($kind);

        return $this->serializeItem($kind, $after);

    }

    private function removeItem($kind, $ref) {

        $this->itemKind($kind);
        $this->requireEditing($kind);

        $data = $this->requireItem($kind, $ref);

        if ($kind === "annotations" && Js::str(Js::get($data, "creatorId")) !== $this->user["id"]) {
            throw self::editError("notAllowed", "Only your own annotations can be changed");
        }

        $stored = $this->serializeItem($kind, $data);

        $this->setItemList($kind, Js::filter($this->itemList($kind), function($other) use ($data) { return $other !== $data; }));
        $this->markDirty($kind);

        return $stored;

    }


    /* ------------------------------------------------------------------ */
    /*  Writing chapters                                                  */
    /* ------------------------------------------------------------------ */

    private function setChapters($chapters) {
        $this->model->chapters = Js::sort($chapters, function($a, $b) { return Js::get($a, "start") - Js::get($b, "start"); });
        $this->markDirty("chapters");
    }

    private function addChapter($data) {

        $this->requireEditing("chapters");

        if (!Js::isObject($data)) {
            throw self::editError("invalid", "Invalid chapter", array(self::error("", "must be object, is " . Js::typeOf($data))));
        }

        $item   = Js::copy($data);
        $errors = self::validate(self::CHAPTER_SCHEMA, $item);

        if (!count($errors) && $this->findChapter($item->start)) {
            $errors[] = self::error("/start", "is taken by another chapter");
        }
        if (count($errors)) {
            throw self::editError("invalid", "Invalid chapter", $errors);
        }

        $chapters   = $this->model->chapters;
        $chapters[] = $item;
        $this->setChapters($chapters);

        return Js::copy($item);

    }

    private function updateChapter($start, $patch) {

        $this->requireEditing("chapters");

        $chapter = $this->findChapter($start);

        if (!$chapter) {
            throw self::editError("notFound", "No chapter starts at " . Js::stringify($start));
        }

        if (!Js::isObject($patch)) {
            throw self::editError("invalid", "Invalid change of chapter", array(self::error("", "must be object, is " . Js::typeOf($patch))));
        }

        $next   = Util::mergePatch($chapter, $patch);
        $errors = self::validate(self::CHAPTER_SCHEMA, $next);
        $other  = count($errors) ? null : $this->findChapter($next->start);

        if ($other && $other !== $chapter) {
            $errors[] = self::error("/start", "is taken by another chapter");
        }
        if (count($errors)) {
            throw self::editError("invalid", "Invalid change of chapter", $errors);
        }

        if (Js::same($next, $chapter)) {
            return Js::copy($chapter);
        }

        $this->setChapters(array_map(function($data) use ($chapter, $next) { return ($data === $chapter) ? $next : $data; }, $this->model->chapters));

        return Js::copy($next);

    }

    private function removeChapter($start) {

        $this->requireEditing("chapters");

        $chapter = $this->findChapter($start);

        if (!$chapter) {
            throw self::editError("notFound", "No chapter starts at " . Js::stringify($start));
        }

        $this->setChapters(Js::filter($this->model->chapters, function($data) use ($chapter) { return $data !== $chapter; }));

        return Js::copy($chapter);

    }


    /* ------------------------------------------------------------------ */
    /*  add, update, remove, layout, subtitles                            */
    /* ------------------------------------------------------------------ */

    private static function noIdentity($kind) {
        if ($kind === "contentViews") {
            return self::editError("invalid", "Content views are set per layout area, with setLayout(area, contentViews)");
        }
        if ($kind === "subtitles") {
            return self::editError("invalid", "Subtitles are set per language, with setSubtitles(lang, vttText)");
        }
        return self::editError("invalid", "Unknown kind \"" . Js::str($kind) . "\"; one of overlays, codeSnippets, annotations, chapters");
    }

    private static function isItemKind($kind) {
        return is_string($kind) && array_key_exists($kind, self::ITEM_KINDS);
    }

    public function add($kind, $data) {
        if ($kind === "chapters") { return $this->addChapter($data); }
        if (!self::isItemKind($kind)) { throw self::noIdentity($kind); }
        return $this->addItem($kind, $data);
    }

    public function update($kind, $ref, $patch) {
        if ($kind === "chapters") { return $this->updateChapter($ref, $patch); }
        if (!self::isItemKind($kind)) { throw self::noIdentity($kind); }
        return $this->updateItem($kind, $ref, $patch);
    }

    public function remove($kind, $ref) {
        if ($kind === "chapters") { return $this->removeChapter($ref); }
        if (!self::isItemKind($kind)) { throw self::noIdentity($kind); }
        return $this->removeItem($kind, $ref);
    }

    public function setLayout($area, $contentViews) {

        $this->requireEditing("contentViews");

        if (!is_string($area) || !array_key_exists($area, self::AREAS)) {
            throw self::editError("invalid", "Unknown layout area \"" . Js::str($area) . "\"; one of top, bottom, left, right");
        }

        $which = self::AREAS[$area];

        if (!is_array($contentViews)) {
            throw self::editError("invalid", "Invalid content views", array(self::error("", "must be array, is " . Js::typeOf($contentViews))));
        }

        $errors = array();

        foreach ($contentViews as $i => $contentView) {
            foreach (self::validate("hypervideo.schema.json#/\$defs/contentView", $contentView) as $e) {
                $errors[] = self::error("/" . $i . $e->path, $e->message);
            }
        }

        if (count($errors)) {
            throw self::editError("invalid", "Invalid content views", $errors);
        }

        // The editor keeps all four areas, and saves them all.
        $old    = Js::isObject(Js::get($this->model, "layout")) ? $this->model->layout : new stdClass();
        $layout = new stdClass();

        foreach (array("areaTop", "areaBottom", "areaLeft", "areaRight") as $key) {
            $views = Js::get($old, $key);
            $layout->{$key} = is_array($views) ? $views : array();
        }
        foreach (Js::ownKeys($old) as $key) {
            if (!property_exists($layout, $key)) { $layout->{$key} = $old->{$key}; }
        }

        $layout->{self::AREA_KEYS[$which]} = Js::copy($contentViews);

        $this->model->layout = $layout;
        $this->markDirty("contentViews");

        return $this->contentViewsOf($which);

    }

    public function setSubtitles($lang, $vtt) {

        $this->requireEditing("subtitles");

        if (!is_string($lang) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $lang)) {
            throw self::editError("invalid", "Invalid language \"" . Js::str($lang) . "\": letters, digits, _ and - (at most 32)");
        }

        if ($vtt !== null) {
            if (!is_string($vtt)) {
                throw self::editError("invalid", "Invalid subtitles", array(self::error("", "must be string or null, is " . Js::typeOf($vtt))));
            }
            if (!preg_match('/^\x{FEFF}?WEBVTT(?:[ \t]|\r?\n|$)/uD', $vtt)) {
                throw self::editError("invalid", "Invalid subtitles", array(self::error("", "must begin with WEBVTT")));
            }
        }

        $files    = is_array(Js::get($this->model, "subtitles")) ? $this->model->subtitles : array();
        $listed   = false;
        foreach ($files as $file) {
            if (Js::isObject($file) && Js::get($file, "srclang") === $lang) { $listed = true; }
        }
        $text     = Js::get($this->subtitles, $lang);
        $previous = ($listed && is_string($text)) ? $text : null;

        if ($previous !== $vtt) {

            $entry = Js::obj(array("src" => $lang . ".vtt", "srclang" => $lang));
            $texts = clone $this->subtitles;

            if ($vtt === null) {
                $this->model->subtitles = Js::filter($files, function($file) use ($lang) { return !(Js::isObject($file) && Js::get($file, "srclang") === $lang); });
                unset($texts->{$lang});
            } else {
                // The file is always written as <lang>.vtt.
                if ($listed) {
                    $this->model->subtitles = array_map(function($file) use ($lang, $entry) {
                        return (Js::isObject($file) && Js::get($file, "srclang") === $lang) ? $entry : $file;
                    }, $files);
                } else {
                    $files[] = $entry;
                    $this->model->subtitles = $files;
                }
                $texts->{$lang} = $vtt;
            }

            $this->subtitles = $texts;
            $this->markDirty("subtitles");

        }

        foreach ($this->list("subtitles") as $file) {
            if (Js::get($file, "srclang") === $lang) { return $file; }
        }

        return null;

    }


    /* ------------------------------------------------------------------ */
    /*  Transactions                                                      */
    /* ------------------------------------------------------------------ */

    private function snapshot() {
        return array(
            "overlays"     => $this->model->overlays,
            "codeSnippets" => $this->model->codeSnippets,
            "chapters"     => $this->model->chapters,
            "layout"       => Js::get($this->model, "layout"),
            "files"        => Js::get($this->model, "subtitles"),
            "annotations"  => $this->annotations,
            "subtitles"    => $this->subtitles,
            "dirty"        => $this->dirty
        );
    }

    private function restore($saved) {
        $this->model->overlays     = $saved["overlays"];
        $this->model->codeSnippets = $saved["codeSnippets"];
        $this->model->chapters     = $saved["chapters"];
        $this->model->layout       = $saved["layout"];
        $this->model->subtitles    = $saved["files"];
        $this->annotations         = $saved["annotations"];
        $this->subtitles           = $saved["subtitles"];
        $this->dirty               = $saved["dirty"];
    }

    /**
     * I run fn with this store and keep what it changes only when it
     * returns; when it throws, everything is as before. Inside a
     * transaction, a transaction is part of it.
     *
     * @method transaction
     * @param {String} $description
     * @param {Callable} $fn (store) → result
     * @return Mixed what fn returns
     */
    public function transaction($description, $fn) {

        if ($this->transacting) { return $fn($this); }

        $saved = $this->snapshot();

        $this->transacting = true;

        try {
            $result = $fn($this);
        } catch (Throwable $e) {
            $this->restore($saved);
            $this->transacting = false;
            throw $e;
        }

        $this->transacting = false;

        return $result;

    }


    /* ------------------------------------------------------------------ */
    /*  The bundle                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * The bundle as a save would leave it: hypervideo.json and the user's
     * annotation file written anew when they changed (with "all": always),
     * every other file as given; the annotations index untouched.
     *
     * @method data
     * @param {Array} $options array("all" => true)
     * @return stdClass
     */
    public function data($options = array()) {

        $all = !empty($options["all"]);
        $out = Js::copy($this->bundle);

        if ($this->dirty["hypervideo"] || $all) {
            $out->hypervideo = $this->getHypervideo();
        }

        $own = array();
        foreach ($this->annotations as $annotation) {
            if (Js::str(Js::get($annotation, "creatorId")) === $this->user["id"]) { $own[] = $annotation; }
        }

        $write = $this->dirty["annotations"] || ($all && (count($own) > 0 || property_exists($this->files, $this->user["id"])));

        if ($write && $this->user["id"] !== "") {
            if (!Js::isObject(Js::get($out, "annotations"))) { $out->annotations = new stdClass(); }
            if (!Js::isObject(Js::get($out->annotations, "files"))) { $out->annotations->files = new stdClass(); }
            $out->annotations->files->{$this->user["id"]} = Serializer::serializeAnnotationFile($own, $this->itemContext());
        }

        if (count(Js::ownKeys($this->subtitles)) || Js::isObject(Js::get($this->bundle, "subtitles"))) {
            $out->subtitles = Js::copy($this->subtitles);
        }

        if (!$this->project) { return $out; }

        $whole = Js::copy($this->project);
        $whole->hypervideos->{$this->id} = $out;
        return $whole;

    }

}
