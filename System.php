<?php

namespace IVT\System;

use Symfony\Component\Process\Process;

class CommandOutput
{
	static function fromSymfonyProcess( Process $process )
	{
		if ( $process->isRunning() )
			$process->wait();

		$self = new self( $process->getCommandLine(), $process->getStdin(), new WriteStream );

		$log = $self->log();
		$log->out( $process->getOutput() );
		$log->err( $process->getErrorOutput() );

		return $self->finish( $process->getExitCode() );
	}

	private $stdOut, $stdErr, $stdBoth, $log;
	private $cmd, $in, $out, $err, $exit;

	function __construct( $command, $stdIn, WriteStream $log )
	{
		$this->log     = new AccumulateStream( array( $log ) );
		$this->stdBoth = new AccumulateStream;
		$this->stdOut  = new AccumulateStream( array( $this->stdBoth ) );
		$this->stdErr  = new AccumulateStream( array( $this->stdBoth ) );

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

	function log()
	{
		return new Log( new WriteStream( array( $this->stdOut, $this->out ) ),
		                new WriteStream( array( $this->stdErr, $this->err ) ) );
	}

	function finish( $exitStatus )
	{
		$exitMessage = array_get( Process::$exitCodes, $exitStatus, "Unknown error" );

		$this->out->flush();
		$this->err->flush();
		$this->exit->write( "$exitStatus $exitMessage\n" );
		$this->exit->flush();

		return new CommandResult( $this->stdOut->data(), $this->stdErr->data(),
		                          $this->stdBoth->data(), $exitStatus, $this->log->data() );
	}
}

abstract class System
{
	private $log;

	function __construct( Log $log )
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
	 *
	 * @return self
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
		$output     = new CommandOutput( $command, $stdIn, $this->log->outStream() );
		$exitStatus = $this->runImpl( $command, $stdIn, $output->log() );
		$result     = $output->finish( $exitStatus );
		$this->log->outStream()->write( "\n" );

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
	 * @param string $command
	 * @param string $stdIn
	 * @param Log    $log
	 *
	 * @return int exit code
	 */
	abstract protected function runImpl( $command, $stdIn, Log $log );

	function log() { return $this->log; }
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
	 *
	 * @return self
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

	/**
	 * @return self
	 */
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
	 *
	 * @return self
	 */
	abstract function setContents( $contents );

	/**
	 * @param string $contents
	 *
	 * @return self
	 */
	abstract function createWithContents( $contents );

	/**
	 * @param string $contents
	 *
	 * @return self
	 */
	abstract function appendContents( $contents );

	/**
	 * @return self
	 */
	abstract function removeDir();
}

