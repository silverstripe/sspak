<?php

define('PACKAGE_ROOT' , dirname(__DIR__).'/');
require_once(PACKAGE_ROOT . 'src/SSPak.php');

/**
 * Confirm that the compile binary executes
 */
class SmokeTest extends PHPUnit_Framework_TestCase
{
	/**
	 * Check that the help output of the binary matches what the internal function generates
	 */
	public function testHelpOutput() {
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
