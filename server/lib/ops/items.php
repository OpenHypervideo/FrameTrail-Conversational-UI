<?php

/*
 * FrameTrail-Conversational-UI — items, the PHP side of client/ops/items.js:
 * the short form of items that operations return, and the stored items and
 * merge patches made from an operation's input.
 */

if (!function_exists("ftExtensionStorage") && !defined("FT_CONVERSATIONAL_UI_LIB")) {
    http_response_code(404);
    exit;
}

use FtConversationalUiJs as Js;
use FtConversationalUiOpsUtil as Util;
use FtConversationalUiKeyframes as Keyframes;


class FtConversationalUiItems {

    // At most this many characters of an item's text in its short form.
    const EXCERPT_LENGTH = 160;

    // Body types that keep their src in value (the others in source).
    const SRC_IN_VALUE = array("webpage", "wikipedia", "entity");


    private static function attributesOf($body) {
        $attributes = Js::get($body, "frametrail:attributes");
        return Js::isObject($attributes) ? $attributes : new stdClass();
    }

    private static function bodyOf($item) {
        $body = Js::get($item, "body");
        $body = is_array($body) ? Js::get($body, 0) : $body;
        return Js::isObject($body) ? $body : new stdClass();
    }

    private static function selectorOf($item) {
        $target = Js::get($item, "target");
        return (Js::isObject($target) && Js::isObject(Js::get($target, "selector"))) ? $target->selector : new stdClass();
    }

    // The text the short form shows for a body type, or "".
    private static function textOf($type, $attributes) {
        switch ($type) {
            case "text":
                $text  = Util::plainText(Js::get($attributes, "text"));
                $title = Util::plainText(Js::get($attributes, "title"));
                return ($title !== "" && $text !== "") ? $title . ": " . $text : (($title !== "") ? $title : $text);
            case "html":
            case "entity":
            case "hotspot":
                return Util::plainText(Js::get($attributes, "text"));
            case "quiz":
                return Util::plainText(Js::get($attributes, "question"));
        }
        return "";
    }

    // type and name of overlays and annotations; finish() adds text, src and tags.
    private static function describe($summary, $item) {

        $body = self::bodyOf($item);
        $type = Js::get($body, "frametrail:type");
        $name = Js::get($body, "frametrail:name");

        if (is_string($type)) { $summary->type = $type; }
        if (is_string($name) && $name !== "") { $summary->name = $name; }

        return function() use ($summary, $item, $body, $type) {

            $text   = is_string($type) ? self::textOf($type, self::attributesOf($body)) : "";
            $source = Js::get($body, "source");
            $value  = Js::get($body, "value");
            $src    = (is_string($source) && $source !== "")
                ? $source
                : ((in_array($type, self::SRC_IN_VALUE, true) && is_string($value) && $value !== "") ? $value : null);

            if ($text !== "") { $summary->text = Util::excerpt($text, self::EXCERPT_LENGTH); }
            if ($src !== null) { $summary->src = $src; }

            $tags = Js::get($item, "frametrail:tags");
            if (is_array($tags) && count($tags)) { $summary->tags = Js::copy($tags); }

            return $summary;

        };

    }

    /**
     * The short form of an item as stored. context: userId (to mark one's own
     * annotations), chapterEnd (a chapter's end).
     *
     * @method summary
     * @param {String} $kind
     * @param {stdClass} $item
     * @param {Array} $context
     * @return stdClass
     */
    public static function summary($kind, $item, $context = array()) {

        if ($kind === "chapters") {
            $title = Js::get($item, "title");
            return Js::obj(array(
                "kind"  => "chapters",
                "ref"   => $item->start,
                "title" => is_string($title) ? $title : "",
                "start" => $item->start,
                "end"   => (array_key_exists("chapterEnd", $context) && !Js::isUndef($context["chapterEnd"])) ? $context["chapterEnd"] : null
            ));
        }

        $selector = self::selectorOf($item);
        $span     = Util::timeSpan(Js::get($selector, "value"));
        $short    = Js::obj(array("kind" => $kind, "ref" => Js::get($item, "created")));

        if ($kind === "codeSnippets") {
            $body = self::bodyOf($item);
            $name = Js::get($body, "frametrail:name");
            if (is_string($name) && $name !== "") { $short->name = $name; }
            $short->start = $span->start;
            return $short;
        }

        if ($kind === "annotations") {
            $creator  = Js::isObject(Js::get($item, "creator")) ? $item->creator : new stdClass();
            $nickname = Js::get($creator, "nickname");
            $id       = Js::get($creator, "id");
            $short->creator = new stdClass();
            if (!Js::isUndef($nickname)) { $short->creator->nickname = Js::str($nickname); }
            if (!Js::isUndef($id))       { $short->creator->id = Js::str($id); }
            $userId = array_key_exists("userId", $context) ? $context["userId"] : Js::undef();
            $short->own = !Js::isUndef($id) && Js::str($id) === Js::str($userId);
        }

        $finish = self::describe($short, $item);

        $short->start = $span->start;
        $short->end   = $span->end;

        if ($kind === "overlays") {
            $area      = Util::box(Js::get($selector, "value"));
            $rotation  = Js::get($selector, "frametrail:rotation");
            $keyframes = Js::get($selector, "frametrail:keyframes");
            if ($area) { $short->box = $area; }
            if (Js::isNumber($rotation) && $rotation != 0) { $short->rotation = $rotation; }
            if (is_array($keyframes) && count($keyframes)) { $short->moving = true; }
        }

        return $finish();

    }


