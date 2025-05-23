<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OC\DB;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OC\DB\Exceptions\DbalException;

/**
 * This handles the way we use to write queries, into something that can be
 * handled by the database abstraction layer.
 */
class Adapter {
	/**
	 * @var \OC\DB\Connection $conn
	 */
	protected $conn;

	public function __construct($conn) {
		$this->conn = $conn;
	}

	/**
	 * @param string $table name
	 *
	 * @return int id of last insert statement, 0 in case there was no INSERT before or it failed to get the ID
	 * @throws Exception
	 */
	public function lastInsertId($table, bool $allowRetry = true): int {
		$return = $this->conn->realLastInsertId($table);
		if ($return === 0 && $allowRetry) {
			/**
			 * During a reconnect we are losing the connection and when the
			 * realLastInsertId call is the one triggering the reconnect, it
			 * does not return the ID. But inside the reconnect, we were able
			 * to save the last insert id, so calling it a second time is going
			 * to be successful.
			 * We can not return the result on the initial call, as we are already
			 * way deeper in the stack performing the actual database query on
			 * the doctrine driver.
			 */
			return $this->lastInsertId($table, false);
		}
		return $return;
	}

	/**
	 * @param string $statement that needs to be changed so the db can handle it
	 * @return string changed statement
	 */
	public function fixupStatement($statement) {
		return $statement;
	}

	/**
	 * Create an exclusive read+write lock on a table
	 *
	 * @throws Exception
	 * @since 9.1.0
	 */
	public function lockTable(string $tableName) {
		$this->conn->beginTransaction();
		$this->conn->executeUpdate('LOCK TABLE `' . $tableName . '` IN EXCLUSIVE MODE');
	}

	/**
	 * Release a previous acquired lock again
	 *
	 * @throws Exception
	 * @since 9.1.0
	 */
	public function unlockTable() {
		$this->conn->commit();
	}

	/**
	 * Insert a row if the matching row does not exists. To accomplish proper race condition avoidance
	 * it is needed that there is also a unique constraint on the values. Then this method will
	 * catch the exception and return 0.
	 *
	 * @param string $table The table name (will replace *PREFIX* with the actual prefix)
	 * @param array $input data that should be inserted into the table  (column name => value)
	 * @param array|null $compare List of values that should be checked for "if not exists"
	 *                            If this is null or an empty array, all keys of $input will be compared
	 *                            Please note: text fields (clob) must not be used in the compare array
	 * @return int number of inserted rows
	 * @throws Exception
	 * @deprecated 15.0.0 - use unique index and "try { $db->insert() } catch (UniqueConstraintViolationException $e) {}" instead, because it is more reliable and does not have the risk for deadlocks - see https://github.com/nextcloud/server/pull/12371
	 */
	public function insertIfNotExist($table, $input, ?array $compare = null) {
		$compare = $compare ?: array_keys($input);

		// Prepare column names and generate placeholders
		$columns = '`' . implode('`,`', array_keys($input)) . '`';
		$placeholders = implode(', ', array_fill(0, count($input), '?'));

		$query = 'INSERT INTO `' . $table . '` (' . $columns . ') '
			. 'SELECT ' . $placeholders . ' '
			. 'FROM `' . $table . '` WHERE ';

		$inserts = array_values($input);
		foreach ($compare as $key) {
			$query .= '`' . $key . '`';
			if (is_null($input[$key])) {
				$query .= ' IS NULL AND ';
			} else {
				$inserts[] = $input[$key];
				$query .= ' = ? AND ';
			}
		}
		$query = substr($query, 0, -5);
		$query .= ' HAVING COUNT(*) = 0';

		try {
			return $this->conn->executeUpdate($query, $inserts);
		} catch (UniqueConstraintViolationException $e) {
			// This exception indicates a concurrent insert happened between
			// the insert and the sub-select in the insert, which is safe to ignore.
			// More details: https://github.com/nextcloud/server/pull/12315
			return 0;
		}
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function insertIgnoreConflict(string $table, array $values) : int {
		try {
			$builder = $this->conn->getQueryBuilder();
			$builder->insert($table);
			foreach ($values as $key => $value) {
				$builder->setValue($key, $builder->createNamedParameter($value));
			}
			return $builder->executeStatement();
		} catch (DbalException $e) {
			if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return 0;
			}
			throw $e;
		}
	}
}
