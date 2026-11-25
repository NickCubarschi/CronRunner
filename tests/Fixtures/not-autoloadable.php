<?php

namespace Fixtures;

/**
 * Class, which Composer can't find, like one declared in Configuration.php. Loaded only by require.
 */
final class NotAutoloadable
{
    /**
     * Closure bound to this object.
     */
    public function job(): \Closure
    {
        return function (): void {
        };
    }

    /**
     * Closure scoped to this class, without $this.
     */
    public static function staticJob(): \Closure
    {
        return static function (): void {
        };
    }
}
