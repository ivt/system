<?php

namespace IVT\System;

use Symfony\Component\Process\Process;

class LocalSystem extends System
{
	function isPortOpen( $host, $port, $timeout )
	{
		$fp = @fsockopen( $host, $port, $errno, $errstr, $timeout );
		if ( $fp === false )
			return false;
		fclose( $fp );

		return true;
	}

	static function createLogging( $useStdErr = false )
	{
		$self   = new self;
		$logger = new Logger( function ( $data ) use ( $self, $useStdErr )
		{
			if ( $useStdErr )
				$self->writeError( $data );
			else
				$self->writeOutput( $data );
		} );

		return new LoggingSystem( $self, $logger );
	}

	function file( $path )
	{
		return new LocalFile( $this, $path );
	}

	protected function runImpl( $command, $input, CommandOutputHandler $output )
	{
		return self::runLocal( $command, $input, $output, null, null );
	}

	/**
	 * @param string               $command
	 * @param string               $input
	 * @param CommandOutputHandler $output
	 * @param string|null          $cwd
	 * @param string[]|null        $environment
	 *
	 * @return int
	 */
	static function runLocal( $command, $input, CommandOutputHandler $output, $cwd, $environment )
	{
		$process = new Process( $command, $cwd, $environment, $input, null );

		return $process->run( function ( $type, $data ) use ( $output )
		{
			if ( $type === Process::OUT )
				$output->writeOutput( $data );

			if ( $type === Process::ERR )
				$output->writeError( $data );
		} );
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
		assertNotFalse( chdir( $dir ) );
	}

	function pwd()
	{
		assertNotFalse( $result = getcwd() );

		return $result;
	}

	function writeOutput( $data )
	{
		assertNotFalse( fwrite( STDOUT, $data ) );
	}

	function writeError( $data )
	{
		assertNotFalse( fwrite( STDERR, $data ) );
	}

	function describe()
	{
		return 'localhost';
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

		assert( is_string( $result = readlink( $this->path() ) ) );

		return $result;
	}

	protected function pathToUrl( $path )
	{
		$isAbsolute = \PCRE::create( '^(/|\w:|' . \PCRE::quote( '\\' ) . ')' )->wholeString()->matches( $path );

		return $isAbsolute ? $path : '.' . DIRECTORY_SEPARATOR . $path;
	}

	function chmod( $mode )
	{
		assertEqual( chmod( $this->path(), $mode ), true );
	}

	function realpath()
	{
		assert( is_string( $result = realpath( $this->path() ) ) );

		return $result;
	}
}
