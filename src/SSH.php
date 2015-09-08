<?php

namespace IVT\System;

use IVT\Assert;
use IVT\SystemException;

class SSHAuth
{
	private $user;
	private $host;
	private $port;
	private $publicKeyFile;
	private $privateKeyFile;

	function __construct( $user, $host, $port = 22 )
	{
		$this->user = $user;
		$this->host = $host;
		$this->port = $port;
	}

	function setPublicKeyFile( $file = null )
	{
		$this->publicKeyFile = $file;
	}

	function setPrivateKeyFile( $file = null )
	{
		$this->privateKeyFile = $file;
	}

	function connect()
	{
		$local = new LocalSystem;

		if ( !$local->isPortOpen( $this->host, $this->port, 20 ) )
		{
			throw new SystemException( "Port $this->port is not open on $this->host" );
		}

		Assert::resource( $ssh = ssh2_connect( $this->host, $this->port ) );
		Assert::true( ssh2_auth_pubkey_file( $ssh, $this->user, $this->publicKeyFile, $this->privateKeyFile ) );

		return $ssh;
	}

	function wrapCmd( System $system, $cmd )
	{
		return $this->sshCmd( $system, array(), "$cmd" );
	}

	function forwardPortCmd( System $system, $localPort, $remoteHost, $remotePort )
	{
		// PHP's proc_open() runs it's command in a shell with "sh -c ...".
		// 'exec' instructs the shell to not just run the command as a child,
		// but replace itself with it.
		//
		// Without 'exec', the process tree looks like this:
		//
		// init
		//  \_ ...
		//      \_ php
		//          \_ sh -c "ssh ..."
		//              \_ ssh ... 
		// 
		// And when PHP kills it's child, the "ssh" is left orphaned on the system:
		//
		// init
		//  \_ ...
		//  |   \_ php
		//  \_ ssh ... 
		// 
		// With 'exec', the process tree looks like this:
		//
		// init
		//  \_ ...
		//      \_ php
		//          \_ ssh ... 
		//
		// And when PHP kills it's child, nothing is left:
		//
		// init
		//  \_ ...
		//      \_ php
		//
		return 'exec ' . $this->sshCmd( $system, array( '-N', '-L', "localhost:$localPort:$remoteHost:$remotePort" ) );
	}

	/**
	 * @param System        $system
	 * @param string[]|null $opts
	 * @param string|null   $command
	 * @return string
	 */
	private function sshCmd( System $system, array $opts = array(), $command = null )
	{
		return $system->escapeCmdArgs( array_merge(
			array(
				'ssh',
				'-o', 'ExitOnForwardFailure=yes',
				'-o', 'BatchMode=yes',
				'-o', 'StrictHostKeyChecking=no',
				'-o', 'UserKnownHostsFile=/dev/null',
				'-i', $this->privateKeyFile,
			),
			$opts,
			array(
				"$this->user@$this->host",
			),
			$command !== null
				? array( $command )
				: array()
		) );
	}

	function describe() { return "$this->user@$this->host"; }
}

class SSHProcess extends Process
{
	private $onStdOut;
	private $onStdErr;
	private $stdOut;
	private $stdErr;
	private $getExitCode;

	/**
	 * @param resource $ssh
	 * @param string   $command
	 * @param \Closure $onStdOut
	 * @param \Closure $onStdErr
	 * @param \Closure $getExitCode
	 */
	function __construct( $ssh, $command, \Closure $onStdOut, \Closure $onStdErr, \Closure $getExitCode )
	{
		// Make sure as many of these objects are collected first before we start a new command.
		gc_collect_cycles();

		$this->onStdOut    = $onStdOut;
		$this->onStdErr    = $onStdErr;
		$this->getExitCode = $getExitCode;
		$this->stdOut      = Assert::resource( ssh2_exec( $ssh, $command ) );
		$this->stdErr      = Assert::resource( ssh2_fetch_stream( $this->stdOut, SSH2_STREAM_STDERR ) );

		Assert::true( stream_set_blocking( $this->stdOut, false ) );
		Assert::true( stream_set_blocking( $this->stdErr, false ) );
	}

	function __destruct()
	{
		if ( is_resource( $this->stdOut ) )
			Assert::true( fclose( $this->stdOut ) );
		if ( is_resource( $this->stdErr ) )
			Assert::true( fclose( $this->stdErr ) );
	}

