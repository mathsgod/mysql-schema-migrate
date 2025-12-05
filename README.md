# MySQL Schema Migrate

Export and import MySQL database schema to/from JSON format.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-8892BF.svg)](https://www.php.net/)

## Features

- Export complete MySQL database schema to JSON format
- Import schema from JSON with intelligent diff detection
- Supports:
  - Tables (with columns, primary keys, unique keys, foreign keys, indexes)
  - Views
  - Triggers
  - Functions
  - Procedures
  - Events
- Smart schema diff: uses `ALTER TABLE` for existing tables instead of recreating
- Dry-run mode to preview SQL without executing
- CLI tool with easy-to-use options
- Can be used as a PHP library

## Requirements

- PHP >= 8.1
- PDO extension
- PDO MySQL extension
- JSON extension

## Installation

### Via Composer

```bash
composer require mathsgod/mysql-schema-migrate
```

### Global Installation

```bash
composer global require mathsgod/mysql-schema-migrate
```

## Usage

### Export Command

```bash
# Basic usage
./vendor/bin/mysql-schema-migrate export -u username -d database_name

# With password
./vendor/bin/mysql-schema-migrate export -u username -p password -d database_name

# Specify host and port
./vendor/bin/mysql-schema-migrate export -H localhost -P 3306 -u username -p password -d database_name

# Output to file
./vendor/bin/mysql-schema-migrate export -u username -d database_name -o schema.json

# With custom charset
./vendor/bin/mysql-schema-migrate export -u username -d database_name -c utf8mb4
```

#### Export Options

| Option | Short | Description | Default |
|--------|-------|-------------|---------|
| `--host` | `-H` | MySQL host | `localhost` |
| `--port` | `-P` | MySQL port | `3306` |
| `--database` | `-d` | Database name | (required) |
| `--username` | `-u` | MySQL username | (required) |
| `--password` | `-p` | MySQL password | (empty) |
| `--charset` | `-c` | Connection charset | `utf8mb4` |
| `--output` | `-o` | Output file path | stdout |

### Import Command

```bash
# Basic import
./vendor/bin/mysql-schema-migrate import -u username -d database_name schema.json

# Dry-run mode (preview SQL without executing)
./vendor/bin/mysql-schema-migrate import --dry-run -u username -d database_name schema.json

# Dry-run with SQL output to file
./vendor/bin/mysql-schema-migrate import --dry-run -o output.sql -u username -d database_name schema.json

# Allow dropping columns/tables/objects
./vendor/bin/mysql-schema-migrate import --allow-drop -u username -d database_name schema.json

# Keep original DEFINER (default: reset to CURRENT_USER)
./vendor/bin/mysql-schema-migrate import --keep-definer -u username -d database_name schema.json

# Import only specific object types
./vendor/bin/mysql-schema-migrate import --only=tables,views -u username -d database_name schema.json
```

#### Import Options

| Option | Short | Description | Default |
|--------|-------|-------------|---------|
| `--host` | `-H` | MySQL host | `localhost` |
| `--port` | `-P` | MySQL port | `3306` |
| `--database` | `-d` | Database name | (required) |
| `--username` | `-u` | MySQL username | (required) |
| `--password` | `-p` | MySQL password | (empty) |
| `--charset` | `-c` | Connection charset | `utf8mb4` |
| `--dry-run` | | Only generate SQL without executing | `false` |
| `--output-file` | `-o` | Output SQL to file (use with --dry-run) | |
| `--allow-drop` | | Allow dropping columns, tables, and objects | `false` |
| `--keep-definer` | | Keep original DEFINER | `false` |
| `--only` | | Only process specific types (comma-separated) | all |

#### Import Behavior

- **Tables**: Uses `ALTER TABLE` for existing tables (ADD/MODIFY/DROP COLUMN, index changes)
- **Views**: Uses `CREATE OR REPLACE VIEW`
- **Functions/Procedures/Triggers/Events**: Uses `DROP IF EXISTS` + `CREATE`
- **DEFINER**: Reset to `CURRENT_USER` by default (use `--keep-definer` to preserve)
- **Foreign Keys**: Handled separately to avoid dependency issues

### As a Library

```php
<?php

use MysqlSchemaMigrate\Exporter;
use MysqlSchemaMigrate\Importer;

// Export schema
$exporter = new Exporter(
    host: 'localhost',
    database: 'my_database',
    username: 'root',
    password: 'password',
    port: 3306,
    charset: 'utf8mb4'
);

// Get schema as array
$schema = $exporter->export();

// Get schema as JSON string
$json = $exporter->toJson();

// Save to file
file_put_contents('schema.json', $json);

// Import schema
$importer = new Importer(
    host: 'localhost',
    database: 'target_database',
    username: 'root',
    password: 'password',
    port: 3306,
    charset: 'utf8mb4'
);

// Import from file (dry-run)
$statements = $importer->importFromFile(
    filePath: 'schema.json',
    dryRun: true,
    allowDrop: false,
    keepDefiner: false,
    only: [] // empty = all types
);

// Get formatted SQL for review
$sql = $importer->formatSqlForFile();
file_put_contents('migration.sql', $sql);

// Import and execute
$statements = $importer->importFromFile(
    filePath: 'schema.json',
    dryRun: false,
    allowDrop: true
);
```

## Output Format

The exported JSON contains the following structure:

```json
{
    "tables": [
        {
            "name": "users",
            "columns": [
                {
                    "name": "id",
                    "type": "int",
                    "nullable": false,
                    "default": null,
                    "auto_increment": true,
                    "unsigned": true
                },
                {
                    "name": "email",
                    "type": "varchar",
                    "nullable": false,
                    "default": null,
                    "auto_increment": false,
                    "length": 255,
                    "charset": "utf8mb4",
                    "collation": "utf8mb4_unicode_ci"
                }
            ],
            "primary_key": ["id"],
            "unique_keys": [
                {
                    "name": "users_email_unique",
                    "columns": ["email"]
                }
            ],
            "foreign_keys": [],
            "indexes": [],
            "engine": "InnoDB",
            "charset": "utf8mb4",
            "collation": "utf8mb4_unicode_ci",
            "comment": null
        }
    ],
    "views": [],
    "triggers": [],
    "functions": [],
    "procedures": [],
    "events": []
}
```

## License

MIT License. See [LICENSE](LICENSE) for more information.

## Author

Raymond Chong (mathsgod@yahoo.com)
