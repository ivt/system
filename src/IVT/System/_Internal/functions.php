<?php

namespace IVT\System\_Internal;

/**
 * Split a block of text into lines. Properly handles ending newline, ie "a\nb\n" => ["a", "b"].
 * @param string $text
 * @return string[]
 */
function split_lines($text) {
    $lines = explode("\n", $text);

    if ($lines && $lines[count($lines) - 1] === '')
        array_pop($lines);

    return $lines;
}

/**
 * @param bool $bool
 * @return string
 */
function yes_no($bool) {
    return $bool ? 'yes' : 'no';
}

/**
 * @param int $n
 * @return string
 */
function humanize_bytes($n) {
    $i = (int)log(max(abs($n), 1), 1000);
    $p = 'KMGTPEZY';
    if ($i == 0)
        return "$n B";
    else
        return number_format($n / pow(1000, $i), 1) . " {$p[$i - 1]}B";
}
