<?php

/*
 * FrameTrail-Conversational-UI — a PHP port of FrameTrail's
 * FrameTrailSerializer (src/_shared/frametrail-core/serialization/FrameTrailSerializer.js):
 * "stored JSON ⇄ working model" for hypervideo.json, content items and
 * annotation files, and the folder bundle format. Same results as the
 * JavaScript (FrameTrail's tests/README.md, "Round trips"); FrameTrail's version
 * is the reference.
 *
 * Writing is FrameTrail's three-way merge: every parsed object keeps the stored
 * object it came from in "_stored"; what did not change is written as stored,
 * what changed in the current form, unknown properties are kept, and an item's
 * @context and created are always written in the current form.
 *
 * Objects are stdClass (models too, which may hold undefined like their
 * JavaScript counterparts); a written object's keys keep the order they were
 * stored in. A folder is an array of paths (relative to _data/) to contents:
 * parsed JSON for .json files, text for .vtt and .css.
 */

if (!function_exists("ftExtensionStorage") && !defined("FT_CONVERSATIONAL_UI_LIB")) {
    http_response_code(404);
    exit;
}

use FtConversationalUiJs as Js;
use FtConversationalUiKeyframes as Keyframes;


class FtConversationalUiSerializer {

    const CONTEXT = array("http://www.w3.org/ns/anno.jsonld", "https://frametrail.org/ns/context.jsonld");

    const MEDIA_FRAGMENTS = "http://www.w3.org/TR/media-frags/";

    // Where a parsed object keeps the stored object it came from.
    const STORED = "_stored";

    // Item keys that are always written in their current form.
    const FORCED_ITEM_KEYS = array("@context", "created");

    // Per resource type: the W3C body type and format, and where src goes (DATA-MODEL.md, "Resource types").
    const RESOURCE_TYPES = array(
        "text"        => array("type" => "TextualBody", "format" => "text/html", "src" => "value"),
        "html"        => array("src" => "source"),
        "quiz"        => array("type" => "TextualBody", "format" => "text/html", "src" => "value"),
        "hotspot"     => array("type" => "TextualBody", "format" => "text/html", "src" => null),
        "cursor"      => array("type" => "Dataset", "format" => "application/x-frametrail-cursor", "src" => null),
        "counter"     => array("type" => "Dataset", "format" => "application/x-frametrail-counter", "src" => null),
        "chart"       => array("type" => "Dataset", "format" => "application/x-frametrail-chart", "src" => null),
        "image"       => array("type" => "Image", "format" => "image/*", "src" => "source"),
        "video"       => array("type" => "Video", "format" => "video/mp4", "src" => "source"),
        "audio"       => array("src" => "source"),
        "pdf"         => array("src" => "source"),
        "youtube"     => array("type" => "Video", "format" => "text/html", "src" => "source"),
        "vimeo"       => array("type" => "Video", "format" => "text/html", "src" => "source"),
        "wistia"      => array("type" => "Video", "format" => "text/html", "src" => "source"),
        "loom"        => array("type" => "Video", "format" => "text/html", "src" => "source"),
        "twitch"      => array("type" => "Video", "format" => "text/html", "src" => "source"),
        "soundcloud"  => array("type" => "Sound", "format" => "text/html", "src" => "source"),
        "spotify"     => array("type" => "Sound", "format" => "text/html", "src" => "source"),
        "webpage"     => array("type" => "Text", "format" => "text/html", "src" => "value"),
        "wikipedia"   => array("type" => "Text", "format" => "text/html", "src" => "value"),
        "entity"      => array("type" => "Text", "format" => "text/html", "src" => "value"),
        "location"    => array("type" => "Dataset", "format" => "application/x-frametrail-location", "src" => "source"),
        "mastodon"    => array("type" => "Text", "format" => "text/html", "src" => "source"),
        "codepen"     => array("type" => "Text", "format" => "text/html", "src" => "source"),
        "urlpreview"  => array("type" => "Text", "format" => "text/html", "src" => "source"),
        "figma"       => array("type" => "Image", "format" => "text/html", "src" => "source"),
        "codesnippet" => array("type" => "TextualBody", "format" => "text/javascript", "src" => "value")
    );

    // Body types whose body may carry a media fragment of the embedded media.
    const MEDIA_SELECTOR_TYPES = array("video", "vimeo", "youtube");

    // The playback-relevant keys of config.json, the only ones a project bundle carries.
    const PLAYBACK_CONFIG_KEYS = array("defaultTheme", "defaultLanguage", "videoFit", "overviewMode", "overviewTitle", "overviewShowSearchBar");

    // Keys of annotations/_index.json that are not legacy per-user entries.
    const ANNOTATION_INDEX_KEYS = array("mainAnnotation", "annotation-increment", "annotationfiles");

    // Stands in for contents while the rest of hypervideo.json is merged.
    const CONTENTS_SLOT = "\0contents";

    private static $bundleFormats = null;


    /* ------------------------------------------------------------------ */
    /*  JSON helpers                                                      */
    /* ------------------------------------------------------------------ */

    // An object literal: undefined values are kept, as JavaScript keeps them.
    private static function literal($pairs) {
        $obj = new stdClass();
        foreach ($pairs as $key => $value) {
            $obj->{(string)$key} = $value;
        }
        return $obj;
    }

    // PHP writes an empty object as [], so read [] as {} where an object is meant.
    private static function objectOrEmpty($value) {
        return (is_array($value) && count($value) === 0) ? new stdClass() : $value;
    }

    // A value of a context array (JavaScript's context.key), undefined when missing.
    private static function ctx($context, $key) {
        return (is_array($context) && array_key_exists($key, $context)) ? $context[$key] : Js::undef();
    }

    /**
     * I merge a model's changes into the object it was read from (see
     * FrameTrailSerializer.mergeStored).
     *
     * @method mergeStored
     * @param {Mixed} $stored the object as it was read
     * @param {Mixed} $base   what I write for stored, read back without changes
     * @param {Mixed} $fresh  what I write for the model now
     * @return Mixed
     */
    public static function mergeStored($stored, $base, $fresh) {

        if (Js::same($fresh, $base)) { return Js::copy($stored); }
        if (Js::isUndef($fresh)) { return Js::undef(); }
        if (!Js::isObject($fresh) || !Js::isObject($base) || !Js::isObject($stored)) { return Js::copy($fresh); }

        $out = new stdClass();

        foreach (Js::ownKeys($stored) as $key) {
            $value = (Js::has($fresh, $key) || Js::has($base, $key))
                ? self::mergeStored($stored->{$key}, Js::get($base, $key), Js::get($fresh, $key))
                : Js::copy($stored->{$key});
            if (!Js::isUndef($value)) { $out->{$key} = $value; }
        }

        foreach (Js::ownKeys($fresh) as $key) {
            if (Js::has($stored, $key) || !Js::has($fresh, $key)) { continue; }
            if (!Js::same($fresh->{$key}, Js::get($base, $key))) { $out->{$key} = Js::copy($fresh->{$key}); }
        }

        return $out;

    }

