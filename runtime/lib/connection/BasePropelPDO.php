<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Psr\Log\LoggerInterface;

/**
 * PDO connection subclass that provides the basic fixes to PDO that are required by Propel.
 *
 * This class was designed to work around the limitation in PDO where attempting to begin
 * a transaction when one has already been begun will trigger a PDOException.  Propel
 * relies on the ability to create nested transactions, even if the underlying layer
 * simply ignores these (because it doesn't support nested transactions).
 *
 * The changes that this class makes to the underlying API include the addition of the
 * getNestedTransactionDepth() and isInTransaction() and the fact that beginTransaction()
 * will no longer throw a PDOException (or trigger an error) if a transaction is already
 * in-progress.
 *
 * @author     Cameron Brunner <cameron.brunner@gmail.com>
 * @author     Hans Lellelid <hans@xmpl.org>
 * @author     Christian Abegg <abegg.ch@gmail.com>
 * @since      2006-09-22
 * @package    propel.runtime.connection
 */
class BasePropelPDO extends PDO
{

    /**
     * Attribute to use to set whether to cache prepared statements.
     */
    const PROPEL_ATTR_CACHE_PREPARES = -1;

    /**
     * Attribute to use to set the connection name useful for explains
     */
    const PROPEL_ATTR_CONNECTION_NAME = -2;

    const DEFAULT_SLOW_THRESHOLD = 0.1;
    const DEFAULT_ONLYSLOW_ENABLED = false;

    /**
     * The current transaction depth.
     *
     * @var       integer
     */
    protected $nestedTransactionCount = 0;

    /**
     * Cache of prepared statements (PDOStatement) keyed by md5 of SQL.
     *
     * @var       array  [md5(sql) => PDOStatement]
     */
    protected $preparedStatements = [];

    /**
     * Whether to cache prepared statements.
     *
     * @var       boolean
     */
    protected $cachePreparedStatements = false;

    /**
     * Whether the final commit is possible
     * Is false if a nested transaction is rolled back
     */
    protected $isUncommitable = false;

    /**
     * Count of queries performed.
     *
     * @var       integer
     */
    protected $queryCount = 0;

    /**
     * SQL code of the latest performed query.
     *
     * @var       string
     */
    protected $lastExecutedQuery;

    /**
     * Whether the debug is enabled
     *
     * @var       boolean
     */
    public $useDebug = false;

    /**
     * Configured logger.
     *
     * @var       LoggerInterface|null
     */
    protected $logger = null;

    /**
     * The log level to use for logging.
     *
     * @var       string
     */
    private $logLevel = Propel::LOG_DEBUG;

    /**
     * The runtime configuration
     *
     * @var       PropelConfiguration
     */
    protected $configuration;

    /**
     * The connection name
     *
     * @var string
     */
    protected $connectionName;

    /**
     * The default value for runtime config item "debugpdo.logging.methods".
     *
     * @var string[]
     */
    protected static $defaultLogMethods = [
        'PropelPDO::exec',
        'PropelPDO::query',
        'DebugPDOStatement::execute',
    ];

    /**
     * Creates a PropelPDO instance representing a connection to a database.
     *.
     * If so configured, specifies a custom PDOStatement class and makes an entry
     * to the log with the state of this object just after its initialization.
     * Add PropelPDO::__construct to $defaultLogMethods to see this message
     *
     * @param string  $dsn            Connection DSN.
     * @param string  $username       The username for the DSN string.
     * @param string  $password       The password for the DSN string.
     * @param array   $driver_options A key=>value array of driver-specific connection options.
     *
     * @throws PDOException if there is an error during connection initialization.
     */
    public function __construct($dsn, $username = null, $password = null, $driver_options = [])
    {
        if ($this->useDebug) {
            $debug = $this->getDebugSnapshot();
        }

        parent::__construct($dsn, $username, $password, $driver_options);

        if ($this->useDebug) {
            $this->configureStatementClass(DebugPDOStatement::class, true);
            $this->log('Opening connection', null, __METHOD__, $debug);
        }
    }

