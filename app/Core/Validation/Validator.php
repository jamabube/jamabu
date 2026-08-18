<?php

declare(strict_types=1);

namespace App\Core\Validation;

use App\Core\Database\Connection;
use App\Core\Support\Str;
use App\Exceptions\ValidationException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Request validator.
 *
 * Rules are declared as "field => 'rule|rule:argument'". Validation always
 * runs before any business logic, and a failure raises a ValidationException
 * that the HTTP layer turns into a 422 with a field => messages map.
 *
 * Unknown rule names raise immediately rather than being ignored: a typo in a
 * rule must never silently disable a check.
 *
 * @package App\Core\Validation
 * @version 1.0.0
 */
class Validator
{
    /** @var array<string,mixed> */
    private array $data;

    /** @var array<string,list<string>> */
    private array $errors = [];

    /** @var array<string,string> Human-readable field names for messages. */
    private array $labels = [];

    /** @var array<string,string> Caller-supplied message overrides ("field.rule"). */
    private array $messages = [];

    /** @var array<string,mixed> Values that passed validation. */
    private array $validated = [];

    public function __construct(private readonly ?Connection $connection = null)
    {
    }

    /**
     * Validate a data set, throwing on the first complete failure pass.
     *
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     * @param array<string,string> $messages
     *
     * @return array<string,mixed> The validated subset of the input.
     *
     * @throws ValidationException
     */
    public function validate(array $data, array $rules, array $labels = [], array $messages = []): array
    {
        if (!$this->passes($data, $rules, $labels, $messages)) {
            throw new ValidationException($this->errors);
        }

        return $this->validated;
    }

    /**
     * Run validation and report success without throwing.
     *
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     * @param array<string,string> $messages
     */
    public function passes(array $data, array $rules, array $labels = [], array $messages = []): bool
    {
        $this->data      = $data;
        $this->errors    = [];
        $this->validated = [];
        $this->labels    = $labels;
        $this->messages  = $messages;

        foreach ($rules as $field => $ruleString) {
            $this->validateField($field, $this->parseRules($ruleString));
        }

        return $this->errors === [];
    }

    /**
     * @return array<string,list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string,mixed>
     */
    public function validated(): array
    {
        return $this->validated;
    }

    /**
     * Split "required|max:100|in:a,b" into [[name, [args]], ...].
     *
     * @return list<array{0:string,1:list<string>}>
     */
    private function parseRules(string $ruleString): array
    {
        $parsed = [];

        foreach (explode('|', $ruleString) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }

            if (!str_contains($rule, ':')) {
                $parsed[] = [$rule, []];
                continue;
            }

            [$name, $argumentString] = explode(':', $rule, 2);
            // "regex" arguments may legitimately contain commas, so they are
            // never split.
            $arguments = $name === 'regex' ? [$argumentString] : explode(',', $argumentString);

            $parsed[] = [trim($name), array_map('trim', $arguments)];
        }

