<?php

/*
 * FrameTrail-Conversational-UI — JavaScript's semantics in PHP, for the ports
 * of FrameTrail's pure scripts and of the operations (server/lib/): the
 * results must equal what the JavaScript gives, down to numbers in strings,
 * sort order and dates.
 *
 * JSON values are what json_decode() gives without its second argument:
 * objects are stdClass (so {} stays an object), arrays are lists. JavaScript's
 * undefined is FtConversationalUiJs::undef(): what get() returns for a missing
 * property, dropped where JSON would drop it (clone(), obj(), same()), refused
 * by stringify().
 */

if (!function_exists("ftExtensionStorage") && !defined("FT_CONVERSATIONAL_UI_LIB")) {
    http_response_code(404);
    exit;
}


/**
 * JavaScript's undefined. There is one: FtConversationalUiJs::undef().
 */
final class FtConversationalUiUndefined {
}


class FtConversationalUiJs {

    // JavaScript's white space and line terminators (\s, trim(), parseFloat()), for a character class.
    const SPACE = '\x{0009}\x{000A}\x{000B}\x{000C}\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    // The largest time value a JavaScript Date holds, in milliseconds.
    const MAX_TIME = 8.64e15;

    const MONTHS = array("jan" => 1, "feb" => 2, "mar" => 3, "apr" => 4, "may" => 5, "jun" => 6,
                         "jul" => 7, "aug" => 8, "sep" => 9, "oct" => 10, "nov" => 11, "dec" => 12);

    private static $undefined = null;


    /* ------------------------------------------------------------------ */
    /*  undefined                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * @method undef
     * @return FtConversationalUiUndefined
     */
    public static function undef() {
        if (self::$undefined === null) {
            self::$undefined = new FtConversationalUiUndefined();
        }
        return self::$undefined;
    }

    public static function isUndef($value) {
        return $value instanceof FtConversationalUiUndefined;
    }

    // value === undefined ? fallback : value
    public static function orDefault($value, $fallback) {
        return self::isUndef($value) ? $fallback : $value;
    }


    /* ------------------------------------------------------------------ */
    /*  JSON values                                                       */
    /* ------------------------------------------------------------------ */

    public static function isObject($value) {
        return $value instanceof stdClass;
    }

    public static function isList($value) {
        return is_array($value);
    }

    public static function isNumber($value) {
        return is_int($value) || is_float($value);
    }

    // typeof as error messages name it: null, array, object, string, number, boolean, undefined.
    public static function typeOf($value) {
        if ($value === null)          { return "null"; }
        if (is_array($value))         { return "array"; }
        if (self::isNumber($value))   { return "number"; }
        if (is_string($value))        { return "string"; }
        if (is_bool($value))          { return "boolean"; }
        if (self::isUndef($value))    { return "undefined"; }
        if ($value instanceof Closure) { return "function"; }
        return "object";
    }

    // An own property of an object that is not undefined.
    public static function has($obj, $key) {
        return ($obj instanceof stdClass) && property_exists($obj, (string)$key) && !self::isUndef($obj->{(string)$key});
    }

    // obj[key]: undefined for a missing key, and for anything that is not an object.
    public static function get($obj, $key) {
        if (is_array($obj)) {
            return (is_int($key) && $key >= 0 && $key < count($obj)) ? $obj[$key] : self::undef();
        }
        if (!($obj instanceof stdClass) || !property_exists($obj, (string)$key)) {
            return self::undef();
        }
        return $obj->{(string)$key};
    }

    public static function set($obj, $key, $value) {
        $obj->{(string)$key} = $value;
    }

    public static function delete($obj, $key) {
        unset($obj->{(string)$key});
    }

