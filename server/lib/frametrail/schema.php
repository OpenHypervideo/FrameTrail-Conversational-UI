<?php

/*
 * FrameTrail-Conversational-UI — a PHP port of FrameTrail's FrameTrailSchema
 * (src/_shared/frametrail-core/schema/FrameTrailSchema.js): a validator for
 * the subset of JSON Schema 2020-12 FrameTrail's schemas use, with the same
 * errors ({ path, message }: the path a JSON Pointer, the messages of fixed
 * templates, the meant alternative of a failed oneOf). FrameTrail's
 * tests/README.md states the rules; FrameTrail's version is the reference.
 *
 *     $validator = FtConversationalUiSchema::create($schemas);   // parsed schema documents (stdClass)
 *     $validator->validate("hypervideo.schema.json", $json);      // array() when valid
 *
 * Patterns are read as PCRE with the u and D modifiers, which agrees with
 * JavaScript's u flag for the syntax both have (FrameTrail's schemas use no
 * other).
 */

if (!function_exists("ftExtensionStorage") && !defined("FT_CONVERSATIONAL_UI_LIB")) {
    http_response_code(404);
    exit;
}

use FtConversationalUiJs as Js;


class FtConversationalUiSchema {

    // What a schema name given to validate() is resolved against.
    const BASE = "https://frametrail.org/schemas/1/";

    // The keywords of the subset, by what their value is.
    const KEYWORDS = array(
        '$schema'              => "value",
        '$id'                  => "value",
        '$defs'                => "map",
        '$ref'                 => "value",
        "title"                => "value",
        "description"          => "value",
        "default"              => "value",
        "type"                 => "value",
        "properties"           => "map",
        "required"             => "value",
        "additionalProperties" => "schema",
        "items"                => "schema",
        "minItems"             => "value",
        "maxItems"             => "value",
        "enum"                 => "value",
        "const"                => "value",
        "minimum"              => "value",
        "maximum"              => "value",
        "pattern"              => "value",
        "oneOf"                => "list"
    );

    const TYPES = array("object", "array", "string", "number", "integer", "boolean", "null");

    private $documents      = array();
    private $base           = self::BASE;
    private $discriminators = array();
    private $patterns       = array();
    private $references     = array();


    /**
     * I make a validator for a set of schema documents, each with an absolute
     * $id. I throw for a schema outside the subset, a malformed keyword value
     * or a reference to nothing.
     *
     * @method create
     * @param {Array} $schemas parsed schema documents
     * @param {Array} $options array("base" => …)
     * @return FtConversationalUiSchema
     */
    public static function create($schemas, $options = array()) {
        return new self($schemas, $options);
    }

    public function __construct($schemas, $options = array()) {

        if (isset($options["base"])) { $this->base = $options["base"]; }

        foreach ((array)$schemas as $schema) {
            $id = Js::get($schema, '$id');
            if (!Js::isObject($schema) || !is_string($id) || !preg_match('/^[a-z][a-z0-9+.-]*:/i', $id)) {
                throw new Exception("A schema document needs an absolute \$id");
            }
            $id = explode("#", $id)[0];
            if (isset($this->documents[$id])) { throw new Exception("Two schemas with the \$id " . $id); }
            $this->documents[$id] = $schema;
        }

        foreach ($this->documents as $id => $schema) {
            $this->checkSchema($schema, (string)$id, "");
        }

    }

    /**
     * I validate a document against a schema.
     *
     * @method validate
     * @param {String} $schema the schema's $id, or a reference resolved against the base
     * @param {Mixed} $data parsed JSON
     * @return Array array() when valid, otherwise of stdClass { path, message }
     */
    public function validate($schema, $data) {
        $target = $this->resolve($schema, $this->base);
        return $this->unique($this->check($target[0], $target[1], $data, ""));
    }

