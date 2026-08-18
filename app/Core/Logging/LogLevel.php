<?php

declare(strict_types=1);

namespace App\Core\Logging;

/**
 * Severity levels, ordered from least to most severe (RFC 5424 subset).
 *
 * @package App\Core\Logging
 * @version 1.0.0
 */
enum LogLevel: string
{
    case Debug     = 'debug';
    case Info      = 'info';
    case Notice    = 'notice';
    case Warning   = 'warning';
    case Error     = 'error';
    case Critical  = 'critical';
    case Alert     = 'alert';
    case Emergency = 'emergency';

    /**
     * Numeric weight used to compare severities.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Debug     => 100,
            self::Info      => 200,
            self::Notice    => 250,
            self::Warning   => 300,
            self::Error     => 400,
            self::Critical  => 500,
            self::Alert     => 550,
            self::Emergency => 600,
        };
    }

    /**
     * Whether this level should be written given a minimum threshold.
     */
    public function passes(self $threshold): bool
    {
        return $this->weight() >= $threshold->weight();
    }

    /**
     * Bootstrap-safe parse: an unrecognised name falls back to Info rather
     * than throwing, so a typo in .env cannot take the application down.
     */
    public static function parse(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Info;
    }

    /**
     * Bootstrap colour used when the level is rendered as a badge.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Debug            => 'secondary',
            self::Info             => 'info',
            self::Notice           => 'primary',
            self::Warning          => 'warning',
            self::Error            => 'danger',
            self::Critical,
            self::Alert,
            self::Emergency        => 'dark',
        };
    }
}
