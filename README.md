# mj-zana/scheduler

Scheduled tasks for Zana applications: cron expressions, a container scope
per run, overlap locks, and `schedule:run`.

```
composer require mj-zana/scheduler
```

Register `SchedulerServiceProvider` (with `ConsoleServiceProvider`), add
tasks in a provider's or module's `boot()`, and run the scheduler every
minute from the system's crontab:

```php
$schedule = $container->get(Schedule::class);
$schedule->call(PublishDuePosts::class)->everyMinute()->withoutOverlapping();
$schedule->call([Reports::class, 'weekly'])->cron('0 7 * * mon')->timezone('Africa/Kinshasa');
```

```
* * * * * cd /var/www/app && php bin/zana schedule:run >> var/log/schedule.log 2>&1
```

## Rules

- **What a task can be:** a closure, an invokable class or `[class, method]`.
  Its parameters are resolved from a fresh container scope for each run.
- **Failures:** a failing task is logged (PSR-3) and does not stop the
  others. `schedule:run` then exits with 1.
- **Cron expressions** have five fields, with lists, ranges, steps and names,
  plus `@daily`-style aliases. Shortcuts: `everyMinute()`, `hourly()`,
  `dailyAt('03:00')`, `weekly()`, `monthly()`.
- **Overlap:** `withoutOverlapping()` skips a run while the previous one
  holds its lock, using the PSR-16 cache.
- **Listing:** `schedule:list` shows every task and its next run.

## License

MIT
