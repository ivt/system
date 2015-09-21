<?php

namespace IVT\System;

class LoggingFile extends WrappedFile
{
    private $log;

    function __construct(System $system, $path, File $file, LoggingSystem $log)
    {
        parent::__construct($system, $path, $file);
        $this->log = $log;
    }

    function fileType()
    {
        $type = parent::fileType();
        $this->log("file type => $type");

        return $type;
    }

    function isFile()
    {
        $result = parent::isFile();
        $this->log("is file => " . yes_no($result));

        return $result;
    }

    function scanDir()
    {
        $result = parent::scanDir();
        $this->log("scandir => " . Logger::summarizeArray($result));

        return $result;
    }

    function isDir()
    {
        $result = parent::isDir();
        $this->log("is dir => " . yes_no($result));

        return $result;
    }

    function mkdir($mode = 0777, $recursive = false)
    {
        $this->log('mkdir, mode ' . decoct($mode) . ', recursive: ' . yes_no($recursive));
        parent::mkdir($mode, $recursive);
    }

    function isLink()
    {
        $result = parent::isLink();
        $this->log("is link => " . yes_no($result));

        return $result;
    }

    function readlink()
    {
        $result = parent::readlink();
        $this->log("read link => $result");

        return $result;
    }

    function exists()
    {
        $result = parent::exists();
        $this->log("exists => " . yes_no($result));

        return $result;
    }

    function perms()
    {
        $result = parent::perms();
        $this->log('perms => ' . decoct($result));
        return $result;
    }

    function size()
    {
        $size = parent::size();
        $this->log("size => " . humanize_bytes($size));

        return $size;
    }

    function unlink()
    {
        $this->log("unlink");
        parent::unlink();
    }

    function mtime()
    {
        $result = parent::mtime();
        $this->log("mtime => $result");

        return $result;
    }

    function ctime()
    {
        $result = parent::ctime();
        $this->log("ctime => $result");

        return $result;
    }

    function read($offset = 0, $maxLength = null)
    {
        $result = parent::read($offset, $maxLength);

        $log = 'read';
        if ($offset !== 0)
            $log .= ", offset: $offset";
        if ($maxLength !== null)
            $log .= ", length: $maxLength";

        $this->log("$log => " . Logger::summarize($result));

        return $result;
    }

    function write($contents)
    {
        $this->log("write " . Logger::summarize($contents));
        parent::write($contents);
        return $this;
    }

    function create($contents)
    {
        $this->log("create " . Logger::summarize($contents));
        parent::create($contents);
    }

    function append($contents)
    {
        $this->log("append " . Logger::summarize($contents));
        parent::append($contents);
    }

    function rmdir()
    {
        $this->log("rmdir");
        parent::rmdir();
    }

    function chmod($mode)
    {
        $this->log('chmod ' . decoct($mode));
        parent::chmod($mode);
    }

    function realpath()
    {
        $result = parent::realpath();
        $this->log("realpath => $result");

        return $result;
    }

    protected function copyImpl($dest)
    {
        $this->log("copy to $dest");
        parent::copyImpl($dest);
    }

    function log($line)
    {
        $this->log->log("{$this->path()}: $line");
    }

    protected function renameImpl($to)
    {
        $this->log("rename to $to");
        parent::renameImpl($to);
    }
}
