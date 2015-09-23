<?php

namespace IVT\System;

interface FileSystem {
    /**
     * @return string
     */
    function pwd();

    /**
     * @param string $dir
     */
    function cd($dir);

    /**
     * @param string $path
     *
     * @return File
     */
    function file($path);

    /**
     * @return string
     */
    function dirSep();
}

