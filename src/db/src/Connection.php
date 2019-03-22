<?php declare(strict_types=1);


namespace Swoft\Db;


use Swoft\Bean\Exception\PrototypeException;
use Swoft\Connection\Pool\AbstractConnection;
use Swoft\Context\Context;
use Swoft\Db\Concern\Transaction;
use Swoft\Db\Exception\QueryException;
use Swoft\Db\Query\Builder;
use Swoft\Db\Query\Expression;
use Swoft\Db\Query\Grammar\Grammar;
use Swoft\Db\Query\Processor\Processor;

/**
 * Class Connection
 *
 * @since 2.0
 */
class Connection extends AbstractConnection implements ConnectionInterface
{
    use Transaction;

    /**
     * Default fetch mode
     */
    const DEFAULT_FETCH_MODE = \PDO::FETCH_OBJ;

    /**
     * Default type
     */
    const TYPE_DEFAULT = 0;

    /**
     * Use write pdo
     */
    const TYPE_WRITE = 1;

    /**
     * Use read pdo
     */
    const TYPE_READ = 2;

    /**
     * The active PDO connection.
     *
     * @var \PDO
     */
    protected $pdo;

    /**
     * The active PDO connection used for reads.
     *
     * @var \PDO
     */
    protected $readPdo;

    /**
     * @var Database
     */
    protected $database;

    /**
     * The query grammar implementation.
     *
     * @var Grammar
     */
    protected $queryGrammar;

    /**
     * @var Processor
     */
    protected $postProcessor;

    /**
     * Use pdo type
     *
     * @var int
     */
    protected $pdoType = 0;

    /**
     * Replace constructor
     *
     * @param Pool     $pool
     * @param Database $database
     *
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function initialize(Pool $pool, Database $database)
    {
        // Init connection
        $this->initConnection($pool);

        $this->database = $database;

        // We need to initialize a query grammar and the query post processors
        // which are both very important parts of the database abstractions
        // so we initialize these to their default values while starting.
        $this->useDefaultQueryGrammar();

        $this->useDefaultPostProcessor();
    }

    /**
     * Get the query post processor used by the connection.
     *
     * @return Processor
     */
    public function getPostProcessor()
    {
        return $this->postProcessor;
    }

    /**
     * Set the query post processor to the default implementation.
     *
     * @return void
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function useDefaultPostProcessor()
    {
        $this->postProcessor = $this->getDefaultPostProcessor();
    }

    /**
     * Get the default post processor instance.
     *
     * @return Processor
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    protected function getDefaultPostProcessor()
    {
        return \bean(Processor::class);
    }

    /**
     * Set the query post processor used by the connection.
     *
     * @param  Processor $processor
     *
     * @return $this
     */
    public function setPostProcessor(Processor $processor)
    {
        $this->postProcessor = $processor;

        return $this;
    }

