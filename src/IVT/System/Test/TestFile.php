<?php

namespace IVT\System\Test;

use IVT\System\_Internal\Local\LocalSystem;

class TestFile extends \PHPUnit_Framework_TestCase {
    function testStreamInto() {
        $local = LocalSystem::create();

        $len = 0;
        $callback = function ($bytes) use (&$len) {
            $len += strlen($bytes);
        };
        $file = $local->file('/etc/passwd');
        $file->streamInto($callback, 20);
        $this->assertEquals($file->size(), $len);
    }
}
