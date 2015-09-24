<?php

namespace IVT\System\_Internal\Logging;

use IVT\System\_Internal;
use IVT\System\_Internal\Wrapped\WrappedFile;
use IVT\System\_Internal\Wrapped\WrappedSystem;
use IVT\System\File;
use IVT\System\Loggable;
use IVT\System\LogUtils;
use IVT\System\PrefixLogger;
use IVT\System\System;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Causes continuous chunks of binary data to be sent to the underlying
 * stream in a single chunk.
 */
class BinaryBuffer {
    private $buffer = '';
    private $delegate;

    /**
     * @param callable $delegate
     */
    function __construct($delegate) {
        $this->delegate = $delegate;
    }

    function __invoke($data) {
        if ($data === '')
            return;

        if (!mb_check_encoding($data, 'UTF-8')) {
            $this->buffer .= $data;

            if (strlen($this->buffer) > 10000000)
                $this->flush();
        } else {
            $this->flush();
            $this->send($data);
        }
    }

    function __destruct() {
        $this->flush();
    }

    private function flush() {
        $this->send($this->buffer);
        $this->buffer = '';
    }

    private function send($s) {
        $f = $this->delegate;
        $f($s);
    }
}

class LinePrefixStream {
    private $buffer = '', $firstPrefix, $prefix, $delegate, $lineNo = 0;

    /**
     * @param string   $firstPrefix
     * @param string   $prefix
     * @param \Closure $delegate
     */
    function __construct($firstPrefix, $prefix, \Closure $delegate) {
        $this->firstPrefix = $firstPrefix;
        $this->prefix      = $prefix;
        $this->delegate    = $delegate;
    }

    function __invoke($data) {
        $data = $this->clean($data);

        $this->buffer .= $data;
        $lines        = explode("\n", $this->buffer);
        $this->buffer = array_pop($lines);

        foreach ($lines as $line) {
            $prefix = $this->lineNo == 0 ? $this->firstPrefix : $this->prefix;
            $this->send("$prefix$line\n");
            $this->lineNo++;
        }
    }

    function __destruct() {
        if ($this->buffer !== '') {
            $this("\n");
            $this->send("^ no end of line\n");
        }
    }

    /**
     * @param string $bytes
     * @return string
     */
    private function clean($bytes) {
        if (mb_check_encoding($bytes, 'UTF-8'))
            return $bytes;
        else
            return "[" . _Internal\humanize_bytes(strlen($bytes)) . " binary]\n";
    }

    private function send($data) {
        $delegate = $this->delegate;
        $delegate($data);
    }
}

class LoggingFile extends WrappedFile {
    /** @var LoggerInterface */
    private $log;
    /** @var string */
    private $level;

    function __construct(File $file, LoggerInterface $log, $level = LogLevel::DEBUG) {
        parent::__construct($file);
        $this->log   = new PrefixLogger($log, "$file->path: ");
        $this->level = $level;
    }

    function fileType() {
        $type = parent::fileType();
        $this->log("file type => $type");

        return $type;
    }

    function isFile() {
        $result = parent::isFile();
        $this->log("is file => " . _Internal\yes_no($result));

        return $result;
    }

    function scanDir() {
        $result = parent::scanDir();
        $this->log("scandir => " . LogUtils::summarizeArray($result));

        return $result;
    }

    function isDir() {
        $result = parent::isDir();
        $this->log("is dir => " . _Internal\yes_no($result));

        return $result;
    }

    function mkdir($mode = 0777, $recursive = false) {
        $this->log('mkdir, mode ' . decoct($mode) . ', recursive: ' . _Internal\yes_no($recursive));
        parent::mkdir($mode, $recursive);
    }

    function isLink() {
        $result = parent::isLink();
        $this->log("is link => " . _Internal\yes_no($result));

        return $result;
    }

    function readlink() {
        $result = parent::readlink();
        $this->log("read link => $result");

        return $result;
    }

    function exists() {
        $result = parent::exists();
        $this->log("exists => " . _Internal\yes_no($result));

        return $result;
    }

