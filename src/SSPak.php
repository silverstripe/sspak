<?php

use SilverStripe\SsPak\DataExtractor\DatabaseConnector;
use SilverStripe\SsPak\DataExtractor\CsvTableWriter;
use SilverStripe\SsPak\DataExtractor\CsvTableReader;

/**
 * SSPak handler
 */
class SSPak {
	protected $executor;

	/**
	 * Create a new handler
	 * @param Executor $executor The Executor object to handle command execution
	 */
	public function __construct($executor) {
		$this->executor = $executor;
	}

	public function getActions() {
		return array(
			"help" => array(
				"description" => "Show this help message.",
				"method" => "help",
			),
			"save" => array(
				"description" => "Save an .sspak file from a SilverStripe site.",
				"unnamedArgs" => array("webroot", "sspak file"),
				"namedArgs" => array("identity"),
				"method" => "save",
			),
			"load" => array(
				"description" => "Load an .sspak file into a SilverStripe site. Does not backup - be careful!",
				"unnamedArgs" => array("sspak file", "[webroot]"),
				"namedArgs" => array("identity"),
				"namedFlags" => array("drop-db"),
				"method" => "load",
			),
			"saveexisting" => array(
				"description" => "Create an .sspak file from database SQL dump and/or assets. Does not require a SilverStripe site.",
				"unnamedArgs" => array("sspak file"),
				"namedArgs" => array("db", "assets"),
				"method" => "saveexisting"
			),
			"extract" => array(
				"description" => "Extract an .sspak file into the current working directory. Does not require a SilverStripe site.",
				"unnamedArgs" => array("sspak file", "destination path"),
				"method" => "extract"
			),
			"listtables" => array(
				"description" => "List tables in the database",
				"unnamedArgs" => array("webroot"),
				"method" => "listTables"
			),

			"savecsv" => array(
				"description" => "Save tables in the database to a collection of CSV files",
				"unnamedArgs" => array("webroot", "output-path"),
				"method" => "saveCsv"
			),

			"loadcsv" => array(
				"description" => "Load tables from collection of CSV files to a webroot",
				"unnamedArgs" => array("input-path", "webroot"),
				"method" => "loadCsv"
			),
			/*

			"install" => array(
				"description" => "Install a .sspak file into a new environment.",
				"unnamedArgs" => array("sspak file", "new webroot"),
				"method" => "install",
			),
			"bundle" => array(
				"description" => "Bundle a .sspak file into a self-extracting executable .sspak.phar installer.",
				"unnamedArgs" => array("sspak file", "executable"),
				"method" => "bundle",
			),
			"transfer" => array(
				"description" => "Transfer db & assets from one site to another (not implemented yet).",
				"unnamedArgs" => array("src webroot", "dest webroot"),
				"method" => "transfer",
			),
			*/
		);
	}

	public function help($args) {
		echo "SSPak: manage SilverStripe .sspak archives.\n\nUsage:\n";
		foreach($this->getActions() as $action => $info) {
			echo "sspak $action";
			if(!empty($info['unnamedArgs'])) {
				foreach($info['unnamedArgs'] as $arg) echo " ($arg)";
			}
			if(!empty($info['namedFlags'])) {
				foreach($info['namedFlags'] as $arg) echo " (--$arg)";
			}
			if(!empty($info['namedArgs'])) {
				foreach($info['namedArgs'] as $arg) echo " --$arg=\"$arg value\"";
			}
			echo "\n  {$info['description']}\n\n";
		}
	}

	/**
	 * Save an existing database and/or assets into an .sspak.phar file.
	 * Does the same as {@link save()} but doesn't require an existing site.
	 */
	public function saveexisting($args) {
		$executor = $this->executor;

		$args->requireUnnamed(array('sspak file'));
		$unnamedArgs = $args->getUnnamedArgs();
		$namedArgs = $args->getNamedArgs();

		$sspak = new SSPakFile($unnamedArgs[0], $executor);

		// Look up which parts of the sspak are going to be saved
		$pakParts = $args->pakParts();

		$filesystem = new FilesystemEntity(null, $executor);

		if($pakParts['db']) {
			$dbPath = escapeshellarg($namedArgs['db']);
			$process = $filesystem->createProcess("cat $dbPath | gzip -c");
			$sspak->writeFileFromProcess('database.sql.gz', $process);
		}

		if($pakParts['assets']) {
			$assetsParentArg = escapeshellarg(dirname($namedArgs['assets']));
			$assetsBaseArg = escapeshellarg(basename($namedArgs['assets']));
			$process = $filesystem->createProcess("cd $assetsParentArg && tar cfh - $assetsBaseArg | gzip -c");
			$sspak->writeFileFromProcess('assets.tar.gz', $process);
		}
	}

