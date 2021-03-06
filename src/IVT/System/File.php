<?php

namespace IVT\System;

abstract class File {
    const DEFAULT_CHUNK_SIZE = 65536;

    /** @var string */
    protected $path;
    /** @var FileSystem */
    protected $system;

    /**
     * @param FileSystem $system
     * @param string     $path
     */
    function __construct(FileSystem $system, $path) {
        $this->path   = $path;
        $this->system = $system;
    }

    /**
     * @param File $other
     * @return bool
     */
    final function is(self $other) {
        return $this->path === $other->path;
    }

    /**
     * @return string
     */
    final function path() {
        return $this->path;
    }

    /**
     * @return string
     * @deprecated
     */
    final function __toString() {
        return $this->path();
    }

    /**
     * @param FileSystem $system
     * @return File
     */
    final function on(FileSystem $system) {
        return $system->file($this->path);
    }

    /**
     * @param bool $followLinks
     * @return self[]
     */
    final function recursiveScan($followLinks = true) {
        $results = array($this);

        if ($this->isDir() && ($followLinks || !$this->isLink())) {
            foreach ($this->dirContents() as $file) {
                $results = array_merge($results, $file->recursiveScan($followLinks));
            }
        }

        return $results;
    }

    /**
     * @return File
     */
    final function parentDirectory() {
        return $this->system->file($this->dirname());
    }

    /**
     * @return string /blah/foo.txt => /blah
     */
    final function dirname() {
        return pathinfo($this->path, PATHINFO_DIRNAME);
    }

    /**
     * @return string /blah/foo.txt => foo.txt
     */
    final function basename() {
        return pathinfo($this->path, PATHINFO_BASENAME);
    }

    /**
     * @return string /blah/foo.txt => txt
     */
    final function extension() {
        return pathinfo($this->path, PATHINFO_EXTENSION);
    }

    /**
     * @return string /blah/foo.txt => foo
     */
    final function filename() {
        return pathinfo($this->path, PATHINFO_FILENAME);
    }

    /**
     * @param int $mode
     * @return $this
     */
    final function createDirs($mode = 0777) {
        $this->system->file($this->dirname())->ensureIsDir($mode, true);
        return $this;
    }

    /**
     * Combine this path with the given path, placing a directory separator
     * between them if necessary
     *
     * @param string $path
     * @return string
     */
    final function combinePath($path) {
        return $this->combinePaths($this->path, $path);
    }

    /**
     * @param string $string
     * @return bool
     */
    final function startsWith($string) {
        return $this->read(0, strlen($string)) === "$string";
    }

    /**
     * @param string $path1
     * @param string $path2
     * @return string
     */
    final function combinePaths($path1, $path2) {
        $dirSep = $this->system->dirSep();
        $sepLen = strlen($dirSep);

        if (substr($path2, 0, $sepLen) === $dirSep || substr($path1, -$sepLen) === $dirSep)
            return $path1 . $path2;
        else
            return $path1 . $dirSep . $path2;
    }

    /**
     * @param string $path
     * @return File
     */
    final function combine($path) {
        return $this->system->file($this->combinePath($path));
    }

    final function isBlockDevice() {
        return $this->fileType() === 'block';
    }

    /**
     * @param string $to
     * @return File the new file
     */
    final function copy($to) {
        $this->copyImpl($to);
        return $this->system->file($to);
    }

    /**
     * @param string $dir
     */
    final function copyDirContents($dir) {
        foreach ($this->scanDirNoDots() as $file)
            $this->combine($file)->copyRecursive($this->combinePaths($dir, $file));
    }

    /**
     * @param string $to
     * @return \IVT\System\File
     */
    final function copyRecursive($to) {
        if ($this->isDir() && !$this->isLink()) {
            $to = $this->system->file($to);
            $to->mkdir();
            $this->copyDirContents($to->path);
            return $to;
        } else {
            return $this->copy($to);
        }
    }

