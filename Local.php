<?php

namespace IVT\System;

use IVT\Assert;
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

	private $outputHandler;

	function __construct()
	{
		$this->outputHandler = PHP_SAPI === 'cli' ? new CLIOutputHandler : new WebOutputHandler;
	}

	function file( $path )
	{
		return new LocalFile( $this, $path );
	}

	function dirSep() { return DIRECTORY_SEPARATOR; }

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
		Assert::true( chdir( $dir ) );
	}

	function pwd()
	{
		return Assert::string( getcwd() );
	}

	function writeOutput( $data )
	{
		$this->outputHandler->writeOutput( $data );
	}

	function writeError( $data )
	{
		$this->outputHandler->writeError( $data );
	}

	function describe()
	{
		return 'localhost';
	}
}

class CLIOutputHandler implements CommandOutputHandler
{
	function writeOutput( $data )
	{
		Assert::equal( fwrite( STDOUT, $data ), strlen( $data ) );
	}

	function writeError( $data )
	{
		Assert::equal( fwrite( STDERR, $data ), strlen( $data ) );
	}
}

class WebOutputHandler implements CommandOutputHandler
{
	private $initDone = false;

	function writeOutput( $data )
	{
		$this->send( $data, false );
	}

	function writeError( $data )
	{
		$this->send( $data, true );
	}

	private function send( $data = '', $isError = false )
	{
		if ( !$this->initDone )
		{
			if ( !headers_sent() )
			{
				header( 'Content-Type: text/html; charset=utf8' );
				echo "<!DOCTYPE html><html><body>";
			}

			while ( ob_get_level() > 0 && ob_end_flush() )
			{
			}

			$this->initDone = true;
		}

		$color = $isError ? "darkred" : "darkblue";
		echo "<pre style=\"display: inline; margin: 0; padding: 0; color: $color;\">";
		echo html( $data );
		echo "</pre>";

		flush();
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
			return \PCRE::create( '^([A-Za-z]:\\\\|\\\\\\\\|\\\\)', 'D' )->matches( $path ) ? $path : ".\\$path";
		else
			return starts_with( $path, '/' ) ? $path : "./$path";
	}

	function chmod( $mode )
	{
		Assert::true( chmod( $this->path(), $mode ) );
	}

	function realpath()
	{
		return Assert::string( realpath( $this->path() ) );
	}
}
