<?php

/*
 * FrameTrail-Conversational-UI — a PHP port of FrameTrail's FrameTrailKeyframes
 * (src/_shared/frametrail-core/serialization/FrameTrailKeyframes.js): the eases'
 * evaluators and the keyframe math of box motion (normalise, sample, union
 * box). Same results as the JavaScript; FrameTrail's version is the reference.
 *
 * Keyframes are JSON values: { t, xywh: [left, top, width, height], r?, ease? }.
 *
 * The spring and wiggle eases come from HyperFrames
 * (https://github.com/heygen-com/hyperframes), Copyright 2026 HeyGen, Inc.,
 * Apache License 2.0, through FrameTrail; modified.
 */

if (!function_exists("ftExtensionStorage") && !defined("FT_CONVERSATIONAL_UI_LIB")) {
    http_response_code(404);
    exit;
}

use FtConversationalUiJs as Js;


class FtConversationalUiKeyframes {

    // The eases: a cubic bezier, or a function ("linear", "hold", "spring", "wiggle") with its arguments.
    const EASES = array(
        "linear"       => array("linear"),
        "hold"         => array("hold"),
        "ease"         => array("bezier", 0.25, 0.1, 0.25, 1),
        "easeIn"       => array("bezier", 0.42, 0, 1, 1),
        "easeOut"      => array("bezier", 0, 0, 0.58, 1),
        "easeInOut"    => array("bezier", 0.42, 0, 0.58, 1),
        "gentleOut"    => array("bezier", 0.2, 0.7, 0.2, 1),
        "power1In"     => array("bezier", 0.11, 0, 0.5, 0),
        "power1Out"    => array("bezier", 0.5, 1, 0.89, 1),
        "power1InOut"  => array("bezier", 0.45, 0, 0.55, 1),
        "power2In"     => array("bezier", 0.32, 0, 0.67, 0),
        "power2Out"    => array("bezier", 0.33, 1, 0.68, 1),
        "power2InOut"  => array("bezier", 0.65, 0, 0.35, 1),
        "power3In"     => array("bezier", 0.5, 0, 0.75, 0),
        "power3Out"    => array("bezier", 0.25, 1, 0.5, 1),
        "power3InOut"  => array("bezier", 0.76, 0, 0.24, 1),
        "power4In"     => array("bezier", 0.64, 0, 0.78, 0),
        "power4Out"    => array("bezier", 0.22, 1, 0.36, 1),
        "power4InOut"  => array("bezier", 0.83, 0, 0.17, 1),
        "sineIn"       => array("bezier", 0.12, 0, 0.39, 0),
        "sineOut"      => array("bezier", 0.61, 1, 0.88, 1),
        "sineInOut"    => array("bezier", 0.37, 0, 0.63, 1),
        "expoIn"       => array("bezier", 0.7, 0, 0.84, 0),
        "expoOut"      => array("bezier", 0.16, 1, 0.3, 1),
        "expoInOut"    => array("bezier", 0.87, 0, 0.13, 1),
        "circIn"       => array("bezier", 0.55, 0, 1, 0.45),
        "circOut"      => array("bezier", 0, 0.55, 0.45, 1),
        "circInOut"    => array("bezier", 0.85, 0, 0.15, 1),
        "backIn"       => array("bezier", 0.36, 0, 0.66, -0.56),
        "backOut"      => array("bezier", 0.34, 1.56, 0.64, 1),
        "backInOut"    => array("bezier", 0.68, -0.6, 0.32, 1.6),
        "springGentle" => array("spring", 0.15),
        "springQuick"  => array("spring", 0.4),
        "springBouncy" => array("spring", 0.6),
        "springSlow"   => array("spring", 0.25),
        "wiggle"       => array("wiggle", 3, "easeInOut", 0.12),
        "wiggleBounce" => array("wiggle", 4, "easeOut", 0.22)
    );


    /* ------------------------------------------------------------------ */
    /*  Eases                                                             */
    /* ------------------------------------------------------------------ */

    public static function hasEase($id) {
        return is_string($id) && array_key_exists($id, self::EASES);
    }

    /**
     * I evaluate an ease at progress p (0..1); unknown ids are linear.
     *
     * @method ease
     * @param {String} $id
     * @param {Number} $p
     * @return Number
     */
    public static function ease($id, $p) {

        $def = self::hasEase($id) ? self::EASES[$id] : self::EASES["linear"];

        switch ($def[0]) {
            case "bezier": return self::bezier($def[1], $def[2], $def[3], $def[4], $p);
            case "spring": return self::spring($def[1], $p);
            case "wiggle": return self::wiggle($def[1], $def[2], $def[3], $p);
            case "hold":   return ($p < 1) ? 0 : 1;
            default:       return max(0, min(1, $p));
        }

    }

