<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Application;
use App\Core\Console\Output;
use Tests\TestCase;
use Throwable;

/**
 * Discovers and runs the test suites.
 *
 * Deliberately small: the project has no Composer dependencies, so the suite
 * must run on a bare PHP installation — which is also what a XAMPP deployment
 * looks like when an administrator wants to verify an installation on site.
 *
 * @package Tests\Support
 * @version 1.0.0
 */
final class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private int $skipped = 0;

    /** @var list<array{suite:string,test:string,detail:string}> */
    private array $failures = [];

    public function __construct(
        private readonly Application $app,
        private readonly Output $output
    ) {
    }

    /**
     * Run every discovered suite, optionally filtered by name.
     *
     * @return int Process exit code.
     */
    public function run(?string $filter = null): int
    {
        $startedAt = microtime(true);
        $suites    = $this->discover();

        if ($filter !== null && $filter !== '') {
            $suites = array_values(array_filter(
                $suites,
                static fn (string $class): bool => stripos($class, $filter) !== false
            ));
        }

        if ($suites === []) {
            $this->output->warning('No test suites matched.');

            return 1;
        }

        foreach ($suites as $class) {
            $this->runSuite($class);
        }

        return $this->summarise(microtime(true) - $startedAt);
    }

    /**
     * @param class-string<TestCase> $class
     */
    private function runSuite(string $class): void
    {
        /** @var TestCase $suite */
        $suite = new $class($this->app);

        $this->output->line();
        $this->output->line($this->output->colour($this->shortName($class), 'bold', 'cyan')
            . $this->output->colour('  ' . $suite->description(), 'grey'));

        if (!$suite->canRun()) {
            $this->skipped++;
            $this->output->line('  ' . $this->output->colour('SKIP', 'yellow') . '  ' . $suite->skipReason());

            return;
        }

        // setUp and tearDown run around *each* test method, not once around the
        // whole class. Sharing fixture state between methods makes a suite pass
        // or fail depending on the order it happens to run in, which is exactly
        // the kind of flakiness a test suite exists to rule out.
        foreach ($suite->testMethods() as $method) {
            try {
                $suite->setUp();
            } catch (Throwable $e) {
                $suite->recordFailure($method . ' [setUp]', $e);

                continue;
            }

            try {
                $suite->{$method}();
            } catch (Throwable $e) {
                // A test that throws is a failure, not a crash of the run.
                $suite->recordFailure($method, $e);
            } finally {
                try {
                    $suite->tearDown();
                } catch (Throwable $e) {
                    $suite->recordFailure($method . ' [tearDown]', $e);
                }
            }
        }

        foreach ($suite->results() as $result) {
            if ($result['passed']) {
                $this->passed++;
                $this->output->line(sprintf(
                    '  %s  %s%s',
                    $this->output->colour('PASS', 'green'),
                    $result['name'],
                    $result['detail'] === '' ? '' : $this->output->colour('  (' . $result['detail'] . ')', 'grey')
                ));

                continue;
            }

            $this->failed++;
            $this->failures[] = [
                'suite'  => $this->shortName($class),
                'test'   => $result['name'],
                'detail' => $result['detail'],
            ];

            $this->output->line(sprintf(
                '  %s  %s%s',
                $this->output->colour('FAIL', 'red', 'bold'),
                $result['name'],
                $result['detail'] === '' ? '' : $this->output->colour('  ' . $result['detail'], 'red')
            ));
        }
    }

    /**
     * Print the summary and return the process exit code.
     */
    private function summarise(float $elapsed): int
    {
        $this->output->line();
        $this->output->line(str_repeat('-', 72));

        if ($this->failures !== []) {
            $this->output->line();
            $this->output->line($this->output->colour('Failures', 'red', 'bold'));

            foreach ($this->failures as $failure) {
                $this->output->line(sprintf(
                    '  %s :: %s%s',
                    $failure['suite'],
                    $failure['test'],
                    $failure['detail'] === '' ? '' : "\n      " . $failure['detail']
                ));
            }

            $this->output->line();
        }

        $summary = sprintf(
            '%d passed, %d failed, %d suite(s) skipped  —  %.2fs',
            $this->passed,
            $this->failed,
            $this->skipped,
            $elapsed
        );

        $this->failed === 0
            ? $this->output->success($summary)
            : $this->output->error($summary);

        return $this->failed === 0 ? 0 : 1;
    }

    /**
     * Find every TestCase subclass under tests/.
     *
     * @return list<class-string<TestCase>>
     */
    private function discover(): array
    {
        $root    = $this->app->basePath('tests');
        $classes = [];

        foreach (['Unit', 'Integration'] as $group) {
            $directory = $root . DIRECTORY_SEPARATOR . $group;

            if (!is_dir($directory)) {
                continue;
            }

            foreach (glob($directory . DIRECTORY_SEPARATOR . '*Test.php') ?: [] as $file) {
                $class = 'Tests\\' . $group . '\\' . basename($file, '.php');

                if (class_exists($class) && is_subclass_of($class, TestCase::class)) {
                    $classes[] = $class;
                }
            }
        }

        sort($classes);

        return $classes;
    }

    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
