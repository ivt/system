<?php

namespace IVT\System;

final class CommandFailedException extends Exception
{
    private $result;

    function __construct(CommandResult $result)
    {
        parent::__construct($result->toString(), $result->exitStatus());

        $this->result = $result;
    }

    function result()
    {
        return $this->result;
    }
}

