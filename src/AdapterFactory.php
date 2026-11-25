<?php

namespace CronRunner;

use CronRunner\Adapters\Runner\CrunzAdapter;
use CronRunner\Adapters\Runner\LaravelAdapter;
use CronRunner\Interfaces\Adapter;

final class AdapterFactory
{
    /**
     * Env variable, which picks the scheduler.
     */
    public const ENV_VARIABLE = 'CRON_RUNNER_ADAPTER';

    /**
     * Adapter used when the env variable isn't set.
     */
    public const DEFAULT_ADAPTER = 'laravel';

    /**
     * Create the adapter picked by the CRON_RUNNER_ADAPTER env variable: "laravel" (default) or "crunz".
     *
     * @param  string  $timezone  Timezone, in which cron expressions are evaluated
     */
    public static function fromEnvironment(string $timezone): Adapter
    {
        return self::create(getenv(self::ENV_VARIABLE) ?: self::DEFAULT_ADAPTER, $timezone);
    }

    /**
     * Create the adapter by its name, "laravel" or "crunz".
     */
    public static function create(string $name, string $timezone): Adapter
    {
        $timezone = new \DateTimeZone($timezone);

        return match (strtolower($name)) {
            'laravel' => new LaravelAdapter($timezone),
            'crunz' => new CrunzAdapter($timezone),
            default => throw new \InvalidArgumentException('Unknown ' . self::ENV_VARIABLE . ' "' . $name . '", use "laravel" or "crunz".'),
        };
    }
}
