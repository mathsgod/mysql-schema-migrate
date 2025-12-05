<?php

namespace MysqlSchemaMigrate;

use PDO;
use PDOException;

class Importer
{
    private PDO $pdo;
    private Exporter $exporter;
    private array $sqlStatements = [];

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
        $this->exporter = new Exporter(
            host: $this->host,
            database: $this->database,
            username: $this->username,
            password: $this->password,
            port: $this->port,
            charset: $this->charset
        );
    }

    /**
     * Import schema from JSON file
     */
    public function importFromFile(
        string $filePath,
        bool $dryRun = false,
        bool $allowDrop = false,
        bool $keepDefiner = false,
        array $only = []
    ): array {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $json = file_get_contents($filePath);
        $schema = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException("Invalid JSON: " . json_last_error_msg());
        }

        return $this->import($schema, $dryRun, $allowDrop, $keepDefiner, $only);
    }

    /**
     * Import schema from array
     */
    public function import(
        array $schema,
        bool $dryRun = false,
        bool $allowDrop = false,
        bool $keepDefiner = false,
        array $only = []
    ): array {
        $this->sqlStatements = [];

        // Get current schema for comparison
        $currentSchema = $this->exporter->export();

        // Filter object types if --only specified
        $processTypes = empty($only) 
            ? ['tables', 'views', 'functions', 'procedures', 'triggers', 'events']
            : $only;

        // Phase 1: DROP (reverse order to handle dependencies)
        if ($allowDrop) {
            if (in_array('triggers', $processTypes)) {
                $this->dropTriggers($currentSchema['triggers'] ?? [], $schema['triggers'] ?? []);
            }
            if (in_array('events', $processTypes)) {
                $this->dropEvents($currentSchema['events'] ?? [], $schema['events'] ?? []);
            }
            if (in_array('views', $processTypes)) {
                $this->dropViews($currentSchema['views'] ?? [], $schema['views'] ?? []);
            }
            if (in_array('procedures', $processTypes)) {
                $this->dropProcedures($currentSchema['procedures'] ?? [], $schema['procedures'] ?? []);
            }
            if (in_array('functions', $processTypes)) {
                $this->dropFunctions($currentSchema['functions'] ?? [], $schema['functions'] ?? []);
            }
        }

        // Drop foreign keys before table modifications
        if (in_array('tables', $processTypes)) {
            $this->dropForeignKeysForModification($currentSchema['tables'] ?? [], $schema['tables'] ?? []);
        }

        // Phase 2: CREATE/ALTER (proper order for dependencies)
        if (in_array('tables', $processTypes)) {
            $this->processTables($currentSchema['tables'] ?? [], $schema['tables'] ?? [], $allowDrop);
        }

        // Add foreign keys after all tables exist
        if (in_array('tables', $processTypes)) {
            $this->addForeignKeys($schema['tables'] ?? [], $currentSchema['tables'] ?? []);
        }

        if (in_array('functions', $processTypes)) {
            $this->processFunctions($currentSchema['functions'] ?? [], $schema['functions'] ?? [], $keepDefiner);
        }
        if (in_array('procedures', $processTypes)) {
            $this->processProcedures($currentSchema['procedures'] ?? [], $schema['procedures'] ?? [], $keepDefiner);
        }
        if (in_array('views', $processTypes)) {
            $this->processViews($currentSchema['views'] ?? [], $schema['views'] ?? [], $keepDefiner);
        }
        if (in_array('triggers', $processTypes)) {
            $this->processTriggers($currentSchema['triggers'] ?? [], $schema['triggers'] ?? [], $keepDefiner);
        }
        if (in_array('events', $processTypes)) {
            $this->processEvents($currentSchema['events'] ?? [], $schema['events'] ?? [], $keepDefiner);
        }

        // Execute if not dry run
        if (!$dryRun) {
            $this->executeSql();
        }

        return $this->sqlStatements;
    }

    /**
     * Get SQL statements formatted for output file
     */
    public function formatSqlForFile(): string
    {
        $output = "-- MySQL Schema Import\n";
        $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $output .= "-- Database: {$this->database}\n\n";
        $output .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($this->sqlStatements as $stmt) {
            $output .= "-- {$stmt['comment']}\n";
            if ($stmt['delimiter'] ?? false) {
                $output .= "DELIMITER $$\n";
                $output .= $stmt['sql'] . "$$\n";
                $output .= "DELIMITER ;\n";
            } else {
                $output .= $stmt['sql'] . ";\n";
            }
            $output .= "\n";
        }

        $output .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        return $output;
    }

    /**
     * Execute all collected SQL statements
     */
    private function executeSql(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->sqlStatements as $stmt) {
            try {
                $this->pdo->exec($stmt['sql']);
            } catch (PDOException $e) {
                throw new PDOException(
                    "Error executing SQL [{$stmt['comment']}]: " . $e->getMessage() . "\nSQL: " . $stmt['sql'],
                    (int) $e->getCode(),
                    $e
                );
            }
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Add SQL statement to the queue
     */
    private function addSql(string $sql, string $comment, bool $delimiter = false): void
    {
        $this->sqlStatements[] = [
            'sql' => $sql,
            'comment' => $comment,
            'delimiter' => $delimiter,
        ];
    }

    // ==================== TABLES ====================

    /**
     * Process tables (create new, alter existing)
     */
    private function processTables(array $currentTables, array $targetTables, bool $allowDrop): void
    {
        $currentByName = $this->indexByName($currentTables);
        $targetByName = $this->indexByName($targetTables);

        // Create new tables
        foreach ($targetTables as $table) {
            if (!isset($currentByName[$table['name']])) {
                $this->createTable($table);
            } else {
                $this->alterTable($currentByName[$table['name']], $table, $allowDrop);
            }
        }

        // Drop removed tables
        if ($allowDrop) {
            foreach ($currentTables as $table) {
                if (!isset($targetByName[$table['name']])) {
                    $this->addSql(
                        "DROP TABLE IF EXISTS `{$table['name']}`",
                        "Drop table: {$table['name']}"
                    );
                }
            }
        }
    }

    /**
     * Create a new table
     */
    private function createTable(array $table): void
    {
        $sql = "CREATE TABLE `{$table['name']}` (\n";
        $definitions = [];

        // Columns
        foreach ($table['columns'] as $column) {
            $definitions[] = '  ' . $this->buildColumnDefinition($column);
        }

        // Primary key
        if (!empty($table['primary_key'])) {
            $columns = implode('`, `', $table['primary_key']);
            $definitions[] = "  PRIMARY KEY (`{$columns}`)";
        }

        // Unique keys
        foreach ($table['unique_keys'] ?? [] as $key) {
            $columns = implode('`, `', $key['columns']);
            $definitions[] = "  UNIQUE KEY `{$key['name']}` (`{$columns}`)";
        }

        // Indexes
        foreach ($table['indexes'] ?? [] as $index) {
            $definitions[] = '  ' . $this->buildIndexDefinition($index);
        }

        $sql .= implode(",\n", $definitions);
        $sql .= "\n)";

        // Table options
        if (!empty($table['engine'])) {
            $sql .= " ENGINE={$table['engine']}";
        }
        if (!empty($table['charset'])) {
            $sql .= " DEFAULT CHARSET={$table['charset']}";
        }
        if (!empty($table['collation'])) {
            $sql .= " COLLATE={$table['collation']}";
        }
        if (!empty($table['comment'])) {
            $sql .= " COMMENT=" . $this->pdo->quote($table['comment']);
        }

        $this->addSql($sql, "Create table: {$table['name']}");
    }

    /**
     * Alter existing table
     */
    private function alterTable(array $current, array $target, bool $allowDrop): void
    {
        $alterations = [];
        $tableName = $target['name'];

        // Diff columns
        $columnAlterations = $this->diffColumns($current['columns'], $target['columns'], $allowDrop);
        $alterations = array_merge($alterations, $columnAlterations);

        // Diff indexes
        $indexAlterations = $this->diffIndexes(
            $current['indexes'] ?? [],
            $target['indexes'] ?? [],
            $allowDrop
        );
        $alterations = array_merge($alterations, $indexAlterations);

        // Diff unique keys
        $uniqueKeyAlterations = $this->diffUniqueKeys(
            $current['unique_keys'] ?? [],
            $target['unique_keys'] ?? [],
            $allowDrop
        );
        $alterations = array_merge($alterations, $uniqueKeyAlterations);

        // Diff primary key
        $pkAlteration = $this->diffPrimaryKey(
            $current['primary_key'] ?? [],
            $target['primary_key'] ?? []
        );
        if ($pkAlteration) {
            $alterations[] = $pkAlteration;
        }

        // Table options
        if (($current['engine'] ?? null) !== ($target['engine'] ?? null) && !empty($target['engine'])) {
            $alterations[] = "ENGINE={$target['engine']}";
        }
        if (($current['charset'] ?? null) !== ($target['charset'] ?? null) && !empty($target['charset'])) {
            $alterations[] = "DEFAULT CHARSET={$target['charset']}";
        }
        if (($current['collation'] ?? null) !== ($target['collation'] ?? null) && !empty($target['collation'])) {
            $alterations[] = "COLLATE={$target['collation']}";
        }
        if (($current['comment'] ?? null) !== ($target['comment'] ?? null)) {
            $comment = $target['comment'] ? $this->pdo->quote($target['comment']) : "''";
            $alterations[] = "COMMENT={$comment}";
        }

        if (!empty($alterations)) {
            $sql = "ALTER TABLE `{$tableName}`\n  " . implode(",\n  ", $alterations);
            $this->addSql($sql, "Alter table: {$tableName}");
        }
    }

    /**
     * Diff columns and generate ALTER statements
     */
    private function diffColumns(array $currentColumns, array $targetColumns, bool $allowDrop): array
    {
        $alterations = [];
        $currentByName = $this->indexByName($currentColumns);
        $targetByName = $this->indexByName($targetColumns);

        $prevColumn = null;
        foreach ($targetColumns as $targetCol) {
            $colName = $targetCol['name'];
            
            if (!isset($currentByName[$colName])) {
                // New column
                $def = $this->buildColumnDefinition($targetCol);
                $position = $prevColumn ? "AFTER `{$prevColumn}`" : 'FIRST';
                $alterations[] = "ADD COLUMN {$def} {$position}";
            } else {
                // Check if column needs modification
                $currentCol = $currentByName[$colName];
                if ($this->columnNeedsModification($currentCol, $targetCol)) {
                    $def = $this->buildColumnDefinition($targetCol);
                    $alterations[] = "MODIFY COLUMN {$def}";
                }
            }
            $prevColumn = $colName;
        }

        // Drop removed columns
        if ($allowDrop) {
            foreach ($currentColumns as $currentCol) {
                if (!isset($targetByName[$currentCol['name']])) {
                    $alterations[] = "DROP COLUMN `{$currentCol['name']}`";
                }
            }
        }

        return $alterations;
    }

    /**
     * Check if column definition has changed
     */
    private function columnNeedsModification(array $current, array $target): bool
    {
        $compareFields = [
            'type', 'nullable', 'default', 'auto_increment', 'unsigned',
            'length', 'precision', 'scale', 'charset', 'collation', 'comment', 'values'
        ];

        foreach ($compareFields as $field) {
            $currentVal = $current[$field] ?? null;
            $targetVal = $target[$field] ?? null;

            // Normalize for comparison
            if ($field === 'values' && is_array($currentVal) && is_array($targetVal)) {
                sort($currentVal);
                sort($targetVal);
                if ($currentVal !== $targetVal) {
                    return true;
                }
            } elseif ($currentVal !== $targetVal) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build column definition SQL
     */
    private function buildColumnDefinition(array $column): string
    {
        $sql = "`{$column['name']}` ";

        // Type with length/precision
        $type = strtoupper($column['type']);
        
        // Types that should NOT have any length/precision in definition
        $noLengthTypes = [
            'int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint',
            'float', 'double', 'real',
            'date', 'datetime', 'timestamp', 'time', 'year',
            'text', 'tinytext', 'mediumtext', 'longtext',
            'blob', 'tinyblob', 'mediumblob', 'longblob',
            'json', 'geometry', 'point', 'linestring', 'polygon',
            'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection',
            'boolean', 'bool'
        ];
        
        // Types that use precision and scale (decimal types)
        $decimalTypes = ['decimal', 'numeric', 'dec', 'fixed'];
        
        if (in_array($column['type'], ['enum', 'set']) && !empty($column['values'])) {
            $values = array_map(fn($v) => $this->pdo->quote($v), $column['values']);
            $sql .= strtoupper($column['type']) . '(' . implode(',', $values) . ')';
        } elseif (in_array($column['type'], $decimalTypes) && isset($column['precision'])) {
            // DECIMAL/NUMERIC types use precision and scale
            if (isset($column['scale'])) {
                $sql .= "{$type}({$column['precision']},{$column['scale']})";
            } else {
                $sql .= "{$type}({$column['precision']})";
            }
        } elseif (in_array($column['type'], ['varchar', 'char', 'varbinary', 'binary']) && isset($column['length'])) {
            $sql .= "{$type}({$column['length']})";
        } elseif (in_array(strtolower($column['type']), $noLengthTypes)) {
            // These types should not have length specifier
            $sql .= $type;
        } elseif (isset($column['length'])) {
            // Other types with length
            $sql .= "{$type}({$column['length']})";
        } else {
            $sql .= $type;
        }

        // Unsigned
        if (!empty($column['unsigned'])) {
            $sql .= ' UNSIGNED';
        }

        // Character set and collation
        if (!empty($column['charset'])) {
            $sql .= " CHARACTER SET {$column['charset']}";
        }
        if (!empty($column['collation'])) {
            $sql .= " COLLATE {$column['collation']}";
        }

        // Nullable
        $sql .= $column['nullable'] ? ' NULL' : ' NOT NULL';

        // Default
        if (array_key_exists('default', $column) && $column['default'] !== null) {
            $default = $column['default'];
            // Check for MySQL expressions
            if (in_array(strtoupper($default), ['CURRENT_TIMESTAMP', 'CURRENT_DATE', 'NULL']) 
                || preg_match('/^[A-Z_]+\(/i', $default)) {
                $sql .= " DEFAULT {$default}";
            } else {
                $sql .= " DEFAULT " . $this->pdo->quote($default);
            }
        } elseif ($column['nullable'] && !($column['auto_increment'] ?? false)) {
            $sql .= ' DEFAULT NULL';
        }

        // Auto increment
        if (!empty($column['auto_increment'])) {
            $sql .= ' AUTO_INCREMENT';
        }

        // Comment
        if (!empty($column['comment'])) {
            $sql .= ' COMMENT ' . $this->pdo->quote($column['comment']);
        }

        return $sql;
    }

    /**
     * Diff indexes
     */
    private function diffIndexes(array $currentIndexes, array $targetIndexes, bool $allowDrop): array
    {
        $alterations = [];
        $currentByName = $this->indexByName($currentIndexes);
        $targetByName = $this->indexByName($targetIndexes);

        foreach ($targetIndexes as $index) {
            $indexName = $index['name'];
            if (!isset($currentByName[$indexName])) {
                $alterations[] = 'ADD ' . $this->buildIndexDefinition($index);
            } elseif ($this->indexNeedsModification($currentByName[$indexName], $index)) {
                $alterations[] = "DROP INDEX `{$indexName}`";
                $alterations[] = 'ADD ' . $this->buildIndexDefinition($index);
            }
        }

        if ($allowDrop) {
            foreach ($currentIndexes as $index) {
                if (!isset($targetByName[$index['name']])) {
                    $alterations[] = "DROP INDEX `{$index['name']}`";
                }
            }
        }

        return $alterations;
    }

    /**
     * Check if index needs modification
     */
    private function indexNeedsModification(array $current, array $target): bool
    {
        // Compare columns
        $currentCols = $this->normalizeIndexColumns($current['columns']);
        $targetCols = $this->normalizeIndexColumns($target['columns']);
        
        return $currentCols !== $targetCols || ($current['type'] ?? 'BTREE') !== ($target['type'] ?? 'BTREE');
    }

    /**
     * Normalize index columns for comparison
     */
    private function normalizeIndexColumns(array $columns): array
    {
        return array_map(function ($col) {
            if (is_array($col)) {
                return ['name' => $col['name'], 'length' => $col['length'] ?? null];
            }
            return ['name' => $col, 'length' => null];
        }, $columns);
    }

    /**
     * Build index definition SQL
     */
    private function buildIndexDefinition(array $index): string
    {
        $type = strtoupper($index['type'] ?? 'BTREE');
        $prefix = $type === 'FULLTEXT' ? 'FULLTEXT INDEX' : 'INDEX';
        
        $columns = array_map(function ($col) {
            if (is_array($col)) {
                return $col['length'] ? "`{$col['name']}`({$col['length']})" : "`{$col['name']}`";
            }
            return "`{$col}`";
        }, $index['columns']);

        $sql = "{$prefix} `{$index['name']}` (" . implode(', ', $columns) . ')';
        
        if ($type !== 'FULLTEXT' && $type !== 'BTREE') {
            $sql .= " USING {$type}";
        }

        return $sql;
    }

    /**
     * Diff unique keys
     */
    private function diffUniqueKeys(array $currentKeys, array $targetKeys, bool $allowDrop): array
    {
        $alterations = [];
        $currentByName = $this->indexByName($currentKeys);
        $targetByName = $this->indexByName($targetKeys);

        foreach ($targetKeys as $key) {
            $keyName = $key['name'];
            if (!isset($currentByName[$keyName])) {
                $columns = implode('`, `', $key['columns']);
                $alterations[] = "ADD UNIQUE KEY `{$keyName}` (`{$columns}`)";
            } elseif ($currentByName[$keyName]['columns'] !== $key['columns']) {
                $alterations[] = "DROP INDEX `{$keyName}`";
                $columns = implode('`, `', $key['columns']);
                $alterations[] = "ADD UNIQUE KEY `{$keyName}` (`{$columns}`)";
            }
        }

        if ($allowDrop) {
            foreach ($currentKeys as $key) {
                if (!isset($targetByName[$key['name']])) {
                    $alterations[] = "DROP INDEX `{$key['name']}`";
                }
            }
        }

        return $alterations;
    }

    /**
     * Diff primary key
     */
    private function diffPrimaryKey(array $currentPk, array $targetPk): ?string
    {
        if ($currentPk === $targetPk) {
            return null;
        }

        if (empty($targetPk)) {
            return 'DROP PRIMARY KEY';
        }

        $columns = implode('`, `', $targetPk);
        
        if (empty($currentPk)) {
            return "ADD PRIMARY KEY (`{$columns}`)";
        }

        // Need to drop and recreate
        return "DROP PRIMARY KEY, ADD PRIMARY KEY (`{$columns}`)";
    }

    /**
     * Drop foreign keys that need modification
     */
    private function dropForeignKeysForModification(array $currentTables, array $targetTables): void
    {
        $targetByName = $this->indexByName($targetTables);

        foreach ($currentTables as $table) {
            $tableName = $table['name'];
            $currentFks = $table['foreign_keys'] ?? [];
            $targetFks = $targetByName[$tableName]['foreign_keys'] ?? [];
            $targetFkByName = $this->indexByName($targetFks);

            foreach ($currentFks as $fk) {
                // Drop if not in target or if changed
                if (!isset($targetFkByName[$fk['name']]) || 
                    $this->foreignKeyNeedsModification($fk, $targetFkByName[$fk['name']])) {
                    $this->addSql(
                        "ALTER TABLE `{$tableName}` DROP FOREIGN KEY `{$fk['name']}`",
                        "Drop foreign key: {$tableName}.{$fk['name']}"
                    );
                }
            }
        }
    }

    /**
     * Check if foreign key needs modification
     */
    private function foreignKeyNeedsModification(array $current, array $target): bool
    {
        return $current['columns'] !== $target['columns']
            || $current['referenced_table'] !== $target['referenced_table']
            || $current['referenced_columns'] !== $target['referenced_columns']
            || $current['on_update'] !== $target['on_update']
            || $current['on_delete'] !== $target['on_delete'];
    }

    /**
     * Add foreign keys
     */
    private function addForeignKeys(array $targetTables, array $currentTables): void
    {
        $currentByName = $this->indexByName($currentTables);

        foreach ($targetTables as $table) {
            $tableName = $table['name'];
            $currentFks = $currentByName[$tableName]['foreign_keys'] ?? [];
            $currentFkByName = $this->indexByName($currentFks);

            foreach ($table['foreign_keys'] ?? [] as $fk) {
                // Add if not exists or if it was dropped for modification
                $shouldAdd = !isset($currentFkByName[$fk['name']]) ||
                    $this->foreignKeyNeedsModification($currentFkByName[$fk['name']], $fk);

                if ($shouldAdd) {
                    $columns = implode('`, `', $fk['columns']);
                    $refColumns = implode('`, `', $fk['referenced_columns']);
                    $sql = "ALTER TABLE `{$tableName}` ADD CONSTRAINT `{$fk['name']}` ";
                    $sql .= "FOREIGN KEY (`{$columns}`) ";
                    $sql .= "REFERENCES `{$fk['referenced_table']}` (`{$refColumns}`)";
                    
                    if (!empty($fk['on_delete'])) {
                        $sql .= " ON DELETE {$fk['on_delete']}";
                    }
                    if (!empty($fk['on_update'])) {
                        $sql .= " ON UPDATE {$fk['on_update']}";
                    }

                    $this->addSql($sql, "Add foreign key: {$tableName}.{$fk['name']}");
                }
            }
        }
    }

    // ==================== VIEWS ====================

    /**
     * Drop views not in target
     */
    private function dropViews(array $currentViews, array $targetViews): void
    {
        $targetByName = $this->indexByName($targetViews);

        foreach ($currentViews as $view) {
            if (!isset($targetByName[$view['name']])) {
                $this->addSql(
                    "DROP VIEW IF EXISTS `{$view['name']}`",
                    "Drop view: {$view['name']}"
                );
            }
        }
    }

    /**
     * Process views (create or replace)
     */
    private function processViews(array $currentViews, array $targetViews, bool $keepDefiner): void
    {
        foreach ($targetViews as $view) {
            $securityType = $view['security_type'] ?? 'DEFINER';
            $checkOption = $view['check_option'] ?? 'NONE';

            $sql = "CREATE OR REPLACE ";
            if (!$keepDefiner) {
                $sql .= "DEFINER=CURRENT_USER ";
            }
            $sql .= "SQL SECURITY {$securityType} ";
            $sql .= "VIEW `{$view['name']}` AS {$view['definition']}";

            if ($checkOption !== 'NONE') {
                $sql .= " WITH {$checkOption} CHECK OPTION";
            }

            $this->addSql($sql, "Create/Replace view: {$view['name']}");
        }
    }

    // ==================== FUNCTIONS ====================

    /**
     * Drop functions not in target
     */
    private function dropFunctions(array $currentFunctions, array $targetFunctions): void
    {
        $targetByName = $this->indexByName($targetFunctions);

        foreach ($currentFunctions as $func) {
            if (!isset($targetByName[$func['name']])) {
                $this->addSql(
                    "DROP FUNCTION IF EXISTS `{$func['name']}`",
                    "Drop function: {$func['name']}"
                );
            }
        }
    }

    /**
     * Process functions
     */
    private function processFunctions(array $currentFunctions, array $targetFunctions, bool $keepDefiner): void
    {
        $currentByName = $this->indexByName($currentFunctions);

        foreach ($targetFunctions as $func) {
            // Drop first if exists (for replacement)
            if (isset($currentByName[$func['name']])) {
                $this->addSql(
                    "DROP FUNCTION IF EXISTS `{$func['name']}`",
                    "Drop function for replacement: {$func['name']}"
                );
            }

            $sql = "CREATE ";
            if (!$keepDefiner) {
                $sql .= "DEFINER=CURRENT_USER ";
            }
            $sql .= "FUNCTION `{$func['name']}`(";

            // Parameters
            $params = [];
            foreach ($func['parameters'] ?? [] as $param) {
                $params[] = "`{$param['name']}` {$param['type']}";
            }
            $sql .= implode(', ', $params);
            $sql .= ") RETURNS {$func['returns']}\n";

            // Characteristics
            if ($func['deterministic'] ?? false) {
                $sql .= "DETERMINISTIC\n";
            } else {
                $sql .= "NOT DETERMINISTIC\n";
            }

            if (!empty($func['sql_data_access'])) {
                $sql .= "{$func['sql_data_access']}\n";
            }

            $sql .= "SQL SECURITY " . ($func['security_type'] ?? 'DEFINER') . "\n";

            if (!empty($func['comment'])) {
                $sql .= "COMMENT " . $this->pdo->quote($func['comment']) . "\n";
            }

            $sql .= $func['body'];

            $this->addSql($sql, "Create function: {$func['name']}", true);
        }
    }

    // ==================== PROCEDURES ====================

    /**
     * Drop procedures not in target
     */
    private function dropProcedures(array $currentProcedures, array $targetProcedures): void
    {
        $targetByName = $this->indexByName($targetProcedures);

        foreach ($currentProcedures as $proc) {
            if (!isset($targetByName[$proc['name']])) {
                $this->addSql(
                    "DROP PROCEDURE IF EXISTS `{$proc['name']}`",
                    "Drop procedure: {$proc['name']}"
                );
            }
        }
    }

    /**
     * Process procedures
     */
    private function processProcedures(array $currentProcedures, array $targetProcedures, bool $keepDefiner): void
    {
        $currentByName = $this->indexByName($currentProcedures);

        foreach ($targetProcedures as $proc) {
            // Drop first if exists (for replacement)
            if (isset($currentByName[$proc['name']])) {
                $this->addSql(
                    "DROP PROCEDURE IF EXISTS `{$proc['name']}`",
                    "Drop procedure for replacement: {$proc['name']}"
                );
            }

            $sql = "CREATE ";
            if (!$keepDefiner) {
                $sql .= "DEFINER=CURRENT_USER ";
            }
            $sql .= "PROCEDURE `{$proc['name']}`(";

            // Parameters
            $params = [];
            foreach ($proc['parameters'] ?? [] as $param) {
                $mode = $param['mode'] ?? 'IN';
                $params[] = "{$mode} `{$param['name']}` {$param['type']}";
            }
            $sql .= implode(', ', $params);
            $sql .= ")\n";

            // Characteristics
            if ($proc['deterministic'] ?? false) {
                $sql .= "DETERMINISTIC\n";
            } else {
                $sql .= "NOT DETERMINISTIC\n";
            }

            if (!empty($proc['sql_data_access'])) {
                $sql .= "{$proc['sql_data_access']}\n";
            }

            $sql .= "SQL SECURITY " . ($proc['security_type'] ?? 'DEFINER') . "\n";

            if (!empty($proc['comment'])) {
                $sql .= "COMMENT " . $this->pdo->quote($proc['comment']) . "\n";
            }

            $sql .= $proc['body'];

            $this->addSql($sql, "Create procedure: {$proc['name']}", true);
        }
    }

    // ==================== TRIGGERS ====================

    /**
     * Drop triggers not in target
     */
    private function dropTriggers(array $currentTriggers, array $targetTriggers): void
    {
        $targetByName = $this->indexByName($targetTriggers);

        foreach ($currentTriggers as $trigger) {
            if (!isset($targetByName[$trigger['name']])) {
                $this->addSql(
                    "DROP TRIGGER IF EXISTS `{$trigger['name']}`",
                    "Drop trigger: {$trigger['name']}"
                );
            }
        }
    }

    /**
     * Process triggers
     */
    private function processTriggers(array $currentTriggers, array $targetTriggers, bool $keepDefiner): void
    {
        $currentByName = $this->indexByName($currentTriggers);

        foreach ($targetTriggers as $trigger) {
            // Drop first if exists (for replacement)
            if (isset($currentByName[$trigger['name']])) {
                $this->addSql(
                    "DROP TRIGGER IF EXISTS `{$trigger['name']}`",
                    "Drop trigger for replacement: {$trigger['name']}"
                );
            }

            $sql = "CREATE ";
            if (!$keepDefiner) {
                $sql .= "DEFINER=CURRENT_USER ";
            }
            $sql .= "TRIGGER `{$trigger['name']}` ";
            $sql .= "{$trigger['timing']} {$trigger['event']} ";
            $sql .= "ON `{$trigger['table']}` FOR EACH ROW\n";
            $sql .= $trigger['statement'];

            $this->addSql($sql, "Create trigger: {$trigger['name']}", true);
        }
    }

    // ==================== EVENTS ====================

    /**
     * Drop events not in target
     */
    private function dropEvents(array $currentEvents, array $targetEvents): void
    {
        $targetByName = $this->indexByName($targetEvents);

        foreach ($currentEvents as $event) {
            if (!isset($targetByName[$event['name']])) {
                $this->addSql(
                    "DROP EVENT IF EXISTS `{$event['name']}`",
                    "Drop event: {$event['name']}"
                );
            }
        }
    }

    /**
     * Process events
     */
    private function processEvents(array $currentEvents, array $targetEvents, bool $keepDefiner): void
    {
        $currentByName = $this->indexByName($currentEvents);

        foreach ($targetEvents as $event) {
            // Drop first if exists (for replacement)
            if (isset($currentByName[$event['name']])) {
                $this->addSql(
                    "DROP EVENT IF EXISTS `{$event['name']}`",
                    "Drop event for replacement: {$event['name']}"
                );
            }

            $sql = "CREATE ";
            if (!$keepDefiner) {
                $sql .= "DEFINER=CURRENT_USER ";
            }
            $sql .= "EVENT `{$event['name']}` ";
            $sql .= "ON SCHEDULE ";

            if ($event['type'] === 'ONE TIME') {
                $sql .= "AT '{$event['execute_at']}' ";
            } else {
                $sql .= "EVERY {$event['interval_value']} {$event['interval_field']} ";
                if (!empty($event['starts'])) {
                    $sql .= "STARTS '{$event['starts']}' ";
                }
                if (!empty($event['ends'])) {
                    $sql .= "ENDS '{$event['ends']}' ";
                }
            }

            $onCompletion = $event['on_completion'] ?? 'NOT PRESERVE';
            $sql .= "ON COMPLETION {$onCompletion} ";

            $status = $event['status'] ?? 'ENABLED';
            if ($status === 'DISABLED') {
                $sql .= "DISABLE ";
            } else {
                $sql .= "ENABLE ";
            }

            if (!empty($event['comment'])) {
                $sql .= "COMMENT " . $this->pdo->quote($event['comment']) . " ";
            }

            $sql .= "DO " . $event['definition'];

            $this->addSql($sql, "Create event: {$event['name']}", true);
        }
    }

    // ==================== HELPERS ====================

    /**
     * Index array by name field
     */
    private function indexByName(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $result[$item['name']] = $item;
        }
        return $result;
    }
}