    // I write a parsed object: the three-way merge with the object it came from, or fresh as it is.
    private static function writeMerged($parsed, $fresh, $rewrite, $forcedKeys) {

        $stored = Js::get($parsed, self::STORED);

        if (!Js::isObject($stored)) { return Js::copy($fresh); }

        $out = self::mergeStored($stored, $rewrite($stored), $fresh);

        foreach ($forcedKeys as $key) {
            if (Js::has($fresh, $key)) { $out->{$key} = Js::copy($fresh->{$key}); }
        }

        return $out;

    }

    // Copy the keys of source that known does not have.
    private static function withRest($known, $source, $skip = array()) {
        if (Js::isObject($source)) {
            foreach (get_object_vars($source) as $key => $value) {
                $key = (string)$key;
                if (property_exists($known, $key) || in_array($key, $skip, true)) { continue; }
                $known->{$key} = $value;
            }
        }
        return $known;
    }


    /* ------------------------------------------------------------------ */
    /*  Time, space and identity                                          */
    /* ------------------------------------------------------------------ */

    private static function selectorText($value) {
        return Js::str(Js::either($value, ""));
    }

    // Start of a Media Fragments time range ("t=1.5,3.2" → 1.5).
    private static function timeStart($selectorValue) {
        return preg_match('/t=([0-9.]+)/', self::selectorText($selectorValue), $m) ? Js::parseFloat($m[1]) : 0;
    }

    // End of a Media Fragments time range ("t=1.5,3.2" → 3.2).
    private static function timeEnd($selectorValue) {
        return preg_match('/t=[0-9.]+,([0-9.]+)/', self::selectorText($selectorValue), $m) ? Js::parseFloat($m[1]) : 0;
    }

    // xywh=percent:… → { left, top, width, height }, {} when there is none.
    private static function spatialBox($selectorValue) {
        $n = '(-?[0-9.]+(?:[eE][-+]?[0-9]+)?)';
        if (!preg_match('/xywh=percent:' . $n . ',' . $n . ',' . $n . ',' . $n . '/', self::selectorText($selectorValue), $m)) {
            return new stdClass();
        }
        return Js::obj(array(
            "left"   => Js::parseFloat($m[1]),
            "top"    => Js::parseFloat($m[2]),
            "width"  => Js::parseFloat($m[3]),
            "height" => Js::parseFloat($m[4])
        ));
    }

    // Static rotation in degrees, undefined for none.
    private static function rotationOf($raw) {
        $rotation = Js::parseFloat($raw);
        return (Js::isFinite($rotation) && $rotation != 0) ? $rotation : Js::undef();
    }

    // An item's created (ISO text or toString() text) in milliseconds.
    private static function parseCreated($created) {
        return ($created === null || Js::isUndef($created) || $created === "") ? NAN : Js::dateParse($created);
    }

    private static function isoCreated($created) {
        $iso = Js::isoString(Js::dateParse($created));
        return ($iso === null) ? Js::undef() : $iso;
    }

    /**
     * I make created unique within each group of items, in order: an item
     * whose created is taken, or missing (counted as 0), moves on by 1 ms.
     *
     * @method dedupeCreated
     * @param {Array} $items model items (stdClass, changed in place)
     * @param {Callable} $groupOf item → group key
     * @return Array the items
     */
    public static function dedupeCreated($items, $groupOf = null) {

        $used = array();

        foreach ((array)$items as $item) {
            $group = $groupOf ? Js::str($groupOf($item)) : "";
            if (!isset($used[$group])) { $used[$group] = array(); }
            $value = Js::get($item, "created");
            $value = Js::isFinite($value) ? $value : 0;
            while (isset($used[$group][Js::number($value)])) { $value += 1; }
            $used[$group][Js::number($value)] = true;
            $item->created = $value;
        }

        return $items;

    }

    private static function storedSource($parsed) {
        $stored = Js::get($parsed, self::STORED);
        return (Js::isObject($stored) && Js::isObject(Js::get($stored, "target"))) ? Js::get($stored->target, "source") : Js::undef();
    }

    // The target of an item is the hypervideo's video; without a sourcePath an item keeps its own.
    private static function targetSource($parsed, $context) {
        $sourcePath = self::ctx($context, "sourcePath");
        return !Js::isUndef($sourcePath) ? $sourcePath : self::storedSource($parsed);
    }


    /* ------------------------------------------------------------------ */
    /*  Bodies                                                            */
    /* ------------------------------------------------------------------ */

    private static function typeInfo($type) {
        return (is_string($type) && isset(self::RESOURCE_TYPES[$type])) ? self::RESOURCE_TYPES[$type] : array();
    }

    private static function bodyType($type) {
        $info = self::typeInfo($type);
        return isset($info["type"]) ? $info["type"] : Js::undef();
    }

    private static function bodyFormat($type, $src) {
        $info = self::typeInfo($type);
        if ($type === "image") {
            $matched = Js::truthy($src) && preg_match('/\.([A-Za-z0-9_]{3,4})$/', Js::str($src), $m);
            return "image/" . ($matched ? $m[1] : "*");
        }
        return isset($info["format"]) ? $info["format"] : Js::undef();
    }

    private static function srcPlace($type) {
        $info = self::typeInfo($type);
        return array_key_exists("src", $info) ? $info["src"] : "source";
    }

    private static function mediaSelector($item) {
        if (in_array(Js::get($item, "type"), self::MEDIA_SELECTOR_TYPES, true)
                && Js::truthy(Js::get($item, "startOffset")) && Js::truthy(Js::get($item, "endOffset"))) {
            return self::literal(array(
                "type"       => "FragmentSelector",
                "conformsTo" => self::MEDIA_FRAGMENTS,
                "value"      => "t=" . Js::str($item->startOffset) . "," . Js::str($item->endOffset)
            ));
        }
        return Js::undef();
    }

    private static function creatorOf($item) {
        return self::literal(array("nickname" => Js::get($item, "creator"), "type" => "Person", "id" => Js::get($item, "creatorId")));
    }


