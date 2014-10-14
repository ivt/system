<?php

namespace IVT\System;

use IVT\Assert;
use Symfony\Component\Process\Process;

class CommandOutput extends DelegateOutputHandler
{
	private $cmd, $in, $out;

	function __construct( CommandOutputHandler $output, \Closure $log )
	{
		parent::__construct( $output );

		$this->cmd  = new LinePrefixStream( '>>> ', '... ', $log );
		$this->in   = new LinePrefixStream( ' IN ', ' .. ', $log );
		$this->out  = new LinePrefixStream( '  ', '  ', $log );
	}

	function writeCommand( $command )
	{
		$this->cmd->write( $command );
	}

	function writeInput( $stdIn )
	{
		$this->in->write( $stdIn );
	}

	static function exitCodeMessage( $exitStatus )
	{
		return "$exitStatus " . array_get( Process::$exitCodes, $exitStatus, "Unknown error" );
	}

	function writeOutput( $data )
	{
		parent::writeOutput( $data );
		$this->out->write( $data );
	}

	function writeError( $data )
	{
		parent::writeError( $data );
		$this->out->write( $data );
	}

	function flush()
	{
		$this->cmd->flush();
		$this->in->flush();
		$this->out->flush();
	}
}

interface FileSystem
{
	/**
	 * @return string
	 */
	function pwd();

	/**
	 * @param string $dir
	 */
	function cd( $dir );

	/**
	 * @param string $path
	 *
	 * @return File
	 */
	function file( $path );

	/**
	 * @return string
	 */
	function dirSep();
}

abstract class System implements CommandOutputHandler, FileSystem
{
	final function escapeCmd( $arg )
	{
		return ProcessBuilder::escape( $arg );
	}

	final function escapeCmdArgs( array $args )
	{
		return ProcessBuilder::escapeArgs( $args );
	}

	final function outputWriter()
	{
		$self = $this;
		return function ( $string ) use ( $self )
		{
			$self->writeOutput( $string );
		};
	}

	final function execArgs( array $command, $stdIn = '' )
	{
		return $this->runCommandArgs( $command, $stdIn )->assertSuccess()->stdOut();
	}

	final function exec( $command, $stdIn = '' )
	{
		return $this->runCommand( $command, $stdIn )->assertSuccess()->stdOut();
	}

	/**
	 * @param string $linkFile
	 * @param string $linkContents
	 */
	final function writeLink( $linkFile, $linkContents )
	{
		$this->execArgs( array( 'ln', '-sTf', $linkContents, $linkFile ) );
	}

	/**
	 * @param string $search
	 * @param string $replace
	 * @param string $file
	 */
	final function replaceInFile( $search, $replace, $file )
	{
		foreach ( str_split( '\\/^.[$()|*+?{' ) as $char )
			$search = str_replace( $char, "\\$char", $search );

		foreach ( str_split( '\\/&' ) as $char )
			$replace = str_replace( $char, "\\$char", $replace );

		$this->execArgs( array( 'sed', '-ri', "s/$search/$replace/g", $file ) );
	}

	final function now()
	{
		// The timezone passed in the constructor of \DateTime is ignored in the case of a timestamp, because a
		// unix timestamp is considered to have a built-in timezone of UTC.
		$timezone = new \DateTimeZone( date_default_timezone_get() );
		$dateTime = new \DateTime( "@{$this->time()}", $timezone );
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

		return new CommandResult( $command, $stdIn, $output, $exitCode );
	}

	/**
	 * @param string[] $command
	 * @param string   $stdIn
	 *
	 * @return CommandResult
	 */
	final function runCommandArgs( array $command, $stdIn = '' )
	{
		return $this->runCommand( $this->escapeCmdArgs( $command ), $stdIn );
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
	abstract function time();

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
	function wrap( System $system ) { return $system; }

	/**
	 * @return string
	 */
	abstract function describe();
}

abstract class File
{
	/** @var string */
	private $path;
	/** @var FileSystem */
	private $system;

	function __construct( FileSystem $system, $path )
	{
		$this->path   = $path;
		$this->system = $system;
	}

	final function path() { return $this->path; }

	final function __toString() { return $this->path(); }

	final function concat( $append )
	{
		return $this->system->file( $this->path . $append );
	}

	/**
	 * @return string /blah/foo.txt => /blah
	 */
	final function dirname() { return pathinfo( $this->path, PATHINFO_DIRNAME ); }

	/**
	 * @return string /blah/foo.txt => foo.txt
	 */
	final function basename() { return pathinfo( $this->path, PATHINFO_BASENAME ); }

	/**
	 * @return string /blah/foo.txt => txt
	 */
	final function extension() { return pathinfo( $this->path, PATHINFO_EXTENSION ); }

	/**
	 * @return string /blah/foo.txt => foo
	 */
	final function filename() { return pathinfo( $this->path, PATHINFO_FILENAME ); }

	/**
	 * @param string $dest
	 * @return void
	 */
	abstract protected function copyImpl( $dest );

	/**
	 * @param string $dest
	 * @return File the new file
	 */
	final function copy( $dest )
	{
		$this->copyImpl( $dest );
		return $this->system->file( $dest );
	}

