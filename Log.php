<?php

namespace IVT\System;

class Log
{
	private $out, $err;

	function __construct( WriteStream $out, WriteStream $err )
	{
		$this->out = $out;
		$this->err = $err;
	}

	final function err( $str ) { $this->err->write( $str ); }

	final function out( $str ) { $this->out->write( $str ); }

	final function outStream() { return $this->out; }

	final function errStream() { return $this->err; }
}

