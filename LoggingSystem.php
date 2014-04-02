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
		$logger->flush();
		if ( $exitStatus !== 0 )
			$logger->writeExitStatus( $exitStatus );
		$logger->flush();
		$this->writeLog( "\n" );

		return $exitStatus;
	}

	function cd( $dir )
	{
		$this->log( array( 'cd', $dir ) );

		parent::cd( $dir );
	}

	function pwd()
	{
		$result = parent::pwd();
		$this->log( 'pwd', $result );

		return $result;
	}

	function isPortOpen( $host, $port, $timeout )
	{
		$result = parent::isPortOpen( $host, $port, $timeout );
		$this->log( array( 'is port open', 'host' => $host, 'port' => $port, 'timeout' => "{$timeout}s" ), $result );

		return $result;
	}

	function writeLog( $data )
	{
		$log = $this->logHandler;
		$log( $data );
	}

	function wrap( System $system )
	{
		return new self( parent::wrap( $system ), $this->logHandler );
	}

	function file( $path )
	{
		return new LoggingFile( $this, $path, parent::file( $path ) );
	}

	function log( $input, $output = null, $context = null )
	{
		$result = self::dump( $input );

		if ( $output !== null )
			$result = "$result => " . self::dump( $output );

		if ( $context !== null )
			$result = self::dump( $context ) . ": $result";

		$this->writeLog( "$result\n" );
	}

	static function dump( $value )
	{
		if ( is_array( $value ) )
		{
			$delimit = function ( $value )
			{
				$result = array();
				foreach ( $value as $k => $v )
				{
					if ( is_int( $k ) )
						$result[ ] = LoggingSystem::dump( $v );
					else
						$result[ ] = LoggingSystem::dump( $k ) . ': ' . LoggingSystem::dump( $v );
				}

				return join( ', ', $result );
			};

			if ( count( $value ) > 6 )
			{
				$start = $delimit( array_slice( $value, 0, 3 ) );
				$end   = $delimit( array_slice( $value, -3 ) );

				return "[$start ... $end]";
			}
			else
			{
				return "[{$delimit( $value )}]";
			}
		}
		else if ( is_bool( $value ) )
		{
			return $value ? 'yes' : 'no';
		}
		else if ( is_null( $value ) )
		{
			return 'null';
		}
		else if ( is_string( $value ) )
		{
			$value = \PCRE::create( '([^[:print:]]|\s)+' )->replace( $value, ' ' )->result();
			$value = trim( $value );

			if ( strlen( $value ) > 60 )
				return substr( $value, 0, 30 ) . "..." . substr( $value, -30 );
			else
				return $value;
		}
		else if ( is_int( $value ) || is_float( $value ) )
		{
			return "$value";
		}
		else
		{
			throw new \Exception( "Invalid type: " . gettype( $value ) );
		}
	}
}

class LoggingFile extends WrappedFile
{
	private $system;

	function __construct( LoggingSystem $system, $path, File $file )
	{
		$this->system = $system;
		parent::__construct( $system, $path, $file );
	}

	function isFile()
	{
		$result = parent::isFile();
		$this->log( "is file", $result );

		return $result;
	}

	function scandir()
	{
		$result = parent::scandir();
		$this->log( "scandir", $result );

		return $result;
	}

	function isDir()
	{
		$result = parent::isDir();
		$this->log( "is dir", $result );

		return $result;
	}

	function mkdir( $mode = 0777, $recursive = false )
	{
		$this->log( array( "mkdir", 'mode' => decoct( $mode ), 'recursive' => $recursive ) );
		parent::mkdir( $mode, $recursive );
	}

	function isLink()
	{
		$result = parent::isLink();
		$this->log( "is link", $result );

		return $result;
	}

	function readlink()
	{
		$result = parent::readlink();
		$this->log( "read link", $result );

		return $result;
	}

	function exists()
	{
		$result = parent::exists();
		$this->log( "exists", $result );

		return $result;
	}

	function size()
	{
		$size = parent::size();
		$this->log( "size", "$size bytes" );

		return $size;
	}

	function unlink()
	{
		$this->log( "unlink" );
		parent::unlink();
	}

	function mtime()
	{
		$result = parent::mtime();
		$this->log( "mtime", $result );

		return $result;
	}

	function ctime()
	{
		$result = parent::ctime();
		$this->log( "ctime", $result );

		return $result;
	}

	function read( $offset = 0, $maxLength = PHP_INT_MAX )
	{
		$result = parent::read( $offset, $maxLength );
		$this->log( array( "read", 'offset' => $offset, 'length' => $maxLength ), $result );

		return $result;
	}

	function write( $contents )
	{
		$this->log( array( "write", $contents ) );
		parent::write( $contents );
	}

	function create( $contents )
	{
		$this->log( array( "create", $contents ) );
		parent::create( $contents );
	}

	function append( $contents )
	{
		$this->log( array( "append", $contents ) );
		parent::append( $contents );
	}

	function rmdir()
	{
		$this->log( "rmdir" );
		parent::rmdir();
	}

	private function log( $input, $output = null )
	{
		$this->system->log( $input, $output, $this->path() );
	}

	function chmod( $mode )
	{
		$this->log( array( 'chmod', decoct( $mode ) ) );
		parent::chmod( $mode );
	}
}
