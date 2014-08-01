<?php

namespace IVT\System;

use DatabaseConnectionInfo;
use Dbase_SQL_Driver;
use DbaseConnectionFailed;
use Symfony\Component\Process\Process;

class SSHDBConnection extends \Dbase_SQL_Driver
{
	/**
	 * This object will keep the port forwarded until the object it gets GC'd, so its important
	 * we keep a reference to it.
	 *
	 * @var SSHForwardedPort
	 */
	private $forwardedPort;

	/**
	 * @param SSHForwardedPorts      $ssh
	 * @param DatabaseConnectionInfo $dsn
	 *
	 * @throws DbaseConnectionFailed
	 */
	function __construct( SSHForwardedPorts $ssh, DatabaseConnectionInfo $dsn )
	{
		$this->forwardedPort = $ssh->forward( $dsn->host(), $dsn->port() ? : '3306' );

		try
		{
			parent::__construct( new DatabaseConnectionInfo( $dsn->type(),
			                                                 "127.0.0.1:{$this->forwardedPort->localPort()}",
			                                                 $dsn->user(),
			                                                 $dsn->password(),
			                                                 $dsn->database() ) );
		}
		catch ( DbaseConnectionFailed $e )
		{
			$e2 = new CommandFailedException( $this->forwardedPort->commandResult() );

			throw new DbaseConnectionFailed( $e->getMessage(), $e->getCode(), $e2 );
		}
	}
}

class SSHForwardedPorts
{
	/** @var SSHCredentials */
	private $credentials;
	private $forwardedPorts = array();

	function __construct( SSHCredentials $ssh )
	{
		$this->credentials = $ssh;
	}

	function forward( $remoteHost, $reportPort )
	{
		$forwarded =& $this->forwardedPorts[ $remoteHost ][ $reportPort ];

		if ( !$forwarded )
			$forwarded = new SSHForwardedPort( $this->credentials, $remoteHost, $reportPort );

		return $forwarded;
	}
}

class SSHForwardedPort
{
	/**
	 * It is important that we store this object in a property, so that the process continues
	 * running until this object is GC'd. The destructor for the object will kill the
	 * process, removing our forwarded port.
	 *
	 * @var Process
	 */
	private $process;
	private $localHost;
	private $localPort;
	private $remotePort;
	private $remoteHost;
	private $ssh;

	function __construct( SSHCredentials $ssh, $remoteHost, $remotePort )
	{
		// PHP only collects cycles when the number of "roots" hits 1000 and
		// by that time there may be many instances of this object in memory,
		// all keeping an SSH connection open with a forwarded port.
		//
		// To prevent many instances of this object from building up and
		// keeping forwarded ports open, we will force the cycle collector to
		// run each time this object is instantiated. At least then there
		// will only ever be at most 1 instance of this class left
		// unreferenced waiting to be collected at any time.
		gc_collect_cycles();

		$this->ssh        = $ssh;
		$this->remoteHost = $remoteHost === 'localhost' ? '127.0.0.1' : $remoteHost;
		$this->remotePort = $remotePort;
		$this->localHost  = '127.0.0.1';

		$lastException = null;

		for ( $attempts = 0; $attempts < 10; $attempts++ )
		{
			try
			{
				$this->localPort = self::randomEphemeralPort();
				$this->process   = $this->tryForwardPort();

				return;
			}
			catch ( SSHForwardPortAlreadyOpen $e )
			{
				$lastException = $e;
			}
			catch ( CommandFailedException $e )
			{
				$lastException = $e;
			}
		}

		throw new DbaseConnectionFailed( "Failed to forward a port after $attempts attempts :(", 0, $lastException );
	}

	function localPort() { return $this->localPort; }

	private static function randomEphemeralPort()
	{
		return \rand( 49152, 65535 );
	}

	function commandResult()
	{
		$this->process->stop();

		return CommandResult::fromSymfonyProcess( $this->process );
	}

	/**
	 * @throws CommandFailedException
	 * @throws SSHForwardPortAlreadyOpen
	 * @return Process
	 */
	private function tryForwardPort()
	{
		if ( $this->isPortOpen() )
		{
			throw new SSHForwardPortAlreadyOpen( "Port $this->localPort already open" );
		}

		$process = new Process( $this->sshCommand() );
		$process->setTimeout( null );
		$process->start();

		// I don't know why but just checking $process->isRunning() &&
		// $this->isPortOpen() succeeds sometimes even when the port hasn't
		// been forwarded yet. So instead, the port must appear to be
		// successfully forwarded at least 4 times in a row with a 0.01s
		// interval.
		for ( $i = 0; $i < 4; $this->isPortOpen() ? $i++ : $i = 0 )
		{
			usleep( 10000 );

			if ( !$process->isRunning() )
			{
				throw new CommandFailedException( CommandResult::fromSymfonyProcess( $process ) );
			}
		}

		return $process;
	}

	private function isPortOpen()
	{
		$local = new LocalSystem;

		return $local->isPortOpen( $this->localHost, $this->localPort, 1 );
	}

	private function sshCommand()
	{
		$localHost  = System::escapeCmd( $this->localHost );
		$localPort  = System::escapeCmd( $this->localPort );
		$remoteHost = System::escapeCmd( $this->remoteHost );
		$remotePort = System::escapeCmd( $this->remotePort );
		$key        = System::escapeCmd( $this->ssh->keyFile() );
		$host       = System::escapeCmd( $this->ssh->host() );
		$user       = System::escapeCmd( $this->ssh->user() );

		return <<<s
ssh -o ExitOnForwardFailure=yes -o BatchMode=yes -o StrictHostKeyChecking=no \
	-i $key -N -L $localHost:$localPort:$remoteHost:$remotePort $user@$host &

PID=$!
trap "kill \$PID" INT TERM EXIT
wait \$PID
s;
	}
}

class SSHForwardPortAlreadyOpen extends \Exception
{
}

