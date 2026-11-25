# CronRunner

Runs cron jobs through the Laravel Scheduler or Crunz, switchable by the `CRON_RUNNER_ADAPTER` env variable.
Everything runs in Docker; only Docker and `make` are needed on the host.
Please read Notes/Comments below.

## Build

```sh
make build
```

## Run locally

```sh
make run                  # Laravel (default)
make run ADAPTER=crunz    # Crunz
```

Cron runs `index.php` every minute; jobs are configured in `src/Configuration.php`.

## Running tests

```sh
make test
```

## Format

```sh
make format               # fixes PSR-12 formatting in place
```

## Static analysis

```sh
make analyse              # PHPStan, level 8
```

## Update dependencies

```sh
make lock                 # updates composer.lock after changing composer.json
```

## File structure

```
index.php             entry point called by cron, returns exit codes from src/ExitCode.php
start-cron.sh         container start: writes the crontab and starts cron
src/
  Configuration.php          job definitions
  CronRunner.php             collects jobs and runs them through an adapter
  Job.php                    scheduler independent job definition
  AdapterFactory.php         picks the adapter by CRON_RUNNER_ADAPTER
  ExitCode.php               exit codes of index.php
  Interfaces/
    Adapter.php              runs jobs through one scheduler library
    Mutex.php                lock storage with owner tokens
  Adapters/
    Runner/
      LaravelAdapter.php     runs jobs through Laravel's scheduler
      CrunzAdapter.php       runs jobs through Crunz
    Mutex/
      LaravelMutexAdapter.php  Mutex for Laravel's EventMutex
      CrunzMutexAdapter.php    Mutex for Symfony's lock store, used by Crunz
  Mutex/
    FileMutex.php            Mutex kept in files
  Exceptions/
    CronRunnerException.php  common type for all exceptions
    MutexException.php       lock storage failure
    SchedulerException.php   scheduler failure
tests/
  Unit/               tests without processes or files
  Integration/        tests with real files, processes and both adapters
  Doubles/, Fixtures/ test helpers
```

# Notes/Comments

I made everything to run from Docker on purpose, so it's not dependent on local host for libraries.

Libraries `Cron\CronExpression` and `Laravel\SerializableClosure\SerializableClosure` are reused libraries, since they are already installed by at least Laravel or Crunz.
I left them wired for now, since those 2 are already installed, but for complete independence those could be wired through interface also.

Used Mutex can be switched only in code in `Configuration.php`, but if there is a need, it also should be rather configurable, same as adapter (Laravel vs Crunz).

In this case, tasks error outputs in terminal/logs are different, but I accepted it as difference between each cron solution. Could be next feature in this project, to unify the error outputs.

In `src/Adapters/Runner/LaravelAdapter.php`, Laravel container is set up with `isDownForMaintenance` always `false` and `environment` always `production`. This project is not meant to run in real production for now, since it's not even clear, where and how it would be deployed, for now only local usage is considered.

There are more differences between Crunz and Laravel Schedule (such as usage of TTL or running in different processes).
I left them for now for simplicity, also as possible reason, why we might want to switch between adapters.
Addressing everything would make project more complex,  which is the future decision if such solution is enough/sufficient
(or we want to go different route entirely).