    /* ------------------------------------------------------------------ */
    /*  Overlays                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * I read an overlay (a content item with frametrail:type "Overlay").
     *
     * @method parseOverlay
     * @param {stdClass} $item
     * @return stdClass
     */
    public static function parseOverlay($item) {

        $data     = Js::copy($item);
        $body     = Js::isObject(Js::get($data, "body")) ? $data->body : new stdClass();
        $target   = Js::isObject(Js::get($data, "target")) ? $data->target : new stdClass();
        $selector = Js::isObject(Js::get($target, "selector")) ? $target->selector : new stdClass();
        $value    = Js::get($selector, "value");
        $creator  = Js::isObject(Js::get($data, "creator")) ? $data->creator : new stdClass();
        $media    = Js::isObject(Js::get($body, "selector")) ? Js::get($body->selector, "value") : Js::undef();
        $attrs    = Js::truthy(Js::get($body, "frametrail:attributes")) ? Js::get($body, "frametrail:attributes") : Js::get($data, "frametrail:attributes");

        $overlay = self::literal(array(
            "name"               => Js::get($body, "frametrail:name"),
            "creator"            => Js::get($creator, "nickname"),
            "creatorId"          => Js::get($creator, "id"),
            "created"            => self::parseCreated(Js::get($data, "created")),
            "type"               => Js::get($body, "frametrail:type"),
            "src"                => Js::either(Js::get($body, "source"), Js::get($body, "value")),
            "thumb"              => Js::either(Js::get($body, "frametrail:thumb"), null),
            "start"              => self::timeStart($value),
            "end"                => self::timeEnd($value),
            "startOffset"        => Js::truthy($media) ? self::timeStart($media) : 0,
            "endOffset"          => Js::truthy($media) ? self::timeEnd($media) : 0,
            "attributes"         => Js::either(self::objectOrEmpty($attrs), new stdClass()),
            "licenseType"        => Js::either(Js::get($body, "frametrail:licenseType"), null),
            "licenseAttribution" => Js::either(Js::get($body, "frametrail:licenseAttribution"), null),
            "position"           => self::spatialBox($value),
            "keyframes"          => Keyframes::normalizeKeyframes(Js::get($selector, "frametrail:keyframes")),
            "rotation"           => self::rotationOf(Js::get($selector, "frametrail:rotation")),
            "events"             => self::objectOrEmpty(Js::get($data, "frametrail:events")),
            "tags"               => Js::get($data, "frametrail:tags"),
            "resourceId"         => Js::get($body, "frametrail:resourceId")
        ));

        self::normalizeAnimationParams($overlay->attributes);

        if ($overlay->type === "location") {
            $location = Js::isObject(Js::get($body, "frametrail:attributes")) ? $body->{"frametrail:attributes"} : new stdClass();
            if (Js::isObject($overlay->attributes)) {
                $overlay->attributes->lat         = Js::parseFloat(Js::get($location, "lat"));
                $overlay->attributes->lon         = Js::parseFloat(Js::get($location, "lon"));
                $overlay->attributes->boundingBox = Js::get($location, "boundingBox");
            }
        }

        $overlay->{self::STORED} = $item;

        return $overlay;

    }

    // Animation params are objects; PHP may have written them as [].
    private static function normalizeAnimationParams($attributes) {
        $animation = Js::isObject($attributes) ? Js::get($attributes, "animation") : Js::undef();
        if (!Js::isObject($animation)) { return; }
        foreach (array("in", "emphasis", "out", "text") as $phase) {
            if (Js::isObject(Js::get($animation, $phase))) {
                $params = self::objectOrEmpty(Js::get($animation->{$phase}, "params"));
                if (Js::isUndef($params)) {
                    unset($animation->{$phase}->params);
                } else {
                    $animation->{$phase}->params = $params;
                }
            }
        }
    }

    /**
     * I build the target selector of an overlay (see
     * FrameTrailSerializer.overlayTargetSelector).
     *
     * @method overlayTargetSelector
     * @param {stdClass} $overlay
     * @return stdClass
     */
    public static function overlayTargetSelector($overlay) {

        $position  = Js::either(Js::get($overlay, "position"), new stdClass());
        $raw       = Js::get($overlay, "keyframes");
        $keyframes = (Js::truthy($raw) && is_array($raw) && count($raw)) ? Keyframes::normalizeKeyframes($raw) : Js::undef();

        if (!Js::isUndef($keyframes)) {
            $position = Keyframes::unionBox($keyframes, $overlay->start, $overlay->end);
        }

        $selector = self::literal(array(
            "conformsTo" => self::MEDIA_FRAGMENTS,
            "type"       => "FragmentSelector",
            "value"      =>
                "t=" . Js::str(Js::get($overlay, "start")) . "," . Js::str(Js::get($overlay, "end"))
                . "&xywh=percent:"
                . Js::str(Js::get($position, "left")) . ","
                . Js::str(Js::get($position, "top")) . ","
                . Js::str(Js::get($position, "width")) . ","
                . Js::str(Js::get($position, "height"))
        ));

        if (!Js::isUndef($keyframes)) {
            $selector->{"frametrail:keyframes"} = $keyframes;
        } else if (!Js::isUndef(self::rotationOf(Js::get($overlay, "rotation")))) {
            $selector->{"frametrail:rotation"} = self::rotationOf($overlay->rotation);
        }

        return $selector;

    }

    private static function overlayAttributes($overlay) {

        $attributes = Js::get($overlay, "attributes");

        if (Js::get($overlay, "type") !== "location" || !Js::isObject($attributes)) { return $attributes; }

        $copy = clone $attributes;
        $copy->lat         = Js::parseFloat(Js::get($attributes, "lat"));
        $copy->lon         = Js::parseFloat(Js::get($attributes, "lon"));
        $copy->boundingBox = Js::truthy(Js::get($attributes, "boundingBox")) ? $attributes->boundingBox : array();
        return $copy;

    }

    private static function writeOverlay($overlay, $context) {

        $type  = Js::get($overlay, "type");
        $place = self::srcPlace($type);
        $src   = Js::get($overlay, "src");

        return self::literal(array(
            "@context"        => self::CONTEXT,
            "creator"         => self::creatorOf($overlay),
            "created"         => self::isoCreated(Js::get($overlay, "created")),
            "type"            => "Annotation",
            "frametrail:type" => "Overlay",
            "frametrail:tags" => Js::either(Js::get($overlay, "tags"), array()),
            "target"          => self::literal(array(
                "type"     => "Video",
                "source"   => self::targetSource($overlay, $context),
                "selector" => self::overlayTargetSelector($overlay)
            )),
            "body"            => self::literal(array(
                "type"                          => self::bodyType($type),
                "frametrail:type"               => $type,
                "format"                        => self::bodyFormat($type, $src),
                "source"                        => ($place === "source") ? $src : Js::undef(),
                "value"                         => ($place === "value") ? $src : Js::undef(),
                "frametrail:name"               => Js::get($overlay, "name"),
                "frametrail:thumb"              => Js::get($overlay, "thumb"),
                "frametrail:licenseType"        => Js::get($overlay, "licenseType"),
                "frametrail:licenseAttribution" => Js::get($overlay, "licenseAttribution"),
                "selector"                      => self::mediaSelector($overlay),
                "frametrail:resourceId"         => Js::get($overlay, "resourceId"),
                "frametrail:attributes"         => self::overlayAttributes($overlay)
            )),
            "frametrail:events" => Js::get($overlay, "events")
        ));

    }

    /**
     * I write an overlay as a W3C Web Annotation.
     *
     * @method serializeOverlay
     * @param {stdClass} $overlay
     * @param {Array} $context array("sourcePath" => …)
     * @return stdClass
     */
    public static function serializeOverlay($overlay, $context = array()) {
        return self::writeMerged($overlay, self::writeOverlay($overlay, $context ?: array()), function($stored) {
            return self::writeOverlay(self::parseOverlay($stored), array());
        }, self::FORCED_ITEM_KEYS);
    }


