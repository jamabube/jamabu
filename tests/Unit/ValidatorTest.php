<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validation\Validator;
use App\Exceptions\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Verifies the validation rules, including the behaviours that are easy to get
 * subtly wrong: an absent optional field, "0" counting as present, and an
 * unknown rule name failing loudly rather than silently passing.
 *
 * @package Tests\Unit
 * @version 1.0.0
 */
final class ValidatorTest extends TestCase
{
    public function description(): string
    {
        return 'Input validation rules';
    }

    private function validator(): Validator
    {
        return new Validator();
    }

    public function testRequiredFields(): void
    {
        $validator = $this->validator();

        $this->assertFalse(
            $validator->passes([], ['username' => 'required']),
            'a missing required field fails'
        );

        $this->assertFalse(
            $this->validator()->passes(['username' => ''], ['username' => 'required']),
            'an empty required field fails'
        );

        // "0" is a legitimate value and must not be mistaken for absence.
        $this->assertTrue(
            $this->validator()->passes(['count' => '0'], ['count' => 'required|integer']),
            'the string "0" counts as present'
        );
    }

    public function testOptionalFieldsAreSkipped(): void
    {
        $validator = $this->validator();

        $this->assertTrue(
            $validator->passes([], ['nickname' => 'nullable|string|max:5']),
            'an absent optional field skips its other rules'
        );

        $this->assertTrue(
            $this->validator()->passes(['nickname' => null], ['nickname' => 'nullable|max:5']),
            'an explicit null passes a nullable rule'
        );
    }

    public function testFormatRules(): void
    {
        $this->assertTrue($this->validator()->passes(['e' => 'guard@forestlawn.local'], ['e' => 'email']), 'a valid email passes');
        $this->assertFalse($this->validator()->passes(['e' => 'not-an-email'], ['e' => 'email']), 'an invalid email fails');
        $this->assertTrue($this->validator()->passes(['m' => '5C:CF:7F:1A:2B:01'], ['m' => 'mac']), 'a valid MAC passes');
        $this->assertFalse($this->validator()->passes(['m' => 'ZZ:CF:7F:1A:2B:01'], ['m' => 'mac']), 'an invalid MAC fails');
        $this->assertTrue($this->validator()->passes(['u' => 'A0000000'], ['u' => 'rfid_uid']), 'a valid RFID UID passes');
        $this->assertFalse($this->validator()->passes(['u' => 'XYZ'], ['u' => 'rfid_uid']), 'a non-hexadecimal UID fails');
        $this->assertTrue($this->validator()->passes(['p' => 'ABC 1234'], ['p' => 'plate']), 'a valid plate passes');
        $this->assertTrue($this->validator()->passes(['i' => '192.168.10.5'], ['i' => 'ip']), 'a valid IP passes');
        $this->assertTrue($this->validator()->passes(['c' => '0 2 * * *'], ['c' => 'cron']), 'a five-field cron expression passes');
        $this->assertFalse($this->validator()->passes(['c' => 'every day'], ['c' => 'cron']), 'free text fails the cron rule');
    }

    public function testLengthAndRangeRules(): void
    {
        $this->assertFalse($this->validator()->passes(['v' => 'ab'], ['v' => 'min:3']), 'a short string fails min');
        $this->assertTrue($this->validator()->passes(['v' => 'abc'], ['v' => 'min:3']), 'an exact-length string passes min');
        $this->assertFalse($this->validator()->passes(['v' => 'abcdef'], ['v' => 'max:3']), 'a long string fails max');
        $this->assertTrue($this->validator()->passes(['v' => 25], ['v' => 'integer|between:10,50']), 'an in-range number passes');
        $this->assertFalse($this->validator()->passes(['v' => 5], ['v' => 'integer|between:10,50']), 'an out-of-range number fails');
        $this->assertTrue($this->validator()->passes(['v' => '12345'], ['v' => 'digits:5']), 'a five-digit string passes');
        $this->assertFalse($this->validator()->passes(['v' => '1234'], ['v' => 'digits:5']), 'a four-digit string fails digits:5');
    }

    public function testDateRules(): void
    {
        $this->assertTrue($this->validator()->passes(['d' => '2026-08-19'], ['d' => 'date_format:Y-m-d']), 'a well-formed date passes');
        // createFromFormat is lenient, so the rule re-formats and compares.
        $this->assertFalse($this->validator()->passes(['d' => '2026-13-45'], ['d' => 'date_format:Y-m-d']), 'an impossible date fails');
        $this->assertTrue(
            $this->validator()->passes(['from' => '2026-01-01', 'to' => '2026-02-01'], ['to' => 'after:from']),
            'a later date passes the after rule'
        );
        $this->assertFalse(
            $this->validator()->passes(['from' => '2026-03-01', 'to' => '2026-02-01'], ['to' => 'after:from']),
            'an earlier date fails the after rule'
        );
    }

    public function testConfirmationAndEnumeration(): void
    {
        $this->assertTrue(
            $this->validator()->passes(['password' => 'abc', 'password_confirmation' => 'abc'], ['password' => 'confirmed']),
            'a matching confirmation passes'
        );

        $this->assertFalse(
            $this->validator()->passes(['password' => 'abc', 'password_confirmation' => 'xyz'], ['password' => 'confirmed']),
            'a mismatched confirmation fails'
        );

        $this->assertTrue($this->validator()->passes(['s' => 'active'], ['s' => 'in:active,inactive']), 'a listed value passes');
        $this->assertFalse($this->validator()->passes(['s' => 'deleted'], ['s' => 'in:active,inactive']), 'an unlisted value fails');
    }

    public function testValidateReturnsOnlyTheValidatedSubset(): void
    {
        $validated = $this->validator()->validate(
            ['username' => 'guard1', 'email' => 'g@example.local', 'injected' => 'not-in-rules'],
            ['username' => 'required|string', 'email' => 'required|email']
        );

        $this->assertCount(2, $validated, 'only the declared fields are returned');
        $this->assertFalse(array_key_exists('injected', $validated), 'an undeclared field is discarded');
    }

    public function testFailureThrowsWithFieldMessages(): void
    {
        $this->assertThrows(
            fn () => $this->validator()->validate(['email' => 'bad'], ['email' => 'required|email']),
            'validation failure throws',
            ValidationException::class
        );

        try {
            $this->validator()->validate(['email' => 'bad'], ['email' => 'required|email']);
        } catch (ValidationException $e) {
            $this->assertTrue(array_key_exists('email', $e->errors()), 'the failure is keyed by field');
            $this->assertSame(422, $e->statusCode(), 'a validation failure maps to HTTP 422');
        }
    }

    public function testUnknownRuleFailsLoudly(): void
    {
        // A typo in a rule name must never silently disable the check.
        $this->assertThrows(
            fn () => $this->validator()->passes(['v' => 'x'], ['v' => 'requried']),
            'an unknown rule name raises',
            InvalidArgumentException::class
        );
    }

    public function testCustomLabelsAndMessages(): void
    {
        try {
            $this->validator()->validate(
                ['plate_number' => ''],
                ['plate_number' => 'required'],
                ['plate_number' => 'Plate number']
            );
        } catch (ValidationException $e) {
            $this->assertSame('Plate number is required.', $e->firstMessage(), 'the custom label appears in the message');
        }
    }
}
