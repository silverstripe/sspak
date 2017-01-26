<?php

class SSPakFile extends FilesystemEntity {

	protected $phar;
	protected $pharAlias;
	protected $pharPath;

	public function __construct($path, $executor, $pharAlias = 'sspak.phar') {
		parent::__construct($path, $executor);
		if(!$this->isLocal()) throw new LogicException("Can't manipulate remote .sspak.phar files, only remote webroots.");

		$this->pharAlias = $pharAlias;
		$this->pharPath = $path;

		// Executable Phar version
		if(substr($path,-5) === '.phar') {
			$this->phar = new Phar($path, FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME,
				$this->pharAlias);
			if(!file_exists($this->path)) $this->makeExecutable();

		// Non-executable Tar version
		} else {
			$this->phar = new PharData($path, FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME,
				$this->pharAlias);
		}
	}

	public function getPhar() {
		return $this->phar;
	}

	/**
	 * Add the SSPak executable information into this SSPak file
	 */
	public function makeExecutable() {
		if(ini_get('phar.readonly')) {
			throw new Exception("Please set phar.readonly to false in your php.ini.");
		}

		passthru("composer install -d " . escapeshellarg(PACKAGE_ROOT) . " --no-dev");

		$root = PACKAGE_ROOT;
		$srcRoots = [
			'src/',
			'vendor/',
		];

		// Add the bin file, but strip of the #! exec header.
		$this->phar['bin/sspak'] = preg_replace("/^#!\/usr\/bin\/env php\n/", '', file_get_contents($root . "bin/sspak"));

		foreach($srcRoots as $srcRoot) {
			foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $srcRoot)) as $fileObj) {
				if($fileObj->isFile()) {
					$file = $fileObj->getRealPath();

					$relativeFile = str_replace($root, '', $file);

					echo "Adding $relativeFile\n";
					$this->phar[$relativeFile] = file_get_contents($file);
				}
			}
		}

		$stub = <<<STUB
#!/usr/bin/env php
<?php
define('PACKAGE_ROOT', 'phar://$this->pharAlias/');
Phar::mapPhar('$this->pharAlias');
require 'phar://$this->pharAlias/bin/sspak';
__HALT_COMPILER();
STUB;

		$this->phar->setStub($stub);
		chmod($this->path, 0775);

		passthru("composer install -d " . escapeshellarg(PACKAGE_ROOT));
	}

	/**
	 * Returns true if this sspak file contains the given file.
	 * @param string $file The filename to look for
	 * @return boolean
	 */
	public function contains($file) {
		return $this->phar->offsetExists($file);
	}

	/**
	 * Returns the content of a file from this sspak
	 */
	public function content($file) {
		return file_get_contents($this->phar[$file]);
	}

	/**
	 * Pipe the output of the given process into a file within this SSPak
	 * @param  string $filename The file to create within the SSPak
	 * @param  Process $process  The process to execute and take the output from
	 * @return null
	 */
	public function writeFileFromProcess($filename, Process $process) {
		// Non-executable Phars can't have content streamed into them
		// This means that we need to create a temp file, which is a pain, if that file happens to be a 3GB
		// asset dump. :-/
		if($this->phar instanceof PharData) {
			$tmpFile = '/tmp/sspak-content-' .rand(100000,999999);
			$process->exec(array('outputFile' => $tmpFile));
			$this->phar->addFile($tmpFile, $filename);
			unlink($tmpFile);

		// So, where we *can* use write streams, we do so.
		} else {
			$stream = $this->writeStreamForFile($filename);
			$process->exec(array('outputStream' => $stream));
			fclose($stream);
		}
	}

	/**
	 * Return a writeable stream corresponding to the given file within the .sspak
	 * @param  string $filename The name of the file within the .sspak
	 * @return Stream context
	 */
	public function writeStreamForFile($filename) {
		return fopen('phar://' . $this->pharAlias . '/' . $filename, 'w');
	}

	/**
	 * Return a readable stream corresponding to the given file within the .sspak
	 * @param  string $filename The name of the file within the .sspak
	 * @return Stream context
	 */
	public function readStreamForFile($filename) {
		// Note: using pharAlias here doesn't work on Debian Wheezy (nor on Windows for that matter).
		//return fopen('phar://' . $this->pharAlias . '/' . $filename, 'r');
		return fopen('phar://' . $this->pharPath . '/' . $filename, 'r');
	}

	/**
	 * Create a file in the .sspak with the given content
	 * @param  string $filename The name of the file within the .sspak
	 * @param  string $content The content of the file
	 * @return null
	 */
	public function writeFile($filename, $content) {
		$this->phar[$filename] = $content;
	}

	/**
	 * Extracts the git remote details and reutrns them as a map
	 */
	public function gitRemoteDetails() {
		$content = $this->content('git-remote');
		$details = array();
		foreach(explode("\n", trim($content)) as $line) {
			if(!$line) continue;

			if(preg_match('/^([^ ]+) *= *(.*)$/', $line, $matches)) {
				$details[$matches[1]] = $matches[2];
			} else {
				throw new Exception("Bad line '$line'");
			}
		}
		return $details;
	}
}
