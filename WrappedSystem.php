<?php

namespace IVT\System;

class WrappedSystem extends System
{
	private $system;

	function __construct( System $system )
	{
		$this->system = $system;
	}

	function setWorkingDirectory( $dir )
	{
		$this->system->setWorkingDirectory( $dir );
	}

	function getWorkingDirectory()
	{
		return $this->system->getWorkingDirectory();
	}

	function file( $path )
	{
		return new WrappedFile( $this, $path, $this->system->file( $path ) );
	}

	function connectDB( \DatabaseConnectionInfo $dsn )
	{
		return $this->system->connectDB( $dsn );
	}

	function currentTimestamp()
	{
		return $this->system->currentTimestamp();
	}

	protected function runImpl( $command, $input, CommandOutputHandler $output )
	{
		return $this->system->runImpl( $command, $input, $output );
	}

	function writeOutput( $data )
	{
		$this->system->writeOutput( $data );
	}

	function writeError( $data )
	{
		$this->system->writeError( $data );
	}

	function wrap( System $sytem )
	{
		return $this->system->wrap( parent::wrap( $sytem ) );
	}

	function directorySeperator()
	{
		return $this->system->directorySeperator();
	}
}

class WrappedFile extends File
{
	private $file;

	function __construct( WrappedSystem $system, $path, File $file )
	{
		parent::__construct( $system, $path );
		$this->file = $file;
	}

	function isFile() { return $this->file->isFile(); }

	function scanDir() { return $this->file->scanDir(); }

	function isDir() { return $this->file->isDir(); }

	function createDir( $mode = 0777, $recursive = false )
	{
		$this->file->createDir( $mode, $recursive );
	}

	function isLink() { return $this->file->isLink(); }

	function readLink() { return $this->file->readLink(); }

	function exists() { return $this->file->exists(); }

	function fileSize() { return $this->file->fileSize(); }

	function removeFile() { $this->file->removeFile(); }

	function lastModified() { return $this->file->lastModified(); }

	function lastStatusCange() { return $this->file->lastStatusCange(); }

	function getContents( $offset = 0, $maxLength = PHP_INT_MAX )
	{
		return $this->file->getContents( $offset, $maxLength );
	}

	function setContents( $contents ) { $this->file->setContents( $contents ); }

	function createWithContents( $contents ) { $this->file->createWithContents( $contents ); }

	function appendContents( $contents ) { $this->file->appendContents( $contents ); }

	function removeDir() { $this->file->removeDir(); }
}
