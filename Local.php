<?php

namespace IVT\System;

use Symfony\Component\Process\Process;

class LocalSystem extends System
{
	static function isPortOpen( $host, $port, $timeout )
	{
		$connection = @fsockopen( $host, $port, ref_new(), ref_new(), $timeout );

		if ( $connection === false )
			return false;

		fclose( $connection );

		return true;
	}

	/**
	 * @param WriteStream[] $delegates
	 *
	 * @return self
	 */
	static function create( array $delegates = array() )
	{
		return new self( new WriteStream( $delegates ) );
	}

	function file( $path )
	{
		return new LocalFile( $this, $path );
	}

	function runImpl( $command, $stdIn, Log $log )
	{
		return self::runLocal( $command, $stdIn, $log, null, null );
	}

	/**
	 * @param string        $command
	 * @param string        $stdIn
	 * @param Log           $log
	 * @param string|null   $cwd
	 * @param string[]|null $environment
	 *
	 * @return int
	 */
	static function runLocal( $command, $stdIn, Log $log, $cwd, $environment )
	{
		$process = new Process( $command, $cwd, $environment, $stdIn, null );

		return $process->run( function ( $type, $data ) use ( $log )
		{
			if ( $type === Process::OUT )
				$log->out( $data );

			if ( $type === Process::ERR )
				$log->err( $data );
		} );
	}

	function currentTimestamp() { return time(); }

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

	function setWorkingDirectory( $dir )
	{
		$this->writeLog( "chdir: $dir\n" );

		assertNotFalse( chdir( $dir ) );
	}

	/** @return string */
	function getWorkingDirectory() { return getcwd(); }

	function writeOut( $data )
	{
		assertNotFalse( fwrite( STDOUT, $data ) );
	}

	function writeErr( $data )
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

	function scanDir()
	{
		assertNotFalse( $result = scandir( $this->fsPath() ) );

		return $result;
	}

	function isDir()
	{
		clearstatcache( true );

		return is_dir( $this->fsPath() );
	}

	function createDir( $mode = 0777, $recursive = false )
	{
		clearstatcache( true );

		assertNotFalse( mkdir( $this->fsPath(), $mode, $recursive ) );
	}

	function isLink()
	{
		clearstatcache( true );

		return is_link( $this->fsPath() );
	}

	function readLink()
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

	function fileSize()
	{
		clearstatcache( true );

		assertNotFalse( $size = filesize( $this->fsPath() ) );

		return $size;
	}

	function removeFile()
	{
		clearstatcache( true );

		assertNotFalse( unlink( $this->fsPath() ) );
	}

	function lastModified()
	{
		clearstatcache( true );

		assertNotFalse( $result = filemtime( $this->fsPath() ) );

		return $result;
	}

	function lastStatusCange()
	{
		clearstatcache( true );

		assertNotFalse( $result = filectime( $this->fsPath() ) );

		return $result;
	}

	function getContents( $offset = 0, $maxLength = PHP_INT_MAX )
	{
		if ( $maxLength == PHP_INT_MAX )
		{
			assertNotFalse( $result = file_get_contents( $this->fsPath(), false, null, $offset ) );
		}
		else
		{
			assertNotFalse( $result = file_get_contents( $this->fsPath(), false, null, $offset, $maxLength ) );
		}

		return $result;
	}

	function removeDir()
	{
		assertNotFalse( rmdir( $this->fsPath() ) );
	}

	function createWithContents( $contents ) { $this->writeImpl( $contents, 'xb' ); }

	function appendContents( $contents ) { $this->writeImpl( $contents, 'ab' ); }

	function setContents( $contents ) { $this->writeImpl( $contents, 'wb' ); }

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
}
