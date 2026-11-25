<?php

namespace CronRunner\Tests\Integration;

use CronRunner\Tests\TestCase;

/**
 * Container start script, which writes the crontab and starts cron. Runs only inside the Docker image.
 */
final class StartCronTest extends TestCase
{
    private const SCRIPT = '/usr/local/bin/start-cron';

    private const CRONTAB = '/etc/cron.d/cron-runner';

    private const ENV_FILE = '/etc/cron-runner.env';

    private const OUTPUT_PIPE = '/run/cron-runner.pipe';

    private const JOB_USER = 'www-data';

    /**
     * File with the script's output, which also gets everything written to the output pipe.
     */
    private string $output;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_file(self::SCRIPT) || !is_writable(dirname(self::CRONTAB))) {
            self::markTestSkipped('Runs only in the Docker image, where the start script is installed.');
        }
    }

    /**
     * Run the start script with a fake cron, so it finishes instead of starting the daemon.
     *
     * @param  array<string, string>  $environment
     * @return list<string> Output of the script
     */
    private function start(array $environment): array
    {
        $bin = $this->temporaryDirectory();
        file_put_contents($bin . '/cron', "#!/bin/sh\necho \"cron \$*\"\n");
        chmod($bin . '/cron', 0755);

        $variables = '';
        foreach ($environment as $name => $value) {
            $variables .= $name . '=' . escapeshellarg($value) . ' ';
        }

        // Output goes to a file, as the script leaves the pipe copying running in the background.
        $this->output = $bin . '/output';
        exec(sprintf(
            'PATH=%s:$PATH %ssh %s > %s 2>&1',
            escapeshellarg($bin),
            $variables,
            escapeshellarg(self::SCRIPT),
            escapeshellarg($this->output),
        ), result_code: $exitCode);
        $output = file($this->output, FILE_IGNORE_NEW_LINES);
        self::assertNotFalse($output);
        self::assertSame(0, $exitCode, implode("\n", $output));

        return $output;
    }

    private static function owner(string $path): string
    {
        $uid = fileowner($path);
        self::assertNotFalse($uid);
        $user = posix_getpwuid($uid);
        self::assertNotFalse($user);

        return $user['name'];
    }

    public function testWritesCrontabAndStartsCron(): void
    {
        self::assertSame(['cron -f'], $this->start(['CRON_RUNNER_ADAPTER' => 'crunz']));

        $crontab = file_get_contents(self::CRONTAB);
        self::assertNotFalse($crontab);
        self::assertStringContainsString("PATH=/usr/local/bin:/usr/bin:/bin\n", $crontab);
        self::assertStringContainsString(
            '* * * * * ' . self::JOB_USER . ' . ' . self::ENV_FILE
                . ' && cd /app && php index.php > ' . self::OUTPUT_PIPE . " 2>&1\n",
            $crontab,
        );
        self::assertSame('644', substr(sprintf('%o', fileperms(self::CRONTAB)), -3));
    }

    public function testCronJobGetsWholeContainerEnvironment(): void
    {
        // Values with spaces, quotes and new lines must arrive unchanged and can't add lines to any file.
        $secret = "pa ss'wo\"rd\n* * * * * root rm -rf /";
        $this->start(['CRON_RUNNER_ADAPTER' => 'crunz', 'DATABASE_PASSWORD' => $secret]);

        // Cron runs jobs with an empty environment, the same as `env -i`.
        exec(sprintf(
            'env -i sh -c %s',
            escapeshellarg('. ' . self::ENV_FILE . ' && printf "%s|%s" "$CRON_RUNNER_ADAPTER" "$DATABASE_PASSWORD"'),
        ), $output, $exitCode);

        self::assertSame(0, $exitCode);
        self::assertSame('crunz|' . $secret, implode("\n", $output));
        $crontab = file_get_contents(self::CRONTAB);
        self::assertNotFalse($crontab);
        self::assertSame(2, substr_count($crontab, "\n"));
    }

    public function testEnvironmentFileIsReadableOnlyByJobUser(): void
    {
        $this->start(['CRON_RUNNER_ADAPTER' => 'laravel']);

        self::assertSame('600', substr(sprintf('%o', fileperms(self::ENV_FILE)), -3));
        self::assertSame(self::JOB_USER, self::owner(self::ENV_FILE));
    }

    public function testJobOutputPipeIsCopiedToContainerOutput(): void
    {
        $this->start(['CRON_RUNNER_ADAPTER' => 'laravel']);

        self::assertSame('fifo', filetype(self::OUTPUT_PIPE));
        self::assertSame('600', substr(sprintf('%o', fileperms(self::OUTPUT_PIPE)), -3));
        self::assertSame(self::JOB_USER, self::owner(self::OUTPUT_PIPE));

        // Two runs, as the copying must survive the end of the first one.
        file_put_contents(self::OUTPUT_PIPE, 'first run' . PHP_EOL);
        file_put_contents(self::OUTPUT_PIPE, 'second run' . PHP_EOL);

        $deadline = microtime(true) + 5;
        while (!str_contains((string) file_get_contents($this->output), 'second run') && microtime(true) < $deadline) {
            usleep(10_000);
        }

        self::assertSame(['cron -f', 'first run', 'second run'], file($this->output, FILE_IGNORE_NEW_LINES));
    }
}
