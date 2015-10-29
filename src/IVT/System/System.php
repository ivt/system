<?php

namespace IVT\System;

use IVT\System\_Internal\Local\LocalSystem;
use IVT\System\_Internal\Logging\LoggingSystem;
use IVT\System\_Internal\SSH\SSHSystem;
use IVT\System\SSH\SSHAuth;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

abstract class System implements FileSystem, Loggable {
    /**
     * @return self
     */
    final static function local() {
        return new LocalSystem;
    }

    /**
     * @param string   $user
     * @param string   $host
     * @param int|null $port
     * @param SSHAuth  $auth
     * @return self
     */
    final static function ssh($user, $host, $port, SSHAuth $auth) {
        return new SSHSystem($user, $host, $port ?: 22, $auth);
    }

    /**
     * @param string $host
     * @param int    $port
     * @return ForwardedPort
     */
    abstract function forwardPort($host, $port);

    /**
     * @param LoggerInterface $log
     * @param string          $level
     * @return System
     */
    final function wrapLogging(LoggerInterface $log, $level = LogLevel::DEBUG) {
        return new LoggingSystem($this, $log, $level);
    }

    function escapeCmd($arg) {
        $arg1    = str_replace(str_split("=:_+./-"), '', $arg);
        $isValid = $arg1 === '' || ctype_alnum($arg1);

        return $isValid && $arg !== '' ? $arg : escapeshellarg($arg);
    }

    final function escapeCmdArgs(array $args) {
        foreach ($args as &$arg)
            $arg = $this->escapeCmd($arg);

        return join(' ', $args);
    }

    final function execArgs(array $command, $stdIn = '') {
        return $this->runCommandArgs($command, $stdIn)->assertSuccess()->stdOut();
    }

    final function exec($command, $stdIn = '') {
        return $this->runCommand($command, $stdIn)->assertSuccess()->stdOut();
    }

    /**
     * @param string   $dir
     * @param \Closure $f
     * @return mixed
     * @throws \Exception
     */
    final function inDir($dir, \Closure $f) {
        $cwd = $this->pwd();
        try {
            $this->cd($dir);
            $result = $f();
            $this->cd($cwd);
            return $result;
        } catch (\Exception $e) {
            $this->cd($cwd);
            throw $e;
        }
    }

    /**
     * @param string $linkFile
     * @param string $linkContents
     */
    final function writeLink($linkFile, $linkContents) {
        $this->execArgs(array('ln', '-sTf', $linkContents, $linkFile));
    }

    /**
     * @param string $directory
     * @return string
     */
    final function createTarXz($directory) {
        return $this->execArgs(array('tar', '-cJ', '-C', $directory, '.'));
    }

    /**
     * @param string $tarball
     * @param string $directory
     */
    final function extractTarXz($tarball, $directory) {
        $this->execArgs(array('tar', '-xJ', '-C', $directory), $tarball);
    }

    /**
     * @param string $from
     * @param string $to
     */
    final function copy($from, $to) {
        $this->execArgs(array('cp', '-rT', $from, $to));
    }

    final function ensureNotExists($path) {
        $this->execArgs(array('rm', '-rf', $path));
    }

    /**
     * @param string $search
     * @param string $replace
     * @param string $file
     */
    final function replaceInFile($search, $replace, $file) {
        foreach (str_split('\\/^.[$()|*+?{') as $char)
            $search = str_replace($char, "\\$char", $search);

        foreach (str_split('\\/&') as $char)
            $replace = str_replace($char, "\\$char", $replace);

        $this->execArgs(array('sed', '-ri', "s/$search/$replace/g", $file));
    }

    /**
     * @param string $command
     * @param string $stdIn
     * @return CommandResult
     */
    final function runCommandAsync($command, $stdIn = '') {
        return new CommandResult($this, $command, $stdIn);
    }

    /**
     * @param string[] $command
     * @param string   $stdIn
     * @return CommandResult
     */
    final function runCommandAsyncArgs(array $command, $stdIn = '') {
        return new CommandResult($this, $this->escapeCmdArgs($command), $stdIn);
    }

    /**
     * @param string $command
     * @param string $stdIn
     * @return CommandResult
     */
    final function runCommand($command, $stdIn = '') {
        $result = $this->runCommandAsync($command, $stdIn);
        $result->wait();
        return $result;
    }

    /**
     * @param string[] $command
     * @param string   $stdIn
     * @return CommandResult
     */
    final function runCommandArgs(array $command, $stdIn = '') {
        return $this->runCommand($this->escapeCmdArgs($command), $stdIn);
    }

    /**
     * @param string $host
     * @param int    $port
     * @param int    $timeout Timeout in seconds
     * @return bool
     */
    function isPortOpen($host, $port, $timeout) {
        $cmd = array('nc', '-z', '-w', $timeout, '--', $host, $port);

        return $this->runCommandArgs($cmd)->succeeded();
    }

    /**
     * Unix timestamp
     *
     * @return int
     */
    abstract function time();

    /**
     * @param string   $command
     * @param string   $stdIn
     * @param \Closure $stdOut
     * @param \Closure $stdErr
     * @return Process
     */
    abstract function runAsync(
        $command,
        $stdIn = '',
        \Closure $stdOut = null,
        \Closure $stdErr = null
    );

    /**
     * Alias for applyLogging()
     * @param System $system
     * @return System
     * @deprecated
     * @see System::applyLogging
     */
    final function wrap(System $system) {
        return $this->applyLogging($system);
    }

    /**
     * @return string
     */
    abstract function describe();

    function applyLogging(Loggable $loggable) {
        return $loggable;
    }
}

