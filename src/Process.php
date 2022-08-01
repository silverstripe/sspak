<?php

namespace SilverStripe\SSPak;

use Exception;

class Process
{
    protected $command;
    protected $options;
    protected $remoteServer = null;

    public function __construct($command, $options = array())
    {
        $this->command = $command;
        $this->options = $options;
    }

    public function setRemoteServer($remoteServer)
    {
        $this->remoteServer = $remoteServer;
    }

    public function exec($options = array())
    {
        $options = array_merge($this->options, $options);

        // Modify command for remote execution, if necessary.
        if ($this->remoteServer) {
            if (!empty($options['outputFile']) || !empty($options['outputStream'])) {
                $ssh = "ssh -T ";
            } else {
                $ssh = "ssh -t ";
            }
            if (!empty($options['identity'])) {
                $ssh .= '-i ' . escapeshellarg($options['identity']) . ' ';
            }
            $command = $ssh . escapeshellarg($this->remoteServer) . ' ' . escapeshellarg($this->command);
        } else {
            $command = $this->command;
        }

        $pipes = array();
        $pipeSpec = array(
            0 => STDIN,
            1 => array('pipe', 'w'),
            2 => STDERR,
        );

        // Alternatives
        if ($options['inputContent'] || $options['inputStream']) {
            $pipeSpec[0] = array('pipe', 'r');
        }

        if ($options['outputFile']) {
            $pipeSpec[1] = array('file',
                $options['outputFile'],
                $options['outputFileAppend'] ? 'a' : 'w');
        }

        $process = proc_open($command, $pipeSpec, $pipes);

        if ($options['inputContent']) {
            fwrite($pipes[0], $options['inputContent']);
        } elseif ($options['inputStream']) {
            while ($content = fread($options['inputStream'], 8192)) {
                fwrite($pipes[0], $content);
            }
        }
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }

        $result = array();

        if (isset($pipes[1])) {
            // If a stream was provided, then pipe all the content
            // Doing it this way rather than passing outputStream to $pipeSpec
            // Means that streams as well as simple FDs can be used
            if ($options['outputStream']) {
                while ($content = fread($pipes[1], 8192)) {
                    fwrite($options['outputStream'], $content);
                }

            // Otherwise save to a string
            } else {
                $result['output'] = stream_get_contents($pipes[1]);
            }
            fclose($pipes[1]);
        }
        if (isset($pipes[2])) {
            $result['error'] = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }

        $result['return'] = proc_close($process);

        if ($options['throwException'] && $result['return'] != 0) {
            throw new Exception("Command: $command\nExecution failed: returned {$result['return']}.\n"
                . (empty($result['output']) ? "" : "Output:\n{$result['output']}"));
        }

        return $result;
    }
}
