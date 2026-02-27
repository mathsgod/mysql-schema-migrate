<?php

namespace MysqlSchemaMigrate\Tests;

use MysqlSchemaMigrate\Importer;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class ImporterTest extends TestCase
{
    private Importer $importer;
    private PDO $mockPdo;

    protected function setUp(): void
    {
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockPdo->method('quote')->willReturnCallback(
            fn(string $string) => "'" . addslashes($string) . "'"
        );

        $ref = new ReflectionClass(Importer::class);
        $this->importer = $ref->newInstanceWithoutConstructor();

        $pdoProp = $ref->getProperty('pdo');
        $pdoProp->setValue($this->importer, $this->mockPdo);

        $dbProp = $ref->getProperty('database');
        $dbProp->setValue($this->importer, 'test_db');
    }

    private function invoke(string $method, ...$args): mixed
    {
        $ref = new ReflectionMethod(Importer::class, $method);
        return $ref->invoke($this->importer, ...$args);
    }

    private function getProperty(string $name): mixed
    {
        $ref = new ReflectionClass(Importer::class);
        $prop = $ref->getProperty($name);
        return $prop->getValue($this->importer);
    }

    private function setProperty(string $name, mixed $value): void
    {
        $ref = new ReflectionClass(Importer::class);
        $prop = $ref->getProperty($name);
        $prop->setValue($this->importer, $value);
    }

    // ==================== indexByName ====================

    public function testIndexByName(): void
    {
        $items = [
            ['name' => 'foo', 'value' => 1],
            ['name' => 'bar', 'value' => 2],
        ];

        $result = $this->invoke('indexByName', $items);

        $this->assertArrayHasKey('foo', $result);
        $this->assertArrayHasKey('bar', $result);
        $this->assertSame(1, $result['foo']['value']);
        $this->assertSame(2, $result['bar']['value']);
    }

    public function testIndexByNameEmpty(): void
    {
        $result = $this->invoke('indexByName', []);
        $this->assertEmpty($result);
    }

    // ==================== buildColumnDefinition ====================

    public function testBuildColumnDefinitionSimpleInt(): void
    {
        $column = [
            'name' => 'id',
            'type' => 'int',
            'nullable' => false,
            'default' => null,
            'auto_increment' => true,
        ];

        $sql = $this->invoke('buildColumnDefinition', $column);

        $this->assertStringContainsString('`id`', $sql);
        $this->assertStringContainsString('INT', $sql);
        $this->assertStringContainsString('NOT NULL', $sql);
        $this->assertStringContainsString('AUTO_INCREMENT', $sql);
    }

    public function testBuildColumnDefinitionVarchar(): void
    {
        $column = [
            'name' => 'email',
            'type' => 'varchar',
            'nullable' => false,
            'default' => null,
            'auto_increment' => false,
            'length' => 255,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];

        $sql = $this->invoke('buildColumnDefinition', $column);

        $this->assertStringContainsString('`email`', $sql);
        $this->assertStringContainsString('VARCHAR(255)', $sql);
        $this->assertStringContainsString('CHARACTER SET utf8mb4', $sql);
        $this->assertStringContainsString('COLLATE utf8mb4_unicode_ci', $sql);
        $this->assertStringContainsString('NOT NULL', $sql);
    }

    public function testBuildColumnDefinitionNullableWithDefault(): void
    {
        $column = [
            'name' => 'status',
            'type' => 'varchar',
            'nullable' => true,
            'default' => 'active',
            'auto_increment' => false,
            'length' => 50,
        ];

        $sql = $this->invoke('buildColumnDefinition', $column);

        $this->assertStringContainsString('NULL', $sql);
        $this->assertStringContainsString("DEFAULT 'active'", $sql);
    }

    public function testBuildColumnDefinitionNullableNoDefault(): void
    {
        $column = [
            'name' => 'deleted_at',
            'type' => 'datetime',
            'nullable' => true,
            'default' => null,
            'auto_increment' => false,
        ];

        $sql = $this->invoke('buildColumnDefinition', $column);

        $this->assertStringContainsString('NULL', $sql);
        $this->assertStringContainsString('DEFAULT NULL', $sql);
    }

    public function testBuildColumnDefinitionDecimal(): void
    {
        $column = [
            'name' => 'price',
            'type' => 'decimal',
            'nullable' => false,
            'default' => null,
            'auto_increment' => false,
            'precision' => 10,
            'scale' => 2,
        ];

        $sql = $this->invoke('buildColumnDefinition', $column);

        $this->assertStringContainsString('DECIMAL(10,2)', $sql);
        $this->assertStringContainsString('NOT NULL', $sql);
    }

    public function testBuildColumnDefinitionUnsigned(): void
    {
        $column = [
            'name' => 'age',
            'type' => 'int',
            'nullable' => false,
            'default' => null,
            'auto_increment' => false,
            'unsigned' => true,
        ];

        $sql = $this->invoke('buildColumnDefinition', $column);

        $this->assertStringContainsString('UNSIGNED', $sql);
    }

    public function testBuildColumnDefinitionEnum(): void
    {
        $column = [
            'name' => 'role',
            'type' => 'enum',
            'nullable' => false,
            'default' => 'user',
            'auto_increment' => false,
            'values' => ['admin', 'user', 'guest'],
        ];

        $sql = $this->invoke('buildColumnDefinition', $column);

        $this->assertStringContainsString("ENUM('admin','user','guest')", $sql);
        $this->assertStringContainsString("DEFAULT 'user'", $sql);
    }

    public function testBuildColumnDefinitionWithComment(): void
    {
        $column = [
            'name' => 'note',
            'type' => 'text',
            'nullable' => true,
            'default' => null,
            'auto_increment' => false,
            'comment' => 'User note',
        ];

        $sql = $this->invoke('buildColumnDefinition', $column);

        $this->assertStringContainsString("COMMENT 'User note'", $sql);
    }

    public function testBuildColumnDefinitionDefaultExpression(): void
    {
        $column = [
            'name' => 'uuid',
            'type' => 'varchar',
            'nullable' => false,
            'default' => 'uuid()',
            'default_expression' => true,
            'auto_increment' => false,
            'length' => 36,
        ];

        $sql = $this->invoke('buildColumnDefinition', $column);

        $this->assertStringContainsString('DEFAULT (uuid())', $sql);
    }

    public function testBuildColumnDefinitionCurrentTimestamp(): void
    {
        $column = [
            'name' => 'created_at',
            'type' => 'timestamp',
            'nullable' => false,
            'default' => 'CURRENT_TIMESTAMP',
            'auto_increment' => false,
        ];

        $sql = $this->invoke('buildColumnDefinition', $column);

        $this->assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $sql);
        // Should NOT be quoted
        $this->assertStringNotContainsString("DEFAULT 'CURRENT_TIMESTAMP'", $sql);
    }

    // ==================== columnNeedsModification ====================

    public function testColumnNeedsModificationNoChange(): void
    {
        $current = [
            'name' => 'id',
            'type' => 'int',
            'nullable' => false,
            'default' => null,
            'auto_increment' => true,
        ];

        $result = $this->invoke('columnNeedsModification', $current, $current);
        $this->assertFalse($result);
    }

    public function testColumnNeedsModificationTypeChanged(): void
    {
        $current = ['name' => 'col', 'type' => 'int', 'nullable' => false, 'default' => null, 'auto_increment' => false];
        $target = ['name' => 'col', 'type' => 'bigint', 'nullable' => false, 'default' => null, 'auto_increment' => false];

        $result = $this->invoke('columnNeedsModification', $current, $target);
        $this->assertTrue($result);
    }

    public function testColumnNeedsModificationNullableChanged(): void
    {
        $current = ['name' => 'col', 'type' => 'int', 'nullable' => false, 'default' => null, 'auto_increment' => false];
        $target = ['name' => 'col', 'type' => 'int', 'nullable' => true, 'default' => null, 'auto_increment' => false];

        $result = $this->invoke('columnNeedsModification', $current, $target);
        $this->assertTrue($result);
    }

    public function testColumnNeedsModificationDefaultChanged(): void
    {
        $current = ['name' => 'col', 'type' => 'varchar', 'nullable' => false, 'default' => 'old', 'auto_increment' => false];
        $target = ['name' => 'col', 'type' => 'varchar', 'nullable' => false, 'default' => 'new', 'auto_increment' => false];

        $result = $this->invoke('columnNeedsModification', $current, $target);
        $this->assertTrue($result);
    }

    public function testColumnNeedsModificationLengthChanged(): void
    {
        $current = ['name' => 'col', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'length' => 100];
        $target = ['name' => 'col', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'length' => 255];

        $result = $this->invoke('columnNeedsModification', $current, $target);
        $this->assertTrue($result);
    }

    public function testColumnNeedsModificationEnumValuesChanged(): void
    {
        $current = ['name' => 'col', 'type' => 'enum', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'values' => ['a', 'b']];
        $target = ['name' => 'col', 'type' => 'enum', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'values' => ['a', 'b', 'c']];

        $result = $this->invoke('columnNeedsModification', $current, $target);
        $this->assertTrue($result);
    }

    public function testColumnNeedsModificationEnumValuesSameUnordered(): void
    {
        $current = ['name' => 'col', 'type' => 'enum', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'values' => ['b', 'a']];
        $target = ['name' => 'col', 'type' => 'enum', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'values' => ['a', 'b']];

        // Should be false since values are sorted before comparison
        $result = $this->invoke('columnNeedsModification', $current, $target);
        $this->assertFalse($result);
    }

    // ==================== diffColumns ====================

    public function testDiffColumnsNewColumn(): void
    {
        $current = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'auto_increment' => true],
        ];
        $target = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'auto_increment' => true],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'length' => 100],
        ];

        $result = $this->invoke('diffColumns', $current, $target, false);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('ADD COLUMN', $result[0]);
        $this->assertStringContainsString('`name`', $result[0]);
        $this->assertStringContainsString('AFTER `id`', $result[0]);
    }

    public function testDiffColumnsNewColumnFirst(): void
    {
        $current = [
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'length' => 100],
        ];
        $target = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'auto_increment' => true],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'length' => 100],
        ];

        $result = $this->invoke('diffColumns', $current, $target, false);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('ADD COLUMN', $result[0]);
        $this->assertStringContainsString('FIRST', $result[0]);
    }

    public function testDiffColumnsModifyColumn(): void
    {
        $current = [
            ['name' => 'col', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'length' => 100],
        ];
        $target = [
            ['name' => 'col', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'length' => 255],
        ];

        $result = $this->invoke('diffColumns', $current, $target, false);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('MODIFY COLUMN', $result[0]);
    }

    public function testDiffColumnsDropColumn(): void
    {
        $current = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'auto_increment' => true],
            ['name' => 'old_col', 'type' => 'varchar', 'nullable' => true, 'default' => null, 'auto_increment' => false, 'length' => 50],
        ];
        $target = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'auto_increment' => true],
        ];

        // allowDrop = false: no drop
        $resultNoDrop = $this->invoke('diffColumns', $current, $target, false);
        $this->assertEmpty($resultNoDrop);

        // allowDrop = true: drop
        $resultDrop = $this->invoke('diffColumns', $current, $target, true);
        $this->assertCount(1, $resultDrop);
        $this->assertStringContainsString('DROP COLUMN `old_col`', $resultDrop[0]);
    }

    public function testDiffColumnsNoChanges(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'auto_increment' => true],
        ];

        $result = $this->invoke('diffColumns', $columns, $columns, false);
        $this->assertEmpty($result);
    }

    // ==================== diffPrimaryKey ====================

    public function testDiffPrimaryKeyNoChange(): void
    {
        $result = $this->invoke('diffPrimaryKey', ['id'], ['id']);
        $this->assertNull($result);
    }

    public function testDiffPrimaryKeyAddNew(): void
    {
        $result = $this->invoke('diffPrimaryKey', [], ['id']);
        $this->assertStringContainsString('ADD PRIMARY KEY (`id`)', $result);
    }

    public function testDiffPrimaryKeyDrop(): void
    {
        $result = $this->invoke('diffPrimaryKey', ['id'], []);
        $this->assertSame('DROP PRIMARY KEY', $result);
    }

    public function testDiffPrimaryKeyChange(): void
    {
        $result = $this->invoke('diffPrimaryKey', ['id'], ['id', 'name']);
        $this->assertStringContainsString('DROP PRIMARY KEY', $result);
        $this->assertStringContainsString('ADD PRIMARY KEY (`id`, `name`)', $result);
    }

    // ==================== buildIndexDefinition ====================

    public function testBuildIndexDefinitionSimple(): void
    {
        $index = [
            'name' => 'idx_name',
            'columns' => ['name'],
            'type' => 'BTREE',
        ];

        $sql = $this->invoke('buildIndexDefinition', $index);

        $this->assertStringContainsString('INDEX `idx_name`', $sql);
        $this->assertStringContainsString('(`name`)', $sql);
    }

    public function testBuildIndexDefinitionMultiColumn(): void
    {
        $index = [
            'name' => 'idx_name_email',
            'columns' => ['name', 'email'],
            'type' => 'BTREE',
        ];

        $sql = $this->invoke('buildIndexDefinition', $index);

        $this->assertStringContainsString('(`name`, `email`)', $sql);
    }

    public function testBuildIndexDefinitionWithSubPart(): void
    {
        $index = [
            'name' => 'idx_content',
            'columns' => [
                ['name' => 'content', 'length' => 100],
            ],
            'type' => 'BTREE',
        ];

        $sql = $this->invoke('buildIndexDefinition', $index);

        $this->assertStringContainsString('`content`(100)', $sql);
    }

    public function testBuildIndexDefinitionFulltext(): void
    {
        $index = [
            'name' => 'ft_content',
            'columns' => ['content'],
            'type' => 'FULLTEXT',
        ];

        $sql = $this->invoke('buildIndexDefinition', $index);

        $this->assertStringContainsString('FULLTEXT INDEX', $sql);
    }

    // ==================== diffIndexes ====================

    public function testDiffIndexesAddNew(): void
    {
        $current = [];
        $target = [
            ['name' => 'idx_name', 'columns' => ['name'], 'type' => 'BTREE'],
        ];

        $result = $this->invoke('diffIndexes', $current, $target, false);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('ADD', $result[0]);
        $this->assertStringContainsString('idx_name', $result[0]);
    }

    public function testDiffIndexesNoChange(): void
    {
        $indexes = [
            ['name' => 'idx_name', 'columns' => ['name'], 'type' => 'BTREE'],
        ];

        $result = $this->invoke('diffIndexes', $indexes, $indexes, false);
        $this->assertEmpty($result);
    }

    public function testDiffIndexesModify(): void
    {
        $current = [
            ['name' => 'idx_name', 'columns' => ['name'], 'type' => 'BTREE'],
        ];
        $target = [
            ['name' => 'idx_name', 'columns' => ['name', 'email'], 'type' => 'BTREE'],
        ];

        $result = $this->invoke('diffIndexes', $current, $target, false);

        $this->assertCount(2, $result);
        $this->assertStringContainsString('DROP INDEX `idx_name`', $result[0]);
        $this->assertStringContainsString('ADD', $result[1]);
    }

    public function testDiffIndexesDrop(): void
    {
        $current = [
            ['name' => 'idx_old', 'columns' => ['old_col'], 'type' => 'BTREE'],
        ];
        $target = [];

        // allowDrop = false
        $this->assertEmpty($this->invoke('diffIndexes', $current, $target, false));

        // allowDrop = true
        $result = $this->invoke('diffIndexes', $current, $target, true);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('DROP INDEX `idx_old`', $result[0]);
    }

    // ==================== diffUniqueKeys ====================

    public function testDiffUniqueKeysAddNew(): void
    {
        $current = [];
        $target = [
            ['name' => 'uk_email', 'columns' => ['email']],
        ];

        $result = $this->invoke('diffUniqueKeys', $current, $target, false);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('ADD UNIQUE KEY `uk_email` (`email`)', $result[0]);
    }

    public function testDiffUniqueKeysModify(): void
    {
        $current = [
            ['name' => 'uk_email', 'columns' => ['email']],
        ];
        $target = [
            ['name' => 'uk_email', 'columns' => ['email', 'domain']],
        ];

        $result = $this->invoke('diffUniqueKeys', $current, $target, false);

        $this->assertCount(2, $result);
        $this->assertStringContainsString('DROP INDEX `uk_email`', $result[0]);
        $this->assertStringContainsString('ADD UNIQUE KEY `uk_email` (`email`, `domain`)', $result[1]);
    }

    public function testDiffUniqueKeysDrop(): void
    {
        $current = [
            ['name' => 'uk_old', 'columns' => ['old']],
        ];
        $target = [];

        $this->assertEmpty($this->invoke('diffUniqueKeys', $current, $target, false));

        $result = $this->invoke('diffUniqueKeys', $current, $target, true);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('DROP INDEX `uk_old`', $result[0]);
    }

    // ==================== foreignKeyNeedsModification ====================

    public function testForeignKeyNeedsModificationNoChange(): void
    {
        $fk = [
            'name' => 'fk_user',
            'columns' => ['user_id'],
            'referenced_table' => 'users',
            'referenced_columns' => ['id'],
            'on_update' => 'NO ACTION',
            'on_delete' => 'CASCADE',
        ];

        $result = $this->invoke('foreignKeyNeedsModification', $fk, $fk);
        $this->assertFalse($result);
    }

    public function testForeignKeyNeedsModificationColumnChanged(): void
    {
        $current = [
            'columns' => ['user_id'],
            'referenced_table' => 'users',
            'referenced_columns' => ['id'],
            'on_update' => 'NO ACTION',
            'on_delete' => 'CASCADE',
        ];
        $target = array_merge($current, ['columns' => ['author_id']]);

        $result = $this->invoke('foreignKeyNeedsModification', $current, $target);
        $this->assertTrue($result);
    }

    public function testForeignKeyNeedsModificationOnDeleteChanged(): void
    {
        $current = [
            'columns' => ['user_id'],
            'referenced_table' => 'users',
            'referenced_columns' => ['id'],
            'on_update' => 'NO ACTION',
            'on_delete' => 'CASCADE',
        ];
        $target = array_merge($current, ['on_delete' => 'SET NULL']);

        $result = $this->invoke('foreignKeyNeedsModification', $current, $target);
        $this->assertTrue($result);
    }

    // ==================== normalizeIndexColumns ====================

    public function testNormalizeIndexColumnsStrings(): void
    {
        $result = $this->invoke('normalizeIndexColumns', ['col1', 'col2']);

        $this->assertSame([
            ['name' => 'col1', 'length' => null],
            ['name' => 'col2', 'length' => null],
        ], $result);
    }

    public function testNormalizeIndexColumnsArrays(): void
    {
        $result = $this->invoke('normalizeIndexColumns', [
            ['name' => 'col1', 'length' => 100],
            ['name' => 'col2'],
        ]);

        $this->assertSame([
            ['name' => 'col1', 'length' => 100],
            ['name' => 'col2', 'length' => null],
        ], $result);
    }

    // ==================== indexNeedsModification ====================

    public function testIndexNeedsModificationNoChange(): void
    {
        $index = ['name' => 'idx', 'columns' => ['col1'], 'type' => 'BTREE'];
        $result = $this->invoke('indexNeedsModification', $index, $index);
        $this->assertFalse($result);
    }

    public function testIndexNeedsModificationColumnsChanged(): void
    {
        $current = ['name' => 'idx', 'columns' => ['col1'], 'type' => 'BTREE'];
        $target = ['name' => 'idx', 'columns' => ['col1', 'col2'], 'type' => 'BTREE'];

        $result = $this->invoke('indexNeedsModification', $current, $target);
        $this->assertTrue($result);
    }

    public function testIndexNeedsModificationTypeChanged(): void
    {
        $current = ['name' => 'idx', 'columns' => ['col1'], 'type' => 'BTREE'];
        $target = ['name' => 'idx', 'columns' => ['col1'], 'type' => 'FULLTEXT'];

        $result = $this->invoke('indexNeedsModification', $current, $target);
        $this->assertTrue($result);
    }

    // ==================== addSql / formatSqlForFile ====================

    public function testAddSqlAndFormatSqlForFile(): void
    {
        $this->setProperty('sqlStatements', []);

        $this->invoke('addSql', 'CREATE TABLE `users` (id INT)', 'Create table: users', false);
        $this->invoke('addSql', 'ALTER TABLE `users` ADD COLUMN `name` VARCHAR(100)', 'Add column: name', false);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(2, $stmts);
        $this->assertSame('Create table: users', $stmts[0]['comment']);

        $output = $this->importer->formatSqlForFile();

        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS = 0;', $output);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS = 1;', $output);
        $this->assertStringContainsString('-- Create table: users', $output);
        $this->assertStringContainsString('CREATE TABLE `users` (id INT);', $output);
    }

    public function testFormatSqlForFileWithDelimiter(): void
    {
        $this->setProperty('sqlStatements', []);

        $this->invoke('addSql', 'CREATE FUNCTION `test`() RETURNS INT BEGIN RETURN 1; END', 'Create function: test', true);

        $output = $this->importer->formatSqlForFile();

        $this->assertStringContainsString('DELIMITER $$', $output);
        $this->assertStringContainsString('DELIMITER ;', $output);
    }

    // ==================== importFromFile ====================

    public function testImportFromFileNotFound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File not found');

        $this->importer->importFromFile('/nonexistent/file.json');
    }

    public function testImportFromFileInvalidJson(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'not valid json');

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Invalid JSON');
            $this->importer->importFromFile($tmpFile);
        } finally {
            unlink($tmpFile);
        }
    }

    // ==================== Full table create scenario ====================

    public function testCreateTableSql(): void
    {
        $this->setProperty('sqlStatements', []);

        $table = [
            'name' => 'users',
            'columns' => [
                ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'auto_increment' => true, 'unsigned' => true],
                ['name' => 'email', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'length' => 255],
                ['name' => 'name', 'type' => 'varchar', 'nullable' => true, 'default' => null, 'auto_increment' => false, 'length' => 100],
            ],
            'primary_key' => ['id'],
            'unique_keys' => [
                ['name' => 'uk_email', 'columns' => ['email']],
            ],
            'indexes' => [
                ['name' => 'idx_name', 'columns' => ['name'], 'type' => 'BTREE'],
            ],
            'engine' => 'InnoDB',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => null,
        ];

        $this->invoke('createTable', $table);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(1, $stmts);

        $sql = $stmts[0]['sql'];
        $this->assertStringContainsString('CREATE TABLE `users`', $sql);
        $this->assertStringContainsString('`id`', $sql);
        $this->assertStringContainsString('`email`', $sql);
        $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);
        $this->assertStringContainsString('UNIQUE KEY `uk_email` (`email`)', $sql);
        $this->assertStringContainsString('INDEX `idx_name`', $sql);
        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
        $this->assertStringContainsString('DEFAULT CHARSET=utf8mb4', $sql);
        $this->assertStringContainsString('COLLATE=utf8mb4_unicode_ci', $sql);
    }

    // ==================== Alter table scenario ====================

    public function testAlterTableSql(): void
    {
        $this->setProperty('sqlStatements', []);

        $current = [
            'name' => 'users',
            'columns' => [
                ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'auto_increment' => true],
                ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'length' => 100],
            ],
            'primary_key' => ['id'],
            'unique_keys' => [],
            'indexes' => [],
            'engine' => 'InnoDB',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => null,
        ];

        $target = [
            'name' => 'users',
            'columns' => [
                ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'auto_increment' => true],
                ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'length' => 255],
                ['name' => 'email', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'length' => 255],
            ],
            'primary_key' => ['id'],
            'unique_keys' => [
                ['name' => 'uk_email', 'columns' => ['email']],
            ],
            'indexes' => [],
            'engine' => 'InnoDB',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => null,
        ];

        $this->invoke('alterTable', $current, $target, false);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(1, $stmts);

        $sql = $stmts[0]['sql'];
        $this->assertStringContainsString('ALTER TABLE `users`', $sql);
        $this->assertStringContainsString('MODIFY COLUMN', $sql);
        $this->assertStringContainsString('ADD COLUMN', $sql);
        $this->assertStringContainsString('ADD UNIQUE KEY `uk_email`', $sql);
    }

    // ==================== Drop scenarios ====================

    public function testDropViewsSql(): void
    {
        $this->setProperty('sqlStatements', []);

        $current = [
            ['name' => 'view_old'],
            ['name' => 'view_keep'],
        ];
        $target = [
            ['name' => 'view_keep'],
        ];

        $this->invoke('dropViews', $current, $target);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(1, $stmts);
        $this->assertStringContainsString('DROP VIEW IF EXISTS `view_old`', $stmts[0]['sql']);
    }

    public function testDropFunctionsSql(): void
    {
        $this->setProperty('sqlStatements', []);

        $current = [['name' => 'func_old']];
        $target = [];

        $this->invoke('dropFunctions', $current, $target);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(1, $stmts);
        $this->assertStringContainsString('DROP FUNCTION IF EXISTS `func_old`', $stmts[0]['sql']);
    }

    public function testDropProceduresSql(): void
    {
        $this->setProperty('sqlStatements', []);

        $current = [['name' => 'proc_old']];
        $target = [];

        $this->invoke('dropProcedures', $current, $target);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(1, $stmts);
        $this->assertStringContainsString('DROP PROCEDURE IF EXISTS `proc_old`', $stmts[0]['sql']);
    }

    public function testDropTriggersSql(): void
    {
        $this->setProperty('sqlStatements', []);

        $current = [['name' => 'trig_old']];
        $target = [];

        $this->invoke('dropTriggers', $current, $target);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(1, $stmts);
        $this->assertStringContainsString('DROP TRIGGER IF EXISTS `trig_old`', $stmts[0]['sql']);
    }

    public function testDropEventsSql(): void
    {
        $this->setProperty('sqlStatements', []);

        $current = [['name' => 'evt_old']];
        $target = [];

        $this->invoke('dropEvents', $current, $target);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(1, $stmts);
        $this->assertStringContainsString('DROP EVENT IF EXISTS `evt_old`', $stmts[0]['sql']);
    }

    // ==================== Process Views ====================

    public function testProcessViewsSql(): void
    {
        $this->setProperty('sqlStatements', []);

        $target = [
            [
                'name' => 'active_users',
                'definition' => 'SELECT * FROM users WHERE active = 1',
                'security_type' => 'DEFINER',
                'check_option' => 'NONE',
            ],
        ];

        $this->invoke('processViews', [], $target, false);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(1, $stmts);

        $sql = $stmts[0]['sql'];
        $this->assertStringContainsString('CREATE OR REPLACE', $sql);
        $this->assertStringContainsString('DEFINER=CURRENT_USER', $sql);
        $this->assertStringContainsString('VIEW `active_users`', $sql);
        $this->assertStringContainsString('SELECT * FROM users WHERE active = 1', $sql);
    }

    public function testProcessViewsKeepDefiner(): void
    {
        $this->setProperty('sqlStatements', []);

        $target = [
            [
                'name' => 'v1',
                'definition' => 'SELECT 1',
                'security_type' => 'DEFINER',
                'check_option' => 'NONE',
            ],
        ];

        $this->invoke('processViews', [], $target, true);

        $stmts = $this->getProperty('sqlStatements');
        $sql = $stmts[0]['sql'];
        $this->assertStringNotContainsString('DEFINER=CURRENT_USER', $sql);
    }

    public function testProcessViewsWithCheckOption(): void
    {
        $this->setProperty('sqlStatements', []);

        $target = [
            [
                'name' => 'v1',
                'definition' => 'SELECT 1',
                'security_type' => 'DEFINER',
                'check_option' => 'CASCADED',
            ],
        ];

        $this->invoke('processViews', [], $target, false);

        $stmts = $this->getProperty('sqlStatements');
        $sql = $stmts[0]['sql'];
        $this->assertStringContainsString('WITH CASCADED CHECK OPTION', $sql);
    }

    // ==================== Process Functions ====================

    public function testProcessFunctionsSql(): void
    {
        $this->setProperty('sqlStatements', []);

        $target = [
            [
                'name' => 'add_numbers',
                'parameters' => [
                    ['name' => 'a', 'mode' => 'IN', 'type' => 'INT'],
                    ['name' => 'b', 'mode' => 'IN', 'type' => 'INT'],
                ],
                'returns' => 'INT',
                'deterministic' => true,
                'sql_data_access' => 'NO SQL',
                'security_type' => 'DEFINER',
                'body' => 'BEGIN RETURN a + b; END',
                'comment' => null,
            ],
        ];

        $this->invoke('processFunctions', [], $target, false);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(1, $stmts);

        $sql = $stmts[0]['sql'];
        $this->assertStringContainsString('CREATE DEFINER=CURRENT_USER FUNCTION `add_numbers`', $sql);
        $this->assertStringContainsString('`a` INT', $sql);
        $this->assertStringContainsString('`b` INT', $sql);
        $this->assertStringContainsString('RETURNS INT', $sql);
        $this->assertStringContainsString('DETERMINISTIC', $sql);
        $this->assertTrue($stmts[0]['delimiter']);
    }

    public function testProcessFunctionsReplacesExisting(): void
    {
        $this->setProperty('sqlStatements', []);

        $current = [
            ['name' => 'myfunc'],
        ];
        $target = [
            [
                'name' => 'myfunc',
                'parameters' => [],
                'returns' => 'INT',
                'deterministic' => false,
                'sql_data_access' => 'READS SQL DATA',
                'security_type' => 'DEFINER',
                'body' => 'BEGIN RETURN 1; END',
                'comment' => null,
            ],
        ];

        $this->invoke('processFunctions', $current, $target, false);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString('DROP FUNCTION IF EXISTS `myfunc`', $stmts[0]['sql']);
        $this->assertStringContainsString('CREATE', $stmts[1]['sql']);
    }

    // ==================== Process Procedures ====================

    public function testProcessProceduresSql(): void
    {
        $this->setProperty('sqlStatements', []);

        $target = [
            [
                'name' => 'get_user',
                'parameters' => [
                    ['name' => 'uid', 'mode' => 'IN', 'type' => 'INT'],
                    ['name' => 'uname', 'mode' => 'OUT', 'type' => 'VARCHAR(100)'],
                ],
                'deterministic' => false,
                'sql_data_access' => 'READS SQL DATA',
                'security_type' => 'DEFINER',
                'body' => 'BEGIN SELECT name INTO uname FROM users WHERE id = uid; END',
                'comment' => 'Get username',
            ],
        ];

        $this->invoke('processProcedures', [], $target, false);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(1, $stmts);

        $sql = $stmts[0]['sql'];
        $this->assertStringContainsString('CREATE DEFINER=CURRENT_USER PROCEDURE `get_user`', $sql);
        $this->assertStringContainsString('IN `uid` INT', $sql);
        $this->assertStringContainsString('OUT `uname` VARCHAR(100)', $sql);
        $this->assertStringContainsString('NOT DETERMINISTIC', $sql);
        $this->assertStringContainsString("COMMENT 'Get username'", $sql);
    }

    // ==================== Process Triggers ====================

    public function testProcessTriggersSql(): void
    {
        $this->setProperty('sqlStatements', []);

        $target = [
            [
                'name' => 'before_insert_users',
                'table' => 'users',
                'event' => 'INSERT',
                'timing' => 'BEFORE',
                'statement' => 'BEGIN SET NEW.created_at = NOW(); END',
            ],
        ];

        $this->invoke('processTriggers', [], $target, false);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(1, $stmts);

        $sql = $stmts[0]['sql'];
        $this->assertStringContainsString('CREATE DEFINER=CURRENT_USER TRIGGER `before_insert_users`', $sql);
        $this->assertStringContainsString('BEFORE INSERT', $sql);
        $this->assertStringContainsString('ON `users` FOR EACH ROW', $sql);
    }

    // ==================== Process Events ====================

    public function testProcessEventsOneTimeSql(): void
    {
        $this->setProperty('sqlStatements', []);

        $target = [
            [
                'name' => 'one_time_cleanup',
                'type' => 'ONE TIME',
                'execute_at' => '2025-12-31 23:59:59',
                'status' => 'ENABLED',
                'on_completion' => 'NOT PRESERVE',
                'definition' => 'DELETE FROM logs WHERE created_at < NOW() - INTERVAL 30 DAY',
                'comment' => null,
            ],
        ];

        $this->invoke('processEvents', [], $target, false);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(1, $stmts);

        $sql = $stmts[0]['sql'];
        $this->assertStringContainsString("AT '2025-12-31 23:59:59'", $sql);
        $this->assertStringContainsString('ENABLE', $sql);
    }

    public function testProcessEventsRecurringSql(): void
    {
        $this->setProperty('sqlStatements', []);

        $target = [
            [
                'name' => 'daily_cleanup',
                'type' => 'RECURRING',
                'interval_value' => 1,
                'interval_field' => 'DAY',
                'starts' => '2025-01-01 00:00:00',
                'ends' => null,
                'status' => 'ENABLED',
                'on_completion' => 'PRESERVE',
                'definition' => 'DELETE FROM logs WHERE created_at < NOW() - INTERVAL 30 DAY',
                'comment' => 'Daily log cleanup',
            ],
        ];

        $this->invoke('processEvents', [], $target, false);

        $stmts = $this->getProperty('sqlStatements');
        $sql = $stmts[0]['sql'];

        $this->assertStringContainsString('EVERY 1 DAY', $sql);
        $this->assertStringContainsString("STARTS '2025-01-01 00:00:00'", $sql);
        $this->assertStringContainsString('ON COMPLETION PRESERVE', $sql);
        $this->assertStringContainsString("COMMENT 'Daily log cleanup'", $sql);
    }

    public function testProcessEventsDisabledStatus(): void
    {
        $this->setProperty('sqlStatements', []);

        $target = [
            [
                'name' => 'disabled_evt',
                'type' => 'RECURRING',
                'interval_value' => 1,
                'interval_field' => 'HOUR',
                'starts' => null,
                'ends' => null,
                'status' => 'DISABLED',
                'on_completion' => 'NOT PRESERVE',
                'definition' => 'SELECT 1',
                'comment' => null,
            ],
        ];

        $this->invoke('processEvents', [], $target, false);

        $stmts = $this->getProperty('sqlStatements');
        $sql = $stmts[0]['sql'];
        $this->assertStringContainsString('DISABLE', $sql);
    }

    // ==================== Foreign keys ====================

    public function testAddForeignKeysSql(): void
    {
        $this->setProperty('sqlStatements', []);

        $targetTables = [
            [
                'name' => 'posts',
                'foreign_keys' => [
                    [
                        'name' => 'fk_posts_user',
                        'columns' => ['user_id'],
                        'referenced_table' => 'users',
                        'referenced_columns' => ['id'],
                        'on_delete' => 'CASCADE',
                        'on_update' => 'NO ACTION',
                    ],
                ],
            ],
        ];
        $currentTables = [
            ['name' => 'posts', 'foreign_keys' => []],
        ];

        $this->invoke('addForeignKeys', $targetTables, $currentTables);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(1, $stmts);

        $sql = $stmts[0]['sql'];
        $this->assertStringContainsString('ALTER TABLE `posts` ADD CONSTRAINT `fk_posts_user`', $sql);
        $this->assertStringContainsString('FOREIGN KEY (`user_id`)', $sql);
        $this->assertStringContainsString('REFERENCES `users` (`id`)', $sql);
        $this->assertStringContainsString('ON DELETE CASCADE', $sql);
        $this->assertStringContainsString('ON UPDATE NO ACTION', $sql);
    }

    public function testDropForeignKeysForModification(): void
    {
        $this->setProperty('sqlStatements', []);

        $currentTables = [
            [
                'name' => 'posts',
                'foreign_keys' => [
                    [
                        'name' => 'fk_old',
                        'columns' => ['user_id'],
                        'referenced_table' => 'users',
                        'referenced_columns' => ['id'],
                        'on_update' => 'NO ACTION',
                        'on_delete' => 'CASCADE',
                    ],
                ],
            ],
        ];
        // Target has no foreign keys for this table
        $targetTables = [
            ['name' => 'posts', 'foreign_keys' => []],
        ];

        $this->invoke('dropForeignKeysForModification', $currentTables, $targetTables);

        $stmts = $this->getProperty('sqlStatements');
        $this->assertCount(1, $stmts);
        $this->assertStringContainsString('DROP FOREIGN KEY `fk_old`', $stmts[0]['sql']);
    }
}
