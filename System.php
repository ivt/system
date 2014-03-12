<?php

namespace IVT\System;

use Symfony\Component\Process\Process;

class CommandOutput implements CommandOutputHandler
{
	static function fromSymfonyProcess( Process $process )
	{
		if ( $process->isRunning() )
			$process->wait();

		$self = new self( $process->getCommandLine(), $process->getStdin(), new WriteStream );

		$self->writeOutput( $process->getOutput() );
		$self->writeError( $process->getErrorOutput() );

		return $self->finish( $process->getExitCode() );
	}

	private $stdOut, $stdErr, $log;
	private $cmd, $in, $out, $err, $exit;

	function __construct( $command, $stdIn, WriteStream $log )
	{
		$this->log     = new AccumulateStream( array( $log ) );
		$this->stdOut  = new AccumulateStream;
		$this->stdErr  = new AccumulateStream;

		$this->cmd  = new LinePrefixStream( '>>> ', array( $this->log ) );
		$this->in   = new LinePrefixStream( ' IN ', array( $this->log ) );
		$this->out  = new LinePrefixStream( '<<< ', array( $this->log ) );
		$this->err  = new LinePrefixStream( '!!! ', array( $this->log ) );
		$this->exit = new LinePrefixStream( '=== ', array( $this->log ) );

		$this->cmd->write( "$command\n" );
		$this->cmd->flush();
		$this->in->write( $stdIn );
		$this->in->flush();
	}

	function finish( $exitStatus )
	{
		$exitMessage = array_get( Process::$exitCodes, $exitStatus, "Unknown error" );

		$this->out->flush();
		$this->err->flush();
		$this->exit->write( "$exitStatus $exitMessage\n" );
		$this->exit->flush();

		return new CommandResult( $this->stdOut->data(), $this->stdErr->data(), $exitStatus, $this->log->data() );
	}

	function writeOutput( $data )
	{
		$this->stdOut->write( $data );
		$this->out->write( $data );
	}

	function writeError( $data )
	{
		$this->stdErr->write( $data );
		$this->err->write( $data );
	}
}

abstract class System implements CommandOutputHandler
{
	private $log;

	function __construct( WriteStream $log )
	{
		$this->log = $log;
	}

	static function escapeCmd( $arg )
	{
		return ProcessBuilder::escape( $arg );
	}

	static function escapeCmdArgs( array $args )
	{
		return ProcessBuilder::escapeArgs( $args );
	}

	final function shellExec( $command, $stdIn = '' )
	{
		return $this->runCommand( $command, $stdIn )->assertSuccess()->stdout();
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
	 * @param string $dir
	 */
	abstract function setWorkingDirectory( $dir );

	/**
	 * @return string
	 */
	abstract function getWorkingDirectory();

	/**
	 * @param $path
	 *
	 * @return File
	 */
	abstract function file( $path );

	/**
	 * @param string $command
	 * @param string $stdIn
	 *
	 * @return CommandResult
	 */
	final function runCommand( $command, $stdIn = '' )
	{
		$output     = new CommandOutput( $command, $stdIn, $this->log );
		$exitStatus = $this->runImpl( $command, $stdIn, $output );
		$result     = $output->finish( $exitStatus );
		$this->log->write( "\n" );

		return $result;
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
	 * @param                      $input
	 * @param CommandOutputHandler $output
	 *
	 * @return int exit code
	 */
	abstract protected function runImpl( $command, $input, CommandOutputHandler $output );

	protected function log() { return $this->log; }

	protected function writeLog( $data )
	{
		$this->log->write( $data );
	}
}

abstract class File
{
	private $path, $system;

	function __construct( System $system, $path )
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
			$result[ ] = $this->appendPath( ends_with( $this->path, '/' ) ? $file : "/$file" );

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

