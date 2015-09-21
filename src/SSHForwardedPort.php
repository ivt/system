<?php

namespace IVT\System;

class SSHForwardedPort
{
    /**
     * It is important that we store this object in a property, so that the process continues
     * running until this object is GC'd. The destructor for the object will kill the
     * process, removing our forwarded port.
     *
     * @var CommandResult
     */
    private $process;
    private $localPort;

    function __construct(CommandResult $process, $localPort)
    {
        // PHP only collects cycles when the number of "roots" hits 1000 and
        // by that time there may be many instances of this object in memory,
        // all keeping an SSH connection open with a forwarded port.
        //
        // To prevent many instances of this object from building up and
        // keeping forwarded ports open, we will force the cycle collector to
        // run each time this object is instantiated. At least then there
        // will only ever be at most 1 instance of this class left
        // unreferenced waiting to be collected at any time.
        gc_collect_cycles();

        $this->process = $process;
        $this->localPort = $localPort;
    }

    function localPort()
    {
        return $this->localPort;
    }
}
