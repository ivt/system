<?php

namespace IVT\System;

class Exception extends \Exception {
    function __construct($message = "", $code = 0, \Exception $previous = null) {
        parent::__construct($message, $code, $previous);

        $trace = debug_backtrace();
        while (isset($trace[0]['object']) && $trace[0]['object'] == $this)
            array_shift($trace);
        $prop = new \ReflectionProperty('Exception', 'trace');
        $prop->setAccessible(true);
        $prop->setValue($this, $trace);
    }
}
