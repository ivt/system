<?php
namespace IVT\System;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Logger that prefixes all given messages with the given string
 */
final class PrefixLogger extends AbstractLogger {
    /** @var LoggerInterface */
    private $logger;
    /** @var string */
    private $prefix;

    /**
     * @param LoggerInterface $logger
     * @param string          $prefix
     */
    function __construct(LoggerInterface $logger, $prefix) {
        $this->logger = $logger;
        $this->prefix = $prefix;
    }

    function log($level, $message, array $context = array()) {
        $this->logger->log($level, $this->prefix . $message, $context);
    }

    function getInnerLogger() {
        return $this->logger;
    }
}