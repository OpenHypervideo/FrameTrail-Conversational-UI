<?php

/*
 * FrameTrail-Conversational-UI — helpers of the operations, the PHP side of
 * client/ops/util.js: merge patches, errors, Media Fragments, plain text and
 * WebVTT cues, with the rules of shared/fixtures/README.md.
 */

if (!function_exists("ftExtensionStorage") && !defined("FT_CONVERSATIONAL_UI_LIB")) {
    http_response_code(404);
    exit;
}

use FtConversationalUiJs as Js;


/**
 * The error operations throw: errorCode "invalid" (with errors, each
 * { path, message }, the path a JSON Pointer into the input), "notFound",
 * "notAllowed" or "conflict". Its message says it all.
 */
class FtConversationalUiOpError extends Exception {

    public $errorCode;
    public $errors;

    public function __construct($code, $message, $errors = array()) {

        $list    = is_array($errors) ? $errors : array();
        $details = count($list)
            ? ": " . implode("; ", array_map(function($e) {
                $path = Js::get($e, "path");
                return (Js::truthy($path) ? $path : "(input)") . ": " . Js::get($e, "message");
            }, $list))
            : "";

        parent::__construct($message . $details);

        $this->errorCode = $code;
        $this->errors    = $list;

    }

}


class FtConversationalUiOpsUtil {

    // The character references plainText() decodes by name.
    const ENTITIES = array("amp" => "&", "lt" => "<", "gt" => ">", "quot" => "\"", "apos" => "'", "nbsp" => " ");

    // A tag (a letter after < or </) or a comment; "a < b" is text.
    const TAG = '/<!--[\s\S]*?-->|<\/?[A-Za-z][^>]*>/u';


    /* ------------------------------------------------------------------ */
    /*  JSON                                                              */
    /* ------------------------------------------------------------------ */

    // A JSON Pointer token (RFC 6901).
    public static function pointerToken($key) {
        return str_replace(array("~", "/"), array("~0", "~1"), Js::str($key));
    }

    /**
     * RFC 7386 JSON Merge Patch.
     *
     * @method mergePatch
     * @param {Mixed} $target
     * @param {Mixed} $patch
     * @return Mixed
     */
    public static function mergePatch($target, $patch) {

        if (!Js::isObject($patch)) { return Js::copy($patch); }

        $result = Js::isObject($target) ? Js::copy($target) : new stdClass();

        foreach (Js::ownKeys($patch) as $key) {
            if ($patch->{$key} === null) {
                unset($result->{$key});
            } else {
                $result->{$key} = self::mergePatch(Js::get($result, $key), $patch->{$key});
            }
        }

        return $result;

    }

    /**
     * The merge patch that turns from into to, or undefined when they are the
     * same: objects key by key, everything else replaced whole, a key to
     * lacks (or has as null) becomes null.
     *
     * @method diffPatch
     * @param {Mixed} $from
     * @param {Mixed} $to
     * @return Mixed
     */
    public static function diffPatch($from, $to) {

        if (Js::same($from, $to)) { return Js::undef(); }
        if (!Js::isObject($from) || !Js::isObject($to)) { return ($to === null) ? null : Js::copy($to); }

        $patch = new stdClass();

        foreach (Js::ownKeys($from) as $key) {
            if (!Js::has($to, $key) || ($to->{$key} === null && $from->{$key} !== null)) {
                $patch->{$key} = null;
            }
        }

        foreach (Js::ownKeys($to) as $key) {
            if ($to->{$key} === null) { continue; }
            $change = self::diffPatch(Js::has($from, $key) ? $from->{$key} : Js::undef(), $to->{$key});
            if (!Js::isUndef($change)) { $patch->{$key} = $change; }
        }

        return $patch;

    }


    /* ------------------------------------------------------------------ */
    /*  Numbers and Media Fragments                                       */
    /* ------------------------------------------------------------------ */

    // Seconds rounded to the millisecond.
    public static function seconds($value) {
        return Js::round($value * 1000) / 1000;
    }

    // "t=12.5,20&…" → { start: 12.5, end: 20 }; end is start for a point.
    public static function timeSpan($value) {
        if (!preg_match('/(?:^|&)t=([0-9.eE+-]+)(?:,([0-9.eE+-]+))?/', Js::str(Js::either($value, "")), $m)) {
            return Js::obj(array("start" => 0, "end" => 0));
        }
        $start = Js::parseFloat($m[1]);
        $end   = (isset($m[2]) && $m[2] !== "") ? Js::parseFloat($m[2]) : $start;
        return Js::obj(array("start" => Js::isFinite($start) ? $start : 0, "end" => Js::isFinite($end) ? $end : 0));
    }

    // "…&xywh=percent:10,20,30,40" → { left, top, width, height }, or null.
    public static function box($value) {
        $n = '(-?[0-9.]+(?:[eE][-+]?[0-9]+)?)';
        if (!preg_match('/xywh=percent:' . $n . ',' . $n . ',' . $n . ',' . $n . '/', Js::str(Js::either($value, "")), $m)) {
            return null;
        }
        return Js::obj(array(
            "left"   => Js::parseFloat($m[1]),
            "top"    => Js::parseFloat($m[2]),
            "width"  => Js::parseFloat($m[3]),
            "height" => Js::parseFloat($m[4])
        ));
    }

