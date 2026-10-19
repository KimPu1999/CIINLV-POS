<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run from the CLI.\n");
    exit(2);
}

$database = getenv('CIINLV_CONCURRENCY_DB') ?: getenv('DB_DATABASE') ?: '';
if (! str_ends_with($database, '_test')) {
    fwrite(STDERR, "Refusing to run: CIINLV_CONCURRENCY_DB must end in _test.\n");
    exit(2);
}
if (! function_exists('pcntl_fork')) {
    fwrite(STDERR, "SKIPPED: pcntl is not installed.\n");
    exit(0);
}

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '5432', $database);
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$username = getenv('DB_USERNAME') ?: 'ciinlv_user';
$password = getenv('DB_PASSWORD') ?: '';
$table = 'phase8_concurrency_probe';
$setup = new PDO($dsn, $username, $password, $options);

try {
    $setup->exec("DROP TABLE IF EXISTS {$table}");
    $setup->exec("CREATE TABLE {$table} (id integer PRIMARY KEY, quantity numeric(18,3) NOT NULL)");
    $setup->exec("INSERT INTO {$table} (id, quantity) VALUES (1, 10)");
    // Do not fork with an active PDO connection. Each worker must own its
    // connection so a child cannot close the parent's PostgreSQL session.
    $setup = null;
    $startAt = microtime(true) + 0.5;
    $children = [];

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork concurrency worker.');
        }
        if ($pid === 0) {
            $worker = new PDO($dsn, $username, $password, $options);
            while (microtime(true) < $startAt) {
                usleep(1000);
            }
            $worker->beginTransaction();
            $quantity = (string) $worker->query("SELECT quantity FROM {$table} WHERE id = 1 FOR UPDATE")->fetchColumn();
            if (bccomp($quantity, '8', 3) < 0) {
                $worker->rollBack();
                exit(10);
            }
            usleep(100000);
            $worker->exec("UPDATE {$table} SET quantity = quantity - 8 WHERE id = 1");
            $worker->commit();
            exit(0);
        }
        $children[] = $pid;
    }

    $statuses = [];
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $statuses[] = pcntl_wexitstatus($status);
    }
    sort($statuses);
    $verify = new PDO($dsn, $username, $password, $options);
    $final = (string) $verify->query("SELECT quantity FROM {$table} WHERE id = 1")->fetchColumn();
    $verify = null;
    if ($statuses !== [0, 10] || bccomp($final, '2', 3) !== 0) {
        throw new RuntimeException('Concurrent stock lock smoke test failed.');
    }

    printf("Concurrent lock smoke test passed: one worker committed, one was rejected, final quantity=%s\n", $final);
} finally {
    $cleanup = new PDO($dsn, $username, $password, $options);
    $cleanup->exec("DROP TABLE IF EXISTS {$table}");
}
