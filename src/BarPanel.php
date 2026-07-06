<?php declare(strict_types=1);

namespace Forrest79\PhPgSql\Tracy;

use Forrest79\PhPgSql;
use Tracy;

class BarPanel implements Tracy\IBarPanel
{
	private const int DEFAULT_SHOW_MAX_LAST_QUERIES = 1000;

	private PhPgSql\Db\Connection $connection;

	private PhPgSql\Tracy\QueryDumper $queryDumper;

	private string $name;

	private float|null $longQueryTimeMs;

	private bool $detectRepeatingQueries;

	private int $showMaxLastQueries;

	/** @var \Closure(string $class, string $function): bool */
	private \Closure $backtraceContinueIterate;

	private float $totalTimeMs = 0;

	private int $count = 0;

	/** @var list<array{0: PhPgSql\Db\Query|string, 1: float|false|null, 2: array<PhPgSql\Db\Row>|null, 3: array{file: string, line: int|null}|null}> */
	private array $queries = [];

	private int $longQueryCount = 0;

	/** @var array<string, int> */
	private array $queriesCount = [];

	/** @var array<string, int>|null */
	private array|null $repeatingQueries = null;

	/** @var list<PhPgSql\Db\Result> */
	private array $results = [];

	/** @var list<array{0: PhPgSql\Db\Query, 1: list<string>}>|null */
	private array|null $nonParsedColumnsQueries = null;

	private bool $disabled = false;

	private bool $temporaryDisabled = false;


	/**
	 * @param (callable(string $class, string $function): bool)|null $backtraceContinueIterate
	 */
	final public function __construct(
		PhPgSql\Db\Connection $connection,
		PhPgSql\Tracy\QueryDumper $queryDumper,
		string $name,
		bool $explain = false,
		bool $notices = false,
		float|null $longQueryTimeMs = null,
		bool $detectRepeatingQueries = false,
		bool $detectNonParsedColumns = false,
		callable|null $backtraceContinueIterate = null,
		int|null $showMaxLastQueries = null,
	)
	{
		$connection->addOnQuery(function (PhPgSql\Db\Connection $connection, PhPgSql\Db\Query $query, float|null $timeNs = null) use ($explain): void {
			$this->logQuery($query, $timeNs, $explain);
		});

		if ($notices) {
			$connection->addOnQuery(function (PhPgSql\Db\Connection $connection): void {
				$this->logNotices($connection);
			});
			$connection->addOnClose(function (PhPgSql\Db\Connection $connection): void {
				$this->logNotices($connection);
			});
		}

		if ($detectNonParsedColumns) {
			$connection->addOnResult(function (PhPgSql\Db\Connection $connection, PhPgSql\Db\Result $result): void {
				$this->results[] = $result;
			});
		}

		$this->connection = $connection;
		$this->queryDumper = $queryDumper;
		$this->name = $name;
		$this->longQueryTimeMs = $longQueryTimeMs;
		$this->detectRepeatingQueries = $detectRepeatingQueries;
		$this->backtraceContinueIterate = \Closure::fromCallable($backtraceContinueIterate ?? static fn (): bool => false);
		$this->showMaxLastQueries = $showMaxLastQueries ?? self::DEFAULT_SHOW_MAX_LAST_QUERIES;
	}


	private function logQuery(PhPgSql\Db\Query $query, float|null $timeNs, bool $explain): void
	{
		if ($this->disabled || $this->temporaryDisabled) {
			return;
		}

		$this->count++;

		$timeMs = null;
		if ($timeNs !== null) {
			$timeMs = $timeNs / 1000000;
			$this->totalTimeMs += $timeMs;
		}

		if (($this->longQueryTimeMs !== null) && (($timeMs ?? 0) >= $this->longQueryTimeMs)) {
			$this->longQueryCount++;
		}

		if ($this->detectRepeatingQueries && (\preg_match('#^\s*(BEGIN|COMMIT|ROLLBACK|SET)#i', $query->sql) === 0)) {
			$this->queriesCount[$query->sql] = ($this->queriesCount[$query->sql] ?? 0) + 1;
		}

		$source = null;
		$trace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
		foreach ($trace as $row) {
			$class = $row['class'] ?? '';
			$function = $row['function'];
			if (
				($class !== self::class)
				&& !\is_a($class, PhPgSql\Db\Events::class, true)
				&& !(\is_a($class, PhPgSql\Db\Transaction::class, true) && \in_array($function, ['begin', 'commit', 'rollback', 'savepoint', 'releaseSavepoint', 'rollbackToSavepoint'], true))
				&& !(\is_a($class, PhPgSql\Db\Connection::class, true) && \in_array($function, ['query', 'queryArgs', 'execute', 'asyncQuery', 'asyncQueryArgs', 'asyncExecute'], true))
				&& !(\is_a($class, PhPgSql\Fluent\QueryExecute::class, true) && \in_array($function, ['execute', 'fetch', 'fetchAll', 'fetchAssoc', 'fetchPairs', 'fetchSingle', 'fetchIterator'], true))
				&& !\call_user_func($this->backtraceContinueIterate, $class, $function)
			) {
				break;
			}

			if (isset($row['file']) && \is_file($row['file'])) {
				$source = [
					'file' => $row['file'],
					'line' => $row['line'] ?? null,
				];
			}
		}

		$this->queries[] = [$query, $timeMs, $explain ? self::explain($query) : null, $source];
	}


