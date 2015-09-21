<?php

namespace IVT\System;

use IVT\Assert;

class SSHProcess extends Process
{
    private $onStdOut;
    private $onStdErr;
    private $stdOut;
    private $stdErr;
    private $getExitCode;

    /**
     * @param resource $ssh
     * @param string $command
     * @param \Closure $onStdOut
     * @param \Closure $onStdErr
     * @param \Closure $getExitCode
     */
    function __construct($ssh, $command, \Closure $onStdOut, \Closure $onStdErr, \Closure $getExitCode)
    {
        // Make sure as many of these objects are collected first before we start a new command.
        gc_collect_cycles();

        $this->onStdOut = $onStdOut;
        $this->onStdErr = $onStdErr;
        $this->getExitCode = $getExitCode;
        $this->stdOut = Assert::resource(ssh2_exec($ssh, $command));
        $this->stdErr = Assert::resource(ssh2_fetch_stream($this->stdOut, SSH2_STREAM_STDERR));

        Assert::true(stream_set_blocking($this->stdOut, false));
        Assert::true(stream_set_blocking($this->stdErr, false));
    }

    function __destruct()
    {
        if (is_resource($this->stdOut))
            Assert::true(fclose($this->stdOut));
        if (is_resource($this->stdErr))
            Assert::true(fclose($this->stdErr));
    }

    function isDone()
    {
        $stdOutDone = $this->isStreamDone($this->stdOut, $this->onStdOut);
        $stdErrDone = $this->isStreamDone($this->stdErr, $this->onStdErr);
        return $stdOutDone && $stdErrDone;
    }

    private function isStreamDone($stream, \Closure $callback)
    {
        $eof = Assert::bool(feof($stream));
        if (!$eof)
            $callback(Assert::string(fread($stream, 8192)));
        return $eof;
    }

    function wait()
    {
        while (!$this->isDone())
            usleep(100000);

        $exitCode = $this->getExitCode;
        return Assert::int($exitCode());
    }
}