    /**
     * Inject the runtime configuration
     *
     * @param PropelConfiguration  $configuration
     */
    public function setConfiguration(PropelConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * Get the runtime configuration
     *
     * @return PropelConfiguration
     */
    public function getConfiguration(): PropelConfiguration
    {
        if (null === $this->configuration) {
            $this->configuration = Propel::getConfiguration(PropelConfiguration::TYPE_OBJECT);
        }

        return $this->configuration;
    }

    /**
     * Gets the current transaction depth.
     *
     * @return integer
     */
    public function getNestedTransactionCount(): int
    {
        return $this->nestedTransactionCount;
    }

    /**
     * Set the current transaction depth.
     *
     * @param int  $v The new depth.
     */
    protected function setNestedTransactionCount(int $v): void
    {
        $this->nestedTransactionCount = $v;
    }

    /**
     * Is this PDO connection currently in-transaction?
     * This is equivalent to asking whether the current nested transaction count is greater than 0.
     *
     * @return boolean
     */
    public function isInTransaction(): bool
    {
        return ($this->getNestedTransactionCount() > 0);
    }

    /**
     * Check whether the connection contains a transaction that can be committed.
     * To be used in an environment where Propelexceptions are caught.
     *
     * @return boolean True if the connection is in a committable transaction
     */
    public function isCommitable(): bool
    {
        return $this->isInTransaction() && !$this->isUncommitable;
    }

    /**
     * Overrides PDO::beginTransaction() to prevent errors due to already-in-progress transaction.
     *
     * @return boolean
     */
    public function beginTransaction(): bool
    {
        $return = true;
        if (!$this->nestedTransactionCount) {
            $return = parent::beginTransaction();
            if ($this->useDebug) {
                $this->log('Begin transaction', null, __METHOD__);
            }
            $this->isUncommitable = false;
        }
        $this->nestedTransactionCount++;

        return $return;
    }

    /**
     * Overrides PDO::commit() to only commit the transaction if we are in the outermost
     * transaction nesting level.
     *
     * @return boolean
     *
     * @throws PropelException
     */
    public function commit(): bool
    {
        $return = true;
        $opcount = $this->nestedTransactionCount;

        if ($opcount > 0) {
            if ($opcount === 1) {
                if ($this->isUncommitable) {
                    throw new PropelException('Cannot commit because a nested transaction was rolled back');
                } else {
                    $return = parent::commit();
                    if ($this->useDebug) {
                        $this->log('Commit transaction', null, __METHOD__);
                    }
                }
            }

            $this->nestedTransactionCount--;
        }

        return $return;
    }

    /**
     * Overrides PDO::rollBack() to only rollback the transaction if we are in the outermost
     * transaction nesting level
     *
     * @return boolean Whether operation was successful.
     */
    public function rollBack(): bool
    {
        $return = true;
        $opcount = $this->nestedTransactionCount;

        if ($opcount > 0) {
            if ($opcount === 1) {
                $return = parent::rollBack();
                if ($this->useDebug) {
                    $this->log('Rollback transaction', null, __METHOD__);
                }
            } else {
                $this->isUncommitable = true;
            }

            $this->nestedTransactionCount--;
        }

        return $return;
    }

    /**
     * Rollback the whole transaction, even if this is a nested rollback
     * and reset the nested transaction count to 0.
     *
     * @return boolean Whether operation was successful.
     */
    public function forceRollBack(): bool
    {
        $return = true;

        if ($this->nestedTransactionCount) {
            // If we're in a transaction, always roll it back
            // regardless of nesting level.
            $return = parent::rollBack();

            // reset nested transaction count to 0 so that we don't
            // try to commit (or rollback) the transaction outside this scope.
            $this->nestedTransactionCount = 0;

            if ($this->useDebug) {
                $this->log('Rollback transaction', null, __METHOD__);
            }
        }

        return $return;
    }

    /**
     * Sets a connection attribute.
     *
     * This is overridden here to provide support for setting Propel-specific attributes too.
     *
     * @param integer  $attribute The attribute to set (e.g. PropelPDO::PROPEL_ATTR_CACHE_PREPARES).
     * @param mixed    $value     The attribute value.
     *
     * @return void
     */
    #[ReturnTypeWillChange]
    public function setAttribute($attribute, $value): void
    {
        switch ($attribute) {
            case self::PROPEL_ATTR_CACHE_PREPARES:
                $this->cachePreparedStatements = $value;
                break;
            case self::PROPEL_ATTR_CONNECTION_NAME:
                $this->connectionName = $value;
                break;
            default:
                parent::setAttribute($attribute, $value);
        }
    }

    /**
     * Gets a connection attribute.
     *
     * This is overridden here to provide support for setting Propel-specific attributes too.
     *
     * @param integer  $attribute The attribute to get (e.g. PropelPDO::PROPEL_ATTR_CACHE_PREPARES).
     *
     * @return mixed
     */
    #[ReturnTypeWillChange]
    public function getAttribute($attribute)
    {
        switch ($attribute) {
            case self::PROPEL_ATTR_CACHE_PREPARES:
                return $this->cachePreparedStatements;
            case self::PROPEL_ATTR_CONNECTION_NAME:
                return $this->connectionName;
            default:
                return parent::getAttribute($attribute);
        }
    }

    /**
     * Prepares a statement for execution and returns a statement object.
     *
     * Overrides PDO::prepare() in order to:
     *  - Add logging and query counting if logging is true.
     *  - Add query caching support if the PropelPDO::PROPEL_ATTR_CACHE_PREPARES was set to true.
     *
     * @param string  $sql     This must be a valid SQL statement for the target database server.
     * @param array   $options One $array or more key => value pairs to set attribute values
     *                         for the PDOStatement object that this method returns.
     *
     * @return PDOStatement|false
     */
    protected function prepareStatement(string $sql, array $options = []): ?PDOStatement
    {
        if ($this->useDebug) {
            $debug = $this->getDebugSnapshot();
        }

        if ($this->cachePreparedStatements) {
            $this->preparedStatements[$sql] = $this->preparedStatements[$sql] ?? parent::prepare($sql, $options) ?: null;
            $return = $this->preparedStatements[$sql];
        } else {
            $return = parent::prepare($sql, $options) ?: null;
        }

        if ($this->useDebug) {
            $this->log($sql, null, __METHOD__, $debug);
        }

        return $return;
    }

    /**
     * Execute an SQL statement and return the number of affected rows.
     * Overrides PDO::exec() to log queries when required
     *
     * @param string  $statement
     *
     * @return int|false
     */
    #[ReturnTypeWillChange]
    public function exec($statement)
    {
        if ($this->useDebug) {
            $debug = $this->getDebugSnapshot();

            $return = parent::exec($statement);

            $this->log($statement, null, __METHOD__, $debug);
            $this->setLastExecutedQuery($statement);
            $this->incrementQueryCount();

            return $return;
        }

        return parent::exec($statement);
    }

    /**
     * Executes an SQL statement, returning a result set as a PDOStatement object.
     * Despite its signature here, this method takes a variety of parameters.
     *
     * Overrides PDO::query() to log queries when required
     *
     * @see http://php.net/manual/en/pdo.query.php for a description of the possible parameters.
     * @see PDO::query()
     *
     * @return PDOStatement|false
     */
    protected function executeQuery(...$args)
    {
        if ($this->useDebug) {
            $debug = $this->getDebugSnapshot();

            $return = parent::query(...$args);

            [$sql] = $args;

            $this->log($sql, null, __METHOD__, $debug);
            $this->setLastExecutedQuery($sql);
            $this->incrementQueryCount();

            return $return;
        }

        return parent::query(...$args);
    }

    /**
     * Clears any stored prepared statements for this connection.
     */
    public function clearStatementCache(): void
    {
        $this->preparedStatements = [];
    }

    /**
     * Configures the PDOStatement class for this connection.
     *
     * @param class-string  $class
     * @param boolean       $suppressError Whether to suppress an exception if the statement class cannot be set.
     *
     * @throws PropelException if the statement class cannot be set (and $suppressError is false).
     */
    protected function configureStatementClass(string $class = PDOStatement::class, bool $suppressError = true): void
    {
        // extending PDOStatement is only supported with non-persistent connections
        if (!$this->getAttribute(PDO::ATTR_PERSISTENT)) {
            $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [$class, [$this]]);
        } elseif (!$suppressError) {
            throw new PropelException('Extending PDOStatement is not supported with persistent connections.');
        }
    }

