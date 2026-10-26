<?php

declare(strict_types=1);

namespace Zana\Scheduler\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Scheduler\Scheduler;

/**
 * Runs the tasks due this minute. Add it to the system's crontab:
 *
 *     * * * * * cd /path/to/app && php bin/zana schedule:run >> var/log/schedule.log 2>&1
 */
#[AsCommand(name: 'schedule:run', description: 'Run the scheduled tasks that are due')]
final class ScheduleRunCommand extends Command
{
    public function handle(SymfonyStyle $io, Scheduler $scheduler): int
    {
        $outcomes = $scheduler->runDue(new \DateTimeImmutable());
        if ($outcomes === []) {
            $io->writeln('No task is due.');

            return self::SUCCESS;
        }

        foreach ($outcomes as $task => $outcome) {
            $io->writeln(sprintf('%-8s %s', $outcome, $task));
        }

        return in_array('failed', $outcomes, true) ? self::FAILURE : self::SUCCESS;
    }
}
