<?php

namespace IVT\System;

class LoggingSystem extends WrappedSystem
{
	private $logger;

	function __construct( System $system, Logger $logger )
	{
		parent::__construct( $system );

		$logger->log( array( 'new', $this->describe() ) );

		$this->logger = $logger;
	}

	protected function runImpl( $command, $input, CommandOutputHandler $output )
	{
		$logger1 = $this->logger;
		$logger  = new CommandOutput( $output, function ( $x ) use ( $logger1 ) { $logger1->writeLog( $x ); } );
		$logger->writeCommand( "$command\n" );
		$logger->writeInput( $input );
		$logger->flush();
		$exitStatus = parent::runImpl( $command, $input, $logger );
		$logger->flush();
		$logger->flush();

		return $exitStatus;
	}

	function cd( $dir )
	{
		$this->logger->log( array( 'cd', $dir ) );

		parent::cd( $dir );
	}

	function pwd()
	{
		$result = parent::pwd();
		$this->logger->log( 'pwd', $result );

		return $result;
	}

	function isPortOpen( $host, $port, $timeout )
	{
		$result = parent::isPortOpen( $host, $port, $timeout );
		$this->logger->log( array( 'is port open', 'host' => $host, 'port' => $port, 'timeout' => "{$timeout}s" ),
		                    $result );

		return $result;
	}

	function wrap( System $system )
	{
		return new self( parent::wrap( $system ), $this->logger );
	}

	function file( $path )
	{
		return new LoggingFile( $this, $path, parent::file( $path ), $this->logger );
	}

	function connectDB( \DatabaseConnectionInfo $dsn )
	{
		$this->logger->log( array( 'connect db', $dsn->__toString() ) );

		return new LoggingDB( parent::connectDB( $dsn ), $this->logger );
	}
}

class Logger
{
	function log( $input, $output = null, $context = null )
	{
		$result = self::dump( $input );

		if ( $output !== null )
			$result = "$result => " . self::dump( $output );

		if ( $context !== null )
			$result = self::dump( $context ) . ": $result";

		$this->writeLog( "$result\n" );
	}

	private static function delimit( array $value )
	{
		$result = array();
		foreach ( $value as $k => $v )
		{
			if ( is_int( $k ) )
				$result[ ] = self::dump( $v );
			else
				$result[ ] = self::dump( $k ) . ': ' . self::dump( $v );
		}

		return join( ', ', $result );
	}

	private static function dump( $value )
	{
		if ( is_array( $value ) )
		{
			if ( count( $value ) > 6 )
			{
				$start = self::delimit( array_slice( $value, 0, 3 ) );
				$end   = self::delimit( array_slice( $value, -3 ) );

				return "[$start ... $end]";
			}
			else
			{
				return "[" . self::delimit( $value ) . "]";
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

	private $callback;

	function __construct( \Closure $callback )
	{
		$this->callback = $callback;
	}

	function writeLog( $string )
	{
		$callback = $this->callback;
		$callback( $string );
	}
}

class LoggingFile extends WrappedFile
{
	private $logger;

	function __construct( System $system, $path, File $file, Logger $logger )
	{
		parent::__construct( $system, $path, $file );
		$this->logger = $logger;
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

	function read( $offset = 0, $maxLength = null )
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

	function chmod( $mode )
	{
		$this->log( array( 'chmod', decoct( $mode ) ) );
		parent::chmod( $mode );
	}

	function realpath()
	{
		$result = parent::realpath();
		$this->log( 'realpath', $result );

		return $result;
	}

	function log( $input, $output = null )
	{
		$this->logger->log( $input, $output, $this->path() );
	}

	protected function renameImpl( $to )
	{
		$this->logger->log( array( 'rename', 'from' => $this->path(), 'to' => $to ) );
		parent::renameImpl( $to );
	}
}

class LoggingDB extends \Dbase_SQL_Driver_Delegate
{
	private $logger;

	function __construct( \Dbase_SQL_Driver_Abstract $driver, Logger $logger )
	{
		parent::__construct( $driver );
		$this->logger = $logger;
	}

	function query( $sql )
	{
		$this->logger->log( array( 'query start', $sql ) );

		$result = parent::query( $sql );

		if ( $result instanceof \Dbase_SQL_Query_Result )
			$result1 = "{$result->num_rows()} rows";
		else
			$result1 = "no result set";

		$this->logger->log( array( 'query end', $result1 ) );

		return $result;
	}

	function insertId()
	{
		$result = parent::insertId();
		$this->logger->log( 'get insert id', $result );

		return $result;
	}

	function affectedRows()
	{
		$result = parent::affectedRows();
		$this->logger->log( 'get affected rows', $result );

		return $result;
	}
}

