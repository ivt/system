<?php

namespace IVT\System;

class WrappedSystem extends System
{
    private $system;

    function system()
    {
        return $this->system;
    }

    function __construct(System $system)
    {
        $this->system = $system;
    }

    function applyVisitor(SystemVisitor $visitor)
    {
        return $visitor->visitWrappedSystem($this);
    }

    function cd($dir)
    {
        $this->system->cd($dir);
    }

    function pwd()
    {
        return $this->system->pwd();
    }

    function escapeCmd($arg)
    {
        return $this->system->escapeCmd($arg);
    }

    function file($path)
    {
        return new WrappedFile($this, $path, $this->system->file($path));
    }

    function dirSep()
    {
        return $this->system->dirSep();
    }

    function time()
    {
        return $this->system->time();
    }

    function runImpl($command, $stdIn, \Closure $stdOut, \Closure $stdErr)
    {
        return $this->system->runImpl($command, $stdIn, $stdOut, $stdErr);
    }

    function wrap(System $system)
    {
        return $this->system->wrap(parent::wrap($system));
    }

    function isPortOpen($host, $port, $timeout)
    {
        return $this->system->isPortOpen($host, $port, $timeout);
    }

    function describe()
    {
        return $this->system->describe();
    }
}

