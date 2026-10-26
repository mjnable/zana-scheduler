<?php

declare(strict_types=1);

namespace Zana\Scheduler;

use Closure;

/**
 * The application's scheduled tasks. Modules add theirs in boot():
 *
 *     $container->get(Schedule::class)->call(PublishDuePosts::class)->everyMinute()->withoutOverlapping();
 *     $container->get(Schedule::class)->call([Reports::class, 'weekly'])->cron('0 7 * * mon')->timezone('Africa/Kinshasa');
 *
 * The system's cron runs `php bin/zana schedule:run` every minute.
 */
final class Schedule
{
    /** @var list<Task> */
    private array $tasks = [];

    /**
     * @param Closure|class-string|array{class-string, string} $callable
     */
    public function call(Closure|string|array $callable): Task
    {
        return $this->tasks[] = new Task($callable);
    }

    /**
     * @return list<Task>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * @return list<Task>
     */
    public function due(\DateTimeImmutable $now): array
    {
        return array_values(array_filter($this->tasks, static fn (Task $task): bool => $task->isDue($now)));
    }
}
