<?php

namespace IVT\System\_Internal\SSH;

use IVT\Assert;
use IVT\System\_Internal\FOpenWrapperFile;
use IVT\System\CommandFailedException;
use IVT\System\CommandResult;
use IVT\System\Exception;
use IVT\System\File;
use IVT\System\ForwardedPort;
use IVT\System\ForwardPortFailed;
use IVT\System\Process;
use IVT\System\SSH\SSHAuth;
use IVT\System\System;

class RemoveFileOnDestruct extends Process {
    /** @var Process */
    private $process;
    /** @var File */
    private $file;

    function __construct(Process $process, File $file) {
        $this->process = $process;
        $this->file    = $file;
    }

    function __destruct() {
        $this->file->ensureNotExists();
    }

    public function isDone() {
        return $this->process->isDone();
    }

    public function wait() {
        return $this->process->wait();
    }
}

class SSHFile extends FOpenWrapperFile {
    private $sftp;
    private $ssh;

    /**
     * @param SSHSystem $system
     * @param resource  $sftp
     * @param string    $path
     */
    function __construct(SSHSystem $system, $sftp, $path) {
        $this->sftp = $sftp;
        $this->ssh  = $system;

        parent::__construct($system, $path);
    }

    function mkdir($mode = 0777, $recursive = false) {
        Assert::true(ssh2_sftp_mkdir($this->sftp, $this->absolutePath(), $mode, $recursive));
    }

    function readlink() {
        return Assert::string(ssh2_sftp_readlink($this->sftp, $this->absolutePath()));
    }

    function unlink() {
        Assert::true(ssh2_sftp_unlink($this->sftp, $this->absolutePath()));
    }

    function ctime() {
        // ctime is not supported over SFTP2, so we run a command to get it instead.
        $stdout = $this->ssh->execArgs(array('stat', '-c', '%Z', $this->path()));

        return (int)substr($stdout, 0, -1);
    }

    function append($contents) {
        $this->_write($contents, true, false);
    }

    function create($contents) {
        $this->_write($contents, false, true);
    }

    function write($contents) {
        $this->_write($contents, false, false);
        return $this;
    }

    private function _write($data, $append, $bailIfExists) {
        // In the case of append, 'a' doesn't work, so we need to open the file and seek to the end instead.
        // If the file exists, 'w' will truncate it, and 'x' will throw an error. 'c' is not supported by the library.
        // That just leaves 'r+', which will throw an error if the file doesn't exist. So the best thing we can do is
        // use 'r+' if the file exists and 'w' if it doesn't.
        $append = $append && $this->exists();

        if ($bailIfExists)
            $mode = 'xb';
        else if ($append)
            $mode = 'r+b';
        else
            $mode = 'wb';

        Assert::resource($handle = fopen($this->url(), $mode));

        if ($append)
            Assert::equal(fseek($handle, 0, SEEK_END), 0);

        Assert::equal(fwrite($handle, $data), strlen($data));
        Assert::true(fclose($handle));
    }

    private function absolutePath() {
        return $this->makeAbsolute($this->path());
    }

    private function makeAbsolute($path) {
        return substr($path, 0, 1) === '/' ? $path : $this->system->pwd() . '/' . $path;
    }

    protected function pathToUrl($path) {
        return "ssh2.sftp://$this->sftp/.{$this->makeAbsolute( $path )}";
    }

    function chmod($mode) {
        /** @noinspection PhpUndefinedFunctionInspection */
        Assert::true(ssh2_sftp_chmod($this->sftp, $this->absolutePath(), $mode));
    }

    protected function renameImpl($to) {
        $this->ssh->execArgs(array('mv', '-T', $this->path(), $to));
    }

    function realpath() {
        return Assert::string(ssh2_sftp_realpath($this->sftp, $this->absolutePath()));
    }
}

class SSHForwardedPort extends ForwardedPort {
    private static function findOpenPort() {
        do {
            $localPort = \mt_rand(49152, 65535);
        } while (System::local()->isPortOpen('localhost', $localPort, 1));
        return $localPort;
    }

    /**
     * It is important that we store this object in a property, so that the process continues
     * running until this object is GC'd. The destructor for the object will kill the
     * process, removing our forwarded port.
     *
     * @var CommandResult
     */
    private $process;
    private $localPort;