    /**
     * Object.keys(): the keys whose value is not undefined, in JavaScript's
     * order: array indices ("0", "17") ascending first, then the others as
     * they were added.
     *
     * @method keys
     * @param {stdClass} $obj
     * @return Array of Strings
     */
    public static function keys($obj) {

        if (!($obj instanceof stdClass)) { return array(); }

        $indices = array();
        $others  = array();

        foreach (get_object_vars($obj) as $key => $value) {
            $key = (string)$key;
            if (self::isUndef($value)) { continue; }
            if (self::isArrayIndex($key)) {
                $indices[] = $key;
            } else {
                $others[] = $key;
            }
        }

        usort($indices, function($a, $b) { return ((float)$a < (float)$b) ? -1 : 1; });

        return array_merge($indices, $others);

    }

    // The keys of an object in the order they were added (how PHP writes them).
    public static function ownKeys($obj) {
        $keys = array();
        if ($obj instanceof stdClass) {
            foreach (get_object_vars($obj) as $key => $value) {
                if (!self::isUndef($value)) { $keys[] = (string)$key; }
            }
        }
        return $keys;
    }

    private static function isArrayIndex($key) {
        return ($key === "0" || preg_match('/^[1-9][0-9]{0,9}$/', $key) === 1) && (float)$key <= 4294967294;
    }

    /**
     * An object of the pairs given, in order; undefined values are left out.
     *
     * @method obj
     * @param {Array} $pairs key => value
     * @return stdClass
     */
    public static function obj($pairs = array()) {
        $obj = new stdClass();
        foreach ($pairs as $key => $value) {
            if (!self::isUndef($value)) { $obj->{(string)$key} = $value; }
        }
        return $obj;
    }

    /**
     * A deep copy with JSON semantics: undefined properties are dropped
     * (undefined in a list becomes null), non-finite numbers become null.
     *
     * @method clone
     * @param {Mixed} $value
     * @return Mixed undefined for undefined
     */
    public static function copy($value) {

        if ($value instanceof stdClass) {
            $out = new stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                if (self::isUndef($item) || $item instanceof Closure) { continue; }
                $out->{(string)$key} = self::copy($item);
            }
            return $out;
        }

        if (is_array($value)) {
            $out = array();
            foreach ($value as $item) {
                $out[] = (self::isUndef($item) || $item instanceof Closure) ? null : self::copy($item);
            }
            return $out;
        }

        if (is_float($value) && !is_finite($value)) { return null; }

