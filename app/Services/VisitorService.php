<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Connection;
use App\Core\Database\Paginator;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Repositories\AccessLogRepository;
use App\Repositories\RfidCardRepository;
use App\Repositories\VisitorLogRepository;
use App\Repositories\VisitorRepository;

/**
 * Visitor registration and temporary pass issuance.
 *
 * @package App\Services
 * @version 1.0.0
 */
class VisitorService
{
    public function __construct(
        private readonly VisitorRepository $visitors,
        private readonly VisitorLogRepository $passes,
        private readonly RfidCardRepository $cards,
        private readonly AccessLogRepository $accessLogs,
        private readonly AuditService $audit,
        private readonly Connection $connection
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginateVisitors(array $filters, array $options): Paginator
    {
        return $this->visitors->paginate($filters, $options);
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginatePasses(array $filters, array $options): Paginator
    {
        return $this->passes->paginate($filters, $options);
    }

    /**
     * Register a visitor, reusing an existing record when the same person
     * returns. A memorial park sees the same families repeatedly, and creating
     * a duplicate each visit would make the history useless.
     *
     * @param array<string,mixed> $attributes
     */
    public function registerVisitor(array $attributes, ?int $actorId): int
    {
        $governmentId = trim((string) ($attributes['government_id'] ?? ''));

        if ($governmentId !== '') {
            $existing = $this->visitors->findByGovernmentId($governmentId);

            if ($existing !== null) {
                $visitorId = (int) $existing['visitor_id'];

                $this->visitors->update($visitorId, array_merge($attributes, ['updated_by' => $actorId]));

                $this->audit->updated('visitors', 'visitors', $visitorId, sprintf(
                    'Returning visitor %s was updated.',
                    (string) $existing['full_name']
                ), $existing, $attributes);

                return $visitorId;
            }
        }

        $attributes['visitor_code'] ??= $this->nextVisitorCode();

        $visitorId = $this->visitors->create(array_merge($attributes, [
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]));

        $this->audit->created('visitors', 'visitors', $visitorId, sprintf(
            'Visitor %s %s was registered.',
            (string) ($attributes['first_name'] ?? ''),
            (string) ($attributes['last_name'] ?? '')
        ), $attributes);

        return $visitorId;
    }

    /**
     * Issue a temporary pass and hand over a card.
     *
     * @param array<string,mixed> $attributes visitor_id, rfid_card_id, purpose,
     *                                        destination, vehicle_plate,
     *                                        companions, authorized_by, hours
     *
     * @throws BusinessRuleException
     */
    public function issuePass(array $attributes, ?int $actorId): int
    {
        $visitorId = (int) ($attributes['visitor_id'] ?? 0);
        $visitor   = $this->visitors->findWithDetail($visitorId);

        if ($visitor === null) {
            throw NotFoundException::record('Visitor', $visitorId);
        }

        if ((int) $visitor['is_blacklisted'] === 1) {
            throw BusinessRuleException::withCode(
                'VISITOR_BLACKLISTED',
                sprintf('This visitor is barred from entry: %s', (string) ($visitor['blacklist_reason'] ?? 'no reason recorded'))
            );
        }

        // One open pass per visitor: two would make "who is inside" ambiguous.
        $open = $this->passes->activity()
            ->whereEquals('visitor_id', $visitorId)
            ->whereIn('status', ['issued', 'inside'])
            ->first();

        if ($open !== null) {
            throw BusinessRuleException::withCode(
                'PASS_ALREADY_OPEN',
                sprintf('This visitor already holds an open pass (%s).', (string) $open['pass_reference'])
            );
        }

        $cardId = isset($attributes['rfid_card_id']) && $attributes['rfid_card_id'] !== ''
            ? (int) $attributes['rfid_card_id']
            : null;

        if ($cardId !== null) {
            $this->assertCardAvailable($cardId);
        }

        // The pass length comes from the visitor's category unless the operator
        // set one explicitly.
        $hours = isset($attributes['hours']) && (int) $attributes['hours'] > 0
            ? (int) $attributes['hours']
            : (int) ($visitor['default_validity_hours'] ?? config('monitoring.visitor.default_validity_hours', 12));

        if ((bool) config('monitoring.visitor.require_authoriser', true)
            && ($attributes['authorized_by'] ?? null) === null) {
            throw BusinessRuleException::withCode(
                'AUTHORISER_REQUIRED',
                'A visitor pass must record the person who authorised the visit.'
            );
        }

        $validFrom  = now();
        $validUntil = $validFrom->modify('+' . max(1, $hours) . ' hours');
        $reference  = $this->passes->nextReference();

        $passId = $this->connection->transaction(function () use ($attributes, $visitorId, $cardId, $reference, $validFrom, $validUntil, $actorId): int {
            $id = $this->passes->create([
                'pass_reference'      => $reference,
                'visitor_id'          => $visitorId,
                'rfid_card_id'        => $cardId,
                'purpose'             => (string) ($attributes['purpose'] ?? 'Not stated'),
                'destination'         => $attributes['destination'] ?? null,
                'vehicle_plate'       => isset($attributes['vehicle_plate'])
                    ? \App\Core\Support\Str::normalisePlate((string) $attributes['vehicle_plate'])
                    : null,
                'vehicle_description' => $attributes['vehicle_description'] ?? null,
                'companions'          => (int) ($attributes['companions'] ?? 0),
                'authorized_by'       => $attributes['authorized_by'] ?? null,
                'issued_by'           => $actorId,
                'issued_at'           => $validFrom->format('Y-m-d H:i:s'),
                'valid_from'          => $validFrom->format('Y-m-d H:i:s'),
                'valid_until'         => $validUntil->format('Y-m-d H:i:s'),
                'status'              => 'issued',
                'remarks'             => $attributes['remarks'] ?? null,
            ]);

            if ($cardId !== null) {
                $this->cards->markIssued($cardId);
            }

            return $id;
        });

        $this->audit->created('visitors', 'visitor_logs', $passId, sprintf(
            'Pass %s was issued to %s, valid until %s.',
            $reference,
            (string) $visitor['full_name'],
            $validUntil->format('Y-m-d H:i')
        ), ['pass_reference' => $reference, 'visitor_id' => $visitorId, 'valid_until' => $validUntil->format('Y-m-d H:i:s')]);

        return $passId;
    }

    /**
     * Revoke a pass before it expires.
     *
     * @throws BusinessRuleException
     */
    public function revokePass(int $passId, string $reason, int $actorId): void
    {
        $pass = $this->passes->findInView($passId);

        if ($pass === null) {
            throw NotFoundException::record('Visitor pass', $passId);
        }

        if (!in_array((string) $pass['status'], ['issued', 'inside'], true)) {
            throw BusinessRuleException::withCode('PASS_NOT_OPEN', 'This pass is no longer open.');
        }

        // Revoking a pass while its holder is still inside would leave an open
        // visit nobody can close by scanning out.
        if ((string) $pass['status'] === 'inside') {
            throw BusinessRuleException::withCode(
                'VISITOR_INSIDE',
                'This visitor is currently inside. Record their exit, or close the visit administratively, before revoking the pass.'
            );
        }

        $this->connection->transaction(function () use ($passId, $reason, $actorId, $pass): void {
            $this->passes->revoke($passId, $actorId, $reason);

            if ($pass['rfid_card_id'] !== null) {
                $this->cards->markReturned((int) $pass['rfid_card_id']);
            }
        });

        $this->audit->record('visitors', 'revoked', sprintf(
            'Pass %s was revoked: %s',
            (string) $pass['pass_reference'],
            $reason
        ), ['record_type' => 'visitor_logs', 'record_id' => $passId]);
    }

    public function setBlacklisted(int $visitorId, bool $blacklisted, ?string $reason, int $actorId): void
    {
        $visitor = $this->visitors->findOrFail($visitorId);

        $this->visitors->setBlacklisted($visitorId, $blacklisted, $reason, $actorId);

        $this->audit->record('visitors', $blacklisted ? 'blacklisted' : 'unblacklisted', sprintf(
            'Visitor %s was %s.%s',
            (string) $visitor['full_name'],
            $blacklisted ? 'barred from entry' : 'permitted to enter again',
            $blacklisted && $reason !== null ? ' Reason: ' . $reason : ''
        ), ['record_type' => 'visitors', 'record_id' => $visitorId]);
    }

    /**
     * Close out passes whose validity has elapsed.
     *
     * Run by the maintenance task. A pass whose holder is still inside is left
     * open deliberately: expiring it would erase the fact that somebody is on
     * the premises.
     *
     * @return int Number of passes expired.
     */
    public function expireOverduePasses(): int
    {
        $expired = $this->passes->expireOverdue();

        if ($expired > 0) {
            // The cards those passes held are free again.
            $this->connection->execute(
                "UPDATE `rfid_cards` c
                    SET c.`status` = 'available'
                  WHERE c.`status` = 'issued'
                    AND NOT EXISTS (
                        SELECT 1 FROM `visitor_logs` l
                         WHERE l.`rfid_card_id` = c.`rfid_card_id`
                           AND l.`status` IN ('issued', 'inside')
                    )"
            );

            $this->audit->record('visitors', 'expired', sprintf(
                '%d visitor pass(es) expired and their cards were returned to the pool.',
                $expired
            ));
        }

        return $expired;
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertCardAvailable(int $cardId): void
    {
        $card = $this->cards->find($cardId);

        if ($card === null) {
            throw NotFoundException::record('Visitor card', $cardId);
        }

        if ((string) $card['status'] !== 'available') {
            throw BusinessRuleException::withCode(
                'CARD_NOT_AVAILABLE',
                sprintf('Card %s is currently %s.', (string) $card['card_code'], (string) $card['status'])
            );
        }
    }

    private function nextVisitorCode(): string
    {
        $highest = (string) $this->connection->scalar(
            "SELECT `visitor_code` FROM `visitors`
              WHERE `visitor_code` LIKE 'VIS-%'
              ORDER BY LENGTH(`visitor_code`) DESC, `visitor_code` DESC
              LIMIT 1"
        );

        $sequence = $highest === '' ? 0 : (int) substr($highest, 4);

        return sprintf('VIS-%04d', $sequence + 1);
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        return [
            'registered'   => $this->visitors->countActive(),
            'inside'       => $this->passes->countInside(),
            'pass_states'  => $this->passes->statusCounts(),
            'cards'        => $this->cards->statusCounts(),
            'issued_today' => $this->passes->countIssuedBetween(
                now()->format('Y-m-d 00:00:00'),
                now()->format('Y-m-d 23:59:59')
            ),
        ];
    }
}
