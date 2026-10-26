<?php

declare(strict_types=1);

namespace Zana\Scheduler\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Scheduler\Schedule;

#[AsCommand(name: 'schedule:list', description: 'List the scheduled tasks and their next run')]
final class ScheduleListCommand extends Command
{
    public function handle(SymfonyStyle $io, Schedule $schedule): int
    {
        $now = new \DateTimeImmutable();
        $rows = array_map(static fn ($task): array => [$task->describe(), $task->expression(), $task->nextRun($now)->format('Y-m-d H:i T')], $schedule->tasks());

        $rows === [] ? $io->writeln('No task is scheduled.') : $io->table(['Task', 'Cron', 'Next run'], $rows);

        return self::SUCCESS;
    }
}
