<?php

namespace SilverStripe\SsPak\DataExtractor;

interface TableWriter
{
	/**
	 * Start writing, declaring the columns that will be provided.
	 * Must be called before writeRecord()
	 * @param array $columns The columns to be provided as keys in writeRecord() calls
	 */
	public function start($columns);

	/**
	 * Write a single record.
	 * @param array $record A map of column => value data
	 */
	public function writeRecord($record);

	/**
	 * Finish writing.
	 * writeRecord() must not be called after this
	 */
	public function finish();
}
