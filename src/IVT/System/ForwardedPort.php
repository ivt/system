<?php

namespace IVT\System;

abstract class ForwardedPort {
    /**
     * @return int
     */
    abstract function localPort();

    /**
     * @return string
     */
    abstract function localHost();
}