    /* ------------------------------------------------------------------ */
    /*  Code snippets                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * I read a code snippet (a content item with frametrail:type "CodeSnippet").
     *
     * @method parseCodeSnippet
     * @param {stdClass} $item
     * @return stdClass
     */
    public static function parseCodeSnippet($item) {

        $data     = Js::copy($item);
        $body     = Js::isObject(Js::get($data, "body")) ? $data->body : new stdClass();
        $target   = Js::isObject(Js::get($data, "target")) ? $data->target : new stdClass();
        $selector = Js::isObject(Js::get($target, "selector")) ? $target->selector : new stdClass();
        $creator  = Js::isObject(Js::get($data, "creator")) ? $data->creator : new stdClass();
        $attrs    = Js::truthy(Js::get($body, "frametrail:attributes")) ? Js::get($body, "frametrail:attributes") : Js::get($data, "frametrail:attributes");

        $snippet = self::literal(array(
            "name"       => Js::get($body, "frametrail:name"),
            "creator"    => Js::get($creator, "nickname"),
            "creatorId"  => Js::get($creator, "id"),
            "created"    => self::parseCreated(Js::get($data, "created")),
            "snippet"    => Js::get($body, "value"),
            "start"      => self::timeStart(Js::get($selector, "value")),
            "attributes" => Js::either(self::objectOrEmpty($attrs), new stdClass()),
            "tags"       => Js::get($data, "frametrail:tags")
        ));

        $snippet->{self::STORED} = $item;

        return $snippet;

    }

    private static function writeCodeSnippet($snippet, $context) {
        return self::literal(array(
            "@context"        => self::CONTEXT,
            "creator"         => self::creatorOf($snippet),
            "created"         => self::isoCreated(Js::get($snippet, "created")),
            "type"            => "Annotation",
            "frametrail:type" => "CodeSnippet",
            "frametrail:tags" => Js::get($snippet, "tags"),
            "target"          => self::literal(array(
                "type"     => "Video",
                "source"   => self::targetSource($snippet, $context),
                "selector" => self::literal(array(
                    "conformsTo" => self::MEDIA_FRAGMENTS,
                    "type"       => "FragmentSelector",
                    "value"      => "t=" . Js::str(Js::get($snippet, "start"))
                ))
            )),
            "body"            => self::literal(array(
                "type"                  => "TextualBody",
                "frametrail:type"       => "codesnippet",
                "format"                => "text/javascript",
                "value"                 => Js::get($snippet, "snippet"),
                "frametrail:name"       => Js::get($snippet, "name"),
                "frametrail:thumb"      => null,
                "frametrail:resourceId" => null,
                "frametrail:attributes" => Js::get($snippet, "attributes")
            ))
        ));
    }

    /**
     * I write a code snippet as a W3C Web Annotation.
     *
     * @method serializeCodeSnippet
     * @param {stdClass} $snippet
     * @param {Array} $context array("sourcePath" => …)
     * @return stdClass
     */
    public static function serializeCodeSnippet($snippet, $context = array()) {
        return self::writeMerged($snippet, self::writeCodeSnippet($snippet, $context ?: array()), function($stored) {
            return self::writeCodeSnippet(self::parseCodeSnippet($stored), array());
        }, self::FORCED_ITEM_KEYS);
    }


    /* ------------------------------------------------------------------ */
    /*  Annotations                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * I read an annotation (an item of a user's annotation file).
     *
     * @method parseAnnotation
     * @param {stdClass} $item
     * @param {Mixed} $source where it was loaded from, kept as annotation.source
     * @return stdClass
     */
    public static function parseAnnotation($item, $source = null) {

        $source   = (func_num_args() > 1) ? $source : Js::undef();
        $data     = Js::copy($item);
        $body     = is_array(Js::get($data, "body")) ? Js::get($data->body, 0) : Js::get($data, "body");
        $target   = Js::isObject(Js::get($data, "target")) ? $data->target : new stdClass();
        $selector = Js::isObject(Js::get($target, "selector")) ? $target->selector : new stdClass();
        $creator  = Js::isObject(Js::get($data, "creator")) ? $data->creator : new stdClass();

        $body = Js::isObject($body) ? $body : new stdClass();
        $type = Js::get($body, "frametrail:type");

        if (Js::truthy(Js::get($data, "frametrail:uri"))) {
            $uri = $data->{"frametrail:uri"};
        } else if ($type === "entity") {
            $uri = Js::get($body, "source");
        } else {
            $uri = null;
        }

        if ($type === "location") {
            $src = null;
        } else if ($type === "urlpreview") {
            $src = Js::either(Js::get($body, "source"), Js::get($body, "value"));
        } else {
            $src = (self::srcPlace($type) === "value") ? Js::get($body, "value") : Js::get($body, "source");
        }

        $annotation = self::literal(array(
            "name"               => Js::get($body, "frametrail:name"),
            "creator"            => Js::get($creator, "nickname"),
            "creatorId"          => Js::get($creator, "id"),
            "created"            => self::parseCreated(Js::get($data, "created")),
            "type"               => $type,
            "uri"                => $uri,
            "src"                => $src,
            "thumb"              => Js::get($body, "frametrail:thumb"),
            "licenseType"        => Js::either(Js::get($body, "frametrail:licenseType"), null),
            "licenseAttribution" => Js::either(Js::get($body, "frametrail:licenseAttribution"), null),
            "start"              => self::timeStart(Js::get($selector, "value")),
            "end"                => self::timeEnd(Js::get($selector, "value")),
            "resourceId"         => Js::get($body, "frametrail:resourceId"),
            "attributes"         => Js::either(self::objectOrEmpty(Js::get($body, "frametrail:attributes")), new stdClass()),
            "tags"               => Js::get($data, "frametrail:tags"),
            "source"             => $source,
            "graphData"          => Js::either(Js::get($data, "frametrail:graphdata"), null),
            "graphDataType"      => Js::either(Js::get($data, "frametrail:graphdatatype"), null)
        ));

        if ($type === "location") {
            $location = Js::isObject(Js::get($body, "frametrail:attributes")) ? $body->{"frametrail:attributes"} : new stdClass();
            if (Js::isObject($annotation->attributes)) {
                $lat = Js::get($location, "lat");
                $annotation->attributes->lat = Js::parseFloat(!Js::isUndef($lat) ? $lat : Js::get($body, "frametrail:lat"));
                $lon = Js::get($location, "lon");
                $annotation->attributes->lon = Js::parseFloat(!Js::isUndef($lon) ? $lon : Js::get($body, "frametrail:long"));
                $box = Js::get($location, "boundingBox");
                $annotation->attributes->boundingBox = !Js::isUndef($box) ? $box : Js::either(Js::get($body, "frametrail:boundingBox"), "");
            }
        }

        if ($type === "video") {
            $media = Js::isObject(Js::get($body, "selector")) ? Js::get($body->selector, "value") : Js::undef();
            $annotation->startOffset = Js::truthy($media) ? self::timeStart($media) : 0;
            $annotation->endOffset   = Js::truthy($media) ? self::timeEnd($media) : 0;
        }

        $annotation->{self::STORED} = $item;

        return $annotation;

    }

