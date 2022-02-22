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
    const PH_RUN_IN_SHELL       = 'run-in-shell';
    const PH_TIMEOUT            = 'timeout';
    const PH_DRY_RUN            = 'dry-run';
    const PH_FIND_EXECUTABLE    = 'find-executable';
    const PH_DIRECTORY          = 'directory';
    const PH_ENV_VARS           = 'environment';

    const PH_DISPLAY_PROGRESS   = 'display-progress';

    const PH_DISABLE_OUTPUT          =  'disable-output';
    const PH_DEFAULT_OUTPUT          = 'default-output-level';
    const PH_OUTPUT_DEBUG_LINES      = 'output-debug-lines';
    const PH_OUTPUT_INFO_LINES       = 'output-info-lines';
    const PH_OUTPUT_NOTICE_LINES     = 'output-notice-lines';
    const PH_OUTPUT_WARNING_LINES    = 'output-warning-lines';
    const PH_OUTPUT_ERROR_LINES      = 'output-error-lines';
    const PH_OUTPUT_CRITICAL_LINES   = 'output-critical-lines';
    const PH_OUTPUT_ALERT_LINES      = 'output-alert-lines';
    const PH_OUTPUT_EMERGENCY_LINES  = 'output-emergency-lines';
    const PH_OUTPUT_IGNORE_LINES     = 'output-ignore-lines';

    const PH_OUTPUT_RAISE_ERROR      = 'output-raise-error';
    const PH_STOP_ON_ERROR           = 'stop-on-error';
    const PH_EXCEPTION_ON_ERROR      = 'exception-on-error';
    const PH_EXIT_CODES_OK           = 'exit-codes-ok';

    const DEFAULT_OPTIONS = [
        self::PH_RUN_IN_SHELL           => false,
        self::PH_TIMEOUT                => 60,
        self::PH_DRY_RUN                => false,
        self::PH_FIND_EXECUTABLE        => false,
        self::PH_DIRECTORY              => '',
        self::PH_ENV_VARS               => [],

        self::PH_DISPLAY_PROGRESS       => false,
        self::PH_DISABLE_OUTPUT         => false,
        self::PH_DEFAULT_OUTPUT         => 'debug',
        self::PH_OUTPUT_DEBUG_LINES     => [],
        self::PH_OUTPUT_INFO_LINES      => [],
        self::PH_OUTPUT_NOTICE_LINES    => [],
        self::PH_OUTPUT_WARNING_LINES   => [],
        self::PH_OUTPUT_ERROR_LINES     => [],
        self::PH_OUTPUT_CRITICAL_LINES  => [],
        self::PH_OUTPUT_ALERT_LINES     => [],
        self::PH_OUTPUT_EMERGENCY_LINES => [],
        self::PH_OUTPUT_IGNORE_LINES    => [],

        self::PH_OUTPUT_RAISE_ERROR     => false,
        self::PH_STOP_ON_ERROR          => true,
        self::PH_EXCEPTION_ON_ERROR     => false,
        self::PH_EXIT_CODES_OK          => [],
    ];
    /** @var bool $stopOnError */
    protected $stopOnError = true;

    /** @var string $output */
    protected $output;

    /** @var int $returnCode */
    protected $returnCode;

    /** @var LoggerInterface $logger */
    protected $logger;

    /** @var array<string,bool|int> */
    protected $defaultOptions;

    /** @var array<string,bool|int> */
    protected $globalOptions;

    /** @var array<string,bool|int> */
    protected $commandOptions;

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

        $this->readOptions(self::DEFAULT_OPTIONS, $this->defaultOptions);
        $this->resetCommandOptions();
        $this->resetGlobalOptions();
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
        $this->globalOptions[self::PH_DISPLAY_PROGRESS] = $displayProgress;

        return $this;
    }
    /**
     * determines wether to stop processing next commands when a command in a list ends with error
     *
     * @param bool $stopOnError
     *
     * @return self
     */
    public function stopOnError($stopOnError)
    {
        $this->globalOptions[self::PH_STOP_ON_ERROR] = $stopOnError;

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
        $this->globalOptions[self::PH_TIMEOUT] = $timeout;

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
     * array<command>
     * Command may be :
     * 1/ a string                    : string will be split on white space
     * 2/ array<string>               : [cmd, arg1, arg2, ...]
     * 3/ named commands with options : [ 'label' => 'This is command one', 'cmd' => command, 'opts' => array<string> ]
     *
     *           1        2
     * @param string|array<string>|array<array<string>|array<array<string,mixed>>> $commands
     * @param string                                                               $label
     * @param array<string,string>                                                 $cmdCtx
     *
     * @return int
     */
    public function execCommands($commands, $label = '', $cmdCtx = [])
    {
        $stringifier  = new Stringifier();
        $interpolator = new PlaceholderInterpolator($stringifier);
        if (!is_array($commands)) {
            $commands = [ $commands ];
        }
        $nCommands = count($commands);
        $logContext = [ 'name' => $label ];
        $step = 1;
        foreach ($commands as $command) {
            $logContext['subProcess'] = '';
            if ($nCommands > 1) {
                $logContext['step']       = $step;
                $logContext['count']      = "$step/$nCommands";
                $step++;
            }
            // case 1
            if (is_string($command)) {
                $cmd = explode(' ', $command);
            // case 2
            } elseif (!array_key_exists('cmd', $command)) {
                $cmd = $command;
            // case 3
            } else {
                $cmd = $command['cmd'];
                if (array_key_exists('opts', $command)) {
                    $this->readCommandOptions($command['opts']);
                } else {
                    $this->resetCommandOptions();
                }
                if (array_key_exists('label', $command)) {
                    $logContext['subProcess'] = $command['label'];
                }
            }
            if ($cmdCtx) {
                $interpolated = [];
                foreach ($cmd as $elem) {
                    $interpolated[] = $interpolator($elem, $cmdCtx);
                }
                $cmd = $interpolated;
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
        return $this->execCommands($rawCmds, $label, $cmdCtx);
    }
    /**
     * executes a command
     *
     * @param array<string>       $command
     * @param array<string,mixed> $logContext
     *
     * @return int
     */
    public function execCommand($command, $logContext = [])
    {
        //$cmd = $command;

        if ($this->getOptionValue(self::PH_RUN_IN_SHELL)) {
            $process = Process::fromShellCommandline(implode(' ', $command));
        } else {
            $process = new Process($command);
        }
        $logContext['cmd'] = $process->getCommandLine();

        $process->setTimeout($this->getOptionValue(self::PH_TIMEOUT));
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
            if ($this->getOptionValue(self::PH_DISPLAY_PROGRESS)) {
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
            $timeout = $this->getOptionValue(self::PH_TIMEOUT);
            $this->logger->error("Timeout : job exeeded timeout of $timeout seconds", $logContext);
            $this->returnCode = 160;
        }
        //$endTime  = new \DateTime();
        //$duration = new \DateTime($process->getStartTime())->diff($endTime)->format('%h H, %i mn, %s secs');
        //$this->logger->info("{name} took $duration", $logContext);

        return $this->returnCode;
    }
        /**
     * lists all valid options
     *
     * @return array<string>
     */
    protected function getValidOptions()
    {
        return array_keys(self::DEFAULT_OPTIONS);
    }


     /**
      * merge options given to target array
      *
      * @param array<string,array<mixed>|bool|int|string> $opts
      * @param array<string,array<mixed>|bool|int|string> $tgtArray
      *
      * @return void
      */
    protected function readOptions($opts, &$tgtArray)
    {
        $validOptions = $this->getValidOptions();
        foreach ($opts as $optName => $optValue) {
            if (!in_array($optName, $validOptions)) {
                throw new \Exception(sprintf("Unknown option : '%s'", $optName));
            }
            $tgtArray[$optName] = $optValue;
        }
    }
    /**
     * read options for given command
     *
     * @param array<string,bool|int|string|array<string>|array<string,string>> $opts
     *
     * @return void
     */
    protected function readGlobalOptions($opts)
    {
        $this->readOptions($opts, $this->globalOptions);
    }
    /**
     * reset all command options
     *
     * @return void
     */
    protected function resetGlobalOptions()
    {
        $this->globalOptions = [];
    }
    /**
     * read options for given command
     *
     * @param array<string,bool|int> $opts
     *
     * @return void
     */
    protected function readCommandOptions($opts)
    {
        $this->readOptions($opts, $this->commandOptions);
    }
    /**
     * reset all command options
     *
     * @return void
     */
    protected function resetCommandOptions()
    {
        $this->commandOptions = [];
    }
    /**
     * Gets the value for given option
     *
     * @param string $optName
     *
     * @return int|bool
     */
    protected function getOptionValue($optName)
    {
        if (!in_array($optName, $this->getValidOptions())) {
            throw new \Exception(sprintf("Unknown option : '%s'", $optName));
        }
        if (array_key_exists($optName, $this->commandOptions)) {
            return $this->commandOptions[$optName];
        }
        if (array_key_exists($optName, $this->globalOptions)) {
            return $this->globalOptions[$optName];
        }

        return $this->defaultOptions[$optName];
    }
}
