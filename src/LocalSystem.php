<?php

namespace IVT\System;

use IVT\Assert;
use IVT\Log;

class LocalSystem extends System
{
    static function create()
    {
        return new self;
    }

    function applyVisitor(SystemVisitor $visitor)
    {
        return $visitor->visitLocalSystem($this);
    }

    function escapeCmd($arg)
    {
        if ($this->isWindows())
            return '"' . addcslashes($arg, '\\"') . '"';
        else
            return parent::escapeCmd($arg);
    }

    final function isWindows()
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    function isPortOpen($host, $port, $timeout)
    {
        set_error_handler(function () {
        });
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        restore_error_handler();
        if ($fp === false)
            return false;
        fclose($fp);

        return true;
    }

    static function createLogging()
    {
        $self = new self;
        return $self->wrapLogging(Log::create());
    }

    function file($path)
    {
        return new LocalFile($this, $path);
    }

    function dirSep()
    {
        return DIRECTORY_SEPARATOR;
    }

    function runImpl($command, $stdIn, \Closure $stdOut, \Closure $stdErr)
    {
        return new LocalProcess($command, $stdIn, $stdOut, $stdErr);
    }

    function time()
    {
        return time();
    }

    /**
     * @return int
     */
    function getmypid()
    {
        return getmypid();
    }

    function cd($dir)
    {
        Assert::true(chdir($dir));
    }

    function pwd()
    {
        return Assert::string(getcwd());
    }

    function describe()
    {
        return 'local';
    }
}