    private static function writeAnnotation($annotation, $context) {

        $type      = Js::get($annotation, "type");
        $place     = self::srcPlace($type);
        $src       = Js::get($annotation, "src");
        $graphData = Js::get($annotation, "graphData");
        $graphType = Js::get($annotation, "graphDataType");

        return self::literal(array(
            "@context"                 => self::CONTEXT,
            "creator"                  => self::creatorOf($annotation),
            "created"                  => self::isoCreated(Js::get($annotation, "created")),
            "type"                     => "Annotation",
            "frametrail:type"          => "Annotation",
            "frametrail:tags"          => Js::either(Js::get($annotation, "tags"), array()),
            "frametrail:uri"           => Js::either(Js::get($annotation, "uri"), null),
            "frametrail:graphdata"     => ($graphData !== null && !Js::isUndef($graphData)) ? $graphData : Js::undef(),
            "frametrail:graphdatatype" => ($graphType !== null && !Js::isUndef($graphType)) ? $graphType : Js::undef(),
            "target"                   => self::literal(array(
                "type"     => "Video",
                "source"   => self::targetSource($annotation, $context),
                "selector" => self::literal(array(
                    "conformsTo" => self::MEDIA_FRAGMENTS,
                    "type"       => "FragmentSelector",
                    "value"      => "t=" . Js::str(Js::get($annotation, "start")) . "," . Js::str(Js::get($annotation, "end"))
                ))
            )),
            "body"                     => self::literal(array(
                "type"                          => self::bodyType($type),
                "frametrail:type"               => $type,
                "format"                        => self::bodyFormat($type, $src),
                "source"                        => ($place !== "value") ? $src : Js::undef(),
                "value"                         => ($place === "value") ? $src : Js::undef(),
                "frametrail:name"               => Js::get($annotation, "name"),
                "frametrail:thumb"              => Js::get($annotation, "thumb"),
                "frametrail:licenseType"        => Js::get($annotation, "licenseType"),
                "frametrail:licenseAttribution" => Js::get($annotation, "licenseAttribution"),
                "selector"                      => self::mediaSelector($annotation),
                "frametrail:resourceId"         => Js::get($annotation, "resourceId"),
                "frametrail:attributes"         => Js::get($annotation, "attributes")
            ))
        ));

    }

    /**
     * I write an annotation as a W3C Web Annotation.
     *
     * @method serializeAnnotation
     * @param {stdClass} $annotation
     * @param {Array} $context array("sourcePath" => …)
     * @return stdClass
     */
    public static function serializeAnnotation($annotation, $context = array()) {
        return self::writeMerged($annotation, self::writeAnnotation($annotation, $context ?: array()), function($stored) {
            return self::writeAnnotation(self::parseAnnotation($stored), array());
        }, self::FORCED_ITEM_KEYS);
    }

    /**
     * I read an annotation file: a list of annotations, created made unique
     * per creator.
     *
     * @method parseAnnotationFile
     * @param {Mixed} $json
     * @param {Mixed} $source kept as each annotation's source
     * @return Array
     */
    public static function parseAnnotationFile($json, $source = null) {

        $hasSource = func_num_args() > 1;

        if (is_array($json)) {
            $list = $json;
        } else if (Js::isObject($json)) {
            $list = array_map(function($key) use ($json) { return $json->{$key}; }, Js::keys($json));
        } else {
            $list = array();
        }

        $annotations = array();
        foreach ($list as $item) {
            if (Js::isObject($item)) {
                $annotations[] = $hasSource ? self::parseAnnotation($item, $source) : self::parseAnnotation($item);
            }
        }

        return self::dedupeCreated($annotations, function($annotation) { return Js::get($annotation, "creatorId"); });

    }

    /**
     * I write an annotation file.
     *
     * @method serializeAnnotationFile
     * @param {Array} $annotations
     * @param {Array} $context array("sourcePath" => …)
     * @return Array
     */
    public static function serializeAnnotationFile($annotations, $context = array()) {
        $out = array();
        foreach ((array)$annotations as $annotation) {
            $out[] = self::serializeAnnotation($annotation, $context);
        }
        return $out;
    }

    /**
     * I read annotations/_index.json, legacy top-level entries included:
     * { mainAnnotation, annotationfiles }.
     *
     * @method parseAnnotationIndex
     * @param {Mixed} $json
     * @return stdClass
     */
    public static function parseAnnotationIndex($json) {

        $index = Js::isObject($json) ? $json : new stdClass();
        $files = Js::isObject(Js::get($index, "annotationfiles")) ? Js::copy($index->annotationfiles) : new stdClass();

        foreach (Js::keys($index) as $key) {
            if (in_array($key, self::ANNOTATION_INDEX_KEYS, true) || !Js::isObject($index->{$key}) || Js::has($files, $key)) { continue; }
            $files->{$key} = Js::copy($index->{$key});
        }

        $main = Js::get($index, "mainAnnotation");

        return Js::obj(array(
            "mainAnnotation"  => Js::isUndef($main) ? null : $main,
            "annotationfiles" => $files
        ));

    }

    /**
     * I return annotations/_index.json with one file's entry set (fields
     * merged over the entry under annotationfiles; a legacy top-level entry
     * of the file removed).
     *
     * @method setAnnotationIndexEntry
     * @param {Mixed} $json
     * @param {String} $fileId
     * @param {stdClass} $fields
     * @return stdClass
     */
    public static function setAnnotationIndexEntry($json, $fileId, $fields) {

        $index = Js::isObject($json) ? Js::copy($json) : new stdClass();
        $key   = Js::str($fileId);

        if (!Js::isObject(Js::get($index, "annotationfiles"))) { $index->annotationfiles = new stdClass(); }

        $entry = Js::isObject(Js::get($index->annotationfiles, $key)) ? clone $index->annotationfiles->{$key} : new stdClass();
        foreach ((array)$fields as $name => $value) {
            if (!Js::isUndef($value)) { $entry->{(string)$name} = $value; }
        }
        $index->annotationfiles->{$key} = $entry;

        if (!in_array($key, self::ANNOTATION_INDEX_KEYS, true) && Js::isObject(Js::get($index, $key))) {
            unset($index->{$key});
        }

        return $index;

    }


    /* ------------------------------------------------------------------ */
    /*  Hypervideos                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * I read the contents of a hypervideo.json: { overlays, codeSnippets,
     * otherContents }, created made unique in the first two.
     *
     * @method parseContents
     * @param {Mixed} $contents
     * @return stdClass
     */
    public static function parseContents($contents) {

        $result = Js::obj(array("overlays" => array(), "codeSnippets" => array(), "otherContents" => array()));

        if (is_array($contents)) {
            $list = $contents;
        } else if (Js::isObject($contents)) {
            $list = array_map(function($key) use ($contents) { return $contents->{$key}; }, Js::keys($contents));
        } else {
            $list = array();
        }

        $overlays = array();
        $snippets = array();
        $others   = array();

        foreach ($list as $item) {
            $type = Js::isObject($item) ? Js::get($item, "frametrail:type") : null;
            if ($type === "Overlay") {
                $overlays[] = self::parseOverlay($item);
            } else if ($type === "CodeSnippet") {
                $snippets[] = self::parseCodeSnippet($item);
            } else {
                $others[] = Js::copy($item);
            }
        }

        $result->overlays      = self::dedupeCreated($overlays);
        $result->codeSnippets  = self::dedupeCreated($snippets);
        $result->otherContents = $others;

        return $result;

    }