    final function scanDirNoDots() {
        return array_values(array_diff($this->scanDir(), array('.', '..')));
    }

    final function removeContents() {
        foreach ($this->dirContents() as $file)
            $file->removeRecursive();
    }

    final function dirContents() {
        /** @var self[] $files */
        $files = array();
        foreach ($this->scanDirNoDots() as $p)
            $files[] = $this->combine($p);
        return $files;
    }

    /**
     * @return bool Whether the file was removed
     */
    final function ensureNotExists() {
        $remove = $this->exists();
        if ($remove)
            $this->removeRecursive();
        return $remove;
    }

    /**
     * Recursive version of remove()
     */
    final function removeRecursive() {
        if ($this->isDir() && !$this->isLink()) {
            foreach ($this->dirContents() as $file)
                $file->removeRecursive();

            $this->rmdir();
        } else {
            $this->unlink();
        }
    }

    /**
     * Calls unlink() for files and rmdir() for directories, like remove() in C.
     */
    final function remove() {
        if ($this->isDir() && !$this->isLink())
            $this->rmdir();
        else
            $this->unlink();
    }

    /**
     * @return null|string
     */
    final function readIfFile() {
        return $this->isFile() ? $this->read() : null;
    }

    /**
     * @return null|string
     */
    final function readLinkIfLink() {
        return $this->isLink() ? $this->readlink() : null;
    }

    /**
     * @param string $contents
     * @return boolean
     */
    final function writeIfChanged($contents) {
        $changed = !$this->exists() || $this->read() !== "$contents";
        if ($changed)
            $this->write($contents);
        return $changed;
    }

    /**
     * @param string $to
     * @return File
     */
    final function rename($to) {
        $this->renameImpl($to);
        return $this->system->file($to);
    }

    /**
     * @param int  $mode
     * @param bool $recursive
     */
    final function ensureIsDir($mode = 0777, $recursive = false) {
        if (!$this->isDir())
            $this->mkdir($mode, $recursive);
    }

    /**
     * @return string
     */
    abstract function fileType();

    /**
     * @return int
     */
    abstract function perms();

    /**
     * @return bool
     */
    abstract function isFile();

    /**
     * @return string[]
     */
    abstract function scanDir();

    /**
     * @return bool
     */
    abstract function isDir();

    /**
     * @param int  $mode
     * @param bool $recursive
     * @return void
     */
    abstract function mkdir($mode = 0777, $recursive = false);

    /**
     * @return bool
     */
    abstract function isLink();

    /**
     * @return string
     */
    abstract function readlink();

    /**
     * @return bool
     */
    abstract function exists();

    /**
     * @return int
     */
    abstract function size();

    /**
     * @return void
     */
    abstract function unlink();

    /**
     * @return int
     */
    abstract function mtime();

    /**
     * @return int
     */
    abstract function ctime();

    /**
     * @param int      $offset
     * @param int|null $maxLength
     * @return string
     */
    abstract function read($offset = 0, $maxLength = null);

    /**
     * @param string $contents
     * @return File
     */
    abstract function write($contents);

    /**
     * @param string $contents
     * @return void
     */
    abstract function create($contents);

    /**
     * @param string $contents
     * @return void
     */
    abstract function append($contents);

    /**
     * @return void
     */
    abstract function rmdir();

    /**
     * @param int $mode
     * @return void
     */
    abstract function chmod($mode);

    /**
     * @return string
     */
    abstract function realpath();

    /**
     * Stream the file into the given \Closure.
     * @param \Closure $callback
     * @param int      $chunkSize
     */
    abstract function streamInto(\Closure $callback, $chunkSize = self::DEFAULT_CHUNK_SIZE);

    /**
     * @param string $dest
     * @return void
     */
    abstract protected function copyImpl($dest);

    /**
     * @param string $to
     * @return void
     */
    abstract protected function renameImpl($to);
}

