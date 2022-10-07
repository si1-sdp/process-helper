<?php
/*
 * This file is part of dgfip-si1/process-helper.
 *
 */

namespace jmg\processHelperTests;

use \Mockery;
use DgfipSI1\ProcessHelper\ProcessHelper as PH;
use DgfipSI1\ProcessHelper\ConfigSchema as CONF;
use DgfipSI1\testLogger\LogTestCase;
use DgfipSI1\testLogger\TestLogger;
use DgfipSI1\ConfigHelper\ConfigHelper;
use DgfipSI1\ProcessHelper\Exception\BadOptionException;
use DgfipSI1\ProcessHelper\Exception\BadSearchException;
use DgfipSI1\ProcessHelper\Exception\ExecNotFoundException;
use DgfipSI1\ProcessHelper\Exception\ProcessException;
use DgfipSI1\ProcessHelper\Exception\UnknownOutputTypeException;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use ReflectionClass;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * @covers \DgfipSI1\ProcessHelper\ProcessHelper
 * @covers \DgfipSI1\ProcessHelper\ProcessHelperOptions
 * @covers \DgfipSI1\ProcessHelper\Exception\BadOptionException
 * @covers \DgfipSI1\ProcessHelper\Exception\BadSearchException
 * @covers \DgfipSI1\ProcessHelper\Exception\ExecNotFoundException
 * @covers \DgfipSI1\ProcessHelper\Exception\ProcessException
 * @covers \DgfipSI1\ProcessHelper\Exception\UnknownOutputTypeException
 * @covers \DgfipSI1\ProcessHelper\ConfigSchema
 *
 * @uses \DgfipSI1\ProcessHelper\ProcessEnv
 * @uses \DgfipSI1\ProcessHelper\ProcessOutput
 */
