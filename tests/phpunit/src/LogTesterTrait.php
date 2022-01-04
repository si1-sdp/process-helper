<?php
/*
 * This file is part of d8ci
 */
namespace jmg\processHelperTests;

/**
 *
 * Implements helpers methods on $this->logger which has to be a TestLogger object
 * Disables phpstan on TestLogger specific methods and properties
 *
 */
trait LogTesterTrait
{
    /**
     * Assert message is in log level $level, then purge message
     *
     * @param string $message
     * @param string $level
     */
    public function assertLogAndPurge($message, $level): void
    {
        $this->assertTrue($this->logContains($message, $level, true), "message [$level]$message not found in log");
    }
    /**
     * Assert message warning exists, then purge message
     *
     * @param string $message
     */
    public function assertLogWarning($message): void
    {
        $this->assertLogAndPurge($message, 'warning');
    }
   /**
     * Assert message info exists, then purge message
     *
     * @param string $message
     */
    public function assertLogInfo($message): void
    {
        $this->assertLogAndPurge($message, 'info');
    }
   /**
     * Assert message error exists, then purge message
     *
     * @param string $message
     */
    public function assertLogError($message): void
    {
        $this->assertLogAndPurge($message, 'error');
    }
    /**
     * assertNoMore function : assert that no more messages of level $level are left in log
     *
     * @param string $level
     *
     * @return void
     */
    public function assertNoMore($level): void
    {
        $recordsByLevel = $this->logGetRecordsByLevelProperty();
        if (array_key_exists($level, $recordsByLevel)) {
            $messages = count($recordsByLevel[$level]);
            $explain = ucfirst($level)." messages left : $messages\n".print_r($recordsByLevel[$level], true);
            $this->assertEquals(0, $messages, $explain);
        }
    }
    /**
     * assert no warning messages in log
     *
     * @return void
     */
    public function assertNoMoreWarnings()
    {
        $this->assertNoMore('warning');
    }
    /**
     * assert no info messages in log
     *
     * @return void
     */
    public function assertNoMoreInfos()
    {
        $this->assertNoMore('info');
    }
    /**
     * assert no error messages in log
     *
     * @return void
     */
    public function assertNoMoreErrors()
    {
        $this->assertNoMore('error');
    }
    /**
     * Assert all logLuvels (except Debug) are empty
     *
     * @return void
     */
    public function assertLogEmpty()
    {
        $recordsByLevel = $this->logGetRecordsByLevelProperty();
        $msg = '';
        foreach ($recordsByLevel as $level => $records) {
            if ('debug' === $level) {
                continue;
            }
            if (count($records) > 0) {
                $msg .= count($records)." message(s) left in level $level\n";
            }
        }
        $this->assertEmpty($msg, "Fail asserting that log is empty :\n$msg");
    }

    /**
     *
     * @param string $message
     * @param string $level
     * @param bool   $delete
     *
     * @return bool
     */
    public function logContains($message, $level, $delete = false)
    {
        $recordsByLevel = $this->logGetRecordsByLevelProperty();
        $toDelete = [];
        $ret = false;

        if (isset($recordsByLevel[$level])) {
            foreach ($recordsByLevel[$level] as $i => $rec) {
                if (strpos($rec['message'], $message) !== false) {
                    $ret = true;
                    $toDelete[] = $i;
                }
            }
        }

        if ($toDelete && $delete) {
            foreach ($toDelete as $index) {
                /** @phpstan-ignore-next-line */
                unset($this->logger->recordsByLevel[$level][$index]);
            }
        }

        return $ret;
    }
    /**
     * empty logs
     */
    public function logReset(): void
    {
        /** @phpstan-ignore-next-line */
        $this->logger->reset();
    }
    /**
     *
     * @return array<string,array<mixed>>
     */
    public function logGetRecordsByLevelProperty()
    {
        /** @phpstan-ignore-next-line */
        $ret = $this->logger->recordsByLevel;

        return $ret;
    }
    /**
     *
     * @param string $level
     *
     * @return array<mixed>
     */
    public function logGetrecordsByLevel(string $level)
    {
        $recordsByLevel = $this->logGetRecordsByLevelProperty();
        if (array_key_exists($level, $recordsByLevel)) {
            return $recordsByLevel[$level];
        }

        return [];
    }
    /**
     *
     * @return array<string>
     */
    public function logGetLevels()
    {
        $recordsByLevel = $this->logGetRecordsByLevelProperty();

        return array_keys($recordsByLevel);
    }
    /**
     *
     * @param bool $cut
     */
    public function showNoDebugLogs(bool $cut = false): void
    {
        $recordsByLevel = $this->logGetRecordsByLevelProperty();
        $printed = 0;
        $fmt = $cut ? "    %-76.76s\n" : "    %s\n";
        print "\n=============================================\n";
        foreach (array_keys($recordsByLevel) as $level) {
            if ('debug' === $level) {
                continue;
            }
            print "LEVEL $level\n";
            foreach ($recordsByLevel[$level] as $record) {
                printf($fmt, $record['message']);
                $printed++;
            }
        }
        print "$printed message(s) in logs (excluding debug)\n";
        print "=============================================\n";
    }
    /**
     * show debug logs
     *
     * @param bool $cut
     *
     * @return void
     */
    public function showDebugLogs(bool $cut = false): void
    {
        $recordsByLevel = $this->logGetRecordsByLevelProperty();
        $printed = 0;
        $fmt = $cut ? "    %-76.76s\n" : "    %s\n";
        print "\n=============================================\n";
        foreach (array_keys($recordsByLevel) as $level) {
            if ('debug' !== $level) {
                continue;
            }
            print "LEVEL $level\n";
            foreach ($recordsByLevel[$level] as $record) {
                printf($fmt, $record['message']);
                $printed++;
            }
        }
        print "$printed DEBUG message(s) in logs\n";
        print "=============================================\n";
    }
}