    // Cubic-bezier: solve x(t) = p by Newton, fall back to bisection.
    private static function bezier($x1, $y1, $x2, $y2, $p) {

        $coord = function($t, $a1, $a2) {
            $u = 1 - $t;
            return 3 * $u * $u * $t * $a1 + 3 * $u * $t * $t * $a2 + $t * $t * $t;
        };
        $slope = function($t, $a1, $a2) {
            $u = 1 - $t;
            return 3 * $u * $u * $a1 + 6 * $u * $t * ($a2 - $a1) + 3 * $t * $t * (1 - $a2);
        };

        if ($p <= 0) { return 0; }
        if ($p >= 1) { return 1; }

        $t = $p;
        for ($i = 0; $i < 8; $i++) {
            $x = $coord($t, $x1, $x2) - $p;
            if (abs($x) < 1e-6) { return $coord($t, $y1, $y2); }
            $d = $slope($t, $x1, $x2);
            if (abs($d) < 1e-6) { break; }
            $t -= $x / $d;
        }

        $lo = 0;
        $hi = 1;
        $t  = $p;
        for ($i = 0; $i < 30; $i++) {
            $x = $coord($t, $x1, $x2);
            if (abs($x - $p) < 1e-6) { break; }
            if ($x < $p) { $lo = $t; } else { $hi = $t; }
            $t = ($lo + $hi) / 2;
        }

        return $coord($t, $y1, $y2);

    }

    // Endpoint-normalised damped cosine.
    private static function spring($bounce, $p) {
        $b        = max(0, min(1, $bounce));
        $decay    = 12 - $b * 6;
        $omega    = M_PI * 2 * (1 + $b * 1.5);
        $endpoint = 1 - exp(-$decay) * cos($omega);
        if ($p <= 0) { return 0; }
        if ($p >= 1) { return 1; }
        return (1 - exp(-$decay * $p) * cos($omega * $p)) / $endpoint;
    }

    // Wiggle around the linear path.
    private static function wiggle($wiggles, $type, $peak, $p) {
        $direction = ($type === "anticipate") ? -1 : 1;
        if ($p <= 0) { return 0; }
        if ($p >= 1) { return 1; }
        $envelope = ($type === "easeInOut") ? $peak * sin(M_PI * $p)
                  : (($type === "uniform") ? $peak : $peak * (1 - $p));
        return $p + $direction * $envelope * sin(M_PI * 2 * $wiggles * $p);
    }


    /* ------------------------------------------------------------------ */
    /*  Box motion keyframes                                              */
    /* ------------------------------------------------------------------ */

    /**
     * I validate, sort and de-duplicate raw keyframes; undefined when nothing
     * valid is left.
     *
     * @method normalizeKeyframes
     * @param {Array} $raw
     * @return Array|FtConversationalUiUndefined
     */
    public static function normalizeKeyframes($raw) {

        if (!is_array($raw)) { return Js::undef(); }

        $valid = array();

        foreach ($raw as $kf) {

            $xywhRaw = Js::get($kf, "xywh");

            if (!Js::isObject($kf) || !is_array($xywhRaw) || count($xywhRaw) !== 4) { continue; }

            $t    = Js::parseFloat(Js::get($kf, "t"));
            $xywh = array_map(function($v) { return Js::parseFloat($v); }, $xywhRaw);

            if (!Js::isFinite($t)) { continue; }
            foreach ($xywh as $v) {
                if (!Js::isFinite($v)) { continue 2; }
            }

            $xywh[2] = max(0, $xywh[2]);
            $xywh[3] = max(0, $xywh[3]);

            $clean = Js::obj(array("t" => $t, "xywh" => $xywh));
            $r     = Js::parseFloat(Js::get($kf, "r"));

            if (Js::isFinite($r) && Js::round($r * 100) != 0) {
                $clean->r = Js::round($r * 100) / 100;
            }

            $ease = Js::get($kf, "ease");
            if (Js::truthy($ease) && $ease !== "linear" && self::hasEase($ease)) {
                $clean->ease = $ease;
            }

            $valid[] = $clean;

        }

        $valid = Js::sort($valid, function($a, $b) { return $a->t - $b->t; });

        $deduped = array();
        foreach ($valid as $kf) {
            $last = count($deduped) - 1;
            if ($last >= 0 && abs($deduped[$last]->t - $kf->t) < 0.0005) {
                $deduped[$last] = $kf;
            } else {
                $deduped[] = $kf;
            }
        }

        return count($deduped) ? $deduped : Js::undef();

    }

