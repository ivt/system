<?php

namespace IVT\System;

abstract class Process
{
    /**
     * @param self[] $processes
     */
    static function waitAll(array $processes)
    {
        while (true) {
            foreach ($processes as $k => $process)
                if ($process->isDone())
                    unset($processes[$k]);

            if ($processes)
                usleep(100000);
            else
                break;
        }
    }

    final function isRunning()
    {
        return !$this->isDone();
    }

    function exitStatus()
    {
        return $this->wait();
    }

    function succeeded()
    {
        return $this->exitStatus() === 0;
    }

    function failed()
    {
        return $this->exitStatus() !== 0;
    }

    /**
     * @return bool Whether the process has finished
     */
    abstract function isDone();

    /**
     * Waits for the process to finish and returns the exit code
     * @return int
     */
    abstract function wait();
}