    // The Media Fragments value of an overlay or annotation; numbers as JavaScript writes them.
    public static function fragment($start, $end, $area = null) {
        $value = "t=" . Js::str($start) . "," . Js::str($end);
        if (Js::truthy($area)) {
            $value .= "&xywh=percent:" . implode(",", array_map(array("FtConversationalUiJs", "str"),
                array(Js::get($area, "left"), Js::get($area, "top"), Js::get($area, "width"), Js::get($area, "height"))));
        }
        return $value;
    }

    /**
     * Where a hypervideo's time begins (its clip's in point) and how long it
     * is: { start, duration }, duration null when nothing says.
     *
     * @method clipSpan
     * @param {Array} $clips
     * @param {Number} $mediaDuration
     * @return stdClass
     */
    public static function clipSpan($clips, $mediaDuration = null) {

        $clip   = (is_array($clips) && count($clips) && Js::isObject($clips[0])) ? $clips[0] : new stdClass();
        $in     = Js::get($clip, "in");
        $inTime = Js::isNumber($in) ? $in : 0;
        $length = Js::get($clip, "duration");
        $media  = (Js::isNumber($mediaDuration) && $mediaDuration > 0)
            ? $mediaDuration
            : ((Js::isNumber($length) && $length > 0) ? $length : null);
        $outRaw = Js::get($clip, "out");
        $out    = (Js::isNumber($outRaw) && $outRaw > 0) ? $outRaw : $media;

        return Js::obj(array(
            "start"    => $inTime,
            "duration" => ($out !== null && $out > $inTime) ? self::seconds($out - $inTime) : null
        ));

    }


    /* ------------------------------------------------------------------ */
    /*  Text                                                              */
    /* ------------------------------------------------------------------ */

    // These character references only, so both sides decode alike.
    public static function decodeEntities($text) {
        return preg_replace_callback('/&(#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[a-zA-Z]+);/', function($m) {
            $name = $m[1];
            if ($name[0] === "#") {
                $code = ($name[1] === "x" || $name[1] === "X") ? hexdec(substr($name, 2)) : (int)substr($name, 1);
                return ($code > 0 && $code <= 0x10FFFF && !($code >= 0xD800 && $code <= 0xDFFF)) ? Js::fromCodePoint($code) : $m[0];
            }
            $lower = Js::asciiLower($name);
            return array_key_exists($lower, self::ENTITIES) ? self::ENTITIES[$lower] : $m[0];
        }, $text);
    }

    /**
     * Plain text of HTML, also of HTML stored escaped: references decoded,
     * tags and comments removed, decoded again, white space collapsed.
     *
     * @method plainText
     * @param {Mixed} $html
     * @return String
     */
    public static function plainText($html) {
        if (!is_string($html)) { return ""; }
        $text = self::decodeEntities(preg_replace(self::TAG, " ", self::decodeEntities($html)));
        return Js::trim(preg_replace('/[' . Js::SPACE . ']+/u', " ", $text));
    }

    // The first max characters (code points) of a text, with … when cut.
    public static function excerpt($text, $max) {
        $chars = Js::codePoints($text);
        if (count($chars) <= $max) { return $text; }
        return preg_replace('/[' . Js::SPACE . ']+$/u', "", implode("", array_slice($chars, 0, $max))) . "…";
    }


    /* ------------------------------------------------------------------ */
    /*  WebVTT                                                            */
    /* ------------------------------------------------------------------ */

    private static function timing() {
        $stamp = '((?:[0-9]+:)?[0-9]{1,2}:[0-9]{2}[.,][0-9]{1,3})';
        $space = '[' . Js::SPACE . ']';
        return '/^' . $space . '*' . $stamp . $space . '+-->' . $space . '+' . $stamp . '/u';
    }

    private static function vttSeconds($stamp) {
        $total = 0;
        foreach (explode(":", preg_replace('/,/', ".", $stamp, 1)) as $part) {
            $total = $total * 60 + Js::parseFloat($part);
        }
        return self::seconds($total);
    }

    /**
     * The cues of a WebVTT text: { start, end, text }, text as plain text on
     * one line (see client/ops/util.js).
     *
     * @method cues
     * @param {Mixed} $vtt
     * @return Array
     */
    public static function cues($vtt) {

        if (!is_string($vtt)) { return array(); }

        $timing = self::timing();
        $lines  = explode("\n", preg_replace('/\r\n?/', "\n", preg_replace('/^\x{FEFF}/u', "", $vtt)));
        $blocks = array();
        $block  = array();

        foreach ($lines as $line) {
            if (Js::trim($line) === "") {
                if (count($block)) { $blocks[] = $block; $block = array(); }
            } else {
                $block[] = $line;
            }
        }
        if (count($block)) { $blocks[] = $block; }

        $result = array();

        foreach ($blocks as $lines) {
            $at = preg_match($timing, $lines[0]) ? 0 : ((count($lines) > 1 && preg_match($timing, $lines[1])) ? 1 : -1);
            if ($at < 0) { continue; }
            preg_match($timing, $lines[$at], $m);
            $text = self::plainText(implode("\n", array_slice($lines, $at + 1)));
            if ($text === "") { continue; }
            $result[] = Js::obj(array("start" => self::vttSeconds($m[1]), "end" => self::vttSeconds($m[2]), "text" => $text));
        }

        return $result;

    }

}
