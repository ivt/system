<?php

namespace IVT\System;

use IVT\Log;

class LoggingSystem extends WrappedSystem
{
	public $log;

	function __construct( System $system, Log $log )
	{
		parent::__construct( $system );

		$this->log = $log;
	}

	function log( $message )
	{
		$this->log->debug( "{$this->describe()}: $message" );
	}

	function runImpl( $command, $stdIn, \Closure $stdOut, \Closure $stdErr )
	{
		$self = $this;
		$log  = function ( $data ) use ( $self )
		{
			foreach ( lines( $data ) as $line )
				$self->log( $line );
		};
		$cmd  = new BinaryBuffer( new LinePrefixStream( '>>> ', '... ', $log ) );
		$in   = new BinaryBuffer( new LinePrefixStream( '--- ', '--- ', $log ) );
		$out  = new BinaryBuffer( new LinePrefixStream( '  ', '  ', $log ) );
		$err  = new BinaryBuffer( new LinePrefixStream( '! ', '! ', $log ) );

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
			function ( $data ) use ( $err, $stdErr )
			{
				$err( $data );
				$stdErr( $data );
			}
		);
		unset( $out );
		unset( $err );
		gc_collect_cycles();

		return $process;
	}

	function cd( $dir )
	{
		$this->log( "cd $dir" );
		parent::cd( $dir );
	}

	function pwd()
	{
		$result = parent::pwd();
		$this->log( "pwd => $result" );

		return $result;
	}

	function isPortOpen( $host, $port, $timeout )
	{
		$result = parent::isPortOpen( $host, $port, $timeout );
		$this->log( "is $host:$port open => " . yes_no( $result ) );

		return $result;
	}

	function wrap( System $system )
	{
		return new self( parent::wrap( $system ), $this->log );
	}

	function file( $path )
	{
		return new LoggingFile( $this, $path, parent::file( $path ), $this );
	}
}

class Logger
{
	/**
	 * Ellipsize the given string to the given length
	 * @param string $string
	 * @param int    $width
	 * @return string
	 */
	private static function ellipsize( $string, $width )
	{
		if ( strlen( $string ) <= $width )
			return $string;

		$ellipses = '...';

		$half  = max( 0, $width - strlen( $ellipses ) ) / 2;
		$left  = substr( $string, 0, ceil( $half ) );
		$right = substr( $string, -floor( $half ) );

		return $left . $ellipses . $right;
	}

	/**
	 * @param string[] $strings
	 * @return string
	 */
	static function summarizeArray( array $strings )
	{
		$result = '[' . join( ', ', $strings ) . ']';
		$result = self::ellipsize( $result, 40 );
		return $result;
	}

	/**
	 * @param string $string
	 * @return string
	 */
	static function summarize( $string )
	{
		$string = self::collapse( $string );
		$string = self::ellipsize( $string, 40 );
		return $string;
	}

	/**
	 * Collapses the given string into a single line
	 * @param string $string
	 * @return string
	 */
	static function collapse( $string )
	{
		return '"' . \PCRE::replace( '([^[:print:]]|\s+)+', trim( $string ), ' ' ) . '"';
	}
}

class LoggingFile extends WrappedFile
{
	private $log;

	function __construct( System $system, $path, File $file, LoggingSystem $log )
	{
		parent::__construct( $system, $path, $file );
		$this->log = $log;
	}

	function fileType()
	{
		$type = parent::fileType();
		$this->log( "file type => $type" );

		return $type;
	}

	function isFile()
	{
		$result = parent::isFile();
		$this->log( "is file => " . yes_no( $result ) );

		return $result;
	}

	function scanDir()
	{
		$result = parent::scanDir();
		$this->log( "scandir => " . Logger::summarizeArray( $result ) );

		return $result;
	}

	function isDir()
	{
		$result = parent::isDir();
		$this->log( "is dir => " . yes_no( $result ) );

		return $result;
	}

	function mkdir( $mode = 0777, $recursive = false )
	{
		$this->log( 'mkdir, mode ' . decoct( $mode ) . ', recursive: ' . yes_no( $recursive ) );
		parent::mkdir( $mode, $recursive );
	}

	function isLink()
	{
		$result = parent::isLink();
		$this->log( "is link => " . yes_no( $result ) );

		return $result;
	}

	function readlink()
	{
		$result = parent::readlink();
		$this->log( "read link => $result" );

		return $result;
	}

	function exists()
	{
		$result = parent::exists();
		$this->log( "exists => " . yes_no( $result ) );

		return $result;
	}

