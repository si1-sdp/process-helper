<?php

declare(strict_types=1);

/*
 * This file is part of deslp
 */

namespace jmg\ProcessHelper;

use mef\Stringifier\Stringifier;
use mef\StringInterpolation\PlaceholderInterpolator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * class RepoMirror
 * Yum repo mirror class
 */
class ProcessHelper
{
    /** @var bool $displayProgress */
    protected $displayProgress = false;

    /** @var bool $stopOnError */
    protected $stopOnError = true;

    /** @var int $timeout */
    protected $timeout = 60;

    /** @var string $output */
    protected $output;

    /** @var int $returnCode */
    protected $returnCode;

    /** @var LoggerInterface $logger */
    protected $logger;
    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     *
     * @return void
     */
    public function __construct($logger = null)
    {
        if ($logger) {
            $this->logger = $logger;
        } else {
            $logger = new ConsoleLogger(new ConsoleOutput());
        }
    }
    /**
     * Enable/disables progress bar display
     *
     * @param bool $displayProgress
     *
     * @return self
     */
    public function displayProgress($displayProgress)
    {
        $this->displayProgress = $displayProgress;

        return $this;
    }
    /**
     * determines wether to stop processing nex commands when a command in a list ends with error
     *
     * @param bool $stopOnError
     *
     * @return self
     */
    public function stopOnError($stopOnError)
    {
        $this->stopOnError = $stopOnError;

        return $this;
    }
    /**
     * Sets timeout for process execution
     *
     * @param int $timeout : Timeout in seconds
     *
     * @return self
     */
    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;

        return $this;
    }
    /**
     * Gets the output of commands
     *
     * @return string
     */
    public function getOutput()
    {
        return $this->output;
    }
    /**
     * Gets the return code of last command
     *
     * @return int
     */
    public function getReturnCode()
    {
        return $this->returnCode;
    }
    /**
     * Runs an array of commands
     *
     * @param array<string,mixed>|string $commands
     * @param string                     $label
     *
     * @return int
     */
    public function execCommands($commands, $label = '')
    {
        if (!is_array($commands)) {
            $commands = [ $label => $commands ];
        }
        $nCommands = count($commands);
        $logContext = [ 'name' => $label ];
        $n = 1;
        foreach ($commands as $step => $cmd) {
            if ($nCommands > 1) {
                $logContext = [ 'step' => $step, 'count' => "$n/$nCommands"];
            }
            $retCode = $this->execCommand($cmd, $logContext);
            if (0 !== $retCode && $this->stopOnError) {
                return $retCode;
            }
        }

        return 0;
    }

    /**
     * interpolates and executes a set of commands
     *
     * @param array<string,mixed>|string $rawCmds
     * @param array<string,string>       $cmdCtx
     * @param string                     $label
     *
     * @return int
     */
    public function interpolateAndExecCommands($rawCmds, $cmdCtx, $label)
    {
        $stringifier  = new Stringifier();
        $interpolator = new PlaceholderInterpolator($stringifier);

        $cmds = [];
        if (is_string($rawCmds)) {
            $rawCmds = [ $label => $rawCmds ];
        }
        foreach ($rawCmds as $name => $cmd) {
            if (is_array($cmd)) {
                if (array_key_exists('ssh', $cmd) && array_key_exists('cmd', $cmd)) {
                    $ssh = $interpolator($cmd['ssh'], $cmdCtx);
                    $remoteCommand = $interpolator($cmd['cmd'], $cmdCtx);
                    $cmds[$name] = explode(' ', $ssh);
                    $cmds[$name][] =  $remoteCommand;
                }
            } else {
                $cmds[$name] = $interpolator($cmd, $cmdCtx);
            }
        }

        return $this->execCommands($cmds, $label);
    }
    /**
     * executes a command
     *
     * @param string|array<string> $command
     * @param array<string,string> $logContext
     *
     * @return int
     */
    public function execCommand($command, $logContext = [])
    {
        if (is_string($command)) {
            $cmd = explode(' ', $command);
        } else {
            $cmd = $command;
            $command = implode(' ', $cmd);
        }

        $process = new Process($cmd);
        $process->setTimeout($this->timeout);
        $startTime = new \DateTime();
        $this->returnCode = 0;
        if (array_key_exists('step', $logContext)) {
            $stepInfo = "{count}:{step} ";
        } else {
            $stepInfo = '';
        }
        $this->logger->info("${stepInfo}CMD = $command", $logContext);
        try {
            $lastLine = '';
            $process->start();
            $progressBar = null;
            if ($this->displayProgress) {
                $progressBar = new ProgressBar(new ConsoleOutput());
                $progressBar->setFormat('%elapsed% [%bar%] %message%');
            }
            $iterator = $process->getIterator();
            foreach ($iterator as $data) {
                print_r($data);
                foreach (explode("\n", $data) as $line) {
                    if (!empty(str_replace(' ', '', $line))) {
                        if ($this->displayProgress) {
                            $progressBar->advance();
                            $progressBar->setMessage(substr($line, 0, 80));
                        }
                        $this->logger->debug($line, $logContext);
                        $lastLine = $line;
                    }
                }
            }
            if ($this->displayProgress) {
                $progressBar->clear();
                $this->logger->notice($lastLine, $logContext);
            }
            if (!$process->isSuccessful()) {
                $err = 'Process '.$command.' exited with code '.$process->getExitCode()."\n";
                $err .= $process->getExitCodeText();
                $this->logger->error($err, $logContext);
                $this->returnCode = 0 + $process->getExitCode();
            } else {
                $this->logger->notice("${stepInfo}command was successfull !", $logContext);
            }
        } catch (ProcessTimedOutException $exception) {
            $this->logger->error("Timeout : job exeeded timeout of $this->timeout seconds", $logContext);
            $this->returnCode = 160;
        }
        $endTime  = new \DateTime();
        $duration = $startTime->diff($endTime)->format('%h H, %i mn, %s secs');
        $this->logger->info("{name} took $duration", $logContext);

        return $this->returnCode;
    }
}
