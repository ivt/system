<?php

namespace IVT\System;

use IVT\Assert;
use IVT\Log;
use Symfony\Component\Process\Process as SymfonyProcess;

class LocalProcess extends Process
{
	/**
	 * @var SymfonyProcess[]
	 */
	public static $processes = array();

	/**
	 * When PHP shuts down due to a fatal error, it doesn't call the object destructors. Consequently, the processes
	 * run through this class are left hanging as children, and PHP waits for them to finish. If they are processes
	 * which run indefinitely until killed, PHP itself ends up stuck forever.
	 *
	 * Shutdown handlers *are* run in the case of a fatal error, even if destructors aren't. Therefore, bind
	 * a shutdown handler which will kill any processes left running. 
	 */
	private static function bindShutdownHandler()
	{
		static $bound = false;
		if ( $bound )
			return;
		$bound = true;
		register_shutdown_function( function ()
		{
			foreach ( LocalProcess::$processes as $k => $v )
			{
				$v->stop();
				unset( LocalProcess::$processes[ $k ] );
			}
		} );
	}

	private $process;

	/**
	 * @param string   $command
	 * @param string   $stdIn
	 * @param \Closure $stdOut
	 * @param \Closure $stdErr
	 */
	function __construct( $command, $stdIn, \Closure $stdOut, \Closure $stdErr )
	{
		self::bindShutdownHandler();

		$this->process = new SymfonyProcess( $command, null, null, $stdIn, null );
		$this->process->start( function ( $type, $data ) use ( $stdOut, $stdErr )
		{
			if ( $type === SymfonyProcess::OUT )
				$stdOut( $data );

			if ( $type === SymfonyProcess::ERR )
				$stdErr( $data );
		} );

		self::$processes[ spl_object_hash( $this->process ) ] = $this->process;
	}

	function __destruct()
	{
		unset( self::$processes[ spl_object_hash( $this->process ) ] );
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
		set_error_handler( function () { } );
		$fp = @fsockopen( $host, $port, $errno, $errstr, $timeout );
		restore_error_handler();
		if ( $fp === false )
			return false;
		fclose( $fp );

		return true;
	}

	static function createLogging()
	{
		$self = new self;
		return $self->wrapLogging( Log::create() );
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