	/**
	 * Extracts an existing database and/or assets from a sspak into the given directory,
	 * defaulting the current working directory if the destination is not given.
	 */
	public function extract($args) {
		$executor = $this->executor;

		$args->requireUnnamed(array('source sspak file'));
		$unnamedArgs = $args->getUnnamedArgs();
		$file = $unnamedArgs[0];
		$dest = !empty($unnamedArgs[1]) ? $unnamedArgs[1] : getcwd();

		// Phar and PharData use "ustar" format for tar archives (http://php.net/manual/pl/phar.fileformat.tar.php).
		// Ustar does not support files larger than 8 GB.
		// If the sspak has been created through tar and gz directly, it will probably be in POSIX, PAX or GNU formats,
		// which do support >8 GB files. Such archive cannot be accessed by Phar/PharData, and needs to be handled
		// manually - it will just spew checksum errors where PHP expects to see ustar headers, but finds garbage
		// from other formats.
		// There is no cross-platform way of checking the assets.tar.gz size without unpacking, so we assume the size
		// of database is negligible which lets us approximate the size of assets.
		if (filesize($file) > 8*1024*1024*1024) {
			$msg = <<<EOM

ERROR: SSPak is unable to extract archives over 8 GB.

Packed asset or database sizes over 8 GB are not supported due to PHP Phar library limitations.
You can still access your data directly by using the tar utility:

	tar xzf "%s"

This tool is sorry for the inconvenience and stands aside in disgrace.
See http://silverstripe.github.io/sspak/, "Manual access" for more information.

EOM;
			printf($msg, $file);
			die(1);
		}

		$sspak = new SSPakFile($file, $executor);

		// Validation
		if(!$sspak->exists()) throw new Exception("File '$file' doesn't exist.");

		$phar = $sspak->getPhar();
		$phar->extractTo($dest);
	}

	public function listTables($args) {
		$args->requireUnnamed(array('webroot'));
		$unnamedArgs = $args->getUnnamedArgs();
		$webroot = $unnamedArgs[0];

		$db = new DatabaseConnector($webroot);

		print_r($db->getTables());
	}

	public function saveCsv($args) {
		$args->requireUnnamed(array('webroot', 'path'));
		$unnamedArgs = $args->getUnnamedArgs();
		$webroot = $unnamedArgs[0];
		$destPath = $unnamedArgs[1];

		if (!file_exists($destPath)) {
			mkdir($destPath) || die("Can't create $destPath");
		}
		if (!is_dir($destPath)) {
			die("$destPath isn't a directory");
		}

		$db = new DatabaseConnector($webroot);

		foreach($db->getTables() as $table) {
			$filename = $destPath . '/' . $table . '.csv';
			echo $filename . "...\n";
			touch($filename);
			$writer = new CsvTableWriter($filename);
			$db->saveTable($table, $writer);
		}
		echo "Done!";
	}

	public function loadCsv($args) {
		$args->requireUnnamed(array('input-path', 'webroot'));
		$unnamedArgs = $args->getUnnamedArgs();

		$srcPath = $unnamedArgs[0];
		$webroot = $unnamedArgs[1];

		if (!is_dir($srcPath)) {
			die("$srcPath isn't a directory");
		}

		$db = new DatabaseConnector($webroot);

		foreach($db->getTables() as $table) {
			$filename = $srcPath . '/' . $table . '.csv';
			if(file_exists($filename)) {
				echo $filename . "...\n";
				$reader = new CsvTableReader($filename);
				$db->loadTable($table, $reader);
			} else {
				echo "$filename doesn't exist; skipping.\n";
			}
		}
		echo "Done!";
	}
	/**
	 * Save a .sspak.phar file
	 */
	public function save($args) {
		$executor = $this->executor;

		$args->requireUnnamed(array('source webroot', 'dest sspak file'));

		$unnamedArgs = $args->getUnnamedArgs();
		$namedArgs = $args->getNamedArgs();

		$webroot = new Webroot($unnamedArgs[0], $executor);
		$file = $unnamedArgs[1];
		if(file_exists($file)) throw new Exception( "File '$file' already exists.");

		$sspak = new SSPakFile($file, $executor);

		if(!empty($namedArgs['identity'])) {
			// SSH private key
			$webroot->setSSHItentityFile($namedArgs['identity']);
		}
		if(!empty($namedArgs['from-sudo'])) $webroot->setSudo($namedArgs['from-sudo']);
		else if(!empty($namedArgs['sudo'])) $webroot->setSudo($namedArgs['sudo']);

		// Look up which parts of the sspak are going to be saved
		$pakParts = $args->pakParts();

		// Get the environment details
		$details = $webroot->sniff();

		// Create a build folder for the sspak file
		$buildFolder = sprintf("%s/sspak-%d", sys_get_temp_dir(), rand(100000,999999));
		$webroot->exec(array('mkdir', $buildFolder));

		$dbFile = "$buildFolder/database.sql.gz";
		$assetsFile = "$buildFolder/assets.tar.gz";
		$gitRemoteFile = "$buildFolder/git-remote";

		// Files to include in the .sspak.phar file
		$fileList = array();

		// Save DB
		if($pakParts['db']) {
			// Check the database type
			$dbFunction = 'getdb_'.$details['db_type'];
			if(!method_exists($this,$dbFunction)) {
				throw new Exception("Can't process database type '" . $details['db_type'] . "'");
			}
			$this->$dbFunction($webroot, $details, $sspak, basename($dbFile));
		}

		// Save Assets
		if($pakParts['assets']) {
			$this->getassets($webroot, $details['assets_path'], $sspak, basename($assetsFile));
		}

		// Save git-remote
		if($pakParts['git-remote']) {
			$this->getgitremote($webroot, $sspak, basename($gitRemoteFile));
		}

		// Remove the build folder
		$webroot->unlink($buildFolder);
	}

