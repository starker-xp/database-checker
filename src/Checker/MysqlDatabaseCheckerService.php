<?php

namespace Starkerxp\DatabaseChecker\Checker;

use Starkerxp\DatabaseChecker\Exception\ColumnNotExistException;
use Starkerxp\DatabaseChecker\Exception\IndexNotExistException;
use Starkerxp\DatabaseChecker\Exception\TableHasNotColumnException;
use Starkerxp\DatabaseChecker\Exception\TableNotExistException;
use Starkerxp\DatabaseChecker\LoggerTrait;
use Starkerxp\DatabaseChecker\Structure\DatabaseInterface;
use Starkerxp\DatabaseChecker\Structure\MysqlDatabase;
use Starkerxp\DatabaseChecker\Structure\MysqlDatabaseColumn;
use Starkerxp\DatabaseChecker\Structure\MysqlDatabaseIndex;
use Starkerxp\DatabaseChecker\Structure\MysqlDatabaseTable;

class MysqlDatabaseCheckerService
{
    use LoggerTrait;

    /**
     * @var boolean
     */
    private $checkCollate;

    /**
     * @var boolean
     */
    private $checkEngine;

    /**
     * @var boolean
     */
    private $dropStatement;

    /**
     * @param MysqlDatabase $database
     * @param MysqlDatabase $newDatabase
     * @return array
     * @throws TableHasNotColumnException
     * @throws \Starkerxp\DatabaseChecker\Exception\TablenameHasNotDefinedException
     */
    public function diff(MysqlDatabase $database, MysqlDatabase $newDatabase): array
    {
        $modificationsBetweenTable = [];
        $tables = $database->getTables();
        $newTables = $newDatabase->getTables();

        if ($this->checkCollate && $database->getCollate() != $newDatabase->getCollate()) {
            $database->setCollate($newDatabase->getCollate());
            $modificationsBetweenTable[] = $database->alterStatement();
        }

        // check Table
        foreach ($tables as $table) {
            try {
                $newTable = $this->getTable($table, $newTables);
                $modificationsBetweenTable[] = $this->checkTable($table, $newTable);
            } catch (TableNotExistException $e) {
                //@todo Drop statement.
                //@todo config for generate create or drop.
                $modificationsBetweenTable[] = $this->createStatement($table);
                continue;
            }
        }

        foreach ($newTables as $newTable) {
            try {
                $this->getTable($newTable, $tables);
            } catch (TableNotExistException $e) {
                $modificationsBetweenTable[] = $this->createStatement($newTable);
                continue;
            }
        }

        return $this->formatStatements($modificationsBetweenTable);
    }

    /**
     * @param MysqlDatabaseTable $table
     * @param MysqlDatabaseTable[] $newTables
     *
     * @return mixed
     * @throws TableNotExistException
     */
    private function getTable($table, array $newTables)
    {
        foreach ($newTables as $newTable) {
            if (strtolower($table->getTable()) == strtolower($newTable->getTable())) {
                return $newTable;
            }
        }
        throw new TableNotExistException('');
    }

