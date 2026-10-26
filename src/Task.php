<?php

declare(strict_types=1);

namespace Zana\Scheduler;

use Closure;

/**
 * One scheduled task: what to run and when. Built with Schedule::call().
 */
final class Task
{
    private CronExpression $cron;

    private ?string $name = null;

    private ?\DateTimeZone $timezone = null;

    private ?int $overlapLock = null;

    /**
     * @param Closure|class-string|array{class-string, string} $callable A closure, an invokable class or [class, method]; its parameters are resolved from the run's scope
     */
    public function __construct(public readonly Closure|string|array $callable)
    {
        $this->cron = new CronExpression('* * * * *');
    }

    public function cron(string $expression): self
    {
        $this->cron = new CronExpression($expression);

        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyFifteenMinutes(): self
    {
        return $this->cron('*/15 * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    public function dailyAt(string $time): self
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m) !== 1) {
            throw new SchedulerException("\"{$time}\" is not a time of day (HH:MM).");
        }

        return $this->cron(sprintf('%d %d * * *', (int) $m[2], (int) $m[1]));
    }

    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    /**
     * Evaluate the expression in this time zone (default: PHP's).
     */
    public function timezone(string $timezone): self
    {
        $this->timezone = new \DateTimeZone($timezone);

        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Skip a run while the previous one is still going; the lock expires
     * after $seconds in case a run dies.
     */
    public function withoutOverlapping(int $seconds = 3600): self
    {
        $this->overlapLock = $seconds;

        return $this;
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        return $this->cron->isDue($this->timezone === null ? $now : $now->setTimezone($this->timezone));
    }

    public function nextRun(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $this->cron->nextRun($this->timezone === null ? $now : $now->setTimezone($this->timezone));
    }

    public function expression(): string
    {
        return (string) $this->cron;
    }

    public function overlapLock(): ?int
    {
        return $this->overlapLock;
    }

    public function describe(): string
    {
        return $this->name ?? match (true) {
            $this->callable instanceof Closure => 'closure',
            is_array($this->callable) => implode('::', $this->callable),
            default => $this->callable,
        };
    }
}
