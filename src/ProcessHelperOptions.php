<?php

declare(strict_types=1);

/*
 * This file is part of deslp
 */

namespace DgfipSI1\ProcessHelper;

use DgfipSI1\ConfigTree\ConfigTree;

/**
 * ProcessHelperOptions
 */
class ProcessHelperOptions extends ConfigTree
{
    const RUN_IN_SHELL            = 'run-in-shell';
    const TIMEOUT                 = 'timeout';
    const DRY_RUN                 = 'dry-run';
    const FIND_EXECUTABLE         = 'find-executable';
    const DIRECTORY               = 'directory';

    // OUTPUT PROCESSING
    const OUTPUT_OPTIONS_LIST     = [ 'silent', 'progress', 'default', 'on_error', 'custom'];
    const OUTPUT_MODE             = 'output.mode';
    const OUTPUT_STDOUT_TO        = 'output.stdout-to';
    const OUTPUT_STDERR_TO        = 'output.stderr-to';

    // ENVIRONMENT VARIABLES
    const ENV_VARS                = 'environment.extra-vars';
    const USE_DOTENV              = 'environment.use-dotenv-vars';
    const DOTENV_DIR              = 'environment.dotenv-dir';
    const USE_APPENV              = 'environment.use-appenv-vars';

    const OUTPUT_RE_SEARCHES      = 'output-re-searches';
    const EXCEPTION_ON_ERROR      = 'exceptions.throw-on-error';
    const EXIT_CODES_OK           = 'exceptions.exit-codes-ok';
    /**
     * Consructor
     *
     * @param array<string,mixed> $options
     */
    public function __construct($options = [])
    {
        $schemaFile = dirname(__FILE__)."/../res/optionSchema.json";
        parent::__construct($schemaFile, $options);
    }
}
