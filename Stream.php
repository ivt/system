<?php

namespace IVT\System;

class LinePrefixStream
{
	private $buffer = '', $prefix, $delegate;

	/**
	 * @param string   $prefix
	 * @param \Closure $delegate
	 */
	function __construct( $prefix, \Closure $delegate )
	{
		$this->prefix   = $prefix;
		$this->delegate = $delegate;
	}

	function __destruct()
	{
		$this->flush();
	}

	function write( $data )
	{
		$this->buffer .= $data;
		$length = strlen( $this->buffer );

		if ( $length > 5000 || !mb_check_encoding( $this->buffer, 'UTF-8' ) )
		{
			$this->send( "$length bytes\n" );
			$this->buffer = '';
		}
		else
		{
			$lines        = explode( "\n", $this->buffer );
			$this->buffer = array_pop( $lines );

			foreach ( $lines as $line )
				$this->send( "$this->prefix$line\n" );
		}
	}

	function flush()
	{
		if ( $this->buffer !== '' )
		{
			$this->write( "\n" );
			$this->send( "^ no end of line\n" );
		}
	}

	private function send( $data )
	{
		$delegate = $this->delegate;
		$delegate( $data );
	}
}

