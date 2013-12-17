<?php

namespace IVT\System\Local;

use IVT\System\StreamStream;
use IVT\System\Exception;
use IVT\System\Log;
use Symfony\Component\Process\Process;

class System extends \IVT\System\System
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
	 * @param \IVT\System\WriteStream[] $delegates
	 *
	 * @return System
	 */
	static function create( array $delegates = array() )
	{
		return new self( new Log( new StreamStream( STDOUT, $delegates ),
		                          new StreamStream( STDERR, $delegates ) ) );
	}

	function file( $path )
	{
		return new File( $this, $path );
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

	function connectDBImpl( \DatabaseConnectionInfo $dsn )
	{
		return new \Dbase_SQL_Driver( $dsn );
	}

	function setWorkingDirectory( $dir )
	{
		$this->log()->out( "chdir: $dir\n" );

		if ( !chdir( $dir ) )
			throw new Exception( "chdir failed: $dir" );
		
		return $this;
	}

	/** @return string */
	function getWorkingDirectory() { return getcwd(); }
}

class File extends \IVT\System\File
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
		
		return $this;
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
		
		return $this;
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

	function createWithContents( $contents ) { return $this->writeImpl( $contents, 'xb' ); }
	
	function appendContents( $contents ) { return $this->writeImpl( $contents, 'ab' ); }
	
	function setContents( $contents ) { return $this->writeImpl( $contents, 'wb' ); }

	private function writeImpl( $data, $mode )
	{
		assertNotFalse( $handle = fopen( $this->fsPath(), $mode ) );
		assertNotFalse( $written = fwrite( $handle, $data ) );
		assertEqual( $written, strlen( $data ) );
		assertNotFalse( fclose( $handle ) );
		
		return $this;
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
