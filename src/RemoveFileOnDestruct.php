<?php

namespace IVT\System;

class RemoveFileOnDestruct extends Process
{
    /** @var Process */
    private $process;
    /** @var File */
    private $file;

    function __construct(Process $process, File $file)
    {
        $this->process = $process;
        $this->file = $file;
    }

    function __destruct()
    {
        $this->file->ensureNotExists();
    }

    function isDone()
    {
        return $this->process->isDone();
    }

    function wait()
    {
        return $this->process->wait();
    }
}

