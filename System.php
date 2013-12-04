<?php

namespace IVT\System;

use Symfony\Component\Process\Process;

class CommandOutput
{
	private $command, $stdOut, $stdErr, $out, $err, $exit, $cmd, $in;

	function __construct( $command, $stdIn, $log )
	{
		$this->stdOut = new AccumulateStream;
		$this->stdErr = new AccumulateStream;

		$this->cmd  = new LinePrefixStream( '>>> ', array( $log ) );
		$this->in   = new LinePrefixStream( ' IN ', array( $log ) );
		$this->out  = new LinePrefixStream( 'OUT ', array( $log ) );
		$this->err  = new LinePrefixStream( 'ERR ', array( $log ) );
		$this->exit = new LinePrefixStream( '    ', array( $log ) );

		$this->cmd->write( "$command\n" );
		$this->cmd->flush();
		$this->in->write( $stdIn );
		$this->in->flush();

		$this->command = $command;
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

		return new CommandResult( $this->command, $this->stdOut->data(), $this->stdErr->data(), $exitStatus );
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
		return Local\ProcessBuilder::escape( $arg );
	}
	
	static function escapeCmdArgs( array $args )
	{
		return Local\ProcessBuilder::escapeArgs( $args );
	}

	final function shell_exec( $command, $stdIn = '' )
	{
		return $this->run( $command, $stdIn )->assertSuccess()->stdout();
	}

	final function now()
	{
		return $this->dateTime( $this->time() );
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
	 * @return void
	 */
	abstract function chdir( $dir );

	/**
	 * @return string
	 */
	abstract function getcwd();

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
	final function run( $command, $stdIn = '' )
	{
		$output = new CommandOutput( $command, $stdIn, $this->log->outStream() );

		return $output->finish( $this->runImpl( $command, $stdIn, $output->log() ) );
	}

	/**
	 * @param string $host
	 * @param string $username
	 * @param string $password
	 * @param string $database
	 *
	 * @return \Dbase_SQL_Driver
	 */
	function connectDB( $host, $username, $password, $database )
	{
		return $this->connectDBImpl( new \DatabaseConnectionInfo( 'mysql', $host, $username, $password, $database ) );
	}

	protected abstract function connectDBImpl( \DatabaseConnectionInfo $dsn );

	/**
	 * Unix timestamp
	 *
	 * @return int
	 */
	abstract function time();

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

	final function append( $append )
	{
		return $this->system->file( $this->path . $append );
	}

	final function chdir()
	{
		$this->system->chdir( $this->path );
	}

	/**
	 * @return bool
	 */
	abstract function is_file();

	/**
	 * @return string[]
	 */
	abstract function scandir();

	/**
	 * @return bool
	 */
	abstract function is_dir();

	/**
	 * @param int  $mode
	 * @param bool $recursive
	 *
	 * @return void
	 */
	abstract function mkdir( $mode = 0777, $recursive = false );

	/**
	 * @return bool
	 */
	abstract function is_link();

	/**
	 * @return string
	 */
	abstract function readlink();

	/**
	 * @return bool
	 */
	abstract function file_exists();

	/**
	 * @return int
	 */
	abstract function filesize();

	/**
	 * @return void
	 */
	abstract function unlink();

	/**
	 * @return int
	 */
	abstract function filemtime();

	/**
	 * @return int
	 */
	abstract function filectime();

	/**
	 * @return string
	 */
	abstract function file_get_contents();

	/**
	 * @param string $data
	 * @param bool   $append
	 * @param bool   $bailIfExists
	 *
	 * @return void
	 */
	abstract function file_put_contents( $data, $append = false, $bailIfExists = false );
}

class Exception extends \IVT\Exception
{
}
