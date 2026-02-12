<?php

namespace Starkerxp\DatabaseChecker\Factory;

use Starkerxp\DatabaseChecker\Exception\JsonInvalidFormatException;
use Starkerxp\DatabaseChecker\Exception\TablenameHasNotDefinedException;
use Starkerxp\DatabaseChecker\LoggerTrait;
use Starkerxp\DatabaseChecker\Structure\MysqlDatabase;
use Starkerxp\DatabaseChecker\Structure\MysqlDatabaseColumn;
use Starkerxp\DatabaseChecker\Structure\MysqlDatabaseTable;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Convert json data to array object.
 *
 * @package Starkerxp\DatabaseChecker\Factory
 */
class JsonDatabaseFactory
{

    use LoggerTrait;
    /**
     * @var string
     */
    private $json;

    /**
     * JsonDatabaseFactory constructor.
     *
     * @param string $json
     */
    public function __construct($json)
    {
        $this->json = $json;
    }

    /**
     * @return MysqlDatabase
     *
     * @throws \RuntimeException
     * @throws TablenameHasNotDefinedException
     */
    public function generate($databaseName): MysqlDatabase
    {
        $tables = [];
        $mysqlDatabase = new MysqlDatabase($databaseName);
        try {
            $dataTables = $this->resolve();
        } catch (\Exception $e) {
            throw new JsonInvalidFormatException('An unexpected error are throw when you check json syntax');
        }
        foreach ($dataTables as $tableName => $dataTable) {
            try {
                $table = new MysqlDatabaseTable($tableName);
            } catch (TablenameHasNotDefinedException $e) {
                throw $e;
            }
            if (isset($dataTable['collate'])) {
                $table->setCollate($dataTable['collate']);
            }
            foreach ((array) $dataTable['columns'] as $columnName => $row) {
                $column = new MysqlDatabaseColumn($columnName, $row['type'], $row['length'], $row['nullable'], $row['defaultValue'], $row['extra']);
                if (isset($row['collate']) || $table->getCollate()) {
                    $column->setCollate($row['collate']);
                }
                $table->addColumn($column);

            }
            foreach ((array) $dataTable['indexes'] as $row) {
                $table->addIndex($row['columns'], $row['name']);
            }
            if (isset($dataTable['primary'])) {
                $table->addPrimary((array) $dataTable['primary']);
            }
            foreach ((array) $dataTable['uniques'] as $row) {
                $table->addUnique($row['columns'], $row['name']);
            }
            $tables[] = $table;
        }

        foreach ($tables as $table) {
            $mysqlDatabase->addTable($table);
        }

        return $mysqlDatabase;
    }

    /**
     * Check syntax of array.
     *
     * @return array
     *
     * @throws \Symfony\Component\OptionsResolver\Exception\UndefinedOptionsException
     * @throws \Symfony\Component\OptionsResolver\Exception\OptionDefinitionException
     * @throws \Symfony\Component\OptionsResolver\Exception\MissingOptionsException
     * @throws \Symfony\Component\OptionsResolver\Exception\InvalidOptionsException
     * @throws \Symfony\Component\OptionsResolver\Exception\AccessException
     * @throws \Symfony\Component\OptionsResolver\Exception\NoSuchOptionException
     */
    protected function resolve(): array
    {
        $data = $this->generateJsonData();

        // On force les valeurs par défaut.
        $resolverTable = new OptionsResolver();
        $resolverTable->setRequired(['columns']);
        $resolverTable->setDefaults(
            [
                'columns' => [],
                'indexes' => [],
                'fulltexts' => [],
                'primary' => null,
                'uniques' => [],
                'collate' => null,
            ]
        );

        $resolverColumns = new OptionsResolver();
        $resolverColumns->setRequired(['type']);
        $resolverColumns->setDefaults(
            [
                'length' => null,
                'nullable' => false,
                'defaultValue' => null,
                'extra' => null,
                'collate' => null,
            ]
        );

        $resolverIndex = new OptionsResolver();
        $resolverIndex->setDefaults(
            [
                'name' => '',
                'columns' => [],
            ]
        );
        $export = [];
        $data = $data['tables'];

        foreach ($data as $nomTable => $table) {
            $dataTable = $resolverTable->resolve($table);
            foreach ((array) $dataTable['columns'] as $columnName => $column) {
                $dataTable['columns'][$columnName] = $resolverColumns->resolve($column);
            }
            foreach (['indexes', 'uniques'] as $indexKey) {
                foreach ((array) $dataTable[$indexKey] as $keyIndex => $index) {
                    $dataTable[$indexKey][$keyIndex] = $resolverIndex->resolve($index);
                }
            }
            $export[$nomTable] = $dataTable;
        }

        return $export;
    }

    private function generateJsonData()
    {
        if (empty($this->json)) {
            return [];
        }
        if (!$data = json_decode($this->json, true)) {
            $data = [];
        }

        return $data;
    }
}
