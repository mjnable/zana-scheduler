<?php

declare(strict_types=1);

namespace Zana\Scheduler;

use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Zana\Console\ConsoleKernel;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\ServiceProvider;
use Zana\Scheduler\Command\ScheduleListCommand;
use Zana\Scheduler\Command\ScheduleRunCommand;

/**
 * Registers the Schedule, the Scheduler (overlap locks in the PSR-16 cache,
 * failures in the PSR-3 logger, when registered) and the schedule:run and
 * schedule:list commands.
 */
final class SchedulerServiceProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
        $services
            ->singleton(Schedule::class)
            ->singleton(Scheduler::class, static function (ServiceResolverInterface $r): Scheduler {
                assert($r instanceof ContainerInterface);
                $schedule = $r->get(Schedule::class);
                $cache = $r->has(CacheInterface::class) ? $r->get(CacheInterface::class) : null;
                $logger = $r->has(LoggerInterface::class) ? $r->get(LoggerInterface::class) : null;
                assert($schedule instanceof Schedule);

                return new Scheduler($schedule, $r, $cache instanceof CacheInterface ? $cache : null, $logger instanceof LoggerInterface ? $logger : null);
            })->uses(Schedule::class, CacheInterface::class, LoggerInterface::class)
            ->singleton(ScheduleRunCommand::class)
            ->tag(ConsoleKernel::TAG, ScheduleRunCommand::class)
            ->singleton(ScheduleListCommand::class)
            ->tag(ConsoleKernel::TAG, ScheduleListCommand::class);
    }

    public function origin(): string
    {
        return 'package:mj-zana/scheduler';
    }
}
