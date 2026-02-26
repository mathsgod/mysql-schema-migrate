<?php

namespace MysqlSchemaMigrate;

use PDO;
use PDOException;

class Exporter
{
    private PDO $pdo;

    public function __construct(
        private readonly string $host,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
        private readonly int $port = 3306,
        private readonly string $charset = 'utf8mb4'
    ) {
        $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset={$this->charset}";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
    }

    /**
     * Export the entire database schema
     */
    public function export(): array
    {
        return [
            'tables' => $this->getTables(),
            'views' => $this->getViews(),
            'triggers' => $this->getTriggers(),
            'functions' => $this->getFunctions(),
            'procedures' => $this->getProcedures(),
            'events' => $this->getEvents(),
        ];
    }

    /**
     * Convert schema to JSON string
     */
    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->export(), $flags);
    }

    /**
     * Get all tables with their structure
     */
    private function getTables(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                TABLE_NAME,
                ENGINE,
                TABLE_COLLATION,
                TABLE_COMMENT
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = ?
            AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME
        ");
        $stmt->execute([$this->database]);
        $tables = $stmt->fetchAll();

        $result = [];
        foreach ($tables as $table) {
            $tableName = $table['TABLE_NAME'];
            $collation = $table['TABLE_COLLATION'];
            $charset = $collation ? explode('_', $collation)[0] : null;

            $result[] = [
                'name' => $tableName,
                'columns' => $this->getColumns($tableName),
                'primary_key' => $this->getPrimaryKey($tableName),
                'unique_keys' => $this->getUniqueKeys($tableName),
                'foreign_keys' => $this->getForeignKeys($tableName),
                'indexes' => $this->getIndexes($tableName),
                'engine' => $table['ENGINE'],
                'charset' => $charset,
                'collation' => $collation,
                'comment' => $table['TABLE_COMMENT'] ?: null,
            ];
        }

        return $result;
    }

    /**
     * Get columns for a table
     */
    private function getColumns(string $tableName): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                COLUMN_NAME,
                ORDINAL_POSITION,
                COLUMN_DEFAULT,
                IS_NULLABLE,
                DATA_TYPE,
                CHARACTER_MAXIMUM_LENGTH,
                NUMERIC_PRECISION,
                NUMERIC_SCALE,
                DATETIME_PRECISION,
                CHARACTER_SET_NAME,
                COLLATION_NAME,
                COLUMN_TYPE,
                COLUMN_KEY,
                EXTRA,
                COLUMN_COMMENT
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ");
        $stmt->execute([$this->database, $tableName]);
        $columns = $stmt->fetchAll();

        $result = [];
        foreach ($columns as $column) {
            $columnType = $column['COLUMN_TYPE'];
            $unsigned = str_contains($columnType, 'unsigned');
            
            // Parse length from column type
            $length = null;
            $precision = null;
            $scale = null;
            
            if (preg_match('/\((\d+)(?:,(\d+))?\)/', $columnType, $matches)) {
                if (isset($matches[2])) {
                    $precision = (int) $matches[1];
                    $scale = (int) $matches[2];
                } else {
                    $length = (int) $matches[1];
                }
            }

            // Use NUMERIC_PRECISION/SCALE for numeric types if available
            if ($column['NUMERIC_PRECISION'] !== null) {
                $precision = (int) $column['NUMERIC_PRECISION'];
                $scale = $column['NUMERIC_SCALE'] !== null ? (int) $column['NUMERIC_SCALE'] : null;
            }

            // Use CHARACTER_MAXIMUM_LENGTH for string types
            if ($column['CHARACTER_MAXIMUM_LENGTH'] !== null) {
                $length = (int) $column['CHARACTER_MAXIMUM_LENGTH'];
            }

            $isDefaultExpression = str_contains($column['EXTRA'], 'DEFAULT_GENERATED');

            $columnData = [
                'name' => $column['COLUMN_NAME'],
                'type' => $column['DATA_TYPE'],
                'nullable' => $column['IS_NULLABLE'] === 'YES',
                'default' => $column['COLUMN_DEFAULT'],
                'auto_increment' => str_contains($column['EXTRA'], 'auto_increment'),
            ];

            if ($isDefaultExpression) {
                $columnData['default_expression'] = true;
            }

            if ($unsigned) {
                $columnData['unsigned'] = true;
            }

            if ($length !== null) {
                $columnData['length'] = $length;
            }

            if ($precision !== null) {
                $columnData['precision'] = $precision;
                if ($scale !== null) {
                    $columnData['scale'] = $scale;
                }
            }

            if ($column['CHARACTER_SET_NAME']) {
                $columnData['charset'] = $column['CHARACTER_SET_NAME'];
            }

            if ($column['COLLATION_NAME']) {
                $columnData['collation'] = $column['COLLATION_NAME'];
            }

            if ($column['COLUMN_COMMENT']) {
                $columnData['comment'] = $column['COLUMN_COMMENT'];
            }

            // Parse enum/set values
            if (in_array($column['DATA_TYPE'], ['enum', 'set'])) {
                if (preg_match('/^(?:enum|set)\((.*)\)$/i', $columnType, $matches)) {
                    $values = str_getcsv($matches[1], ',', "'");
                    $columnData['values'] = $values;
                }
            }

            $result[] = $columnData;
        }

        return $result;
    }

    /**
     * Get primary key columns for a table
     */
    private function getPrimaryKey(string $tableName): array
    {
        $stmt = $this->pdo->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME = ?
            AND INDEX_NAME = 'PRIMARY'
            ORDER BY SEQ_IN_INDEX
        ");
        $stmt->execute([$this->database, $tableName]);
        
        return array_column($stmt->fetchAll(), 'COLUMN_NAME');
    }

    /**
     * Get unique keys for a table (excluding primary key)
     */
    private function getUniqueKeys(string $tableName): array
    {
        $stmt = $this->pdo->prepare("
            SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME = ?
            AND NON_UNIQUE = 0
            AND INDEX_NAME != 'PRIMARY'
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        ");
        $stmt->execute([$this->database, $tableName]);
        $rows = $stmt->fetchAll();

        $uniqueKeys = [];
        foreach ($rows as $row) {
            $indexName = $row['INDEX_NAME'];
            if (!isset($uniqueKeys[$indexName])) {
                $uniqueKeys[$indexName] = [
                    'name' => $indexName,
                    'columns' => [],
                ];
            }
            $uniqueKeys[$indexName]['columns'][] = $row['COLUMN_NAME'];
        }

        return array_values($uniqueKeys);
    }

    /**
     * Get foreign keys for a table
     */
    private function getForeignKeys(string $tableName): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                kcu.CONSTRAINT_NAME,
                kcu.COLUMN_NAME,
                kcu.ORDINAL_POSITION,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            WHERE kcu.TABLE_SCHEMA = ?
            AND kcu.TABLE_NAME = ?
            AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
        ");
        $stmt->execute([$this->database, $tableName]);
        $rows = $stmt->fetchAll();

        $foreignKeys = [];
        foreach ($rows as $row) {
            $constraintName = $row['CONSTRAINT_NAME'];
            if (!isset($foreignKeys[$constraintName])) {
                $foreignKeys[$constraintName] = [
                    'name' => $constraintName,
                    'columns' => [],
                    'referenced_table' => $row['REFERENCED_TABLE_NAME'],
                    'referenced_columns' => [],
                    'on_update' => $row['UPDATE_RULE'],
                    'on_delete' => $row['DELETE_RULE'],
                ];
            }
            $foreignKeys[$constraintName]['columns'][] = $row['COLUMN_NAME'];
            $foreignKeys[$constraintName]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
        }

        return array_values($foreignKeys);
    }

    /**
     * Get regular indexes for a table (non-unique, non-primary)
     */
    private function getIndexes(string $tableName): array
    {
        $stmt = $this->pdo->prepare("
            SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, INDEX_TYPE, SUB_PART
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME = ?
            AND NON_UNIQUE = 1
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        ");
        $stmt->execute([$this->database, $tableName]);
        $rows = $stmt->fetchAll();

        $indexes = [];
        foreach ($rows as $row) {
            $indexName = $row['INDEX_NAME'];
            if (!isset($indexes[$indexName])) {
                $indexes[$indexName] = [
                    'name' => $indexName,
                    'columns' => [],
                    'type' => $row['INDEX_TYPE'],
                ];
            }
            
            $columnInfo = $row['COLUMN_NAME'];
            if ($row['SUB_PART']) {
                $columnInfo = [
                    'name' => $row['COLUMN_NAME'],
                    'length' => (int) $row['SUB_PART'],
                ];
            }
            $indexes[$indexName]['columns'][] = $columnInfo;
        }

        return array_values($indexes);
    }

    /**
     * Get all views
     */
    private function getViews(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                TABLE_NAME,
                VIEW_DEFINITION,
                CHECK_OPTION,
                IS_UPDATABLE,
                SECURITY_TYPE
            FROM INFORMATION_SCHEMA.VIEWS
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME
        ");
        $stmt->execute([$this->database]);
        $views = $stmt->fetchAll();

        $result = [];
        foreach ($views as $view) {
            $result[] = [
                'name' => $view['TABLE_NAME'],
                'definition' => $view['VIEW_DEFINITION'],
                'check_option' => $view['CHECK_OPTION'],
                'is_updatable' => $view['IS_UPDATABLE'] === 'YES',
                'security_type' => $view['SECURITY_TYPE'],
            ];
        }

        return $result;
    }

    /**
     * Get all triggers
     */
    private function getTriggers(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                TRIGGER_NAME,
                EVENT_OBJECT_TABLE,
                EVENT_MANIPULATION,
                ACTION_TIMING,
                ACTION_STATEMENT,
                ACTION_ORDER
            FROM INFORMATION_SCHEMA.TRIGGERS
            WHERE TRIGGER_SCHEMA = ?
            ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER
        ");
        $stmt->execute([$this->database]);
        $triggers = $stmt->fetchAll();

        $result = [];
        foreach ($triggers as $trigger) {
            $result[] = [
                'name' => $trigger['TRIGGER_NAME'],
                'table' => $trigger['EVENT_OBJECT_TABLE'],
                'event' => $trigger['EVENT_MANIPULATION'],
                'timing' => $trigger['ACTION_TIMING'],
                'statement' => $trigger['ACTION_STATEMENT'],
                'action_order' => (int) $trigger['ACTION_ORDER'],
            ];
        }

        return $result;
    }

    /**
     * Get all functions
     */
    private function getFunctions(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                ROUTINE_NAME,
                DTD_IDENTIFIER,
                ROUTINE_DEFINITION,
                IS_DETERMINISTIC,
                SQL_DATA_ACCESS,
                SECURITY_TYPE,
                ROUTINE_COMMENT
            FROM INFORMATION_SCHEMA.ROUTINES
            WHERE ROUTINE_SCHEMA = ?
            AND ROUTINE_TYPE = 'FUNCTION'
            ORDER BY ROUTINE_NAME
        ");
        $stmt->execute([$this->database]);
        $functions = $stmt->fetchAll();

        $result = [];
        foreach ($functions as $function) {
            $result[] = [
                'name' => $function['ROUTINE_NAME'],
                'parameters' => $this->getRoutineParameters($function['ROUTINE_NAME'], 'FUNCTION'),
                'returns' => $function['DTD_IDENTIFIER'],
                'deterministic' => $function['IS_DETERMINISTIC'] === 'YES',
                'sql_data_access' => $function['SQL_DATA_ACCESS'],
                'security_type' => $function['SECURITY_TYPE'],
                'body' => $function['ROUTINE_DEFINITION'],
                'comment' => $function['ROUTINE_COMMENT'] ?: null,
            ];
        }

        return $result;
    }

    /**
     * Get all stored procedures
     */
    private function getProcedures(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                ROUTINE_NAME,
                ROUTINE_DEFINITION,
                IS_DETERMINISTIC,
                SQL_DATA_ACCESS,
                SECURITY_TYPE,
                ROUTINE_COMMENT
            FROM INFORMATION_SCHEMA.ROUTINES
            WHERE ROUTINE_SCHEMA = ?
            AND ROUTINE_TYPE = 'PROCEDURE'
            ORDER BY ROUTINE_NAME
        ");
        $stmt->execute([$this->database]);
        $procedures = $stmt->fetchAll();

        $result = [];
        foreach ($procedures as $procedure) {
            $result[] = [
                'name' => $procedure['ROUTINE_NAME'],
                'parameters' => $this->getRoutineParameters($procedure['ROUTINE_NAME'], 'PROCEDURE'),
                'deterministic' => $procedure['IS_DETERMINISTIC'] === 'YES',
                'sql_data_access' => $procedure['SQL_DATA_ACCESS'],
                'security_type' => $procedure['SECURITY_TYPE'],
                'body' => $procedure['ROUTINE_DEFINITION'],
                'comment' => $procedure['ROUTINE_COMMENT'] ?: null,
            ];
        }

        return $result;
    }

    /**
     * Get parameters for a routine (function or procedure)
     */
    private function getRoutineParameters(string $routineName, string $routineType): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                PARAMETER_NAME,
                PARAMETER_MODE,
                DATA_TYPE,
                DTD_IDENTIFIER,
                ORDINAL_POSITION
            FROM INFORMATION_SCHEMA.PARAMETERS
            WHERE SPECIFIC_SCHEMA = ?
            AND SPECIFIC_NAME = ?
            AND ROUTINE_TYPE = ?
            AND PARAMETER_NAME IS NOT NULL
            ORDER BY ORDINAL_POSITION
        ");
        $stmt->execute([$this->database, $routineName, $routineType]);
        $params = $stmt->fetchAll();

        $result = [];
        foreach ($params as $param) {
            $result[] = [
                'name' => $param['PARAMETER_NAME'],
                'mode' => $param['PARAMETER_MODE'],
                'type' => $param['DTD_IDENTIFIER'],
            ];
        }

        return $result;
    }

    /**
     * Get all events
     */
    private function getEvents(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                EVENT_NAME,
                EVENT_DEFINITION,
                EVENT_TYPE,
                EXECUTE_AT,
                INTERVAL_VALUE,
                INTERVAL_FIELD,
                STARTS,
                ENDS,
                STATUS,
                ON_COMPLETION,
                EVENT_COMMENT
            FROM INFORMATION_SCHEMA.EVENTS
            WHERE EVENT_SCHEMA = ?
            ORDER BY EVENT_NAME
        ");
        $stmt->execute([$this->database]);
        $events = $stmt->fetchAll();

        $result = [];
        foreach ($events as $event) {
            $eventData = [
                'name' => $event['EVENT_NAME'],
                'type' => $event['EVENT_TYPE'],
                'status' => $event['STATUS'],
                'on_completion' => $event['ON_COMPLETION'],
                'definition' => $event['EVENT_DEFINITION'],
                'comment' => $event['EVENT_COMMENT'] ?: null,
            ];

            if ($event['EVENT_TYPE'] === 'ONE TIME') {
                $eventData['execute_at'] = $event['EXECUTE_AT'];
            } else {
                $eventData['interval_value'] = $event['INTERVAL_VALUE'] ? (int) $event['INTERVAL_VALUE'] : null;
                $eventData['interval_field'] = $event['INTERVAL_FIELD'];
                $eventData['starts'] = $event['STARTS'];
                $eventData['ends'] = $event['ENDS'];
            }

            $result[] = $eventData;
        }

        return $result;
    }
}
