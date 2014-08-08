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
	 * @param SSHSystem              $ssh
	 * @param DatabaseConnectionInfo $dsn
	 * @throws \DbaseConnectionFailed
	 */
	function __construct( SSHSystem $ssh, DatabaseConnectionInfo $dsn )
	{
		try
		{
			$forwardedPort = $ssh->forwardPort( $dsn->host(), $dsn->port() ? : '3306' );
		}
		catch ( SSHForwardPortFailed $e )
		{
			throw new DbaseConnectionFailed( 'Could not forward port', 0, $e );
		}

		parent::__construct( new DatabaseConnectionInfo(
			"127.0.0.1:{$forwardedPort->localPort()}",
			$dsn->user(),
			$dsn->password(),
			$dsn->database()
		) );

		$this->forwardedPort = $forwardedPort;
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
	private $localPort;

	function __construct( Process $process, $localPort )
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

		$this->process   = $process;
		$this->localPort = $localPort;
	}

	function localPort() { return $this->localPort; }
}

