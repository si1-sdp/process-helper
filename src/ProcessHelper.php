<?php

declare(strict_types=1);

/*
 * This file is part of deslp
 */

namespace DgfipSI1\ProcessHelper;

use DateTime;
use mef\Stringifier\Stringifier;
use mef\StringInterpolation\PlaceholderInterpolator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use DgfipSI1\ProcessHelper\ProcessHelperOptions as PHO;

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
    protected $globalOptions;

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
        $this->globalOptions->set(PHO::TIMEOUT, $timeout);

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
        $this->globalOptions->set(PHO::RUN_IN_SHELL, $runInShell);

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
        if (!in_array($output, PHO::OUTPUT_OPTIONS_LIST)) {
            throw new \Exception(sprintf("Output option '%s' does not exists", $output));
        }
        foreach ([$stdout, $stderr] as $channel) {
            if (!defined("Psr\Log\LogLevel::".strtoupper($channel))) {
                throw new \Exception(sprintf("Unavailable output channel '%s'.", $channel));
            }
        }
        $this->globalOptions->set(PHO::OUTPUT_MODE, $output);
        $this->globalOptions->set(PHO::OUTPUT_STDERR_TO, $stderr);
        $this->globalOptions->set(PHO::OUTPUT_STDOUT_TO, $stdout);
