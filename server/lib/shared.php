<?php

/*
 * FrameTrail-Conversational-UI — the data the server part reads at run time:
 * the files of shared/ (the operations manifest, the changeset schema, the
 * lint rules), which the build copies to build/server/shared/, and FrameTrail's
 * schemas (frametrail/schemas.json, from scripts/vendor-schemas.php).
 */

if (!function_exists("ftExtensionStorage") && !defined("FT_CONVERSATIONAL_UI_LIB")) {
    http_response_code(404);
    exit;
}


class FtConversationalUiShared {

    private static $cache     = array();
    private static $validator = null;

    /**
     * Where a file of shared/ is: next to lib/ in the build and in an
     * installation (_server/extensions/conversational-ui/shared/), at the
     * repository's root in a working copy.
     *
     * @method path
     * @param {String} $name
     * @return String
     */
    public static function path($name) {
        $built = dirname(__DIR__) . "/shared/" . $name;
        return is_file($built) ? $built : dirname(__DIR__, 2) . "/shared/" . $name;
    }

    /**
     * A file of shared/, parsed (objects as stdClass), read once.
     *
     * @method json
     * @param {String} $name
     * @return Mixed
     */
    public static function json($name) {
        if (!array_key_exists($name, self::$cache)) {
            $file = self::path($name);
            if (!is_file($file)) { throw new Exception("FrameTrail-Conversational-UI: missing shared/" . $name); }
            self::$cache[$name] = FtConversationalUiJs::decode(file_get_contents($file));
        }
        return self::$cache[$name];
    }

    /**
     * FrameTrail's schemas, parsed.
     *
     * @method frameTrailSchemas
     * @return Array
     */
    public static function frameTrailSchemas() {
        if (!array_key_exists("\0frametrail", self::$cache)) {
            self::$cache["\0frametrail"] = FtConversationalUiJs::decode(file_get_contents(__DIR__ . "/frametrail/schemas.json"));
        }
        return self::$cache["\0frametrail"];
    }

    /**
     * A validator that knows FrameTrail's schemas.
     *
     * @method validator
     * @return FtConversationalUiSchema
     */
    public static function validator() {
        if (self::$validator === null) {
            self::$validator = FtConversationalUiSchema::create(self::frameTrailSchemas());
        }
        return self::$validator;
    }

}
