<?php

namespace IVT\System;

interface CommandOutputHandler
{
	/**
	 * @param string $data
	 */
	function writeOutput( $data );

	/**
	 * @param string $data
	 */
	function writeError( $data );
}

class NullCommandOutputHandler implements CommandOutputHandler
{
	function writeOutput( $data ) { }

	function writeError( $data ) { }
}

class AccumulateOutputHandler implements CommandOutputHandler
{
	private $out = '', $err = '', $both = '';

	function writeOutput( $data )
	{
		$this->out .= $data;
		$this->both .= $data;
	}

	function writeError( $data )
	{
		$this->err .= $data;
		$this->both .= $data;
	}

	function stdErr() { return $this->err; }

	function stdOut() { return $this->out; }

	function stdBoth() { return $this->both; }
}

class DelegateOutputHandler implements CommandOutputHandler
{
	private $output;

	function __construct( CommandOutputHandler $output )
	{
		$this->output = $output;
	}

	function writeOutput( $data ) { $this->output->writeOutput( $data ); }

	function writeError( $data ) { $this->output->writeError( $data ); }
}
