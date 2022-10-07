<?php
declare(strict_types=1);

/*
 * This file is part of drupalindus
 */

namespace jmg\processOutputTests;

use ReflectionClass;
use DgfipSI1\testLogger\TestLogger;
use DgfipSI1\ProcessHelper\ConfigSchema as CONF;
use DgfipSI1\ProcessHelper\ProcessHelperOptions;
use DgfipSI1\ProcessHelper\ProcessOutput;
use DgfipSI1\testLogger\LogTestCase;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * @covers \DgfipSI1\ProcessHelper\ProcessOutput
 *
 * @uses DgfipSI1\ProcessHelper\ProcessHelper
 * @uses DgfipSI1\ProcessHelper\ProcessHelperOptions
 * @uses \DgfipSI1\ProcessHelper\ConfigSchema
 */
class ProcessOutputTest extends LogTestCase
{
    /** @var ProgressBar  */
    protected $bar;
    /**
     * Test progressBar output
     */
    public function testProgressBar(): void
    {
        $this->runOutputTest('progress');
        $this->assertNotNull($this->bar);

        $this->assertEquals(3, $this->bar->getProgress());
        $this->assertEquals('line2', $this->bar->getMessage());
        $this->assertInfoInLog("line2");
        $this->assertWarningInLog("Warning !");
        $this->assertNoMoreProdMessages();
    }
    /**
     * Test silent
     */
    public function testSilentOutput(): void
    {
        $output = $this->runOutputTest('silent');
        $this->assertNull($this->bar);
        $this->assertNoMoreProdMessages();
    }
    /**
     * Test default
     */
    public function testDefaultOutput(): void
    {
        $this->runOutputTest('default');
        $this->assertNull($this->bar);
        $this->assertInfoInLog("line1");
        $this->assertInfoInLog("line2");
        $this->assertErrorInLog("error1");
        $this->assertWarningInLog("Warning !");
        $this->assertNoMoreProdMessages();
    }

    /**
     * Test on error
     */
    public function testOnErrorOutput(): void
    {
        $this->runOutputTest('on_error');
        $this->assertNull($this->bar);
        $this->assertInfoInLog("line3");
        $this->assertInfoInLog("line4");
        $this->assertWarningInLog("Warning !");
        $this->assertNoMoreProdMessages();
    }

    /**
     * init test
     *
     * @param string $mode
     *
     * @return ProcessOutput
     */
    protected function runOutputTest($mode)
    {
        $this->logger = new TestLogger();
        $opts   = new ProcessHelperOptions([CONF::OUTPUT_MODE => $mode]);
        $output = new ProcessOutput($opts, $this->logger, []);
        $reflector = new ReflectionClass('DgfipSI1\ProcessHelper\ProcessOutput');
        $prop = $reflector->getProperty('bar');
        $prop->setAccessible(true);
        /** @var ProgressBar $bar */
        $bar = $prop->getValue($output);
        $this->bar = $bar;
        $output->newLine('out', 'line1');
        $output->newLine('err', 'error1');
        $output->newLine('out', 'line2');
        $output->closeProgress();
        $output->log('warning', 'Warning !');
        if ('on_error' === $mode) {
            $output->newLine('out', 'line3', true);
            $output->newLine('out', 'line4', true);
        }

        return $output;
    }
}
