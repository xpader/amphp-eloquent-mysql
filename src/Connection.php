<?php

namespace AmphpEloquentMysql;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Mysql\MysqlTransaction;
use Amp\Sql\Common\ConnectionPool;
use Closure;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Revolt\EventLoop\FiberLocal;

class Connection extends MySqlConnection
{

	protected $connection;

	/**
	 * @var FiberLocal<MysqlTransaction>
	 */
	private FiberLocal $executor;

	public function __construct(array $config)
	{
		$conn = new MysqlConfig(
			$config['host'],
			$config['port'],
			$config['username'],
			$config['password'],
			$config['database']
		);

		if (isset($config['charset'])) {
			$conn = $conn->withCharset($config['charset'], $config['collation']);
		}

		$maxConnection = $config['pool']['max_connections'] ?? ConnectionPool::DEFAULT_MAX_CONNECTIONS;
		$maxIdleTime = $config['pool']['max_idle_time'] ?? ConnectionPool::DEFAULT_IDLE_TIMEOUT;

		$this->connection = new MysqlConnectionPool($conn, $maxConnection, $maxIdleTime);

		parent::__construct(function() {}, $config['database'], $config['prefix'], $config);

		$this->executor ??= new FiberLocal(fn() => $this->connection);
	}

	/**
	 * @return MysqlConnectionPool|MysqlTransaction
	 */
	protected function getConn()
	{
		return $this->executor->get();
	}

	protected function run($query, $bindings, Closure $callback)
	{
		foreach ($this->beforeExecutingCallbacks as $beforeExecutingCallback) {
			$beforeExecutingCallback($query, $bindings, $this);
		}

		$start = microtime(true);

		// Here we will run this query. If an exception occurs we'll determine if it was
		// caused by a connection that has been lost. If that is the cause, we'll try
		// to re-establish connection and re-run the query with a fresh connection.
		try {
			$result = $this->runQueryCallback($query, $bindings, $callback);
		} catch (QueryException $e) {
			$result = $this->handleQueryException(
				$e, $query, $bindings, $callback
			);
		}

		// Once we have run the query we will calculate the time that it took to run and
		// then log the query, bindings, and execution time so we will report them on
		// the event that the developer needs them. We'll log time in milliseconds.
		$this->logQuery(
			$query, $bindings, $this->getElapsedTime($start)
		);

		return $result;
	}

	public function select($query, $bindings = [], $useReadPdo = true)
	{
		//$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		//print_r($backtrace);

		return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
			if ($this->pretending()) {
				return [];
			}

			$statement = $this->getConn()->prepare($query);
			$result = $statement->execute($this->prepareBindings($bindings));

			$rows = [];

			foreach ($result as $row) {
				$rows[] = $row;
			}

			return $rows;
		});
	}

	public function cursor($query, $bindings = [], $useReadPdo = true)
	{
		$statement = $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
			if ($this->pretending()) {
				return [];
			}

			$statement = $this->getConn()->prepare($query);
			$statement->execute($this->prepareBindings($bindings));

			return $statement;
		});


		foreach ($statement as $row) {
			yield $row;
		}
	}

	public function statement($query, $bindings = [])
	{
		return $this->run($query, $bindings, function ($query, $bindings) {
			if ($this->pretending()) {
				return true;
			}

			$statement = $this->getConn()->prepare($query);

			$this->recordsHaveBeenModified();

			$result = $statement->execute($this->prepareBindings($bindings));
			return $result->getRowCount() > 0;
		});
	}

	public function affectingStatement($query, $bindings = [])
	{
		return $this->run($query, $bindings, function ($query, $bindings) {
			if ($this->pretending()) {
				return 0;
			}

			$statement = $this->getConn()->prepare($query);
			$result = $statement->execute($this->prepareBindings($bindings));

			$this->recordsHaveBeenModified(
				($count = $result->getRowCount()) > 0
			);

			return $count;
		});
	}

	public function unprepared($query)
	{
		return $this->run($query, [], function ($query) {
			if ($this->pretending()) {
				return true;
			}

			try {
				$this->getConn()->execute($query);
				$change = true;
			} catch (\Throwable $e) {
				$change = false;
			}

			$this->recordsHaveBeenModified($change);

			return $change;
		});


	}

	public function transaction(Closure $callback, $attempts = 1)
	{
		for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
			$this->beginTransaction();

			// We'll simply execute the given callback within a try / catch block and if we
			// catch any exception we can rollback this transaction so that none of this
			// gets actually persisted to a database or stored in a permanent fashion.
			try {
				$callbackResult = $callback($this);
			}

				// If we catch an exception we'll rollback this transaction and try again if we
				// are not out of attempts. If we are out of attempts we will just throw the
				// exception back out, and let the developer handle an uncaught exception.
			catch (\Throwable $e) {
				$this->handleTransactionException(
					$e, $currentAttempt, $attempts
				);

				continue;
			}

			try {
				if ($this->transactions == 1) {
					$this->fireConnectionEvent('committing');
					$this->getConn()->commit();
				}

				$this->transactions = max(0, $this->transactions - 1);

				if ($this->afterCommitCallbacksShouldBeExecuted()) {
					$this->transactionsManager?->commit($this->getName());
				}
			} catch (\Throwable $e) {
				$this->handleCommitTransactionException(
					$e, $currentAttempt, $attempts
				);

				continue;
			}

			$this->fireConnectionEvent('committed');

			return $callbackResult;
		}
	}


	public function commit()
	{
		if ($this->transactionLevel() == 1) {
			$this->fireConnectionEvent('committing');
			$this->getConn()->commit();
			$this->executor->unset();
		}

		$this->transactions = max(0, $this->transactions - 1);

		if ($this->afterCommitCallbacksShouldBeExecuted()) {
			$this->transactionsManager?->commit($this->getName());
		}

		$this->fireConnectionEvent('committed');
	}

	protected function performRollBack($toLevel)
	{
		if ($toLevel == 0) {
			$conn = $this->getConn();

			if ($conn instanceof MysqlTransaction) {
				$conn->rollBack();
				$this->executor->unset();
			}
		} elseif ($this->queryGrammar->supportsSavepoints()) {
			$this->getConn()->execute(
				$this->queryGrammar->compileSavepointRollBack('trans'.($toLevel + 1))
			);
		}
	}

	/**
	 * Create a transaction within the database.
	 *
	 * @return void
	 *
	 * @throws \Throwable
	 */
	protected function createTransaction()
	{
		if ($this->transactions == 0) {
			$transaction = $this->connection->beginTransaction();
			$this->executor->set($transaction);
		} elseif ($this->transactions >= 1 && $this->queryGrammar->supportsSavepoints()) {
			$this->createSavepoint();
		}
	}

	/**
	 * Create a save point within the database.
	 *
	 * @return void
	 *
	 * @throws \Throwable
	 */
	protected function createSavepoint()
	{
		$this->getConn()->execute(
			$this->queryGrammar->compileSavepoint('trans'.($this->transactions + 1))
		);
	}

}