    function __construct($sshUser, $sshHost, $sshPort, SSHAuth $sshAuth, $remoteHost, $remotePort) {
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

        if ($remoteHost === 'localhost')
            $remoteHost = '127.0.0.1';

        for ($i = 0; $i < 10; $i++) {
            $this->localPort = self::findOpenPort();
            $this->process   = System::local()->runCommandAsyncArgs(array_merge(
                array(
                    // Important! Causes the shell which the command is run in to replace itself with the "ssh"
                    // process, so we can kill it directly. Otherwise "ssh" will be a child of the shell, and
                    // killing the shell will leave the "ssh" process orphaned on the system.
                    'exec',
                ),
                $sshAuth->sshCmd(),
                array(
                    '-N',
                    '-L', "$this->localPort:$remoteHost:$remotePort",
                    '-o', 'ExitOnForwardFailure=yes',
                    '-p', $sshPort,
                    "$sshUser@$sshHost",
                )
            ));
            if ($this->waitForPortForward())
                return;
        }

        $e = $this->process ? new CommandFailedException($this->process) : null;

        throw new ForwardPortFailed("Failed to forward a port after $i attempts :(", 0, $e);
    }

    /**
     * @return bool Whether the port forward was successful
     */
    private function waitForPortForward() {
        $checks = 0;
        while ($this->process->isRunning()) {
            usleep(10000);

            if (System::local()->isPortOpen('localhost', $this->localPort, 1))
                $checks++;
            else
                $checks = 0;

            if ($checks >= 4)
                return true;
        }

        return false;
    }

    function localPort() {
        return $this->localPort;
    }

    function localHost() {
        return '127.0.0.1';
    }
}

class SSHProcess extends Process {
    private $onStdOut;
    private $onStdErr;
    private $stdOut;
    private $stdErr;
    private $getExitCode;

    /**
     * @param resource $ssh
     * @param string   $command
     * @param \Closure $onStdOut
     * @param \Closure $onStdErr
     * @param \Closure $getExitCode
     */
    function __construct($ssh, $command, \Closure $onStdOut, \Closure $onStdErr, \Closure $getExitCode) {
        // Make sure as many of these objects are collected first before we start a new command.
        gc_collect_cycles();

        $this->onStdOut    = $onStdOut;
        $this->onStdErr    = $onStdErr;
        $this->getExitCode = $getExitCode;
        $this->stdOut      = Assert::resource(ssh2_exec($ssh, $command));
        $this->stdErr      = Assert::resource(ssh2_fetch_stream($this->stdOut, SSH2_STREAM_STDERR));

        Assert::true(stream_set_blocking($this->stdOut, false));
        Assert::true(stream_set_blocking($this->stdErr, false));
    }

    function __destruct() {
        if (is_resource($this->stdOut))
            Assert::true(fclose($this->stdOut));
        if (is_resource($this->stdErr))
            Assert::true(fclose($this->stdErr));
    }

    public function isDone() {
        $stdOutDone = $this->isStreamDone($this->stdOut, $this->onStdOut);
        $stdErrDone = $this->isStreamDone($this->stdErr, $this->onStdErr);
        return $stdOutDone && $stdErrDone;
    }

    private function isStreamDone($stream, \Closure $callback) {
        $eof = Assert::bool(feof($stream));
        if (!$eof)
            $callback(Assert::string(fread($stream, 8192)));
        return $eof;
    }

    public function wait() {
        while (!$this->isDone())
            usleep(100000);

        $exitCode = $this->getExitCode;
        return Assert::int($exitCode());
    }
}

class SSHSystem extends System {
    /** @var string */
    private $user;
    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var SSHAuth */
    private $auth;

    /** @var resource */
    private $ssh;
    /** @var resource */
    private $sftp;
    /** @var string */
    private $cwd;

    /** @var SSHForwardedPort[] */
    private $forwardedPorts = array();

    /**
     * @param string  $user
     * @param string  $host
     * @param int     $port
     * @param SSHAuth $auth
     */
    function __construct($user, $host, $port, SSHAuth $auth) {
        $this->user = $user;
        $this->host = $host;
        $this->port = $port ?: 22;
        $this->auth = clone $auth;
    }

    function forwardPort($host, $port) {
        $forwardedPort =& $this->forwardedPorts["$host:$port"];
        if (!$forwardedPort)
            $forwardedPort = new SSHForwardedPort($this->user, $this->host, $this->port, $this->auth, $host, $port);
        return $forwardedPort;
    }

