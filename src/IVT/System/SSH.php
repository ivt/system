<?php

namespace IVT\System\SSH;

use IVT\Assert;
use IVT\System\Exception;
use IVT\System\SSH;

abstract class SSHAuth {
    /**
     * @param resource $session
     * @param string   $username
     */
    abstract function authenticate($session, $username);

    /**
     * @return string[]
     */
    abstract function sshCmd();

    protected static $defaultSSHOptions = array(
        '-o', 'StrictHostKeyChecking=no',
        '-o', 'UserKnownHostsFile=/dev/null',
        '-o', 'GlobalKnownHostsFile=/dev/null',
        '-o', 'IdentityFile=/dev/null',
        '-F', '/dev/null',
    );
}

class SSHAuthNone extends SSH\SSHAuth {
    function authenticate($session, $username) {
        $methods = ssh2_auth_none($session, $username);
        if ($methods !== true)
            throw new Exception("ssh2_auth_none() not supported. Supported authentication methods: " . join(', ', $methods));
        Assert::true($methods);
    }

    function sshCmd() {
        return array_merge(array(
            'ssh',
            '-o', 'BatchMode=yes',
            '-o', 'PubkeyAuthentication=no',
        ), self::$defaultSSHOptions);
    }
}


class SSHAuthPassword extends SSH\SSHAuth {
    private $password;

    /**
     * @param string $password
     */
    function __construct($password) {
        $this->password = $password;
    }

    function authenticate($session, $username) {
        Assert::true(ssh2_auth_password($session, $username, $this->password));
    }

    function sshCmd() {
        return array_merge(array(
            'env',
            "SSH_PASS=$this->password",
            'sshpass', '-e',
            '-o', 'PubkeyAuthentication=no',
        ), self::$defaultSSHOptions);
    }
}

class SSHAuthKeyPair extends SSH\SSHAuth {
    /** @var string */
    private $pubkeyfile;
    /** @var string */
    private $privkeyfile;
    /** @var null|string */
    private $passphrase;

    /**
     * @param string      $pubkeyfile
     * @param string      $privkeyfile
     * @param string|null $passphrase
     */
    function __construct($pubkeyfile, $privkeyfile, $passphrase = null) {
        $this->pubkeyfile  = $pubkeyfile;
        $this->privkeyfile = $privkeyfile;
        $this->passphrase  = $passphrase;
    }

    function authenticate($session, $username) {
        Assert::true(ssh2_auth_pubkey_file($session, $username, $this->pubkeyfile, $this->privkeyfile, $this->passphrase));
    }

    function sshCmd() {
        if ($this->passphrase !== null)
            throw new Exception('I don\'t know how to pass a passphrase for the private key file to the "ssh" command');

        return array_merge(array(
            'ssh',
            '-i', $this->privkeyfile,
            '-o', 'BatchMode=yes',
        ), self::$defaultSSHOptions);
    }
}

class SSHAuthHostBasedFile extends SSH\SSHAuth {
    /** @var string */
    private $hostname;
    /** @var string */
    private $pubkeyfile;
    /** @var string */
    private $privkeyfile;
    /** @var string|null */
    private $passphrase;
    /** @var string|null */
    private $local_username;

    /**
     * @param string      $hostname
     * @param string      $pubkeyfile
     * @param string      $privkeyfile
     * @param string|null $passphrase
     * @param string|null $local_username
     */
    function __construct($hostname, $pubkeyfile, $privkeyfile, $passphrase = null, $local_username = null) {
        $this->hostname       = $hostname;
        $this->pubkeyfile     = $pubkeyfile;
        $this->privkeyfile    = $privkeyfile;
        $this->passphrase     = $passphrase;
        $this->local_username = $local_username;
    }

    function authenticate($session, $username) {
        Assert::true(ssh2_auth_hostbased_file(
            $session,
            $username,
            $this->hostname,
            $this->pubkeyfile,
            $this->privkeyfile,
            $this->passphrase,
            $this->local_username
        ));
    }

    function sshCmd() {
        throw new Exception('Not sure how to do "host based" authentication with the "ssh" command');
    }
}
