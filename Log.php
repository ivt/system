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
	private $out = '', $err = '';

	function writeOutput( $data ) { $this->out .= $data; }

	function writeError( $data ) { $this->err .= $data; }

	function stdErr() { return $this->err; }

	function stdOut() { return $this->out; }
}
