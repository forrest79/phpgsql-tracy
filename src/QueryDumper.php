<?php declare(strict_types=1);

namespace Forrest79\PhPgSql\Tracy;

abstract class QueryDumper
{

	abstract protected function format(string $sql): string;


	/**
	 * @param array<int, mixed> $parameters
	 */
	public function dump(string $sql, array $parameters = []): string
	{
		$sql = $this->format($sql);

		if ($parameters !== []) {
			$sql = \preg_replace_callback(
				'/\$(\d+)/',
				static function (array $matches) use (&$parameters): string {
					$i = \intval($matches[1]) - 1;

					if (\array_key_exists($i, $parameters)) {
						$value = $parameters[$i];

						\assert(\is_scalar($value) || $value === null || $value instanceof \Stringable);

						unset($parameters[$i]);
						return ($value === null) ? 'NULL' : \sprintf('\'%s\'', \str_replace('\'', '\'\'', (string) $value));
					}

					return $matches[0];
				},
				$sql,
			);

			\assert(\is_string($sql));
		}

		return \trim($sql);
	}

}
