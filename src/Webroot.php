<?php

/**
 * Represents one webroot, local or remote, that sspak interacts with
 */
class Webroot extends FilesystemEntity {
	protected $sudo = null;
	protected $details = null;

	function setSudo($sudo) {
		$this->sudo = $sudo;
	}

	/**
	 * Return a map of the db & asset config details.
	 * Calls sniff once and then caches
	 */
	function details() {
		if(!$this->details) $this->details = $this->sniff();
		return $this->details;
	}

	/**
	 * Return a map of the db & asset config details, acquired with ssnap-sniffer
	 */
	function sniff() {
		global $snifferFileContent;

		if(!$snifferFileContent) $snifferFileContent = file_get_contents(PACKAGE_ROOT . 'src/sspak-sniffer.php');

		$remoteSniffer = '/tmp/sspak-sniffer-' . rand(100000,999999) . '.php';
		$this->uploadContent($snifferFileContent, $remoteSniffer);

		$result = $this->execSudo(array('/usr/bin/env', 'php', $remoteSniffer, $this->path));
		//$this->unlink($remoteSniffer);

		$parsed = @unserialize($result['output']);
		if(!$parsed) throw new Exception("Could not parse sspak-sniffer content:\n{$result['output']}\n");
		return $parsed;
	}

	/**
	 * Execute a command on the relevant server, using the given sudo option
	 * @param  string $command Shell command, either a fully escaped string or an array
	 */
	function execSudo($command) {
		if($this->sudo) {
			if(is_array($command)) $command = $this->executor->commandArrayToString($command);
			// Try running sudo without asking for a password
			try {
				return $this->exec("sudo -n -u " . escapeshellarg($this->sudo) . " " . $command);

			// Otherwise capture SUDO password ourselves and pass it in through STDIN
			} catch(Exception $e) {
				echo "[sspak sudo] Enter your password: ";
				$stdin = fopen( 'php://stdin', 'r');
				$password = fgets($stdin);

				return $this->exec("sudo -S -p '' -u " . escapeshellarg($this->sudo) . " " . $command, array('inputContent' => $password));
			}
		
		} else {
			return $this->exec($command);
		}
	}

	/**
	 * Put the database from the given sspak file into this webroot.
	 * @param array $details The previously sniffed details of this webroot
	 * @param string $sspakFile Filename
	 */
	function putdb($sspak) {
		$details = $this->details();

		// Check the database type
		$dbFunction = 'putdb_'.$details['db_type'];
		if(!method_exists($this,$dbFunction)) {
			throw new Exception("Can't process database type '" . $details['db_type'] . "'");
		}

		// Extract DB direct from sspak file
		return $this->$dbFunction($details, $sspak);
	}

	function putdb_MySQLDatabase($conf, $sspak) {
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

		$this->exec("echo 'create database if not exists `" . addslashes($conf['db_database']) . "`' | mysql $usernameArg $passwordArg $hostArg $portArg");

		$stream = $sspak->readStreamForFile('database.sql.gz');
		$this->exec("gunzip -c | mysql --default-character-set=utf8 $usernameArg $passwordArg $hostArg $portArg $databaseArg", array('inputStream' => $stream));
		fclose($stream);
		return true;
	}

	function putdb_PostgreSQLDatabase($conf, $sspak) {
		$usernameArg = escapeshellarg("--username=".$conf['db_username']);
		$passwordArg = "PGPASSWORD=".escapeshellarg($conf['db_password']);
		$databaseArg = escapeshellarg($conf['db_database']);
		$hostArg = escapeshellarg("--host=".$conf['db_server']);

		// Create database if needed
		$result = $this->exec("echo \"select count(*) from pg_catalog.pg_database where datname = $databaseArg\" | $passwordArg psql $usernameArg $hostArg $databaseArg -qt");
		if(trim($result['output']) == '0') {
			$this->exec("$passwordArg createdb $usernameArg $hostArg $databaseArg");
		}

		$stream = $sspak->readStreamForFile('database.sql.gz');
		return $this->exec("gunzip -c | $passwordArg psql $usernameArg $hostArg $databaseArg", array('inputStream' => $stream));
		fclose($stream);
	}

	function putassets($sspak) {
		$details = $this->details();
		$assetsPath = $details['assets_path'];

		$assetsParentArg = escapeshellarg(dirname($assetsPath));
		$assetsBaseArg = escapeshellarg(basename($assetsPath));
		$assetsBaseOldArg = escapeshellarg(basename($assetsPath).'.old');

		// Move existing assets to assets.old
		$this->exec("if [ -d $assetsBaseArg ]; then mv $assetsBaseArg $assetsBaseOldArg; fi");

		// Extract assets
		$stream = $sspak->readStreamForFile('assets.tar.gz');
		$this->exec("tar xzf - -C $assetsParentArg", array('inputStream' => $stream));
		fclose($stream);

		// Remove assets.old
		$this->exec("if [ -d $assetsBaseOldArg ]; then rm -rf $assetsBaseOldArg; fi");
	}

	/**
	 * Load a git remote into this webroot.
	 * It expects that this remote is an empty directory.
	 * 
	 * @param array $details Map of git details
	 */
	function putgit($details) {
		$this->exec(array('git', 'clone', $details['remote'], $this->path));
		$this->exec("cd $this->path && git checkout " . escapeshellarg($details['branch']));
		return true;
	}
}