    /**
     * I read a hypervideo.json into its working model: { meta, config,
     * layout, clips, overlays, codeSnippets, otherContents, chapters,
     * subtitles, globalEvents, customCSS }.
     *
     * @method parseHypervideo
     * @param {Mixed} $json
     * @return stdClass
     */
    public static function parseHypervideo($json) {

        $data     = Js::isObject($json) ? $json : new stdClass();
        $config   = Js::isObject(Js::get($data, "config")) ? Js::copy($data->config) : new stdClass();
        $contents = self::parseContents(Js::get($data, "contents"));
        $layout   = Js::get($config, "layoutArea");

        unset($config->layoutArea);

        $model = self::literal(array(
            "meta"          => Js::isObject(Js::get($data, "meta")) ? Js::copy($data->meta) : new stdClass(),
            "config"        => $config,
            "layout"        => $layout,
            "clips"         => Js::copy(Js::get($data, "clips")),
            "overlays"      => $contents->overlays,
            "codeSnippets"  => $contents->codeSnippets,
            "otherContents" => $contents->otherContents,
            "chapters"      => Js::copy(Js::get($data, "chapters")),
            "subtitles"     => Js::copy(Js::get($data, "subtitles")),
            "globalEvents"  => self::objectOrEmpty(Js::copy(Js::get($data, "globalEvents"))),
            "customCSS"     => Js::get($data, "customCSS")
        ));

        $model->{self::STORED} = $json;

        return $model;

    }

    /**
     * I turn Transcript content views into CustomHTML holding the transcript
     * text, for subtitles given as { <srclang>: { cues: [{ startTime, endTime, text }] } }.
     *
     * @method transcriptsToHTML
     * @param {Mixed} $layoutArea
     * @param {Mixed} $subtitles
     * @return Mixed a new layoutArea
     */
    public static function transcriptsToHTML($layoutArea, $subtitles) {

        if (!Js::isObject($layoutArea) || !Js::truthy($subtitles)) { return $layoutArea; }

        $result = new stdClass();

        foreach (Js::ownKeys($layoutArea) as $area) {
            $views = $layoutArea->{$area};
            if (!is_array($views)) {
                $result->{$area} = $views;
                continue;
            }
            $result->{$area} = array_map(function($view) use ($subtitles) {
                $source = Js::get($view, "transcriptSource");
                $subs   = (Js::isObject($view) && Js::get($view, "type") === "Transcript" && Js::truthy($source))
                    ? Js::get($subtitles, Js::str($source))
                    : null;
                $cues   = Js::get($subs, "cues");
                if (!Js::truthy($subs) || !Js::truthy($cues)) { return $view; }
                $html = "";
                foreach ((array)$cues as $cue) {
                    $html .= '<span class="timebased" data-start="' . Js::str(Js::get($cue, "startTime")) . '" data-end="' . Js::str(Js::get($cue, "endTime")) . '">'
                        . str_replace(array("&", "<", ">"), array("&amp;", "&lt;", "&gt;"), Js::str(Js::get($cue, "text")))
                        . " </span>";
                }
                return self::literal(array(
                    "type"               => "CustomHTML",
                    "name"               => Js::get($view, "name"),
                    "icon"               => Js::get($view, "icon"),
                    "cssClass"           => Js::get($view, "cssClass"),
                    "html"               => $html,
                    "collectionFilter"   => Js::get($view, "collectionFilter"),
                    "contentSize"        => Js::get($view, "contentSize"),
                    "onClickContentItem" => Js::get($view, "onClickContentItem"),
                    "initClosed"         => Js::get($view, "initClosed"),
                    "filterAspect"       => Js::get($view, "filterAspect"),
                    "zoomControls"       => Js::get($view, "zoomControls")
                ));
            }, $views);
        }

        return $result;

    }

    private static function writeHypervideo($model, $context) {

        $meta   = Js::either(Js::get($model, "meta"), new stdClass());
        $config = Js::either(Js::get($model, "config"), new stdClass());
        $layout = !Js::isUndef(Js::get($model, "layout")) ? $model->layout : Js::get($config, "layoutArea");
        $now    = self::ctx($context, "now");

        if (self::ctx($context, "purpose") === "export") {
            $layout = self::transcriptsToHTML($layout, self::ctx($context, "subtitles"));
        }

        return self::literal(array(
            "meta"         => self::withRest(self::literal(array(
                "name"        => Js::get($meta, "name"),
                "description" => Js::get($meta, "description"),
                "thumb"       => Js::get($meta, "thumb"),
                "posterFrame" => Js::either(Js::get($meta, "posterFrame"), null),
                "creator"     => Js::get($meta, "creator"),
                "creatorId"   => Js::get($meta, "creatorId"),
                "created"     => Js::get($meta, "created"),
                "lastchanged" => !Js::isUndef($now) ? $now : Js::get($meta, "lastchanged")
            )), $meta),
            "config"       => self::withRest(self::literal(array(
                "slidingMode"      => Js::get($config, "slidingMode"),
                "slidingTrigger"   => Js::get($config, "slidingTrigger"),
                "autohideControls" => Js::get($config, "autohideControls"),
                "captionsVisible"  => Js::get($config, "captionsVisible"),
                "clipTimeVisible"  => Js::get($config, "clipTimeVisible"),
                "theme"            => Js::either(Js::get($config, "theme"), ""),
                "layoutArea"       => $layout
            )), $config, array("layoutArea")),
            "clips"        => Js::get($model, "clips"),
            "globalEvents" => Js::either(Js::get($model, "globalEvents"), new stdClass()),
            "customCSS"    => Js::either(Js::get($model, "customCSS"), ""),
            "contents"     => self::CONTENTS_SLOT,
            "chapters"     => Js::either(Js::get($model, "chapters"), array()),
            "subtitles"    => Js::get($model, "subtitles")
        ));

    }

