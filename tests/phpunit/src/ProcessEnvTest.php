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
        $this->assertEquals($expected, $dgfipEnv, "Simple merge failed");

        $dgfipEnv = ProcessEnv::getConfigEnvVariables($this->root->url(), true, false);
        $expected = [
            "BASE_DIR" => $this->root->url(),
            'var1'     => 'var1_value',
            'var2'     => 'var2_value',
        ];
        $this->assertEquals($expected, $dgfipEnv, "Only app_env test failed");

        $dgfipEnv = ProcessEnv::getConfigEnvVariables($this->root->url(), false, true);
        $expected = [
            'var2'     => 'var2_.env_value',
            'var3'     => 'var3_value',
        ];
        $this->assertEquals($expected, $dgfipEnv, "Only dot_env test failed");

        $dgfipEnv = ProcessEnv::getConfigEnvVariables($this->root->url(), false, true, false, true);
        $expected = [
            "BASE_DIR" => false,
            'var1'     => false,
            'var2'     => 'var2_.env_value',
            'var3'     => 'var3_value',

        ];
        $this->assertEquals($expected, $dgfipEnv, "setAbsentToFalse, only dot_env failed");

        $dgfipEnv = ProcessEnv::getConfigEnvVariables($this->root->url(), true, false, false, true);
        $expected = [
            "BASE_DIR" => $this->root->url(),
            'var1'     => 'var1_value',
            'var2'     => 'var2_value',
            'var3'     => false,

        ];
        $this->assertEquals($expected, $dgfipEnv, "setAbsentToFalse, only app_env failed");

        $dgfipEnv = ProcessEnv::getConfigEnvVariables($this->root->url(), false, false, false, true);
        $expected = [
            "BASE_DIR" => false,
            'var1'     => false,
            'var2'     => false,
            'var3'     => false,

        ];
        $this->assertEquals($expected, $dgfipEnv, "all shoud be false");

        $dgfipEnv = ProcessEnv::getConfigEnvVariables($this->root->url(), true, true, true, true);
        $expected = [
            "BASE_DIR" => $this->root->url(),
            'var1'     => 'var1_value',
            'var2'     => 'var2_value',
            'var3'     => 'var3_value',
        ];
        $this->assertEquals($expected, $dgfipEnv);
    }
}