    /**
     * I tell whether a schema reference can be resolved.
     *
     * @method has
     * @param {String} $schema
     * @return Boolean
     */
    public function has($schema) {
        try {
            $this->resolve($schema, $this->base);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }


    /* ------------------------------------------------------------------ */
    /*  Helpers                                                           */
    /* ------------------------------------------------------------------ */

    private static function hasType($type, $value) {
        switch ($type) {
            case "integer": return Js::isFinite($value) && floor($value) == $value;
            case "number":  return Js::isFinite($value);
            case "object":  return Js::isObject($value);
            default:        return Js::typeOf($value) === $type;
        }
    }

    private static function types($type) {
        return is_array($type) ? $type : array($type);
    }

    private static function typeList($types) {
        $types = self::types($types);
        return (count($types) < 2)
            ? implode("", $types)
            : implode(", ", array_slice($types, 0, -1)) . " or " . $types[count($types) - 1];
    }

    private static function pointerToken($key) {
        return str_replace(array("~", "/"), array("~0", "~1"), (string)$key);
    }

    private static function error($path, $message) {
        return Js::obj(array("path" => $path, "message" => $message));
    }

    // A reference resolved against a base URI (RFC 3986, as far as schema ids need it).
    private static function resolveUri($ref, $base) {

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $ref)) { return $ref; }

        $hash     = strpos($ref, "#");
        $path     = ($hash !== false) ? substr($ref, 0, $hash) : $ref;
        $fragment = ($hash !== false) ? substr($ref, $hash) : "";
        $document = explode("#", $base)[0];

        if ($path === "") { return $document . $fragment; }

        preg_match('/^([a-z][a-z0-9+.-]*:\/\/[^\/?#]*)?(.*)$/is', $document, $m);

        $origin   = isset($m[1]) ? $m[1] : "";
        $joined   = ($path[0] === "/") ? $path : preg_replace('/[^\/]*$/', "", $m[2]) . $path;
        $all      = explode("/", $joined);
        $last     = count($all) - 1;
        $segments = array();

        foreach ($all as $i => $segment) {
            if ($segment === ".") {
                if ($i === $last) { $segments[] = ""; }
            } else if ($segment === "..") {
                if (count($segments) > 1) { array_pop($segments); }
                if ($i === $last) { $segments[] = ""; }
            } else {
                $segments[] = $segment;
            }
        }

