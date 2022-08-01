<?php

namespace SilverStripe\SSPak\Tests\DataExtractor;

use PHPUnit\Framework\TestCase;
use SilverStripe\SSPak\DataExtractor\CsvTableReader;

class CsvTableReaderTest extends TestCase
{
    public function testCsvReading()
    {

        $csv = new CsvTableReader(__DIR__ . '/fixture/input.csv');
        $this->assertEquals(['Col1', 'Col2', 'Col3'], $csv->getColumns());

        $extractedData = [];
        foreach ($csv as $record) {
            $extractedData[] = $record;
        }

        $this->assertEquals(
            [
                [ 'Col1' => 'One', 'Col2' => 2, 'Col3' => 'Three' ],
                [ 'Col1' => 'Hello, Sam', 'Col2' => 5, 'Col3' => "Nice to meet you\nWhat is your name?" ]
            ],
            $extractedData
        );
    }
}
