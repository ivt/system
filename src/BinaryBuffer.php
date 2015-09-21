<?php

namespace IVT\System;

/**
 * Causes continuous chunks of binary data to be sent to the underlying
 * stream in a single chunk.
 */
class BinaryBuffer
{
    private $buffer = '';
    private $delegate;

    /**
     * @param callable $delegate
     */
    function __construct($delegate)
    {
        $this->delegate = $delegate;
    }

    function __invoke($data)
    {
        if ($data === '')
            return;

        if (!mb_check_encoding($data, 'UTF-8')) {
            $this->buffer .= $data;

            if (strlen($this->buffer) > 10000000)
                $this->flush();
        } else {
            $this->flush();
            $this->send($data);
        }
    }

    function __destruct()
    {
        $this->flush();
    }

    private function flush()
    {
        $this->send($this->buffer);
        $this->buffer = '';
    }

    private function send($s)
    {
        $f = $this->delegate;
        $f($s);
    }
}

