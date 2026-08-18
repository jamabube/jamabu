<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Container;
use Throwable;

/**
 * Synchronous event dispatcher (observer pattern).
 *
 * Domain services raise events; listeners handle the side effects. This is
 * what keeps AccessMonitoringService free of notification and audit code: it
 * announces that a vehicle entered, and the listeners decide what that means.
 *
 * A listener failure never propagates: a notification that could not be
 * created must not roll back a monitoring record that was validly created.
 *
 * @package App\Core\Events
 * @version 1.0.0
 */
class EventDispatcher
{
    /** @var array<string,list<callable|class-string>> */
    private array $listeners = [];

    /** @var list<object> Events raised during this request, for diagnostics. */
    private array $dispatched = [];

    private bool $enabled = true;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Subscribe a listener to an event class.
     *
     * @param class-string              $event
     * @param callable|class-string     $listener Invokable class name or closure.
     */
    public function listen(string $event, callable|string $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * Register a map of event => listeners.
     *
     * @param array<class-string,list<callable|class-string>> $map
     */
    public function subscribe(array $map): void
    {
        foreach ($map as $event => $listeners) {
            foreach ($listeners as $listener) {
                $this->listen($event, $listener);
            }
        }
    }

    /**
     * Dispatch an event to every registered listener.
     */
    public function dispatch(object $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->dispatched[] = $event;

        foreach ($this->listenersFor($event::class) as $listener) {
            try {
                $this->invoke($listener, $event);
            } catch (Throwable $e) {
                // A broken observer is a defect to fix, not a reason to fail
                // the operation that succeeded.
                logger()->channel('application')->error('Event listener failed', [
                    'event'     => $event::class,
                    'listener'  => is_string($listener) ? $listener : 'closure',
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param callable|class-string $listener
     */
    private function invoke(callable|string $listener, object $event): void
    {
        if (is_string($listener)) {
            $instance = $this->container->make($listener);

            if (!is_callable($instance)) {
                if (!method_exists($instance, 'handle')) {
                    throw new \RuntimeException(sprintf(
                        'Listener "%s" must be invokable or define handle().',
                        $listener
                    ));
                }

                $instance->handle($event);

                return;
            }

            $instance($event);

            return;
        }

        $listener($event);
    }

    /**
     * Listeners registered for an event class and each of its parents, so a
     * listener may subscribe to a base event type.
     *
     * @param class-string $eventClass
     *
     * @return list<callable|class-string>
     */
    private function listenersFor(string $eventClass): array
    {
        $listeners = $this->listeners[$eventClass] ?? [];

        foreach (class_parents($eventClass) ?: [] as $parent) {
            $listeners = array_merge($listeners, $this->listeners[$parent] ?? []);
        }

        foreach (class_implements($eventClass) ?: [] as $interface) {
            $listeners = array_merge($listeners, $this->listeners[$interface] ?? []);
        }

        return $listeners;
    }

    /**
     * Suspend dispatching. Used by bulk import routines that would otherwise
     * generate one notification per imported row.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * @return list<object>
     */
    public function dispatchedEvents(): array
    {
        return $this->dispatched;
    }

    public function hasListeners(string $event): bool
    {
        return ($this->listeners[$event] ?? []) !== [];
    }
}
