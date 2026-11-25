<?php

namespace CronRunner;

use Composer\Autoload\ClassLoader;
use Cron\CronExpression;
use CronRunner\Interfaces\Mutex;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * Scheduler independent definition of a cron job. Adapters turn it into Laravel or Crunz events.
 */
final readonly class Job
{
    /**
     * @param  string  $name  Unique name of the job, also used as its mutex key
     * @param  \Closure  $callback  Anonymous function, which can be serialized (Crunz runs it in its own process)
     * @param  string  $cron  Cron expression
     * @param  \CronRunner\Interfaces\Mutex|null  $mutex  Prevents overlapping runs, if set
     */
    public function __construct(
        public string $name,
        public \Closure $callback,
        public string $cron = '* * * * *',
        public ?Mutex $mutex = null,
    ) {
        // Empty name would turn off Laravel's overlapping check and share a lock between jobs.
        if (trim($name) === '') {
            throw new \InvalidArgumentException("Job name can't be empty");
        }

        // Checked here, so a wrong expression fails while configuring, not when the scheduler runs. Both Laravel and Crunz parse it with this library.
        try {
            new CronExpression($cron);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException("Job \"{$name}\" has invalid cron expression \"{$cron}\": {$e->getMessage()}", 0, $e);
        }

        self::assertRunnableInOwnProcess($name, $callback);
    }

    /**
     * Copy of the job with another cron expression.
     */
    public function withCron(string $cron): self
    {
        return new self($this->name, $this->callback, $cron, $this->mutex);
    }

    /**
     * Copy of the job with the mutex.
     */
    public function withMutex(Mutex $mutex): self
    {
        return new self($this->name, $this->callback, $this->cron, $mutex);
    }

    /**
     * Crunz serializes the callback and runs it in a new process, so the same rules apply to every scheduler:
     * otherwise a job could work with Laravel and fail only after switching to Crunz.
     */
    private static function assertRunnableInOwnProcess(string $name, \Closure $callback): void
    {
        // Closures made from functions or methods (strlen(...), Closure::fromCallable()) keep the function's name and
        // can't be restored in another process; only anonymous functions are named "{closure...}".
        if (!str_starts_with((new \ReflectionFunction($callback))->getName(), '{closure')) {
            throw new \InvalidArgumentException("Job \"{$name}\" callback must be an anonymous function, e.g. function () { ... }, not a function or method converted to a closure");
        }

        // Variables captured by "use" are serialized too, so they must support it.
        try {
            serialize(new SerializableClosure($callback));
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException("Job \"{$name}\" callback can't be serialized: {$e->getMessage()}", 0, $e);
        }

        // The new process loads classes only through Composer, the same vendor/autoload.php as this one.
        $classes = [];
        $visited = [];
        self::collectClasses($callback, $classes, $visited);

        foreach (array_keys($classes) as $class) {
            if (!self::isAutoloadable($class)) {
                throw new \InvalidArgumentException(
                    "Job \"{$name}\" callback uses class {$class}, which Composer can't autoload,"
                    . ' so the job can\'t run in its own process',
                );
            }
        }
    }

    /**
     * Collect classes, which unserializing the value needs: closures' scope and $this, captured values and properties.
     *
     * @param  array<class-string, true>  $classes  Collected class names
     * @param  array<int, true>  $visited  Ids of walked objects, so reference cycles end
     */
    private static function collectClasses(mixed $value, array &$classes, array &$visited): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::collectClasses($item, $classes, $visited);
            }

            return;
        }

        if (!is_object($value) || isset($visited[spl_object_id($value)])) {
            return;
        }

        $visited[spl_object_id($value)] = true;

        if ($value instanceof \Closure) {
            $function = new \ReflectionFunction($value);
            $scope = $function->getClosureScopeClass();

            if ($scope !== null) {
                $classes[$scope->getName()] = true;
            }

            self::collectClasses($function->getClosureThis(), $classes, $visited);
            self::collectClasses($function->getClosureUsedVariables(), $classes, $visited);

            return;
        }

        $classes[$value::class] = true;
        self::collectClasses(get_mangled_object_vars($value), $classes, $visited);
    }

    /**
     * Whether the class is built into PHP or Composer's autoloader can find its file.
     *
     * @param  class-string  $class
     */
    private static function isAutoloadable(string $class): bool
    {
        if ((new \ReflectionClass($class))->isInternal()) {
            return true;
        }

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            if ($loader->findFile($class) !== false) {
                return true;
            }
        }

        return false;
    }
}