    /**
     * Returns the number of queries this DebugPDO instance has performed on the database connection.
     *
     * When using DebugPDOStatement as the statement class, any queries by DebugPDOStatement instances
     * are counted as well.
     *
     * @return integer
     * @throws PropelException if persistent connection is used (since unable to override PDOStatement in that case).
     */
    public function getQueryCount(): int
    {
        // extending PDOStatement is not supported with persistent connections
        if ($this->getAttribute(PDO::ATTR_PERSISTENT)) {
            throw new PropelException('Extending PDOStatement is not supported with persistent connections. Count would be inaccurate, because we cannot count the PDOStatment::execute() calls. Either don\'t use persistent connections or don\'t call PropelPDO::getQueryCount()');
        }

        return $this->queryCount;
    }

    /**
     * Increments the number of queries performed by this DebugPDO instance.
     *
     * Returns the original number of queries (ie the value of $this->queryCount before calling this method).
     */
    public function incrementQueryCount(): void
    {
        $this->queryCount++;
    }

    /**
     * Get the SQL code for the latest query executed by Propel
     *
     * @return string Executable SQL code
     */
    public function getLastExecutedQuery()
    {
        return $this->lastExecutedQuery;
    }

    /**
     * Set the SQL code for the latest query executed by Propel
     *
     * @param string  $query Executable SQL code
     */
    public function setLastExecutedQuery(string $query): void
    {
        $this->lastExecutedQuery = $query;
    }

