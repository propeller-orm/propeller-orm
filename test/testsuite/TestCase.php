<?php

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

    protected function useDebug(PropelPDO $con): callable
    {
        return $this->useEffect(function () use ($con) {
            $con->useDebug(true);

            return function () use ($con) {
                $con->useDebug(false);
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
    protected function useEffect(callable $callback): callable {
        $revert = $callback();

        if (! is_callable($revert)) {
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