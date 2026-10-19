<?php

namespace App\Pool\Config;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The validated shape of config/pool_rules.php: tier windows and status levels.
 */
final class RulesConfig
{
    private const STATUS_LEVEL_COUNT = 3;

    /**
     * @param  array<string, array{window_hours: int}>  $tiers
     * @param  list<array{name: string, min_units: int}>  $statusLevels
     */
    private function __construct(
        private readonly array $tiers,
        private readonly array $statusLevels,
        private readonly float $windowScale = 1.0,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidRulesConfig naming the first wrong key
     */
    public static function fromArray(array $config): self
    {
        return new self(
            self::validatedTiers($config['tiers'] ?? null),
            self::validatedStatusLevels($config['status_levels'] ?? null),
            self::validatedWindowScale($config['window_scale'] ?? 1),
        );
    }

    public function windowHours(string $tier): int
    {
        return $this->tiers[$tier]['window_hours']
            ?? throw new InvalidArgumentException("Unknown tier [{$tier}].");
    }

    /**
     * The tier's window in whole seconds, after the demo scale (FR5.1, FR14).
     */
    public function windowSeconds(string $tier): int
    {
        return (int) round($this->windowHours($tier) * 3600 * $this->windowScale);
    }

    /**
     * When a temporary allocation logged at $effectiveAt under $tier returns its units.
     * Adds elapsed seconds, not calendar days, so a daylight-saving change cannot move it.
     */
    public function expiresAt(string $tier, CarbonImmutable $effectiveAt): CarbonImmutable
    {
        return $effectiveAt->addSeconds($this->windowSeconds($tier));
    }

    /**
     * The highest status level whose min_units is reached; the threshold itself counts (FR4.1).
     */
    public function statusFor(int $heldUnits): string
    {
        $status = $this->statusLevels[0]['name'];

        foreach ($this->statusLevels as $level) {
            if ($level['min_units'] <= $heldUnits) {
                $status = $level['name'];
            }
        }

        return $status;
    }

    public function lowestStatus(): string
    {
        return $this->statusLevels[0]['name'];
    }

    /**
     * @return array<string, array{window_hours: int}>
     */
    public function tiers(): array
    {
        return $this->tiers;
    }

    /**
     * @return list<array{name: string, min_units: int}>
     */
    public function statusLevels(): array
    {
        return $this->statusLevels;
    }

    private static function validatedWindowScale(mixed $scale): float
    {
        if (! is_numeric($scale) || (float) $scale <= 0 || (float) $scale > 1) {
            throw new InvalidRulesConfig('window_scale', 'must be a number above 0 and at most 1.');
        }

        return (float) $scale;
    }

    /**
     * @return array<string, array{window_hours: int}>
     */
    private static function validatedTiers(mixed $tiers): array
    {
        if (! is_array($tiers) || $tiers === []) {
            throw new InvalidRulesConfig('tiers', 'at least one tier is required.');
        }

        $validated = [];

        foreach ($tiers as $name => $tier) {
            $hours = is_array($tier) ? ($tier['window_hours'] ?? null) : null;

            if (! is_int($hours) || $hours < 1) {
                throw new InvalidRulesConfig("tiers.{$name}.window_hours", 'must be a positive whole number of hours.');
            }

            $validated[(string) $name] = ['window_hours' => $hours];
        }

        return $validated;
    }

    /**
     * @return list<array{name: string, min_units: int}>
     */
    private static function validatedStatusLevels(mixed $levels): array
    {
        if (! is_array($levels) || ! array_is_list($levels) || count($levels) !== self::STATUS_LEVEL_COUNT) {
            throw new InvalidRulesConfig('status_levels', 'exactly '.self::STATUS_LEVEL_COUNT.' levels are required.');
        }

        $validated = [];

        foreach ($levels as $index => $level) {
            $name = is_array($level) ? ($level['name'] ?? null) : null;
            $minUnits = is_array($level) ? ($level['min_units'] ?? null) : null;

            if (! is_string($name) || $name === '') {
                throw new InvalidRulesConfig("status_levels.{$index}.name", 'must be a non-empty string.');
            }

            if (! is_int($minUnits) || $minUnits < 0) {
                throw new InvalidRulesConfig("status_levels.{$index}.min_units", 'must be a whole number of units, 0 or more.');
            }

            $validated[] = ['name' => $name, 'min_units' => $minUnits];
        }

        if ($validated[0]['min_units'] !== 0) {
            throw new InvalidRulesConfig('status_levels.0.min_units', 'the first level must start at 0.');
        }

        for ($index = 1; $index < self::STATUS_LEVEL_COUNT; $index++) {
            if ($validated[$index]['min_units'] <= $validated[$index - 1]['min_units']) {
                throw new InvalidRulesConfig("status_levels.{$index}.min_units", 'thresholds must be strictly ascending.');
            }
        }

        return $validated;
    }
}
