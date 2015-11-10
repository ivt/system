<?php

namespace IVT\System;

/**
 * The promise of an exit code for a process running asynchronously
 */
abstract class Process {
    final function isRunning() {
        return !$this->isDone();
    }

    final function exitStatus() {
        if ($this->isDone())
            return $this->wait();
        else
            throw new Exception('Cannot get exit code, process has not finished. Use wait() if you want to wait');
    }

    final function succeeded() {
        return $this->exitStatus() === 0;
    }

    final function failed() {
        return $this->exitStatus() !== 0;
    }

    /**
     * Stop the process, gracefully if possible.
     * @return void
     */
    abstract function stop();

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

