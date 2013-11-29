<?php

namespace IVT\System;

class WriteStream
{
	private $delegates;

	/**
	 * @param self[] $delegates
	 */
	function __construct( array $delegates = array() )
	{
		$this->delegates = $delegates;
	}

	/**
	 * @param string $data
	 *
	 * @return $this
	 */
	function write( $data )
	{
		foreach ( $this->delegates as $stream )
			$stream->write( $data );

		return $this;
	}
}

class StreamStream extends WriteStream
{
	private $resource;

	/**
	 * @param resource      $resource
	 * @param WriteStream[] $delegates
	 */
	function __construct( $resource, array $delegates = array() )
	{
		$this->resource = $resource;

		parent::__construct( $delegates );
	}

	function write( $data )
	{
		assertEqual( fwrite( $this->resource, $data ), strlen( $data ) );

		return parent::write( $data );
	}
}

class AccumulateStream extends WriteStream
{
	private $data = '';

	function data() { return $this->data; }
	
	function reset() { $this->data = ''; return $this; }

	function write( $data )
	{
		$this->data .= $data;

		return parent::write( $data );
	}
}

class LinePrefixStream extends WriteStream
{
	private $buffer = '', $prefix;

	/**
	 * @param string        $prefix
	 * @param WriteStream[] $streams
	 */
	function __construct( $prefix, array $streams )
	{
		$this->prefix = $prefix;

		parent::__construct( $streams );
	}

	function __destruct()
	{
		$this->flush();
	}

	function write( $data )
	{
		$lines        = explode( "\n", $this->buffer . $data );
		$this->buffer = array_pop( $lines );

		foreach ( $lines as $line )
			parent::write( "$this->prefix$line\n" );

		return $this;
	}

	function flush()
	{
		if ( $this->buffer !== '' )
		{
			$this->write( "\n" );
			parent::write( "^ no end of line\n" );
		}

		return $this;
	}
}