    function perms() {
        $result = parent::perms();
        $this->log('perms => ' . decoct($result));
        return $result;
    }

    function size() {
        $size = parent::size();
        $this->log("size => " . _Internal\humanize_bytes($size));

        return $size;
    }

    function unlink() {
        $this->log("unlink");
        parent::unlink();
    }

    function mtime() {
        $result = parent::mtime();
        $this->log("mtime => $result");

        return $result;
    }

    function ctime() {
        $result = parent::ctime();
        $this->log("ctime => $result");

        return $result;
    }

    function read($offset = 0, $maxLength = null) {
        $result = parent::read($offset, $maxLength);

        $log = 'read';
        if ($offset !== 0)
            $log .= ", offset: $offset";
        if ($maxLength !== null)
            $log .= ", length: $maxLength";

        $this->log("$log => " . LogUtils::summarize($result));

        return $result;
    }

    function write($contents) {
        $this->log("write " . LogUtils::summarize($contents));
        parent::write($contents);
        return $this;
    }

    function create($contents) {
        $this->log("create " . LogUtils::summarize($contents));
        parent::create($contents);
    }

    function append($contents) {
        $this->log("append " . LogUtils::summarize($contents));
        parent::append($contents);
    }

    function rmdir() {
        $this->log("rmdir");
        parent::rmdir();
    }

    function chmod($mode) {
        $this->log('chmod ' . decoct($mode));
        parent::chmod($mode);
    }

    function realpath() {
        $result = parent::realpath();
        $this->log("realpath => $result");

        return $result;
    }

    protected function copyImpl($dest) {
        $this->log("copy to $dest");
        parent::copyImpl($dest);
    }

    function log($line) {
        $this->log->log($this->level, $line);
    }

    protected function renameImpl($to) {
        $this->log("rename to $to");
        parent::renameImpl($to);
    }
}

class LoggingSystem extends WrappedSystem {
    /** @var PrefixLogger */
    private $logger;
    /** @var string */
    private $level;

    function __construct(System $system, LoggerInterface $logger, $level = LogLevel::DEBUG) {
        parent::__construct($system);

        $this->logger = new PrefixLogger($logger, "{$this->describe()}: ");
        $this->level  = $level;
    }

    function log($message) {
        $this->logger->log($this->level, $message);
    }

    function runAsync(
        $command,
        $stdIn = '',
        \Closure $stdOut = null,
        \Closure $stdErr = null
    ) {
        $self = $this;
        $log  = function ($data) use ($self) {
            foreach (_Internal\split_lines($data) as $line)
                $self->log($line);
        };
        $cmd  = new BinaryBuffer(new LinePrefixStream('>>> ', '... ', $log));
        $in   = new BinaryBuffer(new LinePrefixStream('--- ', '--- ', $log));
        $out  = new BinaryBuffer(new LinePrefixStream('  ', '  ', $log));
        $err  = new BinaryBuffer(new LinePrefixStream('! ', '! ', $log));

        $cmd("$command\n");
        unset($cmd);

        $in($stdIn);
        unset($in);

        $process = parent::runAsync(
            $command,
            $stdIn,
            function ($data) use ($out, $stdOut) {
                $out($data);
                $stdOut($data);
            },
            function ($data) use ($err, $stdErr) {
                $err($data);
                $stdErr($data);
            }
        );
        unset($out);
        unset($err);
        gc_collect_cycles();

        return $process;
    }

    function cd($dir) {
        $this->log("cd $dir");
        parent::cd($dir);
    }

    function pwd() {
        $result = parent::pwd();
        $this->log("pwd => $result");

        return $result;
    }

    function isPortOpen($host, $port, $timeout) {
        $result = parent::isPortOpen($host, $port, $timeout);
        $this->log("is $host:$port open => " . _Internal\yes_no($result));

        return $result;
    }

    function applyLogging(Loggable $loggable) {
        return parent::applyLogging($loggable)->wrapLogging($this->logger->getInnerLogger(), $this->level);
    }

    function file($path) {
        return new LoggingFile(parent::file($path), $this->logger, $this->level);
    }
}
