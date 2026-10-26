<?php

declare(strict_types=1);

namespace Zana\Scheduler\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Container\Contracts\Disposable;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Container\ScopeMetadata;
use Zana\Core\Application;
use Zana\Scheduler\CronExpression;
use Zana\Scheduler\Schedule;
use Zana\Scheduler\Scheduler;
use Zana\Scheduler\SchedulerException;
use Zana\Scheduler\SchedulerServiceProvider;

final class SchedulerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function expressions(): iterable
    {
        yield 'every minute' => ['* * * * *', '2026-10-26 14:37', true];
        yield 'step' => ['*/15 * * * *', '2026-10-26 14:45', true];
        yield 'step miss' => ['*/15 * * * *', '2026-10-26 14:46', false];
        yield 'range and list' => ['0 9-17 * * 1,3', '2026-10-26 12:00', true];   // a Monday
        yield 'names' => ['0 7 * jan-dec mon', '2026-10-26 07:00', true];
        yield 'sunday as 7' => ['0 0 * * 7', '2026-11-01 00:00', true];
        yield 'day or weekday' => ['0 0 1 * fri', '2026-10-30 00:00', true];   // a Friday, not the 1st
        yield 'daily alias' => ['@daily', '2026-10-26 00:00', true];
        yield 'range with step' => ['10-50/20 * * * *', '2026-10-26 14:30', true];
    }

    #[DataProvider('expressions')]
    public function testCronExpressions(string $expression, string $at, bool $due): void
    {
        $this->assertSame($due, (new CronExpression($expression))->isDue(new \DateTimeImmutable($at)));
    }

    public function testNextRun(): void
    {
        $this->assertSame('2026-10-27 03:00', (new CronExpression('0 3 * * *'))->nextRun(new \DateTimeImmutable('2026-10-26 03:00:30'))->format('Y-m-d H:i'));
    }

    public function testInvalidExpressionsAreRejected(): void
    {
        foreach (['* * * *', '60 * * * *', '*/0 * * * *', 'x * * * *', '5-1 * * * *'] as $invalid) {
            try {
                new CronExpression($invalid);
                $this->fail("Expected {$invalid} to be rejected.");
            } catch (SchedulerException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDueTasksRunEachInItsOwnScope(): void
    {
        [$app, $schedule] = $this->app();
        $schedule->call(ReportTask::class)->dailyAt('03:00');
        $schedule->call(static function (Work $work): void {
            Log::$lines[] = "closure in {$work->scope->type}";
        })->everyMinute()->name('heartbeat');
        $schedule->call([ReportTask::class, 'weekly'])->weekly();

        $outcomes = $this->scheduler($app)->runDue(new \DateTimeImmutable('2026-10-26 03:00'));

        $this->assertSame([ReportTask::class => 'ran', 'heartbeat' => 'ran'], $outcomes);
        $this->assertSame(['report in schedule', 'disposed', 'closure in schedule', 'disposed'], Log::$lines);
    }

    public function testFailuresAreLoggedAndDoNotStopTheOthers(): void
    {
        [$app, $schedule] = $this->app();
        $schedule->call(static fn () => throw new \RuntimeException('Disk full'))->name('broken');
        $schedule->call(static function (): void {
            Log::$lines[] = 'still ran';
        })->name('fine');

        $outcomes = $this->scheduler($app)->runDue(new \DateTimeImmutable());

        $this->assertSame(['broken' => 'failed', 'fine' => 'ran'], $outcomes);
        $this->assertContains('error: Scheduled task broken failed: Disk full', Log::$lines);
    }

    public function testOverlappingRunsAreSkipped(): void
    {
        [$app, $schedule] = $this->app();
        $task = $schedule->call(static fn () => null)->name('import')->withoutOverlapping();
        $cache = $app->container()->get(CacheInterface::class);
        assert($cache instanceof MemoryCache);
        $cache->set('zana.schedule.lock.' . hash('xxh128', 'import* * * * *'), true);

        $this->assertSame('skipped', $this->scheduler($app)->run($task));

        $cache->clear();
        $this->assertSame('ran', $this->scheduler($app)->run($task));
        $this->assertSame([], $cache->items, 'the lock is released');
    }

    public function testTheCommands(): void
    {
        [$app, $schedule] = $this->app();
        $schedule->call(static fn () => null)->everyMinute()->name('heartbeat');
        $kernel = $app->container()->get(ConsoleKernel::class);
        assert($kernel instanceof ConsoleKernel);
        $kernel->application()->setAutoExit(false);

        $output = new BufferedOutput();
        $this->assertSame(0, $kernel->run(new ArrayInput(['command' => 'schedule:run']), $output));
        $this->assertStringContainsString('ran      heartbeat', $output->fetch());

        $kernel->run(new ArrayInput(['command' => 'schedule:list']), $output);
        $this->assertStringContainsString('heartbeat', $output->fetch());
    }

    /**
     * @return array{Application, Schedule}
     */
    private function app(): array
    {
        Log::$lines = [];
        $app = Application::configure(sys_get_temp_dir() . '/zana-scheduler')
            ->withEnvironment('testing')
            ->withProviders(ConsoleServiceProvider::class, SchedulerServiceProvider::class)
            ->withServices(static fn (ServiceCollectionInterface $s) => $s
                ->scoped(Work::class, static fn (ServiceResolverInterface $r): Work => new Work($r->metadata()))
                ->transient(ReportTask::class)
                ->singleton(LoggerInterface::class, Log::class)
                ->instance(CacheInterface::class, new MemoryCache()))
            ->build();
        $schedule = $app->container()->get(Schedule::class);
        assert($schedule instanceof Schedule);

        return [$app, $schedule];
    }

    private function scheduler(Application $app): Scheduler
    {
        $scheduler = $app->container()->get(Scheduler::class);
        assert($scheduler instanceof Scheduler);

        return $scheduler;
    }
}

final class Work implements Disposable
{
    public function __construct(public readonly ScopeMetadata $scope)
    {
    }

    public function dispose(): void
    {
        Log::$lines[] = 'disposed';
    }
}

final class ReportTask
{
    public function __invoke(Work $work): void
    {
        Log::$lines[] = "report in {$work->scope->type}";
    }

    public function weekly(): void
    {
        Log::$lines[] = 'weekly';
    }
}

final class Log extends AbstractLogger
{
    /** @var list<string> */
    public static array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $replace['{' . $key . '}'] = $value;
            }
        }
        self::$lines[] = "{$level}: " . strtr((string) $message, $replace);
    }
}

final class MemoryCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $items = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->items[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return [];
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }
}
