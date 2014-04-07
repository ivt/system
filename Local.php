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

	static function createLogging()
	{
		$self   = new self;
		$logger = new Logger( function ( $data ) use ( $self )
		{
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
}

class LocalFile extends File
{
	function isFile()
	{
		clearstatcache( true );

		return is_file( $this->fsPath() );
	}

	function is_executable()
	{
		clearstatcache( true );

		return is_executable( $this->fsPath() );
	}

	function scandir()
	{
		assertNotFalse( $result = scandir( $this->fsPath() ) );

		return $result;
	}

	function isDir()
	{
		clearstatcache( true );

		return is_dir( $this->fsPath() );
	}

	function mkdir( $mode = 0777, $recursive = false )
	{
		clearstatcache( true );

		assertNotFalse( mkdir( $this->fsPath(), $mode, $recursive ) );
	}

	function isLink()
	{
		clearstatcache( true );

		return is_link( $this->fsPath() );
	}

	function readlink()
	{
		clearstatcache( true );

		assertNotFalse( $result = readlink( $this->fsPath() ) );

		return $result;
	}

	function exists()
	{
		clearstatcache( true );

		return file_exists( $this->fsPath() );
	}

	function size()
	{
		clearstatcache( true );

		assertNotFalse( $size = filesize( $this->fsPath() ) );

		return $size;
	}

	function unlink()
	{
		clearstatcache( true );

		assertNotFalse( unlink( $this->fsPath() ) );
	}

	function mtime()
	{
		clearstatcache( true );

		assertNotFalse( $result = filemtime( $this->fsPath() ) );

		return $result;
	}

	function ctime()
	{
		clearstatcache( true );

		assertNotFalse( $result = filectime( $this->fsPath() ) );

		return $result;
	}

	function read( $offset = 0, $maxLength = null )
	{
		if ( $maxLength === null )
		{
			assertNotFalse( $result = file_get_contents( $this->fsPath(), false, null, $offset ) );
		}
		else
		{
			assertNotFalse( $result = file_get_contents( $this->fsPath(), false, null, $offset, $maxLength ) );
		}

		return $result;
	}

	function rmdir()
	{
		assertNotFalse( rmdir( $this->fsPath() ) );
	}

	function create( $contents ) { $this->writeImpl( $contents, 'xb' ); }

	function append( $contents ) { $this->writeImpl( $contents, 'ab' ); }

	function write( $contents ) { $this->writeImpl( $contents, 'wb' ); }

	private function writeImpl( $data, $mode )
	{
		assertNotFalse( $handle = fopen( $this->fsPath(), $mode ) );
		assertEqual( fwrite( $handle, $data ), strlen( $data ) );
		assertNotFalse( fclose( $handle ) );
	}

	private function fsPath()
	{
		$path = $this->path();

		return $this->isPathAbsolute() ? $path : '.' . DIRECTORY_SEPARATOR . $path;
	}

	private function isPathAbsolute()
	{
		return \PCRE::create( '^(/|\w:|' . \PCRE::quote( '\\' ) . ')' )->wholeString()->matches( $this->path() );
	}

	function chmod( $mode )
	{
		assertNotFalse( chmod( $this->fsPath(), $mode ) );
	}
}
