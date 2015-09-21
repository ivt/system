<?php

namespace IVT\System;

use ArrayAccess;

/**
 * Get a key from an array using a default value if it is not set.
 *
 * Note that this uses isset() rather than array_key_exists(), because that's what the code which
 * this function is intended to replace was using, and I don't want to change it's semantics.
 *
 * @param array|ArrayAccess $array
 * @param                   $key
 * @param                   $default
 *
 * @return mixed
 */
function array_get($array, $key, $default = null)
{
    return isset($array[$key]) ? $array[$key] : $default;
}

function random_string($len)
{
    $alphabet = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    $count = strlen($alphabet) - 1;
    $str = "";

    mt_srand((double)microtime() * 1000000);

    for ($i = 0; $i < $len; $i++)
        $str .= $alphabet[mt_rand(0, $count)];

    return $str;
}

function starts_with($string, $start)
{
    return strncmp($string, $start, strlen($start)) === 0;
}

function ends_with($string, $end)
{
    return substr($string, -strlen($end)) === $end;
}

/**
 * Split a block of text into lines. Properly handles ending newline, ie "a\nb\n" => ["a", "b"].
 * @param string $text
 * @return string[]
 */
function lines($text)
{
    $lines = explode("\n", $text);

    if ($lines && $lines[count($lines) - 1] === '')
        array_pop($lines);

    return $lines;
}

/**
 * @param bool $bool
 * @return string
 */
function yes_no($bool)
{
    return $bool ? 'yes' : 'no';
}

/**
 * @param int $n
 * @return string
 */
function humanize_bytes($n)
{
    $i = (int)log(max(abs($n), 1), 1000);
    $p = 'KMGTPEZY';
    if ($i == 0)
        return "$n B";
    else
        return number_format($n / pow(1000, $i), 1) . " {$p[$i - 1]}B";
}
