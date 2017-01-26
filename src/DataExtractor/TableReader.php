<?php

namespace SilverStripe\SsPak\DataExtractor;

interface TableReader extends \IteratorAggregate
{
	/**
	 * Return an iterator that returns each record of the table reader as a map.
	 * @return Iterator
	 */
	public function getIterator();

	/**
	 * Return the names of the the columns in this table
	 * @return array The column names
	 */
	public function getColumns();
}
