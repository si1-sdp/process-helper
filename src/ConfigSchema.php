<?php
/*
 * This file is part of dgfip-si1/process-helper
 */

namespace DgfipSI1\ProcessHelper;

use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration schema for processHelper
 */
class ConfigSchema implements ConfigurationInterface
{
    public const RUN_IN_SHELL            = 'run_in_shell';
    public const TIMEOUT                 = 'timeout';
    public const DRY_RUN                 = 'dry_run';
    public const FIND_EXECUTABLE         = 'find_executable';
    public const DIRECTORY               = 'directory';

    // OUTPUT PROCESSING
    const OUTPUT_OPTIONS_LIST     = [ 'silent', 'progress', 'default', 'on_error', 'custom'];
    private const OUTPUT_GROUP            = 'output';
    private const OUTPUT_MODE_OPT         = 'mode';
    private const OUTPUT_STDOUT_TO_OPT    = 'stdout_to';
    private const OUTPUT_STDERR_TO_OPT    = 'stderr_to';
    public const OUTPUT_MODE             = self::OUTPUT_GROUP.'.'.self::OUTPUT_MODE_OPT;
    public const OUTPUT_STDOUT_TO        = self::OUTPUT_GROUP.'.'.self::OUTPUT_STDOUT_TO_OPT;
    public const OUTPUT_STDERR_TO        = self::OUTPUT_GROUP.'.'.self::OUTPUT_STDERR_TO_OPT;

    // ENVIRONMENT VARIABLES
    private const ENVIRONMENT_GROUP       = 'environment';
    private const ENV_VARS_OPT            = 'extra_vars';
    private const USE_DOTENV_OPT          = 'use_dotenv_vars';
    private const DOTENV_DIR_OPT          = 'dotenv_dir';
    private const USE_APPENV_OPT          = 'use_appenv_vars';

    public const ENV_VARS                = self::ENVIRONMENT_GROUP.'.'.self::ENV_VARS_OPT;
    public const USE_DOTENV              = self::ENVIRONMENT_GROUP.'.'.self::USE_DOTENV_OPT;
    public const DOTENV_DIR              = self::ENVIRONMENT_GROUP.'.'.self::DOTENV_DIR_OPT;
    public const USE_APPENV              = self::ENVIRONMENT_GROUP.'.'.self::USE_APPENV_OPT;

    public const OUTPUT_RE_SEARCHES      = 'output_re_searches';

    // EXCEPTION HANDLING
    private const EXCEPTIONS_GROUP        = 'exceptions';
    private const EXCEPTION_ON_ERROR_OPT  = 'throw_on_error';
    private const EXIT_CODES_OK_OPT       = 'exit_codes_ok';
    const EXCEPTION_ON_ERROR              = self::EXCEPTIONS_GROUP.'.'.self::EXCEPTION_ON_ERROR_OPT;
    const EXIT_CODES_OK                   = self::EXCEPTIONS_GROUP.'.'.self::EXIT_CODES_OK_OPT;

    /**
     * The main configuration tree
     *
     * @return TreeBuilder
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('process');
        $treeBuilder->getRootNode()->children()
                ->booleanNode(self::RUN_IN_SHELL)->defaultValue(false)
                    ->info("If true, run process in shell (see symfony/process documentation).")->end()
                ->integerNode(self::TIMEOUT)->defaultValue(60)->min(1)
                    ->info("symfony process timeout in seconds.")->end()
                ->booleanNode(self::DRY_RUN)->defaultValue(false)
                    ->info("Do not run process, just log command line.")->end()
                ->booleanNode(self::FIND_EXECUTABLE)->defaultValue(false)
                    ->info("Try to locate executable (via 'which' command)")->end()
                ->scalarNode(self::DIRECTORY)->info("Directory where process shoud run")->end()
                ->append($this->outputConfig())
                ->append($this->envConfig())
                ->append($this->exceptionsConfig())
                ->append($this->outputSearchConfig())
            ->end();

        return $treeBuilder;
    }
    /**
     * The 'output' configuration branch
     *
     * @return NodeDefinition
     */
    public function outputConfig()
    {
        $treeBuilder = new TreeBuilder(self::OUTPUT_GROUP);
        $logChannels = [ "emergency", "alert", "critical", "error", "warning", "notice", "info", "debug" ];
        $node = $treeBuilder->getRootNode()
            ->info("Output options")
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode(self::OUTPUT_MODE_OPT)->defaultValue('default')
                    ->values(['silent', 'progress', 'default', 'on_error'])
                    ->info("How should we display process output. 'silent', 'progress', 'default' or 'on_error'.")
                    ->end()
                ->enumNode(self::OUTPUT_STDOUT_TO_OPT)->defaultValue('info')->values($logChannels)
                    ->info("Log channel to send stdout process output. Possible values : see psr/log.")->end()
                ->enumNode(self::OUTPUT_STDERR_TO_OPT)->defaultValue('error')->values($logChannels)
                    ->info("Log channel to send stderr process output. Possible values : see psr/log.")->end()
            ->end();

        return $node;
    }
    /**
     * The 'environment' configuration branch
     *
     * @return NodeDefinition
     */
    public function envConfig()
    {
        $treeBuilder = new TreeBuilder(self::ENVIRONMENT_GROUP);
        $node = $treeBuilder->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode(self::ENV_VARS_OPT)
                    ->useAttributeAsKey('name')
                    ->prototype('scalar')->end()
                    ->info("array of variable names and values to pass as environment for the process.")
                    ->end()     // arrayNode
                ->booleanNode(self::USE_APPENV_OPT)->defaultValue(false)
                    ->info('Add vars in $_SERVER and $_ENV to process environment')->end()
                ->booleanNode(self::USE_DOTENV_OPT)->defaultValue(false)
                    ->info("Add vars in .env file to process environment")->end()
                ->scalarNode(self::DOTENV_DIR_OPT)->defaultValue('.')
                    ->info('directory where .env file is located')->end()
            ->end();

            return $node;
    }
    /**
     * The 'exceptions' configuration branch
     *
     * @return NodeDefinition
     */
    public function exceptionsConfig()
    {
        $treeBuilder = new TreeBuilder(self::EXCEPTIONS_GROUP);
        $node = $treeBuilder->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode(self::EXCEPTION_ON_ERROR_OPT)->defaultValue(true)
                    ->info("Raise exception if process->isSuccessful returns false")->end()
                ->arrayNode(self::EXIT_CODES_OK_OPT)
                    ->integerPrototype()->end()
                    ->info('return codes that will not raise an exception if exception-on-error is set to true.')
                    ->end()
            ->end();

            return $node;
    }

    /**
     * The 'output search' configuration branch
     *
     * @return NodeDefinition
     */
    public function outputSearchConfig()
    {
        $treeBuilder = new TreeBuilder('output_re_searches');
        $node = $treeBuilder->getRootNode()
            ->arrayPrototype()
                ->children()
                    ->scalarNode('name')->isRequired()->cannotBeEmpty()
                        ->info('docker base image for testing repository')->end()
                    ->scalarNode('regexp')->isRequired()->cannotBeEmpty()
                        ->info('')->end()
                    ->end() // children
                ->end();

        return $node;
    }
}
