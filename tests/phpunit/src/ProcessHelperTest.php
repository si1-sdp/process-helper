<?php
/*
 * This file is part of dgfip-si1/process-helper.
 *
 */

namespace jmg\processHelperTests;

use \Mockery;
use DgfipSI1\ProcessHelper\ProcessHelper as PH;
use DgfipSI1\ProcessHelper\ProcessHelperOptions as PHO;
use DgfipSI1\testLogger\LogTestCase;
use DgfipSI1\testLogger\TestLogger;
use DgfipSI1\ConfigTree\ConfigTree;
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
        $data['run_out_silent']   = [[PHO::OUTPUT_MODE => 'silent']                                     , $out,  0, 0];
        $data['run_outerr_err']   = [[PHO::OUTPUT_MODE => 'on_error', PHO::EXCEPTION_ON_ERROR => false] , $out,  1, 0];
        $data['run_outerr_throw'] = [[PHO::OUTPUT_MODE => 'on_error']                                   , $out,  1, 0];
        $data['run_outerr_ok']    = [[PHO::OUTPUT_MODE => 'on_error']                                   , $out,  0, 0];
        $data['run_outdefault']   = [[PHO::OUTPUT_MODE => 'default']                                    , $out,  0, 0];
        $data['run_in_shell']     = [[PHO::RUN_IN_SHELL => true ]                                       , $out,  0, 0];
        $data['run_error']        = [[PHO::EXCEPTION_ON_ERROR => false]                                 , $out,  2, 0];
        $data['run_exception']    = [[PHO::EXCEPTION_ON_ERROR => true]                                  , $out,  2, 0];
        $data['run_timeout']      = [[]                                                                 , $out, -1, 0];
        $data['run_in_dir']       = [[PHO::DIRECTORY => '/' ]                                           , $out,  0, 0];


        return $data;
    }
   /**
     * Test execCommand method
     * @param array<mixed>                $opts
     * @param array<array<string,string>> $output
     * @param int                         $returnCode
     * @param int                         $runTime
     *
     * @dataProvider dataExecCommand
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     *
     */
    public function testExecCommand($opts, $output, $returnCode, $runTime): void
    {
        $this->logger = new TestLogger();
        $cmd = '/foobar/baz';
        if (!array_key_exists(PHO::OUTPUT_MODE, $opts)) {
            $opts[PHO::OUTPUT_MODE] = 'default';
        }
        $this->makeProcessMock($cmd, $opts, $returnCode, $output);
        $ph = new PH($this->logger, $opts);
        $eMsg = '';
        try {
            $ph->execCommand(explode(' ', $cmd));
        } catch (ProcessException $e) {
            $eMsg = $e->getMessage();
        }
        $throwError = !array_key_exists(PHO::EXCEPTION_ON_ERROR, $opts) || $opts[PHO::EXCEPTION_ON_ERROR];
        if ($throwError && $returnCode > 0) {
            $this->assertMatchesRegularExpression('/error \(code=[0-9]+\) running command/', $eMsg);
        } else {
            $this->assertEquals('', $eMsg);
        }
        switch ($opts[PHO::OUTPUT_MODE]) {
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
        $prop = $reflector->getProperty('globalOptions');
        $prop->setAccessible(true);
        /** @var ConfigTree $options */
        $options = $prop->getValue($ph);

        $log = $reflector->getProperty('logger');
        $log->setAccessible(true);
        $logger = $log->getValue($ph);
        /** @var object $logger */
        $this->assertEquals('Symfony\Component\Console\Logger\ConsoleLogger', get_class($logger));
        /*
         * Output options
         */
        $this->assertEquals('default', $options->get(PHO::OUTPUT_MODE));
        $p = $ph->setOutput('on_error', 'info', 'error');
        $this->assertEquals('on_error', $options->get(PHO::OUTPUT_MODE));
        $this->assertEquals('info', $options->get(PHO::OUTPUT_STDOUT_TO));
        $this->assertEquals('error', $options->get(PHO::OUTPUT_STDERR_TO));
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
        $this->assertEquals(60, $options->get(PHO::TIMEOUT));
        $this->assertFalse($options->get(PHO::RUN_IN_SHELL));
        $p = $ph->setTimeout(120)->runInShell(true);
        $this->assertTrue($options->get(PHO::RUN_IN_SHELL));
        $this->assertEquals(120, $options->get(PHO::TIMEOUT));
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
        $extraEnv = [ 'process_arg_a' => true, 'process_arg_b' => 'blabla', 'process_arg_c' => 'foo'];

        $opts = [
            PHO::RUN_IN_SHELL => false,
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
        $opts = [PHO::RUN_IN_SHELL => true];

        $this->makeProcessMock('cmd', $opts, 0, $output);
        $ph = new PH($this->logger, []);
        $this->assertEquals('/bin/my_program', $ph->findExecutable('my_program'));
        // test when program not found
        \Mockery::close();
        $opts = [PHO::FIND_EXECUTABLE => true, PHO::RUN_IN_SHELL => true];
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
        $opts = [PHO::FIND_EXECUTABLE => true, PHO::RUN_IN_SHELL => true];
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
        $opts = [PHO::DRY_RUN => true, PHO::EXCEPTION_ON_ERROR => true];

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
        if (array_key_exists(PHO::RUN_IN_SHELL, $options) && true === $options[PHO::RUN_IN_SHELL]) {
            $m->shouldReceive('fromShellCommandline')->andReturn($m);            /* @phpstan-ignore-line */
        }
        if (array_key_exists(PHO::DIRECTORY, $options) && null !== $options[PHO::DIRECTORY]) {
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

        return $m;
    }
}
