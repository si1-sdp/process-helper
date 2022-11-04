<?php

declare(strict_types=1);

/*
 * This file is part of dgfip-si1/process-helper
 */

namespace DgfipSI1\ProcessHelper;

use DgfipSI1\ConfigHelper\ConfigHelper;

/**
 * ProcessHelperOptions
 */
class ProcessHelperOptions extends ConfigHelper
{

    /**
     * Consructor
     *
     * @param array<string,mixed> $options
     */
    public function __construct($options = [])
    {
        parent::__construct(new ConfigSchema());
        $this->addArray(self::DEFAULT_CONTEXT, $options);
        $this->setActiveContext('command');
    }
}