//        $this->globalOptions->check();

        return $this;
    }
    /**
     * Add a regexp search on output
     *
     * @param string $name
     * @param string $re
     *
     * @return void
     */
    public function addSearch($name, $re)
    {
        /** @var array<string,mixed> */
        $searches = $this->globalOptions->get(PHO::OUTPUT_RE_SEARCHES);
        $searches[] = [ 'name' => $name, 'regexp' => $re];
        $this->globalOptions->set(PHO::OUTPUT_RE_SEARCHES, $searches);
        $this->matches[$name] = [];
        $this->globalOptions->check();
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
            throw new \Exception(sprintf($err, $name));
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
                throw new \Exception(sprintf("resetMatches: No match on variable '%s'", $name));
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
            throw new \Exception(sprintf("ProcessHelper:getOutput: Unkown type %s", $type));
        }
        $filterOnType = function ($v) use ($type) {
            if ('out' === $type or 'err' === $type) {
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
        $escapedName = str_replace("'", "", $name); // TODO : make it nicer..
        try {
            $options = [
                PHO::FIND_EXECUTABLE    => false,
                PHO::EXCEPTION_ON_ERROR => true,
                PHO::OUTPUT_MODE        => 'silent',
                PHO::RUN_IN_SHELL       => true,
            ];
            $this->execCommand(['which', $escapedName], [], $options);
            $firstLine = $this->getOutput()[0];
            if (strlen($firstLine) !== 0) {
                $firstLine = Path::canonicalize($firstLine);
            }

            return $firstLine;
        } catch (\Exception $e) {
            throw new \Exception(sprintf("executable '%s' not found", $escapedName));
        }
    }

    /**
     * executes a command
     *
     * @param array<string>        $command
     * @param array<string,string> $logContext
     * @param array<string,mixed>  $commandOptions
     *
     * @return int
     */
    public function execCommand($command, $logContext = [], $commandOptions = [])
    {
        /** @var ProcessHelperOptions $opts */
        $opts = clone $this->globalOptions;
        $opts->merge($commandOptions);
        /** @var string $dir */
        $dir = $opts->get(PHO::DOTENV_DIR);
        $useAppEnv = $opts->get(PHO::USE_APPENV) ? true : false;
        $useDotEnv = $opts->get(PHO::USE_DOTENV) ? true : false;
        $vars = ProcessEnv::getConfigEnvVariables($dir, $useAppEnv, $useDotEnv, false, true);
        if ($opts->get(PHO::FIND_EXECUTABLE)) {
            $exe = $this->findExecutable($command[0]);
            $command[0] = $exe;
        }
        if ($opts->get(PHO::RUN_IN_SHELL)) {
            $process = Process::fromShellCommandline(implode(' ', $command));
        } else {
            $process = new Process($command);
        }
        if ($opts->get(PHO::ENV_VARS) || $opts->get(PHO::USE_APPENV) || $opts->get(PHO::USE_DOTENV)) {
            /** @var array<string,string> $envVars */
            $envVars = $opts->get(PHO::ENV_VARS);
            $execEnv = array_replace_recursive($vars, $envVars);
            $process->setEnv($execEnv);
        }
        $this->commandLine = $process->getCommandLine();
        $logContext['cmd'] = join(' ', $command);
        $timeout = 0 + $opts->get(PHO::TIMEOUT);
        $process->setTimeout($timeout);
        if ($opts->get(PHO::DIRECTORY) !== null) {
            $this->logger->debug("set working directory to ".$opts->get(PHO::DIRECTORY));
            $process->setWorkingDirectory(''.$opts->get(PHO::DIRECTORY));
        }
        $this->returnCode = 0;
        $ignore  = $opts->get(PHO::EXCEPTION_ON_ERROR) ? 'false' : 'true';
        $optsMsg = sprintf("ignore_errors=%s, timeout=%s", $ignore, $timeout);
        $this->logger->debug("Execute command ($optsMsg): {cmd}", $logContext);
        if ($opts->get(PHO::DRY_RUN)) {
            $this->logger->notice("DRY-RUN - execute command {cmd}", $logContext);

            return 0;
        }
        try {
            $lastLine = '';
            $process->start();
            $progressBar = null;
            if ('progress' === $opts->get(PHO::OUTPUT_MODE)) {
                $progressBar = new ProgressBar(new ConsoleOutput());
                $progressBar->setFormat('%elapsed% [%bar%] %message%');
            }
            $iterator = $process->getIterator();
            /** @var string $type */
            /** @var string $data */
            foreach ($iterator as $type => $data) {
                foreach (explode("\n", $data) as $line) {
                    if (!empty(str_replace(' ', '', $line))) {
                        if ($opts->get(PHO::OUTPUT_RE_SEARCHES)) {
                            $this->search($line, $type, $opts);
                        }
                        if ($progressBar) {
                            $progressBar->advance();
                            $progressBar->setMessage(substr($line, 0, 80));
                        } else {
                            $this->logOutput($type, $line, $opts, $logContext);
                        }
                        $lastLine = $line;
                    }
                    $this->output[] = ["$type" => $line];
                }
            }
            if ($progressBar) {
                $progressBar->clear();
                $this->logger->notice($lastLine, $logContext);
            }
            if (!$process->isSuccessful()) {
                if ('on_error' === $opts->get(PHO::OUTPUT_MODE)) {
                    foreach ($this->output as $line) {
                        foreach ($line as $type => $msg) {
                            $this->logOutput($type, $msg, $opts, $logContext, true);
                        }
                    }
                }
                $this->logger->error(''.$process->getExitCodeText(), $logContext);
                $this->returnCode = 0 + $process->getExitCode();
                if ($opts->get(PHO::EXCEPTION_ON_ERROR)) {
                    $err = sprintf(
                        "error (code=%d) running command: %s",
                        $process->getExitCode(),
                        $process->getCommandLine()
                    );
                    /** @var array<integer>|null */
                    $okErrors = $opts->get(PHO::EXIT_CODES_OK);
                    if (null === $okErrors || !in_array($this->returnCode, $okErrors)) {
                        throw new \Exception($err);
                    }
                    $this->logger->notice("IGNORED: ".$err);
                }
            } else {
                if ($opts->get(PHO::OUTPUT_MODE) !== 'silent') {
                    $this->logger->notice("command was successfull !", $logContext);
                }
            }
        } catch (ProcessTimedOutException $exception) {
            $this->logger->error(sprintf("Timeout : job exeeded timeout of %d seconds", $timeout), $logContext);
            $this->returnCode = 160;
        }

        return $this->returnCode;
    }
    /**
     * @param string               $type
     * @param string               $line
     * @param ProcessHelperOptions $opts
     * @param array<string,string> $logContext
     * @param bool                 $errorHappened
     *
     * @return void
     */
    protected function logOutput($type, $line, $opts, $logContext, $errorHappened = false)
    {
        if ($opts->get(PHO::OUTPUT_MODE) === 'silent' or
           ($opts->get(PHO::OUTPUT_MODE) === 'on_error' and !$errorHappened)) {
            return;
        }
        if ('err' === $type) {
            $channel = $opts->get(PHO::OUTPUT_STDERR_TO);
        } else {
            $channel = $opts->get(PHO::OUTPUT_STDOUT_TO);
        }
        $this->logger->$channel("$line", $logContext);
    }
    /**
     * Search line for matching regexp, set $this->matches accordingly
     *
     * @param string               $line
     * @param string               $type 'out' | 'err'
     * @param ProcessHelperOptions $opts

     * @return void
     */
    protected function search($line, $type, $opts)
    {
        /** @var array<array<string,string>> $searches */
        $searches = $opts->get(PHO::OUTPUT_RE_SEARCHES);
        foreach ($searches as $reSearch) {
            $name  = $reSearch['name'];
            $re    = $reSearch['regexp'];
            $matches = [];
            if (preg_match("/$re/", $line, $matches)) {
                $this->matches["$name"][] = $matches[1];
            }
        }
    }
}
