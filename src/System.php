<?php

namespace IVT\System;

use IVT\Assert;
use IVT\Log;

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

abstract class Process
{
	/**
	 * @param self[] $processes
	 */
	static function waitAll( array $processes )
	{
		while ( true )
		{
			foreach ( $processes as $k => $process )
				if ( $process->isDone() )
					unset( $processes[ $k ] );

			if ( $processes )
				usleep( 100000 );
			else
				break;
		}
	}

	final function isRunning() { return !$this->isDone(); }

	function exitStatus() { return $this->wait(); }

	function succeeded()
	{
		return $this->exitStatus() === 0;
	}

	function failed()
	{
		return $this->exitStatus() !== 0;
	}

	/**
	 * @return bool Whether the process has finished
	 */
	abstract function isDone();

	/**
	 * Waits for the process to finish and returns the exit code
	 * @return int
	 */
	abstract function wait();
}

abstract class System implements FileSystem
{
	final static function removeSecrets( $string )
	{
		$gitHub    = '(\w+(:\w+)?)(?=@github.com)';
		$awsKey    = '(?<=\-\-key=)\S+';
		$awsSecret = '(?<=\-\-secret=)\S+';

		return \PCRE::replace( "$gitHub|$awsKey|$awsSecret", $string, '[HIDDEN]' );
	}

	final function wrapLogging( Log $log )
	{
		return new LoggingSystem( $this, $log );
	}

	function escapeCmd( $arg )
	{
		$arg1    = str_replace( str_split( "=:_+./-" ), '', $arg );
		$isValid = $arg1 === '' || ctype_alnum( $arg1 );

		return $isValid && $arg !== '' ? $arg : escapeshellarg( $arg );
	}

