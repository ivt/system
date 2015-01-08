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
		$this->logger->log( 'init' );

		$this->callback = $callback;
	}

	protected function runImpl( $command, $stdIn, \Closure $stdOut, \Closure $stdErr )
	{
		$logger = $this->logger;
		$log    = function ( $x ) use ( $logger ) { $logger->writeLog( $x ); };
		$cmd    = new BinaryBuffer( new LinePrefixStream( '>>> ', '... ', $log ) );
		$in     = new BinaryBuffer( new LinePrefixStream( '--- ', '--- ', $log ) );
		$out    = new BinaryBuffer( new LinePrefixStream( '  ', '  ', $log ) );

		$cmd( self::removeSecrets( "$command\n" ) );
		unset( $cmd );

		$in( $stdIn );
		unset( $in );

		$process = parent::runImpl(
			$command,
			$stdIn,
			function ( $data ) use ( $out, $stdOut )
			{
				$out( $data );
				$stdOut( $data );
			},
			function ( $data ) use ( $out, $stdErr )
			{
				$out( $data );
				$stdErr( $data );
			}
		);
		unset( $out );
		gc_collect_cycles();

		return $process;
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
			$result = "$context: $result";

		$this->writeLog( "$result\n" );
	}

	/**
	 * @param array|bool|null|string|int|float $value
	 * @return string
	 * @throws Exception
	 */
	static function dump( $value )
	{
		if ( is_array( $value ) )
		{
			$result = array();
			foreach ( $value as $k => $v )
			{
				$s = self::dump( $v );
				if ( !is_int( $k ) )
					$s = self::dump( $k ) . ": $s";
				$result[ ] = $s;
			}
			$result = '[' . join( ', ', $result ) . ']';
			$result = self::trim( $result );
			return $result;
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
			if ( !\PCRE::match( '^[A-Za-z0-9_ ]+$', $value, 'D' ) )
			{
				$value = \PCRE::replace( '([^[:print:]]|\s+)+', $value, ' ' );
				$value = trim( $value );
				$value = "\"$value\"";
			}

			return self::trim( $value );
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

	private static function trim( $value )
	{
		if ( strlen( $value ) > 40 )
			return substr( $value, 0, 20 ) . '...' . substr( $value, -20 );
		else
			return $value;
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

	function fileType()
	{
		$type = parent::fileType();
		$this->log( 'file type', $type );

		return $type;
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

		if ( $offset === 0 && $maxLength === null )
			$input = 'read';
		else
			$input = array( 'read', 'offset' => $offset, 'length' => $maxLength );

		$this->log( $input, $result );

		return $result;
	}

	function write( $contents )
	{
		$this->log( array( "write", $contents ) );
		parent::write( $contents );
		return $this;
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
		$this->log( array( 'rename to', $to ) );
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
		$this->log( 'insert id', $result );
		return $result;
	}

	function affectedRows()
	{
		$result = parent::affectedRows();
		$this->log( 'affected rows', $result );
		return $result;
	}

	function selectDB( $dbName )
	{
		$this->log( array( 'select db', $dbName ) );
		parent::selectDB( $dbName );
	}

	function simpleSelect( $table, array $columns, array $where, \Closure $allWheres = null )
	{
		$rows = parent::simpleSelect( $table, $columns, $where, $allWheres );
		$this->log( array( 'simple select', 'from' => $table ), count( $rows ) . ' rows' );
		return $rows;
	}

	function update( $table, array $set = array(), array $where = array() )
	{
		$affected = parent::update( $table, $set, $where );
		$this->logger->log( array( 'update', $table ), "$affected rows affected" );
		return $affected;
	}

	function startTransaction()
	{
		return new LoggingTransaction( parent::startTransaction(), $this );
	}

	function log( $input, $output = null )
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
	/** @var LoggingDB */
	private $logger;

	function __construct( \DatabaseTransaction $txn, LoggingDB $logger )
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

