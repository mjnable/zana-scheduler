<?php

declare(strict_types=1);

namespace Zana\Scheduler;

use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\ScopeMetadata;

/**
 * Runs the tasks that are due, each in its own container scope, so a task
 * gets fresh scoped services (a unit of work, a database connection) and
 * one failing task does not stop the others. Failures are logged.
 */
final readonly class Scheduler
{
    public function __construct(
        private Schedule $schedule,
        private ContainerInterface $container,
        private ?CacheInterface $locks = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array<string, 'ran'|'skipped'|'failed'> task => outcome
     */
    public function runDue(\DateTimeImmutable $now): array
    {
        $outcomes = [];
        foreach ($this->schedule->due($now) as $task) {
            $outcomes[$task->describe()] = $this->run($task);
        }

        return $outcomes;
    }

    /**
     * @return 'ran'|'skipped'|'failed'
     */
    public function run(Task $task): string
    {
        $lock = 'zana.schedule.lock.' . hash('xxh128', $task->describe() . $task->expression());
        $held = null;
        if ($task->overlapLock() !== null) {
            $held = $this->locks ?? throw new SchedulerException('withoutOverlapping() needs a PSR-16 cache.');
            if ($held->has($lock)) {
                return 'skipped';
            }
            $held->set($lock, true, $task->overlapLock());
        }

        $scope = $this->container->createScope(new ScopeMetadata('schedule', $task->describe()));
        try {
            $callable = $task->callable;
            $scope->call(is_string($callable) ? [$callable, '__invoke'] : $callable);

            return 'ran';
        } catch (\Throwable $e) {
            $this->logger?->error('Scheduled task {task} failed: {message}', ['task' => $task->describe(), 'message' => $e->getMessage(), 'exception' => $e]);

            return 'failed';
        } finally {
            $scope->dispose();
            $held?->delete($lock);
        }
    }
}
