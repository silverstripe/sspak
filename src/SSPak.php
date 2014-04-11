<?php

/**
 * SSPak handler
 */
class SSPak {
	protected $executor;

	/**
	 * Create a new handler
	 * @param Executor $executor The Executor object to handle command execution
	 */
	function __construct($executor) {
		$this->executor = $executor;
	}

	function getActions() {
		return array(
			"help" => array(
				"description" => "Show this help message.",
				"method" => "help",
			),
			"save" => array(
				"description" => "Save an .sspak file from a SilverStripe site.",
				"unnamedArgs" => array("webroot", "sspak file"),
				"method" => "save",
			),
			"load" => array(
				"description" => "Load an .sspak file into a SilverStripe site. Does not backup - be careful!",
				"unnamedArgs" => array("sspak file", "webroot"),
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

	function help($args) {
		echo "SSPak: manage SilverStripe .sspak archives.\n\nUsage:\n";
		foreach($this->getActions() as $action => $info) {
			echo "sspak $action";
			if(!empty($info['unnamedArgs'])) {
				foreach($info['unnamedArgs'] as $arg) echo " ($arg)";
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
	function saveexisting($args) {
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
	function extract($args) {
		$executor = $this->executor;

		$args->requireUnnamed(array('source sspak file'));
		$unnamedArgs = $args->getUnnamedArgs();
		$file = $unnamedArgs[0];
		$dest = !empty($unnamedArgs[1]) ? $unnamedArgs[1] : getcwd();

		$sspak = new SSPakFile($file, $executor);

		// Validation
		if(!$sspak->exists()) throw new Exception("File '$file' doesn't exist.");

		$phar = $sspak->getPhar();
		$phar->extractTo($dest);
	}

	/**
	 * Save a .sspak.phar file
	 */
	function save($args) {
		$executor = $this->executor;

		$args->requireUnnamed(array('source webroot', 'dest sspak file'));

		$unnamedArgs = $args->getUnnamedArgs();
		$namedArgs = $args->getNamedArgs();

		$webroot = new Webroot($unnamedArgs[0], $executor);
		$file = $unnamedArgs[1];
		if(file_exists($file)) throw new Exception( "File '$file' already exists.");

		$sspak = new SSPakFile($file, $executor);

		if(!empty($namedArgs['from-sudo'])) $webroot->setSudo($namedArgs['from-sudo']);
		else if(!empty($namedArgs['sudo'])) $webroot->setSudo($namedArgs['sudo']);

		// Look up which parts of the sspak are going to be saved
		$pakParts = $args->pakParts();

		// Get the environment details
		$details = $webroot->sniff();

		// Create a build folder for the sspak file
		$buildFolder = "/tmp/sspak-" . rand(100000,999999);
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

	function getdb_MySQLDatabase($webroot, $conf, $sspak, $filename) {
		$usernameArg = escapeshellarg("--user=".$conf['db_username']);
		$passwordArg = escapeshellarg("--password=".$conf['db_password']);
		$databaseArg = escapeshellarg($conf['db_database']);

		$hostArg = '';
		$postArg = '';
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

		$process = $webroot->createProcess("mysqldump --skip-opt --add-drop-table --extended-insert --create-options --quick  --set-charset --default-character-set=utf8 $usernameArg $passwordArg $hostArg $portArg $databaseArg | gzip -c");
		$sspak->writeFileFromProcess($filename, $process);
		return true;
	}

	function getdb_PostgreSQLDatabase($webroot, $conf, $sspak, $filename) {
		$usernameArg = escapeshellarg("--username=".$conf['db_username']);
		$passwordArg = "PGPASSWORD=".escapeshellarg($conf['db_password']);
		$databaseArg = escapeshellarg($conf['db_database']);
		$hostArg = escapeshellarg("--host=".$conf['db_server']);
		$filenameArg = escapeshellarg($filename);

		$process = $webroot->createProcess("$passwordArg pg_dump --clean --no-owner --no-tablespaces $usernameArg $hostArg $databaseArg | gzip -c");
		$sspak->writeFileFromProcess($filename, $process);
		return true;
	}

	function getassets($webroot, $assetsPath, $sspak, $filename) {
		$assetsParentArg = escapeshellarg(dirname($assetsPath));
		$assetsBaseArg = escapeshellarg(basename($assetsPath));

		$process = $webroot->createProcess("cd $assetsParentArg && tar cfh - $assetsBaseArg | gzip -c");
		$sspak->writeFileFromProcess($filename, $process);
	}

	function getgitremote($webroot, $sspak, $gitRemoteFile) {
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
	function load($args) {
		$executor = $this->executor;

		$args->requireUnnamed(array('source sspak file', 'dest webroot'));

		// Set-up
		$file = $args->unnamed(0);
		$sspak = new SSPakFile($file, $executor);
		$webroot = new Webroot($args->unnamed(1), $executor);
		$webroot->setSudo($args->sudo('to'));
		$pakParts = $args->pakParts();

		// Validation
		if(!$sspak->exists()) throw new Exception( "File '$file' doesn't exist.");

		// Push database, if necessary
		if($pakParts['db'] && $sspak->contains('database.sql.gz')) {
			$webroot->putdb($sspak);
		}

		// Push assets, if neccessary
		if($pakParts['assets'] && $sspak->contains('assets.tar.gz')) {
			$webroot->putassets($sspak);
		}
	}

	/**
	 * Install an .sspak into a new environment.
	 */
	function install($args) {
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
		if($pakParts['db'] && $sspak->contains('database.sql.gz')) {
			$webroot->putdb($sspak);
		}

		// Push assets, if neccessary
		if($pakParts['assets'] && $sspak->contains('assets.tar.gz')) {
			$webroot->putassets($sspak);
		}
	}

	/**
	 * Bundle a .sspak into a self-extracting executable installer.
	 */
	function bundle($args) {
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
	function transfer($args) {
		echo "Not implemented yet.\n";
	}
}
