<?php

class SSPakFile extends FilesystemEntity {

	/**
	 * Given a command array, switch to a self-extracting variation if needed
	 */
	function selfStreamCommand($command) {
		if($this->path == '@self') {
			$chosenPath = "-";
			$self = escapeshellarg($_SERVER['argv'][0]);
			$prefix = "sed -n -e '/\/\/ TAR START/,\$p' $self | tail -n +2 | ";
		} else {
			$chosenPath = $this->path;
			$prefix = null;
		}

		foreach($command as $i => $item) {
			if($item == '@path') $command[$i] = $chosenPath;
		}

		return $prefix . $this->executor->commandArrayToString($command);
	}

	/**
	 * Returns true if this sspak file contains the given file.
	 * @param string $file The filename to look for
	 * @return boolean
	 */
	function contains($file) {
		$contents = $this->exec($this->selfStreamCommand(array('tar', 'tf', '@path')));

		$items = explode("\n", trim($contents['output']));
		foreach($items as $item) if ($item == $file) return true;
		return false;
	}

	/**
	 * Returns the content of a file from this sspak
	 */
	function content($file) {
		$command = $this->selfStreamCommand(array("tar", "Oxf", '@path', "--include", $file));
		$result = $this->exec($command);
		
		return $result['output'];
	}

	/**
	 * Pipes the content of a file through another command
	 */
	function pipeContent($file, $pipeCommand) {
		$command = $this->selfStreamCommand(array("tar", "Oxf", '@path', "--include", $file));
		$result = $this->exec("$command | $pipeCommand");
		
		return $result['output'];
	}

	/**
	 * Extracts the git remote details and reutrns them as a map
	 */
	function gitRemoteDetails() {
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