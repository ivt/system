<?php

namespace IVT\System;

use Symfony\Component\Process\Process as SymfonyProcess;

final class CommandFailedException extends SystemException
{
    private $result;

    function __construct( CommandResult $result )
    {
        parent::__construct( $result->toString(), $result->exitStatus() );

        $this->result = $result;
    }

    function result() { return $this->result; }
}

class CommandResult extends Process
{
    /**
     * @param self[] $results
     */
    static function assertSuccessAll( array $results )
    {
        Process::waitAll( $results );

        foreach ( $results as $self )
            $self->assertSuccess();
    }

    /** @var Process */
    private $process;
    private $stdOut;
    private $stdErr;
    private $command;
    private $stdIn;

    function stdErr() { return $this->stdErr; }

    function stdOut() { return $this->stdOut; }

    function command() { return $this->command; }

    function stdIn() { return $this->stdIn; }

    function toString()
    {
        $this->wait();
        $exitStatus  = $this->exitStatus();
        $exitMessage = array_get( SymfonyProcess::$exitCodes, $exitStatus, "Unknown error" );

        $result = <<<s
>>> command <<<
$this->command

>>> input <<<
$this->stdIn
>>> output <<<
$this->stdOut
>>> error <<<
$this->stdErr
>>> exit status <<<
$exitStatus $exitMessage
s;
        $result = System::removeSecrets( $result );
        $result = utf8_encode( $result ); // to convert raw binary data from command/stdin/stdout/stderr to valid UTF-8
        return $result;
    }

    /**
     * @param System $system
     * @param string $command
     * @param string $stdIn
     */
    function __construct( System $system, $command, $stdIn = '' )
    {
        $stdOut =& $this->stdOut;
        $stdErr =& $this->stdErr;

        $this->command = "$command";
        $this->stdIn   = "$stdIn";
        $this->process = $system->runImpl(
            $command,
            $stdIn,
            function ( $x ) use ( &$stdOut ) { $stdOut .= $x; },
            function ( $x ) use ( &$stdErr ) { $stdErr .= $x; }
        );
    }

    function assertSuccess()
    {
        if ( $this->failed() )
            throw new CommandFailedException( $this );

        return $this;
    }

    function isDone() { return $this->process->isDone(); }
    function wait() { return $this->process->wait(); }
}
