<?php

namespace IVT\System;

class SSHForwardedPorts
{
    /** @var SSHAuth */
    private $auth;
    /** @var array */
    private $forwardedPorts = array();

    function __construct(SSHAuth $auth)
    {
        $this->auth = $auth;
    }

    function forwardPort($remoteHost, $remotePort)
    {
        $forwarded =& $this->forwardedPorts[$remoteHost][$remotePort];

        if (!$forwarded)
            $forwarded = $this->doPortForward($remoteHost, $remotePort);

        return $forwarded;
    }

    /**
     * @param string $remoteHost
     * @param int $remotePort
     * @throws SSHForwardPortFailed
     * @return SSHForwardedPort
     */
    private function doPortForward($remoteHost, $remotePort)
    {
        if ($remoteHost === 'localhost')
            $remoteHost = '127.0.0.1';

        $process = null;
        $local = new LocalSystem;

        for ($attempts = 0; $attempts < 10; $attempts++) {
            do {
                $port = \mt_rand(49152, 65535);
            } while ($local->isPortOpen('localhost', $port, 1));

            $process = $local->runCommandAsync($this->auth->forwardPortCmd($local, $port, $remoteHost, $remotePort));

            $checks = 0;
            while ($process->isRunning()) {
                usleep(10000);

                if ($local->isPortOpen('localhost', $port, 1))
                    $checks++;
                else
                    $checks = 0;

                if ($checks >= 4)
                    return new SSHForwardedPort($process, $port);
            }
        }

        $e = $process ? new CommandFailedException($process) : null;

        throw new SSHForwardPortFailed("Failed to forward a port after $attempts attempts :(", 0, $e);
    }
}