	function isDone()
	{
		$stdOutDone = $this->isStreamDone( $this->stdOut, $this->onStdOut );
		$stdErrDone = $this->isStreamDone( $this->stdErr, $this->onStdErr );
		return $stdOutDone && $stdErrDone;
	}

	private function isStreamDone( $stream, \Closure $callback )
	{
		$eof = Assert::bool( feof( $stream ) );
		if ( !$eof )
			$callback( Assert::string( fread( $stream, 8192 ) ) );
		return $eof;
	}

	function wait()
	{
		while ( !$this->isDone() )
			usleep( 100000 );

		$exitCode = $this->getExitCode;
		return Assert::int( $exitCode() );
	}
}

class RemoveFileOnDestruct extends Process
{
	/** @var Process */
	private $process;
	/** @var File */
	private $file;

	function __construct( Process $process, File $file )
	{
		$this->process = $process;
		$this->file    = $file;
	}

	function __destruct()
	{
		$this->file->ensureNotExists();
	}

	function isDone() { return $this->process->isDone(); }
	function wait() { return $this->process->wait(); }
}

class SSHSystem extends System
{
	/** @var SSHAuth */
	private $auth;
	/** @var resource */
	private $ssh;
	/** @var resource */
	private $sftp;
	/** @var string */
	private $cwd;
	/** @var SSHForwardedPorts */
	private $forwardedPorts;

	function __construct( SSHAuth $auth )
	{
		$this->auth           = $auth;
		$this->forwardedPorts = new SSHForwardedPorts( $auth );
	}

	private function connect()
	{
		if ( $this->ssh )
			return;

		$this->ssh  = $this->auth->connect();
		$this->sftp = Assert::resource( ssh2_sftp( $this->ssh ) );
		$this->cwd  = Assert::string( substr( $this->exec( 'pwd' ), 0, -1 ) );
	}

	function file( $path )
	{
		$this->connect();

		return new SSHFile( $this, $this->sftp, $path );
	}

	function dirSep() { return '/'; }

	function runImpl( $command, $stdIn, \Closure $stdOut, \Closure $stdErr )
	{
		$command = "sh -c {$this->escapeCmd( $command )}";

		// If the input is short enough, pipe it into the command using "echo ... | cmd".
		// Otherwise, write it to a file and pipe it into the command using "cmd < file".
		if ( strlen( $stdIn ) < 1000 )
		{
			return $this->runImplHandleExitCode(
				"echo -nE {$this->escapeCmd( $stdIn )} | $command",
				$stdOut,
				$stdErr
			);
		}
		else
		{
			$tmpFile = $this->file( "/tmp/tmp-ssh-command-input-" . random_string( 12 ) );
			$tmpFile->write( $stdIn );

			$process = $this->runImplHandleExitCode(
				"$command < {$this->escapeCmd( $tmpFile->path() )}",
				$stdOut,
				$stdErr
			);
			$process = new RemoveFileOnDestruct( $process, $tmpFile );

			return $process;
		}
	}

	/**
	 * PHP's ssh2 extension doesn't provide a means to get the exit code of a command, so we have to
	 * munge the command to print the exit code after it finishes, and then parse it out.
	 * @param string   $command
	 * @param \Closure $stdOut
	 * @param \Closure $stdErr
	 * @return SSHProcess
	 */
	private function runImplHandleExitCode( $command, \Closure $stdOut, \Closure $stdErr )
	{
		$marker  = "*EXIT CODE: ";
		$wrapped = "$command\necho -nE {$this->escapeCmd( $marker )}$?";
		$buffer  = '';
		$stdOut  = function ( $data ) use ( $stdOut, &$buffer, $marker )
		{
			$buffer .= $data;

			// Stop at the start of the marker, if present
			$pos = strrpos( $buffer, $marker );

			// If we didn't find a marker, we need to check if the string ends with
			// the start of the marker.
			if ( $pos === false )
			{
				// Starting at len(marker) bytes short of the end
				$pos = max( 0, strlen( $buffer ) - strlen( $marker ) );

				// As long as the remaining buffer at this point is not the start of a marker
				while ( !starts_with( $marker, substr( $buffer, $pos ) ) )
					// Move forward
					$pos++;
			}

			// Send all bytes up to $pos, so we keep the marker
			$stdOut( (string) substr( $buffer, 0, $pos ) );
			$buffer = substr( $buffer, $pos );
		};

		// When we need the exit code, we need to parse it out of $buffer
		$getExitCode = function () use ( &$buffer, $marker )
		{
			// Make sure $buffer starts with the marker
			Assert::equal( $marker, substr( $buffer, 0, strlen( $marker ) ) );

			// The exit code will be whatever is after the marker
			return (int) substr( $buffer, strlen( $marker ) );
		};

		return $this->sshRunCommand( $wrapped, $stdOut, $stdErr, $getExitCode );
	}

