<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database\Connection;
use App\Exceptions\BusinessRuleException;
use App\Repositories\BackupRepository;
use App\Services\BackupService;
use Tests\TestCase;

/**
 * Exercises backup creation, integrity verification and restoration.
 *
 * A backup feature that has never been restored from is not a backup feature,
 * so this suite performs a genuine round trip: it takes a backup, destroys
 * data, restores, and confirms the data came back.
 *
 * @package Tests\Integration
 * @version 1.0.0
 */
final class BackupRestoreTest extends TestCase
{
    protected bool $requiresDatabase = true;

    private BackupService $backups;
    private BackupRepository $repository;
    private Connection $connection;

    /** @var list<int> Backups created by this suite, removed afterwards. */
    private array $created = [];

    public function description(): string
    {
        return 'Database backup, integrity verification and restore';
    }

    public function setUp(): void
    {
        $this->backups    = $this->app->make(BackupService::class);
        $this->repository = $this->app->make(BackupRepository::class);
        $this->connection = $this->app->make(Connection::class);
    }

    public function tearDown(): void
    {
        foreach ($this->created as $backupId) {
            try {
                $this->backups->delete($backupId, 1);
            } catch (\Throwable) {
                // Already gone; nothing to clean up.
            }
        }

        $this->created = [];
    }

    public function testABackupIsProducedAndVerified(): void
    {
        $record = $this->backups->create('manual', 1);
        $this->created[] = (int) $record['backup_id'];

        $this->assertContains(
            (string) $record['status'],
            ['completed', 'verified'],
            'the backup completes'
        );

        $this->assertSame('ok', (string) $record['verification_result'], 'the archive verifies');
        $this->assertGreaterThan(0, (int) $record['file_size'], 'the archive has content');
        $this->assertGreaterThan(0, (int) $record['table_count'], 'tables were captured');
        $this->assertGreaterThan(0, (int) $record['row_count'], 'rows were captured');
        $this->assertMatches('/^[0-9a-f]{64}$/', (string) $record['checksum'], 'a SHA-256 checksum was recorded');

        $path = $this->app->basePath((string) config('backup.path', 'database/backups'))
            . DIRECTORY_SEPARATOR . (string) $record['filename'];

        $this->assertTrue(is_file($path), 'the archive exists on disk');
        $this->assertSame(
            (string) $record['checksum'],
            hash_file('sha256', $path),
            'the recorded checksum matches the file on disk'
        );
    }

    public function testTheArchiveContainsSchemaDataViewsAndTriggers(): void
    {
        $record = $this->backups->create('manual', 1);
        $this->created[] = (int) $record['backup_id'];

        $path = $this->app->basePath((string) config('backup.path', 'database/backups'))
            . DIRECTORY_SEPARATOR . (string) $record['filename'];

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'the archive opens');

        $sql = '';
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name      = (string) $zip->getNameIndex($index);
            $entries[] = $name;

