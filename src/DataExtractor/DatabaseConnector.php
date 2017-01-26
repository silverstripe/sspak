<?php

namespace SilverStripe\SsPak\DataExtractor;

use DB;

/**
 * Connects to the SilverStripe Database object of a given SilverStripe project,
 * in order to bulk save/load data
 */
class DatabaseConnector
{

	private $basePath;
	private $isConnected = false;

	public function __construct($basePath) {
		$this->basePath = $basePath;
	}

	public function connect() {
		if ($this->isConnected) {
			return;
		}

		$this->isConnected = true;

		// Necessary for SilverStripe's _ss_environment.php loader to work
		$_SERVER['SCRIPT_FILENAME'] = $this->basePath . '/dummy.php';

		global $databaseConfig;

		// require composers autoloader
		if (file_exists($this->basePath . '/vendor/autoload.php')) {
			require_once $this->basePath . '/vendor/autoload.php';
		}

		if (file_exists($this->basePath . '/framework/core/Core.php')) {
			require_once($this->basePath . '/framework/core/Core.php');
		} elseif (file_exists($this->basePath . '/sapphire/core/Core.php')) {
			require_once($this->basePath . '/sapphire/core/Core.php');
		} else {
			throw new \LogicException("No framework/core/Core.php or sapphire/core/Core.php included in project.  Perhaps $this->basePath is not a SilverStripe project?");
		}

		// Connect to database
		require_once('model/DB.php');

		if ($databaseConfig) {
			DB::connect($databaseConfig);
		} else {
			throw new \LogicException("No \$databaseConfig found");
		}
	}

	public function getDatabase() {
		$this->connect();

		if(method_exists('DB', 'get_conn')) {
			return DB::get_conn();
		} else {
			return DB::getConn();
		}
	}

	/**
	 * Get a list of tables from the database
	 */
	public function getTables() {
		$this->connect();

		if(method_exists('DB', 'table_list')) {
			return DB::table_list();
		} else {
			return DB::tableList();
		}
	}

	/**
	 * Get a list of tables from the database
	 */
	public function getFieldsForTable($tableName) {
		$this->connect();

		if(method_exists('DB', 'field_list')) {
			return DB::field_list($tableName);
		} else {
			return DB::fieldList($tableName);
		}
	}

	/**
	 * Save the named table to the given table write
	 */
	public function saveTable($tableName, TableWriter $writer) {
		$query = $this->getDatabase()->query("SELECT * FROM \"$tableName\"");

		foreach ($query as $record) {
			$writer->writeRecord($record);
		}

		$writer->finish();
	}

	/**
	 * Save the named table to the given table write
	 */
	public function loadTable($tableName, TableReader $reader) {
		$this->getDatabase()->clearTable($tableName);

		$fields = $this->getFieldsForTable($tableName);

		foreach($reader as $record) {
			foreach ($record as $k => $v) {
				if (!isset($fields[$k])) {
					unset($record[$k]);
				}
			}
			// TODO: Batch records
			$manipulation = [
				$tableName => [
					'command' => 'insert',
					'fields' => $record,
				],
			];
			DB::manipulate($manipulation);
		}
	}
}
