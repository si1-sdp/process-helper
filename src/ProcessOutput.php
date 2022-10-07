<?php

declare(strict_types=1);

/*
 * This file is part of dgfip-si1/process-helper
 */

namespace DgfipSI1\ProcessHelper;

use Symfony\Component\Console\Helper\ProgressBar as HelperProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;
use DgfipSI1\ProcessHelper\ConfigSchema as CONF;
use Psr\Log\LoggerInterface;

/**
 * ProcessHelperOptions
 */
class ProcessOutput
{
    /** @var HelperProgressBar|null $bar */
    protected $bar;

    /** @var LoggerInterface $logger */
    protected $logger;

    /** @var string $outputMode */
    protected $outputMode;

    /** @var string $errChannel */
    protected $errChannel;

    /** @var string $outChannel */
    protected $outChannel;

    /** @var array<string,string> $logContext */
    protected $logContext;

    /** @var string $lastLine */
    protected $lastLine;
    /**
     * constructor
     *
     * @param ProcessHelperOptions $opts
     * @param LoggerInterface      $logger
     * @param array<string,string> $logContext
     *
     * @return void
     */
    public function __construct($opts, $logger, $logContext)
    {
        $this->outputMode = "".$opts->get(CONF::OUTPUT_MODE);

        $this->errChannel = "".$opts->get(CONF::OUTPUT_STDERR_TO);
        $this->outChannel = "".$opts->get(CONF::OUTPUT_STDOUT_TO);
        $this->logger     = $logger;
        $this->logContext = $logContext;
        if ('progress' === $this->outputMode) {
            $this->bar = new HelperProgressBar(new ConsoleOutput());
            $this->bar->setFormat('%elapsed% [%bar%] %message%');
        } else {
            $this->bar = null;
        }
    }
    /**
     * Manage a line from process output
     *
     * @param string $type
     * @param string $line
     * @param bool   $errorHappened
     *
     * @return void
     */
    public function newLine($type, $line, $errorHappened = false)
    {
        $this->lastLine = $line;
        if ($this->bar) {
            $this->bar->advance();
            $this->bar->setMessage(substr($line, 0, 80));
        } else {
            $this->logOutput($type, $line, $errorHappened);
        }
    }
    /**
     * Logs a message
     *
     * @param string $level
     * @param string $line
     *
     * @return void
     */
    public function log($level, $line)
    {
        if ($this->outputMode !== 'silent') {
            $this->logger->$level("$line", $this->logContext);
        }
    }
    /**
     * Close progress bar if needed
     *
     * @return void
     *
     */
    public function closeProgress()
    {
        if ($this->bar) {
            $this->bar->clear();
            $this->logOutput('out', $this->lastLine);
        }
    }
    /**
     * @param string $type
     * @param string $line
     * @param bool   $errorHappened
     *
     * @return void
     */
    public function logOutput($type, $line, $errorHappened = false)
    {
        if ($this->outputMode === 'silent' || $this->outputMode === 'on_error' && !$errorHappened) {
            return;
        }
        if ('err' === $type) {
            $channel = $this->errChannel;
        } else {
            $channel = $this->outChannel;
        }
        $this->logger->$channel("$line", $this->logContext);
    }
}
