<?php

namespace CronRunner\Exceptions;

/**
 * Mutex storage failed, e.g. its files can't be written.
 */
final class MutexException extends \RuntimeException implements CronRunnerException
{
}
