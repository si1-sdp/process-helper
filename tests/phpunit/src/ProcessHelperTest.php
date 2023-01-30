<?php
/*
 * This file is part of dgfip-si1/process-helper.
 *
 */

namespace jmg\processHelperTests;

use \Mockery;
use DgfipSI1\ProcessHelper\ProcessHelper as PH;
use DgfipSI1\ProcessHelper\ProcessOutput;
use DgfipSI1\ProcessHelper\ConfigSchema as CONF;
use DgfipSI1\testLogger\LogTestCase;
use DgfipSI1\testLogger\TestLogger;
use DgfipSI1\ConfigHelper\ConfigHelper;
use DgfipSI1\ProcessHelper\Exception\BadOptionException;
use DgfipSI1\ProcessHelper\Exception\BadSearchException;
use DgfipSI1\ProcessHelper\Exception\ExecNotFoundException;
use DgfipSI1\ProcessHelper\Exception\ProcessException;
use DgfipSI1\ProcessHelper\Exception\UnknownOutputTypeException;
use DgfipSI1\ProcessHelper\ProcessHelper;
use DgfipSI1\ProcessHelper\ProcessHelperOptions;
use Exception;
use Mockery\Mock;
use ReflectionClass;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * @uses \DgfipSI1\ProcessHelper\ProcessHelper
 * @uses \DgfipSI1\ProcessHelper\ProcessHelperOptions
 * @uses \DgfipSI1\ProcessHelper\Exception\BadOptionException
 * @uses \DgfipSI1\ProcessHelper\Exception\BadSearchException
 * @uses \DgfipSI1\ProcessHelper\Exception\ExecNotFoundException
 * @uses \DgfipSI1\ProcessHelper\Exception\ProcessException
 * @uses \DgfipSI1\ProcessHelper\Exception\UnknownOutputTypeException
 * @uses \DgfipSI1\ProcessHelper\ConfigSchema
 * @uses \DgfipSI1\ProcessHelper\ProcessEnv
 * @uses \DgfipSI1\ProcessHelper\ProcessOutput
 */
class ProcessHelperTest extends LogTestCase
{
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
     * Test constructor
     *
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::__construct
     * @covers DgfipSI1\ProcessHelper\ProcessHelperOptions::__construct

     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     *
     */
    public function testConstructor(): void
    {
        $ref = new ReflectionClass(PH::class);
        $logprop = $ref->getProperty('logger');
        $logprop->setAccessible(true);
        $outprop = $ref->getProperty('output');
        $outprop->setAccessible(true);

        $ph = new PH();
        $conf = $this->getConf($ph);
        self::assertInstanceOf(ConsoleLogger::class, $logprop->getValue($ph));
        self::assertInstanceOf(ProcessHelperOptions::class, $conf);
        self::assertEquals([], $outprop->getValue($ph));

        $logger = new TestLogger();
        $ph = new PH($logger);
        $conf = $this->getConf($ph);
        self::assertEquals($logger, $logprop->getValue($ph));
        self::assertInstanceOf(ProcessHelperOptions::class, $conf);
        self::assertEquals([], $outprop->getValue($ph));
        self::assertFalse($conf->get(CONF::DRY_RUN));

        $options = [ CONF::DRY_RUN => true ];
        $ph = new PH($logger, $options);
        $conf = $this->getConf($ph);
        self::assertEquals($logger, $logprop->getValue($ph));
        self::assertTrue($conf->get(CONF::DRY_RUN));
        self::assertEquals([], $outprop->getValue($ph));

        $confRef = new ReflectionClass(ConfigHelper::class);
        $acProp = $confRef->getProperty('activeContext');
        $acProp->setAccessible(true);
        self::assertEquals('command', $acProp->getValue($conf));
    }

