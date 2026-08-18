<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Application;
use App\Core\Database\Connection;
use App\Services\SettingsService;
use Throwable;

/**
 * Base class for every test.
 *
 * Each public method whose name begins with "test" is executed as one case.
 * Assertions record a result rather than throwing, so a single failing
 * expectation does not hide the ones after it.
 *
 * @package Tests
 * @version 1.0.0
 */
abstract class TestCase
{
    /** @var list<array{name:string,passed:bool,detail:string}> */
    private array $results = [];

    protected Application $app;

    /** Whether this case needs a working database connection. */
    protected bool $requiresDatabase = false;

    /** Set when the suite must be skipped, with the reason. */
    private ?string $skipReason = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * A short description shown as the suite heading.
     */
    abstract public function description(): string;

    /**
     * Prepare the fixture. Runs before every test method in the class.
     */
    public function setUp(): void
    {
    }

    /**
     * Release what setUp() acquired. Runs after every test method.
     */
    public function tearDown(): void
    {
    }

    /**
     * Whether the suite can run in this environment.
     */
    public function canRun(): bool
    {
        if (!$this->requiresDatabase) {
            return true;
        }

        try {
            if (!$this->app->make(Connection::class)->isHealthy()) {
                $this->skipReason = 'no database connection';

                return false;
            }
        } catch (Throwable $e) {
            $this->skipReason = 'no database connection (' . $e->getMessage() . ')';

            return false;
        }

        // Runtime settings are overlaid so tests see the same configuration a
        // real request would.
        $this->app->make(SettingsService::class)->applyToConfiguration();

        return true;
    }

    public function skipReason(): string
    {
        return $this->skipReason ?? '';
    }

    // ------------------------------------------------------------------
    // Assertions
    // ------------------------------------------------------------------

    protected function assertTrue(bool $condition, string $name, string $detail = ''): void
    {
        $this->record($name, $condition, $detail);
    }

    protected function assertFalse(bool $condition, string $name, string $detail = ''): void
    {
        $this->record($name, !$condition, $detail);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $name): void
    {
        $this->record(
            $name,
            $expected === $actual,
            $expected === $actual ? '' : sprintf('expected %s, got %s', $this->describe($expected), $this->describe($actual))
        );
    }

    protected function assertNotSame(mixed $unexpected, mixed $actual, string $name): void
    {
        $this->record($name, $unexpected !== $actual, $unexpected !== $actual ? '' : 'values are identical');
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $name): void
    {
        $this->record(
            $name,
            $expected == $actual,
            $expected == $actual ? '' : sprintf('expected %s, got %s', $this->describe($expected), $this->describe($actual))
        );
    }

    protected function assertNull(mixed $value, string $name): void
    {
        $this->record($name, $value === null, $value === null ? '' : 'value is ' . $this->describe($value));
    }

    protected function assertNotNull(mixed $value, string $name): void
    {
        $this->record($name, $value !== null, $value !== null ? '' : 'value is null');
    }

    protected function assertGreaterThan(float|int $floor, float|int $actual, string $name): void
    {
        $this->record($name, $actual > $floor, $actual > $floor ? '' : sprintf('%s is not greater than %s', $actual, $floor));
    }

    protected function assertContains(mixed $needle, array $haystack, string $name): void
    {
        $found = in_array($needle, $haystack, true);
        $this->record($name, $found, $found ? '' : sprintf('%s is not present', $this->describe($needle)));
    }

    protected function assertCount(int $expected, array $actual, string $name): void
    {
        $this->record(
            $name,
            count($actual) === $expected,
            count($actual) === $expected ? '' : sprintf('expected %d item(s), got %d', $expected, count($actual))
        );
    }

    protected function assertMatches(string $pattern, string $subject, string $name): void
    {
        $matched = preg_match($pattern, $subject) === 1;
        $this->record($name, $matched, $matched ? '' : sprintf('"%s" does not match %s', $subject, $pattern));
    }

    /**
     * Assert that a callable throws, optionally of a given class.
     *
     * @param class-string<Throwable>|null $expectedClass
     */
    protected function assertThrows(callable $callback, string $name, ?string $expectedClass = null, ?string $expectedCode = null): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if ($expectedClass !== null && !$e instanceof $expectedClass) {
                $this->record($name, false, sprintf('threw %s, expected %s', $e::class, $expectedClass));

                return;
            }

            if ($expectedCode !== null) {
                $actualCode = method_exists($e, 'errorCode') ? (string) $e->errorCode() : '';

                $this->record(
                    $name,
                    $actualCode === $expectedCode,
                    $actualCode === $expectedCode ? $actualCode : sprintf('error code %s, expected %s', $actualCode, $expectedCode)
                );

                return;
            }

            $this->record($name, true, $e::class);

            return;
        }

        $this->record($name, false, 'no exception was thrown');
    }

    /**
     * Assert that a callable completes without throwing.
     */
    protected function assertDoesNotThrow(callable $callback, string $name): void
    {
        try {
            $callback();
            $this->record($name, true);
        } catch (Throwable $e) {
            $this->record($name, false, $e::class . ': ' . $e->getMessage());
        }
    }

    private function record(string $name, bool $passed, string $detail = ''): void
    {
        $this->results[] = ['name' => $name, 'passed' => $passed, 'detail' => $detail];
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            $value === null  => 'null',
            is_bool($value)  => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            is_array($value) => 'array(' . count($value) . ')',
            default          => get_debug_type($value),
        };
    }

    /**
     * @return list<array{name:string,passed:bool,detail:string}>
     */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * Every test method declared on the concrete class.
     *
     * @return list<string>
     */
    public function testMethods(): array
    {
        $methods = [];

        foreach (get_class_methods($this) as $method) {
            if (str_starts_with($method, 'test')) {
                $methods[] = $method;
            }
        }

        sort($methods);

        return $methods;
    }

    /**
     * Record that a test method itself blew up.
     */
    public function recordFailure(string $name, Throwable $e): void
    {
        $this->record($name, false, $e::class . ': ' . $e->getMessage());
    }
}
