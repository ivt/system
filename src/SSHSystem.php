<?php

namespace IVT\System;

use IVT\Assert;

class SSHSystem extends System
{
    /** @var SSHAuth */
    private $auth;
    /** @var resource */
    private $ssh;
    /** @var resource */
    private $sftp;
    /** @var string */
    private $cwd;
    /** @var SSHForwardedPorts */
    private $forwardedPorts;

    function forwardedPorts()
    {
        return $this->forwardedPorts;
    }

    function __construct(SSHAuth $auth)
    {
        $this->auth = $auth;
        $this->forwardedPorts = new SSHForwardedPorts($auth);
    }

    function applyVisitor(SystemVisitor $visitor)
    {
        return $visitor->visitSSHSystem($this);
    }

    private function connect()
    {
        if ($this->ssh)
            return;

        $this->ssh = $this->auth->connect();
        $this->sftp = Assert::resource(ssh2_sftp($this->ssh));
        $this->cwd = Assert::string(substr($this->exec('pwd'), 0, -1));
    }

    function file($path)
    {
        $this->connect();

        return new SSHFile($this, $this->sftp, $path);
    }

    function dirSep()
    {
        return '/';
    }

    function runImpl($command, $stdIn, \Closure $stdOut, \Closure $stdErr)
    {
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
            $tmpFile = $this->file("/tmp/tmp-ssh-command-input-" . random_string(12));
            $tmpFile->write($stdIn);

            $process = $this->runImplHandleExitCode(
                "$command < {$this->escapeCmd( $tmpFile->path() )}",
                $stdOut,
                $stdErr
            );
            $process = new RemoveFileOnDestruct($process, $tmpFile);

            return $process;
        }
    }

    /**
     * PHP's ssh2 extension doesn't provide a means to get the exit code of a command, so we have to
     * munge the command to print the exit code after it finishes, and then parse it out.
     * @param string $command
     * @param \Closure $stdOut
     * @param \Closure $stdErr
     * @return SSHProcess
     */
    private function runImplHandleExitCode($command, \Closure $stdOut, \Closure $stdErr)
    {
        $marker = "*EXIT CODE: ";
        $wrapped = "$command\necho -nE {$this->escapeCmd( $marker )}$?";
        $buffer = '';
        $stdOut = function ($data) use ($stdOut, &$buffer, $marker) {
            $buffer .= $data;

            // Stop at the start of the marker, if present
            $pos = strrpos($buffer, $marker);

            // If we didn't find a marker, we need to check if the string ends with
            // the start of the marker.
            if ($pos === false) {
                // Starting at len(marker) bytes short of the end
                $pos = max(0, strlen($buffer) - strlen($marker));

                // As long as the remaining buffer at this point is not the start of a marker
                while (!starts_with($marker, substr($buffer, $pos)))
                    // Move forward
                    $pos++;
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

    function time()
    {
        return (int)substr($this->exec('date +%s'), 0, -1);
    }

    /**
     * @param string $command
     * @param \Closure $onStdOut
     * @param \Closure $onStdErr
     * @param \Closure $getExitCode
     * @return SSHProcess
     */
    private function sshRunCommand($command, \Closure $onStdOut, \Closure $onStdErr, \Closure $getExitCode)
    {
        $this->connect();

        if (isset($this->cwd))
            $command = "cd {$this->escapeCmd( $this->cwd )}\n$command";

        return new SSHProcess($this->ssh, $command, $onStdOut, $onStdErr, $getExitCode);
    }

    function cd($dir)
    {
        $dir = $this->exec("cd {$this->escapeCmd( $dir )} && pwd");
        $dir = substr($dir, 0, -1);

        $this->cwd = $dir;
    }

    function pwd()
    {
        $this->connect();

        return $this->cwd;
    }

    function describe()
    {
        return $this->auth->describe();
    }
}

