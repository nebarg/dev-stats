<?php

namespace App\Actions\Heartbeats;

/**
 * A single raw heartbeat as submitted by wakatime-cli. Wraps the decoded JSON
 * and coerces each loosely-typed field to the shape our columns expect, so the
 * storing logic never reaches into the raw array. Ingestion is deliberately
 * lenient: an unexpected type yields null rather than rejecting the heartbeat.
 */
readonly class RawHeartbeat
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private array $raw) {}

    public function value(string $key): mixed
    {
        return $this->raw[$key] ?? null;
    }

    public function string(string $key): ?string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function int(string $key): ?int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function float(string $key): ?float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    public function boolean(string $key): ?bool
    {
        return isset($this->raw[$key]) ? (bool) $this->raw[$key] : null;
    }

    /**
     * @return array<int, string>|null
     */
    public function stringList(string $key): ?array
    {
        $value = $this->raw[$key] ?? null;

        if (! is_array($value)) {
            return null;
        }

        $strings = array_values(array_filter($value, 'is_string'));

        return $strings === [] ? null : $strings;
    }
}
