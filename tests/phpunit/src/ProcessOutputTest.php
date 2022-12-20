<?php
declare(strict_types=1);

/*
 * This file is part of drupalindus
 */

namespace jmg\processOutputTests;

use \Mockery;
use ReflectionClass;
use DgfipSI1\testLogger\TestLogger;
use DgfipSI1\ProcessHelper\ConfigSchema as CONF;
use DgfipSI1\ProcessHelper\ProcessHelperOptions;
use DgfipSI1\ProcessHelper\ProcessOutput;
use DgfipSI1\testLogger\LogTestCase;
use ReflectionProperty;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * @uses \DgfipSI1\ProcessHelper\ProcessOutput
 * @uses DgfipSI1\ProcessHelper\ProcessHelper
 * @uses DgfipSI1\ProcessHelper\ProcessHelperOptions
 * @uses \DgfipSI1\ProcessHelper\ConfigSchema
 */
class ProcessOutputTest extends LogTestCase
{

    /** @var ProgressBar  */
    protected $bar;
    /** @var ReflectionProperty $logprop */
    protected $logprop;
    /** @var ReflectionProperty $barprop */
    protected $barprop;
    /** @var ReflectionProperty $omprop */
    protected $omprop;
    /** @var ReflectionProperty $errprop */
    protected $errprop;
    /** @var ReflectionProperty $outprop */
    protected $outprop;
    /** @var ReflectionProperty $lcprop */
    protected $lcprop;
    /** @var ReflectionProperty $llprop */
    protected $llprop;
   /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        $ref = new ReflectionClass(ProcessOutput::class);
        $this->logprop = $ref->getProperty('logger');
        $this->logprop->setAccessible(true);

        $this->barprop = $ref->getProperty('bar');
        $this->barprop->setAccessible(true);

        $this->omprop = $ref->getProperty('outputMode');
        $this->omprop->setAccessible(true);

        $this->errprop = $ref->getProperty('errChannel');
        $this->errprop->setAccessible(true);

        $this->outprop = $ref->getProperty('outChannel');
        $this->outprop->setAccessible(true);

        $this->lcprop = $ref->getProperty('logContext');
        $this->lcprop->setAccessible(true);

        $this->llprop = $ref->getProperty('lastLine');
        $this->llprop->setAccessible(true);

