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
use DgfipSI1\ConfigHelper\ConfigHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Path;
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
        if (!$logger) {
            $logger = new ConsoleLogger(new ConsoleOutput());
        }
        $this->logger = $logger;
        $this->output = [];
        $this->conf = new ProcessHelperOptions($options);
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
        $this->conf->build();

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
        $this->conf->build();

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
        if (!in_array($output, CONF::OUTPUT_OPTIONS_LIST)) {
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
        $this->conf->build();

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
        $this->conf->build();
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
        if ($name) {
            if (!array_key_exists($name, $this->matches)) {
                throw new BadSearchException(sprintf("resetMatches: No match on variable '%s'", $name));
            }
            $this->matches[$name] = [];
        } else {
            foreach (array_keys($this->matches) as $name) {
                $this->matches[$name] = [];
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
        if (!in_array($type, ['err', 'out', 'all'])) {
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
        try {
            $options = [
                CONF::FIND_EXECUTABLE    => false,
                CONF::EXCEPTION_ON_ERROR => true,
                CONF::OUTPUT_MODE        => 'silent',
                CONF::RUN_IN_SHELL       => true,
            ];
            $ph = new self($this->logger, $options);
            $ph->execCommand(['which', $escapedName]);
            $firstLine = $ph->getOutput()[0];
            if (strlen($firstLine) !== 0) {
                $firstLine = Path::canonicalize($firstLine);
            }

            return $firstLine;
        } catch (\Exception $e) {
            throw new ExecNotFoundException(sprintf("executable '%s' not found", $escapedName));
        }
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
        $this->conf->addArray('command', $commandOptions);
        $process = $this->prepareProcess($command, $logContext);

        if ($this->conf->get(CONF::DRY_RUN)) {
            $this->logger->notice("DRY-RUN - execute command {cmd}", $logContext);
            $this->conf->removeContext('command');

            return 0;
        }
        $processOutput = new ProcessOutput($this->conf, $this->logger, $logContext);
        try {
            $process->start();
            $iterator = $process->getIterator();
            /** @var string $type */
            /** @var string $data */
            foreach ($iterator as $type => $data) {
                foreach (explode("\n", $data) as $line) {
                    if (!empty(str_replace(' ', '', $line))) {
                        if ($this->conf->get(CONF::OUTPUT_RE_SEARCHES)) {
                            $this->search($line, $type, $this->conf);
                        }
                        $processOutput->newLine($type, $line);
                    }
                    $this->output[] = ["$type" => $line];
                }
            }
            $this->closeProcess($process, $this->conf, $processOutput, $logContext);
        } catch (ProcessTimedOutException $exception) {
            $timeout = 0 + $this->conf->get(CONF::TIMEOUT);
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
     * @param array<string,string> $logContext
     *
     * @return Process
     */
    protected function prepareProcess($command, &$logContext)
    {
        $opts = $this->conf;
        if ($opts->get(CONF::FIND_EXECUTABLE)) {
            $exe = $this->findExecutable($command[0]);
            $command[0] = $exe;
        }
        if ($opts->get(CONF::RUN_IN_SHELL)) {
            $process = Process::fromShellCommandline(implode(' ', $command));
        } else {
            $process = new Process($command);
        }
        if ($opts->get(CONF::ENV_VARS) || $opts->get(CONF::USE_APPENV) || $opts->get(CONF::USE_DOTENV)) {
            /** @var string $dir */
            $dir = $opts->get(CONF::DOTENV_DIR);
            $useAppEnv = (bool) $opts->get(CONF::USE_APPENV);
            $useDotEnv = (bool) $opts->get(CONF::USE_DOTENV);
            $vars = ProcessEnv::getConfigEnvVariables($dir, $useAppEnv, $useDotEnv, false, true);

            /** @var array<string,string> $envVars */
            $envVars = $opts->get(CONF::ENV_VARS);
            $execEnv = array_replace_recursive($vars, $envVars);
            $process->setEnv($execEnv);
        }
        $this->commandLine = $process->getCommandLine();
        $logContext['cmd'] = join(' ', $command);
        $timeout = 0 + $opts->get(CONF::TIMEOUT);
        $process->setTimeout($timeout);
        if ($opts->get(CONF::DIRECTORY) !== null) {
            $this->logger->debug("set working directory to ".$opts->get(CONF::DIRECTORY));
            $process->setWorkingDirectory(''.$opts->get(CONF::DIRECTORY));
        }
        $this->returnCode = 0;
        $ignore  = $opts->get(CONF::EXCEPTION_ON_ERROR) ? 'false' : 'true';
        $optsMsg = sprintf("ignore_errors=%s, timeout=%s", $ignore, $timeout);
        $this->logger->debug("Execute command ($optsMsg): {cmd}", $logContext);

        return $process;
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
    protected function closeProcess($process, $opts, $processOutput, $logContext)
    {
        $processOutput->closeProgress();
        if (!$process->isSuccessful()) {
            if ('on_error' === $opts->get(CONF::OUTPUT_MODE)) {
                $this->outputToLog($processOutput);
            }
            $processOutput->log('error', ''.$process->getExitCodeText());
            $this->returnCode = 0 + $process->getExitCode();
            if ($opts->get(CONF::EXCEPTION_ON_ERROR)) {
                $err = sprintf(
                    "error (code=%d) running command: %s",
                    $process->getExitCode(),
                    $process->getCommandLine()
                );
                /** @var array<integer>|null */
                $okErrors = $opts->get(CONF::EXIT_CODES_OK);
                if (null === $okErrors || !in_array($this->returnCode, $okErrors)) {
                    throw new ProcessException($err);
                }
                $processOutput->log('notice', "IGNORED: ".$err);
            }
        } else {
            if (array_key_exists('label', $logContext) && $logContext['label']) {
                $processOutput->log('notice', "{label} - command was successfull !");
            } else {
                $processOutput->log('notice', "command was successfull !");
            }
        }
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
     * Search line for matching regexp, set $this->matches accordingly
     *
     * @param string               $line
     * @param string|null          $type 'out' | 'err'
     * @param ProcessHelperOptions $opts

     * @return void
     */
    protected function search($line, $type, $opts)
    {
        /** @var array<array<string,string|null>> $searches */
        $searches = $opts->get(CONF::OUTPUT_RE_SEARCHES);
        foreach ($searches as $reSearch) {
            $name     = $reSearch['name'];
            $re       = $reSearch['regexp'];
            $searchIn = $reSearch['type'];

            $m     = [];
            if (preg_match("/$re/", $line, $m)) {
                if (null === $searchIn || $type === $searchIn) {
                    $this->matches["$name"][] = $m[1];
                }
            }
        }
    }
}
