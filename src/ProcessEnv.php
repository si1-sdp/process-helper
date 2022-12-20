<?php

declare(strict_types=1);

/*
 * This file is part of dgfip-si1/process-helper
 */

namespace DgfipSI1\ProcessHelper;

use Symfony\Component\Filesystem\Path;
use DgfipSI1\ProcessHelper\ConfigSchema as CONF;

/**
 * class ProcessEnv
 * manage environment variables
 */
class ProcessEnv
{
    /**
     * gets the execution environment variables according to configuration
     *
     * @param ProcessHelperOptions $opts
     *
     * @return array<string,string>
     */
    public static function getExecutionEnvironment(ProcessHelperOptions $opts)
    {
        // Handle environment
        /** @var array<string,string> $envVars */
        $envVars = $opts->get(CONF::ENV_VARS);
        $useAppEnv = (bool) $opts->get(CONF::USE_APPENV);
        $useDotEnv = (bool) $opts->get(CONF::USE_DOTENV);
        /** @var string $dir */
        $dir = $opts->get(CONF::DOTENV_DIR);
        $vars = ProcessEnv::getConfigEnvVariables($dir, $useAppEnv, $useDotEnv, false, true);
        $execEnv = array_replace_recursive($vars, $envVars);

        return $execEnv;
    }

    /**
     * @param string $rootDir     root directory
     * @param bool   $appEnv      if true, return application environement
     * @param bool   $dotEnv      if true, return variables from .env file
     * @param bool   $loadDotEnv  if true, load variables from .env file in the app environment
     * @param bool   $unsetAbsent if true, absent vars will be set to false and symfony process will not pass them
     *                            See Symfony process / environment vars at :
     *                            https://symfony.com/doc/current/components/process.html#setting-environment-variables-for-processes
     *                            To unset a var in environment, set its value to false
     *
     * @return array<string,string>
     */
    public static function getConfigEnvVariables(
        $rootDir,
        $appEnv = true,
        $dotEnv = true,
        $loadDotEnv = false,
        $unsetAbsent = false
    ) {
        $result = [];
        $absentKeys = [];
        if ($dotEnv && $loadDotEnv) {
            $result = \Dotenv\Dotenv::createImmutable($rootDir)->safeLoad();
        } else {
            $dotEnvVars = self::parseDotEnv($rootDir);
            if (true === $dotEnv) {
                $result = $dotEnvVars;
            } else {
                $absentKeys = array_keys($dotEnvVars);
            }
        }
        if (true === $appEnv) {
            $result = array_replace_recursive($result, $_SERVER + $_ENV);
        } else {
            $absentKeys = array_merge($absentKeys, array_keys($_SERVER + $_ENV));
        }
        if (true === $unsetAbsent) {
            // don't unset keys that are in both arrays
            $absentKeys = array_diff($absentKeys, array_keys($result));
            foreach ($absentKeys as $key) {
                $result[$key] = false;
            }
        }

        return $result;
    }
    /**
     * parseDotEnv file
     *
     * @param string $rootDir
     *
     * @return array<string,string|null>
     */
    private static function parseDotEnv($rootDir)
    {
        $dotEnvVars = [];
        $dotEnvFile = Path::join($rootDir, ".env");
        if (file_exists($dotEnvFile)) {
            /** @var string $content */
            $content = file_get_contents($dotEnvFile);
            $dotEnvVars = \Dotenv\Dotenv::parse($content);
        }

        return $dotEnvVars;
    }
}