	public function getdb_MySQLPDODatabase($webroot, $conf, $sspak, $filename) {
		return $this->getdb_MySQLDatabase($webroot, $conf, $sspak, $filename);
	}

	public function getdb_MySQLDatabase($webroot, $conf, $sspak, $filename) {
		$usernameArg = escapeshellarg("--user=".$conf['db_username']);
		$passwordArg = escapeshellarg("--password=".$conf['db_password']);
		$databaseArg = escapeshellarg($conf['db_database']);

		$hostArg = '';
		$portArg = '';
		if (!empty($conf['db_server']) && $conf['db_server'] != 'localhost') {
			if (strpos($conf['db_server'], ':')!==false) {
				// Handle "server:port" format.
				$server = explode(':', $conf['db_server'], 2);
				$hostArg = escapeshellarg("--host=".$server[0]);
				$portArg = escapeshellarg("--port=".$server[1]);
			} else {
				$hostArg = escapeshellarg("--host=".$conf['db_server']);
			}
		}

		$filenameArg = escapeshellarg($filename);

		$process = $webroot->createProcess("mysqldump --no-tablespaces --skip-opt --add-drop-table --extended-insert --create-options --quick  --set-charset --default-character-set=utf8 $usernameArg $passwordArg $hostArg $portArg $databaseArg | gzip -c");
		$sspak->writeFileFromProcess($filename, $process);
		return true;
	}

	public function getdb_PostgreSQLDatabase($webroot, $conf, $sspak, $filename) {
		$usernameArg = escapeshellarg("--username=".$conf['db_username']);
		$passwordArg = "PGPASSWORD=".escapeshellarg($conf['db_password']);
		$databaseArg = escapeshellarg($conf['db_database']);
		$hostArg = escapeshellarg("--host=".$conf['db_server']);
		$filenameArg = escapeshellarg($filename);

		$process = $webroot->createProcess("$passwordArg pg_dump --clean --no-owner --no-tablespaces $usernameArg $hostArg $databaseArg | gzip -c");
		$sspak->writeFileFromProcess($filename, $process);
		return true;
	}

	public function getassets($webroot, $assetsPath, $sspak, $filename) {
		$assetsParentArg = escapeshellarg(dirname($assetsPath));
		$assetsBaseArg = escapeshellarg(basename($assetsPath));

		$process = $webroot->createProcess("cd $assetsParentArg && tar cfh - $assetsBaseArg | gzip -c");
		$sspak->writeFileFromProcess($filename, $process);
	}

	public function getgitremote($webroot, $sspak, $gitRemoteFile) {
		// Only do anything if we're copying from a git checkout
		$gitRepo = $webroot->getPath() .'/.git';
		if($webroot->exists($gitRepo)) {
			// Identify current branch
			$output = $webroot->exec(array('git', '--git-dir='.$gitRepo, 'branch'));
			if(preg_match("/\* ([^ \n]*)/", $output['output'], $matches) && strpos("(no branch)", $matches[1])===false) {
				// If there is a current branch, use that branch's remove
				$currentBranch = trim($matches[1]);
				$output = $webroot->exec(array('git', '--git-dir='.$gitRepo, 'config','--get',"branch.$currentBranch.remote"));
				$remoteName = trim($output['output']);
				if(!$remoteName) $remoteName = 'origin';

			// Default to origin
			} else {
				$currentBranch = null;
				$remoteName = 'origin';
			}

			// Determine the URL of that remote
			$output = $webroot->exec(array('git', '--git-dir='.$gitRepo, 'config','--get',"remote.$remoteName.url"));
			$remoteURL = trim($output['output']);

			// Determine the current SHA
			$output = $webroot->exec(array('git', '--git-dir='.$gitRepo, 'log','-1','--format=%H'));
			$sha = trim($output['output']);

			$content = "remote = $remoteURL\nbranch = $currentBranch\nsha = $sha\n";

			$sspak->writeFile($gitRemoteFile, $content);

			return true;
		}
		return false;
	}

