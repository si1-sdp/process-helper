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
     * @param string               $message
     * @param string               $level
     * @param array<string,string> $context
     */
    public function assertLogAndPurge($message, $level, $context = []): void
    {
        $ctxString = '';
        if ($context) {
            $ctx = $context;
            array_walk($ctx, function (&$a, $b) {
                $a = "'$b' = '$a'";
            });
            $ctxString = " with context [ ".implode(', ', $ctx)." ]";
        }
        $explain = "message [$level]$message not found in log $ctxString";
        $this->assertTrue($this->logContains($message, $level, $context, true), $explain);
    }
    /**
     * Assert message warning exists, then purge message
     *
     * @param string               $message
     * @param array<string,string> $context
     */
    public function assertLogWarning($message, $context = []): void
    {
        $this->assertLogAndPurge($message, 'warning', $context);
    }
   /**
     * Assert message info exists, then purge message
     *
     * @param string               $message
     * @param array<string,string> $context
     */
    public function assertLogInfo($message, $context = []): void
    {
        $this->assertLogAndPurge($message, 'info', $context);
    }
   /**
     * Assert message error exists, then purge message
     *
     * @param string               $message
     * @param array<string,string> $context
     */
    public function assertLogError($message, $context = []): void
    {
        $this->assertLogAndPurge($message, 'error', $context);
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
     * @param string               $message
     * @param string               $level
     * @param array<string,string> $context
     * @param bool                 $delete
     *
     * @return bool
     */
    public function logContains($message, $level, $context = [], $delete = false)
    {
        $recordsByLevel = $this->logGetRecordsByLevelProperty();
        $toDelete = [];
        $ret = false;

        if (isset($recordsByLevel[$level])) {
            foreach ($recordsByLevel[$level] as $i => $rec) {
                if (strpos($rec['message'], $message) !== false) {
                    foreach ($context as $key => $value) {
                        if (!(array_key_exists($key, $rec['context']) && $rec['context'][$key] === $value)) {
                            continue 2;
                        }
                    }
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
        $fmt    = $cut ? "    %-76.76s\n" : "    %s\n";
        print "\n=============================================\n";
        foreach (array_keys($recordsByLevel) as $level) {
            if ('debug' === $level) {
                continue;
            }
            print "LEVEL $level\n";
            foreach ($recordsByLevel[$level] as $record) {
                printf($fmt, $record['message']);
                if ($record['context']) {
                    $ctx = $record['context'];
                    array_walk($ctx, function (&$a, $b) {
                        $a = "'$b' = '$a'";
                    });
                    print("    CONTEXT = [ ".implode(', ', $ctx)." ]\n");
                }
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
