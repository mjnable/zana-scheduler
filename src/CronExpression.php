<?php

declare(strict_types=1);

namespace Zana\Scheduler;

/**
 * A standard five-field cron expression: minute, hour, day of month, month,
 * day of week (0 or 7 is Sunday). Fields accept *, lists (1,15), ranges
 * (1-5), steps (*\/15, 10-50/10), and month and day names (jan, mon).
 * Aliases: @yearly, @monthly, @weekly, @daily, @hourly.
 *
 * As in cron, when both day of month and day of week are restricted, either
 * one matching is enough.
 */
final readonly class CronExpression implements \Stringable
{
    private const array ALIASES = [
        '@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *', '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0', '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@hourly' => '0 * * * *',
    ];

    private const array NAMES = [
        3 => ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12],
        4 => ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6],
    ];

    private const array BOUNDS = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    /** @var list<array<int, true>> allowed values per field */
    private array $fields;

    /** @var list<bool> whether the field is "*" */
    private array $wildcards;

    public function __construct(public string $expression)
    {
        $parts = preg_split('/\s+/', trim(self::ALIASES[strtolower(trim($expression))] ?? $expression)) ?: [];
        if (count($parts) !== 5) {
            throw new SchedulerException("\"{$expression}\" is not a cron expression: expected five fields.");
        }

        $fields = [];
        $wildcards = [];
        foreach ($parts as $index => $part) {
            $wildcards[] = $part === '*';
            $fields[] = self::parseField(strtolower($part), $index, $expression);
        }
        // Sunday is 0 or 7.
        if (isset($fields[4][7])) {
            $fields[4][0] = true;
        }
        $this->fields = $fields;
        $this->wildcards = $wildcards;
    }

    public function isDue(\DateTimeInterface $at): bool
    {
        [$minute, $hour, $day, $month, $weekday] = array_map('intval', explode(' ', $at->format('i G j n w')));

        if (!isset($this->fields[0][$minute], $this->fields[1][$hour], $this->fields[3][$month])) {
            return false;
        }

        $dayMatches = isset($this->fields[2][$day]);
        $weekdayMatches = isset($this->fields[4][$weekday]);
        if ($this->wildcards[2] || $this->wildcards[4]) {
            return ($this->wildcards[2] || $dayMatches) && ($this->wildcards[4] || $weekdayMatches);
        }

        return $dayMatches || $weekdayMatches;
    }

    /**
     * The next minute after $after when the expression is due.
     */
    public function nextRun(\DateTimeImmutable $after): \DateTimeImmutable
    {
        $candidate = $after->setTime((int) $after->format('G'), (int) $after->format('i'))->modify('+1 minute');
        for ($i = 0; $i < 527_040; $i++) {   // a leap year of minutes
            if ($this->isDue($candidate)) {
                return $candidate;
            }
            $candidate = $candidate->modify('+1 minute');
        }

        throw new SchedulerException("\"{$this->expression}\" is never due.");
    }

    public function __toString(): string
    {
        return $this->expression;
    }

    /**
     * @return array<int, true>
     */
    private static function parseField(string $field, int $index, string $expression): array
    {
        [$min, $max] = self::BOUNDS[$index];
        $values = [];

        foreach (explode(',', $field) as $item) {
            $pieces = explode('/', $item, 2);
            $range = $pieces[0];
            $step = !isset($pieces[1]) ? 1 : (ctype_digit($pieces[1]) && (int) $pieces[1] > 0 ? (int) $pieces[1] : throw new SchedulerException("Invalid step in \"{$expression}\"."));

            if ($range === '*') {
                [$from, $to] = [$min, $max];
            } elseif (str_contains($range, '-')) {
                [$from, $to] = array_map(static fn (string $v): int => self::value($v, $index, $expression), explode('-', $range, 2));
            } else {
                $from = self::value($range, $index, $expression);
                $to = $step === 1 ? $from : $max;
            }

            if ($from < $min || $to > $max || $from > $to) {
                throw new SchedulerException("\"{$item}\" is out of range in \"{$expression}\".");
            }
            for ($value = $from; $value <= $to; $value += $step) {
                $values[$value] = true;
            }
        }

        return $values;
    }

    private static function value(string $value, int $index, string $expression): int
    {
        if (isset(self::NAMES[$index][$value])) {
            return self::NAMES[$index][$value];
        }
        if (!ctype_digit($value)) {
            throw new SchedulerException("\"{$value}\" is not valid in \"{$expression}\".");
        }

        return (int) $value;
    }
}
