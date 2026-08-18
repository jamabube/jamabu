<?php

declare(strict_types=1);

namespace App\Core\View;

use App\Exceptions\NotFoundException;
use Throwable;

/**
 * Plain-PHP template engine.
 *
 * Templates are ordinary PHP files rendered in an isolated scope. A template
 * declares its layout with `$this->layout(...)`, contributes named sections
 * with `$this->start(...)` / `$this->stop()`, and reuses partials with
 * `$this->include(...)` or `$this->component(...)`.
 *
 * There is no expression language: templates call `e()` to escape, which keeps
 * the escaping decision visible at the point of output rather than hidden
 * behind auto-escaping that a developer might disable.
 *
 * @package App\Core\View
 * @version 1.0.0
 */
class ViewEngine
{
    /** @var array<string,mixed> Data shared with every template. */
    private array $shared = [];

    /** @var array<string,string> Rendered section contents. */
    private array $sections = [];

    /** @var list<string> Stack of section names currently being captured. */
    private array $sectionStack = [];

    /** Layout requested by the template currently rendering. */
    private ?string $pendingLayout = null;

    /** @var array<string,mixed> Data passed to the pending layout. */
    private array $pendingLayoutData = [];

    /** @var list<string> Templates currently rendering, for loop detection. */
    private array $renderStack = [];

    public function __construct(private readonly string $viewPath)
    {
    }

    /**
     * Make a value available to every template.
     */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function shareMany(array $data): void
    {
        $this->shared = array_merge($this->shared, $data);
    }

    /**
     * Render a template and any layout it declares.
     *
     * @param string              $template Dot or slash separated path, e.g. "pages/vehicles/index".
     * @param array<string,mixed> $data
     *
     * @throws NotFoundException When the template file is missing.
     */
    public function render(string $template, array $data = []): string
    {
        $content = $this->renderFile($template, $data);

        // A template that declared a layout is wrapped by it. Layouts may
        // themselves declare a parent layout, so this loops until none remains.
        while ($this->pendingLayout !== null) {
            $layout = $this->pendingLayout;
            $layoutData = $this->pendingLayoutData;

            $this->pendingLayout     = null;
            $this->pendingLayoutData = [];

            $this->sections['content'] = $content;

            $content = $this->renderFile($layout, array_merge($data, $layoutData));
        }

        return $content;
    }

    /**
     * Render a single template file without layout resolution.
     *
     * @param array<string,mixed> $data
     *
     * @throws NotFoundException
     */
    private function renderFile(string $template, array $data): string
    {
        $path = $this->resolvePath($template);

        if (in_array($path, $this->renderStack, true)) {
            throw new \RuntimeException(sprintf('Recursive template include detected for "%s".', $template));
        }

        $this->renderStack[] = $path;
        $level = ob_get_level();

        ob_start();

        try {
            // Templates receive only the data they were given plus shared
            // values; they cannot reach into the caller's scope.
            (function (string $__path, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__path;
            })->call($this, $path, array_merge($this->shared, $data));

            $output = ob_get_clean();
        } catch (Throwable $e) {
            // Discard partial output so a broken template cannot emit half a page.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            array_pop($this->renderStack);

            throw $e;
        }

        array_pop($this->renderStack);

        return $output === false ? '' : $output;
    }

    /**
     * Declare the layout that wraps the current template.
     *
     * @param array<string,mixed> $data
     */
    public function layout(string $layout, array $data = []): void
    {
        $this->pendingLayout     = $layout;
        $this->pendingLayoutData = $data;
    }

    /**
     * Begin capturing a named section.
     */
    public function start(string $section): void
    {
        $this->sectionStack[] = $section;
        ob_start();
    }

    /**
     * Finish capturing the current section.
     */
    public function stop(): void
    {
        $section = array_pop($this->sectionStack);

        if ($section === null) {
            ob_end_clean();

            throw new \RuntimeException('View section stack underflow: stop() without a matching start().');
        }

        $content = ob_get_clean();

        // Repeated sections append, which lets a page add scripts on top of
        // whatever a parent template already contributed.
        $this->sections[$section] = ($this->sections[$section] ?? '') . ($content === false ? '' : $content);
    }

    /**
     * Emit a captured section, or a default when it was never defined.
     */
    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]) && trim($this->sections[$name]) !== '';
    }

    /**
     * Render a partial in place.
     *
     * @param array<string,mixed> $data
     */
    public function include(string $template, array $data = []): string
    {
        return $this->renderFile($template, $data);
    }

    /**
     * Render a reusable component from app/Views/components.
     *
     * @param array<string,mixed> $data
     */
    public function component(string $name, array $data = []): string
    {
        return $this->renderFile('components/' . $name, $data);
    }

    /**
     * Render a template only when it exists; used for optional module hooks.
     *
     * @param array<string,mixed> $data
     */
    public function includeIf(string $template, array $data = []): string
    {
        return $this->exists($template) ? $this->renderFile($template, $data) : '';
    }

    public function exists(string $template): bool
    {
        return is_file($this->pathFor($template));
    }

    /**
     * @throws NotFoundException
     */
    private function resolvePath(string $template): string
    {
        $path = $this->pathFor($template);

        if (!is_file($path)) {
            throw new NotFoundException(sprintf('View template "%s" does not exist.', $template));
        }

        return $path;
    }

    /**
     * Translate a template name into a filesystem path, rejecting traversal.
     */
    private function pathFor(string $template): string
    {
        $relative = str_replace(['.', '\\'], '/', $template);
        $relative = preg_replace('#/+#', '/', $relative) ?? $relative;

        if (str_contains($relative, '..')) {
            throw new \InvalidArgumentException('View names may not contain parent-directory segments.');
        }

        return rtrim($this->viewPath, '/\\') . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, trim($relative, '/')) . '.php';
    }

    /**
     * Reset per-render state. Called between requests in long-lived processes
     * and between test cases.
     */
    public function reset(): void
    {
        $this->sections          = [];
        $this->sectionStack      = [];
        $this->pendingLayout     = null;
        $this->pendingLayoutData = [];
        $this->renderStack       = [];
    }
}
