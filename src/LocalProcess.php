<?php

namespace IVT\System;

use Symfony\Component\Process\Process as SymfonyProcess;

class LocalProcess extends Process
{
    /**
     * @var SymfonyProcess[]
     */
    public static $processes = array();

    /**
     * When PHP shuts down due to a fatal error, it doesn't call the object destructors. Consequently, the processes
     * run through this class are left hanging as children, and PHP waits for them to finish. If they are processes
     * which run indefinitely until killed, PHP itself ends up stuck forever.
     *
     * Shutdown handlers *are* run in the case of a fatal error, even if destructors aren't. Therefore, bind
     * a shutdown handler which will kill any processes left running.
     */
    private static function bindShutdownHandler()
    {
        static $bound = false;
        if ($bound)
            return;
        $bound = true;
        register_shutdown_function(function () {
            foreach (LocalProcess::$processes as $k => $v) {
                $v->stop();
                unset(LocalProcess::$processes[$k]);
            }
        });
    }

    private $process;

    /**
     * @param string $command
     * @param string $stdIn
     * @param \Closure $stdOut
     * @param \Closure $stdErr
     */
    function __construct($command, $stdIn, \Closure $stdOut, \Closure $stdErr)
    {
        self::bindShutdownHandler();

        $this->process = new SymfonyProcess($command, null, null, $stdIn, null);
        $this->process->start(function ($type, $data) use ($stdOut, $stdErr) {
            if ($type === SymfonyProcess::OUT)
                $stdOut($data);

            if ($type === SymfonyProcess::ERR)
                $stdErr($data);
        });

        self::$processes[spl_object_hash($this->process)] = $this->process;
    }

    function __destruct()
    {
        unset(self::$processes[spl_object_hash($this->process)]);
    }

    function isDone()
    {
        return $this->process->isTerminated();
    }

    function wait()
    {
        return $this->process->wait();
    }
}
