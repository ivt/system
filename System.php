<?php

namespace IVT\System;

use Symfony\Component\Process\Process;

class CommandOutput extends DelegateOutputHandler
{
	private $cmd, $in, $out, $err, $exit;

	function __construct( CommandOutputHandler $output, \Closure $log )
	{
		parent::__construct( $output );

		$this->cmd  = new LinePrefixStream( '>>> ', $log );
		$this->in   = new LinePrefixStream( ' IN ', $log );
		$this->out  = new LinePrefixStream( '<<< ', $log );
		$this->err  = new LinePrefixStream( '!!! ', $log );
		$this->exit = new LinePrefixStream( '=== ', $log );
	}

	function writeCommand( $command )
	{
		$this->cmd->write( $command );
	}

	function writeInput( $stdIn )
	{
		$this->in->write( $stdIn );
	}

	function writeExitStatus( $exitStatus )
	{
		$exitMessage = array_get( Process::$exitCodes, $exitStatus, "Unknown error" );

		$this->exit->write( "$exitStatus $exitMessage\n" );
	}

	function writeOutput( $data )
	{
		parent::writeOutput( $data );
		$this->out->write( $data );
	}

	function writeError( $data )
	{
		parent::writeError( $data );
		$this->err->write( $data );
	}

	function flush()
	{
		$this->cmd->flush();
		$this->in->flush();
		$this->out->flush();
		$this->err->flush();
		$this->exit->flush();
	}
}

interface FileSystem
{
	/**
	 * @return string
	 */
	function getWorkingDirectory();

	/**
	 * @param string $dir
	 */
	function setWorkingDirectory( $dir );

	/**
	 * @param string $path
	 *
	 * @return File
	 */
	function file( $path );

	/**
	 * @return string
	 */
	function directorySeperator();
}

abstract class System implements CommandOutputHandler, FileSystem
{
	static function escapeCmd( $arg )
	{
		return ProcessBuilder::escape( $arg );
	}

	static function escapeCmdArgs( array $args )
	{
		return ProcessBuilder::escapeArgs( $args );
	}

	final function shellExecArgs( array $command, $stdIn = '' )
	{
		return $this->runCommandArgs( $command, $stdIn )->assertSuccess()->stdOut();
	}

	final function shellExec( $command, $stdIn = '' )
	{
		return $this->runCommand( $command, $stdIn )->assertSuccess()->stdOut();
	}

	final function now()
	{
		return $this->dateTime( $this->currentTimestamp() );
	}

	/**
	 * @param int $timestamp Unix timestamp (UTC)
	 *
	 * @return \DateTime
	 */
	final function dateTime( $timestamp )
	{
		// The timezone passed in the constructor of \DateTime is ignored in the case of a timestamp, because a
		// unix timestamp is considered to have a built-in timezone of UTC.
		$timezone = new \DateTimeZone( date_default_timezone_get() );
		$dateTime = new \DateTime( "@$timestamp", $timezone );
		$dateTime->setTimezone( $timezone );

		return $dateTime;
	}

	/**
	 * @param string $command
	 * @param string $stdIn
	 *
	 * @return CommandResult
	 */
	final function runCommand( $command, $stdIn = '' )
	{
		$output   = new AccumulateOutputHandler;
		$exitCode = $this->runImpl( $command, $stdIn, $output );

		return new CommandResult( $command, $stdIn, $output->stdOut(), $output->stdErr(), $exitCode );
	}

	/**
	 * @param string[] $command
	 * @param string   $stdIn
	 *
	 * @return CommandResult
	 */
	final function runCommandArgs( array $command, $stdIn = '' )
	{
		return $this->runCommand( self::escapeCmdArgs( $command ), $stdIn );
	}

	final function printLineError( $string = '' ) { $this->writeError( "$string\n" ); }

	final function printLine( $string = '' ) { $this->writeOutput( "$string\n" ); }

	function isPortOpen( $host, $port, $timeout )
	{
		$cmd = array( 'nc', '-z', '-w', $timeout, '--', $host, $port );

		return $this->runCommandArgs( $cmd )->succeeded();
	}

	/**
	 * @param \DatabaseConnectionInfo $dsn
	 *
	 * @return \Dbase_SQL_Driver_Abstract
	 */
	abstract function connectDB( \DatabaseConnectionInfo $dsn );

	/**
	 * Unix timestamp
	 *
	 * @return int
	 */
	abstract function currentTimestamp();

	/**
	 * @param string               $command
	 * @param string               $input
	 * @param CommandOutputHandler $output
	 *
	 * @return int exit code
	 */
	abstract protected function runImpl( $command, $input, CommandOutputHandler $output );

	/**
	 * If this System happens to be a wrapper around another System, this
	 * applies the same wrapping to the given system.
	 */
	function wrap( System $sytem ) { return $sytem; }
}

abstract class File
{
	private $path, $system;

	function __construct( FileSystem $system, $path )
	{
		$this->path   = $path;
		$this->system = $system;
	}

	final function path() { return $this->path; }

	final function __toString() { return $this->path(); }

	final function appendPath( $append )
	{
		return $this->system->file( $this->path . $append );
	}

	final function removeRecursive()
	{
		if ( $this->isDir() && !$this->isLink() )
		{
			foreach ( $this->subFiles() as $file )
				$file->removeRecursive();

			$this->removeDir();
		}
		else
		{
			$this->removeFile();
		}
	}

	/**
	 * @return self[]
	 */
	final function subFiles()
	{
		$result = array();

		foreach ( $this->scanDirNoDots() as $file )
			$result[ ] = $this->subFile( $file );

		return $result;
	}

	final function scanDirNoDots()
	{
		$result = array();

		foreach ( $this->scanDir() as $file )
			if ( $file !== '.' && $file !== '..' )
				$result[ ] = $file;

		return $result;
	}

	final function subFile( $file )
	{
		$sep = $this->system->directorySeperator();

		return $this->appendPath( ends_with( $this->path, $sep ) ? $file : $sep . $file );
	}

	/**
	 * @return bool
	 */
	abstract function isFile();

	/**
	 * @return string[]
	 */
	abstract function scanDir();

	/**
	 * @return bool
	 */
	abstract function isDir();

	/**
	 * @param int  $mode
	 * @param bool $recursive
	 */
	abstract function createDir( $mode = 0777, $recursive = false );

	/**
	 * @return bool
	 */
	abstract function isLink();

	/**
	 * @return string
	 */
	abstract function readLink();

	/**
	 * @return bool
	 */
	abstract function exists();

	/**
	 * @return int
	 */
	abstract function fileSize();

	abstract function removeFile();

	/**
	 * @return int
	 */
	abstract function lastModified();

	/**
	 * @return int
	 */
	abstract function lastStatusCange();

	/**
	 * @param int $offset
	 * @param int $maxLength
	 *
	 * @return string
	 */
	abstract function getContents( $offset = 0, $maxLength = PHP_INT_MAX );

	/**
	 * @param string $contents
	 */
	abstract function setContents( $contents );

	/**
	 * @param string $contents
	 */
	abstract function createWithContents( $contents );

	/**
	 * @param string $contents
	 */
	abstract function appendContents( $contents );

	abstract function removeDir();
}

