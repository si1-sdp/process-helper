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

use Generator;
use \Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use jmg\ProcessHelper\ProcessHelper;
use Psr\Log\Test\TestLogger;
use jmg\processHelperTests\LogTesterTrait;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;

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
        $ph->execCommand('ls -l /foobar/baz');
        $this->assertLogInfo('CMD = {cmd}', [ 'cmd' => '/path/to/cmd' ]);
        $this->showDebugLogs();
        $this->showNoDebugLogs();
        //print "COUNT : ".$startStub->callCount()."\n";
    }

    /**
     * get mock object for process
     * @param array<string,mixed> $mockData
     *
     * @return MockObject
     */
    protected function makeProcessMock($mockData)
    {
        $m = Mockery::mock('overload:Symfony\Component\Process\Process')->makePartial();
        /**
         * Generator for output
         *
         * @param array<array<string,string>> $lines
         *
         * @return void
         */
        function generator($lines)
        {
            foreach ($lines as $content) {
                foreach ($content as $outputType => $line) {
                    yield $outputType => $line;
                }
            }
        }
        $m->shouldReceive('getIterator')->andReturn(generator($mockData['lines']));
        $m->shouldReceive('getCommandLine')->andReturn('/path/to/cmd');
        $m->shouldReceive('start')->withNoArgs();
        $m->shouldReceive('setTimeout')->with($mockData['timeout']);
        if (0 === $mockData['exit']) {
            $m->shouldReceive('isSuccessful')->withNoArgs()->andReturn(true);
        } else {
            $m->shouldReceive('isSuccessful')->withNoArgs()->andReturn(false);
            $m->shouldReceive('getExitCode')->withNoArgs()->andReturn($mockData['exit']);
            if (array_key_exists('exitText', $mockData)) {
                $exitText = $mockData['exitText'];
            } else {
                $exitText = 'exitText';
            }
            $m->shouldReceive('getExitCodeText')->withNoArgs()->andReturn($exitText);
        }

        return $m;
    }
}
