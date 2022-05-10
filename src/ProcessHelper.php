<?php

declare(strict_types=1);

/*
 * This file is part of deslp
 */

namespace jmg\ProcessHelper;

use DateTime;
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
    /** @var string $output */
    protected $output;

    /** @var int $returnCode */
    protected $returnCode;

    /** @var LoggerInterface $logger */
    protected $logger;

    /** @var ProcessHelperOptions */
    protected $globalOptions;

    /**
     * Constructor
     *
     * @param LoggerInterface     $logger
     * @param array<string,mixed> $options
     *
     * @return void
     */
    public function __construct($logger = null, $options = [])
    {
        if ($logger) {
            $this->logger = $logger;
        } else {
            $logger = new ConsoleLogger(new ConsoleOutput());
        }
        $this->globalOptions = new ProcessHelperOptions($options);
    }
    /**
     *
     * @param int $timeout
     *
     * @return ProcessHelper
     */
    public function setTimeout($timeout)
    {
        $this->globalOptions->set(ProcessHelperOptions::PH_TIMEOUT, $timeout);

        return $this;
    }
    /**
     * @param bool $progress
     *
     * @return ProcessHelper
     */
    public function setDisplayProgress($progress)
    {
        $this->globalOptions->set(ProcessHelperOptions::PH_DISPLAY_PROGRESS, $progress);

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
     * executes a command
     *
     * @param array<string>       $command
     * @param array<string,mixed> $logContext
     * @param array<string,mixed> $commandOptions
     *
     * @return int
     */
    public function execCommand($command, $logContext = [], $commandOptions = [])
    {
        $opts = $this->globalOptions->merge($commandOptions);
        if ($opts->get(ProcessHelperOptions::PH_RUN_IN_SHELL)) {
            $process = Process::fromShellCommandline(implode(' ', $command));
        } else {
            $process = new Process($command);
        }
        $logContext['cmd'] = $process->getCommandLine();

        $process->setTimeout($opts->get(ProcessHelperOptions::PH_TIMEOUT));
        $this->returnCode = 0;
        if (array_key_exists('step', $logContext)) {
            $stepInfo = "{count}:[{subProcess}] ";
        } else {
            $stepInfo = '';
        }
        $this->logger->info("${stepInfo}CMD = {cmd}", $logContext);
        try {
            $lastLine = '';
            $process->start();
            $progressBar = null;
            if ($opts->get(ProcessHelperOptions::PH_DISPLAY_PROGRESS)) {
                $progressBar = new ProgressBar(new ConsoleOutput());
                $progressBar->setFormat('%elapsed% [%bar%] %message%');
            }
            $iterator = $process->getIterator();
            foreach ($iterator as $data) {
                foreach (explode("\n", $data) as $line) {
                    if (!empty(str_replace(' ', '', $line))) {
                        if ($progressBar) {
                            $progressBar->advance();
                            $progressBar->setMessage(substr($line, 0, 80));
                        }
                        $this->logger->debug($line, $logContext);
                        $lastLine = $line;
                    }
                }
            }
            if ($progressBar) {
                $progressBar->clear();
                $this->logger->notice($lastLine, $logContext);
            }
            if (!$process->isSuccessful()) {
                $err = "Process '{cmd}' exited with code ".$process->getExitCode();
                $this->logger->error($err, $logContext);
                $this->logger->error($process->getExitCodeText(), $logContext);

                $this->returnCode = 0 + $process->getExitCode();
            } else {
                $this->logger->notice("${stepInfo}command was successfull !", $logContext);
            }
        } catch (ProcessTimedOutException $exception) {
            $timeout = $opts->get(ProcessHelperOptions::PH_TIMEOUT);
            $this->logger->error("Timeout : job exeeded timeout of $timeout seconds", $logContext);
            $this->returnCode = 160;
        }
        //$endTime  = new \DateTime();
        //$duration = new \DateTime($process->getStartTime())->diff($endTime)->format('%h H, %i mn, %s secs');
        //$this->logger->info("{name} took $duration", $logContext);

        return $this->returnCode;
    }
}
