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

