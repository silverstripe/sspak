<?php

namespace SilverStripe\SSPak\Tests;

use PHPUnit\Framework\TestCase;
use SilverStripe\SSPak\SSPak;

/**
 * Confirm that the compile binary executes
 */
class SmokeTest extends TestCase
{
    /**
     * Check that the help output of the binary matches what the internal function generates
     */
    public function testHelpOutput()
    {
        $ssPak = new SSPak(null);

        // Internal call
        ob_start();
        $ssPak->help(array());
        $helpText = ob_get_contents();
        ob_end_clean();

        // Call to binary
        $this->assertEquals($helpText, `build/sspak.phar help &> /dev/stdout`);
    }
}