    /**
     *  test config setters commands
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::getCommandLine
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::setOutput
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::setTimeout
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::runInShell
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::setLogger
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::setOptions
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::runInShell
     */
    public function testSettersAndGetters(): void
    {
        $ph = new PH();

        $reflector = new ReflectionClass('DgfipSI1\ProcessHelper\ProcessHelper');
        $prop = $reflector->getProperty('conf');
        $prop->setAccessible(true);
        $prop->setValue($ph, null);
        self::assertEquals(null, $prop->getValue($ph));

        $ret = $ph->setOptions([]);
        self::assertEquals($ph, $ret);

        /** @var ConfigHelper $options */
        $options = $prop->getValue($ph);
        self::assertEquals(ProcessHelperOptions::class, get_class($options));


        $log = $reflector->getProperty('logger');
        $log->setAccessible(true);
        $logger = $log->getValue($ph);
        /** @var object $logger */
        self::assertEquals('Symfony\Component\Console\Logger\ConsoleLogger', get_class($logger));

        $logger = new TestLogger();
        $ret = $ph->setLogger($logger);
        self::assertEquals($ph, $ret);
        self::assertEquals($logger, $log->getValue($ph));
        /*
         * Output options
         */
        self::assertEquals('default', $options->get(CONF::OUTPUT_MODE));
        $p = $ph->setOutput('on_error', 'notice', 'alert');
        self::assertEquals('on_error', $options->get(CONF::OUTPUT_MODE));
        self::assertEquals('notice', $options->get(CONF::OUTPUT_STDOUT_TO));
        self::assertEquals('alert', $options->get(CONF::OUTPUT_STDERR_TO));
        self::assertEquals($ph, $p);
        $message = '';
        try {
            $ph->setOutput('i_dont_exist');
        } catch (BadOptionException $e) {
            $message = $e->getMessage();
        }
        self::assertMatchesRegularExpression('/Output option .* does not exists/', $message);
        $message = '';
        try {
            $ph->setOutput('default', 'i_dont_exist');
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }
        self::assertMatchesRegularExpression('/Unavailable output channel/', $message);
        /*
         * Timeout option
         */
        self::assertEquals(60, $options->get(CONF::TIMEOUT));
        self::assertFalse($options->get(CONF::RUN_IN_SHELL));
        $p = $ph->setTimeout(120)->runInShell(true);
        self::assertTrue($options->get(CONF::RUN_IN_SHELL));
        self::assertEquals(120, $options->get(CONF::TIMEOUT));
        self::assertEquals($ph, $p);

        $message = '';
        try {
            $p = $ph->setTimeout(0);
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }
        self::assertMatchesRegularExpression('/The value 0 is too small/', $message);
        self::assertEquals(null, $ph->getCommandLine());
    }
    /**
     * test getOutput exception
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::getOutput
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
        self::assertEquals('ProcessHelper:getOutput: Unkown type i_dont_exist', $msg);
    }
    /**
     * testGetProcessEnv data provider
     *
     * @return array<string,array<mixed>>
     */
    public function envData()
    {
        $data = [];
        //                           extraEnv appEnv  DotEnv  DotEnvDir
        $data['all_root_dir   '] = [ true,   true,   true  , '/',  'EAD' ];
        $data['all_default_dir'] = [ true,   true,   true  , null  ,  'EA'  ];
        $data['no_dot         '] = [ true,   true,   false , null  ,  'EA'  ];
        $data['default_dot    '] = [ true,   true,   null  , '/',  'EA'  ];
        $data['no_app         '] = [ true,   false,  null  , null  ,  'E'   ];
        $data['app_default    '] = [ true,   null,   null  , null  ,  'EA'  ];
        $data['nothing        '] = [ false,  false,  null  , null  ,  ''    ];
        $data['all_default    '] = [ null,   null,   null  , null  ,  'A'   ];

        return $data;
    }


