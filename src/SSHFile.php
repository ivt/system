<?php

namespace IVT\System;

use IVT\Assert;

class SSHFile extends FOpenWrapperFile
{
    private $sftp, $system;

    /**
     * @param SSHSystem $system
     * @param resource $sftp
     * @param string $path
     */
    function __construct(SSHSystem $system, $sftp, $path)
    {
        $this->sftp = $sftp;
        $this->system = $system;

        parent::__construct($system, $path);
    }

    function mkdir($mode = 0777, $recursive = false)
    {
        Assert::true(ssh2_sftp_mkdir($this->sftp, $this->absolutePath(), $mode, $recursive));
    }

    function readlink()
    {
        return Assert::string(ssh2_sftp_readlink($this->sftp, $this->absolutePath()));
    }

    function unlink()
    {
        Assert::true(ssh2_sftp_unlink($this->sftp, $this->absolutePath()));
    }

    function ctime()
    {
        // ctime is not supported over SFTP2, so we run a command to get it instead.
        $stdout = $this->system->execArgs(array('stat', '-c', '%Z', $this->path()));

        return (int)substr($stdout, 0, -1);
    }

    function append($contents)
    {
        $this->_write($contents, true, false);
    }

    function create($contents)
    {
        $this->_write($contents, false, true);
    }

    function write($contents)
    {
        $this->_write($contents, false, false);
        return $this;
    }

    private function _write($data, $append, $bailIfExists)
    {
        // In the case of append, 'a' doesn't work, so we need to open the file and seek to the end instead.
        // If the file exists, 'w' will truncate it, and 'x' will throw an error. 'c' is not supported by the library.
        // That just leaves 'r+', which will throw an error if the file doesn't exist. So the best thing we can do is
        // use 'r+' if the file exists and 'w' if it doesn't.
        $append = $append && $this->exists();

        if ($bailIfExists)
            $mode = 'xb';
        else if ($append)
            $mode = 'r+b';
        else
            $mode = 'wb';

        Assert::resource($handle = fopen($this->url(), $mode));

        if ($append)
            Assert::equal(fseek($handle, 0, SEEK_END), 0);

        Assert::equal(fwrite($handle, $data), strlen($data));
        Assert::true(fclose($handle));
    }

    private function absolutePath()
    {
        return $this->absolutePath1($this->path());
    }

    private function absolutePath1($path)
    {
        return starts_with($path, '/') ? $path : $this->system->pwd() . '/' . $path;
    }

    protected function pathToUrl($path)
    {
        return "ssh2.sftp://$this->sftp/.{$this->absolutePath1( $path )}";
    }

    function chmod($mode)
    {
        /** @noinspection PhpUndefinedFunctionInspection */
        Assert::true(ssh2_sftp_chmod($this->sftp, $this->absolutePath(), $mode));
    }

    protected function renameImpl($to)
    {
        Assert::true(ssh2_sftp_rename($this->sftp, $this->absolutePath(), $to));
    }

    function realpath()
    {
        return Assert::string(ssh2_sftp_realpath($this->sftp, $this->absolutePath()));
    }
}