        return $parsed;
    }

    /**
     * @param list<array{0:string,1:list<string>}> $rules
     */
    private function validateField(string $field, array $rules): void
    {
        $value    = $this->value($field);
        $names    = array_column($rules, 0);
        $required = in_array('required', $names, true);
        $nullable = in_array('nullable', $names, true);
        $present  = $this->isPresent($value);

        if (!$present) {
            if ($required) {
                $this->addError($field, 'required', sprintf('%s is required.', $this->label($field)));

                return;
            }

            // An absent optional field records its null and skips every other
            // rule: "max:50" has nothing to measure.
            if ($nullable || !$this->hasKey($field)) {
                if ($this->hasKey($field) || $nullable) {
                    $this->validated[$field] = null;
                }

                return;
            }

            $this->validated[$field] = $value;

            return;
        }

        $failed = false;

        foreach ($rules as [$rule, $arguments]) {
            if (in_array($rule, ['required', 'nullable', 'sometimes'], true)) {
                continue;
            }

            if (!$this->applyRule($field, $value, $rule, $arguments)) {
                $failed = true;
                // Stop at the first failure per field so the user sees one
                // actionable message rather than a cascade of consequences.
                break;
            }
        }

        if (!$failed) {
            $this->validated[$field] = $value;
        }
    }

    /**
     * Dispatch one rule. Returns false when the value failed.
     *
     * @param list<string> $arguments
     */
    private function applyRule(string $field, mixed $value, string $rule, array $arguments): bool
    {
        $label = $this->label($field);

        return match ($rule) {
            'string'   => $this->assert($field, $rule, is_string($value), sprintf('%s must be text.', $label)),
            'integer'  => $this->assert($field, $rule, is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1), sprintf('%s must be a whole number.', $label)),
            'numeric'  => $this->assert($field, $rule, is_numeric($value), sprintf('%s must be a number.', $label)),
            'boolean'  => $this->assert($field, $rule, is_bool($value) || in_array((string) (is_scalar($value) ? $value : ''), ['0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'], true), sprintf('%s must be true or false.', $label)),
            'array'    => $this->assert($field, $rule, is_array($value), sprintf('%s must be a list.', $label)),
            'email'    => $this->assert($field, $rule, is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false, sprintf('%s must be a valid email address.', $label)),
            'url'      => $this->assert($field, $rule, is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false, sprintf('%s must be a valid URL.', $label)),
            'ip'       => $this->assert($field, $rule, is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false, sprintf('%s must be a valid IP address.', $label)),
            'mac'      => $this->assert($field, $rule, is_string($value) && preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $value) === 1, sprintf('%s must be a valid MAC address.', $label)),
            'hex'      => $this->assert($field, $rule, is_string($value) && ctype_xdigit($value), sprintf('%s must be hexadecimal.', $label)),
            'alpha'    => $this->assert($field, $rule, is_string($value) && preg_match('/^[\pL]+$/u', $value) === 1, sprintf('%s may only contain letters.', $label)),
            'alpha_num'=> $this->assert($field, $rule, is_string($value) && preg_match('/^[\pL\pN]+$/u', $value) === 1, sprintf('%s may only contain letters and numbers.', $label)),
            'alpha_dash' => $this->assert($field, $rule, is_string($value) && preg_match('/^[\pL\pN_-]+$/u', $value) === 1, sprintf('%s may only contain letters, numbers, dashes and underscores.', $label)),
            'alpha_space' => $this->assert($field, $rule, is_string($value) && preg_match("/^[\pL\pN .,'\-]+$/u", $value) === 1, sprintf('%s contains characters that are not permitted.', $label)),
            'slug'     => $this->assert($field, $rule, is_string($value) && preg_match('/^[a-z0-9\-]+$/', $value) === 1, sprintf('%s may only contain lower-case letters, numbers and dashes.', $label)),
            'json'     => $this->assert($field, $rule, is_string($value) && self::isValidJson($value), sprintf('%s must be valid JSON.', $label)),
            'digits'   => $this->validateDigits($field, $value, $arguments),
            'min'      => $this->validateMin($field, $value, $arguments),
            'max'      => $this->validateMax($field, $value, $arguments),
            'between'  => $this->validateBetween($field, $value, $arguments),
            'size'     => $this->validateSize($field, $value, $arguments),
            'in'       => $this->assert($field, $rule, in_array((string) (is_scalar($value) ? $value : ''), $arguments, true), sprintf('%s must be one of: %s.', $label, implode(', ', $arguments))),
            'not_in'   => $this->assert($field, $rule, !in_array((string) (is_scalar($value) ? $value : ''), $arguments, true), sprintf('%s contains a value that is not permitted.', $label)),
            'regex'    => $this->assert($field, $rule, is_string($value) && @preg_match($arguments[0] ?? '//', $value) === 1, sprintf('%s has an invalid format.', $label)),
            'date'     => $this->validateDate($field, $value),
            'date_format' => $this->validateDateFormat($field, $value, $arguments),
            'before'   => $this->validateDateComparison($field, $value, $arguments, '<'),
            'after'    => $this->validateDateComparison($field, $value, $arguments, '>'),
            'after_or_equal' => $this->validateDateComparison($field, $value, $arguments, '>='),
            'confirmed'=> $this->assert($field, $rule, $value === $this->value($field . '_confirmation'), sprintf('%s does not match the confirmation.', $label)),
            'same'     => $this->assert($field, $rule, $value === $this->value($arguments[0] ?? ''), sprintf('%s must match %s.', $label, $this->label($arguments[0] ?? ''))),
            'different'=> $this->assert($field, $rule, $value !== $this->value($arguments[0] ?? ''), sprintf('%s must be different from %s.', $label, $this->label($arguments[0] ?? ''))),
            'unique'   => $this->validateUnique($field, $value, $arguments),
            'exists'   => $this->validateExists($field, $value, $arguments),
            'plate'    => $this->assert($field, $rule, is_string($value) && preg_match('/^[A-Z0-9][A-Z0-9 \-]{2,14}$/', Str::normalisePlate($value)) === 1, sprintf('%s is not a valid plate number.', $label)),
            'rfid_uid' => $this->assert($field, $rule, is_string($value) && preg_match('/^[0-9A-F]{8,32}$/', Str::normaliseUid($value)) === 1, sprintf('%s must be a hexadecimal RFID UID of 8 to 32 characters.', $label)),
            'phone'    => $this->assert($field, $rule, is_string($value) && preg_match('/^[0-9+\-() ]{7,20}$/', $value) === 1, sprintf('%s is not a valid contact number.', $label)),
            'timezone' => $this->assert($field, $rule, is_string($value) && in_array($value, timezone_identifiers_list(), true), sprintf('%s is not a recognised timezone.', $label)),
            'cron'     => $this->assert($field, $rule, is_string($value) && preg_match('/^(\S+\s+){4}\S+$/', trim($value)) === 1, sprintf('%s must be a five-field cron expression.', $label)),
            default    => throw new InvalidArgumentException(sprintf('Unknown validation rule "%s".', $rule)),
        };
    }

    // ------------------------------------------------------------------
    // Individual rule implementations
    // ------------------------------------------------------------------

    /**
     * @param list<string> $arguments
     */
    private function validateMin(string $field, mixed $value, array $arguments): bool
    {
        $minimum = (float) ($arguments[0] ?? 0);
        $label   = $this->label($field);

        if (is_numeric($value) && !is_string($value)) {
            return $this->assert($field, 'min', (float) $value >= $minimum, sprintf('%s must be at least %s.', $label, $arguments[0] ?? '0'));
        }

        if (is_array($value)) {
            return $this->assert($field, 'min', count($value) >= $minimum, sprintf('%s must contain at least %s item(s).', $label, $arguments[0] ?? '0'));
        }

        return $this->assert(
            $field,
            'min',
            mb_strlen((string) (is_scalar($value) ? $value : ''), 'UTF-8') >= $minimum,
            sprintf('%s must be at least %s characters.', $label, $arguments[0] ?? '0')
        );
    }

    /**
     * @param list<string> $arguments
     */
    private function validateMax(string $field, mixed $value, array $arguments): bool
    {
        $maximum = (float) ($arguments[0] ?? 0);
        $label   = $this->label($field);

        if (is_numeric($value) && !is_string($value)) {
            return $this->assert($field, 'max', (float) $value <= $maximum, sprintf('%s may not exceed %s.', $label, $arguments[0] ?? '0'));
        }

        if (is_array($value)) {
            return $this->assert($field, 'max', count($value) <= $maximum, sprintf('%s may not contain more than %s item(s).', $label, $arguments[0] ?? '0'));
        }

        return $this->assert(
            $field,
            'max',
            mb_strlen((string) (is_scalar($value) ? $value : ''), 'UTF-8') <= $maximum,
            sprintf('%s may not be longer than %s characters.', $label, $arguments[0] ?? '0')
        );
    }

    /**
     * @param list<string> $arguments
     */
    private function validateBetween(string $field, mixed $value, array $arguments): bool
    {
        $minimum = (float) ($arguments[0] ?? 0);
        $maximum = (float) ($arguments[1] ?? 0);

        $measure = is_numeric($value) && !is_string($value)
            ? (float) $value
            : (is_array($value) ? count($value) : mb_strlen((string) (is_scalar($value) ? $value : ''), 'UTF-8'));

        return $this->assert(
            $field,
            'between',
            $measure >= $minimum && $measure <= $maximum,
            sprintf('%s must be between %s and %s.', $this->label($field), $arguments[0] ?? '0', $arguments[1] ?? '0')
        );
    }

    /**
     * @param list<string> $arguments
     */
    private function validateSize(string $field, mixed $value, array $arguments): bool
    {
        $expected = (int) ($arguments[0] ?? 0);
        $measure  = is_array($value) ? count($value) : mb_strlen((string) (is_scalar($value) ? $value : ''), 'UTF-8');

        return $this->assert(
            $field,
            'size',
            $measure === $expected,
            sprintf('%s must be exactly %d characters.', $this->label($field), $expected)
        );
    }

    /**
     * @param list<string> $arguments
     */
    private function validateDigits(string $field, mixed $value, array $arguments): bool
    {
        $expected = (int) ($arguments[0] ?? 0);
        $string   = (string) (is_scalar($value) ? $value : '');

        return $this->assert(
            $field,
            'digits',
            ctype_digit($string) && strlen($string) === $expected,
            sprintf('%s must be exactly %d digits.', $this->label($field), $expected)
        );
    }

    private function validateDate(string $field, mixed $value): bool
    {
        $valid = false;

        if (is_string($value) && $value !== '') {
            $timestamp = strtotime($value);
            $valid = $timestamp !== false;
        }

        return $this->assert($field, 'date', $valid, sprintf('%s must be a valid date.', $this->label($field)));
    }

    /**
     * @param list<string> $arguments
     */
    private function validateDateFormat(string $field, mixed $value, array $arguments): bool
    {
        $format = $arguments[0] ?? 'Y-m-d';
        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat($format, $value) : false;

        // createFromFormat succeeds on partially-matching input, so the result
        // is re-formatted and compared to reject "2026-13-45".
        $valid = $parsed !== false && $parsed->format($format) === $value;

        return $this->assert(
            $field,
            'date_format',
            $valid,
            sprintf('%s must match the format %s.', $this->label($field), $format)
        );
    }

    /**
     * Compare a date against a literal date or another field's value.
     *
     * @param list<string> $arguments
     */
    private function validateDateComparison(string $field, mixed $value, array $arguments, string $operator): bool
    {
        $reference = $arguments[0] ?? 'now';

        // The argument may name another field in the same payload.
        $other = $this->hasKey($reference) ? $this->value($reference) : $reference;

        $left  = is_string($value) ? strtotime($value) : false;
        $right = is_string($other) ? strtotime($other) : false;

        if ($left === false || $right === false) {
            return $this->assert($field, 'date', false, sprintf('%s must be a valid date.', $this->label($field)));
        }

        $passes = match ($operator) {
            '<'  => $left < $right,
            '>'  => $left > $right,
            '>=' => $left >= $right,
            default => false,
        };

        $wording = match ($operator) {
            '<'  => 'before',
            '>'  => 'after',
            '>=' => 'on or after',
            default => 'compared with',
        };

        return $this->assert(
            $field,
            'date_comparison',
            $passes,
            sprintf('%s must be %s %s.', $this->label($field), $wording, $this->label($reference))
        );
    }

    /**
     * unique:table,column[,exceptId[,exceptColumn]]
     *
     * @param list<string> $arguments
     */
    private function validateUnique(string $field, mixed $value, array $arguments): bool
    {
        if ($this->connection === null) {
            throw new InvalidArgumentException('The "unique" rule requires a database connection.');
        }

        $table  = $this->assertIdentifier($arguments[0] ?? '');
        $column = $this->assertIdentifier($arguments[1] ?? $field);
        $except = $arguments[2] ?? null;
        $exceptColumn = $this->assertIdentifier($arguments[3] ?? 'id');

        $sql      = sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = ?', $table, $column);
        $bindings = [$value];

        if ($except !== null && $except !== '' && $except !== '0') {
            $sql .= sprintf(' AND `%s` <> ?', $exceptColumn);
            $bindings[] = $except;
        }

        $count = (int) $this->connection->scalar($sql, $bindings);

        return $this->assert(
            $field,
            'unique',
            $count === 0,
            sprintf('%s is already in use.', $this->label($field))
        );
    }

    /**
     * exists:table,column
     *
     * @param list<string> $arguments
     */
    private function validateExists(string $field, mixed $value, array $arguments): bool
    {
        if ($this->connection === null) {
            throw new InvalidArgumentException('The "exists" rule requires a database connection.');
        }

        $table  = $this->assertIdentifier($arguments[0] ?? '');
        $column = $this->assertIdentifier($arguments[1] ?? 'id');

        $count = (int) $this->connection->scalar(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = ?', $table, $column),
            [$value]
        );

        return $this->assert(
            $field,
            'exists',
            $count > 0,
            sprintf('The selected %s does not exist.', strtolower($this->label($field)))
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Record a failure when the condition is false.
     */
    private function assert(string $field, string $rule, bool $condition, string $message): bool
    {
        if ($condition) {
            return true;
        }

        $this->addError($field, $rule, $message);

        return false;
    }

    private function addError(string $field, string $rule, string $message): void
    {
        $this->errors[$field][] = $this->messages[$field . '.' . $rule] ?? $this->messages[$field] ?? $message;
    }

    /**
     * Whether a string parses as JSON.
     *
     * json_validate() only exists from PHP 8.3; the decode fallback keeps the
     * rule working on the 8.2 baseline the specification allows.
     */
    private static function isValidJson(string $value): bool
    {
        if (function_exists('json_validate')) {
            return json_validate($value);
        }

        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private function value(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    private function hasKey(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    /**
     * A value counts as present unless it is null, an empty string, or an
     * empty array. Zero and "0" are present.
     */
    private function isPresent(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? Str::title($field);
    }

    /**
     * Guard identifiers used by the unique/exists rules, which interpolate a
     * table and column name into SQL.
     */
    private function assertIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf('Unsafe identifier "%s" in a validation rule.', $identifier));
        }

        return $identifier;
    }
}
