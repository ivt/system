<?php

namespace IVT\System;

class LoggingSystem extends WrappedSystem
{
	private $logHandler;

	function __construct( System $system, \Closure $logHandler )
	{
		parent::__construct( $system );

		$this->logHandler = $logHandler;
	}

	protected function runImpl( $command, $input, CommandOutputHandler $output )
	{
		$logger = new CommandOutput( $output, $this->logHandler );
		$logger->writeCommand( "$command\n" );
		$logger->writeInput( $input );
		$logger->flush();
		$exitStatus = parent::runImpl( $command, $input, $logger );
		if ( $exitStatus !== 0 )
			$logger->writeExitStatus( $exitStatus );
		$logger->flush();
		$this->writeLog( "\n" );

		return $exitStatus;
	}

	function setWorkingDirectory( $dir )
	{
		$this->writeLog( "chdir: $dir\n\n" );

		parent::setWorkingDirectory( $dir );
	}

	function writeLog( $data )
	{
		$log = $this->logHandler;
		$log( $data );
	}

	function wrap( System $sytem )
	{
		return new self( parent::wrap( $sytem ), $this->logHandler );
	}
}
