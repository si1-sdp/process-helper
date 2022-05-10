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
class ProcessHelperOptions
{
    const PH_RUN_IN_SHELL       = 'run-in-shell';
    const PH_TIMEOUT            = 'timeout';
    const PH_DRY_RUN            = 'dry-run';
    const PH_FIND_EXECUTABLE    = 'find-executable';
    const PH_DIRECTORY          = 'directory';
    const PH_ENV_VARS           = 'environment';

    const PH_DISPLAY_PROGRESS   = 'display-progress';

    const PH_DISABLE_OUTPUT          = 'disable-output';
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
    const PH_EXCEPTION_ON_ERROR      = 'exception-on-error';
    const PH_EXIT_CODES_OK           = 'exit-codes-ok';

    const DEFAULT_OPTIONS = [
        self::PH_RUN_IN_SHELL           => false,
        self::PH_TIMEOUT                => 60,
        self::PH_DRY_RUN                => false,
        self::PH_FIND_EXECUTABLE        => false,
        self::PH_DIRECTORY              => '',
        self::PH_ENV_VARS               => [],

        self::PH_DISPLAY_PROGRESS       => false,     // OK
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
        self::PH_EXCEPTION_ON_ERROR     => false,
        self::PH_EXIT_CODES_OK          => [],
    ];

    /** @var array<string,mixed> */
    protected $options;

    /**
     * Constructor
     *
     * @param array<string,mixed> $options
     *
     * @return void
     */
    public function __construct($options = [])
    {
        $this->options = self::DEFAULT_OPTIONS;
        $this->mergeOptions($options);
    }
    /**
     * Undocumented function
     *
     * @param array<string,mixed> $options
     *
     * @return ProcessHelperOptions
     */
    public function merge($options)
    {
        $newOpts = clone $this;
        $newOpts->mergeOptions($options);

        return $newOpts;
    }
    /**
     * getters
     *
     * @param string $name
     *
     * @return mixed
     */
    public function get(string $name)
    {
        if (array_key_exists($name, $this->options)) {
            return $this->options[$name];
        }
        throw new \Exception(sprintf("ProcessHelperOptions->get() : Unknown option '%s'", $name));
    }
    /**
     * Setters
     *
     * @param string $name
     * @param mixed  $value
     *
     * @return ProcessHelperOptions
     */
    public function set(string $name, mixed $value)
    {
        if (array_key_exists($name, $this->options)) {
            $this->options[$name] = $value;
        } else {
            throw new \Exception(sprintf("ProcessHelperOptions->set() : Unknown option '%s'", $name));
        }

        return $this;
    }
    /**
     * merge options given to target array
     *
     * @param array<string,array<mixed>|bool|int|string> $options
     *
     * @return void
    */
    protected function mergeOptions($options)
    {
        $validOptions = $this->getValidOptions();
        foreach ($options as $optName => $optValue) {
            if (!in_array($optName, $validOptions)) {
                throw new \Exception(sprintf("Unknown option : '%s'", $optName));
            }
            $this->options[$optName] = $optValue;
        }
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
}