            if (str_ends_with($name, '.sql')) {
                $sql = (string) $zip->getFromIndex($index);
            }
        }

        $manifest = $zip->getFromName('manifest.json');
        $zip->close();

        $this->assertTrue($sql !== '', 'the archive carries the SQL dump');
        $this->assertTrue($manifest !== false, 'the archive carries a manifest');
        $this->assertTrue(
            str_contains($sql, 'CREATE TABLE `vehicle_access_logs`'),
            'the schema is included'
        );
        $this->assertTrue(
            str_contains($sql, 'INSERT INTO `permissions`'),
            'table data is included'
        );
        $this->assertTrue(
            str_contains($sql, 'CREATE') && str_contains($sql, '`v_access_monitoring`'),
            'reporting views are included'
        );
        $this->assertTrue(
            str_contains($sql, 'trg_access_logs_entry_immutable'),
            'triggers are included'
        );

        // A DEFINER naming an account that does not exist on the target server
        // would make the whole restore fail.
        $this->assertFalse(
            str_contains($sql, 'DEFINER='),
            'DEFINER clauses are stripped so the dump restores on another server'
        );

        // The environment file holds the database credentials and must never
        // travel inside a backup.
        $this->assertFalse(
            in_array('.env', $entries, true),
            'the environment file is not included in the archive'
        );
    }

    public function testARoundTripRestoresDestroyedData(): void
    {
        // A recognisable marker row, so the assertion is about this test's data
        // rather than about the database happening to be non-empty.
        $marker = 'BACKUP-ROUNDTRIP-' . bin2hex(random_bytes(4));

        $this->connection->execute(
            'INSERT INTO `reference_codes` (`category`, `code`, `label`, `sort_order`, `status`)
             VALUES (?, ?, ?, ?, ?)',
            ['test_backup', $marker, 'Round-trip marker', 999, 'active']
        );

        $record = $this->backups->create('manual', 1);
        $this->created[] = (int) $record['backup_id'];

        // Destroy it.
        $this->connection->execute('DELETE FROM `reference_codes` WHERE `code` = ?', [$marker]);

        $this->assertSame(
            0,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM `reference_codes` WHERE `code` = ?', [$marker]),
            'the marker row was destroyed'
        );

        // Restore without taking a snapshot first: this test does not need the
        // extra archive, and taking one would slow the suite noticeably.
        $this->backups->restore((int) $record['backup_id'], 1, false);

        $this->assertSame(
            1,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM `reference_codes` WHERE `code` = ?', [$marker]),
            'the restore brought the destroyed row back'
        );

        // The restore must not have damaged anything else on the way through.
        $this->assertGreaterThan(
            0,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM `permissions`'),
            'the permission catalogue survived the restore'
        );
        $this->assertGreaterThan(
            0,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM `v_access_monitoring`'),
            'the reporting views work after the restore'
        );

        $this->connection->execute('DELETE FROM `reference_codes` WHERE `code` = ?', [$marker]);
    }

    public function testTriggersSurviveARestore(): void
    {
        $record = $this->backups->create('manual', 1);
        $this->created[] = (int) $record['backup_id'];

        $this->backups->restore((int) $record['backup_id'], 1, false);

        $triggers = (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'
        );

        $this->assertGreaterThan(0, $triggers, 'the restored database still has its triggers');

        // The immutability guarantee has to hold after a restore too, or a
        // restore would quietly remove a protection the system depends on.
        $openVisit = $this->connection->selectOne(
            "SELECT `access_log_id` FROM `vehicle_access_logs` WHERE `status` = 'completed' LIMIT 1"
        );

        if ($openVisit !== null) {
            $this->assertThrows(
                fn () => $this->connection->execute(
                    'UPDATE `vehicle_access_logs` SET `entry_time` = ? WHERE `access_log_id` = ?',
                    [now()->modify('-1 year')->format('Y-m-d H:i:s'), (int) $openVisit['access_log_id']]
                ),
                'the entry-immutability trigger still fires after a restore'
            );
        }
    }

    public function testACorruptedArchiveIsRefused(): void
    {
        $record = $this->backups->create('manual', 1);
        $backupId = (int) $record['backup_id'];
        $this->created[] = $backupId;

        $path = $this->app->basePath((string) config('backup.path', 'database/backups'))
            . DIRECTORY_SEPARATOR . (string) $record['filename'];

        // Alter the archive behind the system's back.
        file_put_contents($path, 'corrupted content', FILE_APPEND);

        // Restoring a corrupted archive over a working database would destroy
        // both, so it must be refused before anything is touched.
        $this->assertThrows(
            fn () => $this->backups->restore($backupId, 1, false),
            'an archive that no longer matches its checksum is refused',
            BusinessRuleException::class,
            'CHECKSUM_MISMATCH'
        );
    }

    public function testAMissingArchiveIsReportedNotIgnored(): void
    {
        $record = $this->backups->create('manual', 1);
        $backupId = (int) $record['backup_id'];

        $path = $this->app->basePath((string) config('backup.path', 'database/backups'))
            . DIRECTORY_SEPARATOR . (string) $record['filename'];

        @unlink($path);

        $this->assertThrows(
            fn () => $this->backups->restore($backupId, 1, false),
            'a backup whose file is gone is reported clearly',
            BusinessRuleException::class,
            'BACKUP_MISSING'
        );

        $reconciled = $this->backups->reconcile();

        $this->assertContains(
            (string) $record['filename'],
            $reconciled['missing_files'],
            'reconciliation reports the missing archive'
        );

        $this->repository->markDeleted($backupId, 1);
    }
}
