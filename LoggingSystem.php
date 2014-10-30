<?php

namespace IVT\System;

use IVT\Exception;

class LoggingSystem extends WrappedSystem
{
	private $callback;
	private $logger;

	function __construct( System $system, \Closure $callback )
	{
		parent::__construct( $system );

		$self = $this;

		$this->logger = new Logger( function ( $x ) use ( $self, $callback )
		{
			$callback( "{$self->describe()}: $x" );
		} );

		$this->callback = $callback;
	}

	protected function runImpl( $command, $stdIn, \Closure $stdOut, \Closure $stdErr )
	{
		$logger = $this->logger;
		$log    = function ( $x ) use ( $logger ) { $logger->writeLog( $x ); };
		$cmd    = new LinePrefixStream( '>>> ', '... ', $log );
		$in     = new LinePrefixStream( '--- ', '--- ', $log );
		$out    = new LinePrefixStream( '  ', '  ', $log );

		$cmd->write( self::removeGitHubCredentials( "$command\n" ) );
		$cmd->flush();

		$in->write( $stdIn );
		$in->flush();

		$exitStatus = parent::runImpl(
			$command,
			$stdIn,
			function ( $data ) use ( $out, $stdOut )
			{
				$out->write( $data );
				$stdOut( $data );
			},
			function ( $data ) use ( $out, $stdErr )
			{
				$out->write( $data );
				$stdErr( $data );
			}
		);
		$out->flush();

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
		return new self( parent::wrap( $system ), $this->callback );
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
	/**
	 * @param mixed  $input
	 * @param mixed  $output
	 * @param string $context
	 * @throws Exception
	 */
	function log( $input, $output = null, $context = null )
	{
		$result = self::dump( $input );

		if ( $output !== null )
			$result = "$result => " . self::dump( $output );

		if ( $context !== null )
			$result = self::dump( $context ) .  ": $result";

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

	/**
	 * @param array|bool|null|string|int|float $value
	 * @return string
	 * @throws Exception
	 */
	private static function dump( $value )
	{
		if ( is_array( $value ) )
		{
			if ( count( $value ) > 4 )
			{
				$start = self::delimit( array_slice( $value, 0, 2, true ) );
				$end   = self::delimit( array_slice( $value, -2, null, true ) );

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
			$maxLength  = 40;
			$isReserved = in_array( $value, array( 'null', 'yes', 'no' ) );
			$isWords    = \PCRE::create( '^[A-Za-z_][A-Za-z0-9_ ]+$' )->matches( $value );
			$isShort    = strlen( $value ) < $maxLength;

			if ( !$isReserved && $isWords && $isShort )
				return $value;

			$value = \PCRE::create( '([^[:print:]]|\s+)+' )->replace( $value, ' ' )->result();
			$value = trim( $value );

			if ( strlen( $value ) > $maxLength )
				$value = substr( $value, 0, $maxLength / 2 ) . "..." . substr( $value, -$maxLength / 2 );

			return "\"$value\"";
		}
		else if ( is_int( $value ) || is_float( $value ) )
		{
			return "$value";
		}
		else
		{
			throw new Exception( "Invalid type: " . gettype( $value ) );
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

	function scanDir()
	{
		$result = parent::scanDir();
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

	function perms()
	{
		$result = parent::perms();
		$this->log( 'perms', decoct( $result ) );
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

	protected function copyImpl( $dest )
	{
		$this->log( array( 'copy to', $dest ) );
		parent::copyImpl( $dest );
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
		$result = parent::query( $sql );

		if ( $result instanceof \Dbase_SQL_Query_Result_Select )
			$result1 = "{$result->num_rows()} rows";
		else
			$result1 = "no result set";

		$this->log( array( 'query', $sql ), $result1 );

		return $result;
	}

	function insertId()
	{
		$result = parent::insertId();
		$this->log( 'get insert id', $result );
		return $result;
	}

	function affectedRows()
	{
		$result = parent::affectedRows();
		$this->log( 'get affected rows', $result );
		return $result;
	}

	function selectDB( $dbName )
	{
		$this->log( array( 'select db', $dbName ) );
		parent::selectDB( $dbName );
	}

	function simpleSelect( $table, array $columns, array $where, \Closure $allWheres = null )
	{
		$this->log( array( 'simple select', $table, $columns, $where ) );
		return parent::simpleSelect( $table, $columns, $where, $allWheres );
	}

	function startTransaction()
	{
		return new LoggingTransaction( parent::startTransaction(), $this->logger );
	}

	private function log( $input, $output = null )
	{
		$dsn  = $this->connectionInfo();
		$user = $dsn->user();
		$host = $dsn->host();
		$db   = $dsn->database();

		$this->logger->log( $input, $output, "$user@$host/$db" );
	}
}

class LoggingTransaction extends \WrappedDatabaseTransaction
{
	/** @var Logger */
	private $logger;

	function __construct( \DatabaseTransaction $txn, Logger $logger )
	{
		parent::__construct( $txn );
		$this->logger = $logger;
		$this->logger->log( array( 'start transaction', $this->name() ) );
	}

	function rollback()
	{
		$this->logger->log( array( 'rollback', $this->name() ) );
		parent::rollback();
	}

	function commit()
	{
		$this->logger->log( array( 'commit', $this->name() ) );
		parent::commit();
	}
}

