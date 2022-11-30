<?php

namespace Propeller\Tests;

use Propel;
use PropelException;
use PropelPDO;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var callable[] */
    private $teardown = [];

    protected function tearDown(): void
    {
        parent::tearDown();

        while ($callback = array_pop($this->teardown)) {
            $callback();
        }
    }

    /**
     * Get connection PDO object that will be automatically triggered
     * to rollback unfinished transactions, if any, after the test.
     *
     * @param string  $name
     * @return PropelPDO
     * @throws PropelException
     */
    protected function getConnection(string $name): PropelPDO
    {
        $connection = Propel::getConnection($name);

        assert($connection instanceof PropelPDO);

        $this->rollbackOnTearDown($connection);

        return $connection;
    }

    protected function rollbackOnTearDown(PropelPDO $con): void
    {
        $this->useEffect(function () use ($con) {
            return function () use ($con) {
                $con->rollback();
            };
        });
    }

    protected function useDebug(PropelPDO $con, bool $debug = true): callable
    {
        return $this->useEffect(function () use ($con, $debug) {
            $wasDebug = $con->useDebug($debug);

            return function () use ($con, $wasDebug) {
                $con->useDebug($wasDebug);
            };
        });
    }

    /**
     * Modify global state, storing a function to revert
     * to the original state after the test.
     *
     * @param callable  $callback
     * @return callable Revert the effect manually.
     */
    protected function useEffect(callable $callback): callable
    {
        $revert = $callback();

        if (!is_callable($revert)) {
            throw new InvalidArgumentException('useEffect() callback should return a `revert` callable.');
        }

        $this->teardown[] = $revert;

        return function () use ($revert) {
            $revert();

            $this->teardown = array_filter($this->teardown, function ($callback) use ($revert) {
                return $callback !== $revert;
            });
        };
    }
}