    /* ------------------------------------------------------------------ */
    /*  From an operation's input                                         */
    /* ------------------------------------------------------------------ */

    /**
     * The target selector of an overlay from its time, box, rotation and
     * keyframes, as FrameTrail writes it.
     */
    private static function overlaySelector($start, $end, $area, $rotation, $keyframes) {

        $selector = new stdClass();

        if (!Js::isUndef($keyframes)) {
            $selector->value = Util::fragment($start, $end, Keyframes::unionBox($keyframes, $start, $end));
            $selector->{"frametrail:keyframes"} = $keyframes;
        } else {
            $selector->value = Util::fragment($start, $end, $area);
            if (Js::isNumber($rotation) && $rotation != 0) {
                $selector->{"frametrail:rotation"} = $rotation;
            }
        }

        return $selector;

    }

    private static function invalid($path, $message) {
        return new FtConversationalUiOpError("invalid", "Invalid input", array(Js::obj(array("path" => $path, "message" => $message))));
    }

    /**
     * A new overlay (for the store's add) from add_overlay's input.
     *
     * @method newOverlay
     * @param {stdClass} $input
     * @param {Mixed} $generator
     * @return stdClass
     * @throws FtConversationalUiOpError
     */
    public static function newOverlay($input, $generator) {

        $keyframes = is_array(Js::get($input, "keyframes")) ? Keyframes::normalizeKeyframes($input->keyframes) : Js::undef();
        $rotation  = Js::get($input, "rotation");

        if (Js::isUndef($keyframes) && !Js::isObject(Js::get($input, "box"))) {
            throw self::invalid("/box", "is required without keyframes");
        }
        if (!Js::isUndef($keyframes) && Js::isNumber($rotation) && $rotation != 0) {
            throw self::invalid("/rotation", "cannot be given with keyframes, which carry their own rotation (r)");
        }

        $item = Js::obj(array(
            "target" => Js::obj(array("selector" => self::overlaySelector($input->start, $input->end, Js::get($input, "box"), $rotation, $keyframes))),
            "body"   => Js::copy(Js::get($input, "body"))
        ));

        if (Js::has($input, "tags"))   { $item->{"frametrail:tags"} = Js::copy($input->tags); }
        if (Js::has($input, "events")) { $item->{"frametrail:events"} = Js::copy($input->events); }
        if (!Js::isUndef($generator))  { $item->generator = Js::copy($generator); }

        return $item;

    }