	private function logNotices(PhPgSql\Db\Connection $connection): void
	{
		if ($this->disabled) {
			return;
		}

		$notices = $connection->getNotices();

		if ($notices !== []) {
			$this->queries[] = [
				\sprintf(
					'<pre class="dump"><strong style="color:gray">%s</strong></pre>',
					\implode('<br><br>', \array_map(static function (string $notice): string {
						return '<em>Notice:</em><br>' . \substr($notice, 9);
					}, \array_map('nl2br', $notices))),
				),
				false,
				null,
				null,
			];
		}
	}


	/**
	 * @return list<PhPgSql\Db\Row>|null
	 */
	private function explain(PhPgSql\Db\Query $query): array|null
	{
		$sql = $query->sql;

		if (\preg_match('#\s*\(?\s*SELECT\s#iA', $sql) === 0) {
			return null;
		}

		$explainQuery = new PhPgSql\Db\Sql\Query('EXPLAIN ' . $sql, $query->params);

		$this->temporaryDisabled = true;

		try {
			$explain = $this->connection->query($explainQuery)->fetchAll();
		} catch (PhPgSql\Db\Exceptions\QueryException) {
			$explain = null;
		} finally {
			$this->temporaryDisabled = false;
		}

		return $explain;
	}


	public function getTab(): string
	{
		return Tracy\Helpers::capture(function (): void {
			$name = $this->name;
			$count = $this->count;
			$totalTimeMs = $this->totalTimeMs;

			$hasLongQuery = $this->longQueryCount > 0;
			$hasRepeatingQueries = \count($this->getRepeatingQueries()) > 0;
			$hasNonParsedColumns = \count($this->getNonParsedColumnsQueries()) > 0;

			require __DIR__ . '/templates/BarPanel.tab.phtml';
		});
	}


	public function getPanel(): string
	{
		if ($this->count === 0) {
			return '';
		}

		return Tracy\Helpers::capture(function (): void {
			$name = $this->name;
			$count = $this->count;
			$totalTimeMs = $this->totalTimeMs;
			$queries = \array_slice($this->queries, -1 * $this->showMaxLastQueries);

			$longQueryTimeMs = $this->longQueryTimeMs;

			$longQueryCount = $this->longQueryCount;
			$repeatingQueries = $this->getRepeatingQueries();
			$nonParsedColumnsQueries = $this->getNonParsedColumnsQueries();

			$queryDump = function (string $sql, array $parameters = []): string {
				return \sprintf('<pre class="dump">%s</pre>', $this->queryDumper->dump($sql, $parameters));
			};

			$paramsDump = static function (array $parameters): string {
				return Helper::dumpParameters($parameters);
			};

			require __DIR__ . '/templates/BarPanel.panel.phtml';
		});
	}


	/**
	 * @return array<string, int>
	 */
	private function getRepeatingQueries(): array
	{
		if ($this->repeatingQueries === null) {
			$this->repeatingQueries = \array_filter($this->queriesCount, static function (int $count): bool {
				return $count > 1;
			});
			\arsort($this->repeatingQueries);
		}

		return $this->repeatingQueries;
	}


	/**
	 * @return list<array{0: PhPgSql\Db\Query, list<string>}>
	 */
	private function getNonParsedColumnsQueries(): array
	{
		if ($this->nonParsedColumnsQueries === null) {
			$this->nonParsedColumnsQueries = [];

			foreach ($this->results as $result) {
				$nonParsedColumns = \array_filter($result->getParsedColumns() ?? [], static function (bool $isUsed): bool {
					return !$isUsed;
				});

				if ($nonParsedColumns !== []) {
					$this->nonParsedColumnsQueries[] = [$result->getQuery(), \array_keys($nonParsedColumns)];
				}
			}
		}

		return $this->nonParsedColumnsQueries;
	}


	public function disable(): void
	{
		$this->disabled = false;
	}


	/**
	 * @param (callable(string $class, string $function): bool)|null $backtraceContinueIterate
	 */
	public static function initialize(
		Tracy\Bar $tracyBar,
		PhPgSql\Db\Connection $connection,
		PhPgSql\Tracy\QueryDumper $queryDumper,
		string $name,
		bool $explain,
		bool $notices,
		float|null $longQueryTimeMs = null,
		bool $detectRepeatingQueries = false,
		bool $detectNonParsedColumns = false,
		callable|null $backtraceContinueIterate = null,
		int|null $showMaxLastQueries = null,
	): self
	{
		$panel = new static(
			$connection,
			$queryDumper,
			$name,
			$explain,
			$notices,
			$longQueryTimeMs,
			$detectRepeatingQueries,
			$detectNonParsedColumns,
			$backtraceContinueIterate,
			$showMaxLastQueries,
		);
		$tracyBar->addPanel($panel);
		return $panel;
	}

}
