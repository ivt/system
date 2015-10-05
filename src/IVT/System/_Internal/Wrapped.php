<?php

namespace IVT\System\_Internal\Wrapped;

use IVT\System\File;
use IVT\System\Loggable;
use IVT\System\System;

class WrappedFile extends File {
    private $file;

    function __construct(File $file) {
        parent::__construct($file->system, $file->path);
        $this->file = $file;
    }

    function fileType() {
        return $this->file->fileType();
    }

    function isFile() {
        return $this->file->isFile();
    }

    function scanDir() {
        return $this->file->scanDir();
    }

    function isDir() {
        return $this->file->isDir();
    }

    function mkdir($mode = 0777, $recursive = false) {
        $this->file->mkdir($mode, $recursive);
    }

    function isLink() {
        return $this->file->isLink();
    }

    function readlink() {
        return $this->file->readlink();
    }

    function exists() {
        return $this->file->exists();
    }

    function size() {
        return $this->file->size();
    }

    function unlink() {
        $this->file->unlink();
    }

    function mtime() {
        return $this->file->mtime();
    }

    function ctime() {
        return $this->file->ctime();
    }

    function read($offset = 0, $maxLength = null) {
        return $this->file->read($offset, $maxLength);
    }

    function write($contents) {
        $this->file->write($contents);
        return $this;
    }

    function create($contents) {
        $this->file->create($contents);
    }

    function append($contents) {
        $this->file->append($contents);
    }

    function rmdir() {
        $this->file->rmdir();
    }

    function chmod($mode) {
        $this->file->chmod($mode);
    }

    function realpath() {
        return $this->file->realpath();
    }

    function perms() {
        return $this->file->perms();
    }

    function isLocal() {
        return $this->file->isLocal();
    }

    protected function renameImpl($to) {
        $this->file->renameImpl($to);
    }

    protected function copyImpl($dest) {
        $this->file->copyImpl($dest);
    }
}

class WrappedSystem extends System {
    protected $system;

    function __construct(System $system) {
        $this->system = $system;
    }

    function cd($dir) {
        $this->system->cd($dir);
    }

    function pwd() {
        return $this->system->pwd();
    }

    function escapeCmd($arg) {
        return $this->system->escapeCmd($arg);
    }

    function file($path) {
        return new WrappedFile($this->system->file($path));
    }

    function dirSep() {
        return $this->system->dirSep();
    }

    function time() {
        return $this->system->time();
    }

    function runAsync(
        $command,
        $stdIn = '',
        \Closure $stdOut = null,
        \Closure $stdErr = null
    ) {
        return $this->system->runAsync($command, $stdIn, $stdOut, $stdErr);
    }

    function isPortOpen($host, $port, $timeout) {
        return $this->system->isPortOpen($host, $port, $timeout);
    }

    function describe() {
        return $this->system->describe();
    }

    function forwardPort($host, $port) {
        return $this->system->forwardPort($host, $port);
    }

    function applyLogging(Loggable $loggable) {
        return $this->system->applyLogging($loggable);
    }
}

