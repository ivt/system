<?php

namespace IVT\System\_Internal\Local;

use IVT\Assert;
use IVT\System\_Internal\FOpenWrapperFile;
use IVT\System\ForwardedPort;
use IVT\System\Process;
use IVT\System\System;
use Symfony\Component\Process\Process as SymfonyProcess;

class LocalFile extends FOpenWrapperFile {
    function readlink() {
        clearstatcache(true);

        return Assert::string(readlink($this->path()));
    }

    protected function pathToUrl($path) {
        if (DIRECTORY_SEPARATOR === '\\')
            return \PCRE::match('^([A-Za-z]:\\\\|\\\\\\\\|\\\\)', $path, 'D') ? $path : ".\\$path";
        else
            return substr($path, 0, 1) === '/' ? $path : "./$path";
    }

    function chmod($mode) {
        Assert::true(chmod($this->path(), $mode));
    }

    function realpath() {
        clearstatcache(true);

        return Assert::string(realpath($this->path()));
    }
}

class LocalPort extends ForwardedPort {
    private $host, $port;

    /**
     * @param string $host
     * @param int    $port
     */
    function __construct($host, $port) {
        $this->host = $host;
        $this->port = $port;
    }

    function localHost() {
        return $this->host;
    }

    function localPort() {
        return $this->port;
    }
}

class LocalProcess extends Process {
    /**
     * @var SymfonyProcess[]
     */
    public static $processes = array();

    /**
     * When PHP shuts down due to a fatal error, it doesn't call the object destructors. Consequently, the processes
     * run through this class are left hanging as children, and PHP waits for them to finish. If they are processes
     * which run indefinitely until killed, PHP itself ends up stuck forever.
     *
     * Shutdown handlers *are* run in the case of a fatal error, even if destructors aren't. Therefore, bind
     * a shutdown handler which will kill any processes left running.
     */
    private static function bindShutdownHandler() {
        static $bound = false;
        if ($bound)
            return;
        $bound = true;
        register_shutdown_function(function () {
            foreach (LocalProcess::$processes as $k => $v) {
                $v->stop();
                unset(LocalProcess::$processes[$k]);
            }
        });
    }

    private $process;

    /**
     * @param string   $command
     * @param string   $stdIn
     * @param \Closure $stdOut
     * @param \Closure $stdErr
     */
    function __construct($command, $stdIn, \Closure $stdOut, \Closure $stdErr) {
        self::bindShutdownHandler();

        $this->process = new SymfonyProcess($command, null, null, $stdIn, null);
        $this->process->start(function ($type, $data) use ($stdOut, $stdErr) {
            if ($type === SymfonyProcess::OUT)
                $stdOut($data);

            if ($type === SymfonyProcess::ERR)
                $stdErr($data);
        });

        self::$processes[spl_object_hash($this->process)] = $this->process;
    }

    function __destruct() {
        unset(self::$processes[spl_object_hash($this->process)]);
    }

    function isDone() {
        return $this->process->isTerminated();
    }

    function wait() {
        return $this->process->wait();
    }

    function stop() {
        $this->process->stop();
    }
}

class LocalSystem extends System {
    static function create() {
        return new self;
    }

    function escapeCmd($arg) {
        if ($this->isWindows())
            return '"' . addcslashes($arg, '\\"') . '"';
        else
            return parent::escapeCmd($arg);
    }

    private function isWindows() {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    function isPortOpen($host, $port, $timeout) {
        set_error_handler(function () { });
        $fp = @fsockopen("tcp://$host", $port, $errno, $errstr, $timeout);
        restore_error_handler();
        if ($fp === false)
            return false;
        fclose($fp);

        return true;
    }

    function file($path) {
        return new LocalFile($this, $path);
    }

    function dirSep() {
        return DIRECTORY_SEPARATOR;
    }

    function runAsync(
        $command,
        $stdIn = '',
        \Closure $stdOut = null,
        \Closure $stdErr = null
    ) {
        return new LocalProcess(
            $command,
            $stdIn,
            $stdOut ?: function () { },
            $stdErr ?: function () { }
        );
    }

    function time() {
        return time();
    }

    /**
     * @return int
     */
    function getmypid() {
        return getmypid();
    }

    function cd($dir) {
        Assert::true(chdir($dir));
    }

    function pwd() {
        return Assert::string(getcwd());
    }

    function describe() {
        return 'local';
    }

    function forwardPort($host, $port) {
        return new LocalPort($host, $port);
    }
}