class ProcessHelperTest extends LogTestCase
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
     *
     * {@inheritDoc}
     * @see \PHPUnit\Framework\TestCase::tearDown()
     */
    protected function tearDown(): void
    {
        \Mockery::close();
    }
   /**
     * @return array<string,mixed>
     */
    public function dataExecCommand(): array
    {
        $out = [
            [ 'out' => '1' ],
            [ 'out' => '2' ],
            [ 'err' => '3' ],
        ];
        $om  = CONF::OUTPUT_MODE;
        $eoe = CONF::EXCEPTION_ON_ERROR;
        $data['out_silent']   = [[$om => 'silent']                   , $out,  0];
        $data['outerr_err']   = [[$om => 'on_error', $eoe => false]  , $out,  1];
        $data['outerr_throw'] = [[$om => 'on_error']                 , $out,  1];
        $data['outerr_ok']    = [[$om => 'on_error']                 , $out,  0];
        $data['outdefault']   = [[$om => 'default']                  , $out,  0];
        $data['in_shell']     = [[CONF::RUN_IN_SHELL => true ]       , $out,  0];
        $data['error']        = [[$eoe => false]                     , $out,  2];
        $data['exception']    = [[$eoe => true]                      , $out,  2];
        $data['timeout']      = [[]                                  , $out, -1];
        $data['in_dir']       = [[CONF::DIRECTORY => '/' ]           , $out,  0];

        foreach ($data as $name => $values) {
            $defOpts = $values;
            $defOpts[] = true;
            $dataOut["default_$name"] = $defOpts;
            $cmdOpts = $values;
            $cmdOpts[] = false;
            $dataOut["cmd_opts_$name"] = $cmdOpts;
        }

        return $dataOut;
    }
   /**
     * Test execCommand method
     * @param array<mixed>                $opts
     * @param array<array<string,string>> $output
     * @param int                         $returnCode
     * @param bool                        $useDefaultOptions
     *
     * @dataProvider dataExecCommand
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     *
     */
    public function testExecCommand($opts, $output, $returnCode, $useDefaultOptions): void
    {
        $this->logger = new TestLogger();
        $cmd = '/foobar/baz';
        if (!array_key_exists(CONF::OUTPUT_MODE, $opts)) {
            $opts[CONF::OUTPUT_MODE] = 'default';
        }
        $this->makeProcessMock($cmd, $opts, $returnCode, $output);
        if ($useDefaultOptions) {
            $ph = new PH($this->logger, $opts);
        } else {
            $ph = new PH($this->logger);
        }
        $eMsg = '';
        try {
            if ($useDefaultOptions) {
                $ph->execCommand(explode(' ', $cmd));
            } else {
                $ph->execCommand(explode(' ', $cmd), $opts, [ 'label' => 'command options']);
            }
        } catch (ProcessException $e) {
            $eMsg = $e->getMessage();
        }
        $throwError = !array_key_exists(CONF::EXCEPTION_ON_ERROR, $opts) || $opts[CONF::EXCEPTION_ON_ERROR];
        if ($throwError && $returnCode > 0) {
            $this->assertMatchesRegularExpression('/error \(code=[0-9]+\) running command/', $eMsg);
        } else {
            $this->assertEquals('', $eMsg);
        }
        switch ($opts[CONF::OUTPUT_MODE]) {
            case 'silent':
                break;
            case 'default':
                if (0 === $returnCode) {
                    $this->assertNoticeInLog('command was successfull');
                } elseif ($returnCode > 0) {
                    $this->assertErrorInLog('exitText');
                }
                $this->assertErrorInLog('3');
                $this->assertInfoInLog('1');
                $this->assertInfoInLog('2');
                $this->assertDebugInLog('Execute command (ignore_errors');
                break;
            case 'progress':
                if (0 === $returnCode) {
                    $this->assertNoticeInLog('command was successfull');
                } elseif ($returnCode > 0) {
                    $this->assertErrorInLog('exitText');
                }
                $this->assertNoticeInLog('3'); // progress displays last line
                break;
            case 'on_error':
                if (0 === $returnCode) {
                    $this->assertNoticeInLog('command was successfull');
                } else {
                    $this->assertErrorInLog('3');
                    $this->assertInfoInLog('1');
                    $this->assertInfoInLog('2');
                    $this->assertErrorInLog('exitText');
                }
                break;
        }
        if (-1 === $returnCode) {
            $this->assertErrorInLog('Timeout : job exeeded timeout');
        }
        $this->assertNoMoreProdMessages();

        $this->assertEquals("1,2,3", implode(',', $ph->getOutput()));
        $this->assertEquals("1,2", implode(',', $ph->getOutput('out')));
        $this->assertEquals("3", implode(',', $ph->getOutput('err')));
        if (-1 !== $returnCode) {
            $this->assertEquals($returnCode, $ph->getReturnCode());
        }
        // $this->showDebugLogs();
    }
    /**
     *  test config setters commands
     */
    public function testOptionSetters(): void
    {
        $ph = new PH();

        $reflector = new ReflectionClass('DgfipSI1\ProcessHelper\ProcessHelper');
        $prop = $reflector->getProperty('conf');
        $prop->setAccessible(true);
        /** @var ConfigHelper $options */
        $options = $prop->getValue($ph);

        $log = $reflector->getProperty('logger');
        $log->setAccessible(true);
        $logger = $log->getValue($ph);
        /** @var object $logger */
        $this->assertEquals('Symfony\Component\Console\Logger\ConsoleLogger', get_class($logger));
        /*
         * Output options
         */
        $this->assertEquals('default', $options->get(CONF::OUTPUT_MODE));
        $p = $ph->setOutput('on_error', 'info', 'error');
        $this->assertEquals('on_error', $options->get(CONF::OUTPUT_MODE));
        $this->assertEquals('info', $options->get(CONF::OUTPUT_STDOUT_TO));
        $this->assertEquals('error', $options->get(CONF::OUTPUT_STDERR_TO));
        $this->assertEquals($ph, $p);
        $message = '';
        try {
            $ph->setOutput('i_dont_exist');
        } catch (BadOptionException $e) {
            $message = $e->getMessage();
        }
        $this->assertMatchesRegularExpression('/Output option .* does not exists/', $message);
        $message = '';
        try {
            $ph->setOutput('default', 'i_dont_exist');
        } catch (BadOptionException $e) {
            $message = $e->getMessage();
        }
        $this->assertMatchesRegularExpression('/Unavailable output channel/', $message);
        /*
         * Timeout option
         */
        $this->assertEquals(60, $options->get(CONF::TIMEOUT));
        $this->assertFalse($options->get(CONF::RUN_IN_SHELL));
        $p = $ph->setTimeout(120)->runInShell(true);
        $this->assertTrue($options->get(CONF::RUN_IN_SHELL));
        $this->assertEquals(120, $options->get(CONF::TIMEOUT));
        $this->assertEquals($ph, $p);
    }
    /**
     * test getOutput exception
     *
     * @return void
     */
    public function testGetOutputException(): void
    {
        $ph = new PH();
        $msg = '';
        try {
            $ph->getOutput('i_dont_exist');
        } catch (UnknownOutputTypeException $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals('ProcessHelper:getOutput: Unkown type i_dont_exist', $msg);
    }
    /**
     * test environment passing to process
     *
     * @return void
     */
    public function testGetProcessEnv(): void
    {
        $_SERVER = ["BASE_DIR" => "/foo/bar" ];
        $_ENV    = [ 'var1' => 'var1_value', 'var2' => 'var2_value', ];                             // APPENV: var1,var2
        file_put_contents($this->root->url()."/.env", "var2 = var2_.env_value\nvar3 = var3_value"); // DOTENV: var2,var3
        // $extraEnv = [ [ 'name' => 'process_arg_a', 'value' => true ],
        //               [ 'name' => 'process_arg_b', 'value' => 'blabla' ],
        //               [ 'name' => 'process_arg_c', 'value' => 'foo']];
        $extraEnv = [  'process_arg_a' => true , 'process_arg_b' => 'blabla', 'process_arg_c' =>  'foo'];

        $opts = [
            CONF::RUN_IN_SHELL => false,
        ];
        $ph = new PH(new TestLogger(), $opts);
        $ph->setEnv($extraEnv, true, true, $this->root->url());
        $ph->execCommand(['env']);
        $outputVars = [];
        foreach ($ph->getOutput() as $line) {
            $sep = strpos($line, '=');
            if ($sep) {
                $key   = substr($line, 0, $sep);
                $value = substr($line, $sep + 1);
                $outputVars[$key] = $value;
            }
        }
        $expected = [
            "BASE_DIR" => "/foo/bar",
            'var1' => 'var1_value',
            'var2' => 'var2_value',
            'var3' => 'var3_value',
            'process_arg_a' => true,
            'process_arg_b' => 'blabla',
            'process_arg_c' => 'foo',
        ];
        foreach ($expected as $var => $value) {
            $this->assertArrayHasKey($var, $outputVars, "Missing environment variable : $var");
            $this->assertEquals($value, $outputVars[$var], "Unexpected value for variable : $var");
        }
    }


    /**
     * test findExecutable method
     *
     * @return void
     *
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     *
     */
    public function testFindExecutable()
    {
        // test find program ok
        $this->logger = new TestLogger();
        $output[] = [ 'out' => '/bin/my_program' ];
        $opts = [CONF::RUN_IN_SHELL => true];
        $this->makeProcessMock('cmd', $opts, 0, $output);
        $ph = new PH($this->logger, []);
        $program = $ph->findExecutable('my_program');
        $this->assertEquals('/bin/my_program', $program);

        // test when program not found
        \Mockery::close();
        $opts = [CONF::FIND_EXECUTABLE => true, CONF::RUN_IN_SHELL => true];
        $this->makeProcessMock('cmd', $opts, 1, $output);
        $ph = new PH($this->logger, $opts);
        $message = '';
        try {
            $ph->execCommand(['my_program', '-s'], $opts);
        } catch (ExecNotFoundException $e) {
             $message = $e->getMessage();
        }
        $this->assertMatchesRegularExpression("/^executable .[a-z_]+. not found/", $message);
        // test when program is found
        \Mockery::close();
        $opts = [CONF::FIND_EXECUTABLE => true, CONF::RUN_IN_SHELL => true];
        $this->makeProcessMock('/bin/my_program', $opts, 0, $output);
        $ph = new PH($this->logger, $opts);
        $message = '';
        try {
            $ph->execCommand(['my_program', '-s'], $opts);
        } catch (\Exception $e) {
             $message = $e->getMessage();
        }
        // Cannot traverse closed iterator proves we ran execCommmand twice
        $this->assertMatchesRegularExpression("/^Cannot traverse/", $message);
        $this->assertMatchesRegularExpression('#^/bin/my_program#', $ph->getCommandLine());
    }
    /**
     * test dryRun method
     *
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     *
     * @return void
     */
    public function testDryRun()
    {
        // test find program ok
        $this->logger = new TestLogger();
        $output[] = [ 'out' => 'command was executed' ];
        $opts = [CONF::DRY_RUN => true, CONF::EXCEPTION_ON_ERROR => true];

        // set a return code of -1 to raise an exception if called
        $this->makeProcessMock('cmd', $opts, 1, $output);
        $ph = new PH($this->logger, $opts);
        $ph->execCommand(['my_program', '-s'], $opts);
        $this->assertNoticeInLog('DRY-RUN - execute command');
    }

    /**
     * test regexp searches on output and related functions
     *
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     *
     * @return void
     */
    public function testRegexpSearches()
    {
        $this->logger = new TestLogger();
        $output[] = [ 'out' => 'foo=2'];
        $output[] = [ 'out' => 'bar=3'];
        $output[] = [ 'out' => 'foo=5'];
        $this->makeProcessMock('cmd', [], 0, $output);
        $ph = new PH($this->logger, []);
        $ph->addSearch('foo', 'foo=(.*)');
        $ph->addSearch('bar', 'bar=(.*)');
        $ph->execCommand(['ls', '-l', '/foobar/baz']);
        $this->assertEquals([2, 5], $ph->getMatches('foo'));
        $this->assertEquals([3], $ph->getMatches('bar'));
        $ph->resetMatches('foo');
        $this->assertEmpty($ph->getMatches('foo'));
        $this->assertEquals([3], $ph->getMatches('bar'));
        $ph->resetMatches();
        $this->assertEmpty($ph->getMatches('bar'));
        $message = '';
        try {
            $ph->resetMatches('baz');
        } catch (BadSearchException $e) {
            $message = $e->getMessage();
        }
        $this->assertMatchesRegularExpression("#resetMatches: No match on variable#", $message);
        $message = '';
        try {
            $ph->getMatches('baz');
        } catch (BadSearchException $e) {
            $message = $e->getMessage();
        }
        $this->assertMatchesRegularExpression("#getMatches: Can't search for a match on variable#", $message);
        $this->assertEquals([], $ph->getMatches('bar'));
        $ph->resetSearches();
        $message = '';
        try {
            $ph->getMatches('bar');
        } catch (BadSearchException $e) {
            $message = $e->getMessage();
        }
        $this->assertMatchesRegularExpression("#getMatches: Can't search for a match on variable#", $message);
    }
    /**
     * tests the printOptions method
     *
     * @return void
     */
    public function testPrintOptions()
    {
        $ph = new PH($this->logger, []);
        $this->expectOutputRegex("/timeout: 60/");
        $ph->printOptions();
    }


    /**
     * Generator for output
     *
     * @param array<array<string,string>> $lines
     *
     * @return \Iterator<string>
     */
    protected static function generator($lines)
    {
        foreach ($lines as $content) {
            foreach ($content as $outputType => $line) {
                yield $outputType => $line;
            }
        }
    }
    /**
     * get mock object for process
     * @param string                      $cmd
     * @param array<string,mixed>         $options
     * @param int                         $returnCode
     * @param array<array<string,string>> $output
     * @param string                      $exitText
     *
     * @return Mockery\Mock
     */
    protected function makeProcessMock($cmd, $options, $returnCode, $output, $exitText = 'exitText')
    {
        /** @var Mockery\Mock $m */
        $m = \Mockery::mock('overload:Symfony\Component\Process\Process')->makePartial(); /* @php-ignore */
        if (array_key_exists(CONF::RUN_IN_SHELL, $options) && true === $options[CONF::RUN_IN_SHELL]) {
            $m->shouldReceive('fromShellCommandline')->andReturn($m);            /* @phpstan-ignore-line */
        }
        if (array_key_exists(CONF::DIRECTORY, $options) && null !== $options[CONF::DIRECTORY]) {
            $m->shouldReceive('setWorkingDirectory');
        }
        $m->shouldReceive('setEnv');
        if (!array_key_exists('timeout', $options)) {
            $options['timeout'] = 60;
        }
        $m->shouldReceive('getIterator')->andReturn(self::generator($output));   /* @phpstan-ignore-line */
        $m->shouldReceive('getCommandLine')->andReturn($cmd);                    /* @phpstan-ignore-line */
        $m->shouldReceive('start');
        /** @phpstan-ignore-next-line */
        $m->shouldReceive('setTimeout')->with($options['timeout']);
        if (-1 === $returnCode) {
            $m->shouldReceive('getTimeout')->andReturn($options['timeout']);     /* @phpstan-ignore-line */
            /** @phpstan-ignore-next-line */
            $m->shouldReceive('isSuccessful')->andThrow(new ProcessTimedOutException($m, 1));
        } elseif (0 === $returnCode) {
            $m->shouldReceive('isSuccessful')->andReturn(true);                 /* @phpstan-ignore-line */
        } else {
            $m->shouldReceive('isSuccessful')->andReturn(false);                /* @phpstan-ignore-line */
            $m->shouldReceive('getExitCode')->andReturn($returnCode);           /* @phpstan-ignore-line */
            $m->shouldReceive('getExitCodeText')->andReturn($exitText);         /* @phpstan-ignore-line */
        }
//print_r($m);
        return $m;
    }
}