    /**
     * Create connection
     *
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function create(): void
    {
        // Create pdo
        $this->createPdo();

        // Create read pdo
        $this->createReadPdo();
    }

    /**
     * Reconnect
     */
    public function reconnect(): bool
    {
        try {
            switch ($this->pdoType) {
                case  self::TYPE_WRITE;
                    $this->createPdo();
                    break;
                case self::TYPE_READ;
                    $this->createReadPdo();
                    break;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    public function getLastTime(): int
    {
        return time();
    }

    /**
     * @return Grammar
     */
    public function getQueryGrammar(): Grammar
    {
        return $this->queryGrammar;
    }

    /**
     * @param Grammar $queryGrammar
     */
    public function setQueryGrammar(Grammar $queryGrammar): void
    {
        $this->queryGrammar = $queryGrammar;
    }

    /**
     * Create pdo
     *
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    private function createPdo()
    {
        $writes = $this->database->getWrites();
        $write  = $writes[0];

        $this->pdo = $this->database->getConnector()->connect($write);
    }

    /**
     * Create read pdo
     *
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    private function createReadPdo()
    {
        $reads = $this->database->getReads();
        if (!empty($reads)) {
            $read          = $reads[0];
            $this->readPdo = $this->database->getConnector()->connect($read);
        }
    }

    /**
     * Set the query grammar to the default implementation.
     *
     * @return void
     */
    public function useDefaultQueryGrammar(): void
    {
        $this->queryGrammar = $this->getDefaultQueryGrammar();
    }

    /**
     * Get the default query grammar instance.
     *
     * @return Grammar
     */
    protected function getDefaultQueryGrammar(): Grammar
    {
        return new Grammar();
    }

    /**
     * Set the table prefix and return the grammar.
     *
     * @param Grammar $grammar
     *
     * @return Grammar
     */
    public function withTablePrefix(Grammar $grammar)
    {
        $grammar->setTablePrefix($this->database->getPrefix());

        return $grammar;
    }

    /**
     * Set the table prefix in use by the connection.
     *
     * @param  string $prefix
     *
     * @return static
     */
    public function setTablePrefix($prefix): self
    {
        $this->getQueryGrammar()->setTablePrefix($prefix);

        return $this;
    }

    /**
     * Get a new query builder instance.
     *
     * @return Builder
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function query()
    {
        return Builder::new($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    /**
     * Begin a fluent query against a database table.
     *
     * @param string $table
     *
     * @return Builder
     *
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function table($table): Builder
    {
        return $this->query()->from($table);
    }

    /**
     * Get a new raw query expression.
     *
     * @param mixed $value
     *
     * @return Expression
     * @throws PrototypeException
     */
    public function raw($value): Expression
    {
        return Expression::new($value);
    }

    /**
     * Run a select statement and return a single result.
     *
     * @param string $query
     * @param array  $bindings
     * @param bool   $useReadPdo
     *
     * @return mixed
     * @throws QueryException
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        $records = $this->select($query, $bindings, $useReadPdo);

        return array_shift($records);
    }

    /**
     * Run a select statement against the database.
     *
     * @param string $query
     * @param array  $bindings
     * @param bool   $useReadPdo
     *
     * @return array
     * @throws QueryException
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function select(string $query, array $bindings = [], bool $useReadPdo = true): array
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            // For select statements, we'll simply execute the query and return an array
            // of the database result set. Each element in the array will be a single
            // row from the database table, and will either be an array or objects.
            $statement = $this->getPdoForSelect($useReadPdo)->prepare($query);
            $statement = $this->prepared($statement);

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $statement->execute();
            return $statement->fetchAll();
        });
    }

    /**
     * Run a select statement against the database and returns a generator.
     *
     * @param string $query
     * @param array  $bindings
     * @param bool   $useReadPdo
     *
     * @return \Generator
     * @throws QueryException
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function cursor(string $query, array $bindings = [], bool $useReadPdo = true): \Generator
    {
        $statement = $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            // First we will create a statement for the query. Then, we will set the fetch
            // mode and prepare the bindings for the query. Once that's done we will be
            // ready to execute the query against the database and return the cursor.
            $statement = $this->getPdoForSelect($useReadPdo)->prepare($query);
            $statement = $this->prepared($statement);

            $this->bindValues(
                $statement, $this->prepareBindings($bindings)
            );

            // Next, we'll execute the query against the database and return the statement
            // so we can return the cursor. The cursor will use a PHP generator to give
            // back one row at a time without using a bunch of memory to render them.
            $statement->execute();

            return $statement;
        });

        while ($record = $statement->fetch()) {
            yield $record;
        }
    }

    /**
     * Run an insert statement against the database.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return bool
     * @throws QueryException
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function insert(string $query, array $bindings = []): bool
    {
        return $this->statement($query, $bindings);
    }

    /**
     * Run an update statement against the database.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return int
     * @throws QueryException
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function update(string $query, array $bindings = []): int
    {
        return $this->affectingStatement($query, $bindings);
    }

    /**
     * Run a delete statement against the database.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return int
     * @throws QueryException
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function delete(string $query, array $bindings = []): int
    {
        return $this->affectingStatement($query, $bindings);
    }

    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return bool
     * @throws QueryException
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function statement(string $query, array $bindings = []): bool
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $statement = $this->getPdo()->prepare($query);

            $this->bindValues($statement, $this->prepareBindings($bindings));

            return $statement->execute();
        });
    }

    /**
     * Run an SQL statement and get the number of rows affected.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return int
     * @throws QueryException
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function affectingStatement(string $query, array $bindings = []): int
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            // For update or delete statements, we want to get the number of rows affected
            // by the statement and return that back to the developer. We'll first need
            // to execute the statement and then we'll use PDO to fetch the affected.
            $statement = $this->getPdo()->prepare($query);

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $statement->execute();
            $count = $statement->rowCount();

            return $count;
        });
    }

    /**
     * @param string $query
     *
     * @return bool
     * @throws QueryException
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function unprepared(string $query): bool
    {
        return $this->run($query, [], function ($query) {

            $change = $this->getPdo()->exec($query);

            return $change;
        });
    }

    /**
     * Prepare the query bindings for execution.
     *
     * @param array $bindings
     *
     * @return array
     */
    public function prepareBindings(array $bindings): array
    {
        $grammar = $this->getQueryGrammar();

        foreach ($bindings as $key => $value) {
            // We need to transform all instances of DateTimeInterface into the actual
            // date string. Each query grammar maintains its own date string format
            // so we'll just ask the grammar for the format to get from the date.
            if ($value instanceof \DateTimeInterface) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            } elseif (is_bool($value)) {
                $bindings[$key] = (int)$value;
            }
        }

        return $bindings;
    }

    /**
     * Run a SQL statement and log its execution context.
     *
     * @param  string   $query
     * @param  array    $bindings
     * @param  \Closure $callback
     *
     * @return mixed
     *
     * @throws QueryException
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    protected function run(string $query, array $bindings, \Closure $callback)
    {
        $this->reconnectIfMissingConnection();

        // Here we will run this query. If an exception occurs we'll determine if it was
        // caused by a connection that has been lost. If that is the cause, we'll try

        // Run callback
        $result = $this->runQueryCallback($query, $bindings, $callback);

        // Once we have run the query we will calculate the time that it took to run and
        // then log the query, bindings, and execution time so we will report them on
        // the event that the developer needs them. We'll log time in milliseconds.

        return $result;
    }

    /**
     * Run a SQL statement.
     *
     * @param  string   $query
     * @param  array    $bindings
     * @param  \Closure $callback
     * @param  bool     $reconnect
     *
     * @return mixed
     *
     * @throws QueryException
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    protected function runQueryCallback(string $query, array $bindings, \Closure $callback, bool $reconnect = false)
    {
        // To execute the statement, we'll simply call the callback, which will actually
        // run the SQL against the PDO connection. Then we can calculate the time it
        // took to execute and log the query SQL, bindings and time in our memory.
        try {
            $result = $callback($query, $bindings);

            // Release connection
            $this->release();
        } catch (\Throwable $e) {
            // If an exception occurs when attempting to run a query, we'll format the error
            // message to include the bindings with SQL, which will make this exception a
            // lot more helpful to the developer instead of just the database's errors.

            if (!$reconnect && $this->isReconnect() && $this->reconnect()) {
                return $this->runQueryCallback($query, $bindings, $callback, true);
            }

            // Reconnect fail to remove exception connection
            if ($this->isReconnect()) {
                $this->pool->remove();
            } else {
                // Other exception to release connection
                $this->release();
            }

            // Throw exception
            throw new QueryException($e->getMessage());
        }

        $this->pdoType = self::TYPE_DEFAULT;
        return $result;
    }

    /**
     * Whether to reconnect
     *
     * @return bool
     */
    protected function isReconnect(): bool
    {
        return false;
    }

    /**
     * Reconnect to the database if a PDO connection is missing.
     *
     * @return void
     */
    protected function reconnectIfMissingConnection()
    {
        if (is_null($this->pdo)) {
            $this->reconnect();
        }
    }

    /**
     * Configure the PDO prepared statement.
     *
     * @param  \PDOStatement $statement
     *
     * @return \PDOStatement
     */
    protected function prepared(\PDOStatement $statement): \PDOStatement
    {
        $config    = $this->database->getConfig();
        $fetchMode = $config['fetchMode'] ?? self::DEFAULT_FETCH_MODE;

        $statement->setFetchMode($fetchMode);

        return $statement;
    }

    /**
     * Get the PDO connection to use for a select query.
     *
     * @param  bool $useReadPdo
     *
     * @return \PDO
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    protected function getPdoForSelect($useReadPdo = true)
    {
        return $useReadPdo ? $this->getReadPdo() : $this->getPdo();
    }

    /**
     * Get the current PDO connection.
     *
     * @return \PDO
     */
    public function getPdo(): \PDO
    {
        $this->pdoType = self::TYPE_WRITE;
        return $this->pdo;
    }

    /**
     * Get the current PDO connection used for reading.
     *
     * @return \PDO
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function getReadPdo(): \PDO
    {
        $cm = $this->getConnectionManager();
        // transaction
        if ($cm->isTransaction()) {
            return $this->getPdo();
        }

        if (empty($this->readPdo)) {
            return $this->getPdo();
        }

        $this->pdoType = self::TYPE_READ;
        return $this->readPdo;
    }

    /**
     * Execute a Closure within a transaction.
     *
     * @param  \Closure $callback
     * @param  int      $attempts
     *
     * @return mixed
     *
     * @throws \Exception|\Throwable
     */
    public function transaction(\Closure $callback, $attempts = 1)
    {
        for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
            $this->beginTransaction();

            // We'll simply execute the given callback within a try / catch block and if we
            // catch any exception we can rollback this transaction so that none of this
            // gets actually persisted to a database or stored in a permanent fashion.
            try {
                $result = $callback($this);
                $this->commit();
                return $result;
            } catch (\Throwable $e) {
                $this->rollBack();

                throw $e;
            }
        }
        return null;
    }

    /**
     * Start a new database transaction.
     *
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function beginTransaction(): void
    {
        $cm = $this->getConnectionManager();

        // Begin transaction
        if (!$cm->isTransaction()) {
            $this->createTransaction($cm);
        }

        // Inc transactions
        $cm->IncTransactions();

        \Swoft::trigger(DbEvent::BEGIN_TRANSACTION);
    }

    /**
     * Commit the active database transaction.
     *
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function commit(): void
    {
        $cm = $this->getConnectionManager();
        $ts = $cm->getTransactions();

        // Not to commit
        if ($ts <= 0) {
            return;
        }

        // Commit 
        if ($ts == 1) {
            $this->getPdo()->commit();

            //Release from transaction manager
            $cm->releaseTransaction($this->id);

            // Release connection
            $this->release(true);
        }

        // Dec transaction
        $cm->DecTransactions();

        \Swoft::trigger(DbEvent::COMMIT_TRANSACTION);
    }

    /**
     * Rollback the active database transaction.
     *
     * @param int|null $toLevel
     *
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function rollBack(int $toLevel = null): void
    {
        $cm = $this->getConnectionManager();
        $ts = $cm->getTransactions();

        // We allow developers to rollback to a certain transaction level. We will verify
        // that this given transaction level is valid before attempting to rollback to
        // that level. If it's not we will just return out and not attempt anything.
        $toLevel = is_null($toLevel) ? $ts - 1 : $toLevel;

        if ($toLevel < 0 || $toLevel >= $ts) {
            return;
        }

        // Next, we will actually perform this rollback within this database and fire the
        // rollback event. We will also set the current transaction level to the given
        // level that was passed into this method so it will be right from here out.
        $this->performRollBack($toLevel);

        $cm->setTransactions($toLevel);
        \Swoft::trigger(DbEvent::ROLLBACK_TRANSACTION);
    }

    /**
     * Get the number of active transactions.
     *
     * @return int
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function transactionLevel(): int
    {
        $tm = \Swoft::getBean(ConnectionManager::class);
        return $tm->getTransactions();
    }

    /**
     * Bind values to their parameters in the given statement.
     *
     * @param  \PDOStatement $statement
     * @param  array         $bindings
     *
     * @return void
     */
    public function bindValues(\PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                is_string($key) ? $key : $key + 1, $value,
                is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR
            );
        }
    }

    /**
     * Rewrite release
     *
     * @param bool $force
     *
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function release(bool $force = false): void
    {
        $cm = $this->getConnectionManager();

        // Not transaction
        if ($force || !$cm->isTransaction()) {
            // Release from connection manager
            $cm->releaseConnection($this->id);

            // Release connection
            parent::release();
        }
    }

    /**
     * Create a transaction within the database.
     *
     * @param ConnectionManager $tm
     */
    protected function createTransaction(ConnectionManager $tm): void
    {
        $ts = $tm->getTransactions();
        if ($ts == 0) {
            $this->getPdo()->beginTransaction();
            $tm->setTransactionConnection($this);
        } elseif ($ts >= 1 && $this->queryGrammar->supportsSavepoints()) {
            $this->createSavepoint($ts);
        }
    }

    /**
     * Perform a rollback within the database.
     *
     * @param  int $toLevel
     *
     * @return void
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    protected function performRollBack(int $toLevel): void
    {
        if ($toLevel == 0) {
            $cm = $this->getConnectionManager();
            $this->getPdo()->rollBack();

            //Release from transaction manager
            $cm->releaseTransaction($this->id);

            // Release connection
            $this->release(true);
        } elseif ($this->queryGrammar->supportsSavepoints()) {
            $this->getPdo()->exec(
                $this->queryGrammar->compileSavepointRollBack('trans' . ($toLevel + 1))
            );
        }
    }

    /**
     * Get transaction manager
     *
     * @return ConnectionManager
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    protected function getConnectionManager(): ConnectionManager
    {
        return Context::getRequestBean(ConnectionManager::class);
    }

    /**
     * Create a save point within the database.
     *
     * @param int $ts
     *
     * @return void
     */
    protected function createSavepoint(int $ts): void
    {
        $this->getPdo()->exec(
            $this->queryGrammar->compileSavepoint('trans' . ($ts + 1))
        );
    }
}