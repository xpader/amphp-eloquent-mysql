<?php

namespace AmphpEloquentMysql;

use Amp\Mysql\MysqlResult;
use Illuminate\Database\Query\Builder;

class Processor extends \Illuminate\Database\Query\Processors\MySqlProcessor
{

	/**
	 * Process an  "insert get ID" query.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  string  $sql
	 * @param  array  $values
	 * @param  string|null  $sequence
	 * @return int
	 */
	public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
	{
		/** @var MysqlResult $result */
		$result = $query->getConnection()->insertStatement($sql, $values);

		$id = $result->getLastInsertId();

		return is_numeric($id) ? (int) $id : $id;
	}

}