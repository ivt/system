<?php

namespace IVT\System;

use IVT\Exception;
use IVT\str;

class SSHCredentials
{
	private $user, $host, $port, $privateKeyFile, $publicKeyFile;

	function __construct( $user, $host, $port, $privateKeyFile, $publicKeyFile )
	{
		$this->user           = $user;
		$this->host           = $host;
		$this->port           = $port;
		$this->privateKeyFile = $privateKeyFile;
		$this->publicKeyFile  = $publicKeyFile;
	}

	function user() { return $this->user; }

	function host() { return $this->host; }

	function port() { return $this->port; }

	function keyFile() { return $this->privateKeyFile; }

	function keyFilePublic() { return $this->publicKeyFile; }

	function __toString()
	{
		return "$this->user@$this->host:$this->port (pubkey: $this->publicKeyFile, privkey: $this->privateKeyFile)";
	}
}

class SSHSystem extends System
{
	const EXIT_CODE_MARKER = "*EXIT CODE: ";

	private $ssh, $sftp, $credentials, $cwd;
	private $delegate;

	function credentials() { return $this->credentials; }

	function __construct( SSHCredentials $credentials, CommandOutputHandler $delegate )
	{
		$this->credentials = $credentials;
		$this->delegate    = $delegate;

		$host = $credentials->host();
		$port = $credentials->port();

		if ( !LocalSystem::isPortOpen( $host, $port, 5 ) )
		{
			throw new Exception( "Port $port is not open on $host" );
		}

		assertNotFalse( $this->ssh = ssh2_connect( $host, $port ), "Connection failed" );

		assertNotFalse( ssh2_auth_pubkey_file( $this->ssh,
		                                       $credentials->user(),
		                                       $credentials->keyFilePublic(),
		                                       $credentials->keyFile() ), "authentication failed" );

		assertNotFalse( $this->sftp = ssh2_sftp( $this->ssh ), "Failed to get SFTP subsystem" );
		assertNotFalse( $this->cwd = substr( $this->shellExec( 'pwd' ), 0, -1 ) );
	}

	function pwd() { return $this->cwd; }

	function file( $path )
	{
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
		return new SSHDBConnection( $this->credentials, $dsn );
	}

	function currentTimestamp()
	{
		return (int) substr( $this->shellExec( 'date +%s' ), 0, -1 );
	}

	/**
	 * @param string               $command
	 * @param CommandOutputHandler $output
	 */
	private function sshRunCommand( $command, CommandOutputHandler $output )
	{
		assertNotFalse( $stdOut = ssh2_exec( $this->ssh, $command ) );
		assertNotFalse( $stdErr = ssh2_fetch_stream( $stdOut, SSH2_STREAM_STDERR ) );

		assertNotFalse( stream_set_blocking( $stdOut, false ) );
		assertNotFalse( stream_set_blocking( $stdErr, false ) );

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
		assertNotFalse( $data = fread( $resource, 8192 ) );

		if ( !$isError )
			$output->writeOutput( $data );
		else
			$output->writeError( $data );

		if ( feof( $resource ) )
		{
			// For some reason, with SSH2, we have to set the stream to blocking mode and call
			// fread() again, otherwise the next call to ssh2_exec() will fail.
			assertNotFalse( stream_set_blocking( $resource, true ) );
			assertNotFalse( $data = fread( $resource, 8192 ) );
			assertEqual( $data, '' );
			assertNotFalse( fclose( $resource ) );

			return true;
		}

		return false;
	}

	function setWorkingDirectory( $dir )
	{
		$this->cwd = substr( $this->shellExec( "cd " . self::escapeCmd( $dir ) . " && pwd" ), 0, -1 );
	}

	/**
	 * @return string
	 */
	function getWorkingDirectory()
	{
		return $this->cwd;
	}

	function writeOutput( $data )
	{
		$this->delegate->writeOutput( $data );
	}

	function writeError( $data )
	{
		$this->delegate->writeError( $data );
	}
}

class ExitCodeStream extends DelegateOutputHandler
{
	private $buffer = '', $marker = SSHSystem::EXIT_CODE_MARKER;

	function exitCode()
	{
		$buffer = str::mk( $this->buffer );
		$marker = str::mk( $this->marker );

		assertEqual( $this->marker, $buffer->take( $marker->len() ) );

		return $buffer->skip( $marker->len() );
	}

