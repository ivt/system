<?php

namespace IVT\System;

interface SystemVisitor
{
    function visitLocalSystem(LocalSystem $system);

    function visitSSHSystem(SSHSystem $system);

    function visitLoggingSystem(LoggingSystem $system);

    function visitWrappedSystem(WrappedSystem $system);
}
