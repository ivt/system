<?php

namespace IVT\System;

use IVT\Assert;
use IVT\Log;
use Symfony\Component\Process\Process as SymfonyProcess;

class LocalProcess extends Process
{
	private $process;

	/**
	 * @param string   $command
	 * @param string   $stdIn
	 * @param callable $stdOut
	 * @param callable $stdErr
	 */
	function __construct( $command, $stdIn, \Closure $stdOut, \Closure $stdErr )
	{
		$this->process = new SymfonyProcess( $command, null, null, $stdIn, null );
		$this->process->start( function ( $type, $data ) use ( $stdOut, $stdErr )
		{
			if ( $type === SymfonyProcess::OUT )
				$stdOut( $data );

			if ( $type === SymfonyProcess::ERR )
				$stdErr( $data );
		} );
	}

	function isDone()
	{
		return $this->process->isTerminated();
	}

	function wait()
	{
		return $this->process->wait();
	}
}

class LocalSystem extends System
{
	static function create()
	{
		return new self;
	}

	function escapeCmd( $arg )
	{
		if ( $this->isWindows() )
			return '"' . addcslashes( $arg, '\\"' ) . '"';
		else
			return parent::escapeCmd( $arg );
	}

	final function isWindows()
	{
		return strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN';
	}

	function isPortOpen( $host, $port, $timeout )
	{
		$fp = @fsockopen( $host, $port, $errno, $errstr, $timeout );
		if ( $fp === false )
			return false;
		fclose( $fp );

		return true;
	}

	/**
	 * @return System
	 */
	static function createLogging()
	{
		$self = new self;
		$self = $self->wrapLogging( Log::create() );
		return $self;
	}

	function file( $path )
	{
		return new LocalFile( $this, $path );
	}

	function dirSep() { return DIRECTORY_SEPARATOR; }

	function runImpl( $command, $stdIn, \Closure $stdOut, \Closure $stdErr )
	{
		return new LocalProcess( $command, $stdIn, $stdOut, $stdErr );
	}

	function time() { return time(); }

	/**
	 * @return int
	 */
	function getmypid()
	{
		return getmypid();
	}

	function connectDB( \DatabaseConnectionInfo $dsn )
	{
		return new \Dbase_SQL_Driver( $dsn );
	}

	function cd( $dir )
	{
		Assert::true( chdir( $dir ) );
	}

	function pwd()
	{
		return Assert::string( getcwd() );
	}

	function describe()
	{
		return 'local';
	}
}

class LocalFile extends FOpenWrapperFile
{
	private $system;

	function __construct( LocalSystem $system, $path )
	{
		parent::__construct( $system, $path );
		$this->system = $system;
	}

	function readlink()
	{
		clearstatcache( true );

		return Assert::string( readlink( $this->path() ) );
	}

	protected function pathToUrl( $path )
	{
		if ( DIRECTORY_SEPARATOR === '\\' )
			return \PCRE::match( '^([A-Za-z]:\\\\|\\\\\\\\|\\\\)', $path, 'D' ) ? $path : ".\\$path";
		else
			return starts_with( $path, '/' ) ? $path : "./$path";
	}

	function chmod( $mode )
	{
		Assert::true( chmod( $this->path(), $mode ) );
	}

	function realpath()
	{
		clearstatcache( true );

		return Assert::string( realpath( $this->path() ) );
	}

	function changeGroup( $group )
	{
		Assert::true( chgrp( $this->path(), $group ) );
	}

	function changeOwner( $owner )
	{
		Assert::true( chown( $this->path(), $owner ) );
	}
}
