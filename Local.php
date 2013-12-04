<?php

namespace IVT\System\Local;

use IVT\System\StreamStream;
use IVT\System\Exception;
use IVT\System\Log;
use Symfony\Component\Process\Process;

class System extends \IVT\System\System
{
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

	function time() { return time(); }

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

	function chdir( $dir )
	{
		$this->log()->out( "chdir: $dir\n" );

		if ( !chdir( $dir ) )
			throw new Exception( "chdir failed: $dir" );
	}

	/** @return string */
	function getcwd() { return getcwd(); }
}

class File extends \IVT\System\File
{
	function is_file()
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
		$this->check( $result = scandir( $this->fsPath() ) );

		return $result;
	}

	function is_dir()
	{
		clearstatcache( true );

		return is_dir( $this->fsPath() );
	}

	function mkdir( $mode = 0777, $recursive = false )
	{
		clearstatcache( true );

		$this->check( mkdir( $this->fsPath(), $mode, $recursive ) );
	}

	function is_link()
	{
		clearstatcache( true );

		return is_link( $this->fsPath() );
	}

	function readlink()
	{
		clearstatcache( true );

		$this->check( $result = readlink( $this->fsPath() ) );

		return $result;
	}

	function file_exists()
	{
		clearstatcache( true );

		return file_exists( $this->fsPath() );
	}

	function filesize()
	{
		clearstatcache( true );

		$this->check( $size = filesize( $this->fsPath() ) );

		return $size;
	}

	function unlink()
	{
		clearstatcache( true );

		$this->check( unlink( $this->fsPath() ) );
	}

	function filemtime()
	{
		clearstatcache( true );

		$this->check( $result = filemtime( $this->fsPath() ) );

		return $result;
	}

	function filectime()
	{
		clearstatcache( true );

		$this->check( $result = filectime( $this->fsPath() ) );

		return $result;
	}

	function file_get_contents()
	{
		$this->check( $result = file_get_contents( $this->fsPath(), false ) );

		return $result;
	}

	function file_put_contents( $data, $append = false, $bailIfExists = false )
	{
		if ( $bailIfExists )
			$mode = 'xb';
		else if ( $append )
			$mode = 'ab';
		else
			$mode = 'wb';

		$this->check( $handle = fopen( $this->fsPath(), $mode ) );
		$this->check( $written = fwrite( $handle, $data ) );
		assertEqual( $written, strlen( $data ) );
		$this->check( fclose( $handle ) );
	}

	private function check( $result )
	{
		if ( $result === false )
		{
			throw new Exception( "Failed to do something with $this ( see the stack trace)" );
		}
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
