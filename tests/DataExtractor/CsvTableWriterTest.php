<?php

use SilverStripe\SsPak\DataExtractor\CsvTableWriter;

class CsvTableWriterTest extends PHPUnit_Framework_TestCase
{
	public function testCsvReading() {

		if (file_exists('/tmp/output.csv')) {
			unlink('/tmp/output.csv');
		}

		$csv = new CsvTableWriter('/tmp/output.csv');

		$csv->start(['Col1', 'Col2', 'Col3']);
		$csv->writeRecord([ 'Col1' => 'One', 'Col2' => 2, 'Col3' => 'Three' ]);
		$csv->writeRecord([ 'Col1' => 'Hello, Sam', 'Col2' => 5, 'Col3' => "Nice to meet you\nWhat is your name?" ]);
		$csv->finish();

		$csvContent = file_get_contents('/tmp/output.csv');
		unlink('/tmp/output.csv');

		$fixture = file_get_contents(__DIR__ . '/fixture/input.csv');

		$this->assertEquals($fixture, $csvContent);
	}

	public function testNoStartCall() {

		if (file_exists('/tmp/output.csv')) {
			unlink('/tmp/output.csv');
		}

		$csv = new CsvTableWriter('/tmp/output.csv');

		$csv->writeRecord([ 'Col1' => 'One', 'Col2' => 2, 'Col3' => 'Three' ]);
		$csv->writeRecord([ 'Col1' => 'Hello, Sam', 'Col2' => 5, 'Col3' => "Nice to meet you\nWhat is your name?" ]);
		$csv->finish();

		$csvContent = file_get_contents('/tmp/output.csv');
		unlink('/tmp/output.csv');

		$fixture = file_get_contents(__DIR__ . '/fixture/input.csv');

		$this->assertEquals($fixture, $csvContent);
	}

}