    /**
     * I write a hypervideo model as hypervideo.json. Context: sourcePath (every
     * item's target source), now (meta.lastchanged), purpose ("save" or
     * "export"), subtitles (for exports).
     *
     * @method serializeHypervideo
     * @param {stdClass} $model
     * @param {Array} $context
     * @return stdClass
     */
    public static function serializeHypervideo($model, $context = array()) {

        $ctx    = $context ?: array();
        $fresh  = self::writeHypervideo($model, $ctx);
        $stored = Js::get($model, self::STORED);
        $now    = self::ctx($ctx, "now");

        if (Js::isObject($stored)) {
            $storedTop = clone $stored;
            $storedTop->contents = self::CONTENTS_SLOT;
            $out = self::mergeStored($storedTop, self::writeHypervideo(self::parseHypervideo($stored), array()), $fresh);
            if (!Js::isUndef($now) && Js::isObject(Js::get($out, "meta"))) {
                $out->meta->lastchanged = $now;
            }
        } else {
            $out = Js::copy($fresh);
        }

        $itemContext = array("sourcePath" => self::ctx($ctx, "sourcePath"));
        $contents    = array();

        foreach ((array)Js::either(Js::get($model, "overlays"), array()) as $overlay) {
            $contents[] = self::serializeOverlay($overlay, $itemContext);
        }
        foreach ((array)Js::either(Js::get($model, "codeSnippets"), array()) as $snippet) {
            $contents[] = self::serializeCodeSnippet($snippet, $itemContext);
        }
        foreach ((array)Js::either(Js::get($model, "otherContents"), array()) as $item) {
            $contents[] = Js::copy($item);
        }

        $out->contents = $contents;

        return $out;

    }


    /* ------------------------------------------------------------------ */
    /*  Bundles                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * I register a bundle format: array("read" => callable(source, options),
     * "write" => callable(bundle, options)).
     *
     * @method registerBundleFormat
     * @param {String} $name
     * @param {Array} $format
     */
    public static function registerBundleFormat($name, $format) {
        self::formats();
        self::$bundleFormats[$name] = $format;
    }

    private static function formats() {
        if (self::$bundleFormats === null) {
            self::$bundleFormats = array(
                "folder" => array(
                    "read"  => array(__CLASS__, "readFolder"),
                    "write" => array(__CLASS__, "writeFolder")
                )
            );
        }
        return self::$bundleFormats;
    }

    private static function bundleFormat($name) {
        $formats = self::formats();
        if (!isset($formats[$name])) { throw new Exception("Unknown bundle format: " . $name); }
        return $formats[$name];
    }

    /**
     * I read a bundle from a source in the given format.
     *
     * @method readBundle
     * @param {Mixed} $source
     * @param {String} $format
     * @param {Array} $options
     * @return stdClass
     */
    public static function readBundle($source, $format, $options = array()) {
        return call_user_func(self::bundleFormat($format)["read"], $source, $options ?: array());
    }

    /**
     * I write a bundle in the given format.
     *
     * @method writeBundle
     * @param {stdClass} $bundle
     * @param {String} $format
     * @param {Array} $options
     * @return Mixed
     */
    public static function writeBundle($bundle, $format, $options = array()) {
        return call_user_func(self::bundleFormat($format)["write"], $bundle, $options ?: array());
    }

    // The ids of the resources a hypervideo bundle uses: its clips and the items made from a resource.
    private static function usedResourceIds($bundle) {

        $used  = array();
        $hv    = Js::either(Js::get($bundle, "hypervideo"), new stdClass());
        $items = is_array(Js::get($hv, "contents")) ? $hv->contents : array();
        $annos = Js::get($bundle, "annotations");
        $files = (Js::truthy($annos) && Js::isObject(Js::get($annos, "files"))) ? $annos->files : new stdClass();

        $add = function($id) use (&$used) {
            if ($id !== null && !Js::isUndef($id) && $id !== "" && !in_array(Js::str($id), $used, true)) { $used[] = Js::str($id); }
        };

        foreach ((is_array(Js::get($hv, "clips")) ? $hv->clips : array()) as $clip) {
            if (Js::isObject($clip)) { $add(Js::get($clip, "resourceId")); }
        }

        foreach (Js::keys($files) as $fileId) {
            if (is_array($files->{$fileId})) { $items = array_merge($items, $files->{$fileId}); }
        }

        foreach ($items as $item) {
            if (Js::isObject($item) && Js::isObject(Js::get($item, "body"))) { $add(Js::get($item->body, "frametrail:resourceId")); }
        }

        return $used;

    }

    private static function folderDir($index, $id) {
        $entries = Js::get($index, "hypervideos");
        $entry   = Js::isObject($entries) ? Js::get($entries, Js::str($id)) : Js::undef();
        $rel     = Js::truthy($entry) ? $entry : "./" . Js::str($id);
        $dir     = preg_replace('/\/+$/', "", preg_replace('/^\.\//', "", Js::str($rel)));
        if ($dir === "" || $dir[0] === "/" || in_array("..", explode("/", $dir), true)) {
            throw new Exception("Invalid hypervideo folder: " . Js::str($rel));
        }
        return "hypervideos/" . $dir . "/";
    }

    private static function folderFile($files, $path) {
        if (!array_key_exists($path, $files)) { return Js::undef(); }
        $content = $files[$path];
        if (is_string($content) && preg_match('/\.json$/', $path)) {
            try {
                return Js::decode($content);
            } catch (Exception $e) {
                throw new Exception("Invalid JSON in " . $path);
            }
        }
        return Js::copy($content);
    }

    private static function subtitleFileName($hypervideo, $srclang) {
        $list = (Js::isObject($hypervideo) && is_array(Js::get($hypervideo, "subtitles"))) ? $hypervideo->subtitles : array();
        foreach ($list as $entry) {
            if (Js::isObject($entry) && Js::get($entry, "srclang") === $srclang && Js::truthy(Js::get($entry, "src"))) { return $entry->src; }
        }
        return $srclang . ".vtt";
    }

    private static function readHypervideoFolder($files, $id, $dir, $withResources) {

        $hypervideo = self::folderFile($files, $dir . "hypervideo.json");

        if (Js::isUndef($hypervideo)) { throw new Exception("Missing " . $dir . "hypervideo.json"); }

        $bundle          = Js::obj(array("bundle" => "hypervideo", "formatVersion" => 1, "id" => Js::str($id), "hypervideo" => $hypervideo));
        $annotationsDir  = $dir . "annotations/";
        $annotationIndex = Js::undef();
        $annotationFiles = null;

        foreach (array_keys($files) as $path) {
            $path = (string)$path;
            $name = (strpos($path, $annotationsDir) === 0) ? substr($path, strlen($annotationsDir)) : null;
            if ($name === null || $name === "" || !preg_match('/^[^\/]+\.json$/', $name)) { continue; }
            if ($name === "_index.json") {
                $annotationIndex = self::folderFile($files, $path);
            } else {
                if ($annotationFiles === null) { $annotationFiles = new stdClass(); }
                $annotationFiles->{preg_replace('/\.json$/', "", $name)} = self::folderFile($files, $path);
            }
        }

        // Index first, whatever the order of the paths.
        if (!Js::isUndef($annotationIndex) || $annotationFiles !== null) {
            $bundle->annotations = new stdClass();
            if (!Js::isUndef($annotationIndex)) { $bundle->annotations->index = $annotationIndex; }
            $bundle->annotations->files = ($annotationFiles !== null) ? $annotationFiles : new stdClass();
        }

        if ($withResources) {
            $index = self::folderFile($files, "resources/_index.json");
            $all   = (Js::isObject($index) && Js::isObject(Js::get($index, "resources"))) ? $index->resources : null;
            if ($all) {
                $bundle->resources = new stdClass();
                foreach (self::usedResourceIds($bundle) as $resourceId) {
                    if (Js::has($all, $resourceId)) { $bundle->resources->{$resourceId} = $all->{$resourceId}; }
                }
            }
        }

        foreach ((is_array(Js::get($hypervideo, "subtitles")) ? $hypervideo->subtitles : array()) as $entry) {
            if (!Js::isObject($entry) || !Js::truthy(Js::get($entry, "srclang"))) { continue; }
            $name = Js::truthy(Js::get($entry, "src")) ? Js::str($entry->src) : Js::str($entry->srclang) . ".vtt";
            $text = self::folderFile($files, $dir . "subtitles/" . $name);
            if (is_string($text)) {
                if (!Js::isObject(Js::get($bundle, "subtitles"))) { $bundle->subtitles = new stdClass(); }
                $bundle->subtitles->{Js::str($entry->srclang)} = $text;
            }
        }

        return $bundle;

    }

