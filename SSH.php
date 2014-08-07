<?php

namespace IVT\System;

use IVT\Assert;
use IVT\Exception;
use IVT\StringBuffer;
use Symfony\Component\Process\Process;

class SSHSystem extends System
{
	const EXIT_CODE_MARKER = "*EXIT CODE: ";

	/** @var string */
	private $user;
	/** @var string */
	private $host;
	/** @var resource */
	private $ssh;
	/** @var resource */
	private $sftp;
	/** @var string */
	private $cwd;
	private $connected = false;
	/** @var CommandOutputHandler */
	private $outputHandler;
	/** @var array */
	private $forwardedPorts = array();
	/** @var string */
	private $publicKeyFile;
	/** @var string */
	private $privateKeyFile;

	function __construct( $user, $host, CommandOutputHandler $outputHandler )
	{
		$this->user          = $user;
		$this->host          = $host;
		$this->outputHandler = $outputHandler;
	}

	function setPublicKeyFile( $file = null )
	{
		$this->publicKeyFile = $file;
	}

	function setPrivateKeyFile( $file = null )
	{
		$this->privateKeyFile = $file;
	}

	private function connect()
	{
		if ( $this->connected )
			return;

		$this->connected = true;

		$local = new LocalSystem;

		if ( !$local->isPortOpen( $this->host, 22, 20 ) )
		{
			throw new Exception( "Port 22 is not open on $this->host" );
		}

		Assert::resource( $this->ssh = ssh2_connect( $this->host, 22 ) );
		Assert::true( ssh2_auth_pubkey_file( $this->ssh, $this->user, $this->publicKeyFile, $this->privateKeyFile ) );
		Assert::resource( $this->sftp = ssh2_sftp( $this->ssh ) );
		Assert::string( $this->cwd = substr( $this->exec( 'pwd' ), 0, -1 ) );
	}

	function file( $path )
	{
		$this->connect();

		return new SSHFile( $this, $this->sftp, $path );
	}

	/**
	 * @param string               $command
	 * @param string               $input
	 * @param CommandOutputHandler $output
	 *
	 * @return int
	 */
	protected function runImpl( $command, $input, CommandOutputHandler $output )
	{
		$this->sshRunCommand( $this->wrapCommand( $command, $input ),
		                      $exitCodePruner = new ExitCodeStream( $output ) );

		return (int) $exitCodePruner->exitCode();
	}

	function connectDB( \DatabaseConnectionInfo $dsn )
	{
		return new SSHDBConnection( $this, $dsn );
	}

	function time()
	{
		return (int) substr( $this->exec( 'date +%s' ), 0, -1 );
	}

	/**
	 * @param string               $command
	 * @param CommandOutputHandler $output
	 */
	private function sshRunCommand( $command, CommandOutputHandler $output )
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
			$stdOutDone = $stdOutDone || $this->readStream( $stdOut, $output, false );
			$stdErrDone = $stdErrDone || $this->readStream( $stdErr, $output, true );

			usleep( 100000 );
		}
	}

	private function wrapCommand( $command, $stdIn = '' )
	{
		$this->connect();

		$cwdSh            = self::escapeCmd( $this->cwd );
		$stdInSh          = self::escapeCmd( $stdIn );
		$exitCodeMarkerSh = self::escapeCmd( self::EXIT_CODE_MARKER );

		$cdCmd = isset( $this->cwd ) ? "cd $cwdSh" : '';

		return <<<s
$cdCmd
echo -nE $stdInSh | ($command)
echo -nE $exitCodeMarkerSh\$?
s;
	}

	private function readStream( $resource, CommandOutputHandler $output, $isError )
	{
		Assert::string( $data = fread( $resource, 8192 ) );

		if ( !$isError )
			$output->writeOutput( $data );
		else
			$output->writeError( $data );

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
		$this->cwd = substr( $this->exec( "cd " . self::escapeCmd( $dir ) . " && pwd" ), 0, -1 );
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
		return "$this->user@$this->host";
	}

	function forwardPort( $remoteHost, $remotePort )
	{
		$forwarded =& $this->forwardedPorts[ $remoteHost ][ $remotePort ];

		if ( !$forwarded )
			$forwarded = $this->doPortForward( $remoteHost, $remotePort );

		return $forwarded;
	}

	private function doPortForward( $remoteHost, $remotePort )
	{
		if ( $remoteHost === 'localhost' )
			$remoteHost = '127.0.0.1';

		$process = null;
		$local   = new LocalSystem;

		for ( $attempts = 0; $attempts < 10; $attempts++ )
		{
			do
			{
				$port = \mt_rand( 49152, 65535 );
			}
			while ( $local->isPortOpen( 'localhost', $port, 1 ) );

			$process = new Process( <<<s
ssh -o ExitOnForwardFailure=yes -o BatchMode=yes \
	-i $this->privateKeyFile -N -L localhost:$port:$remoteHost:$remotePort $this->user@$this->host &

PID=$!
trap "kill \$PID" INT TERM EXIT
wait \$PID
s
			);
			$process->setTimeout( null );
			$process->start();

			$checks = 0;
			while ( $process->isRunning() )
			{
				usleep( 10000 );

				if ( $local->isPortOpen( 'localhost', $port, 1 ) )
					$checks++;
				else
					$checks = 0;

				if ( $checks >= 4 )
					return new SSHForwardedPort( $process, $port );
			}
		}

		$e = $process ? new CommandFailedException( CommandResult::fromSymfonyProcess( $process ) ) : null;

		throw new SSHForwardPortFailed( "Failed to forward a port after $attempts attempts :(", 0, $e );
	}
}

class SSHForwardPortFailed extends Exception
{
}

class ExitCodeStream extends DelegateOutputHandler
{
	/** @var StringBuffer */
	private $buffer;
	/** @var StringBuffer */
	private $marker;

	function __construct( CommandOutputHandler $output )
	{
		$this->buffer = new StringBuffer;
		$this->marker = new StringBuffer( SSHSystem::EXIT_CODE_MARKER );

		parent::__construct( $output );
	}

	function exitCode()
	{
		$marker = $this->marker;
		$buffer = $this->buffer;

		Assert::equal( "$marker", $buffer->remove( $marker->len() ) );

		return $buffer->removeAll();
	}

	function writeOutput( $data )
	{
		$buffer = $this->buffer;
		$marker = $this->marker;

		$buffer->append( $data );

		$markerPos = $buffer->findLast( $marker );

		if ( $markerPos !== false )
		{
			parent::writeOutput( $buffer->remove( $markerPos ) );
			
			return;
		}

		$pos = max( 0, $buffer->len() - $marker->len() );

		for ( ; ; $pos++ )
		{
			if ( $marker->startsWith( $buffer->after( $pos ) ) )
			{
				parent::writeOutput( $buffer->remove( $pos ) );

				return;
			}
		}

		throw new Exception( "The code above should always return. Why are we here?" );
	}
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