    /**
     * @param MysqlDatabaseTable $table
     * @param MysqlDatabaseTable $newTable
     *
     * @return array
     *
     * @throws TableHasNotColumnException
     * @throws \Starkerxp\DatabaseChecker\Exception\TablenameHasNotDefinedException
     */
    private function checkTable(MysqlDatabaseTable $table, MysqlDatabaseTable $newTable): array
    {
        $this->prepareTable($table);
        $this->prepareTable($newTable);
        // Aucune différence
        if ($this->tableIsEquals($table, $newTable)) {
            return [];
        }

        $modificationsBetweenTable = $newTable->alterStatement();
        $columns = $table->getColumns();
        $newColumns = $newTable->getColumns();

        foreach ($columns as $column) {
            try {
                $newColumn = $this->getColumn($column, $newColumns);
                $modificationsBetweenTable[$newColumn->getName()] = $this->checkColumn($column, $newColumn);
            } catch (ColumnNotExistException $e) {
                if ($this->dropStatement) {
                    $modificationsBetweenTable[$column->getName()] = $column->deleteStatement();
                    continue;
                }
                $modificationsBetweenTable[$column->getName()] = $this->createStatement($column);
                continue;
            } catch (\Exception $e) {
                continue;
            }
        }
        $columnNeedAlter = array_unique(array_keys(array_filter($modificationsBetweenTable)));
        $indexes = $table->getIndexes();
        $newIndexes = $newTable->getIndexes();

        $modificationsIndexBetweenTable = [];
        $modificationsIndexRemoveBetweenTable = [];
        foreach ($indexes as $index) {
            try {
                $newIndex = $this->getIndex($index, $newIndexes);
                $modificationsIndexBetweenTable[$newIndex->getName()] = $this->checkIndex($index, $newIndex);
            } catch (IndexNotExistException $e) {
                if ($this->dropStatement) {
                    $modificationsIndexRemoveBetweenTable[$index->getName()] = $index->deleteStatement();
                    continue;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        // Generate new Indexes.
        foreach ($newIndexes as $index) {
            try {
                $this->getIndex($index, $indexes);
            } catch (IndexNotExistException $e) {
                $modificationsIndexBetweenTable[$index->getName()] = $index->createStatement();
            } catch (\Exception $e) {
                continue;
            }
        }

        foreach ($newIndexes as $indexName => $index) {
            foreach ($columnNeedAlter as $colonne) {
                if (!in_array($colonne, $index->getColumns(), false)) {
                    continue;
                }
                $modificationsIndexRemoveBetweenTable[] = $index->deleteStatement();
                $modificationsIndexBetweenTable[] = $index->createStatement();
            }
        }

        $modificationsIndexBetweenTable = $this->formatStatements(array_filter($modificationsIndexBetweenTable));
        $modificationsIndexRemoveBetweenTable = $this->formatStatements(array_filter($modificationsIndexRemoveBetweenTable));

        $modificationsBetweenTable = $this->formatStatements($modificationsBetweenTable);

        $result = array_merge($modificationsIndexRemoveBetweenTable, $modificationsBetweenTable, $modificationsIndexBetweenTable);

        return $result;
    }

    private function prepareTable(MysqlDatabaseTable $table): void
    {
        if (!$this->checkCollate) {
            $this->disabledCollate($table);
        }
        if (!$this->checkEngine) {
            $table->setEngine('');
        }
    }

    /**
     * @param MysqlDatabaseTable $table
     * @throws TableHasNotColumnException
     */
    private function disabledCollate(MysqlDatabaseTable $table): void
    {
        $table->setCollate('');
        $columns = $table->getColumns();
        foreach ($columns as $column) {
            $column->setCollate('');
        }
    }

    private function tableIsEquals(MysqlDatabaseTable $table, MysqlDatabaseTable $newTable): bool
    {
        // Table is equals no need more check
        if ($table == $newTable) {
            return true;
        }

        return strtolower(json_encode($table->toArray())) == strtolower(json_encode($newTable->toArray()));
    }

    /**
     * @param MysqlDatabaseColumn $column
     * @param MysqlDatabaseColumn[] $newColumns
     *
     * @return mixed
     *
     * @throws ColumnNotExistException
     */
    private function getColumn(MysqlDatabaseColumn $column, array $newColumns)
    {
        foreach ($newColumns as $newColumn) {
            if (strtolower($column->getName()) == strtolower($newColumn->getName())) {
                return $newColumn;
            }
        }
        throw new ColumnNotExistException('');
    }

    /**
     * @param MysqlDatabaseColumn $column
     * @param MysqlDatabaseColumn $newColumn
     *
     * @return array
     *
     * @throws \Starkerxp\DatabaseChecker\Exception\TablenameHasNotDefinedException
     */
    private function checkColumn(MysqlDatabaseColumn $column, MysqlDatabaseColumn $newColumn): array
    {
        if ($this->columnIsEquals($column, $newColumn)) {
            return [];
        }
        try {
            $statements = $newColumn->alterStatement();
        } catch (\RuntimeException $e) {
            return [];
        }

        return $statements;
    }

    private function columnIsEquals(MysqlDatabaseColumn $column, MysqlDatabaseColumn $newColumn): bool
    {
        // Column is equals no need more check
        if ($column == $newColumn) {
            return true;
        }

        return strtolower(json_encode($column->toArray())) == strtolower(json_encode($newColumn->toArray()));
    }

    private function getIndex(MysqlDatabaseIndex $index, array $newIndexes)
    {
        foreach ($newIndexes as $newIndex) {
            if (strtolower($index->getName()) == strtolower($newIndex->getName())) {
                return $newIndex;
            }
        }
        throw new IndexNotExistException('');
    }

    private function checkIndex(MysqlDatabaseIndex $index, MysqlDatabaseIndex $newIndex): array
    {
        if ($this->indexIsEquals($index, $newIndex)) {
            return [];
        }
        try {
            $statements = $newIndex->alterStatement();
        } catch (\RuntimeException $e) {
            return [];
        }

        return $statements;
    }

    private function indexIsEquals(MysqlDatabaseIndex $index, MysqlDatabaseIndex $newIndex): bool
    {
        if ($index == $newIndex) {
            return true;
        }

        return strtolower(json_encode($index->toArray())) == strtolower(json_encode($newIndex->toArray()));
    }

    /**
     * @param  $databaseInterface
     *
     * @return array
     */
    protected function createStatement(DatabaseInterface $databaseInterface): ?array
    {
        try {
            return $databaseInterface->createStatement();
        } catch (TableHasNotColumnException $e) {
            return [];
        }
    }

    /**
     * @param array $modificationsBetweenTable
     *
     * @return array
     */
    private function formatStatements(array $modificationsBetweenTable): array
    {
        $statements = [];
        foreach ($modificationsBetweenTable as $modifications) {
            foreach ((array) $modifications as $modification) {
                $statements[] = $modification;
            }
        }

        return array_filter(array_unique($statements));
    }

    public function enableCheckCollate(): void
    {
        $this->checkCollate = true;
    }

    public function enableCheckEngine(): void
    {
        $this->checkEngine = true;
    }

    public function enableDropStatement(): void
    {
        $this->dropStatement = true;
    }

}