    public function isDebug(): bool
    {
        return $this->useDebug;
    }

    /**
     * Enable or disable the query debug features
     *
     * @param bool  $value True to enable debug (default), false to disable it* @returns bool Previous `useDebug` value.
     */
    public function useDebug(bool $value = true): bool
    {
        if ($value) {
            $this->configureStatementClass(DebugPDOStatement::class, true);
        } else {
            // reset query logging
            $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
            $this->setLastExecutedQuery('');
            $this->queryCount = 0;
        }
        $this->clearStatementCache();

        $prev = $this->useDebug;

        $this->useDebug = $value;

        return $prev;
    }

    /**
     * Sets the logging level to use for logging method calls and SQL statements.
     *
     * @param string  $level Value of one of the `LogLevel` class constants.
     */
    public function setLogLevel(string $level): void
    {
        $this->logLevel = $level;
    }

    /**
     * Sets a logger to use.
     *
     * The logger will be used by this class to log various method calls and their properties.
     *
     * @param LoggerInterface|null  $logger
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Gets the logger in use.
     *
     * @return LoggerInterface|null
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Logs the method call or SQL using the Propel::log() method or a registered logger class.
     *
     * @uses      self::getLogPrefix()
     * @see       self::setLogger()
     *
     * @param string       $msg           Message to log.
     * @param string|null  $level         Log level to use; will use self::setLogLevel() specified level by default.
     * @param string|null  $methodName    Name of the method whose execution is being logged.
     * @param array|null   $debugSnapshot Previous return value from self::getDebugSnapshot().
     */
    public function log(string $msg, ?string $level = null, ?string $methodName = null, ?array $debugSnapshot = null): void
    {
        // If logging has been specifically disabled, this method won't do anything
        if (!$this->isLoggingEnabled()) {
            return;
        }

        // If the method being logged isn't one of the ones to be logged, bail
        if (!$this->isMethodLogged($methodName)) {
            return;
        }

        // If a logging level wasn't provided, use the default one
        if ($level === null) {
            $level = $this->logLevel;
        }

        // Determine if this query is slow enough to warrant logging
        if ($this->getLoggingConfig("onlyslow", self::DEFAULT_ONLYSLOW_ENABLED)) {
            $now = $this->getDebugSnapshot();
            if ($now['microtime'] - $debugSnapshot['microtime'] < $this->getLoggingConfig("details.slow.threshold", self::DEFAULT_SLOW_THRESHOLD)) {
                return;
            }
        }

        // If the necessary additional parameters were given, get the debug log prefix for the log line
        if ($methodName && $debugSnapshot) {
            $msg = $this->getLogPrefix($methodName, $debugSnapshot) . $msg;
        }

        // We won't log empty messages
        if (!$msg) {
            return;
        }

        $logger = $this->logger ?: Propel::logger();
        // Delegate the actual logging forward
        if ($logger) {
            $logger->log($level, $msg);
        }
    }

    /**
     * Returns a snapshot of the current values of some functions useful in debugging.
     *
     * @return array
     *
     * @throws PropelException
     */
    public function getDebugSnapshot(): array
    {
        if (!$this->useDebug) {
            throw new PropelException('Should not get debug snapshot when not debugging');
        }

        return [
            'microtime'             => microtime(true),
            'memory_get_usage'      => memory_get_usage($this->getLoggingConfig('realmemoryusage', false)),
            'memory_get_peak_usage' => memory_get_peak_usage($this->getLoggingConfig('realmemoryusage', false)),
        ];
    }

    /**
     * Returns a named configuration item from the Propel runtime configuration, from under the
     * 'debugpdo.logging' prefix.  If such a configuration setting hasn't been set, the given default
     * value will be returned.
     *
     * @param string  $key          Key for which to return the value.
     * @param mixed   $defaultValue Default value to apply if config item hasn't been set.
     *
     * @return mixed
     */
    protected function getLoggingConfig(string $key, $defaultValue)
    {
        return $this->getConfiguration()->getParameter("debugpdo.logging.$key", $defaultValue);
    }

