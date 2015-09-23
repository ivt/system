<?php

namespace IVT\System\Test;

use IVT\System\_Internal\Local\LocalSystem;

class TestLocal extends \PHPUnit_Framework_TestCase {
    function testProcess() {
        $local = LocalSystem::create();

        $result = $local->runCommand("cat /etc/passwd | wc -l");
        $this->assertGreaterThan(0, $result->stdOut());

        $result = $local->runCommand("true");
        $this->assertTrue($result->succeeded());

        $result = $local->runCommand("false");
        $this->assertTrue($result->failed());
    }
}