    /**
     * test environment passing to process
     *
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::setEnv
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::prepareProcess
     *
     * @dataProvider envData
     *
     * @param bool|null   $extra
     * @param bool|null   $app
     * @param bool|null   $dot
     * @param string|null $dotDir
     * @param string      $expect
     *
     * @return void
     */
    public function testGetProcessEnv($extra, $app, $dot, $dotDir, $expect): void
    {
        $extraEnv = [ 'extra_env_var' => 'extra_env_value'];                             // EXTRAENV
        $ph = new PH(new TestLogger());
        if (null === $extra) {
            $ret = $ph->setEnv();
        } elseif (null === $app) {
            $ret = $ph->setEnv($extra ? $extraEnv : []);
        } elseif (null === $dot) {
            $ret = $ph->setEnv($extra ? $extraEnv : [], $app);
        } elseif (null === $dotDir) {
            $ret = $ph->setEnv($extra ? $extraEnv : [], $app, $dot);
        } else {
            $ret = $ph->setEnv($extra ? $extraEnv : [], $app, $dot, $dotDir);
        }
        self::assertEquals($ph, $ret);
        $conf = $this->getConf($ph);
        self::assertEquals(($extra !== null && $extra !== false) ? $extraEnv : [], $conf->get(CONF::ENV_VARS));
        self::assertEquals($app ?? true, $conf->get(CONF::USE_APPENV));
        self::assertEquals($dot, $conf->get(CONF::USE_DOTENV));
        if (true === $dot) {
            self::assertEquals($dotDir ?? '.', $conf->get(CONF::DOTENV_DIR));
        }
    }
    /**
     * data provider for testFindExecutable
     *
     * @return array<string,array<mixed>>
     */
    public function findExecutableData()
    {
        $data = [];           // which return string          Exception thrown ? 0: none, 1/2
        $data['default_run'] = [ '/bin/bash'                     , 0 ];
        $data['not_found  '] = [ ''                              , 1  ];
        $data['alias - zsh'] = [ 'ls: aliased to ls --color=tty' , 2  ];

        return $data;
    }
    /**
     * test findExecutable method
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::findExecutable
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     *
     * @dataProvider findExecutableData
     *
     * @param string $found
     * @param int    $throw
     *
     * @return void
     *
     */
    public function testFindExecutable($found, $throw)
    {
        $ph = $this->createProcessHelperMock([]);

        /** @var Mockery\Mock $fe */
        $fe = \Mockery::mock(ProcessHelper::class);
        $fe->makePartial();
        $fe->shouldAllowMockingProtectedMethods();

        $name = "'my_program'";
        $escapedName = "my_program";
        switch ($throw) {
            case 1:
                $exceptionMsg = "executable 'my_program' not found";
                break;
            case 2:
                $exceptionMsg = "which return value '$found' not found";
                break;
            default:
                $exceptionMsg = '';
        }
        if (1 === $throw) {
            $fe->shouldReceive('execCommand')->with(['which', $escapedName])              /** @phpstan-ignore-line */
            ->once()->andThrow(ExecNotFoundException::class, $exceptionMsg);
        } else {
            $fe->shouldReceive('execCommand')->with(['which', $escapedName])->once();     /** @phpstan-ignore-line */
            $fe->shouldReceive('getOutput')->once()->andReturn([$found]);                 /** @phpstan-ignore-line */
        }
        /** @var Mock $ph */
        $ph->shouldReceive('createFindExecutableProcess')->once()->andReturn($fe);        /** @phpstan-ignore-line */
        $msg = '';
        try {
            /** @var ProcessHelper $ph */
            $ph->findExecutable($name);
        } catch (Exception $e) {
            $msg = $e->getMessage();
        }
        if (0 !== $throw) {
            self::assertEquals($exceptionMsg, $msg);
        } else {
            self::assertEquals('', $msg);
        }
        $method = (new ReflectionClass(ProcessHelper::class))->getMethod('findExecutable');
        self::assertTrue($method->isPublic());
    }
   /**
     * test createFindExecutableProcess method
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::createFindExecutableProcess
     *
     * @return void
     */
    public function testCreateFindExecutableProcess()
    {
        $this->logger = new TestLogger();
        $ph = new PH($this->logger);

        $fe = $ph->createFindExecutableProcess();
        $ref = new ReflectionClass($fe::class);
        $optProp = $ref->getProperty('conf');
        $optProp->setAccessible(true);
        $logProp = $ref->getProperty('logger');
        $logProp->setAccessible(true);

        self::assertEquals($this->logger, $logProp->getValue($fe));
        /** @var ConfigHelper $options */
        $options = $optProp->getValue($fe);
        self::assertEquals(false, $options->get(CONF::FIND_EXECUTABLE));
        self::assertEquals(true, $options->get(CONF::EXCEPTION_ON_ERROR));
        self::assertEquals('silent', $options->get(CONF::OUTPUT_MODE));
        self::assertEquals(true, $options->get(CONF::RUN_IN_SHELL));
    }
    /**
     * test dryRun method
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::execProcess
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
        $output = [];
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
     * @return array<string,array<mixed>>
     */
    public function execCommandData()
    {
        return [        //    dry      cmd context
            'all_default' => [ null,   null           ],
            'dry_run_tst' => [ true,   null           ],
            'cmd_context' => [ false,  'test_cmd'     ],
        ];
    }
    /**
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::execCommand
     *
     * @dataProvider  execCommandData
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     *
     * @param bool|null   $dryRun
     * @param string|null $name
     */
    public function testExecCommand($dryRun, $name): void
    {
        /** @var Mock $ph */
        $ph = $this->createProcessHelperMock([]);
        $conf = $this->getConf($ph);
        $conf->addPlaceholder('command');

        $command = [ 'test_command' ];
        if (null !== $dryRun) {
            $options = [CONF::DRY_RUN => $dryRun];
        } else {
            $options = [];
        }
        if (null !== $name) {
            $logContext = ['cmd' => $name];
        } else {
            $logContext = [];
        }
        $proc = new Process(['foo']);
        /** @phpstan-ignore-next-line */
        $ph->shouldReceive('prepareProcess')->with($command, $options, $logContext)->once()->andReturn($proc);
        if (true !== $dryRun) {
            /** @phpstan-ignore-next-line */
            $ph->shouldReceive('execProcess')->once()->with($proc, $logContext)->andReturn(2);
        }
        self::assertTrue($this->getConf($ph)->hasContext('command'));
        /** @var ProcessHelper $ph */
        $ph->setOptions($options);
        if (null === $dryRun) {
            $ret = $ph->execCommand($command);
        } elseif (null === $name) {
            $ret = $ph->execCommand($command, $options);
        } else {
            $ret = $ph->execCommand($command, $options, $logContext);
        }
        if (true === $dryRun) {
            if (null !== $name) {
                $this->assertNoticeInLog("DRY-RUN - execute command $name", true);
            } else {
                $this->assertNoticeInLog("DRY-RUN - execute command {cmd}", true);
            }
            self::assertEquals(0, $ret);
            self::assertFalse($this->getConf($ph)->hasContext('command'));
        } else {
            $this->assertInfoInLog("Launching command {cmd}");
        }
        $this->assertLogEmpty();
    }
    /**
     * @return array<string,mixed>
     */
    public function dataPrepCommand(): array
    {
        $data = [];
        $data['find_exec'] = [[CONF::FIND_EXECUTABLE => true ]    , true  ];
        $data['in_shell '] = [[CONF::RUN_IN_SHELL => true ]       , false ];
        $data['error    '] = [[CONF::EXCEPTION_ON_ERROR => false] , true  ];
        $data['exception'] = [[CONF::EXCEPTION_ON_ERROR => true]  , false ];
        $data['timeout  '] = [[CONF::TIMEOUT => 10]               , true  ];
        $data['in_dir   '] = [[CONF::DIRECTORY => '/' ]           , false ];
        $data['bad_env  '] = [[CONF::ENV_VARS => null ]           , true  ];

        return $data;
    }
    /**
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::prepareProcess
     *
     * @dataProvider dataPrepCommand
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled

     * @param array<string,mixed> $opts
     * @param bool                $useDefaultOptions
     */
    public function testPrepareProcess($opts, $useDefaultOptions): void
    {

        if ($useDefaultOptions) {
            $cmdOpts = $opts;
            $defOpts = [];
        } else {
            $cmdOpts = [];
            $defOpts = $opts;
        }
        $command = [ 'testCommand' ];
        $logCtx = [];
        $env = ['foo' => 'bar'];
        $runInShell     = array_key_exists(CONF::RUN_IN_SHELL, $opts) && (bool) $opts[CONF::RUN_IN_SHELL];
        /** @var int $timeout */
        $timeout        = array_key_exists(CONF::TIMEOUT, $opts) ? $opts[CONF::TIMEOUT] : 60;
        /** @var string $wd */
        $wd             = array_key_exists(CONF::DIRECTORY, $opts) ? $opts[CONF::DIRECTORY] : '';
        $findExec       = array_key_exists(CONF::FIND_EXECUTABLE, $opts) && (bool) $opts[CONF::FIND_EXECUTABLE];
        $ignoreErrs     = array_key_exists(CONF::EXCEPTION_ON_ERROR, $opts) ? $opts[CONF::EXCEPTION_ON_ERROR] : true;
        $ignoreErrsText = (bool) $ignoreErrs ? 'true' : 'false';

        /** @var Mockery\Mock $proc SymfonyProcess */
        $proc = \Mockery::mock(Process::class);
        $proc->makePartial();
        $proc->shouldReceive('setEnv')->once();                                             /** @phpstan-ignore-line */
        $proc->shouldReceive('getCommandLine')->once()->andReturn('commandLine');           /** @phpstan-ignore-line */
        $proc->shouldReceive('setTimeout')->once()->with($timeout);                         /** @phpstan-ignore-line */
        if ('' !== $wd) {
            $proc->shouldReceive('setWorkingDirectory')->once()->with($wd);                 /** @phpstan-ignore-line */
        }

        /** @var Mock $ph */
        $ph = $this->createProcessHelperMock($defOpts);
        /** @phpstan-ignore-next-line */
        $ph->shouldReceive('createSymfonyProcess')->once()->with($command, $runInShell)->andReturn($proc);
        if ($findExec) {
            $ph->shouldReceive('findExecutable')->once()->andReturn('testCommand');         /** @phpstan-ignore-line */
        }

        /** @var ProcessHelper $ph */
        $ph->prepareProcess($command, $cmdOpts, $logCtxt);
        $debug = "Execute command (ignore_errors=$ignoreErrsText, timeout=$timeout): {cmd}";
        $this->assertDebugInLog($debug);
        if ('' !== $wd) {
            $this->assertDebugInLog("set working directory to $wd");
        }
        $this->assertLogEmpty();
    }
    /**
     * Undocumented function
     *
     * @return array<string,array<mixed>>
     */
    public function createSymfonyProcessData()
    {
        $data = [];
        $data['in_shell    '] = [ true ];
        $data['not_in_shell'] = [ false];

        return $data;
    }
    /**
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::createSymfonyProcess
     *
     * @dataProvider createSymfonyProcessData
     *
     * @param bool $inShell
     */
    public function testCreateSymfonyProcess($inShell): void
    {
        $ph = new ProcessHelper();
        $phref  = new ReflectionClass(ph::class);
        $method = $phref->getMethod('createSymfonyProcess');
        $method->setAccessible(true);

        $command = ['test_command'];
        /** @var Process $process */
        $process = $method->invokeArgs($ph, [$command, $inShell]);
        self::assertInstanceOf(Process::class, $process);
        if ($inShell) {
            self::assertEquals(implode(' ', $command), $process->getCommandLine());
        } else {
            self::assertEquals("'".implode(' ', $command)."'", $process->getCommandLine());
        }
    }
   /**
     * @return array<string,mixed>
     */
    public function dataExecProcess(): array
    {
        $out = [[ 'out' => '1' ], ['out' => '    '], [ 'out' => '2' ], [ 'err' => '3' ]];
        $om  = CONF::OUTPUT_MODE;
        $eoe = CONF::EXCEPTION_ON_ERROR;
        $data = [];
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
        $data['bad_env']      = [[CONF::ENV_VARS => null ]          , $out,  2];
        $dataOut = [];
        foreach ($data as $name => $values) {
            $dataOut["default_$name"] = $values;
            array_push($dataOut["default_$name"], true);
            $dataOut["cmd_opts_$name"] = $values;
            array_push($dataOut["cmd_opts_$name"], false);
        }

        return $dataOut;
    }