        return $origin . implode("/", $segments) . $fragment;

    }

    // I find the schema a reference points at: array(schema, base), or throw.
    private function resolve($ref, $from) {

        $cacheKey = $from . " " . $ref;

        if (!array_key_exists($cacheKey, $this->references)) {
            $this->references[$cacheKey] = $this->lookup($ref, $from);
        }

        return $this->references[$cacheKey];

    }

    private function lookup($ref, $from) {

        $uri      = self::resolveUri($ref, $from);
        $hash     = strpos($uri, "#");
        $id       = ($hash !== false) ? substr($uri, 0, $hash) : $uri;
        $fragment = ($hash !== false) ? substr($uri, $hash + 1) : "";

        if (!isset($this->documents[$id])) {
            throw new Exception("Cannot resolve \"" . $ref . "\" from " . $from . ": no schema " . $id);
        }
        if ($fragment !== "" && $fragment[0] !== "/") {
            throw new Exception("Cannot resolve \"" . $ref . "\" from " . $from . ": only JSON Pointer fragments are supported");
        }

        $node = $this->documents[$id];

        foreach (array_slice(explode("/", $fragment), 1) as $token) {
            $key  = str_replace(array("~1", "~0"), array("/", "~"), rawurldecode($token));
            $node = (is_array($node) && preg_match('/^(0|[1-9][0-9]*)$/', $key)) ? Js::get($node, (int)$key) : Js::get($node, $key);
            if (Js::isUndef($node)) { throw new Exception("Cannot resolve \"" . $ref . "\" from " . $from); }
        }

        return array($node, $id);

    }

    // I follow a schema that is only a reference to what it refers to.
    private function deref($schema, $from) {
        $hops = 0;
        while (Js::isObject($schema) && is_string(Js::get($schema, '$ref'))) {
            if (++$hops > 32) { throw new Exception("Reference loop at " . $schema->{'$ref'}); }
            $target = $this->resolve($schema->{'$ref'}, $from);
            $schema = $target[0];
            $from   = $target[1];
        }
        return array($schema, $from);
    }

    /**
     * The PCRE form of a pattern, or null when it cannot be compiled: the u
     * modifier (code points, like JavaScript's u flag), D ($ only at the
     * end), and \uXXXX and \u{X} as \x{…}.
     */
    private function pattern($source) {

        if (!array_key_exists($source, $this->patterns)) {
            $pcre = preg_replace(array('/(?<!\\\\)((?:\\\\\\\\)*)\\\\u\\{([0-9A-Fa-f]+)\\}/', '/(?<!\\\\)((?:\\\\\\\\)*)\\\\u([0-9A-Fa-f]{4})/'), '$1\\x{$2}', $source);
            $pcre = "\x01" . $pcre . "\x01uD";
            $this->patterns[$source] = (@preg_match($pcre, "") === false) ? null : $pcre;
        }

        return $this->patterns[$source];

    }


    /* ------------------------------------------------------------------ */
    /*  Checking the schemas                                              */
    /* ------------------------------------------------------------------ */

    private function checkSchema($schema, $id, $where) {

        if (is_bool($schema)) { return; }

        $at = " at " . $id . "#" . $where;

        if (!Js::isObject($schema)) { throw new Exception("Not a schema" . $at); }

        foreach (get_object_vars($schema) as $keyword => $value) {

            $keyword = (string)$keyword;
            $place   = $where . "/" . self::pointerToken($keyword);

            if (!array_key_exists($keyword, self::KEYWORDS)) { throw new Exception("Unsupported keyword \"" . $keyword . "\"" . $at); }

            switch ($keyword) {
                case "type":
                    $types = self::types($value);
                    if (!count($types) || count(array_filter($types, function($type) { return !in_array($type, self::TYPES, true); }))) {
                        throw new Exception("Invalid type " . Js::stringify($value) . $at);
                    }
                    break;
                case "required":
                    if (!is_array($value) || count(array_filter($value, function($key) { return !is_string($key); }))) {
                        throw new Exception("\"required\" must be a list of names" . $at);
                    }
                    break;
                case "enum":
                    if (!is_array($value) || !count($value)) { throw new Exception("\"enum\" must be a non-empty list" . $at); }
                    break;
                case "minimum":
                case "maximum":
                    if (!Js::isNumber($value)) { throw new Exception("\"" . $keyword . "\" must be a number" . $at); }
                    break;
                case "minItems":
                case "maxItems":
                    if (!self::hasType("integer", $value) || $value < 0) { throw new Exception("\"" . $keyword . "\" must be a non-negative integer" . $at); }
                    break;
                case "pattern":
                    if (!is_string($value)) { throw new Exception("\"pattern\" must be a string" . $at); }
                    if ($this->pattern($value) === null) { throw new Exception("Invalid pattern " . Js::stringify($value) . $at); }
                    break;
                case '$ref':
                    if (!is_string($value)) { throw new Exception("\"\$ref\" must be a string" . $at); }
                    $this->resolve($value, $id);
                    break;
            }

            switch (self::KEYWORDS[$keyword]) {
                case "schema":
                    $this->checkSchema($value, $id, $place);
                    break;
                case "map":
                    if (!Js::isObject($value)) { throw new Exception("\"" . $keyword . "\" must be an object" . $at); }
                    foreach (get_object_vars($value) as $key => $sub) {
                        $this->checkSchema($sub, $id, $place . "/" . self::pointerToken($key));
                    }
                    break;
                case "list":
                    if (!is_array($value) || !count($value)) { throw new Exception("\"" . $keyword . "\" must be a non-empty list" . $at); }
                    foreach ($value as $i => $item) { $this->checkSchema($item, $id, $place . "/" . $i); }
                    break;
            }

        }

    }


    /* ------------------------------------------------------------------ */
    /*  Validating                                                        */
    /* ------------------------------------------------------------------ */

    // The property that tells the alternatives of a oneOf apart, if there is one.
    private function discriminator($schema, $from) {

        $cacheKey = spl_object_id($schema);

        if (array_key_exists($cacheKey, $this->discriminators)) { return $this->discriminators[$cacheKey]; }

        $branches = array();
        foreach ($schema->oneOf as $branch) {
            $branches[] = $this->deref($branch, $from)[0];
        }

        $fixes = function($branch, $key) {
            $properties = Js::get($branch, "properties");
            $property   = Js::get($properties, $key);
            $required   = Js::get($branch, "required");
            return Js::isObject($branch) && Js::isObject($properties) && Js::isObject($property)
                && Js::has($property, "const")
                && is_array($required) && in_array($key, $required, true);
        };

        $found = null;

        if (Js::isObject($branches[0]) && Js::isObject(Js::get($branches[0], "properties"))) {
            foreach (Js::keys($branches[0]->properties) as $key) {
                $all = true;
                foreach ($branches as $branch) {
                    if (!$fixes($branch, $key)) { $all = false; break; }
                }
                if (!$all) { continue; }
                $values = array();
                foreach ($branches as $branch) { $values[] = $branch->properties->{$key}->{"const"}; }
                $distinct = true;
                foreach ($values as $i => $value) {
                    if (self::indexOfJSON($values, $value) !== $i) { $distinct = false; break; }
                }
                if (!$distinct) { continue; }
                $found = array("key" => $key, "values" => $values);
                break;
            }
        }

        $this->discriminators[$cacheKey] = $found;

        return $found;

    }

    private static function indexOfJSON($list, $value) {
        foreach ($list as $i => $item) {
            if (Js::same($item, $value)) { return $i; }
        }
        return -1;
    }

    private static function fewest($results) {
        $best = $results[0];
        foreach ($results as $result) {
            if (count($result["errors"]) < count($best["errors"])) { $best = $result; }
        }
        return $best["errors"];
    }

    private function checkOneOf($schema, $from, $data, $path) {

        $branches   = $schema->oneOf;
        $key        = $this->discriminator($schema, $from);
        $candidates = array();

        if ($key && Js::isObject($data)) {
            $keyPath = $path . "/" . self::pointerToken($key["key"]);
            if (!Js::has($data, $key["key"])) { return array(self::error($keyPath, "is required")); }
            foreach ($key["values"] as $i => $value) {
                if (Js::same($value, $data->{$key["key"]})) { $candidates[] = $i; }
            }
            if (!count($candidates)) {
                return array(self::error($keyPath, "must be one of " . implode(", ", array_map(array("FtConversationalUiJs", "stringify"), $key["values"]))));
            }
        } else {
            $candidates = array_keys($branches);
        }

        $results = array();
        foreach ($candidates as $i) {
            $results[] = array("branch" => $i, "errors" => $this->check($branches[$i], $from, $data, $path));
        }

        $matching = count(array_filter($results, function($result) { return !count($result["errors"]); }));

        if ($matching === 1) { return array(); }
        if ($matching > 1)   { return array(self::error($path, "must match exactly one of the alternatives, matches " . $matching)); }

        if ($key && Js::isObject($data)) { return self::fewest($results); }

        $declared = function($i) use ($branches, $from) {
            $target = $this->deref($branches[$i], $from)[0];
            return (Js::isObject($target) && Js::has($target, "type")) ? self::types($target->type) : null;
        };

        $fitting = array();
        foreach ($results as $result) {
            $types = $declared($result["branch"]);
            if ($types && count(array_filter($types, function($type) use ($data) { return self::hasType($type, $data); }))) {
                $fitting[] = $result;
            }
        }

        if (count($fitting)) { return self::fewest($fitting); }

        $types = array();
        foreach ($branches as $i => $branch) {
            foreach ($declared($i) ?: array() as $type) {
                if (!in_array($type, $types, true)) { $types[] = $type; }
            }
        }

        return count($types)
            ? array(self::error($path, "must be " . self::typeList($types) . ", is " . Js::typeOf($data)))
            : self::fewest($results);

    }

    private function check($schema, $from, $data, $path) {

        if ($schema === true)  { return array(); }
        if ($schema === false) { return array(self::error($path, "is not allowed")); }

        $errors = array();

        if (Js::has($schema, '$ref')) {
            $target = $this->resolve($schema->{'$ref'}, $from);
            $errors = array_merge($errors, $this->check($target[0], $target[1], $data, $path));
        }

        if (Js::has($schema, "type")) {
            $fits = false;
            foreach (self::types($schema->type) as $type) {
                if (self::hasType($type, $data)) { $fits = true; break; }
            }
            if (!$fits) {
                $errors[] = self::error($path, "must be " . self::typeList($schema->type) . ", is " . Js::typeOf($data));
                return $errors;
            }
        }

        if (Js::has($schema, "const") && !Js::same($schema->{"const"}, $data)) {
            $errors[] = self::error($path, "must be " . Js::stringify($schema->{"const"}));
        }

        if (Js::has($schema, "enum") && self::indexOfJSON($schema->{"enum"}, $data) < 0) {
            $errors[] = self::error($path, "must be one of " . implode(", ", array_map(array("FtConversationalUiJs", "stringify"), $schema->{"enum"})));
        }

        if (Js::isNumber($data)) {
            if (Js::has($schema, "minimum") && $data < $schema->minimum) { $errors[] = self::error($path, "must be >= " . Js::number($schema->minimum)); }
            if (Js::has($schema, "maximum") && $data > $schema->maximum) { $errors[] = self::error($path, "must be <= " . Js::number($schema->maximum)); }
        }

        if (is_string($data) && Js::has($schema, "pattern") && !preg_match($this->pattern($schema->pattern), $data)) {
            $errors[] = self::error($path, "must match the pattern " . $schema->pattern);
        }

        if (is_array($data)) {
            if (Js::has($schema, "minItems") && count($data) < $schema->minItems) {
                $errors[] = self::error($path, "must have at least " . Js::number($schema->minItems) . (($schema->minItems == 1) ? " item" : " items"));
            }
            if (Js::has($schema, "maxItems") && count($data) > $schema->maxItems) {
                $errors[] = self::error($path, "must have at most " . Js::number($schema->maxItems) . (($schema->maxItems == 1) ? " item" : " items"));
            }
            if (Js::has($schema, "items")) {
                foreach ($data as $i => $item) {
                    $errors = array_merge($errors, $this->check($schema->items, $from, $item, $path . "/" . $i));
                }
            }
        }

        if (Js::isObject($data)) {

            $properties = Js::isObject(Js::get($schema, "properties")) ? $schema->properties : new stdClass();

            foreach ((Js::has($schema, "required") ? $schema->required : array()) as $key) {
                if (!Js::has($data, $key)) { $errors[] = self::error($path . "/" . self::pointerToken($key), "is required"); }
            }

            foreach (Js::keys($properties) as $key) {
                if (Js::has($data, $key)) {
                    $errors = array_merge($errors, $this->check($properties->{$key}, $from, $data->{$key}, $path . "/" . self::pointerToken($key)));
                }
            }

            if (Js::has($schema, "additionalProperties")) {
                foreach (Js::keys($data) as $key) {
                    if (Js::has($properties, $key)) { continue; }
                    $errors = array_merge($errors, $this->check($schema->additionalProperties, $from, $data->{$key}, $path . "/" . self::pointerToken($key)));
                }
            }

        }

        if (Js::has($schema, "oneOf")) {
            $errors = array_merge($errors, $this->checkOneOf($schema, $from, $data, $path));
        }

        return $errors;

    }

    // The same error can be found twice, e.g. a required discriminator.
    private function unique($errors) {
        $seen = array();
        $out  = array();
        foreach ($errors as $e) {
            $key = $e->path . "\0" . $e->message;
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $out[] = $e;
        }
        return $out;
    }

}
