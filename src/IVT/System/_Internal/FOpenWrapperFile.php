<?php

namespace IVT\System\_Internal;

use IVT\Assert;
use IVT\System\File;
use IVT\System\FOpenFailed;

abstract class FOpenWrapperFile extends File {
    function isFile() {
        clearstatcache(true);

        return is_file($this->url());
    }

    function isExecutable() {
        clearstatcache(true);

        return is_executable($this->url());
    }

    function isDir() {
        clearstatcache(true);

        return is_dir($this->url());
    }

    function mkdir($mode = 0777, $recursive = false) {
        clearstatcache(true);

        Assert::true(mkdir($this->url(), $mode, $recursive), "Failed to create directory at {$this->url()}");
    }

    function isLink() {
        clearstatcache(true);

        return is_link($this->url());
    }

    function exists() {
        clearstatcache(true);

        return file_exists($this->url());
    }

    function size() {
        clearstatcache(true);

        return Assert::int(filesize($this->url()), "Failed to get file size on {$this->url()}");
    }

    function unlink() {
        clearstatcache(true);

        Assert::true(unlink($this->url()), "Failed to unlink file at {$this->url()}");
    }

    function ctime() {
        clearstatcache(true);

        return Assert::int(filectime($this->url()), "Failed to read create time on file {$this->url()}");
    }

    function fileType() {
        clearstatcache(true);

        return Assert::string(filetype($this->url()), "Failed to get file type of {$this->url()}");
    }

    function perms() {
        clearstatcache(true);

        return Assert::int(fileperms($this->url()), "Failed to get file permissions on {$this->url()}");
    }

    function rmdir() {
        Assert::true(rmdir($this->url()), "Failed to remove directory at {$this->url()}");
    }

    function create($contents) {
        $this->writeImpl($contents, 'xb');
    }

    function append($contents) {
        $this->writeImpl($contents, 'ab');
    }

    function write($contents) {
        $this->writeImpl($contents, 'wb');
        return $this;
    }

    function read($offset = 0, $maxLength = null) {
        clearstatcache(true);

        if ($maxLength === null) {
            return Assert::string(file_get_contents($this->url(), false, null, $offset),
                "Failed to read file at {$this->url()}");
        } else {
            return Assert::string(file_get_contents($this->url(), false, null, $offset, $maxLength),
                "Failed to read file at {$this->url()}");
        }
    }

    function scanDir() {
        clearstatcache(true);

        return Assert::array_(scandir($this->url()), "Failed to scan directory at {$this->url()}");
    }

    function mtime() {
        clearstatcache(true);

        return Assert::int(filemtime($this->url()), "Failed to read mod time on file at {$this->url()}");
    }

    function stream(\Closure $callback, $chunkSize = self::DEFAULT_CHUNK_SIZE) {
        $url    = $this->url();
        $handle = fopen($url, 'rb');

        if ($handle === false)
            throw new FOpenFailed("fopen '$url' failed");

        Assert::resource($handle);

        try {
            while (!Assert::bool(feof($handle))) {
                $callback(Assert::string(fread($handle, $chunkSize)));
            }
        } catch (\Exception $e) {
            Assert::true(fclose($handle));
            throw $e;
        }

        Assert::true(fclose($handle));
    }

    protected function renameImpl($to) {
        Assert::true(rename($this->url(), $this->pathToUrl($to)), "Failed to rename file at {$this->url()}");
    }

    protected function copyImpl($dest) {
        Assert::true(copy($this->url(), $this->pathToUrl($dest)),
            "Failed to copy file at {$this->url()} to {$this->pathToUrl( $dest )}");
    }

    protected function url() {
        return $this->pathToUrl($this->path());
    }

    /**
     * @param string $path
     * @return string
     */
    abstract protected function pathToUrl($path);

    private function writeImpl($data, $mode) {
        Assert::resource($handle = fopen($this->url(), $mode), "Failed to open file for write at {$this->url()}");
        Assert::equal(fwrite($handle, $data), strlen($data), "Failed to write to file at {$this->url()}");
        Assert::true(fclose($handle), "Failed to close file handle after write() on {$this->url()}");
    }
}

