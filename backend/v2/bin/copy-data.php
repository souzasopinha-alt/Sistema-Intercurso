<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

$apply = in_array('--apply', $argv, true);
$allowedTables = [
    'emails_autorizados',
    'usuarios',
    'configuracoes',
    'pedidos',
    'opcoes',
    'camisas',
    'votos',
    'votos_realizados',
    'comprovantes',
];

function source_db(): PDO
{
    $host = v2_config('SOURCE_DB_HOST');
    $port = v2_config('SOURCE_DB_PORT', '3306');
    $name = v2_config('SOURCE_DB_NAME');
    $user = v2_config('SOURCE_DB_USER');
    $pass = v2_config('SOURCE_DB_PASS', '');

    if ($host === null || $name === null || $user === null) {
        throw new RuntimeException('SOURCE_DB connection settings are incomplete.');
    }

    return new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function table_columns(PDO $db, string $table): array
{
    $stmt = $db->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([$table]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function table_count(PDO $db, string $table): int
{
    return (int) $db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}

try {
    $sourceName = v2_config('SOURCE_DB_NAME');
    $targetName = v2_config('DB_NAME')
        ?? v2_config('MYSQLDATABASE');
    $sourceHost = v2_config('SOURCE_DB_HOST');
    $targetHost = v2_config('DB_HOST', 'localhost');
    if (
        $sourceName === null ||
        $targetName === null ||
        $sourceHost === null
    ) {
        throw new RuntimeException('Source and target database settings are incomplete.');
    }
    if (
        strcasecmp($sourceHost, $targetHost) === 0 &&
        strcasecmp($sourceName, $targetName) === 0
    ) {
        throw new RuntimeException('Source and target databases must be different.');
    }

    $source = source_db();
    $target = v2_db();

    $tablePlan = [];
    foreach ($allowedTables as $table) {
        $sourceColumns = table_columns($source, $table);
        $targetColumns = table_columns($target, $table);
        if ($sourceColumns === [] || $targetColumns === []) {
            throw new RuntimeException("A required migration table is missing: {$table}.");
        }

        $unexpectedColumns = array_diff($sourceColumns, $targetColumns);
        if ($unexpectedColumns !== []) {
            throw new RuntimeException(
                "Source table {$table} has columns not represented by the new schema."
            );
        }

        $tablePlan[$table] = array_values(array_intersect($targetColumns, $sourceColumns));
    }

    $source->beginTransaction();
    $target->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');

    $counts = [];
    foreach ($allowedTables as $table) {
        $sourceCount = table_count($source, $table);
        $targetCount = table_count($target, $table);
        if ($targetCount !== 0) {
            throw new RuntimeException(
                "Target table {$table} is not empty; refusing to merge or overwrite data."
            );
        }
        $counts[$table] = $sourceCount;
    }

    echo $apply ? "Migration mode: APPLY\n" : "Migration mode: DRY RUN\n";
    foreach ($counts as $table => $count) {
        printf("%-24s %d source rows\n", $table, $count);
    }
    echo "Session rows are intentionally not migrated.\n";
    echo "Uploaded files are not copied; keep/mount the existing persistent storage separately.\n";

    if (!$apply) {
        $source->rollBack();
        $target->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
        exit(0);
    }

    $target->beginTransaction();
    foreach ($allowedTables as $table) {
        $columns = $tablePlan[$table];
        $quotedColumns = implode(', ', array_map(
            static fn(string $column): string => "`{$column}`",
            $columns
        ));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $select = $source->query(
            "SELECT {$quotedColumns} FROM `{$table}` ORDER BY `"
            . ($table === 'configuracoes' ? 'chave' : 'id')
            . '`'
        );
        $insert = $target->prepare(
            "INSERT INTO `{$table}` ({$quotedColumns}) VALUES ({$placeholders})"
        );

        while ($row = $select->fetch(PDO::FETCH_NUM)) {
            $insert->execute($row);
        }
    }

    foreach ($allowedTables as $table) {
        if (table_count($target, $table) !== $counts[$table]) {
            throw new RuntimeException(
                "Row-count verification failed for {$table}; migration rolled back."
            );
        }
    }

    $target->commit();
    $source->rollBack();
    $target->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
    echo "Migration copied and verified all listed tables.\n";
} catch (Throwable $error) {
    if (isset($target) && $target instanceof PDO && $target->inTransaction()) {
        $target->rollBack();
    }
    if (isset($source) && $source instanceof PDO && $source->inTransaction()) {
        $source->rollBack();
    }
    if (isset($target) && $target instanceof PDO) {
        $target->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
    }

    error_log('V2 data copy failed: ' . $error->getMessage());
    fwrite(STDERR, "Migration stopped safely: {$error->getMessage()}\n");
    exit(1);
}
