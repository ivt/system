<?php

namespace IVT\System;

class WrappedFile extends File
{
    private $file;

    function __construct(System $system, $path, File $file)
    {
        parent::__construct($system, $path);
        $this->file = $file;
    }

    function fileType()
    {
        return $this->file->fileType();
    }

    function isFile()
    {
        return $this->file->isFile();
    }

    function scanDir()
    {
        return $this->file->scanDir();
    }

    function isDir()
    {
        return $this->file->isDir();
    }

    function mkdir($mode = 0777, $recursive = false)
    {
        $this->file->mkdir($mode, $recursive);
    }

    function isLink()
    {
        return $this->file->isLink();
    }

    function readlink()
    {
        return $this->file->readlink();
    }

    function exists()
    {
        return $this->file->exists();
    }

    function size()
    {
        return $this->file->size();
    }

    function unlink()
    {
        $this->file->unlink();
    }

    function mtime()
    {
        return $this->file->mtime();
    }

    function ctime()
    {
        return $this->file->ctime();
    }

    function read($offset = 0, $maxLength = null)
    {
        return $this->file->read($offset, $maxLength);
    }

    function write($contents)
    {
        $this->file->write($contents);
        return $this;
    }

    function create($contents)
    {
        $this->file->create($contents);
    }

    function append($contents)
    {
        $this->file->append($contents);
    }

    function rmdir()
    {
        $this->file->rmdir();
    }

    function chmod($mode)
    {
        $this->file->chmod($mode);
    }

    protected function renameImpl($to)
    {
        $this->file->renameImpl($to);
    }

    function realpath()
    {
        return $this->file->realpath();
    }

    protected function copyImpl($dest)
    {
        $this->file->copyImpl($dest);
    }

    function perms()
    {
        return $this->file->perms();
    }
}