	function writeOutput( $data )
	{
		$buffer = str::mk( $this->buffer .= $data );
		$marker = str::mk( $this->marker );

		if ( $buffer->contains( $marker, true ) )
		{
			// Send data up to the last marker we found.
			$this->send( $buffer->lastPos( $marker ) );

			return;
		}

		// Loop from $buffer->len() - $marker->len() to $buffer->len(), each time checking
		// if this part of the buffer is the start of a marker.
		//
		// Once we reach $buffer->len(), the whole buffer is sent.

		for ( $pos = max( 0, $buffer->len() - $marker->len() ); $pos <= $buffer->len(); $pos++ )
		{
			if ( $marker->startsWith( $buffer->skip( $pos ) ) )
			{
				$this->send( $pos );

				return;
			}
		}

		throw new Exception( "The code above should always return. Why are we here?" );
	}

	/**
	 * Send data from the buffer up to $pos.
	 *
	 * @param int $pos
	 */
	private function send( $pos )
	{
		$buffer = str::mk( $this->buffer );
		parent::writeOutput( $buffer->take( $pos ) );
		$this->buffer = $buffer->skip( $pos );
	}
}

class SSHFile extends File
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

	function getContents( $offset = 0, $maxLength = PHP_INT_MAX )
	{
		clearstatcache( true );

		if ( $maxLength == PHP_INT_MAX )
		{
			assertNotFalse( $result = file_get_contents( $this->sftpURL(), false, null, $offset ) );
		}
		else
		{
			assertNotFalse( $result = file_get_contents( $this->sftpURL(), false, null, $offset, $maxLength ) );
		}

		return $result;
	}

	function isFile()
	{
		clearstatcache( true );

		return is_file( $this->sftpURL() );
	}

	function scanDir()
	{
		clearstatcache( true );

		assertNotFalse( $result = scandir( $this->sftpURL() ) );

		return $result;
	}

	function isDir()
	{
		clearstatcache( true );

		return is_dir( $this->sftpURL() );
	}

	function createDir( $mode = 0777, $recursive = false )
	{
		assertNotFalse( ssh2_sftp_mkdir( $this->sftp, $this->absolutePath(), $mode, $recursive ) );
	}

	function isLink()
	{
		clearstatcache( true );

		return is_link( $this->sftpURL() );
	}

	function readLink()
	{
		assertNotFalse( $result = ssh2_sftp_readlink( $this->sftp, $this->absolutePath() ) );

		return $result;
	}

	function exists()
	{
		clearstatcache( true );

		return file_exists( $this->sftpURL() );
	}

	function fileSize()
	{
		clearstatcache( true );

		assertNotFalse( $size = filesize( $this->sftpURL() ) );

		return $size;
	}

	function removeFile()
	{
		assertNotFalse( ssh2_sftp_unlink( $this->sftp, $this->absolutePath() ) );
	}

	function lastModified()
	{
		clearstatcache( true );

		assertNotFalse( $mtime = filemtime( $this->sftpURL() ) );

		return $mtime;
	}

	function lastStatusCange()
	{
		// ctime is not supported over SFTP2, so we run a command to get it instead.
		$stdout = $this->system->shellExec( "stat -c %Z " . SSHSystem::escapeCmd( $this->path() ) );

		return (int) substr( $stdout, 0, -1 );
	}

	function removeDir()
	{
		assertNotFalse( rmdir( $this->sftpURL() ) );
	}

	function appendContents( $contents ) { $this->writeImpl( $contents, true, false ); }

	function createWithContents( $contents ) { $this->writeImpl( $contents, false, true ); }

	function setContents( $contents ) { $this->writeImpl( $contents, false, false ); }

	private function writeImpl( $data, $append, $bailIfExists )
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

		assertNotFalse( $handle = fopen( $this->sftpURL(), $mode ) );

		if ( $append )
			assertEqual( fseek( $handle, 0, SEEK_END ), 0 );

		assertEqual( fwrite( $handle, $data ), strlen( $data ) );
		assertNotFalse( fclose( $handle ) );
	}

	private function absolutePath()
	{
		$path = $this->path();

		return starts_with( $path, '/' ) ? $path : $this->system->pwd() . '/' . $path;
	}

	private function sftpURL()
	{
		$result = "ssh2.sftp://$this->sftp{$this->absolutePath()}";

		clearstatcache( true );

		return $result;
	}
}