	/**
	 * Load an .sspak into an environment.
	 * Does not backup - be careful! */
	public function load($args) {
		$executor = $this->executor;

		$args->requireUnnamed(array('source sspak file'));

		// Set-up
		$file = $args->unnamed(0);
		$sspak = new SSPakFile($file, $executor);
		$webroot = new Webroot(($args->unnamed(1) ?: '.'), $executor);
		$webroot->setSudo($args->sudo('to'));
		$pakParts = $args->pakParts();

		$namedArgs = $args->getNamedArgs();
		if(!empty($namedArgs['identity'])) {
			// SSH private key
			$webroot->setSSHItentityFile($namedArgs['identity']);
		}

		// Validation
		if(!$sspak->exists()) throw new Exception( "File '$file' doesn't exist.");

		// Push database, if necessary
		$namedArgs = $args->getNamedArgs();
		if($pakParts['db'] && $sspak->contains('database.sql.gz')) {
			$webroot->putdb($sspak, isset($namedArgs['drop-db']));
		}

		// Push assets, if neccessary
		if($pakParts['assets'] && $sspak->contains('assets.tar.gz')) {
			$webroot->putassets($sspak);
		}
	}

	/**
	 * Install an .sspak into a new environment.
	 */
	public function install($args) {
		$executor = $this->executor;

		$args->requireUnnamed(array('source sspak file', 'dest new webroot'));

		// Set-up
		$file = $args->unnamed(0);
		$webrootDir = $args->unnamed(1);
		$sspak = new SSPakFile($file, $executor);
		$webroot = new Webroot($webrootDir, $executor);
		$webroot->setSudo($args->sudo('to'));
		$pakParts = $args->pakParts();

		// Validation
		if($webroot->exists($webroot->getPath())) throw new Exception( "Webroot '$webrootDir' already exists.");
		if(!$sspak->exists()) throw new Exception( "File '$file' doesn't exist.");

		// Create new dir
		$webroot->exec(array('mkdir', $webroot->getPath()));

		if($sspak->contains('git-remote')) {
			$details = $sspak->gitRemoteDetails();
			$webroot->putgit($details);
		}

		// TODO: composer install needed.

		// Push database, if necessary
		$namedArgs = $args->getNamedArgs();
		if($pakParts['db'] && $sspak->contains('database.sql.gz')) {
			$webroot->putdb($sspak, isset($namedArgs['drop-db']));
		}

		// Push assets, if neccessary
		if($pakParts['assets'] && $sspak->contains('assets.tar.gz')) {
			$webroot->putassets($sspak);
		}
	}

	/**
	 * Bundle a .sspak into a self-extracting executable installer.
	 */
	public function bundle($args) {
		// TODO: throws require_once errors, fix before re-enabling.

		$executor = $this->executor;

		$args->requireUnnamed(array('source sspak file', 'dest executable file'));

		// Set-up
		$sourceFile = $args->unnamed(0);
		$destFile = $args->unnamed(1);

		$sspakScript = file_get_contents($_SERVER['argv'][0]);
		// Broken up to not get detected by our sed command
		$sspakScript .= "\n__halt_compiler();\n"."//"." TAR START?>\n";

		// Mark as self-extracting
		$sspakScript = str_replace('$isSelfExtracting = false;', '$isSelfExtracting = true;', $sspakScript);

		// Load the sniffer file
		$snifferFile = dirname(__FILE__) . '/sspak-sniffer.php';
		$sspakScript = str_replace("\$snifferFileContent = '';\n",
			"\$snifferFileContent = '"
			. str_replace(array("\\","'"),array("\\\\", "\\'"), file_get_contents($snifferFile)) . "';\n", $sspakScript);

		file_put_contents($destFile, $sspakScript);
		chmod($destFile, 0775);

		$executor->execLocal(array('cat', $sourceFile), array(
			'outputFile' => $destFile,
			'outputFileAppend' => true
		));
	}

	/**
	 * Transfer between environments without creating an sspak file
	 */
	public function transfer($args) {
		echo "Not implemented yet.\n";
	}
}
