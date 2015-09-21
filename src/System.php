<?php

namespace IVT\System;

use IVT\Log;

abstract class System implements FileSystem
{
    /* @param SystemVisitor $visitor
     * @return mixed
     */
    abstract function applyVisitor(SystemVisitor $visitor);

    final static function removeSecrets($string)
    {
        $gitHub = '(\w+(:\w+)?)(?=@github.com)';
        $awsKey = '(?<=\-\-key=)\S+';
        $awsSecret = '(?<=\-\-secret=)\S+';

        return \PCRE::replace("$gitHub|$awsKey|$awsSecret", $string, '[HIDDEN]');
    }

    final function wrapLogging(Log $log)
    {
        return new LoggingSystem($this, $log);
    }

    function escapeCmd($arg)
    {
        $arg1 = str_replace(str_split("=:_+./-"), '', $arg);
        $isValid = $arg1 === '' || ctype_alnum($arg1);

        return $isValid && $arg !== '' ? $arg : escapeshellarg($arg);
    }

    final function escapeCmdArgs(array $args)
    {
        foreach ($args as &$arg)
            $arg = $this->escapeCmd($arg);

        return join(' ', $args);
    }

    final function execArgs(array $command, $stdIn = '')
    {
        return $this->runCommandArgs($command, $stdIn)->assertSuccess()->stdOut();
    }

    final function exec($command, $stdIn = '')
    {
        return $this->runCommand($command, $stdIn)->assertSuccess()->stdOut();
    }

    /**
     * @param string $dir
     * @param \Closure $f
     * @return mixed
     * @throws \Exception
     */
    final function inDir($dir, \Closure $f)
    {
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
    final function writeLink($linkFile, $linkContents)
    {
        $this->execArgs(array('ln', '-sTf', $linkContents, $linkFile));
    }

    /**
     * @param string $directory
     * @return string
     */
    final function createTarXz($directory)
    {
        return $this->execArgs(array('tar', '-cJ', '-C', $directory, '.'));
    }

    /**
     * @param string $tarball
     * @param string $directory
     */
    final function extractTarXz($tarball, $directory)
    {
        $this->execArgs(array('tar', '-xJ', '-C', $directory), $tarball);
    }

    /**
     * @param string $from
     * @param string $to
     */
    final function copy($from, $to)
    {
        $this->execArgs(array('cp', '-rT', $from, $to));
    }

    final function ensureNotExists($path)
    {
        $this->execArgs(array('rm', '-rf', $path));
    }

    /**
     * @param string $search
     * @param string $replace
     * @param string $file
     */
    final function replaceInFile($search, $replace, $file)
    {
        foreach (str_split('\\/^.[$()|*+?{') as $char)
            $search = str_replace($char, "\\$char", $search);

        foreach (str_split('\\/&') as $char)
            $replace = str_replace($char, "\\$char", $replace);

        $this->execArgs(array('sed', '-ri', "s/$search/$replace/g", $file));
    }

    final function replaceInFileMany($file, array $replacements)
    {
        foreach ($replacements as $search => $replace)
            $this->replaceInFile($search, $replace, $file);
    }

    final function now()
    {
        // The timezone passed in the constructor of \DateTime is ignored in the case of a timestamp, because a
        // unix timestamp is considered to have a built-in timezone of UTC.
        $timezone = new \DateTimeZone(date_default_timezone_get());
        $dateTime = new \DateTime("@{$this->time()}", $timezone);
        $dateTime->setTimezone($timezone);

        return $dateTime;
    }

    /**
     * @param string $command
     * @param string $stdIn
     * @return CommandResult
     */
    final function runCommandAsync($command, $stdIn = '')
    {
        return new CommandResult($this, $command, $stdIn);
    }

    /**
     * @param string $command
     * @param string $stdIn
     * @return CommandResult
     */
    final function runCommand($command, $stdIn = '')
    {
        $result = $this->runCommandAsync($command, $stdIn);
        $result->wait();
        return $result;
    }

    /**
     * @param string[] $command
     * @param string $stdIn
     *
     * @return CommandResult
     */
    final function runCommandArgs(array $command, $stdIn = '')
    {
        return $this->runCommand($this->escapeCmdArgs($command), $stdIn);
    }

    /**
     * @param string[] $commands
     * @return CommandResult[]
     */
    final function runCommandAsyncMany(array $commands)
    {
        /** @var CommandResult[] $processes */
        $processes = array();
        foreach ($commands as $command)
            $processes[] = $this->runCommandAsync($command);
        return $processes;
    }

    final function runAsync($command, $stdIn = '')
    {
        return $this->runImpl($command, $stdIn, function () {
        }, function () {
        });
    }

    function isPortOpen($host, $port, $timeout)
    {
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
     * @param string $command
     * @param string $stdIn
     * @param \Closure $stdOut
     * @param \Closure $stdErr
     * @return Process
     */
    abstract function runImpl($command, $stdIn, \Closure $stdOut, \Closure $stdErr);

    /**
     * If this System happens to be a wrapper around another System, this
     * applies the same wrapping to the given system.
     * @param System $system
     * @return System
     */
    function wrap(System $system)
    {
        return $system;
    }

    /**
     * @return string
     */
    abstract function describe();
}