    private static function writeHypervideoFolder(&$files, $bundle, $dir) {

        $files[$dir . "hypervideo.json"] = Js::copy(Js::get($bundle, "hypervideo"));

        $annotations = Js::get($bundle, "annotations");

        if (Js::isObject($annotations)) {
            if (!Js::isUndef(Js::get($annotations, "index"))) {
                $files[$dir . "annotations/_index.json"] = Js::copy($annotations->index);
            }
            $annotationFiles = Js::either(Js::get($annotations, "files"), new stdClass());
            foreach (Js::keys($annotationFiles) as $fileId) {
                if ($fileId === "_index" || preg_match('/[\/\\\\]|^\.\.?$/', $fileId)) { throw new Exception("Invalid annotation file id: " . $fileId); }
                $files[$dir . "annotations/" . $fileId . ".json"] = Js::copy($annotationFiles->{$fileId});
            }
        }

        $subtitles = Js::either(Js::get($bundle, "subtitles"), new stdClass());
        foreach (Js::keys($subtitles) as $srclang) {
            $name = self::subtitleFileName(Js::get($bundle, "hypervideo"), $srclang);
            if (preg_match('/[\/\\\\]|^\.\.?$/', Js::str($name))) { throw new Exception("Invalid subtitle file name: " . Js::str($name)); }
            $files[$dir . "subtitles/" . Js::str($name)] = $subtitles->{$srclang};
        }

    }

    /**
     * The folder format, reading: options bundle ("project" or "hypervideo")
     * and, for a hypervideo, id. Without them a tree with a hypervideos index
     * is read as a project. JSON may also be given as text.
     *
     * @method readFolder
     * @param {Array} $files path → content
     * @param {Array} $options
     * @return stdClass
     */
    public static function readFolder($files, $options = array()) {

        if (!is_array($files)) { throw new Exception("A folder is a map of paths to contents"); }

        $index = self::folderFile($files, "hypervideos/_index.json");
        $id    = isset($options["id"]) ? $options["id"] : null;
        $kind  = !empty($options["bundle"]) ? $options["bundle"] : ((!Js::isUndef($index) && $id === null) ? "project" : "hypervideo");

        if ($kind === "hypervideo") {
            if ($id === null) { throw new Exception("Reading a hypervideo bundle needs options.id"); }
            return self::readHypervideoFolder($files, $id, self::folderDir($index, $id), true);
        }

        if (!Js::isObject($index)) { throw new Exception("Missing hypervideos/_index.json"); }

        $bundle  = Js::obj(array("bundle" => "project", "formatVersion" => 1, "hypervideosIndex" => $index, "hypervideos" => new stdClass()));
        $entries = Js::isObject(Js::get($index, "hypervideos")) ? $index->hypervideos : new stdClass();

        foreach (Js::keys($entries) as $hypervideoId) {
            $bundle->hypervideos->{$hypervideoId} = self::readHypervideoFolder($files, $hypervideoId, self::folderDir($index, $hypervideoId), false);
        }

        $resources      = self::folderFile($files, "resources/_index.json");
        $tagdefinitions = self::folderFile($files, "tagdefinitions.json");
        $config         = self::folderFile($files, "config.json");
        $customCSS      = self::folderFile($files, "custom.css");

        if (!Js::isUndef($resources))      { $bundle->resources = $resources; }
        if (!Js::isUndef($tagdefinitions)) { $bundle->tagdefinitions = $tagdefinitions; }
        if (Js::isObject($config)) {
            $bundle->config = new stdClass();
            foreach (self::PLAYBACK_CONFIG_KEYS as $key) {
                if (Js::has($config, $key)) { $bundle->config->{$key} = $config->{$key}; }
            }
        }
        if (is_string($customCSS)) { $bundle->customCSS = $customCSS; }

        return $bundle;

    }

    /**
     * The folder format, writing: path → content.
     *
     * @method writeFolder
     * @param {stdClass} $bundle
     * @param {Array} $options array("id" => …) for a hypervideo bundle
     * @return Array
     */
    public static function writeFolder($bundle, $options = array()) {

        $files = array();

        if (!Js::isObject($bundle)) { throw new Exception("Not a bundle"); }

        $kind = Js::get($bundle, "bundle");

        if ($kind === "project") {

            $files["hypervideos/_index.json"] = Js::copy(Js::get($bundle, "hypervideosIndex"));

            $hypervideos = Js::either(Js::get($bundle, "hypervideos"), new stdClass());
            foreach (Js::keys($hypervideos) as $id) {
                self::writeHypervideoFolder($files, $hypervideos->{$id}, self::folderDir(Js::get($bundle, "hypervideosIndex"), $id));
            }

            if (!Js::isUndef(Js::get($bundle, "resources")))      { $files["resources/_index.json"] = Js::copy($bundle->resources); }
            if (!Js::isUndef(Js::get($bundle, "tagdefinitions"))) { $files["tagdefinitions.json"] = Js::copy($bundle->tagdefinitions); }
            if (!Js::isUndef(Js::get($bundle, "config")))         { $files["config.json"] = Js::copy($bundle->config); }
            if (is_string(Js::get($bundle, "customCSS")))         { $files["custom.css"] = $bundle->customCSS; }

        } else if ($kind === "hypervideo") {

            $id = (isset($options["id"]) && $options["id"] !== null) ? $options["id"] : Js::get($bundle, "id");
            if ($id === null || Js::isUndef($id)) { throw new Exception("Writing a hypervideo bundle needs an id"); }

            self::writeHypervideoFolder($files, $bundle, self::folderDir(null, $id));

            if (Js::isObject(Js::get($bundle, "resources"))) {
                $highest = 0;
                foreach (Js::keys($bundle->resources) as $resourceId) {
                    $number  = Js::parseInt($resourceId);
                    $highest = max($highest, Js::truthy($number) ? $number : 0);
                }
                $files["resources/_index.json"] = Js::obj(array(
                    "resources-increment" => $highest,
                    "resources"           => Js::copy($bundle->resources)
                ));
            }

        } else {
            throw new Exception("Not a bundle: " . Js::str($kind));
        }

        return $files;

    }

}
