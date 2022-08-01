<?php

namespace SilverStripe\SSPak;

/*
 * Responsible for executing commands.
 *
 * This could probably be replaced with something from Symfony, but right now this simple implementation works.
 */
class Executor
{
    protected $defaultOptions = array(
        'throwException' => true,
        'inputContent' => null,
        'inputFile' => null,
        'inputStream' => null,
        'outputFile' => null,
        'outputFileAppend' => false,
        'outputStream' => null,
    );

    /**
     * @param string $command The command
     * @param boolean $throwException If true, an Exception will be thrown on a nonzero error code
     * @param boolean $returnOutput If true, output will be captured
     * @param boolean $inputContent Content for STDIN. Otherwise the parent script's STDIN is used
     * @return A map containing 'return', 'output', and 'error'
     */
    public function execLocal($command, $options = array())
    {
        $process = $this->createLocal($command, $options);
        return $process->exec();
    }

    public function execRemote($command, $options = array())
    {
        $process = $this->createRemote($command, $options);
        return $process->exec();
    }

    public function createLocal($command, $options)
    {
        $options = array_merge($this->defaultOptions, $options);
        if (is_array($command)) {
            $command = $this->commandArrayToString($command);
        }

        return new Process($command, $options);
    }

    public function createRemote($server, $command, $options = array())
    {
        $process = $this->createLocal($command, $options);
        $process->setRemoteServer($server);
        return $process;
    }

    /**
     * Turn an array command in a string, escaping and concatenating each item
     * @param array $command Command array. First element is the command and all remaining are the arguments.
     * @return string String command
     */
    public function commandArrayToString($command)
    {
        $string = escapeshellcmd(array_shift($command));
        foreach ($command as $arg) {
            $string .= ' ' . escapeshellarg($arg);
        }
        return $string;
    }
}