    /**
     * I sample the box of a track at time t: [left, top, width, height].
     *
     * @method sampleKeyframes
     * @param {Array} $kfs
     * @param {Number} $t
     * @return Array|null
     */
    public static function sampleKeyframes($kfs, $t) {

        if (!is_array($kfs) || !count($kfs)) { return null; }
        if ($t <= $kfs[0]->t) { return $kfs[0]->xywh; }
        $last = $kfs[count($kfs) - 1];
        if ($t >= $last->t) { return $last->xywh; }

        list($a, $b) = self::segment($kfs, $t);

        $p   = ($t - $a->t) / ($b->t - $a->t);
        $e   = self::ease(isset($a->ease) ? $a->ease : "linear", $p);
        $out = array();

        for ($i = 0; $i < 4; $i++) {
            $out[] = $a->xywh[$i] + ($b->xywh[$i] - $a->xywh[$i]) * $e;
        }
        $out[2] = max(0, $out[2]);
        $out[3] = max(0, $out[3]);

        return $out;

    }

    /**
     * I sample the rotation (degrees) of a track at time t.
     *
     * @method sampleRotation
     * @param {Array} $kfs
     * @param {Number} $t
     * @return Number
     */
    public static function sampleRotation($kfs, $t) {

        $r = function($kf) { return (isset($kf->r) && Js::truthy($kf->r)) ? $kf->r : 0; };

        if (!is_array($kfs) || !count($kfs)) { return 0; }
        if ($t <= $kfs[0]->t) { return $r($kfs[0]); }
        $last = $kfs[count($kfs) - 1];
        if ($t >= $last->t) { return $r($last); }

        list($a, $b) = self::segment($kfs, $t);

        $p = ($t - $a->t) / ($b->t - $a->t);
        $e = self::ease(isset($a->ease) ? $a->ease : "linear", $p);

        return $r($a) + ($r($b) - $r($a)) * $e;

    }

    // The two keyframes around t (binary search).
    private static function segment($kfs, $t) {
        $lo = 0;
        $hi = count($kfs) - 1;
        while ($hi - $lo > 1) {
            $mid = ($lo + $hi) >> 1;
            if ($kfs[$mid]->t <= $t) { $lo = $mid; } else { $hi = $mid; }
        }
        return array($kfs[$lo], $kfs[$hi]);
    }

    /**
     * I return the union bounding box of a track within [start, end],
     * clamped to 0..100 and rounded to 4 decimals: { left, top, width, height }.
     *
     * @method unionBox
     * @param {Array} $kfs
     * @param {Number} $start
     * @param {Number} $end
     * @return stdClass
     */
    public static function unionBox($kfs, $start, $end) {

        $samples = array(self::sampleKeyframes($kfs, $start), self::sampleKeyframes($kfs, $end));
        $count   = count($kfs);

        for ($i = 0; $i < $count; $i++) {
            $kf   = $kfs[$i];
            $next = ($i + 1 < $count) ? $kfs[$i + 1] : null;
            if ($kf->t > $start && $kf->t < $end) { $samples[] = $kf->xywh; }
            if ($next && $next->t > $start && $kf->t < $end) {
                for ($s = 1; $s < 16; $s++) {
                    $t = $kf->t + ($next->t - $kf->t) * ($s / 16);
                    if ($t > $start && $t < $end) { $samples[] = self::sampleKeyframes($kfs, $t); }
                }
            }
        }

        $x1 = INF;
        $y1 = INF;
        $x2 = -INF;
        $y2 = -INF;

        foreach ($samples as $b) {
            if (!$b) { continue; }
            $x1 = min($x1, $b[0]);
            $y1 = min($y1, $b[1]);
            $x2 = max($x2, $b[0] + $b[2]);
            $y2 = max($y2, $b[1] + $b[3]);
        }

        $clamp = function($v) { return max(0, min(100, $v)); };
        $round = function($v) { return Js::round($v * 10000) / 10000; };

        $x1 = $clamp($x1);
        $y1 = $clamp($y1);
        $x2 = $clamp($x2);
        $y2 = $clamp($y2);

        return Js::obj(array(
            "left"   => $round($x1),
            "top"    => $round($y1),
            "width"  => $round(max(0, $x2 - $x1)),
            "height" => $round(max(0, $y2 - $y1))
        ));

    }

}
