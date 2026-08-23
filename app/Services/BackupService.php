<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Connection;
use App\Core\Events\EventDispatcher;
use App\Core\Support\Str;
use App\Events\BackupCompleted;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Repositories\BackupRepository;
use Throwable;
use ZipArchive;

/**
 * Database backup and recovery.
 *
 * Backups are produced by reading the schema and data through the existing PDO
 * connection rather than shelling out to mysqldump. That matters for the target
 * deployment: on a Windows XAMPP installation mysqldump is frequently absent
 * from the PATH, and a backup feature that silently does not work is worse than
 * no backup feature at all.
 *
 * Every archive carries a SHA-256 checksum, and a restore verifies it before
 * touching the live database. A restore also takes its own snapshot first, so
 * an operator who restores the wrong file has a way back.
 *
 * @package App\Services
 * @version 1.0.0
 */
class BackupService
{
    /** Rows read per batch, bounding memory on a large table. */
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly BackupRepository $backups,
        private readonly AuditService $audit,
        private readonly EventDispatcher $events
    ) {
    }

    /**
     * Produce a backup.
     *
     * @param string $type manual | scheduled | pre_restore
     *
     * @return array<string,mixed> The backup_history row.
     */
    public function create(string $type = 'manual', ?int $actorId = null, bool $includeUploads = false): array
    {
        $startedAt = microtime(true);
        $directory = $this->directory();
        $compress  = (bool) config('backup.compress', true);

        $basename = sprintf(
            '%s-%s-%s',
            Str::slug((string) config('app.short_name', 'vams')),
            now()->format('Ymd-His'),
            Str::randomCode(4)
        );

        $filename = $basename . ($compress ? '.zip' : '.sql');

        $backupId = $this->backups->create([
            'filename'    => $filename,
            'backup_type' => $type,
            'scope'       => $includeUploads ? 'full' : 'database',
            'compressed'  => $compress ? 1 : 0,
            'status'      => 'running',
            'created_by'  => $actorId,
            'created_at'  => now()->format('Y-m-d H:i:s'),
        ]);

        try {
            $dump = $this->dumpDatabase();

            $path = $directory . DIRECTORY_SEPARATOR . $filename;

            if ($compress) {
                $this->writeArchive($path, $basename . '.sql', $dump['sql'], $includeUploads);
            } elseif (file_put_contents($path, $dump['sql']) === false) {
                throw new \RuntimeException('The backup file could not be written.');
            }

            $size     = (int) filesize($path);
            $checksum = (string) hash_file((string) config('backup.checksum_algorithm', 'sha256'), $path);
            $duration = (int) round((microtime(true) - $startedAt) * 1000);

            $maximum = (int) config('backup.max_backup_bytes', 2147483648);
            if ($maximum > 0 && $size > $maximum) {
                @unlink($path);

                throw new \RuntimeException(sprintf(
                    'The backup is %s, which exceeds the configured maximum of %s.',
                    Str::bytes($size),
                    Str::bytes($maximum)
                ));
            }

            $verification = (bool) config('backup.verify_after_backup', true)
                ? $this->verifyArchive($path, $checksum)
                : 'not verified';

            $this->backups->complete($backupId, [
                'file_size'           => $size,
                'checksum'            => $checksum,
                'table_count'         => $dump['tables'],
                'row_count'           => $dump['rows'],
                'duration_ms'         => $duration,
                'status'              => $verification === 'ok' ? 'verified' : 'completed',
                'verified_at'         => $verification === 'ok' ? now()->format('Y-m-d H:i:s') : null,
                'verification_result' => $verification,
            ]);

            $this->audit->record('backup', 'created', sprintf(
                'Backup %s was created (%s, %d tables, %s rows, %dms).',
                $filename,
                Str::bytes($size),
                $dump['tables'],
                number_format($dump['rows']),
                $duration
            ), ['record_type' => 'backup_history', 'record_id' => $backupId]);

            $this->events->dispatch(new BackupCompleted(
                backupId: $backupId,
                filename: $filename,
                fileSize: $size,
                successful: true
            ));

            $this->pruneBeyondRetention($actorId);

            /** @var array<string,mixed> $record */
            $record = $this->backups->find($backupId);

            return $record;
        } catch (Throwable $e) {
            $this->backups->markFailed($backupId, $e->getMessage());

            $this->audit->failed('backup', 'created', sprintf(
                'Backup %s failed: %s',
                $filename,
                $e->getMessage()
            ), ['record_type' => 'backup_history', 'record_id' => $backupId]);

            $this->events->dispatch(new BackupCompleted(
                backupId: $backupId,
                filename: $filename,
                fileSize: 0,
                successful: false,
                message: $e->getMessage()
            ));

            throw $e;
        }
    }

    /**
     * Restore the database from a backup.
     *
     * @throws BusinessRuleException
     */
    public function restore(int $backupId, ?int $actorId, bool $snapshotFirst = true): void
    {
        $backup = $this->backups->find($backupId);

        if ($backup === null) {
            throw NotFoundException::record('Backup', $backupId);
        }

        $path = $this->directory() . DIRECTORY_SEPARATOR . (string) $backup['filename'];

        if (!is_readable($path)) {
            throw BusinessRuleException::withCode(
                'BACKUP_MISSING',
                sprintf('The archive %s is not present on disk.', (string) $backup['filename'])
            );
        }

        // Restoring a corrupted archive over a working database would destroy
        // both, so the checksum is confirmed before anything is touched.
        if ((bool) config('backup.restore.verify_checksum', true) && $backup['checksum'] !== null) {
            $actual = (string) hash_file((string) config('backup.checksum_algorithm', 'sha256'), $path);

            if (!hash_equals((string) $backup['checksum'], $actual)) {
                throw BusinessRuleException::withCode(
                    'CHECKSUM_MISMATCH',
                    'The archive does not match the checksum recorded when it was created. It may be corrupt or altered, and will not be restored.'
                );
            }
        }

        // A snapshot of the current state, so restoring the wrong file is
        // recoverable rather than final.
        if ($snapshotFirst && (bool) config('backup.restore.snapshot_before_restore', true)) {
            $this->create('pre_restore', $actorId);
        }

        $sql = $this->readArchive($path);

        if (trim($sql) === '') {
            throw BusinessRuleException::withCode('BACKUP_EMPTY', 'The archive contains no SQL to restore.');
        }

        $statements = \App\Core\Database\SqlScript::split($sql);
        $applied    = 0;

        // Constraint checking is suspended for the load: the dump is ordered by
        // table name, not by dependency, so a foreign key would otherwise
        // reject rows whose parent has not been inserted yet. It is restored in
        // the finally block whatever happens.
        $this->connection->unprepared('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($statements as $statement) {
                if (trim($statement) === '') {
                    continue;
                }

                $this->connection->unprepared($statement);
                $applied++;
            }
        } finally {
            $this->connection->unprepared('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->backups->markRestored($backupId, $actorId);

        $this->audit->record('backup', 'restored', sprintf(
            'The database was restored from %s (%d statements applied).',
            (string) $backup['filename'],
            $applied
        ), ['record_type' => 'backup_history', 'record_id' => $backupId]);
    }

    /**
     * Delete an archive and mark its history row.
     */
    public function delete(int $backupId, ?int $actorId): void
    {
        $backup = $this->backups->find($backupId);

        if ($backup === null) {
            throw NotFoundException::record('Backup', $backupId);
        }

        $path = $this->directory() . DIRECTORY_SEPARATOR . (string) $backup['filename'];

        if (is_file($path)) {
            @unlink($path);
        }

        // The history row survives the file: an audit of what backups existed
        // and when they were removed is part of the record.
        $this->backups->markDeleted($backupId, $actorId);

        $this->audit->record('backup', 'deleted', sprintf(
            'Backup %s was deleted.',
            (string) $backup['filename']
        ), ['record_type' => 'backup_history', 'record_id' => $backupId]);
    }

    /**
     * Read an archive for download.
     *
     * @return array{filename:string,contents:string,mime:string}
     */
    public function download(int $backupId): array
    {
        $backup = $this->backups->find($backupId);

        if ($backup === null) {
            throw NotFoundException::record('Backup', $backupId);
        }

        $filename = (string) $backup['filename'];
        $path     = $this->directory() . DIRECTORY_SEPARATOR . $filename;

        if (!is_readable($path)) {
            throw BusinessRuleException::withCode(
                'BACKUP_MISSING',
                'The archive is no longer present on disk.'
            );
        }

        $this->audit->record('backup', 'downloaded', sprintf(
            'Backup %s was downloaded.',
            $filename
        ), ['record_type' => 'backup_history', 'record_id' => $backupId]);

        return [
            'filename' => $filename,
            'contents' => (string) file_get_contents($path),
            'mime'     => str_ends_with($filename, '.zip') ? 'application/zip' : 'application/sql',
        ];
    }

    // ------------------------------------------------------------------
    // Dump generation
    // ------------------------------------------------------------------

    /**
     * Produce the SQL dump.
     *
     * @return array{sql:string,tables:int,rows:int}
     */
    private function dumpDatabase(): array
    {
        $database = $this->connection->databaseName();

        $sql = sprintf(
            "-- %s backup\n-- Database: %s\n-- Generated: %s\n-- Server: %s\n--\n"
            . "-- Restore with: php bin/console backup:restore <id>\n"
            . "-- Direct restore: mysql -u <user> -p %s < this-file.sql\n\n",
            (string) config('app.name', 'VAMS'),
            $database,
            now()->format('Y-m-d H:i:s P'),
            $this->connection->serverVersion(),
            $database
        );

        $sql .= "SET NAMES utf8mb4;\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

        $tables    = $this->tableNames();
        $totalRows = 0;

        foreach ($tables as $table) {
            $sql .= $this->dumpTable($table, $totalRows);
        }

        // Views are recreated after the tables they read from exist.
        foreach ($this->viewNames() as $view) {
            $sql .= $this->dumpView($view);
        }

        foreach ($this->triggerNames() as $trigger) {
            $sql .= $this->dumpTrigger($trigger);
        }

        $sql .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";
        $sql .= sprintf("-- Backup complete: %d table(s), %s row(s).\n", count($tables), number_format($totalRows));

        return ['sql' => $sql, 'tables' => count($tables), 'rows' => $totalRows];
    }

    /**
     * Dump one table's structure and contents.
     */
    private function dumpTable(string $table, int &$totalRows): string
    {
        $create = $this->connection->selectOne(sprintf('SHOW CREATE TABLE `%s`', $table));

        $sql = sprintf("\n--\n-- Table: %s\n--\n", $table);
        $sql .= sprintf("DROP TABLE IF EXISTS `%s`;\n", $table);
        $sql .= (string) ($create['Create Table'] ?? '') . ";\n\n";

        $count = (int) $this->connection->scalar(sprintf('SELECT COUNT(*) FROM `%s`', $table));

        if ($count === 0) {
            return $sql;
        }

        $totalRows += $count;

        // Rows are read in batches so a table with a million access records
        // does not have to fit in memory all at once.
        for ($offset = 0; $offset < $count; $offset += self::BATCH_SIZE) {
            $rows = $this->connection->select(sprintf(
                'SELECT * FROM `%s` LIMIT %d OFFSET %d',
                $table,
                self::BATCH_SIZE,
                $offset
            ));

            if ($rows === []) {
                break;
            }

            $columns = array_map(
                static fn (string $column): string => '`' . $column . '`',
                array_keys($rows[0])
            );

            $values = [];

            foreach ($rows as $row) {
                $values[] = '(' . implode(', ', array_map($this->quote(...), array_values($row))) . ')';
            }

            $sql .= sprintf(
                "INSERT INTO `%s` (%s) VALUES\n%s;\n",
                $table,
                implode(', ', $columns),
                implode(",\n", $values)
            );
        }

        return $sql . "\n";
    }

    private function dumpView(string $view): string
    {
        $create = $this->connection->selectOne(sprintf('SHOW CREATE VIEW `%s`', $view));

        $definition = (string) ($create['Create View'] ?? '');

        if ($definition === '') {
            return '';
        }

        // The DEFINER clause names an account that may not exist on the machine
        // the backup is restored to, which would make the whole restore fail.
        $definition = (string) preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $definition);

        return sprintf("\n--\n-- View: %s\n--\nDROP VIEW IF EXISTS `%s`;\n%s;\n", $view, $view, $definition);
    }

    private function dumpTrigger(string $trigger): string
    {
        $create = $this->connection->selectOne(sprintf('SHOW CREATE TRIGGER `%s`', $trigger));

        $definition = (string) ($create['SQL Original Statement'] ?? '');

        if ($definition === '') {
            return '';
        }

        $definition = (string) preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $definition);

        // A trigger body contains semicolons, so the restore has to be told
        // where the statement really ends.
        return sprintf(
            "\n--\n-- Trigger: %s\n--\nDROP TRIGGER IF EXISTS `%s`;\n\nDELIMITER $$\n%s$$\nDELIMITER ;\n",
            $trigger,
            $trigger,
            $definition
        );
    }

    /**
     * Quote a value for inclusion in an INSERT statement.
     */
    private function quote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        // PDO::quote applies the driver's own escaping, which is what keeps a
        // value containing quotes or backslashes from breaking the dump.
        return (string) $this->connection->pdo()->quote((string) $value);
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        return array_map(strval(...), $this->connection->column(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
              ORDER BY TABLE_NAME"
        ));
    }

    /**
     * @return list<string>
     */
    private function viewNames(): array
    {
        return array_map(strval(...), $this->connection->column(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
        ));
    }

    /**
     * @return list<string>
     */
    private function triggerNames(): array
    {
        return array_map(strval(...), $this->connection->column(
            'SELECT TRIGGER_NAME FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME'
        ));
    }

    // ------------------------------------------------------------------
    // Archive handling
    // ------------------------------------------------------------------

    /**
     * Write the compressed archive.
     */
    private function writeArchive(string $path, string $entryName, string $sql, bool $includeUploads): void
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The backup archive could not be created.');
        }

        $zip->addFromString($entryName, $sql);

        // A manifest travels with the archive so its provenance is readable
        // without the database that produced it.
        $zip->addFromString('manifest.json', (string) json_encode([
            'application' => (string) config('app.name', 'VAMS'),
            'version'     => (string) config('app.version', '1.0.0'),
            'organisation'=> (string) config('app.organization', ''),
            'database'    => $this->connection->databaseName(),
            'created_at'  => now()->format(DATE_ATOM),
            'sql_entry'   => $entryName,
        ], JSON_PRETTY_PRINT));

        if ((bool) config('backup.include.configuration', true)) {
            $configPath = base_path('config');

            foreach (glob($configPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                // Configuration files are shipped, but never the .env: it holds
                // the credentials the archive must not carry.
                $zip->addFile($file, 'config/' . basename($file));
            }
        }

        if ($includeUploads && (bool) config('backup.include.uploads', true)) {
            $this->addDirectory($zip, base_path('public/uploads'), 'uploads');
        }

        $zip->close();
    }

    /**
     * Add a directory tree to an archive.
     */
    private function addDirectory(ZipArchive $zip, string $directory, string $prefix): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() === '.gitkeep') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($directory) + 1);
            $zip->addFile($file->getPathname(), $prefix . '/' . str_replace('\\', '/', $relative));
        }
    }

    /**
     * Read the SQL out of an archive, compressed or not.
     */
    private function readArchive(string $path): string
    {
        if (!str_ends_with($path, '.zip')) {
            return (string) file_get_contents($path);
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new \RuntimeException('The archive could not be opened.');
        }

        $sql = '';

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_ends_with($name, '.sql')) {
                $sql = (string) $zip->getFromIndex($index);
                break;
            }
        }

        $zip->close();

        return $sql;
    }

    /**
     * Confirm an archive is readable and matches its checksum.
     */
    private function verifyArchive(string $path, string $expectedChecksum): string
    {
        $actual = (string) hash_file((string) config('backup.checksum_algorithm', 'sha256'), $path);

        if (!hash_equals($expectedChecksum, $actual)) {
            return 'checksum mismatch';
        }

        if (str_ends_with($path, '.zip')) {
            $zip = new ZipArchive();

            if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
                return 'archive is not readable';
            }

            $hasSql = false;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                if (str_ends_with((string) $zip->getNameIndex($index), '.sql')) {
                    $hasSql = true;
                    break;
                }
            }

            $zip->close();

            if (!$hasSql) {
                return 'archive contains no SQL';
            }
        }

        return 'ok';
    }

    /**
     * Remove archives beyond the retention count.
     */
    private function pruneBeyondRetention(?int $actorId): void
    {
        $keep = (int) config('backup.retention', 30);

        if ($keep <= 0) {
            return;
        }

        foreach ($this->backups->beyondRetention($keep) as $old) {
            $path = $this->directory() . DIRECTORY_SEPARATOR . (string) $old['filename'];

            if (is_file($path)) {
                @unlink($path);
            }

            // Null, not 0: retention pruning has no actor, and 0 is not a user
            // the foreign key would accept.
            $this->backups->markDeleted((int) $old['backup_id'], $actorId);
        }
    }

    /**
     * The backup directory, created if absent.
     */
    private function directory(): string
    {
        $directory = base_path((string) config('backup.path', 'database/backups'));

        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new \RuntimeException('The backup directory could not be created.');
        }

        return $directory;
    }

    /**
     * Backups on disk that have no history row, and history rows whose file is
     * gone. Both are worth surfacing rather than quietly ignoring.
     *
     * @return array{orphaned_files:list<string>,missing_files:list<string>}
     */
    public function reconcile(): array
    {
        $directory = $this->directory();
        $onDisk    = [];

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.{zip,sql}', GLOB_BRACE) ?: [] as $file) {
            $onDisk[] = basename($file);
        }

        $recorded = array_map(
            static fn (array $row): string => (string) $row['filename'],
            $this->backups->query()->whereIn('status', ['completed', 'verified', 'restored'])->get()
        );

        return [
            'orphaned_files' => array_values(array_diff($onDisk, $recorded)),
            'missing_files'  => array_values(array_diff($recorded, $onDisk)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        return array_merge($this->backups->summary(), $this->reconcile());
    }
}
