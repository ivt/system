<?php

namespace IVT\System;

use IVT\Assert;

class LocalFile extends FOpenWrapperFile
{
    private $system;

    function __construct(LocalSystem $system, $path)
    {
        parent::__construct($system, $path);
        $this->system = $system;
    }

    function readlink()
    {
        clearstatcache(true);

        return Assert::string(readlink($this->path()));
    }

    protected function pathToUrl($path)
    {
        if (DIRECTORY_SEPARATOR === '\\')
            return \PCRE::match('^([A-Za-z]:\\\\|\\\\\\\\|\\\\)', $path, 'D') ? $path : ".\\$path";
        else
            return starts_with($path, '/') ? $path : "./$path";
    }

    function chmod($mode)
    {
        Assert::true(chmod($this->path(), $mode));
    }

    function realpath()
    {
        clearstatcache(true);

        return Assert::string(realpath($this->path()));
    }

    function changeGroup($group)
    {
        Assert::true(chgrp($this->path(), $group));
    }

    function changeOwner($owner)
    {
        Assert::true(chown($this->path(), $owner));
    }
}
