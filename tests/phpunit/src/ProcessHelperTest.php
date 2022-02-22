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
use jmg\ProcessHelper\ProcessHelper;
use Psr\Log\Test\TestLogger;
use jmg\processHelperTests\LogTesterTrait;
//use PHPUnit\Framework\MockObject\MockObject;
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
     * Test execCommand method
     */
    public function testExecCommand(): void
    {
        $this->logger = new TestLogger();
        $mockData = [
            'lines' => [
                [ 'out' => 'Starting Mock process'],
                [ 'out' => 'Scanning for config'  ],
                [ 'err' => 'No config here'       ],
            ],
            'timeout' => 60,
            'exit'    => 1,
        ];

        $this->makeProcessMock($mockData);
        $ph = new ProcessHelper($this->logger);
        $ph->execCommand(['ls', '-l', '/foobar/baz']);
        $this->assertLogInfo('CMD = {cmd}', [ 'cmd' => '/path/to/cmd' ]);
        $this->showDebugLogs();
        $this->showNoDebugLogs();
        //print "COUNT : ".$startStub->callCount()."\n";
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

        $generator = function ($lines) {
            foreach ($lines as $content) {
                foreach ($content as $outputType => $line) {
                    yield $outputType => $line;
                }
            }
        };
        $m->shouldReceive('getIterator')->andReturn($generator($mockData['lines']));
        $m->shouldReceive('getCommandLine')->andReturn('/path/to/cmd');
        /** @phpstan-ignore-next-line */
        $m->shouldReceive('start')->withNoArgs();
        /** @phpstan-ignore-next-line */
        $expect = $m->shouldReceive('setTimeout')->with($mockData['timeout']);
        if (0 === $mockData['exit']) {
            /** @phpstan-ignore-next-line */
            $m->shouldReceive('isSuccessful')->withNoArgs()->andReturn(true);
        } else {
            /** @phpstan-ignore-next-line */
            $m->shouldReceive('isSuccessful')->withNoArgs()->andReturn(false);
            /** @phpstan-ignore-next-line */
            $m->shouldReceive('getExitCode')->withNoArgs()->andReturn($mockData['exit']);
            if (array_key_exists('exitText', $mockData)) {
                $exitText = $mockData['exitText'];
            } else {
                $exitText = 'exitText';
            }
            /** @phpstan-ignore-next-line */
            $m->shouldReceive('getExitCodeText')->withNoArgs()->andReturn($exitText);
        }

        return $m;
    }
}