	function perms()
	{
		$result = parent::perms();
		$this->log( 'perms => ' . decoct( $result ) );
		return $result;
	}

	function size()
	{
		$size = parent::size();
		$this->log( "size => " . sb_file_size_conversion( $size ) );

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
		$this->log( "mtime => $result" );

		return $result;
	}

	function ctime()
	{
		$result = parent::ctime();
		$this->log( "ctime => $result" );

		return $result;
	}

	function read( $offset = 0, $maxLength = null )
	{
		$result = parent::read( $offset, $maxLength );

		$log = 'read';
		if ( $offset !== 0 )
			$log .= ", offset: $offset";
		if ( $maxLength !== null )
			$log .= ", length: $maxLength";

		$this->log( "$log => " . Logger::summarize( $result ) );

		return $result;
	}

	function write( $contents )
	{
		$this->log( "write " . Logger::summarize( $contents ) );
		parent::write( $contents );
		return $this;
	}

	function create( $contents )
	{
		$this->log( "create " . Logger::summarize( $contents ) );
		parent::create( $contents );
	}

	function append( $contents )
	{
		$this->log( "append " . Logger::summarize( $contents ) );
		parent::append( $contents );
	}

	function rmdir()
	{
		$this->log( "rmdir" );
		parent::rmdir();
	}

	function chmod( $mode )
	{
		$this->log( 'chmod ' . decoct( $mode ) );
		parent::chmod( $mode );
	}

	function realpath()
	{
		$result = parent::realpath();
		$this->log( "realpath => $result" );

		return $result;
	}

	protected function copyImpl( $dest )
	{
		$this->log( "copy to $dest" );
		parent::copyImpl( $dest );
	}

	function log( $line )
	{
		$this->log->log( "{$this->path()}: $line" );
	}

	protected function renameImpl( $to )
	{
		$this->log( "rename to $to" );
		parent::renameImpl( $to );
	}
}

class LoggingDB extends \Dbase_SQL_Driver_Delegate
{
	private $log;

	function __construct( \Dbase_SQL_Driver_Abstract $driver, LoggingSystem $log )
	{
		parent::__construct( $driver );
		$this->log = $log;
	}

	function query( $sql )
	{
		$result = parent::query( $sql );

		$rows = $result instanceof \Dbase_SQL_Query_Result_Select ? $result->numRows() . ' rows' : 'ok';
		$this->log( 'query ' . Logger::summarize( $sql ) . " => $rows" );

		return $result;
	}

	function insertId()
	{
		$result = parent::insertId();
		$this->log( "insert id => $result" );
		return $result;
	}

	function affectedRows()
	{
		$result = parent::affectedRows();
		$this->log( "affected rows => $result" );
		return $result;
	}

	function selectDB( $dbName )
	{
		$this->log( "select db: $dbName" );
		parent::selectDB( $dbName );
	}

	function simpleSelect( $table, array $columns, array $where, \Closure $allWheres = null )
	{
		$rows = parent::simpleSelect( $table, $columns, $where, $allWheres );

		$columns_ = Logger::summarizeArray( $columns );
		$where_   = Logger::summarizeArray( array_keys( $where ) );
		$this->log( "select $columns_ from $table where $where_" );

		return $rows;
	}

	function update( $table, array $set = array(), array $where = array(), $limit = null )
	{
		$affected = parent::update( $table, $set, $where, $limit );

		$columns_ = Logger::summarizeArray( array_keys( $set ) );
		$where_   = Logger::summarizeArray( array_keys( $where ) );
		$this->log( "update $table set $columns_ where $where_ => $affected rows changed" );

		return $affected;
	}

	function startTransaction()
	{
		return new LoggingTransaction( parent::startTransaction(), $this->log );
	}

	function log( $line )
	{
		$this->log->log( "{$this->connectionInfo()->summary()}: $line" );
	}
}

class LoggingTransaction extends \WrappedDatabaseTransaction
{
	/** @var Log */
	private $log;

	function __construct( \DatabaseTransaction $txn, LoggingSystem $log )
	{
		parent::__construct( $txn );
		$this->log = $log;
		$this->log( 'start' );
	}

	function rollback()
	{
		$this->log( 'rollback' );
		parent::rollback();
	}

	function commit()
	{
		$this->log( 'commit' );
		parent::commit();
	}

	private function log( $s )
	{
		$this->log->log( "{$this->name()}: $s" );
	}
}

