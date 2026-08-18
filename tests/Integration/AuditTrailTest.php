<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\AuditLogRepository;
use App\Repositories\SecurityEventRepository;
use App\Services\AuditService;
use App\Services\SecurityEventService;
use LogicException;
use Tests\TestCase;

/**
 * Verifies that the audit trail and security log are append-only and that
 * sensitive values never reach them in readable form.
 *
 * @package Tests\Integration
 * @version 1.0.0
 */
final class AuditTrailTest extends TestCase
{
    protected bool $requiresDatabase = true;

    private AuditService $audit;
    private AuditLogRepository $auditLogs;
    private SecurityEventService $security;
    private SecurityEventRepository $securityEvents;

    public function description(): string
    {
        return 'Append-only audit trail and security event evidence';
    }

    public function setUp(): void
    {
        $this->audit          = $this->app->make(AuditService::class);
        $this->auditLogs      = $this->app->make(AuditLogRepository::class);
        $this->security       = $this->app->make(SecurityEventService::class);
        $this->securityEvents = $this->app->make(SecurityEventRepository::class);
    }

    public function testAuditRecordsCannotBeModified(): void
    {
        $this->assertThrows(
            fn () => $this->auditLogs->update(1, ['action' => 'tampered']),
            'an audit record cannot be updated',
            LogicException::class
        );

        $this->assertThrows(
            fn () => $this->auditLogs->delete(1),
            'an audit record cannot be deleted',
            LogicException::class
        );

        $this->assertThrows(
            fn () => $this->auditLogs->forceDelete(1),
            'an audit record cannot be force-deleted',
            LogicException::class
        );
    }

    public function testSecurityEventsCannotBeDeleted(): void
    {
        $this->assertThrows(
            fn () => $this->securityEvents->delete(1),
            'a security event cannot be deleted',
            LogicException::class
        );
    }

    public function testAnActionIsRecordedWithItsContext(): void
    {
        $before = $this->auditLogs->countSince(now()->modify('-1 minute')->format('Y-m-d H:i:s'));

        $this->audit->record('testing', 'verified', 'The automated test suite recorded an action.', [
            'record_type' => 'tests',
            'record_id'   => 'audit-trail',
        ]);

        $after = $this->auditLogs->countSince(now()->modify('-1 minute')->format('Y-m-d H:i:s'));

        $this->assertGreaterThan($before, $after, 'the action appears in the audit trail');

        $records = $this->auditLogs->forRecord('tests', 'audit-trail', 1);

        $this->assertCount(1, $records, 'the record is retrievable by its subject');
        $this->assertSame('verified', (string) $records[0]['action'], 'the action verb is stored');
        $this->assertSame('testing', (string) $records[0]['module'], 'the module is stored');
    }

    public function testChangedValuesAreStoredAsADifference(): void
    {
        $this->audit->updated(
            'testing',
            'tests',
            'diff-check',
            'A record was updated by the test suite.',
            ['status' => 'active', 'colour' => 'red', 'untouched' => 'same'],
            ['status' => 'inactive', 'colour' => 'red', 'untouched' => 'same']
        );

        $records = $this->auditLogs->forRecord('tests', 'diff-check', 1);

        $this->assertCount(1, $records, 'the update is recorded');

        /** @var array<string,mixed> $newValues */
        $newValues = json_decode((string) $records[0]['new_values'], true) ?? [];

        $this->assertTrue(array_key_exists('status', $newValues), 'the changed field is recorded');
        $this->assertFalse(array_key_exists('colour', $newValues), 'an unchanged field is not recorded');
        $this->assertFalse(array_key_exists('untouched', $newValues), 'a second unchanged field is not recorded');
    }

    public function testSensitiveValuesAreRedacted(): void
    {
        $this->audit->updated(
            'testing',
            'tests',
            'redaction-check',
            'A credential was changed by the test suite.',
            ['password' => 'old-secret-value', 'api_key' => 'old-key-value'],
            ['password' => 'new-secret-value', 'api_key' => 'new-key-value']
        );

        $records = $this->auditLogs->forRecord('tests', 'redaction-check', 1);
        $stored  = (string) ($records[0]['new_values'] ?? '') . (string) ($records[0]['old_values'] ?? '');

        // The whole point of the audit trail is that it proves a credential
        // changed without becoming a place to read credentials from.
        $this->assertFalse(str_contains($stored, 'new-secret-value'), 'a new password does not reach the audit trail');
        $this->assertFalse(str_contains($stored, 'old-secret-value'), 'an old password does not reach the audit trail');
        $this->assertFalse(str_contains($stored, 'new-key-value'), 'an API key does not reach the audit trail');
        $this->assertTrue(str_contains($stored, 'redacted'), 'the fields are recorded as redacted');
    }

    public function testSecurityEventsCarryTheirEvidence(): void
    {
        $eventId = $this->security->record(
            'unauthorized_access',
            'The automated test suite simulated an authorisation refusal.',
            ['required_permission' => 'tests.simulate', 'path' => '/tests/simulate'],
            'rejected'
        );

        $this->assertNotNull($eventId, 'the event was stored');

        $event = $this->securityEvents->find((int) $eventId);

        $this->assertNotNull($event, 'the event is retrievable');
        $this->assertSame('unauthorized_access', (string) $event['event_type'], 'the type is stored');
        $this->assertSame('high', (string) $event['severity'], 'the severity comes from the central mapping');
        $this->assertSame('rejected', (string) $event['action_taken'], 'the action taken is stored');
        $this->assertTrue(
            str_contains((string) $event['detail'], 'tests.simulate'),
            'the structured evidence is stored'
        );
    }

    public function testTriageUpdatesTheStatusButNotTheEvidence(): void
    {
        $eventId = (int) $this->security->record(
            'failed_login',
            'The automated test suite simulated a failed sign-in.',
            ['username' => 'triage-check'],
            'rejected'
        );

        $before = $this->securityEvents->find($eventId);

        $this->security->acknowledge($eventId, 1, 'resolved', 'Reviewed by the automated test suite.');

        $after = $this->securityEvents->find($eventId);

        $this->assertSame('resolved', (string) $after['status'], 'the status is updated');
        $this->assertSame('Reviewed by the automated test suite.', (string) $after['resolution_notes'], 'the note is stored');
        $this->assertSame(
            (string) $before['description'],
            (string) $after['description'],
            'the original description is unchanged'
        );
        $this->assertSame(
            (string) $before['occurred_at'],
            (string) $after['occurred_at'],
            'the original timestamp is unchanged'
        );
    }
}
