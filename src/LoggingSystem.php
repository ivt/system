<?php

namespace IVT\System;

use IVT\Log;

class LoggingSystem extends WrappedSystem
{
    public $log;

    function __construct(System $system, Log $log)
    {
        parent::__construct($system);

        $this->log = $log;
    }

    function applyVisitor(SystemVisitor $visitor)
    {
        return $visitor->visitLoggingSystem($this);
    }

    function parentApplyVisitor(SystemVisitor $visitor)
    {
        return parent::applyVisitor($visitor);
    }

    function log($message)
    {
        $this->log->debug("{$this->describe()}: $message");
    }

    function runImpl($command, $stdIn, \Closure $stdOut, \Closure $stdErr)
    {
        $self = $this;
        $log = function ($data) use ($self) {
            foreach (lines($data) as $line)
                $self->log($line);
        };
        $cmd = new BinaryBuffer(new LinePrefixStream('>>> ', '... ', $log));
        $in = new BinaryBuffer(new LinePrefixStream('--- ', '--- ', $log));
        $out = new BinaryBuffer(new LinePrefixStream('  ', '  ', $log));
        $err = new BinaryBuffer(new LinePrefixStream('! ', '! ', $log));

        $cmd(self::removeSecrets("$command\n"));
        unset($cmd);

        $in($stdIn);
        unset($in);

        $process = parent::runImpl(
            $command,
            $stdIn,
            function ($data) use ($out, $stdOut) {
                $out($data);
                $stdOut($data);
            },
            function ($data) use ($err, $stdErr) {
                $err($data);
                $stdErr($data);
            }
        );
        unset($out);
        unset($err);
        gc_collect_cycles();

        return $process;
    }

    function cd($dir)
    {
        $this->log("cd $dir");
        parent::cd($dir);
    }

    function pwd()
    {
        $result = parent::pwd();
        $this->log("pwd => $result");

        return $result;
    }

    function isPortOpen($host, $port, $timeout)
    {
        $result = parent::isPortOpen($host, $port, $timeout);
        $this->log("is $host:$port open => " . yes_no($result));

        return $result;
    }

    function wrap(System $system)
    {
        return new self(parent::wrap($system), $this->log);
    }

    function file($path)
    {
        return new LoggingFile($this, $path, parent::file($path), $this);
    }
}
