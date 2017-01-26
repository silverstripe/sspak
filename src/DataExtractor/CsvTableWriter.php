<?php

namespace SilverStripe\SsPak\DataExtractor;

class CsvTableWriter implements TableWriter
{

	private $filename;
	private $handle;
	private $columns;

	public function __construct($filename) {
		$this->filename = $filename;
	}

	public function start($columns) {
		$this->open();
		$this->putRow($columns);
		$this->columns = $columns;
	}

	public function finish() {
		$this->close();
	}

	public function writeRecord($record) {
		if (!$this->columns) {
			$this->start(array_keys($record));
		}

		$this->putRow($this->mapFromColumns($record));
	}

	private function mapFromColumns($record) {
		$row = [];
		foreach($this->columns as $i => $column)
		{
			$row[$i] = isset($record[$column]) ? $record[$column] : null;
		}
		return $row;
	}

	private function putRow($row) {
		return fputcsv($this->handle, $row);
	}

	private function open() {
		if ($this->handle) {
			fclose($this->handle);
			$this->handle = null;
		}
		$this->handle = fopen($this->filename, 'w');
		if (!$this->handle) {
			throw new \LogicException("Can't open $this->filename for writing.");
		}
	}

	private function close() {
		if ($this->handle) {
			fclose($this->handle);
			$this->handle = null;
		}
	}
}
