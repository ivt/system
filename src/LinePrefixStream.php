<?php

namespace IVT\System;

class LinePrefixStream
{
    private $buffer = '', $firstPrefix, $prefix, $delegate, $lineNo = 0;

    /**
     * @param string $firstPrefix
     * @param string $prefix
     * @param \Closure $delegate
     */
    function __construct($firstPrefix, $prefix, \Closure $delegate)
    {
        $this->firstPrefix = $firstPrefix;
        $this->prefix = $prefix;
        $this->delegate = $delegate;
    }

    function __invoke($data)
    {
        $data = $this->clean($data);

        $this->buffer .= $data;
        $lines = explode("\n", $this->buffer);
        $this->buffer = array_pop($lines);

        foreach ($lines as $line) {
            $prefix = $this->lineNo == 0 ? $this->firstPrefix : $this->prefix;
            $this->send("$prefix$line\n");
            $this->lineNo++;
        }
    }

    function __destruct()
    {
        if ($this->buffer !== '') {
            $this("\n");
            $this->send("^ no end of line\n");
        }
    }

    /**
     * @param string $bytes
     * @return string
     */
    private function clean($bytes)
    {
        if (mb_check_encoding($bytes, 'UTF-8'))
            return $bytes;
        else
            return "[" . humanize_bytes(strlen($bytes)) . " binary]\n";
    }

    private function send($data)
    {
        $delegate = $this->delegate;
        $delegate($data);
    }
}
