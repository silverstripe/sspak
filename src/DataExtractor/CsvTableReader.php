<?php

namespace SilverStripe\SsPak\DataExtractor;

class CsvTableReader implements TableReader
{

	private $filename;
	private $handle;
	private $columns;

	public function __construct($filename) {
		$this->filename = $filename;
	}

	public function getColumns() {
		if (!$this->columns) {
			$this->initColumns();
		}
		return $this->columns;
	}

	public function getIterator() {
		$this->columns = null;
		$this->initColumns();

		while(($row = $this->getRow()) !== false) {
			yield $this->mapToColumns($row);
		}

		$this->close();
	}

	private function mapToColumns($row) {
		$record = [];
		foreach($row as $i => $value)
		{
			if(isset($this->columns[$i])) {
				$record[$this->columns[$i]] = $value;
			} else {
				throw new \LogicException("Row contains invalid column #$i\n" . var_export($row, true));
			}
		}
		return $record;
	}

	private function initColumns() {
		$this->open();
		$this->columns = $this->getRow();
	}

	private function getRow() {
		return fgetcsv($this->handle);
	}

	private function open() {
		if ($this->handle) {
			fclose($this->handle);
			$this->handle = null;
		}
		$this->handle = fopen($this->filename, 'r');
	}

	private function close() {
		if ($this->handle) {
			fclose($this->handle);
			$this->handle = null;
		}
	}
}
