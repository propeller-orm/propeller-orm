<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * <p>Unit tests for class <code>NameFactory</code> and known
 * <code>NameGenerator</code> implementations.</p>
 *
 * <p>To add more tests, add entries to the <code>ALGORITHMS</code>,
 * <code>INPUTS</code>, and <code>OUTPUTS</code> arrays, and code to
 * the <code>makeInputs()</code> method.</p>
 *
 * <p>This test assumes that it's being run using the MySQL database
 * adapter, <code>DBMM</code>.  MySQL has a column length limit of 64
 * characters.</p>
 *
 * @author     <a href="mailto:dlr@collab.net">Daniel Rall</a>
 * @version    $Id$
 * @package    generator.model
 */
class NameFactoryTest extends BaseTestCase
{
    /** The database to mimic in generating the SQL. */
    const DATABASE_TYPE = "mysql";

    /**
     * The list of known name generation algorithms, specified as the
     * fully qualified class names to <code>NameGenerator</code>
     * implementations.
     */
    private static $ALGORITHMS = [NameFactory::CONSTRAINT_GENERATOR, NameFactory::PHP_GENERATOR];

    /**
     * Two-dimensional arrays of inputs for each algorithm.
     */
    private static $INPUTS = [];


    /**
     * Given the known inputs, the expected name outputs.
     */
    private static $OUTPUTS = [];

    /**
     * Used as an input.
     * @var Database
     */
    private $database;

    public static function setUpBeforeClass(): void
    {
        self::$INPUTS = [
            [
                [self::makeString(61), "I", 1],
                [self::makeString(61), "I", 2],
                [self::makeString(65), "I", 3],
                [self::makeString(4), "FK", 1],
                [self::makeString(5), "FK", 2],
            ],
            [
                ["MY_USER", NameGenerator::CONV_METHOD_UNDERSCORE],
                ["MY_USER", NameGenerator::CONV_METHOD_PHPNAME],
                ["MY_USER", NameGenerator::CONV_METHOD_NOCHANGE],
            ],
        ];


        self::$OUTPUTS = [
            [
                self::makeString(60) . "_I_1",
                self::makeString(60) . "_I_2",
                self::makeString(60) . "_I_3",
                self::makeString(4) . "_FK_1",
                self::makeString(5) . "_FK_2",
            ],
            ["MyUser", "MYUSER", "MY_USER"],
        ];

    }

    /**
     * Creates a string of the specified length consisting entirely of
     * the character <code>A</code>.  Useful for simulating table
     * names, etc.
     *
     * @param int  $len the number of characters to include in the string
     *
     * @return string of length <code>len</code> with every character an 'A'
     */
    private static function makeString($len)
    {
        $buf = "";
        for ($i = 0; $i < $len; $i++) {
            $buf .= 'A';
        }

        return $buf;
    }

    /** Sets up the Propel model. */
    public function setUp(): void
    {
        $appData = new AppData(new MysqlPlatform());
        $this->database = new Database();
        $appData->addDatabase($this->database);
    }

    /**
     * @throws Exception on fail
     */
    public function testNames()
    {
        for ($algoIndex = 0; $algoIndex < count(self::$ALGORITHMS); $algoIndex++) {
            $algo = self::$ALGORITHMS[$algoIndex];
            $algoInputs = self::$INPUTS[$algoIndex];
            for ($i = 0; $i < count($algoInputs); $i++) {
                $inputs = $this->makeInputs($algo, $algoInputs[$i]);
                $generated = NameFactory::generateName($algo, $inputs);
                $expected = self::$OUTPUTS[$algoIndex][$i];
                $this->assertEquals($expected, $generated, "Algorithm " . $algo . " failed to generate an unique name");
            }
        }
    }

    /**
     * Creates the list of arguments to pass to the specified type of
     * <code>NameGenerator</code> implementation.
     *
     * @param string  $algo   The class name of the <code>NameGenerator</code> to
     *                        create an argument list for.
     * @param string  $inputs The (possibly partial) list inputs from which to
     *                        generate the final list.
     * @return array the list of arguments to pass to the <code>NameGenerator</code>
     */
    private function makeInputs($algo, $inputs)
    {
        if (NameFactory::CONSTRAINT_GENERATOR == $algo) {
            array_unshift($inputs, $this->database);
        }

        return $inputs;
    }

}
