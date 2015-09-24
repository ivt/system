<?php

namespace IVT\System;

final class LogUtils {
    /**
     * Ellipsize the given string to the given length
     * @param string $string
     * @param int    $width
     * @return string
     */
    static function ellipsize($string, $width) {
        if (strlen($string) <= $width)
            return $string;

        $ellipses = '...';

        $half  = max(0, $width - strlen($ellipses)) / 2;
        $left  = substr($string, 0, ceil($half));
        $right = substr($string, -floor($half));

        return $left . $ellipses . $right;
    }

    /**
     * @param string[] $strings
     * @return string
     */
    static function summarizeArray(array $strings) {
        $result = '[' . join(', ', $strings) . ']';
        $result = self::ellipsize($result, 40);
        return $result;
    }

    /**
     * @param string $string
     * @return string
     */
    static function summarize($string) {
        $string = self::collapse($string);
        $string = self::ellipsize($string, 40);
        return $string;
    }

    /**
     * Collapses the given string into a single line
     * @param string $string
     * @return string
     */
    static function collapse($string) {
        return '"' . \PCRE::replace('([^[:print:]]|\s+)+', trim($string), ' ') . '"';
    }
}