	function time()
	{
		return (int) substr( $this->exec( 'date +%s' ), 0, -1 );
	}

	/**
	 * @param string   $command
	 * @param \Closure $onStdOut
	 * @param \Closure $onStdErr
	 * @param \Closure $getExitCode
	 * @return SSHProcess
	 */
	private function sshRunCommand( $command, \Closure $onStdOut, \Closure $onStdErr, \Closure $getExitCode )
	{
		$this->connect();

		if ( isset( $this->cwd ) )
			$command = "cd {$this->escapeCmd( $this->cwd )}\n$command";

		return new SSHProcess( $this->ssh, $command, $onStdOut, $onStdErr, $getExitCode );
	}

	function cd( $dir )
	{
		$dir = $this->exec( "cd {$this->escapeCmd( $dir )} && pwd" );
		$dir = substr( $dir, 0, -1 );

		$this->cwd = $dir;
	}

	function pwd()
	{
		$this->connect();

		return $this->cwd;
	}

	function describe()
	{
		return $this->auth->describe();
	}
}

class SSHForwardPortFailed extends SystemException
{
}

class SSHFile extends FOpenWrapperFile
{
	private $sftp, $system;

	/**
	 * @param SSHSystem $system
	 * @param resource  $sftp
	 * @param string    $path
	 */
	function __construct( SSHSystem $system, $sftp, $path )
	{
		$this->sftp   = $sftp;
		$this->system = $system;

		parent::__construct( $system, $path );
	}

	function mkdir( $mode = 0777, $recursive = false )
	{
		Assert::true( ssh2_sftp_mkdir( $this->sftp, $this->absolutePath(), $mode, $recursive ) );
	}

	function readlink()
	{
		return Assert::string( ssh2_sftp_readlink( $this->sftp, $this->absolutePath() ) );
	}

	function unlink()
	{
		Assert::true( ssh2_sftp_unlink( $this->sftp, $this->absolutePath() ) );
	}

	function ctime()
	{
		// ctime is not supported over SFTP2, so we run a command to get it instead.
		$stdout = $this->system->execArgs( array( 'stat', '-c', '%Z', $this->path() ) );

		return (int) substr( $stdout, 0, -1 );
	}

	function append( $contents ) { $this->_write( $contents, true, false ); }

	function create( $contents ) { $this->_write( $contents, false, true ); }

	function write( $contents ) { $this->_write( $contents, false, false ); return $this; }

	private function _write( $data, $append, $bailIfExists )
	{
		// In the case of append, 'a' doesn't work, so we need to open the file and seek to the end instead.
		// If the file exists, 'w' will truncate it, and 'x' will throw an error. 'c' is not supported by the library.
		// That just leaves 'r+', which will throw an error if the file doesn't exist. So the best thing we can do is
		// use 'r+' if the file exists and 'w' if it doesn't.
		$append = $append && $this->exists();

		if ( $bailIfExists )
			$mode = 'xb';
		else if ( $append )
			$mode = 'r+b';
		else
			$mode = 'wb';

		Assert::resource( $handle = fopen( $this->url(), $mode ) );

		if ( $append )
			Assert::equal( fseek( $handle, 0, SEEK_END ), 0 );

		Assert::equal( fwrite( $handle, $data ), strlen( $data ) );
		Assert::true( fclose( $handle ) );
	}

	private function absolutePath()
	{
		return $this->absolutePath1( $this->path() );
	}

	private function absolutePath1( $path )
	{
		return starts_with( $path, '/' ) ? $path : $this->system->pwd() . '/' . $path;
	}

	protected function pathToUrl( $path )
	{
		return "ssh2.sftp://$this->sftp/.{$this->absolutePath1( $path )}";
	}

	function chmod( $mode )
	{
		/** @noinspection PhpUndefinedFunctionInspection */
		Assert::true( ssh2_sftp_chmod( $this->sftp, $this->absolutePath(), $mode ) );
	}

	protected function renameImpl( $to )
	{
		Assert::true( ssh2_sftp_rename( $this->sftp, $this->absolutePath(), $to ) );
	}

	function realpath()
	{
		return Assert::string( ssh2_sftp_realpath( $this->sftp, $this->absolutePath() ) );
	}
}
