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
			"save" => array(
				"description" => "Save a .sspak file from a site.",
				"method" => "save",
			),
			"load" => array(
				"description" => "Load an .sspak into an environment. Does not backup - be careful!",
				"method" => "load",
			),
			"install" => array(
				"description" => "Install an .sspak into a new environment.",
				"method" => "install",
			),
			"bundle" => array(
				"description" => "Bundle a .sspak into a self-extracting executable installer.",
				"method" => "bundle",
			),
			"transfer" => array(
				"description" => "Transfer db & assets from one site to another (not implemented yet).",
				"method" => "transfer",
			),
		);
	}

	/**
	 * Save an .sspak file
	 */
	function save($args) {
		$executor = $this->executor;

		$args->requireUnnamed(array('source webroot', 'dest sspak file'));

		$unnamedArgs = $args->getUnnamedArgs();
		$namedArgs = $args->getNamedArgs();

		$webroot = new Webroot($unnamedArgs[0], $executor);
		$file = $unnamedArgs[1];
		$sspak = new SSPakFile($file, $executor);

		if(!empty($namedArgs['from-sudo'])) $webroot->setSudo($namedArgs['from-sudo']);
		else if(!empty($namedArgs['sudo'])) $webroot->setSudo($namedArgs['sudo']);

		// Look up which parts of the sspak are going to be saved
		$pakParks = array();
		foreach(array('assets','db','git-remote') as $part) {
			$pakParts[$part] = !empty($namedArgs[$part]);
		}
		// Default to db and assets
		if(!array_filter($pakParts)) $pakParts = array('db' => true, 'assets' => true, 'git-remote' => true);

		// Get the environment details
		$details = $webroot->sniff();

		if(file_exists($file)) throw new Exception( "File '$file' already exists.");

		// Create a build folder for the sspak file
		$buildFolder = "/tmp/sspak-" . rand(100000,999999);
		$webroot->exec(array('mkdir', $buildFolder));

		$dbFile = "$buildFolder/database.sql.gz";
		$assetsFile = "$buildFolder/assets.tar.gz";
		$gitRemoteFile = "$buildFolder/git-remote";

		// Files to include in the .sspak file
		$fileList = array();

		// Save DB
		if($pakParts['db']) {
			// Check the database type
			$dbFunction = 'getdb_'.$details['db_type'];
			if(!method_exists($this,$dbFunction)) {
				throw new Exception("Can't process database type '" . $details['db_type'] . "'");
			}
			$this->$dbFunction($webroot, $details, $dbFile);
			$fileList[] = basename($dbFile);
		}

		// Save Assets
		if($pakParts['assets']) {
			$this->getassets($webroot, $details['assets_path'], $assetsFile);
			$fileList[] = basename($assetsFile);
		}

		// Save git-remote
		if($pakParts['git-remote']) {
			if($this->getgitremote($webroot, $gitRemoteFile)) {
				$fileList[] = basename($gitRemoteFile);
			}
		}

		// Create the sspak file
		$webroot->exec(
			array_merge(array('tar', '-C', $buildFolder, '-c', '-f','-'), $fileList),
			array('outputFile' => $file)
		);
		
		// Remove the build folder
		$webroot->unlink($buildFolder);
	}

	function getdb_MySQLDatabase($webroot, $conf, $filename) {
		$usernameArg = escapeshellarg("--user=".$conf['db_username']);
		$passwordArg = escapeshellarg("--password=".$conf['db_password']);
		$databaseArg = escapeshellarg($conf['db_database']);
		$hostArg = (!empty($conf['db_server']) && $conf['db_server'] != 'localhost') ? escapeshellarg("--host=".$conf['db_server']) : '';
		$filenameArg = escapeshellarg($filename);

		return $webroot->exec("mysqldump --skip-opt --add-drop-table --extended-insert --create-options --quick  --set-charset --default-character-set=utf8 $usernameArg $passwordArg $hostArg $databaseArg | gzip -c > $filenameArg");
	}

	function getdb_PostgreSQLDatabase($webroot, $conf, $filename) {
		$usernameArg = escapeshellarg("--username=".$conf['db_username']);
		$passwordArg = "PGPASSWORD=".escapeshellarg($conf['db_password']);
		$databaseArg = escapeshellarg($conf['db_database']);
		$hostArg = escapeshellarg("--host=".$conf['db_server']);
		$filenameArg = escapeshellarg($filename);

		return $webroot->exec("$passwordArg pg_dump --clean $usernameArg $hostArg $databaseArg | gzip -c > $filenameArg");
	}

	function getassets($webroot, $assetsPath, $filename) {
		$assetsParentArg = escapeshellarg(dirname($assetsPath));
		$assetsBaseArg = escapeshellarg(basename($assetsPath));
		$filenameArg = escapeshellarg($filename);

		return $webroot->exec("cd $assetsParentArg && tar czf $filenameArg $assetsBaseArg");
	}

	function getgitremote($webroot, $gitRemoteFile) {
		// Only do anything if we're copying from a git checkout
		$gitRepo = $webroot->getPath() .'/.git';
		if($webroot->exists($gitRepo)) {
			// Identify current branch
			$output = $webroot->exec(array('git', '--git-dir='.$gitRepo, 'branch'));
			if(preg_match("/\* ([^ \n]*)/", $output['output'], $matches)) {
				// If there is a current branch, use that branch's remove
				$currentBranch = $matches[1];
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

			$webroot->writeFile($gitRemoteFile, $content);

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

		// Ensure the pakfile is on the destination server
		// If local, we can just use the sspak file directly
		if($webroot->isLocal()) {
			$sspakFile = $file;

		} else {
			// Give a name for sspakFile /tmp
			$sspakFile = "/tmp/sspak-" . rand(100000,999999) . ".sspak";

			// Upload the sspak file
			$webroot->upload($file, $sspakFile);
			$sspak = new SSPakFile($webroot->getServer().":$sspakFile", $executor);
		}

		// Push database, if necessary
		if($pakParts['db'] && $sspak->contains('database.sql.gz')) {
			$webroot->putdb($sspak);
		}

		// Push assets, if neccessary
		if($pakParts['assets'] && $sspak->contains('assets.tar.gz')) {
			$webroot->putassets($sspak);
		}

		// Remove sspak from the server
		if($sspakFile != $file) $webroot->unlink($sspakFile);
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

		// Ensure the pakfile is on the destination server
		// If local, we can just use the sspak file directly
		if($webroot->isLocal()) {
			$sspakFile = $file;

		} else {
			// Give a name for sspakFile /tmp
			$sspakFile = "/tmp/sspak-" . rand(100000,999999) . ".sspak";

			// Upload the sspak file
			$webroot->upload($file, $sspakFile);
			$sspak = new SSPakFile($webroot->getServer().":$sspakFile", $executor);

		}

		// Push database, if necessary
		if($pakParts['db'] && $sspak->contains('database.sql.gz')) {
			$webroot->putdb($sspak);
		}

		// Push assets, if neccessary
		if($pakParts['assets'] && $sspak->contains('assets.tar.gz')) {
			$webroot->putassets($sspak);
		}

		// Remove sspak from the server
		if($sspakFile != $file) $webroot->unlink($sspakFile);
	}

	/**
	 * Bundle a .sspak into a self-extracting executable installer.
	 */
	function bundle($args) {
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