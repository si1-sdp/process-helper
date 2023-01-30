<?php

declare(strict_types=1);

/*
 * This file is part of dgfip-si1/process-helper
 */

namespace DgfipSI1\ProcessHelper;

use DgfipSI1\ProcessHelper\Exception\BadOptionException;
use DgfipSI1\ProcessHelper\Exception\BadSearchException;
use DgfipSI1\ProcessHelper\Exception\ExecNotFoundException;
use DgfipSI1\ProcessHelper\Exception\ProcessException;
use DgfipSI1\ProcessHelper\Exception\UnknownOutputTypeException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use DgfipSI1\ProcessHelper\ConfigSchema as CONF;

/**
 * class ProcessHelper
 *
 */
class ProcessHelper
{
    /** @var string $commandLine */
    protected $commandLine;

    /** @var array<array<string,string>> $output */
    protected $output;

    /** @var int $returnCode */
    protected $returnCode;

    /** @var LoggerInterface $logger */
    protected $logger;

    /** @var ProcessHelperOptions */
    protected $conf;

    /** @var array<string,array<string>> $matches */
    protected $matches = [];
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
        if (null === $logger) {
            $logger = new ConsoleLogger(new ConsoleOutput());
        }
        $this->logger = $logger;
        $this->output = [];
        $this->conf = new ProcessHelperOptions($options);
    }
    /**
     * set logger
     *
     * @param LoggerInterface $logger
     *
     * @return self
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }
    /**
     * set options
     *
     * @param array<string,mixed> $options
     *
     * @return self
     */
    public function setOptions($options)
    {
        $this->conf = new ProcessHelperOptions($options);

        return $this;
    }
    /**
     * For debuging purpose : print options
     *
     * @return void
     */
    public function printOptions()
    {
        print $this->conf->dumpConfig();
    }
    /**
     *
     * @param int $timeout
     *
     * @return ProcessHelper
     */
    public function setTimeout($timeout)
    {
        $this->conf->setDefault(CONF::TIMEOUT, $timeout);
        $this->conf->build();

        return $this;
    }
    /**
     *
     * @param bool $runInShell
     *
     * @return ProcessHelper
     */
    public function runInShell($runInShell)
    {
        $this->conf->setDefault(CONF::RUN_IN_SHELL, $runInShell);

        return $this;
    }
    /**
     * set Environment parameters
     *
     * @param array<string,mixed> $env
     * @param boolean             $useAppEnv
     * @param boolean             $useDotEnv
     * @param string              $dotEnvDir
     *
     * @return self
     */
    public function setEnv($env = [], $useAppEnv = true, $useDotEnv = false, $dotEnvDir = '.')
    {
        $this->conf->setDefault(CONF::ENV_VARS, $env);
        $this->conf->setDefault(CONF::USE_APPENV, $useAppEnv);
        $this->conf->setDefault(CONF::USE_DOTENV, $useDotEnv);
        // don't override dotenv directory if useDotEnv is false
        if ($useDotEnv) {
            $this->conf->setDefault(CONF::DOTENV_DIR, $dotEnvDir);
        }

        return $this;
    }

    /**
     *
     * @param string $output
     * @param string $stdout
     * @param string $stderr
     *
     * @return ProcessHelper
     */
    public function setOutput($output, $stdout = 'info', $stderr = 'error')
    {
        if (!in_array($output, CONF::OUTPUT_OPTIONS_LIST, true)) {
            throw new BadOptionException(sprintf("Output option '%s' does not exists", $output));
        }
        foreach ([$stdout, $stderr] as $channel) {
            if (!defined("Psr\Log\LogLevel::".strtoupper($channel))) {
                throw new BadOptionException(sprintf("Unavailable output channel '%s'.", $channel));
            }
        }

        $this->conf->setDefault(CONF::OUTPUT_MODE, $output);
        $this->conf->setDefault(CONF::OUTPUT_STDERR_TO, $stderr);
        $this->conf->setDefault(CONF::OUTPUT_STDOUT_TO, $stdout);

        return $this;
    }
    /**
     * Add a regexp search on output
     *
     * @param string      $name
     * @param string      $re
     * @param string|null $type // 'out' or 'err'
     *
     * @return void
     */
    public function addSearch($name, $re, $type = null)
    {
        /** @var array<string,mixed> */
        $searches = $this->conf->get(CONF::OUTPUT_RE_SEARCHES);

        $searches[] = [ 'name' => $name, 'regexp' => $re, 'type' => $type];
        $this->conf->contextSet('searches', CONF::OUTPUT_RE_SEARCHES, $searches);
        $this->matches[$name] = [];
        $this->conf->build();
    }
    /**
     * reset all searches
     *
     * @return void
     */
    public function resetSearches()
    {
        $this->conf->removeContext('searches');
        $this->matches = [];
    }

    /**
     * get items matched by regexps
     *
     * @param string $name
     *
     * @return array<string>
     */
    public function getMatches($name)
    {
        if (!array_key_exists($name, $this->matches)) {
            $err = "getMatches: Can't search for a match on variable '%s' - Initialize with 'addSearch()'";
            throw new BadSearchException(sprintf($err, $name));
        }

        return $this->matches[$name];
    }
    /**
     * reset matched items
     *
     * @param string|null $name
     *
     * @return void
     */
    public function resetMatches($name = null)
    {
        if (null !== $name) {
            if (!array_key_exists($name, $this->matches)) {
                throw new BadSearchException(sprintf("resetMatches: No match on variable '%s'", $name));
            }
            $this->matches[$name] = [];
        } else {
            foreach (array_keys($this->matches) as $match) {
                $this->matches[$match] = [];
            }
        }
    }

    /**
     * Gets the output of commands
     *
     * @param string $type either 'err', 'out' or 'all'
     *
     * @return array<string>
     */
    public function getOutput($type = 'all')
    {
        if (!in_array($type, ['err', 'out', 'all'], true)) {
            throw new UnknownOutputTypeException(sprintf("ProcessHelper:getOutput: Unkown type %s", $type));
        }
        $filterOnType = function ($v) use ($type) {
            if ('out' === $type || 'err' === $type) {
                return (array_key_exists($type, $v) ? $v[$type] : null);
            }

            return array_values($v)[0];
        };

        return array_filter(array_map($filterOnType, $this->output));
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
     * Gets the effective command line
     *
     * @return string
     */
    public function getCommandLine()
    {
        return $this->commandLine;
    }

    /**
     * Undocumented function
     *
     * @param string $name
     *
     * @return string
     */
    public function findExecutable($name)
    {
        $escapedName = str_replace("'", "", $name);
        $ph = $this->createFindExecutableProcess();
        try {
            $ph->execCommand(['which', $escapedName]);
            $ret = $ph->getOutput()[0];
        } catch (\Exception $e) {
            print $e->getMessage()."\n\n";
            throw new ExecNotFoundException(sprintf("executable '%s' not found", $escapedName));
        }
        if (!file_exists($ret)) {
            throw new ExecNotFoundException(sprintf("which return value '%s' not found", $ret));
        }

        return $ret;
    }
    /**
     * Creates a ProcessHelper object to be used by findExecutable method
     *
     * @return self
     */
    public function createFindExecutableProcess()
    {
        $options = [
            CONF::OUTPUT_MODE        => 'silent',
            CONF::RUN_IN_SHELL       => true,
        ];

        return new self($this->logger, $options);
    }
    /**
     * executes a command
     *
     * @param array<string>        $command
     * @param array<string,mixed>  $commandOptions
     * @param array<string,string> $logContext
     *
     * @return int
     */
    public function execCommand($command, $commandOptions = [], $logContext = [])
    {
        $process = $this->prepareProcess($command, $commandOptions, $logContext);
        if ((bool) $this->conf->get(CONF::DRY_RUN)) {
            $this->logger->notice("DRY-RUN - execute command {cmd}", $logContext);
            $this->conf->removeContext('command');

            return 0;
        }
        $this->logger->info("Launching command {cmd}", $logContext);

        return $this->execProcess($process, $logContext);
    }
    /**
     * executes a process after prepareProcess : step 2/2 of command execution
     *
     * @param Process              $process
     * @param array<string,string> $logContext
     *
     * @return int
     */
    public function execProcess($process, $logContext = [])
    {
        $processOutput = new ProcessOutput($this->conf, $this->logger, $logContext);
        try {
            $process->start();
            $iterator = $process->getIterator();
            /** @var string $type */
            /** @var string $data */
            foreach ($iterator as $type => $data) {
                foreach (explode("\n", $data) as $line) {
                    if (0 !== strlen(str_replace(' ', '', $line))) {
                        if ((bool) $this->conf->get(CONF::OUTPUT_RE_SEARCHES)) {
                            $this->search($line, $type, $this->conf);
                        }
                        $processOutput->newLine($type, $line);
                        $this->output[] = ["$type" => $line];
                    }
                }
            }
            $this->closeProcess($process, $this->conf, $processOutput, $logContext);
        } catch (ProcessTimedOutException $exception) {
            /** @var int $timeout */
            $timeout = $this->conf->get(CONF::TIMEOUT);
            $this->logger->error(sprintf("Timeout : job exeeded timeout of %d seconds", $timeout), $logContext);
            $this->returnCode = 160;
        }
        $this->conf->removeContext('command');

        return $this->returnCode;
    }

    /**
     * get environment variables for process
     *
     * @param array<string>        $command
     * @param array<string,mixed>  $commandOptions
     * @param array<string,string> $logContext
     *
     * @return Process
     */
    public function prepareProcess($command, $commandOptions, &$logContext)
    {
        $this->conf->addArray('command', $commandOptions);
        $opts = $this->conf;
        // HANDLE FIND EXECUTABLE
        if ((bool) $opts->get(CONF::FIND_EXECUTABLE)) {
            $exe = $this->findExecutable($command[0]);
            $command[0] = $exe;
        }

        // GET SYMFO
        $process = $this->createSymfonyProcess($command, (bool) $opts->get(CONF::RUN_IN_SHELL));

        // SET ENVIRONMENT VARIABLES
        $process->setEnv(ProcessEnv::getExecutionEnvironment($opts));

        // SET COMMAND LINE
        $this->commandLine = $process->getCommandLine();
        $logContext['cmd'] = join(' ', $command);

        // SET TIMEOUT
        /** @var int $timeout */
        $timeout = $opts->get(CONF::TIMEOUT);
        $process->setTimeout($timeout);

        // SET WORKING DIRECTORY
        if ($opts->get(CONF::DIRECTORY) !== null) {
            /** @var string $dir */
            $dir = $opts->get(CONF::DIRECTORY);
            $this->logger->debug("set working directory to $dir");
            $process->setWorkingDirectory($dir);
        }
        $this->returnCode = 0;
        $ignore  = $opts->get(CONF::EXCEPTION_ON_ERROR) === false ? 'false' : 'true';
        $optsMsg = sprintf("ignore_errors=%s, timeout=%s", $ignore, $timeout);
        $this->logger->debug("Execute command ($optsMsg): {cmd}", $logContext);

        return $process;
    }

    /**
     * Creates the symfony process
     *
     * @param array<string> $command
     * @param boolean       $inShell
     *
     * @return Process
     */
    protected function createSymfonyProcess($command, $inShell)
    {
        if (true === $inShell) {
            $process = Process::fromShellCommandline(implode(' ', $command));
        } else {
            $process = new Process($command);
        }

        return $process;
    }
    /**
     * send all output to logger
     *
     * @param ProcessOutput $processOutput
     *
     * @return void
     */
    protected function outputToLog($processOutput)
    {
        foreach ($this->output as $line) {
            foreach ($line as $type => $msg) {
                $processOutput->logOutput($type, $msg, true);
            }
        }
    }
    /**
     * close Process
     *
     * @param Process              $process
     * @param ProcessHelperOptions $opts
     * @param ProcessOutput        $processOutput
     * @param array<string,string> $logContext
     *
     * @return void
     */
    private function closeProcess($process, $opts, $processOutput, $logContext)
    {
        $processOutput->closeProgress();
        if (!$process->isSuccessful()) {
            if ('on_error' === $opts->get(CONF::OUTPUT_MODE)) {
                $this->outputToLog($processOutput);
            }
            $processOutput->log('error', (string) $process->getExitCodeText());
            $this->returnCode = (int) $process->getExitCode();
            if (true === $opts->get(CONF::EXCEPTION_ON_ERROR)) {
                $err = sprintf(
                    "error (code=%d) running command: '%s'",
                    $this->returnCode,
                    $process->getCommandLine()
                );
                /** @var array<integer>|null */
                $okErrors = $opts->get(CONF::EXIT_CODES_OK);
                if (null === $okErrors || !in_array($this->returnCode, $okErrors, true)) {
                    $opts->removeContext('command');
                    throw new ProcessException($err);
                }
                $processOutput->log('notice', "IGNORED: ".$err);
            }
        } else {
            if (array_key_exists('label', $logContext) && '' !== $logContext['label']) {
                $processOutput->log('info', "{label} - command was successfull !");
            } else {
                $processOutput->log('info', "command was successfull !");
            }
        }
    }
    /**
     * Search line for matching regexp, set $this->matches accordingly
     *
     * @param string               $line
     * @param string|null          $type 'out' | 'err'
     * @param ProcessHelperOptions $opts

     * @return void
     */
    private function search($line, $type, $opts)
    {
        /** @var array<array<string,string|null>> $searches */
        $searches = $opts->get(CONF::OUTPUT_RE_SEARCHES);
        foreach ($searches as $reSearch) {
            $name     = $reSearch['name'];
            $re       = $reSearch['regexp'];
            $doSearch = (null === $reSearch['type'] || $type === $reSearch['type']);

            $m     = [];
            if (preg_match("/$re/", $line, $m) === 1 && $doSearch) {
                $this->matches["$name"][] = $m[1];
            }
        }
    }
}
