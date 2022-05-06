<?php
/*
 * This file is part of jmg/ProcessHelper.
 *
 * (c) Jean-Marie Gervais jm.gervais@gmail.com
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace jmg\processHelperTests;

use \Mockery;
use PHPUnit\Framework\TestCase;
use jmg\ProcessHelper\ProcessHelper as PH;
use Psr\Log\Test\TestLogger;
use jmg\processHelperTests\LogTesterTrait;
use Psr\Log\LoggerInterface;

/**
 * @covers \jmg\ProcessHelper\ProcessHelper
 *
 */
class ProcessHelperTest extends TestCase
{
    use LogTesterTrait;

    /** @var LoggerInterface $logger*/
    protected $logger;

   /**
     * @return array<string,mixed>
     */
    public function dataExecCommand(): array
    {
        $out = [
            [ 'out' => 'Starting Mock process'],
            [ 'out' => 'Scanning for config'  ],
            [ 'err' => 'No config here'       ],
        ];
        $opts = [];
        //                       exec,    output   rc,  runTime,
        //                       Opts     lines
        //$data['plain_run']    = [$opts   , $out,   0,   0           ];

        $opts = [PH::PH_RUN_IN_SHELL => true];
        $data['run_in_shell'] = [$opts   , $out,   0,   0           ];

        return $data;
    }
   /**
     * Test execCommand method
     * @param array<mixed>                $opts 
     * @param array<array<string,string>> $output
     * @param int                         $runTime 
     * @param int                         $returnCode

     *
     * @dataProvider dataExecCommand
     */
  
    public function testExecCommand($opts, $output, $returnCode, $runTime): void
    {
        $this->logger = new TestLogger();
        $mockData = [
            'lines'          => $output,
            'timeout'        => 60,
            'return_code'    => $returnCode,
            'exec_opts'      => $opts,
        ];

        $this->makeProcessMock($mockData);
        $ph = new PH($this->logger, $opts);
        $ph->execCommand(['ls', '-l', '/foobar/baz']);
        $this->assertLogInfo('CMD = {cmd}', [ 'cmd' => '/path/to/cmd' ]);
        $this->showDebugLogs();
        $this->showNoDebugLogs();
    }
    public function testExecCommand2(): void
    {
        $this->logger = new TestLogger();
        $mockData = [
            'lines'          => [ ['err' => 'foo']],
            'timeout'        => 60,
            'return_code'    => 0,
            'exec_opts'      => [],
        ];

        $this->makeProcessMock($mockData);
        $ph = new PH($this->logger);
        $ph->execCommand(['ls', '-l', '/foobar/baz']);
        $this->assertLogInfo('CMD = {cmd}', [ 'cmd' => '/path/to/cmd' ]);
        $this->showDebugLogs();
        $this->showNoDebugLogs();
    }
    /**
     * get mock object for process
     * @param array<string,mixed> $mockData
     *
     * @return Mockery\Mock
     */
    protected function makeProcessMock($mockData)
    {
        /** @var Mockery\Mock */
        $m = \Mockery::mock('overload:Symfony\Component\Process\Process')->makePartial();
        /**
         * Generator for output
         *
         * @param array<array<string,string>> $lines
         *
         * @return \Iterator<string>
         */

        $generator = function($lines) {
            return function () use ($lines) {
                foreach ($lines as $content) {
                    foreach ($content as $outputType => $line) {
                        yield $outputType => $line;
                    }
                }
            };
        };
        $options = $mockData['exec_opts'];
        print_r($options);
        print "EXISTS: ".print_r(array_key_exists(PH::PH_RUN_IN_SHELL, $options), true)."\n";
        if (array_key_exists(PH::PH_RUN_IN_SHELL, $options) && true === $options[PH::PH_RUN_IN_SHELL]) {
            $m->shouldReceive('fromShellCommandline')->andReturn($m);
        }
        $m->shouldReceive('getIterator')->andReturn($generator($mockData['lines']));
        $m->shouldReceive('getCommandLine')->andReturn('/path/to/cmd');
        $m->shouldReceive('start');
        /** @phpstan-ignore-next-line */
        $expect = $m->shouldReceive('setTimeout')->with($mockData['timeout']);
        if (0 === $mockData['return_code']) {
            $m->shouldReceive('isSuccessful')->andReturn(true);
        } else {
            $m->shouldReceive('isSuccessful')->andReturn(false);
            $m->shouldReceive('getExitCode')->andReturn($mockData['return_code']);
            if (array_key_exists('exitText', $mockData)) {
                $exitText = $mockData['exitText'];
            } else {
                $exitText = 'exitText';
            }
            $m->shouldReceive('getExitCodeText')->andReturn($exitText);
        }
        return $m;
    }
}
