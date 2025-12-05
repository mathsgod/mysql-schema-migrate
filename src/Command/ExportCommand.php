<?php

namespace MysqlSchemaMigrate\Command;

use MysqlSchemaMigrate\Exporter;
use PDOException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'export',
    description: 'Export MySQL database schema to JSON format'
)]
class ExportCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('host', 'H', InputOption::VALUE_REQUIRED, 'MySQL host', 'localhost')
            ->addOption('port', 'P', InputOption::VALUE_REQUIRED, 'MySQL port', '3306')
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED, 'Database name')
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'MySQL username')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'MySQL password', '')
            ->addOption('charset', 'c', InputOption::VALUE_REQUIRED, 'Connection charset', 'utf8mb4')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path (default: stdout)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $host = $input->getOption('host');
        $port = (int) $input->getOption('port');
        $database = $input->getOption('database');
        $username = $input->getOption('username');
        $password = $input->getOption('password');
        $charset = $input->getOption('charset');
        $outputFile = $input->getOption('output');

        // Validate required options
        if (!$database) {
            $io->error('The --database option is required.');
            return Command::FAILURE;
        }

        if (!$username) {
            $io->error('The --username option is required.');
            return Command::FAILURE;
        }

        try {
            $exporter = new Exporter(
                host: $host,
                database: $database,
                username: $username,
                password: $password,
                port: $port,
                charset: $charset
            );

            $json = $exporter->toJson();

            if ($outputFile) {
                $dir = dirname($outputFile);
                if ($dir && !is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                
                file_put_contents($outputFile, $json);
                $io->success("Schema exported to: {$outputFile}");
            } else {
                $output->writeln($json);
            }

            return Command::SUCCESS;
        } catch (PDOException $e) {
            $io->error("Database connection failed: " . $e->getMessage());
            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->error("Export failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
