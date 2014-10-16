<?php

namespace IVT\System;

use IVT\Assert;
use IVT\Exception;

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
			throw new Exception( "Port $this->port is not open on $this->host" );
		}

		Assert::resource( $ssh = ssh2_connect( $this->host, $this->port ) );
		Assert::true( ssh2_auth_pubkey_file( $ssh, $this->user, $this->publicKeyFile, $this->privateKeyFile ) );

		return $ssh;
	}

	function wrapCmd( System $system, $cmd )
	{
		return $this->sshCmd( $system, null, "$cmd" );
	}

	function forwardPortCmd( System $system, $localPort, $remoteHost, $remotePort )
	{
		$ssh = $this->sshCmd( $system, "-N -L localhost:$localPort:$remoteHost:$remotePort" );

		return <<<s
$ssh &
PID=$!
trap "kill \$PID" INT TERM EXIT
wait \$PID
s;
	}

	private function sshCmd( System $system, $opts = null, $command = null )
	{
		$cmd[ ] = "ssh";
		$cmd[ ] = " -o ExitOnForwardFailure=yes";
		$cmd[ ] = " -o BatchMode=yes";
		$cmd[ ] = " -o StrictHostKeyChecking=no";
		$cmd[ ] = " -o UserKnownHostsFile=/dev/null";
		$cmd[ ] = " -i " . $system->escapeCmd( $this->privateKeyFile );

		if ( $opts !== null )
			$cmd[ ] = $opts;

		$cmd[ ] = $system->escapeCmd( "$this->user@$this->host" );

		if ( $command !== null )
			$cmd[ ] = $system->escapeCmd( $command );

		return join( ' ', $cmd );
	}

	function describe() { return "$this->user@$this->host"; }
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
	/** @var CommandOutputHandler */
	private $outputHandler;
	/** @var SSHForwardedPorts */
	private $forwardedPorts;

	function __construct( SSHAuth $auth, CommandOutputHandler $outputHandler )
	{
		$this->auth           = $auth;
		$this->outputHandler  = $outputHandler;
		$this->forwardedPorts = new SSHForwardedPorts( $auth );
	}

	private function connect()
	{
		if ( $this->ssh )
			return;

		$this->ssh = $this->auth->connect();
		Assert::resource( $this->sftp = ssh2_sftp( $this->ssh ) );
		Assert::string( $this->cwd = substr( $this->exec( 'pwd' ), 0, -1 ) );
	}

	function file( $path )
	{
		$this->connect();

		return new SSHFile( $this, $this->sftp, $path );
	}

	/**
	 * @param string   $command
	 * @param string   $stdIn
	 * @param callable $stdOut
	 * @param callable $stdErr
	 * @return int
	 */
	protected function runImpl( $command, $stdIn, \Closure $stdOut, \Closure $stdErr )
	{
		$this->connect();

		$marker  = "*EXIT CODE: ";
		$wrapped = <<<s
echo -nE {$this->escapeCmd( $stdIn )} | ($command)
echo -nE {$this->escapeCmd( $marker )}\$?
s;

		if ( isset( $this->cwd ) )
			$wrapped = "cd {$this->escapeCmd( $this->cwd )}\n$wrapped";

		$buffer = '';
		$stdOut = function ( $data ) use ( $stdOut, &$buffer, $marker )
		{
			$buffer .= $data;

			$pos = strrpos( $buffer, $marker );

			if ( $pos === false )
			{
				$pos = max( 0, strlen( $buffer ) - strlen( $marker ) );

				while ( !starts_with( $marker, substr( $buffer, $pos ) ) )
					$pos++;
			}

			$stdOut( substr( $buffer, 0, $pos ) );
			$buffer = substr( $buffer, $pos );
		};

		$this->sshRunCommand( $wrapped, $stdOut, $stdErr );

		Assert::equal( $marker, substr( $buffer, 0, strlen( $marker ) ) );

		return (int) substr( $buffer, strlen( $marker ) );
	}

	function connectDB( \DatabaseConnectionInfo $dsn )
	{
		return new SSHDBConnection( $this->forwardedPorts, $dsn );
	}

	function time()
	{
		return (int) substr( $this->exec( 'date +%s' ), 0, -1 );
	}

	/**
	 * @param string   $command
	 * @param callable $fStdOut
	 * @param callable $fStdErr
	 */
	private function sshRunCommand( $command, \Closure $fStdOut, \Closure $fStdErr )
	{
		$this->connect();

		Assert::resource( $stdOut = ssh2_exec( $this->ssh, $command ) );
		Assert::resource( $stdErr = ssh2_fetch_stream( $stdOut, SSH2_STREAM_STDERR ) );

		Assert::true( stream_set_blocking( $stdOut, false ) );
		Assert::true( stream_set_blocking( $stdErr, false ) );

		$stdErrDone = false;
		$stdOutDone = false;

		while ( !$stdErrDone || !$stdOutDone )
		{
			$stdOutDone = $stdOutDone || $this->readStream( $stdOut, $fStdOut );
			$stdErrDone = $stdErrDone || $this->readStream( $stdErr, $fStdErr );

			usleep( 100000 );
		}
	}

	private function readStream( $resource, \Closure $into )
	{
		Assert::string( $data = fread( $resource, 8192 ) );

		$into( $data );

		if ( feof( $resource ) )
		{
			// For some reason, with SSH2, we have to set the stream to blocking mode and call
			// fread() again, otherwise the next call to ssh2_exec() will fail.
			Assert::true( stream_set_blocking( $resource, true ) );
			Assert::equal( fread( $resource, 8192 ), '' );
			Assert::true( fclose( $resource ) );

			return true;
		}

		return false;
	}

	function cd( $dir )
	{
		$this->cwd = substr( $this->exec( "cd " . $this->escapeCmd( $dir ) . " && pwd" ), 0, -1 );
	}

	function pwd()
	{
		$this->connect();

		return $this->cwd;
	}

	function writeOutput( $data )
	{
		$this->outputHandler->writeOutput( $data );
	}

	function writeError( $data )
	{
		$this->outputHandler->writeError( $data );
	}

	function describe()
	{
		return $this->auth->describe();
	}
}

class SSHForwardPortFailed extends Exception
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

	function write( $contents ) { $this->_write( $contents, false, false ); }

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
		return "ssh2.sftp://$this->sftp{$this->absolutePath1( $path )}";
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