        $this->logger = new TestLogger();
    }
    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        \Mockery::close();
    }

    /**
     * @covers \DgfipSI1\ProcessHelper\ProcessOutput::__construct
     * Test constructor
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function testConstructorWithProgress(): void
    {
        /** @var Mockery\Mock $m */
        $m = \Mockery::mock('overload:Symfony\Component\Console\Helper\ProgressBar')->makePartial();
        $m->shouldReceive('setFormat')->once();           /** @phpstan-ignore-line */

        $conf = new ProcessHelperOptions([CONF::OUTPUT_MODE => 'progress']);
        $this->logger = new TestLogger();
        $po = new ProcessOutput($conf, $this->logger, []);

        self::assertNotNull($this->barprop->getValue($po));
        self::assertEquals($this->logger, $this->logprop->getValue($po));
        self::assertEquals('progress', $this->omprop->getValue($po));
        self::assertEquals('info', $this->outprop->getValue($po));
        self::assertEquals('error', $this->errprop->getValue($po));
        self::assertEquals([], $this->lcprop->getValue($po));
        $this->assertLogEmpty();
    }
    /**
     * @covers \DgfipSI1\ProcessHelper\ProcessOutput::__construct
     * Test constructor
     */
    public function testConstructorWithoutProgress(): void
    {
        $conf = new ProcessHelperOptions([
            CONF::OUTPUT_MODE => 'silent',
            CONF::OUTPUT_STDERR_TO => 'alert',
            CONF::OUTPUT_STDOUT_TO => 'notice',
        ]);
        $po = new ProcessOutput($conf, $this->logger, ['name' => 'test']);

        self::assertNull($this->barprop->getValue($po));
        self::assertEquals($this->logger, $this->logprop->getValue($po));
        self::assertEquals('notice', $this->outprop->getValue($po));
        self::assertEquals('alert', $this->errprop->getValue($po));
        self::assertEquals(['name' => 'test'], $this->lcprop->getValue($po));
        $this->assertLogEmpty();
    }

    /**
     * @covers \DgfipSI1\ProcessHelper\ProcessOutput::newLine
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled

     */
    public function testnewLine(): void
    {
        $po = new ProcessOutput(new ProcessHelperOptions([]), $this->logger, []);
        $this->omprop->setValue($po, 'on_error');
        $po->newLine('out', 'Message');
        $this->assertLogEmpty();
        $po->newLine('out', 'Message', true);
        $this->assertInfoInLog('Message');
        $this->assertLogEmpty();

        /** Mock $bar */
        $bar = $this->mockBar($po);
        $message = "12345678 1 2345678 2 2345678 3 2345678 4 2345678 5 2345678 6 2345678 7 2345678 8 23456";
        $bar->shouldReceive('advance')->once();                                   /** @phpstan-ignore-line  */
        $bar->shouldReceive('setMessage')->with(substr($message, 0, 80))->once(); /** @phpstan-ignore-line  */
        $po->newLine('out', $message);
        $this->assertLogEmpty();
    }

    /**
     * @covers \DgfipSI1\ProcessHelper\ProcessOutput::log
     */
    public function testLog(): void
    {
        $po = new ProcessOutput(new ProcessHelperOptions([]), $this->logger, []);
        $po->log('info', 'Message');
        $this->assertInfoInLog('Message');
        $this->assertLogEmpty();

        $this->omprop->setValue($po, 'silent');
        $po->log('info', 'Message');
        $this->assertLogEmpty();
    }

    /**
     * @covers \DgfipSI1\ProcessHelper\ProcessOutput::closeProgress
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled

     */
    public function testcloseProgress(): void
    {
        $po = new ProcessOutput(new ProcessHelperOptions([]), $this->logger, []);
        $this->llprop->setValue($po, 'Last line');
        $po->closeProgress();
        $this->assertLogEmpty();

        /** Mock $bar */
        $bar = $this->mockBar($po);
        $bar->shouldReceive('clear')->once();                /** @phpstan-ignore-line  */
        $po->closeProgress();
        $this->assertInfoInLog('Last line');
        $this->assertLogEmpty();
    }

    /**
     * @covers \DgfipSI1\ProcessHelper\ProcessOutput::logOutput
     * Test logOutput
     */
    public function testLogOutput(): void
    {
        $po = new ProcessOutput(new ProcessHelperOptions([]), $this->logger, []);
        /** mode silent */
        $this->omprop->setValue($po, 'silent');
        $po->logOutput('err', 'Message', false);
        $this->assertLogEmpty();
        $po->logOutput('err', 'Message', true);
        $this->assertLogEmpty();
        $po->logOutput('err', 'Message');
        $this->assertLogEmpty();
        /** mode on_error */
        $this->omprop->setValue($po, 'on_error');
        $po->logOutput('err', 'Message');
        $this->assertLogEmpty();
        $po->logOutput('err', 'Message', false);
        $this->assertLogEmpty();
        $po->logOutput('err', 'Message', true);
        $this->assertErrorInLog('Message');
        $this->assertLogEmpty();
        /** mode default */
        $this->omprop->setValue($po, 'default');
        $po->logOutput('out', 'Out Message');
        $this->assertInfoInLog('Out Message');
        $this->assertLogEmpty();
        $po->logOutput('out', 'Out Message', false);
        $this->assertInfoInLog('Out Message');
        $this->assertLogEmpty();
        $po->logOutput('err', 'Err Message', true);
        $this->assertErrorInLog('Err Message');
        $this->assertLogEmpty();
    }
    /**
     * get bar mock for process output
     * @param ProcessOutput $processOutput
     *
     * @return Mockery\Mock
     */
    protected function mockBar($processOutput)
    {
        /** @var Mockery\Mock $m */
        $m = \Mockery::mock('overload:Symfony\Component\Console\Helper\ProgressBar');
        $m->makePartial(); /* @php-ignore */

        $bar = new ProgressBar(new ConsoleOutput());
        $this->barprop->setValue($processOutput, $bar);

        /** @var Mockery\Mock $bar */

        return $bar;
    }
}