    /**
     * Returns a prefix that may be prepended to a log line, containing debug information according
     * to the current configuration.
     *
     * Uses a given $debugSnapshot to calculate how much time has passed since the call to self::getDebugSnapshot(),
     * how much the memory consumption by PHP has changed etc.
     *
     * @see self::getDebugSnapshot()
     *
     * @param string  $methodName    Name of the method whose execution is being logged.
     * @param array   $debugSnapshot A previous return value from self::getDebugSnapshot().
     *
     * @return string
     */
    protected function getLogPrefix(string $methodName, array $debugSnapshot): string
    {
        $config = $this->getConfiguration()->getParameters();
        if (!isset($config['debugpdo']['logging']['details'])) {
            return '';
        }
        $prefix = '';
        $logDetails = $config['debugpdo']['logging']['details'];
        $now = $this->getDebugSnapshot();
        $innerGlue = $this->getLoggingConfig('innerglue', ': ');
        $outerGlue = $this->getLoggingConfig('outerglue', ' | ');

        // Iterate through each detail that has been configured to be enabled
        foreach ($logDetails as $detailName => $details) {

            if (!$this->getLoggingConfig("details.$detailName.enabled", false)) {
                continue;
            }

            switch ($detailName) {

                case 'slow';
                    $value = $now['microtime'] - $debugSnapshot['microtime'] >= $this->getLoggingConfig('details.slow.threshold', self::DEFAULT_SLOW_THRESHOLD) ? 'YES' : ' NO';
                    break;

                case 'time':
                    $value = number_format($now['microtime'] - $debugSnapshot['microtime'], $this->getLoggingConfig('details.time.precision', 3)) . ' sec';
                    $value = str_pad($value, $this->getLoggingConfig('details.time.pad', 10), ' ', STR_PAD_LEFT);
                    break;

                case 'mem':
                    $value = self::getReadableBytes($now['memory_get_usage'], $this->getLoggingConfig('details.mem.precision', 1));
                    $value = str_pad($value, $this->getLoggingConfig('details.mem.pad', 9), ' ', STR_PAD_LEFT);
                    break;

                case 'memdelta':
                    $value = $now['memory_get_usage'] - $debugSnapshot['memory_get_usage'];
                    $value = ($value > 0 ? '+' : '') . self::getReadableBytes($value, $this->getLoggingConfig('details.memdelta.precision', 1));
                    $value = str_pad($value, $this->getLoggingConfig('details.memdelta.pad', 10), ' ', STR_PAD_LEFT);
                    break;

                case 'mempeak':
                    $value = self::getReadableBytes($now['memory_get_peak_usage'], $this->getLoggingConfig('details.mempeak.precision', 1));
                    $value = str_pad($value, $this->getLoggingConfig('details.mempeak.pad', 9), ' ', STR_PAD_LEFT);
                    break;

                case 'querycount':
                    $value = str_pad($this->getQueryCount(), $this->getLoggingConfig('details.querycount.pad', 2), ' ', STR_PAD_LEFT);
                    break;

                case 'method':
                    $value = str_pad($methodName, $this->getLoggingConfig('details.method.pad', 28), ' ', STR_PAD_RIGHT);
                    break;

                case 'connection':
                    $value = $this->connectionName;
                    break;

                default:
                    $value = 'n/a';
                    break;
            }

            $prefix .= $detailName . $innerGlue . $value . $outerGlue;
        }

        return $prefix;
    }

    /**
     * Returns a human-readable representation of the given byte count.
     *
     * @param integer  $bytes     Byte count to convert.
     * @param integer  $precision How many decimals to include.
     *
     * @return string
     */
    protected function getReadableBytes(int $bytes, int $precision): string
    {
        $suffix = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $total = count($suffix);

        for ($i = 0; $bytes > 1024 && $i < $total; $i++) {
            $bytes /= 1024;
        }

        return number_format($bytes, $precision) . ' ' . $suffix[$i];
    }

    /**
     * If so configured, makes an entry to the log of the state of this object just prior to its destruction.
     * Add PropelPDO::__destruct to $defaultLogMethods to see this message
     *
     * @see self::log()
     */
    public function __destruct()
    {
        if ($this->useDebug) {
            $this->log('Closing connection', null, __METHOD__, $this->getDebugSnapshot());
        }
    }

    private function isMethodLogged(?string $methodName): bool
    {
        $methods = $this->getLoggingConfig('methods', self::$defaultLogMethods);

        return in_array($methodName, $methods, true)
            || in_array(preg_replace('/^BasePropelPDO::/', 'PropelPDO::', $methodName), $methods, true);
    }

    private function isLoggingEnabled(): bool
    {
        return (bool) $this->getLoggingConfig('enabled', true);
    }
}
