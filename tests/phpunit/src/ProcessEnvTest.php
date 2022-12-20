<?php
declare(strict_types=1);

/*
 * This file is part of drupalindus
 */

namespace jmg\processHelperTests;

use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use DgfipSI1\ProcessHelper\ProcessEnv;
use DgfipSI1\ProcessHelper\ProcessHelperOptions;
use DgfipSI1\ProcessHelper\ConfigSchema as CONF;

/**
 * @covers \DgfipSI1\ProcessHelper\ProcessEnv
 *
 * @uses DgfipSI1\ProcessHelper\ProcessHelper
 * @uses DgfipSI1\ProcessHelper\ProcessHelperOptions
 * @uses \DgfipSI1\ProcessHelper\ConfigSchema
 */
class ProcessEnvTest extends TestCase
{
    /** @var vfsStreamDirectory */
    private $root;

    /** setup a VfsStream filesystem with /conf/satis_dgfip.yaml
     *
     * {@inheritDoc}
     *
     * @see \PHPUnit\Framework\TestCase::setUp()
     */
    protected function setUp(): void
    {
        $this->root = vfsStream::setup();
    }
    /**
     * data provider for testGetExecutionEnvironment
     *
     * @return array<string,array<mixed>>
     */
    public function getExecEnvData()
    {
        return [                    // extra  app    dot
            'extra:N-app:N:dot:N' => [ false, false, false],
            'extra:Y-app:N:dot:N' => [ true,  false, false],
            'extra:N-app:Y:dot:N' => [ false, true,  false],
            'extra:Y-app:Y:dot:N' => [ true,  true,  false],
            'extra:N-app:N:dot:Y' => [ false, false, true ],
            'extra:Y-app:N:dot:Y' => [ true,  false, true ],
            'extra:N-app:Y:dot:Y' => [ false, true,  true ],
            'extra:Y-app:Y:dot:Y' => [ true,  true,  true ],
        ];
    }
    /**
     * @dataProvider getExecEnvData
     *
     * @param bool $extra
     * @param bool $app
     * @param bool $dot
     *
     * @return void
     */
    public function testGetExecutionEnvironment($extra, $app, $dot)
    {
        $options = [ CONF::USE_APPENV => $app, CONF::USE_DOTENV => $dot];
        if ($extra) {
            $options[CONF::ENV_VARS] = [ 'extra_var' => 'extra_value' ];
        }
        if ($dot) {
            $options[CONF::DOTENV_DIR] = $this->root->url();
            file_put_contents($this->root->url().'/.env', "dot_var = dot_value\n");
        }
        $_ENV['app_var'] = 'app_value';
        $pho = new ProcessHelperOptions($options);
        $env = ProcessEnv::getExecutionEnvironment($pho);
        if ($extra) {
            self::assertArrayHasKey('extra_var', $env);
            self::assertEquals('extra_value', $env['extra_var']);
        }
        if ($dot) {
            self::assertArrayHasKey('dot_var', $env);
            self::assertEquals('dot_value', $env['dot_var']);
        }
        if ($app) {
            self::assertArrayHasKey('app_var', $env);
            self::assertEquals('app_value', $env['app_var']);
        } else {
            self::assertArrayHasKey('app_var', $env);
            self::assertEquals(false, $env['app_var']);
        }
    }
    /**
     * Test getGetConfigEnvVariables method
     */
    public function testGetConfigEnvVariables(): void
    {
        $_SERVER = ["BASE_DIR" => $this->root->url() ];
        $_ENV = [ 'var1' => 'var1_value', 'var2' => 'var2_value', ];                                // APPENV: var1,var2
        file_put_contents($this->root->url()."/.env", "var2 = var2_.env_value\nvar3 = var3_value"); // DOTENV: var2,var3

        $dgfipEnv = ProcessEnv::getConfigEnvVariables($this->root->url());
        $expected = [
            "BASE_DIR" => $this->root->url(),
            'var1'     => 'var1_value',
            'var2'     => 'var2_value',
            'var3'     => 'var3_value',
        ];
        self::assertEquals($expected, $dgfipEnv, "Simple merge failed");

        $dgfipEnv = ProcessEnv::getConfigEnvVariables($this->root->url(), true, false);
        $expected = [
            "BASE_DIR" => $this->root->url(),
            'var1'     => 'var1_value',
            'var2'     => 'var2_value',
        ];
        self::assertEquals($expected, $dgfipEnv, "Only app_env test failed");

        $dgfipEnv = ProcessEnv::getConfigEnvVariables($this->root->url(), false, true);
        $expected = [
            'var2'     => 'var2_.env_value',
            'var3'     => 'var3_value',
        ];
        self::assertEquals($expected, $dgfipEnv, "Only dot_env test failed");

        $dgfipEnv = ProcessEnv::getConfigEnvVariables($this->root->url(), false, true, false, true);
        $expected = [
            "BASE_DIR" => false,
            'var1'     => false,
            'var2'     => 'var2_.env_value',
            'var3'     => 'var3_value',

        ];
        self::assertEquals($expected, $dgfipEnv, "setAbsentToFalse, only dot_env failed");

        $dgfipEnv = ProcessEnv::getConfigEnvVariables($this->root->url(), true, false, false, true);
        $expected = [
            "BASE_DIR" => $this->root->url(),
            'var1'     => 'var1_value',
            'var2'     => 'var2_value',
            'var3'     => false,

        ];
        self::assertEquals($expected, $dgfipEnv, "setAbsentToFalse, only app_env failed");

        $dgfipEnv = ProcessEnv::getConfigEnvVariables($this->root->url(), false, false, false, true);
        $expected = [
            "BASE_DIR" => false,
            'var1'     => false,
            'var2'     => false,
            'var3'     => false,

        ];
        self::assertEquals($expected, $dgfipEnv, "all shoud be false");

        $dgfipEnv = ProcessEnv::getConfigEnvVariables($this->root->url(), true, true, true, true);
        $expected = [
            "BASE_DIR" => $this->root->url(),
            'var1'     => 'var1_value',
            'var2'     => 'var2_value',
            'var3'     => 'var3_value',
        ];
        self::assertEquals($expected, $dgfipEnv);
    }
}