    /**
     * Test execProcess method
     *
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::execProcess
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::getOutput
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::getReturnCode
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::outputToLog
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::getCommandLine
     *
     * @param array<mixed>                $opts
     * @param array<array<string,string>> $output
     * @param int                         $returnCode
     * @param bool                        $useDefaultOptions
     *
     * @dataProvider dataExecProcess
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     *
     */
    public function testExecProcess($opts, $output, $returnCode, $useDefaultOptions): void
    {
        $this->logger = new TestLogger();
        $cmd = '/foobar/baz';
        if (!array_key_exists(CONF::OUTPUT_MODE, $opts)) {
            $opts[CONF::OUTPUT_MODE] = 'default';
        }
        $this->makeProcessMock($cmd, $opts, $returnCode, $output);
        if ($useDefaultOptions) {
            $ph = new PH($this->logger, $opts);
            $cmdOpts = [];
        } else {
            $ph = new PH($this->logger);
            $cmdOpts = $opts;
        }
        $eMsg = '';
        $logCtx = [];
        $process = $ph->prepareProcess(explode(' ', $cmd), $cmdOpts, $logCtx);
        try {
            $ret = $ph->execProcess($process, $logCtx);
        } catch (ProcessException $e) {
            $ret = $ph->getReturnCode();
            $eMsg = $e->getMessage();
        }
        $throwError = !array_key_exists(CONF::EXCEPTION_ON_ERROR, $opts) || $opts[CONF::EXCEPTION_ON_ERROR];
        if ($throwError && $returnCode > 0) {
            self::assertMatchesRegularExpression('/error \(code=[0-9]+\) running command/', $eMsg);
        } else {
            self::assertEquals('', $eMsg);
        }
        switch ($opts[CONF::OUTPUT_MODE]) {
            case 'silent':
                break;
            case 'default':
                if (0 === $returnCode) {
                    $this->assertInfoInLog('command was successfull');
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
                    $this->assertInfoInLog('command was successfull');
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

        self::assertEquals("1,2,3", implode(',', $ph->getOutput()));
        self::assertEquals("1,2", implode(',', $ph->getOutput('out')));
        self::assertEquals("3", implode(',', $ph->getOutput('err')));
        if (-1 !== $returnCode) {
            self::assertEquals($returnCode, $ph->getReturnCode());
            self::assertEquals($returnCode, $ret);
        } else {
            self::assertEquals(160, $ph->getReturnCode());
            self::assertEquals(160, $ret);
        }
        self::assertFalse($this->getConf($ph)->hasContext('command'));
    }

   /**
     * Test execProcess method
     *
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::execProcess
     *
     */
    public function testRealProcessExecution(): void
    {
        // test real execution
        $ph = new ProcessHelper($this->logger);
        $ph->execCommand(['composer', '--version']);
        self::assertMatchesRegularExpression('/Composer version 2/', $ph->getOutput()[0]);
    }

    /**
     * Undocumented function
     *
     * @return array<string,array<mixed>>
     */
    public function closeProcessData()
    {
                                //                   except°   exit      ok     should
        $data = [];             //       out_mode    on err    code,    Code    throw     label
        $data['labeled_success    '] = [ 'default' , true,     0,       null,   false,    'cmd1' ];
        $data['unlabeled_success  '] = [ 'default' , true,     0,       null,   false,    null   ];
        $data['on_error_fail      '] = [ 'on_error', false,    100,     null,   false,    null   ];
        $data['exc.Thrown (null)  '] = [ 'default' , true,     100,     null,   true,     null   ];
        $data['exc.Thrown (not in)'] = [ 'default' , true,     100,     200,    true,     null   ];
        $data['exc.Not Thrown     '] = [ 'default' , true,     100,     100,    false,    null   ];

        return $data;
    }
    /**
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::closeProcess
     *
     * @dataProvider closeProcessData
     *
     * @param string      $om       output mode
     * @param bool        $eoe      exception on error
     * @param int         $exitCode
     * @param int|null    $okCode
     * @param bool        $throw
     * @param string|null $label
     *
     * @return void
     */
    public function testCloseProcess($om, $eoe, $exitCode, $okCode, $throw, $label)
    {
        $ref = new ReflectionClass(ProcessHelper::class);
        $cpmeth = $ref->getMethod('closeProcess');
        $cpmeth->setAccessible(true);

        /** @var Mockery\Mock $po */
        $po = \Mockery::mock(ProcessOutput::class);
        $po->makePartial();

        /** @var Mockery\Mock $proc */
        $proc = \Mockery::mock(Process::class);
        $proc->makePartial();

        // prepare things
        $logCtx = [];
        if (null !== $label) {
            $logCtx['label'] = $label;
        }
        $opts = new ConfigHelper(new CONF());
        $opts->set(CONF::OUTPUT_MODE, $om);
        $opts->set(CONF::EXCEPTION_ON_ERROR, $eoe);
        if (null !== $okCode) {
            $opts->set(CONF::EXIT_CODES_OK, [ $okCode ]);
        }

        /** @var Mockery\Mock $ph */
        $ph = \Mockery::mock(ProcessHelper::class);
        $ph->makePartial();
        $ph->shouldAllowMockingProtectedMethods();

        $err = "error (code=$exitCode) running command: 'test_command'";
        $success = 0 === $exitCode ? true : false;
        //
        $po->shouldReceive('closeProgress')->once();                                         /** @phpstan-ignore-line */
        $proc->shouldReceive('isSuccessful')->once()->andReturn($success);                   /** @phpstan-ignore-line */
        if (!$success) {
            if ('on_error' === $om) {
                $ph->shouldReceive('outputToLog')->with($po)->once();                        /** @phpstan-ignore-line */
            }
            $proc->shouldReceive('getExitCodeText')->once()->andReturn('exit text');         /** @phpstan-ignore-line */
            $proc->shouldReceive('getExitCode')->once()->andReturn(100);                     /** @phpstan-ignore-line */
            $po->shouldReceive('log')->with('error', 'exit text');                           /** @phpstan-ignore-line */
            if (true === $eoe) {
                $proc->shouldReceive('getCommandLine')->once()->andReturn('test_command');   /** @phpstan-ignore-line */
                if ($exitCode === $okCode) {
                    $po->shouldReceive('log')->with('notice', "IGNORED: $err")->once();      /** @phpstan-ignore-line */
                }
            }
        } else {
            $successText = "command was successfull !";
            if (null !== $label) {
                $po->shouldReceive('log')->with('info', "{label} - $successText")->once(); /** @phpstan-ignore-line */
            } else {
                $po->shouldReceive('log')->with('info', $successText)->once();             /** @phpstan-ignore-line */
            }
        }
        $msg = '';
        try {
            $cpmeth->invokeArgs($ph, [$proc, $opts, $po, $logCtx]);
        } catch (ProcessException $e) {                                                      /** @phpstan-ignore-line */
            $msg = $e->getMessage();
        }
        $expected = '';
        if ($throw) {
            $expected = $err;
        }
        self::assertEquals($expected, $msg);
    }

    /**
     * test regexp searches on output and related functions
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::search
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::addSearch
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::getMatches
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::resetMatches
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::resetSearches
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::execProcess
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
        $output = [];
        $output[] = [ 'out' => 'foo=2'];
        $output[] = [ 'out' => 'bar=3'];
        $output[] = [ 'out' => 'foo=5'];
        $output[] = [ 'err' => 'foobar_err'];
        $output[] = [ 'out' => 'foobar_out'];
        $this->makeProcessMock('cmd', [], 0, $output);
        $ph = new PH($this->logger, []);
        $ph->addSearch('foo', 'foo=(.*)');
        $ph->addSearch('bar', 'bar=(.*)');
        $ph->addSearch('foobar', 'foobar_(.*)', 'err');

        $conf = $this->getConf($ph);
        $ph->execCommand(['ls', '-l', '/foobar/baz']);
        self::assertEquals([2, 5], $ph->getMatches('foo'));
        self::assertEquals([3], $ph->getMatches('bar'));
        self::assertEquals(['err'], $ph->getMatches('foobar'));

        $ph->resetMatches('foo');
        self::assertEmpty($ph->getMatches('foo'));
        self::assertEquals([3], $ph->getMatches('bar'));
        $ph->resetMatches();
        self::assertEmpty($ph->getMatches('bar'));
        $message = '';
        try {
            $ph->resetMatches('baz');
        } catch (BadSearchException $e) {
            $message = $e->getMessage();
        }
        self::assertMatchesRegularExpression("#resetMatches: No match on variable#", $message);
        $message = '';
        try {
            $ph->getMatches('baz');
        } catch (BadSearchException $e) {
            $message = $e->getMessage();
        }
        self::assertMatchesRegularExpression("#getMatches: Can't search for a match on variable#", $message);
        self::assertEquals([], $ph->getMatches('bar'));
        $ph->resetSearches();
        self::assertFalse($conf->hasContext('searches'));
        $message = '';
        try {
            $ph->getMatches('bar');
        } catch (BadSearchException $e) {
            $message = $e->getMessage();
        }
        self::assertMatchesRegularExpression("#getMatches: Can't search for a match on variable#", $message);
        $message = '';
        try {
            $ph->addSearch('foobar', 'foobar_(.*)', 'this_is_not_in_enum');
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }
        self::assertMatchesRegularExpression('#Permissible values: "out", "err", null#', $message);
    }
    /**
     * tests the printOptions method
     * @covers DgfipSI1\ProcessHelper\ProcessHelper::printOptions
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
            $m->shouldReceive('isSuccessful')->andThrow(ProcessTimedOutException::class, $m, 1);
        } elseif (0 === $returnCode) {
            $m->shouldReceive('isSuccessful')->andReturn(true);                 /* @phpstan-ignore-line */
        } else {
            $m->shouldReceive('isSuccessful')->andReturn(false);                /* @phpstan-ignore-line */
            $m->shouldReceive('getExitCode')->andReturn($returnCode);           /* @phpstan-ignore-line */
            $m->shouldReceive('getExitCodeText')->andReturn($exitText);         /* @phpstan-ignore-line */
        }

        return $m;
    }
    /**
     * returns mock object with given default configuration
     *
     * @param array<string,mixed> $options
     *
     * @return ProcessHelper
     */
    protected function createProcessHelperMock($options)
    {
        /** @var Mock $ph */
        $ph = Mockery::mock(ProcessHelper::class);
        $ph->shouldAllowMockingProtectedMethods();
        $ph->makePartial();
        /** @var ProcessHelper $ph */
        $ph->setOptions($options);
        $this->logger = new TestLogger();
        $ph->setLogger($this->logger);

        return $ph;
    }
    /**
     * returns config on object ($mock or real processHelper object)
     *
     * @param Mock|ProcessHelper $ph
     *
     * @return ConfigHelper
     */
    private function getConf($ph)
    {
        $confProp = (new ReflectionClass($ph::class))->getProperty('conf');
        $confProp->setAccessible(true);
        /** @var ConfigHelper $conf */
        $conf = $confProp->getValue($ph);

        return $conf;
    }
}