        return $value;

    }

    // A value as JSON writes it: non-finite numbers are null, functions undefined.
    private static function jsonValue($value) {
        if (is_float($value) && !is_finite($value)) { return null; }
        if ($value instanceof Closure) { return self::undef(); }
        return $value;
    }

    /**
     * Equality as JSON sees it: key order does not matter, undefined
     * properties do not exist, every number is a number (5 equals 5.0).
     *
     * @method same
     * @param {Mixed} $a
     * @param {Mixed} $b
     * @return Boolean
     */
    public static function same($a, $b) {

        $a = self::jsonValue($a);
        $b = self::jsonValue($b);

        if (self::isNumber($a) && self::isNumber($b)) { return $a == $b; }
        if (self::isUndef($a) || self::isUndef($b))   { return self::isUndef($a) && self::isUndef($b); }
        if (!is_array($a) && !($a instanceof stdClass) || !is_array($b) && !($b instanceof stdClass)) { return $a === $b; }
        if (is_array($a) !== is_array($b)) { return false; }

        if (is_array($a)) {
            if (count($a) !== count($b)) { return false; }
            foreach ($a as $i => $item) {
                $x = self::isUndef(self::jsonValue($item)) ? null : $item;
                $y = self::isUndef(self::jsonValue($b[$i])) ? null : $b[$i];
                if (!self::same($x, $y)) { return false; }
            }
            return true;
        }

        $keysA = array();
        foreach (get_object_vars($a) as $key => $value) {
            if (!self::isUndef(self::jsonValue($value))) { $keysA[] = (string)$key; }
        }
        $countB = 0;
        foreach (get_object_vars($b) as $key => $value) {
            if (!self::isUndef(self::jsonValue($value))) { $countB++; }
        }

        if (count($keysA) !== $countB) { return false; }

        foreach ($keysA as $key) {
            $other = self::get($b, $key);
            if (self::isUndef(self::jsonValue($other)) || !self::same($a->{$key}, $other)) { return false; }
        }

        return true;

    }

    /**
     * I read JSON text: objects as stdClass, so {} and [] stay apart.
     *
     * @method decode
     * @param {String} $text
     * @return Mixed
     * @throws Exception for text that is not JSON
     */
    public static function decode($text) {

        $value = json_decode($text);

        if ($value === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON: " . json_last_error_msg());
        }

        return $value;

    }

    /**
     * JSON.stringify(): numbers and strings as JavaScript writes them. With
     * $space, indented like JSON.stringify(value, null, space). Keys are
     * written in the order they were added. undefined and functions inside
     * objects are left out, inside lists they are null; non-finite numbers
     * are null. undefined itself gives undefined.
     *
     * @method stringify
     * @param {Mixed} $value
     * @param {Number} $space
     * @return String
     */
    public static function stringify($value, $space = 0) {
        return self::stringifyValue($value, ($space > 0) ? str_repeat(" ", min(10, (int)$space)) : "", "");
    }

    private static function stringifyValue($value, $gap, $indent) {

        $value = self::jsonValue($value);

        if (self::isUndef($value))  { return self::undef(); }
        if ($value === null)        { return "null"; }
        if ($value === true)        { return "true"; }
        if ($value === false)       { return "false"; }
        if (self::isNumber($value)) { return self::number($value); }
        if (is_string($value))      { return self::quote($value); }

        $inner = $indent . $gap;
        $parts = array();

        if (is_array($value)) {
            foreach ($value as $item) {
                $text = self::stringifyValue($item, $gap, $inner);
                $parts[] = self::isUndef($text) ? "null" : $text;
            }
            if (!count($parts)) { return "[]"; }
            return ($gap === "")
                ? "[" . implode(",", $parts) . "]"
                : "[\n" . $inner . implode(",\n" . $inner, $parts) . "\n" . $indent . "]";
        }

        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $text = self::stringifyValue($item, $gap, $inner);
                if (self::isUndef($text)) { continue; }
                $parts[] = self::quote((string)$key) . (($gap === "") ? ":" : ": ") . $text;
            }
            if (!count($parts)) { return "{}"; }
            return ($gap === "")
                ? "{" . implode(",", $parts) . "}"
                : "{\n" . $inner . implode(",\n" . $inner, $parts) . "\n" . $indent . "}";
        }

        throw new Exception("Cannot write a " . gettype($value) . " as JSON");

    }

    // A string as JSON.stringify() writes it.
    public static function quote($string) {
        return json_encode((string)$string, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE);
    }


    /* ------------------------------------------------------------------ */
    /*  Numbers                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * A number as JavaScript writes it (Number.prototype.toString): the
     * shortest digits that read back as the same number, 12.5, 0.1, 1e+21,
     * 1e-7, 120.
     *
     * @method number
     * @param {Number} $n
     * @return String
     */
    public static function number($n) {

        if (is_int($n)) { return (string)$n; }

        $n = (float)$n;

        if (is_nan($n))      { return "NaN"; }
        if (is_infinite($n)) { return ($n > 0) ? "Infinity" : "-Infinity"; }
        if ($n == 0)         { return "0"; }

        // The shortest round-trip digits: json_encode() with serialize_precision -1.
        $precision = ini_get("serialize_precision");
        ini_set("serialize_precision", "-1");
        $repr = json_encode(abs($n));
        ini_set("serialize_precision", $precision);

        if (!preg_match('/^([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/', $repr, $m)) {
            throw new Exception("Unexpected number " . $repr);
        }

        $whole    = $m[1];
        $fraction = isset($m[2]) ? $m[2] : "";
        $exponent = isset($m[3]) ? (int)$m[3] : 0;
        $digits   = ltrim($whole . $fraction, "0");
        $trimmed  = rtrim($digits, "0");
        $k        = strlen($trimmed);
        $point    = $k + (strlen($digits) - $k) + $exponent - strlen($fraction);
        $sign     = ($n < 0) ? "-" : "";

        if ($k <= $point && $point <= 21) {
            return $sign . $trimmed . str_repeat("0", $point - $k);
        }
        if (0 < $point && $point <= 21) {
            return $sign . substr($trimmed, 0, $point) . "." . substr($trimmed, $point);
        }
        if (-6 < $point && $point <= 0) {
            return $sign . "0." . str_repeat("0", -$point) . $trimmed;
        }

        $e = $point - 1;

        return $sign . substr($trimmed, 0, 1) . (($k > 1) ? "." . substr($trimmed, 1) : "")
            . "e" . (($e < 0) ? "-" : "+") . abs($e);

    }

    /**
     * String(value).
     *
     * @method str
     * @param {Mixed} $value
     * @return String
     */
    public static function str($value) {
        if (is_string($value))      { return $value; }
        if (self::isNumber($value)) { return self::number($value); }
        if ($value === true)        { return "true"; }
        if ($value === false)       { return "false"; }
        if ($value === null)        { return "null"; }
        if (self::isUndef($value))  { return "undefined"; }
        if (is_array($value)) {
            return implode(",", array_map(function($item) {
                return ($item === null || self::isUndef($item)) ? "" : self::str($item);
            }, $value));
        }
        return "[object Object]";
    }

    // isFinite(value) for a value that is a number.
    public static function isFinite($value) {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    public static function isNaN($value) {
        return is_float($value) && is_nan($value);
    }

    /**
     * parseFloat(value): the number at the start of String(value), NaN when
     * there is none.
     *
     * @method parseFloat
     * @param {Mixed} $value
     * @return Number
     */
    public static function parseFloat($value) {

        if (self::isFinite($value)) { return (float)$value; }

        $text = preg_replace('/^[' . self::SPACE . ']+/u', "", self::str($value));

        if (!preg_match('/^[+-]?(?:Infinity|[0-9]+\.?[0-9]*(?:[eE][+-]?[0-9]+)?|\.[0-9]+(?:[eE][+-]?[0-9]+)?)/', (string)$text, $m)) {
            return NAN;
        }

        $number = $m[0];

        if (substr($number, -8) === "Infinity") {
            return ($number[0] === "-") ? -INF : INF;
        }

        return (float)$number;

    }

    // parseInt(value, 10).
    public static function parseInt($value) {

        $text = preg_replace('/^[' . self::SPACE . ']+/u', "", self::str($value));

        if (!preg_match('/^([+-]?)([0-9]+)/', (string)$text, $m)) {
            return NAN;
        }

        $number = (float)$m[2];

        return ($m[1] === "-") ? -$number : $number;

    }

    // JavaScript's truthiness: false for undefined, null, false, 0, NaN and "".
    public static function truthy($value) {
        if ($value === null || $value === false || $value === "" || self::isUndef($value)) { return false; }
        if (self::isNumber($value)) { return $value != 0 && !self::isNaN($value); }
        return true;
    }

    // a || b
    public static function either($a, $b) {
        return self::truthy($a) ? $a : $b;
    }

    // a === b for two numbers (5 and 5.0 are the same number).
    public static function numEq($a, $b) {
        return self::isNumber($a) && self::isNumber($b) && $a == $b && !self::isNaN($a);
    }

    // a === b for JSON scalars: numbers by value, everything else as PHP's ===.
    public static function strictEq($a, $b) {
        if (self::isNumber($a) && self::isNumber($b)) { return self::numEq($a, $b); }
        return $a === $b;
    }

    /**
     * Math.round(): halves go up (-2.5 → -2).
     *
     * @method round
     * @param {Number} $x
     * @return Number
     */
    public static function round($x) {
        if (!self::isFinite($x)) { return $x; }
        $floor = floor($x);
        return ($x - $floor >= 0.5) ? $floor + 1 : $floor;
    }

    // Math.max() and Math.min(): NaN wins.
    public static function max() {
        $result = -INF;
        foreach (func_get_args() as $value) {
            if (self::isNaN($value)) { return NAN; }
            if ($value > $result) { $result = $value; }
        }
        return $result;
    }

    public static function min() {
        $result = INF;
        foreach (func_get_args() as $value) {
            if (self::isNaN($value)) { return NAN; }
            if ($value < $result) { $result = $value; }
        }
        return $result;
    }


    /* ------------------------------------------------------------------ */
    /*  Strings                                                           */
    /* ------------------------------------------------------------------ */

    // String.prototype.trim().
    public static function trim($text) {
        return preg_replace('/^[' . self::SPACE . ']+|[' . self::SPACE . ']+$/u', "", $text);
    }

    // text.length: UTF-16 code units.
    public static function length($text) {
        return preg_match_all('/./su', $text) + preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text);
    }

    // Array.from(text): the code points.
    public static function codePoints($text) {
        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * String.prototype.toLowerCase(). Without mbstring only A–Z are lowered.
     *
     * @method lower
     * @param {String} $text
     * @return String
     */
    public static function lower($text) {
        return function_exists("mb_strtolower") ? mb_strtolower($text, "UTF-8") : self::asciiLower($text);
    }

    public static function asciiLower($text) {
        return strtr($text, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz");
    }

    // String.fromCodePoint(), as UTF-8 (without mbstring).
    public static function fromCodePoint($code) {
        if ($code < 0x80)    { return chr($code); }
        if ($code < 0x800)   { return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F)); }
        if ($code < 0x10000) { return chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F)); }
        return chr(0xF0 | ($code >> 18)) . chr(0x80 | (($code >> 12) & 0x3F)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
    }


    /* ------------------------------------------------------------------ */
    /*  Lists                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Array.prototype.sort() with a comparator: stable (PHP's usort is not
     * before 8.0), the comparator's number read by its sign, NaN as 0.
     *
     * @method sort
     * @param {Array} $list
     * @param {Callable} $compare (a, b) → Number
     * @return Array a new list
     */
    public static function sort($list, $compare) {

        $decorated = array();
        foreach (array_values($list) as $i => $item) {
            $decorated[] = array($i, $item);
        }

        usort($decorated, function($a, $b) use ($compare) {
            $order = $compare($a[1], $b[1]);
            if (self::isNumber($order) && !self::isNaN($order) && $order != 0) {
                return ($order < 0) ? -1 : 1;
            }
            return $a[0] - $b[0];
        });

        return array_map(function($entry) { return $entry[1]; }, $decorated);

    }

    // Array.prototype.filter().
    public static function filter($list, $test) {
        return array_values(array_filter($list, $test));
    }

    // Array.prototype.indexOf() with ===.
    public static function indexOf($list, $value) {
        foreach ($list as $i => $item) {
            if (self::strictEq($item, $value)) { return $i; }
        }
        return -1;
    }


    /* ------------------------------------------------------------------ */
    /*  Dates                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * new Date(value).getTime(): milliseconds, NaN for what JavaScript cannot
     * read. Text is read in the forms FrameTrail meets: ISO 8601 (the Date
     * Time String Format), and what Date.prototype.toString() and
     * toUTCString() write. A time without offset is local time, a date alone
     * is UTC, as in JavaScript.
     *
     * @method dateParse
     * @param {Mixed} $value
     * @return Number
     */
    public static function dateParse($value) {

        if (self::isNumber($value)) {
            return self::timeClip((float)$value);
        }
        if (!is_string($value)) {
            return NAN;
        }

        $text = self::trim($value);

        // The Date Time String Format.
        if (preg_match('/^([+-][0-9]{6}|[0-9]{4})(?:-([0-9]{2})(?:-([0-9]{2}))?)?(?:T([0-9]{2}):([0-9]{2})(?::([0-9]{2})(?:\.([0-9]+))?)?(Z|[+-][0-9]{2}:[0-9]{2})?)?$/', $text, $m)) {

            if ($m[1] === "-000000") { return NAN; }

            $year   = (int)$m[1];
            $month  = (isset($m[2]) && $m[2] !== "") ? (int)$m[2] : 1;
            $day    = (isset($m[3]) && $m[3] !== "") ? (int)$m[3] : 1;
            $timed  = isset($m[4]) && $m[4] !== "";
            $hour   = $timed ? (int)$m[4] : 0;
            $minute = $timed ? (int)$m[5] : 0;
            $second = (isset($m[6]) && $m[6] !== "") ? (int)$m[6] : 0;
            $millis = (isset($m[7]) && $m[7] !== "") ? (int)substr(str_pad($m[7], 3, "0"), 0, 3) : 0;
            $zone   = isset($m[8]) ? $m[8] : "";

            if ($month < 1 || $month > 12 || $day < 1 || $day > self::daysInMonth($year, $month)
                    || $hour > 24 || $minute > 59 || $second > 59
                    || ($hour === 24 && ($minute > 0 || $second > 0 || $millis > 0))) {
                return NAN;
            }

            if ($timed && $zone === "") {
                return self::localTime($year, $month, $day, $hour, $minute, $second, $millis);
            }

            $offset = 0;
            if ($zone !== "" && $zone !== "Z") {
                $offset = ((int)substr($zone, 1, 2) * 60 + (int)substr($zone, 4, 2)) * (($zone[0] === "-") ? -1 : 1);
            }

            return self::timeClip(self::utcTime($year, $month, $day, $hour, $minute, $second, $millis) - $offset * 60000);

        }

        // Date.prototype.toString(): "Tue Oct 07 2025 14:03:12 GMT+0200 (Central European Summer Time)"
        // and toUTCString(): "Tue, 07 Oct 2025 12:03:12 GMT".
        $month = '(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*';
        $clock = '([0-9]{1,2}):([0-9]{2})(?::([0-9]{2}))?';
        $zone  = '(?:\s*(?:(GMT|UTC|Z)\s*([+-][0-9]{4})?|([+-][0-9]{4})))?(?:\s*\([^)]*\))?';

        if (preg_match('/^(?:[A-Za-z]{3}[a-z]*,?\s+)?' . $month . '\s+([0-9]{1,2}),?\s+([0-9]{4})(?:\s+' . $clock . $zone . ')?$/i', $text, $m)) {
            $parts = array($m[3], $m[1], $m[2], $m[4] ?? "", $m[5] ?? "", $m[6] ?? "", $m[7] ?? "", $m[8] ?? "", $m[9] ?? "");
        } else if (preg_match('/^(?:[A-Za-z]{3}[a-z]*,?\s+)?([0-9]{1,2})\s+' . $month . '\s+([0-9]{4})(?:\s+' . $clock . $zone . ')?$/i', $text, $m)) {
            $parts = array($m[3], $m[2], $m[1], $m[4] ?? "", $m[5] ?? "", $m[6] ?? "", $m[7] ?? "", $m[8] ?? "", $m[9] ?? "");
        } else {
            return NAN;
        }

        list($year, $monthName, $day, $hour, $minute, $second, $utc, $utcOffset, $offset) = $parts;

        $year   = (int)$year;
        $month  = self::MONTHS[strtolower(substr($monthName, 0, 3))];
        $day    = (int)$day;
        $hour   = ($hour !== "") ? (int)$hour : 0;
        $minute = ($minute !== "") ? (int)$minute : 0;
        $second = ($second !== "") ? (int)$second : 0;

        if ($day < 1 || $day > self::daysInMonth($year, $month) || $hour > 23 || $minute > 59 || $second > 59) {
            return NAN;
        }

        $zoneText = ($utcOffset !== "") ? $utcOffset : $offset;

        if ($utc === "" && $zoneText === "") {
            return self::localTime($year, $month, $day, $hour, $minute, $second, 0);
        }

        $minutes = 0;
        if ($zoneText !== "") {
            $minutes = ((int)substr($zoneText, 1, 2) * 60 + (int)substr($zoneText, 3, 2)) * (($zoneText[0] === "-") ? -1 : 1);
        }

        return self::timeClip(self::utcTime($year, $month, $day, $hour, $minute, $second, 0) - $minutes * 60000);

    }

    /**
     * new Date(ms).toISOString(), or null for a time JavaScript cannot hold.
     *
     * @method isoString
     * @param {Number} $ms
     * @return String|null
     */
    public static function isoString($ms) {

        $time = self::timeClip(self::isNumber($ms) ? (float)$ms : NAN);

        if (self::isNaN($time)) { return null; }

        $days   = (int)floor($time / 86400000);
        $inDay  = (int)($time - $days * 86400000);

        list($year, $month, $day) = self::civilFromDays($days);

        $yearText = ($year >= 0 && $year <= 9999)
            ? sprintf("%04d", $year)
            : (($year < 0) ? "-" : "+") . sprintf("%06d", abs($year));

        return sprintf("%s-%02d-%02dT%02d:%02d:%02d.%03dZ", $yearText, $month, $day,
            intdiv($inDay, 3600000), intdiv($inDay % 3600000, 60000), intdiv($inDay % 60000, 1000), $inDay % 1000);

    }

    // TimeClip(): NaN beyond ±8.64e15 ms, otherwise the whole milliseconds (as an integer).
    private static function timeClip($time) {
        if (!self::isFinite($time) || abs($time) > self::MAX_TIME) { return NAN; }
        return (int)(($time < 0) ? ceil($time) : floor($time));
    }

    private static function isLeapYear($year) {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    private static function daysInMonth($year, $month) {
        $days = array(31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
        return ($month === 2 && self::isLeapYear($year)) ? 29 : $days[$month - 1];
    }

    // Days since 1970-01-01 of a date in the proleptic Gregorian calendar.
    private static function daysFromCivil($year, $month, $day) {
        $year -= ($month <= 2) ? 1 : 0;
        $era   = intdiv(($year >= 0) ? $year : $year - 399, 400);
        $yoe   = $year - $era * 400;
        $doy   = intdiv(153 * ($month + (($month > 2) ? -3 : 9)) + 2, 5) + $day - 1;
        $doe   = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        return $era * 146097 + $doe - 719468;
    }

    private static function civilFromDays($days) {
        $days += 719468;
        $era   = intdiv(($days >= 0) ? $days : $days - 146096, 146097);
        $doe   = $days - $era * 146097;
        $yoe   = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $year  = $yoe + $era * 400;
        $doy   = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp    = intdiv(5 * $doy + 2, 153);
        $day   = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $month = $mp + (($mp < 10) ? 3 : -9);
        return array($year + (($month <= 2) ? 1 : 0), $month, $day);
    }

    private static function utcTime($year, $month, $day, $hour, $minute, $second, $millis) {
        return (float)self::daysFromCivil($year, $month, $day) * 86400000 + (($hour * 60 + $minute) * 60 + $second) * 1000 + $millis;
    }

    // A wall-clock time in the server's time zone (JavaScript's local time).
    private static function localTime($year, $month, $day, $hour, $minute, $second, $millis) {
        try {
            $date = new DateTime(sprintf("%04d-%02d-%02d %02d:%02d:%02d", $year, $month, $day, $hour, $minute, $second),
                new DateTimeZone(date_default_timezone_get()));
        } catch (Exception $e) {
            return NAN;
        }
        return self::timeClip((float)$date->getTimestamp() * 1000 + $millis);
    }

}
