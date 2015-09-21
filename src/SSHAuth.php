<?php

namespace IVT\System;

use IVT\Assert;

class SSHAuth
{
    private $user;
    private $host;
    private $port;
    private $publicKeyFile;
    private $privateKeyFile;

    function __construct($user, $host, $port = 22)
    {
        $this->user = $user;
        $this->host = $host;
        $this->port = $port;
    }

    function setPublicKeyFile($file = null)
    {
        $this->publicKeyFile = $file;
    }

    function setPrivateKeyFile($file = null)
    {
        $this->privateKeyFile = $file;
    }

    function connect()
    {
        $local = new LocalSystem;

        if (!$local->isPortOpen($this->host, $this->port, 20)) {
            throw new Exception("Port $this->port is not open on $this->host");
        }

        Assert::resource($ssh = ssh2_connect($this->host, $this->port));
        Assert::true(ssh2_auth_pubkey_file($ssh, $this->user, $this->publicKeyFile, $this->privateKeyFile));

        return $ssh;
    }

    function wrapCmd(System $system, $cmd)
    {
        return $this->sshCmd($system, array(), "$cmd");
    }

    function forwardPortCmd(System $system, $localPort, $remoteHost, $remotePort)
    {
        // PHP's proc_open() runs it's command in a shell with "sh -c ...".
        // 'exec' instructs the shell to not just run the command as a child,
        // but replace itself with it.
        //
        // Without 'exec', the process tree looks like this:
        //
        // init
        //  \_ ...
        //      \_ php
        //          \_ sh -c "ssh ..."
        //              \_ ssh ... 
        // 
        // And when PHP kills it's child, the "ssh" is left orphaned on the system:
        //
        // init
        //  \_ ...
        //  |   \_ php
        //  \_ ssh ... 
        // 
        // With 'exec', the process tree looks like this:
        //
        // init
        //  \_ ...
        //      \_ php
        //          \_ ssh ... 
        //
        // And when PHP kills it's child, nothing is left:
        //
        // init
        //  \_ ...
        //      \_ php
        //
        return 'exec ' . $this->sshCmd($system, array('-N', '-L', "localhost:$localPort:$remoteHost:$remotePort"));
    }

    /**
     * @param System $system
     * @param string[]|null $opts
     * @param string|null $command
     * @return string
     */
    private function sshCmd(System $system, array $opts = array(), $command = null)
    {
        return $system->escapeCmdArgs(array_merge(
            array(
                'ssh',
                '-o', 'ExitOnForwardFailure=yes',
                '-o', 'BatchMode=yes',
                '-o', 'StrictHostKeyChecking=no',
                '-o', 'UserKnownHostsFile=/dev/null',
                '-i', $this->privateKeyFile,
            ),
            $opts,
            array(
                "$this->user@$this->host",
            ),
            $command !== null
                ? array($command)
                : array()
        ));
    }

    function describe()
    {
        return "$this->user@$this->host";
    }
}