	final function escapeCmdArgs( array $args )
	{
		foreach ( $args as &$arg)
			$arg = $this->escapeCmd( $arg );

		return join( ' ', $args );
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
	 * @param string   $dir
	 * @param callable $f
	 * @return mixed
	 * @throws \Exception
	 */
	final function inDir( $dir, \Closure $f )
	{
		$cwd = $this->pwd();
		try
		{
			$this->cd( $dir );
			$result = $f();
			$this->cd( $cwd );
			return $result;
		}
		catch ( \Exception $e )
		{
			$this->cd( $cwd );
			throw $e;
		}
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
	 * @param string $directory
	 * @return string
	 */
	final function createTarXz( $directory )
	{
		return $this->execArgs( array( 'tar', '-cJ', '-C', $directory, '.' ) );
	}

	/**
	 * @param string $tarball
	 * @param string $directory
	 */
	final function extractTarXz( $tarball, $directory )
	{
		$this->execArgs( array( 'tar', '-xJ', '-C', $directory ), $tarball );
	}

	/**
	 * @param string $from
	 * @param string $to
	 */
	final function copy( $from, $to )
	{
		$this->execArgs( array( 'cp', '-rT', $from, $to ) );
	}

	final function ensureNotExists( $path )
	{
		$this->execArgs( array( 'rm', '-rf', $path ) );
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

	final function replaceInFileMany( $file, array $replacements )
	{
		foreach ( $replacements as $search => $replace )
			$this->replaceInFile( $search, $replace, $file );
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
	 * @return CommandResult
	 */
	final function runCommandAsync( $command, $stdIn = '' )
	{
		return new CommandResult( $this, $command, $stdIn );
	}

	/**
	 * @param string $command
	 * @param string $stdIn
	 * @return CommandResult
	 */
	final function runCommand( $command, $stdIn = '' )
	{
		$result = $this->runCommandAsync( $command, $stdIn );
		$result->wait();
		return $result;
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

	/**
	 * @param string[] $commands
	 * @return CommandResult[]
	 */
	final function runCommandAsyncMany( array $commands )
	{
		/** @var CommandResult[] $processes */
		$processes = array();
		foreach ( $commands as $command )
			$processes[ ] = $this->runCommandAsync( $command );
		return $processes;
	}

	final function runAsync( $command, $stdIn = '' )
	{
		return $this->runImpl( $command, $stdIn, function () {}, function () {} );
	}

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
	 * @param string   $command
	 * @param string   $stdIn
	 * @param callable $stdOut
	 * @param callable $stdErr
	 * @return Process
	 */
	abstract function runImpl( $command, $stdIn, \Closure $stdOut, \Closure $stdErr );

	/**
	 * If this System happens to be a wrapper around another System, this
	 * applies the same wrapping to the given system.
	 * @param System $system
	 * @return System
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

	final function is( self $other )
	{
		return $this->path === $other->path;
	}

	final function path() { return $this->path; }

	/**
	 * @return string
	 * @deprecated
	 */
	final function __toString() { return $this->path(); }

	final function on( FileSystem $system )
	{
		return $system->file( $this->path );
	}

	/**
	 * @param bool $followLinks
	 * @return self[]
	 */
	final function recursiveScan( $followLinks = true )
	{
		$results = array( $this );

		if ( $this->isDir() && ( $followLinks || !$this->isLink() ) )
		{
			foreach ( $this->dirContents() as $file )
			{
				$results = array_merge( $results, $file->recursiveScan( $followLinks ) );
			}
		}

		return $results;
	}

	final function parentDirectory()
	{
		return $this->system->file( $this->dirname() );
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

	final function createDirs( $mode = 0777 )
	{
		$this->system->file( $this->dirname() )->ensureIsDir( $mode, true );
		return $this;
	}

	/**
	 * Combine this path with the given path, placing a directory separator
	 * between them if necessary
	 *
	 * @param string $path
	 * @return string
	 */
	final function combinePath( $path )
	{
		return $this->combinePaths( $this->path, $path );
	}

	final function startsWith( $string )
	{
		return $this->read( 0, strlen( $string ) ) === "$string";
	}

	/**
	 * @param string $path1
	 * @param string $path2
	 * @return string
	 */
	final function combinePaths( $path1, $path2 )
	{
		$dirSep = $this->system->dirSep();

		if ( starts_with( $path2, $dirSep ) || ends_with( $path1, $dirSep ) )
			return $path1 . $path2;
		else
			return $path1 . $dirSep . $path2;
	}

	final function combine( $path )
	{
		return $this->system->file( $this->combinePath( $path ) );
	}

	function isBlockDevice() { return $this->fileType() === 'block'; }

	/**
	 * @return string
	 */
	abstract function fileType();

	/**
	 * @return int
	 */
	abstract function perms();

	/**
	 * @param string $dest
	 * @return void
	 */
	abstract protected function copyImpl( $dest );

	/**
	 * @param string $to
	 * @return File the new file
	 */
	final function copy( $to )
	{
		$this->copyImpl( $to );
		return $this->system->file( $to );
	}

	/**
	 * @param string $dir
	 */
	final function copyDirContents( $dir )
	{
		foreach ( $this->scanDirNoDots() as $file )
			$this->combine( $file )->copyRecursive( $this->combinePaths( $dir, $file ) );
	}

	/**
	 * @param string $to
	 * @return \IVT\System\File
	 */
	final function copyRecursive( $to )
	{
		if ( $this->isDir() && !$this->isLink() )
		{
			$to = $this->system->file( $to );
			$to->mkdir();
			$this->copyDirContents( $to->path );
			return $to;
		}
		else
		{
			return $this->copy( $to );
		}
	}

	/**
	 * @return bool
	 */
	abstract function isFile();

	/**
	 * @return string[]
	 */
	abstract function scanDir();

	final function scanDirNoDots()
	{
		return array_values( array_diff( $this->scanDir(), array( '.', '..' ) ) );
	}

	final function removeContents()
	{
		foreach ( $this->dirContents() as $file )
			$file->removeRecursive();
	}

	final function dirContents()
	{
		/** @var self[] $files */
		$files = array();
		foreach ( $this->scanDirNoDots() as $p )
			$files[ ] = $this->combine( $p );
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

	/**
	 * @return bool Whether the file was removed
	 */
	final function ensureNotExists()
	{
		$remove = $this->exists();
		if ( $remove )
			$this->removeRecursive();
		return $remove;
	}

	/**
	 * @return int
	 */
	abstract function size();

	abstract function unlink();

	/**
	 * Recursive version of remove()
	 */
	final function removeRecursive()
	{
		if ( $this->isDir() && !$this->isLink() )
		{
			foreach ( $this->dirContents() as $file )
				$file->removeRecursive();

			$this->rmdir();
		}
		else
		{
			$this->unlink();
		}
	}

	/**
	 * Calls unlink() for files and rmdir() for directories, like remove() in C.
	 */
	final function remove()
	{
		if ( $this->isDir() && !$this->isLink() )
			$this->rmdir();
		else
			$this->unlink();
	}

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

	final function readIfFile()
	{
		return $this->isFile() ? $this->read() : null;
	}

	final function readLinkIfLink()
	{
		return $this->isLink() ? $this->readlink() : null;
	}

	/**
	 * @param string $contents
	 * @return File
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

	function ensureIsDir( $mode = 0777, $recursive = false )
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

		Assert::true( mkdir( $this->url(), $mode, $recursive ), "Failed to create directory at {$this->url()}" );
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

		return Assert::int( filesize( $this->url() ), "Failed to get file size on {$this->url()}" );
	}

	function unlink()
	{
		clearstatcache( true );

		Assert::true( unlink( $this->url() ), "Failed to unlink file at {$this->url()}" );
	}

	function ctime()
	{
		clearstatcache( true );

		return Assert::int( filectime( $this->url() ), "Failed to read create time on file {$this->url()}" );
	}

	function fileType()
	{
		clearstatcache( true );

		return Assert::string( filetype( $this->url() ), "Failed to get file type of {$this->url()}" );
	}

	function perms()
	{
		clearstatcache( true );

		return Assert::int( fileperms( $this->url() ), "Failed to get file permissions on {$this->url()}" );
	}

	function rmdir()
	{
		Assert::true( rmdir( $this->url() ), "Failed to remove directory at {$this->url()}" );
	}

	protected function renameImpl( $to )
	{
		Assert::true( rename( $this->url(), $this->pathToUrl( $to ) ), "Failed to rename file at {$this->url()}" );
	}

	protected function copyImpl( $dest )
	{
		Assert::true( copy( $this->url(), $this->pathToUrl( $dest ) ),
			"Failed to copy file at {$this->url()} to {$this->pathToUrl( $dest )}");
	}

	function create( $contents ) { $this->writeImpl( $contents, 'xb' ); }

	function append( $contents ) { $this->writeImpl( $contents, 'ab' ); }

	function write( $contents ) { $this->writeImpl( $contents, 'wb' ); return $this; }

	function read( $offset = 0, $maxLength = null )
	{
		clearstatcache( true );

		if ( $maxLength === null )
		{
			return Assert::string( file_get_contents( $this->url(), false, null, $offset ),
				"Failed to read file at {$this->url()}" );
		}
		else
		{
			return Assert::string( file_get_contents( $this->url(), false, null, $offset, $maxLength ),
				"Failed to read file at {$this->url()}" );
		}
	}

	function scanDir()
	{
		clearstatcache( true );

		return Assert::isArray( scandir( $this->url() ), "Failed to scan directory at {$this->url()}" );
	}

	function mtime()
	{
		clearstatcache( true );

		return Assert::int( filemtime( $this->url() ), "Failed to read mod time on file at {$this->url()}" );
	}

	private function writeImpl( $data, $mode )
	{
		Assert::resource( $handle = fopen( $this->url(), $mode ), "Failed to open file for write at {$this->url()}" );
		Assert::equal( fwrite( $handle, $data ), strlen( $data ), "Failed to write to file at {$this->url()}" );
		Assert::true( fclose( $handle ), "Failed to close file handle after write() on {$this->url()}" );
	}

	/**
	 * @param string $path
	 * @return string
	 */
	abstract protected function pathToUrl( $path );

	protected function url() { return $this->pathToUrl( $this->path() ); }
}