	/**
	 * @return bool
	 */
	abstract function isFile();

	/**
	 * @return string[]
	 */
	abstract function scandir();

	final function subFiles()
	{
		$path   = $this->path;
		$dirSep = $this->system->dirSep();

		if ( !ends_with( $path, $dirSep ) )
			$path .= $dirSep;

		/** @var self[] $files */
		$files = array();
		foreach ( $this->scandir() as $p )
			if ( $p !== '.' && $p !== '..' )
				$files[ ] = $this->system->file( $path . $p );
		return $files;
	}

	/**
	 * @return bool
	 */
	abstract function isDir();

	/**
	 * @param int  $mode
	 * @param bool $recursive
	 * @return void
	 */
	abstract function mkdir( $mode = 0777, $recursive = false );

	/**
	 * @return bool
	 */
	abstract function isLink();

	/**
	 * @return string
	 */
	abstract function readlink();

	/**
	 * @return bool
	 */
	abstract function exists();

	final function ensureNotExists()
	{
		if ( $this->exists() )
			$this->unlink();
	}

	/**
	 * @return int
	 */
	abstract function size();

	abstract function unlink();

	/**
	 * @return int
	 */
	abstract function mtime();

	/**
	 * @return int
	 */
	abstract function ctime();

	/**
	 * @param int      $offset
	 * @param int|null $maxLength
	 * @return string
	 */
	abstract function read( $offset = 0, $maxLength = null );

	/**
	 * @param string $contents
	 * @return void
	 */
	abstract function write( $contents );

	/**
	 * @param string $contents
	 * @return boolean
	 */
	final function writeIfChanged( $contents )
	{
		$changed = !$this->exists() || $this->read() !== "$contents";
		if ( $changed )
			$this->write( $contents );
		return $changed;
	}

	/**
	 * @param string $contents
	 * @return void
	 */
	abstract function create( $contents );

	/**
	 * @param string $contents
	 * @return void
	 */
	abstract function append( $contents );

	abstract function rmdir();

	abstract protected function renameImpl( $to );

	final function rename( $to )
	{
		$this->renameImpl( $to );
		$this->path = $to;
	}

	/**
	 * @param int $mode
	 * @return void
	 */
	abstract function chmod( $mode );

	/**
	 * @return string
	 */
	abstract function realpath();

	function mkdirIgnore( $mode = 0777, $recursive = false )
	{
		if ( !$this->isDir() )
			$this->mkdir( $mode, $recursive );
	}
}

abstract class FOpenWrapperFile extends File
{
	function isFile()
	{
		clearstatcache( true );

		return is_file( $this->url() );
	}

	function isExecutable()
	{
		clearstatcache( true );

		return is_executable( $this->url() );
	}

	function isDir()
	{
		clearstatcache( true );

		return is_dir( $this->url() );
	}

	function mkdir( $mode = 0777, $recursive = false )
	{
		clearstatcache( true );

		Assert::true( mkdir( $this->url(), $mode, $recursive ) );
	}

	function isLink()
	{
		clearstatcache( true );

		return is_link( $this->url() );
	}

	function exists()
	{
		clearstatcache( true );

		return file_exists( $this->url() );
	}

	function size()
	{
		clearstatcache( true );

		return Assert::int( filesize( $this->url() ) );
	}

	function unlink()
	{
		clearstatcache( true );

		Assert::true( unlink( $this->url() ) );
	}

	function ctime()
	{
		clearstatcache( true );

		return Assert::int( filectime( $this->url() ) );
	}

	function rmdir()
	{
		Assert::true( rmdir( $this->url() ) );
	}

	protected function renameImpl( $to )
	{
		Assert::true( rename( $this->url(), $this->pathToUrl( $to ) ) );
	}

	protected function copyImpl( $dest )
	{
		Assert::true( copy( $this->url(), $this->pathToUrl( $dest ) ) );
	}

	function create( $contents ) { $this->writeImpl( $contents, 'xb' ); }

	function append( $contents ) { $this->writeImpl( $contents, 'ab' ); }

	function write( $contents ) { $this->writeImpl( $contents, 'wb' ); }

	function read( $offset = 0, $maxLength = null )
	{
		clearstatcache( true );

		if ( $maxLength === null )
		{
			return Assert::string( file_get_contents( $this->url(), false, null, $offset ) );
		}
		else
		{
			return Assert::string( file_get_contents( $this->url(), false, null, $offset, $maxLength ) );
		}
	}

	function scandir()
	{
		clearstatcache( true );

		return Assert::isArray( scandir( $this->url() ) );
	}

	function mtime()
	{
		clearstatcache( true );

		return Assert::int( filemtime( $this->url() ) );
	}

	private function writeImpl( $data, $mode )
	{
		Assert::resource( $handle = fopen( $this->url(), $mode ) );
		Assert::equal( fwrite( $handle, $data ), strlen( $data ) );
		Assert::true( fclose( $handle ) );
	}

	/**
	 * @param string $path
	 * @return string
	 */
	abstract protected function pathToUrl( $path );

	protected function url() { return $this->pathToUrl( $this->path() ); }
}