    /**
     * The merge patch update_overlay's input makes of an overlay as stored;
     * undefined when nothing changes.
     *
     * @method overlayPatch
     * @param {stdClass} $current
     * @param {stdClass} $input
     * @param {Mixed} $generator
     * @return Mixed
     * @throws FtConversationalUiOpError
     */
    public static function overlayPatch($current, $input, $generator) {

        $patch = new stdClass();

        // The selector is written anew only when the input changes time or place.
        $placed = false;
        foreach (array("start", "end", "box", "rotation", "keyframes") as $key) {
            if (Js::has($input, $key)) { $placed = true; }
        }

        if ($placed) {

            $selector  = self::selectorOf($current);
            $span      = Util::timeSpan(Js::get($selector, "value"));
            $start     = Js::has($input, "start") ? $input->start : $span->start;
            $end       = Js::has($input, "end") ? $input->end : $span->end;
            $given     = Js::has($input, "keyframes");
            $moving    = Keyframes::normalizeKeyframes(Js::get($selector, "frametrail:keyframes"));
            $keyframes = $given ? (is_array($input->keyframes) ? Keyframes::normalizeKeyframes($input->keyframes) : Js::undef()) : $moving;
            $area      = Js::undef();
            $rotation  = Js::undef();

            if (!Js::isUndef($keyframes)) {
                if (Js::has($input, "box")) {
                    throw self::invalid("/box", "cannot be set while the overlay moves; change its keyframes, or stop the motion with keyframes: null");
                }
                if (Js::has($input, "rotation") && $input->rotation !== null && !Js::numEq($input->rotation, 0)) {
                    throw self::invalid("/rotation", "cannot be set while the overlay moves: its keyframes carry the rotation (r)");
                }
            } else {
                $stored   = Util::box(Js::get($selector, "value"));
                $area     = Js::has($input, "box") ? $input->box : ($stored ?: Js::obj(array("left" => 0, "top" => 0, "width" => 0, "height" => 0)));
                $rotation = Js::has($input, "rotation") ? $input->rotation : (!Js::isUndef($moving) ? Js::undef() : Js::get($selector, "frametrail:rotation"));
            }

            $next = self::overlaySelector($start, $end, $area, $rotation, $keyframes);
            $was  = Js::obj(array("value" => Js::get($selector, "value")));

            // Keyframes the input leaves alone stay as they are stored.
            if (!Js::isUndef($keyframes) && !$given) { $next->{"frametrail:keyframes"} = Js::copy($selector->{"frametrail:keyframes"}); }

            if (!Js::isUndef(Js::get($selector, "frametrail:keyframes"))) { $was->{"frametrail:keyframes"} = $selector->{"frametrail:keyframes"}; }
            if (!Js::isUndef(Js::get($selector, "frametrail:rotation")))  { $was->{"frametrail:rotation"} = $selector->{"frametrail:rotation"}; }

            $change = Util::diffPatch($was, $next);

            if (!Js::isUndef($change)) { $patch->target = Js::obj(array("selector" => $change)); }

        }

        if (Js::has($input, "body"))   { $patch->body = Js::copy($input->body); }
        if (Js::has($input, "tags"))   { $patch->{"frametrail:tags"} = Js::copy($input->tags); }
        if (Js::has($input, "events")) { $patch->{"frametrail:events"} = Js::copy($input->events); }

        return self::finishPatch($patch, $generator);

    }

    /**
     * A new annotation (for the store's add) from add_annotation's input.
     *
     * @method newAnnotation
     * @param {stdClass} $input
     * @param {Mixed} $generator
     * @return stdClass
     */
    public static function newAnnotation($input, $generator) {

        $item = Js::obj(array(
            "target" => Js::obj(array("selector" => Js::obj(array("value" => Util::fragment($input->start, $input->end))))),
            "body"   => Js::copy(Js::get($input, "body"))
        ));

        if (Js::has($input, "tags"))  { $item->{"frametrail:tags"} = Js::copy($input->tags); }
        if (!Js::isUndef($generator)) { $item->generator = Js::copy($generator); }

        return $item;

    }

    /**
     * The merge patch update_annotation's input makes of an annotation as
     * stored; undefined when nothing changes.
     *
     * @method annotationPatch
     * @param {stdClass} $current
     * @param {stdClass} $input
     * @param {Mixed} $generator
     * @return Mixed
     */
    public static function annotationPatch($current, $input, $generator) {

        $selector = self::selectorOf($current);
        $span     = Util::timeSpan(Js::get($selector, "value"));
        $value    = Util::fragment(Js::has($input, "start") ? $input->start : $span->start, Js::has($input, "end") ? $input->end : $span->end);
        $patch    = new stdClass();

        if ($value !== Js::get($selector, "value")) {
            $patch->target = Js::obj(array("selector" => Js::obj(array("value" => $value))));
        }
        if (Js::has($input, "body")) { $patch->body = Js::copy($input->body); }
        if (Js::has($input, "tags")) { $patch->{"frametrail:tags"} = Js::copy($input->tags); }

        return self::finishPatch($patch, $generator);

    }

    // A patch that changes something also records the generator; one that changes nothing is undefined.
    private static function finishPatch($patch, $generator) {
        if (!count(Js::ownKeys($patch))) { return Js::undef(); }
        if (!Js::isUndef($generator)) { $patch->generator = Js::copy($generator); }
        return $patch;
    }

}
