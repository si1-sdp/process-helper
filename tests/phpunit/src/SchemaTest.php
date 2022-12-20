<?php
declare(strict_types=1);

/*
 * This file is part of drupalindus
 */

namespace jmg\processHelperTests;

use DgfipSI1\ConfigHelper\ConfigHelper;
use PHPUnit\Framework\TestCase;
use DgfipSI1\ProcessHelper\ConfigSchema as CONF;
use Exception;

/**
 * @covers \DgfipSI1\ProcessHelper\ConfigSchema
 */
class SchemaTest extends TestCase
{
    /**
     * data provider for testSchemaErrors
     *
     * @return array<string,array<mixed>>
     */
    public function shemaErrorsData()
    {
        return [                  // errors
            CONF::RUN_IN_SHELL     => [ CONF::RUN_IN_SHELL,     'foo'   ],
            CONF::TIMEOUT          => [ CONF::TIMEOUT,          0       ],
            CONF::DRY_RUN          => [ CONF::DRY_RUN,          'foo'   ],
            CONF::FIND_EXECUTABLE  => [ CONF::FIND_EXECUTABLE,  'foo'   ],
            CONF::DIRECTORY        => [ CONF::DIRECTORY,        []       ],
            CONF::OUTPUT_MODE      => [ CONF::OUTPUT_MODE,      'foo'   ],
            CONF::OUTPUT_STDOUT_TO => [ CONF::OUTPUT_STDOUT_TO, 'foo'   ],
            CONF::OUTPUT_STDERR_TO => [ CONF::OUTPUT_STDERR_TO, 'foo'   ],
            CONF::ENV_VARS         => [ CONF::ENV_VARS,         'foo'   ],
            CONF::USE_APPENV       => [ CONF::USE_APPENV,       'foo'   ],
            CONF::USE_DOTENV       => [ CONF::USE_DOTENV,       'foo'   ],
            CONF::DOTENV_DIR       => [ CONF::ENV_VARS,         0],
        ];
    }
    /**
     * @dataProvider shemaErrorsData
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     */
    public function testSchemaErrors($key, $value)
    {
        $conf = new ConfigHelper(new CONF());
        $msg = '';
        try {
            $conf->set($key, $value);
            $conf->build();
        } catch (\Exception $e) {
            $msg = $e->getMessage();
        }
        self::assertMatchesRegularExpression("/$key/", $msg);
    }

    /**
     * data provider for testSchemaNominal
     *
     * @return array<string,array<mixed>>
     */
    public function shemaNominalData()
    {
        return [                       // not default but nominal value
            CONF::RUN_IN_SHELL         => [ CONF::RUN_IN_SHELL,     true        ],
            CONF::TIMEOUT              => [ CONF::TIMEOUT,          1           ],
            CONF::DRY_RUN              => [ CONF::DRY_RUN,          true        ],
            CONF::FIND_EXECUTABLE      => [ CONF::FIND_EXECUTABLE,  true        ],
            CONF::DIRECTORY            => [ CONF::DIRECTORY,        '/tmp'      ],
            CONF::OUTPUT_MODE.'1'      => [ CONF::OUTPUT_MODE,      'silent'    ],
            CONF::OUTPUT_MODE.'2'      => [ CONF::OUTPUT_MODE,      'progress'  ],
            CONF::OUTPUT_MODE.'3'      => [ CONF::OUTPUT_MODE,      'on_error'  ],
            CONF::OUTPUT_STDOUT_TO.'1' => [ CONF::OUTPUT_STDOUT_TO, 'debug'     ],
            CONF::OUTPUT_STDOUT_TO.'2' => [ CONF::OUTPUT_STDOUT_TO, 'notice'    ],
            CONF::OUTPUT_STDERR_TO.'1' => [ CONF::OUTPUT_STDERR_TO, 'warning'   ],
            CONF::OUTPUT_STDERR_TO.'2' => [ CONF::OUTPUT_STDERR_TO, 'critical'  ],
            CONF::OUTPUT_STDERR_TO.'3' => [ CONF::OUTPUT_STDERR_TO, 'alert'     ],
            CONF::OUTPUT_STDERR_TO.'4' => [ CONF::OUTPUT_STDERR_TO, 'emergency' ],
            CONF::ENV_VARS             => [ CONF::ENV_VARS,         ['foo' => 1]],
            CONF::USE_APPENV           => [ CONF::USE_APPENV,       false       ],
            CONF::USE_DOTENV           => [ CONF::USE_DOTENV,       true        ],
        ];
    }
    /**
     * @dataProvider shemaNominalData
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     */
    public function testSchemaNominal($key, $value)
    {
        $conf = new ConfigHelper(new CONF());
        self::assertNotEquals($value, $conf->get($key));
        $conf->set($key, $value);
        $conf->build();
        self::assertEquals($value, $conf->get($key));
    }

    /**
     *
     * @return void
     */
    public function testSchemaDefaultValues()
    {
        $conf = new ConfigHelper(new CONF());
        $conf->build();
        self::assertEquals(false, $conf->get(CONF::RUN_IN_SHELL));
        self::assertEquals(CONF::DEFAULT_TIMEOUT, $conf->get(CONF::TIMEOUT));
        self::assertEquals(false, $conf->get(CONF::DRY_RUN));
        self::assertEquals(false, $conf->get(CONF::FIND_EXECUTABLE));
        self::assertEquals(null, $conf->get(CONF::DIRECTORY));

        self::assertEquals('default', $conf->get(CONF::OUTPUT_MODE));
        self::assertEquals('info', $conf->get(CONF::OUTPUT_STDOUT_TO));
        self::assertEquals('error', $conf->get(CONF::OUTPUT_STDERR_TO));

        self::assertEquals([], $conf->get(CONF::ENV_VARS));
        self::assertEquals(true, $conf->get(CONF::USE_APPENV));
        self::assertEquals(false, $conf->get(CONF::USE_DOTENV));
        self::assertEquals('.', $conf->get(CONF::DOTENV_DIR));

        self::assertEquals(true, $conf->get(CONF::EXCEPTION_ON_ERROR));
        self::assertEquals([], $conf->get(CONF::EXIT_CODES_OK));
    }

    /**
     * @return array<string,array<mixed>>
     */
    public function searchData()
    {
        return [
            'stdout  ' => [ 'out_test',      '/re', 'out'   ],
            'error   ' => [ 'err_test',      '/re', 'err'   ],
            'exeption' => [ 'exeption_test', '/re', 'bang!' ] ,
        ];
    }
    /**
     * @dataProvider searchData
     *
     * @param string $name
     * @param string $regexp
     * @param string $type
     *
     * @return void
     */
    public function testSearches($name, $regexp, $type)
    {
        $conf = new ConfigHelper(new CONF());
        $search = [ 'name' => $name, 'regexp' => $regexp, 'type' => $type  ];
        $msg = '';
        try {
            $conf->set(CONF::OUTPUT_RE_SEARCHES, [ $search ]);
            $conf->build();
        } catch (Exception $e) {
            $msg = $e->getMessage();
        }
        if ('bang!' === $type) {
            self::assertMatchesRegularExpression("/".CONF::OUTPUT_RE_SEARCHES."/", $msg);
        } else {
            /** @var array<array<string,string>> $s*/
            $s = $conf->get(CONF::OUTPUT_RE_SEARCHES);

            self::assertEquals($name, $s[0]['name']);
            self::assertEquals($regexp, $s[0]['regexp']);
            self::assertEquals($type, $s[0]['type']);
        }
    }
}
