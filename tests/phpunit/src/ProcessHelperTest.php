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
use jmg\ProcessHelper\ProcessHelperOptions as PHO;

use Psr\Log\Test\TestLogger;
use jmg\processHelperTests\LogTesterTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * @covers \jmg\ProcessHelper\ProcessHelper
 *
 * @uses \jmg\ProcessHelper\ProcessHelperOptions
 */
class ProcessHelperTest extends TestCase
{
    use LogTesterTrait;

    /** @var LoggerInterface $logger*/
    protected $logger;

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
            [ 'out' => 'Starting Mock process'],
            [ 'out' => 'Scanning for config'  ],
            [ 'err' => 'No config here'       ],
        ];
        $opts = [];

        $data['run_simple']   = [ []                                , $out,   0,   0           ];
        $data['run_in_shell'] = [ [PHO::PH_RUN_IN_SHELL => true]    , $out,   0,   0           ];
        $data['run_progress'] = [ [PHO::PH_DISPLAY_PROGRESS => true], $out,   0,   0           ];
        $data['run_error']    = [ []                                , $out,   2,   0           ];
        $data['run_timeout']  = [ []                                , $out,  -1,   0           ];

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
     * @param array<string,mixed> $mockData
     *
     * @return Mockery\Mock
     */
    protected function makeProcessMock($mockData)
    {
        /** @var Mockery\Mock */
        $m = \Mockery::mock('overload:Symfony\Component\Process\Process')->makePartial();
        $options = $mockData['exec_opts'];
        if (array_key_exists(PHO::PH_RUN_IN_SHELL, $options) && true === $options[PHO::PH_RUN_IN_SHELL]) {
            $m->shouldReceive('fromShellCommandline')->andReturn($m);
        }
        $m->shouldReceive('getIterator')->andReturn(self::generator($mockData['lines']));
        $m->shouldReceive('getCommandLine')->andReturn('/path/to/cmd');
        $m->shouldReceive('start');
        /** @phpstan-ignore-next-line */
        $expect = $m->shouldReceive('setTimeout')->with($mockData['timeout']);
        if (-1 === $mockData['return_code']) {
            $m->shouldReceive('getTimeout')->andReturn(60);
            $m->shouldReceive('isSuccessful')->andThrow(new ProcessTimedOutException($m, 1));
        } elseif (0 === $mockData['return_code']) {
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
