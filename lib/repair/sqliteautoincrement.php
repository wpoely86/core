<?php
/**
 * Copyright (c) 2015 Vincent Petry <pvince81@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC\Repair;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Schema\ColumnDiff;
use OC\Hooks\BasicEmitter;

/**
 * Fixes Sqlite autoincrement by forcing the SQLite table schemas to be
 * altered in order to retrigger SQL schema generation through OCSqlitePlatform.
 */
class SqliteAutoincrement extends BasicEmitter implements \OC\RepairStep {
	/**
	 * @var \OC\DB\Connection
	 */
	protected $connection;

	/**
	 * @param \OC\DB\Connection $connection
	 */
	public function __construct($connection) {
		$this->connection = $connection;
	}

	public function getName() {
		return 'Repair SQLite autoincrement';
	}

	/**
	 * Fix mime types
	 */
	public function run() {
		if (!$this->connection->getDatabasePlatform() instanceof SqlitePlatform) {
			return;
		}

		$sourceSchema = $this->connection->getSchemaManager()->createSchema();

		$schemaDiff = new SchemaDiff();

		foreach ($sourceSchema->getTables() as $tableSchema) {
			$primaryKey = $tableSchema->getPrimaryKey();
			if (!$primaryKey) {
				continue;
			}

			$columnNames = $primaryKey->getColumns();

			// add a column diff for every primary key column,
			// but do not actually change anything, this will
			// force the generation of SQL statements to alter
			// those tables, which will then trigger the
			// specific SQL code from OCSqlitePlatform
			try {
				$tableDiff = new TableDiff($tableSchema->getName());
				$tableDiff->fromTable = $tableSchema;
				foreach ($columnNames as $columnName) {
					$columnSchema = $tableSchema->getColumn($columnName);
					$columnDiff = new ColumnDiff($columnSchema->getName(), $columnSchema);
					$tableDiff->changedColumns[] = $columnDiff;
					$schemaDiff->changedTables[] = $tableDiff;
				}
			} catch (SchemaException $e) {
				// ignore
			}
		}

		$this->connection->beginTransaction();
		foreach ($schemaDiff->toSql($this->connection->getDatabasePlatform()) as $sql) {
			$this->connection->query($sql);
		}
		$this->connection->commit();
	}
}
