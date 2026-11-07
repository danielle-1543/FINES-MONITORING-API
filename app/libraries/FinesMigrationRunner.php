<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
if (!class_exists('FinesMigrationRunner', false)) {
    final class FinesMigrationRunner
    {
        private const TABLE = 'fines_schema_migrations';

        public static function definitions(): array
        {
            $root = dirname(__DIR__, 2);
            $files = glob($root . '/app/migrations/[0-9][0-9][0-9]_*.php');
            sort($files, SORT_STRING);
            $definitions = [];

            foreach ($files as $file) {
                require_once $file;
                $class = substr(basename($file, '.php'), 4);
                if (!class_exists($class, false)) {
                    throw new RuntimeException('Migration class missing: ' . basename($file));
                }
                if (!defined($class . '::KEY')) {
                    continue;
                }

                $key = $class::KEY;
                if (isset($definitions[$key])) {
                    throw new RuntimeException('Duplicate migration key: ' . $key);
                }
                $migration = new $class();
                $definitions[$key] = [
                    'file' => basename($file),
                    'description' => $class::DESCRIPTION,
                    'dependencies' => $class::DEPENDENCIES,
                    'up' => static function (PDO $pdo, string $root) use ($migration): void {
                        $migration->up($pdo, $root);
                    },
                ];
            }

            $seen = [];
            foreach ($definitions as $key => $definition) {
                foreach ($definition['dependencies'] as $dependency) {
                    if (!isset($seen[$dependency])) {
                        throw new RuntimeException('Migration dependency must precede ' . $key . ': ' . $dependency);
                    }
                }
                $seen[$key] = true;
            }

            return $definitions;
        }

        public static function apply(string $key): void
        {
            $result = self::main(['migrate', '--run', '--only=' . $key], self::definitions());
            if ($result !== 0) {
                throw new RuntimeException('Migration failed: ' . $key);
            }
        }

        public static function main(array $arguments, array $migrations, ?PDO $connection = null): int
        {
            $root = dirname(__DIR__, 2);
            $run = in_array('--run', $arguments, true);
            $status = in_array('--status', $arguments, true);
            $only = null;
            foreach (array_slice($arguments, 1) as $argument) {
                if (str_starts_with($argument, '--only=')) {
                    $only = substr($argument, 7);
                } elseif (!in_array($argument, [
                    '--run',
                    '--status',
                    '--list',
                    '--help'
                ], true)) {
                    fwrite(STDERR, "Unknown migration option. Use --help.\n");
                    return 1;
                }
            }
            if ($only !== null && !isset($migrations[$only])) {
                fwrite(STDERR, "Unknown migration name. Use --list.\n");
                return 1;
            }
            if (in_array('--list', $arguments, true)) {
                foreach ($migrations as $name => $migration) {
                    echo ($migration['file'] ?? $name) . ' — ' . $migration['description'] . PHP_EOL;
                }
                return 0;
            }
            if ((!$run && !$status) || in_array('--help', $arguments, true)) {
                echo "Usage:\n";
                echo "  php console/migrate.php --list\n";
                echo "  php console/migrate.php --status\n";
                echo "  php console/migrate.php --run\n";
                echo "  php console/migrate.php --run --only=payment_allocations\n";
                return 0;
            }
            $pdo = null;
            $lockName = null;
            try {
                $pdo = $connection ?? self::connect($root);
                $selected = [];
                $include = function (string $name) use ( & $include, & $selected, $migrations): void {
                    if (isset($selected[$name])) {
                        return;
                    }
                    foreach ($migrations[$name]['dependencies'] as $dependency) {
                        $include($dependency);
                    }
                    $selected[$name] = true;
                };
                if ($only !== null) {
                    $include($only);
                } else {
                    $selected = array_fill_keys(array_keys($migrations), true);
                }
                if ($status) {
                    $completed = self::completed($pdo);
                    foreach ($migrations as $name => $migration) {
                        if (isset($selected[$name])) {
                            echo(isset($completed[$name]) ? '[APPLIED] ' : '[PENDING] ') . $name . PHP_EOL;
                        }
                    }
                    return 0;
                }
                $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
                $lockName = 'fines_migrations_' . substr(hash('sha256', $database), 0, 32);
                $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
                $lock->execute([
                    $lockName
                ]);
                if ((int) $lock->fetchColumn() !== 1) {
                    $lockName = null;
                    throw new RuntimeException('Another migration process is running.');
                }
                $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                    migration VARCHAR(100) NOT NULL PRIMARY KEY,
                    applied_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                $completed = self::completed($pdo);
                foreach ($migrations as $name => $migration) {
                    if (!isset($selected[$name])) {
                        continue;
                    }
                    if (isset($completed[$name])) {
                        echo '[SKIP] ' . $name . PHP_EOL;
                        continue;
                    }
                    echo '[RUN] ' . $name . PHP_EOL;
                    $migration['up']($pdo, $root);
                    $record = $pdo->prepare('INSERT INTO ' . self::TABLE . ' (migration, applied_at) VALUES (?, ?)');
                    $record->execute([
                        $name,
                        (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'),
                    ]);
                }
                echo "Migrations complete.\n";
                return 0;
            } catch (Throwable $error) {
                if ($pdo && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
                return 1;
            } finally {
                if ($pdo && $lockName !== null) {
                    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([
                        $lockName
                    ]);
                }
            }
        }

        private static function connect(string $root): PDO
        {
            foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                putenv(trim($key) . '=' . trim(trim($value), "\"'"));
            }
            require $root . '/app/config/database.php';
            $connection = $database['main'];
            return new PDO("mysql:host={$connection['hostname']};port={$connection['port']};dbname={$connection['database']};charset=utf8mb4", $connection['username'], $connection['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
        }

        private static function completed(PDO $pdo): array
        {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
            $exists->execute([
                self::TABLE
            ]);
            if (!$exists->fetchColumn()) {
                return [];
            }
            return array_fill_keys($pdo->query('SELECT migration FROM ' . self::TABLE)->fetchAll(PDO::FETCH_COLUMN), true);
        }
    }
}
