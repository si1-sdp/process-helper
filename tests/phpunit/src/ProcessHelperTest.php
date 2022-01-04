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
use Symfony\Component\Process\Process;
use jmg\ProcessHelper\ProcessHelper;
use Psr\Log\Test\TestLogger;
use jmg\processHelperTests\LogTesterTrait;

/**
 * @covers \jmg\ProcessHelper\ProcessHelper
 *
 */
class ProcessHelperTest extends TestCase
{
    use LogTesterTrait;

    /** @var LoggerInterface $logger*/
    protected $logger;

    public function testExecCommand(): void
    {

        $this->makeProcessMock(['a', 'b', 'c']);
        $this->logger = new TestLogger();
        $ph = new ProcessHelper($this->logger);
        $ph->execCommand('toto');
        $this->assertLogInfo('CMD = toto');
        $this->showDebugLogs();
        $this->showNoDebugLogs();
        //print "COUNT : ".$startStub->callCount()."\n";

    }

    /**
     * get mock object for process
     *
     * @return MockObject
     */
    protected function makeProcessMock($lines) {
        $m = Mockery::mock('overload:Symfony\Component\Process\Process')->makePartial();
        $m->shouldReceive('getIterator')->andReturn($lines);
        //$m->shouldReceive('getIterator')->once()->andReturnUsing($generate($lines));
        //$m->shouldReceive('getIterator')->withNoArgs()->once()->andYield($lines);
        $m->shouldReceive('start')->withNoArgs();
        $m->shouldReceive('setTimeout')->with(60);
        $m->shouldReceive('isSuccessful')->withNoArgs()->andReturn(true);

        return $m;
    }

}