<?php

namespace IVT\System;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Interface for classes which can be wrapped in a logging version of
 * themselves, and which can apply their own logging to another Loggable.
 */
interface Loggable {
    /**
     * Return a logging version of this class.
     * @param LoggerInterface $logger
     * @param string          $level
     * @return self
     */
    function wrapLogging(LoggerInterface $logger, $level = LogLevel::DEBUG);

    /**
     * Apply whatever logging this instance has to the given instance.
     * @param self $loggable
     * @return self
     */
    function applyLogging(Loggable $loggable);
}