    private function connect() {
        if ($this->ssh)
            return;

        if (!self::local()->isPortOpen($this->host, $this->port, 20))
            throw new Exception("Port $this->port is not open on $this->host");

        $this->ssh = Assert::resource(ssh2_connect($this->host, $this->port));
        $this->auth->authenticate($this->ssh, $this->user);
        $this->sftp = Assert::resource(ssh2_sftp($this->ssh));
        $this->cwd  = Assert::string(substr($this->exec('pwd'), 0, -1));
    }

    function file($path) {
        $this->connect();

        return new SSHFile($this, $this->sftp, $path);
    }

    function dirSep() {
        return '/';
    }

    function runAsync(
        $command,
        $stdIn = '',
        \Closure $stdOut = null,
        \Closure $stdErr = null
    ) {
        $stdOut = $stdOut ?: function () { };
        $stdErr = $stdErr ?: function () { };

        $command = "sh -c {$this->escapeCmd( $command )}";

        // If the input is short enough, pipe it into the command using "echo ... | cmd".
        // Otherwise, write it to a file and pipe it into the command using "cmd < file".
        if (strlen($stdIn) < 1000) {
            return $this->runImplHandleExitCode(
                "echo -nE {$this->escapeCmd( $stdIn )} | $command",
                $stdOut,
                $stdErr
            );
        } else {
            $tmpFile = $this->file("/tmp/tmp-ssh-command-input-" . mt_rand());
            $tmpFile->write($stdIn);

            $process = $this->runImplHandleExitCode(
                "$command < {$this->escapeCmd( $tmpFile->path() )}",
                $stdOut,
                $stdErr
            );
            return new RemoveFileOnDestruct($process, $tmpFile);
        }
    }

    /**
     * PHP's ssh2 extension doesn't provide a means to get the exit code of a command, so we have to
     * munge the command to print the exit code after it finishes, and then parse it out.
     * @param string   $command
     * @param \Closure $stdOut
     * @param \Closure $stdErr
     * @return SSHProcess
     */
    private function runImplHandleExitCode($command, \Closure $stdOut, \Closure $stdErr) {
        $marker  = "*EXIT CODE: ";
        $wrapped = "$command\necho -nE {$this->escapeCmd( $marker )}$?";
        $buffer  = '';
        $stdOut  = function ($data) use ($stdOut, &$buffer, $marker) {
            $buffer .= $data;

            // Stop at the start of the marker, if present
            $pos = strrpos($buffer, $marker);

            // If we didn't find a marker, we need to check if the string ends with
            // the start of the marker.
            if ($pos === false) {
                // Starting at len(marker) bytes short of the end
                $pos = max(0, strlen($buffer) - strlen($marker));

                // As long as the remaining buffer at this point is not the start of a marker
                while (substr($buffer, $pos) !== substr($marker, 0, strlen($buffer) - $pos)) {
                    // Move forward
                    $pos++;
                }
            }

            // Send all bytes up to $pos, so we keep the marker
            $stdOut((string)substr($buffer, 0, $pos));
            $buffer = substr($buffer, $pos);
        };

        // When we need the exit code, we need to parse it out of $buffer
        $getExitCode = function () use (&$buffer, $marker) {
            // Make sure $buffer starts with the marker
            Assert::equal($marker, substr($buffer, 0, strlen($marker)));

            // The exit code will be whatever is after the marker
            return (int)substr($buffer, strlen($marker));
        };

        return $this->sshRunCommand($wrapped, $stdOut, $stdErr, $getExitCode);
    }

    function time() {
        return (int)substr($this->exec('date +%s'), 0, -1);
    }

    /**
     * @param string   $command
     * @param \Closure $onStdOut
     * @param \Closure $onStdErr
     * @param \Closure $getExitCode
     * @return SSHProcess
     */
    private function sshRunCommand($command, \Closure $onStdOut, \Closure $onStdErr, \Closure $getExitCode) {
        $this->connect();

        if (isset($this->cwd))
            $command = "cd {$this->escapeCmd( $this->cwd )}\n$command";

        return new SSHProcess($this->ssh, $command, $onStdOut, $onStdErr, $getExitCode);
    }

    function cd($dir) {
        $dir = $this->exec("cd {$this->escapeCmd( $dir )} && pwd");
        $dir = substr($dir, 0, -1);

        $this->cwd = $dir;
    }

    function pwd() {
        $this->connect();

        return $this->cwd;
    }

    function describe() {
        return "$this->user@$this->host";
    }
}


