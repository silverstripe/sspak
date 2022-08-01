<?php

namespace SilverStripe\SSPak;

use Exception;

/**
 * An entity on the filesystem, used as a base class for Webroot and SSPakFile
 */
class FilesystemEntity
{
    protected $server;
    protected $path;
    protected $executor;
    protected $identity = null;

    public function __construct($path, $executor)
    {
        $this->executor = $executor;

        if (strpos($path, ':') !== false) {
            list($this->server,$this->path) = explode(':', $path, 2);
        } else {
            $this->server = null;
            $this->path = $path;
        }
    }

    public function isLocal()
    {
        return $this->server == null;
    }
    public function getPath()
    {
        return $this->path;
    }
    public function getServer()
    {
        return $this->server;
    }
    public function setSSHItentityFile($filename)
    {
        $this->identity = $filename;
    }

    /**
     * Execute a command on the relevant server
     * @param  string $command Shell command, either a fully escaped string or an array
     */
    public function exec($command, $options = array())
    {
        return $this->createProcess($command, $options)->exec();
    }

    /**
     * Create a process for later exection
     * @param  string $command Shell command, either a fully escaped string or an array
     * @return Process
     */
    public function createProcess($command, $options = array())
    {
        if ($this->server) {
            if ($this->identity && !isset($options['identity'])) {
                $options['identity'] = $this->identity;
            }
            return $this->executor->createRemote($this->server, $command, $options);
        }

        return $this->executor->createLocal($command, $options);
    }

    /**
     * Upload a file to the given destination on the server
     * @param string $file The file to upload
     * @param string $dest The remote filename/dir to upload to
     */
    public function upload($source, $dest)
    {
        if ($this->server) {
            $this->executor->execLocal(array("scp", $source, "$this->server:$dest"));
        } else {
            $this->executor->execLocal(array("cp", $source, $dest));
        }
    }

    /**
     * Create a file with the given content at the given destination on the server
     * @param string $content The content of the file
     * @param string $dest The remote filename/dir to upload to
     */
    public function uploadContent($content, $dest)
    {
        $this->exec("echo " . escapeshellarg($content) . " > " . escapeshellarg($dest));
    }

    /**
     * Download a file from the given source on the server to the given file
     * @param string $source The remote filename to download
     * @param string $dest The local filename/dir to download to
     */
    public function download($source, $dest)
    {
        if ($this->server) {
            $this->executor->execLocal(array("scp", "$this->server:$source", $dest));
        } else {
            $this->executor->execLocal(array("cp", $file, $dest));
        }
    }

    /**
     * Returns true if the given file or directory exists
     * @param string $file The file/dir to look for
     * @return boolean
     */
    public function exists($file = null)
    {
        if (!$file) {
            $file = $this->path;
        }
        if ($file == '@self') {
            return true;
        }

        if ($this->server) {
            $result = $this->exec("if [ -e " . escapeshellarg($file) . " ]; then echo yes; fi");
            return (trim($result['output']) == 'yes');
        } else {
            return file_exists($file);
        }
    }

    /**
     * Create the given file with the given content
     */
    public function writeFile($file, $content)
    {
        if ($this->server) {
            $this->exec("echo " . escapeshellarg($content) . " > " . escapeshellarg($file));
        } else {
            file_put_contents($file, $content);
        }
    }

    /**
     * Remove a file or folder from the webroot's server
     *
     * @param string $file The file to remove
     */
    public function unlink($file)
    {
        if (!$file || $file == '/' || $file == '.') {
            throw new Exception("Can't unlink file '$file'");
        }
        $this->exec(array('rm', '-rf', $file));
        return true;
    }
}
