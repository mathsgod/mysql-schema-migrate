<?php

namespace MysqlSchemaMigrate\Command;

use MysqlSchemaMigrate\Importer;
use PDOException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'import',
    description: 'Import MySQL database schema from JSON format'
)]
class ImportCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('input', InputArgument::REQUIRED, 'Input JSON file path')
            ->addOption('host', 'H', InputOption::VALUE_REQUIRED, 'MySQL host', 'localhost')
            ->addOption('port', 'P', InputOption::VALUE_REQUIRED, 'MySQL port', '3306')
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED, 'Database name')
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'MySQL username')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'MySQL password', '')
            ->addOption('charset', 'c', InputOption::VALUE_REQUIRED, 'Connection charset', 'utf8mb4')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only generate SQL without executing')
            ->addOption('output-file', 'o', InputOption::VALUE_REQUIRED, 'Output SQL to file (use with --dry-run)')
            ->addOption('allow-drop', null, InputOption::VALUE_NONE, 'Allow dropping columns, tables, and other objects')
            ->addOption('keep-definer', null, InputOption::VALUE_NONE, 'Keep original DEFINER (default: reset to CURRENT_USER)')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Only process specific types (comma-separated: tables,views,triggers,functions,procedures,events)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $inputFile = $input->getArgument('input');
        $host = $input->getOption('host');
        $port = (int) $input->getOption('port');
        $database = $input->getOption('database');
        $username = $input->getOption('username');
        $password = $input->getOption('password');
        $charset = $input->getOption('charset');
        $dryRun = $input->getOption('dry-run');
        $outputFile = $input->getOption('output-file');
        $allowDrop = $input->getOption('allow-drop');
        $keepDefiner = $input->getOption('keep-definer');
        $onlyOption = $input->getOption('only');

        // Parse --only option
        $only = [];
        if ($onlyOption) {
            $only = array_map('trim', explode(',', $onlyOption));
            $validTypes = ['tables', 'views', 'triggers', 'functions', 'procedures', 'events'];
            foreach ($only as $type) {
                if (!in_array($type, $validTypes)) {
                    $io->error("Invalid type in --only: {$type}. Valid types: " . implode(', ', $validTypes));
                    return Command::FAILURE;
                }
            }
        }

        // Validate required options
        if (!$database) {
            $io->error('The --database option is required.');
            return Command::FAILURE;
        }

        if (!$username) {
            $io->error('The --username option is required.');
            return Command::FAILURE;
        }

        if (!file_exists($inputFile)) {
            $io->error("Input file not found: {$inputFile}");
            return Command::FAILURE;
        }

        try {
            $importer = new Importer(
                host: $host,
                database: $database,
                username: $username,
                password: $password,
                port: $port,
                charset: $charset
            );

            $io->title('MySQL Schema Import');
            $io->text([
                "Host: {$host}:{$port}",
                "Database: {$database}",
                "Input: {$inputFile}",
                "Dry Run: " . ($dryRun ? 'Yes' : 'No'),
                "Allow Drop: " . ($allowDrop ? 'Yes' : 'No'),
                "Keep Definer: " . ($keepDefiner ? 'Yes' : 'No'),
                "Only: " . ($only ? implode(', ', $only) : 'All'),
            ]);
            $io->newLine();

            $statements = $importer->importFromFile(
                filePath: $inputFile,
                dryRun: $dryRun,
                allowDrop: $allowDrop,
                keepDefiner: $keepDefiner,
                only: $only
            );

            if (empty($statements)) {
                $io->success('No changes needed. Schema is up to date.');
                return Command::SUCCESS;
            }

            $count = count($statements);
            $io->text("Generated {$count} SQL statement(s):");
            $io->newLine();

            if ($dryRun) {
                // Output SQL
                $sqlContent = $importer->formatSqlForFile();

                if ($outputFile) {
                    $dir = dirname($outputFile);
                    if ($dir && !is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    file_put_contents($outputFile, $sqlContent);
                    $io->success("SQL written to: {$outputFile}");
                } else {
                    // Output to console
                    $output->writeln($sqlContent);
                }

                $io->note("Dry run mode: No changes were made to the database.");
            } else {
                // Show summary of executed statements
                foreach ($statements as $stmt) {
                    $io->text("✓ {$stmt['comment']}");
                }
                $io->newLine();
                $io->success("Successfully executed {$count} SQL statement(s).");
            }

            return Command::SUCCESS;
        } catch (PDOException $e) {
            $io->error("Database error: " . $e->getMessage());
            return Command::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->error("Import failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
