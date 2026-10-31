<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Turns git's unified diff into rows for the changes page: numbered lines, hunk headers,
 * and the changed part of an edited line marked, so a one-word change is easy to spot.
 */
final class DiffView
{
    /**
     * @return array{rows: list<array{type: string, old: ?int, new: ?int, html: string, eol?: bool}>, added: int, removed: int, binary: bool}
     *         type is hunk, ctx, add, del or note; html is escaped text
     */
    public static function parse(string $text): array
    {
        $rows = [];
        $added = $removed = 0;
        $old = $new = 0;
        $inHunk = false;
        foreach (explode("\n", rtrim($text, "\n")) as $line) {
            if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@ ?(.*)$/', $line, $m)) {
                [$old, $new, $inHunk] = [(int) $m[1], (int) $m[2], true];
                $rows[] = ['type' => 'hunk', 'old' => null, 'new' => null, 'html' => e(rtrim($m[3], "\r"))];
                continue;
            }
            if (!$inHunk || $line === '') {
                continue; // the "diff --git", "index", "---" and "+++" header
            }
            $body = substr($line, 1);
            $row = match ($line[0]) {
                '+' => ['type' => 'add', 'old' => null, 'new' => $new++],
                '-' => ['type' => 'del', 'old' => $old++, 'new' => null],
                '\\' => ['type' => 'note', 'old' => null, 'new' => null],
                default => ['type' => 'ctx', 'old' => $old++, 'new' => $new++],
            };
            $added += $row['type'] === 'add' ? 1 : 0;
            $removed += $row['type'] === 'del' ? 1 : 0;
            $rows[] = $row + ['text' => $body];
        }
        self::markChanges($rows);
        foreach ($rows as &$row) {
            if (isset($row['text'])) {
                $row['html'] ??= e(rtrim($row['text'], "\r"));
                unset($row['text']);
            }
        }
        unset($row);
        return ['rows' => $rows, 'added' => $added, 'removed' => $removed, 'binary' => str_contains($text, "\nBinary files ") || str_starts_with($text, 'Binary files ')];
    }

    /**
     * Rows for the side-by-side view: [left row or null, right row or null], or a hunk row
     * on its own. Removed lines sit next to the added lines that replace them.
     *
     * @return list<array{0: ?array, 1: ?array}|array{hunk: array}>
     */
    public static function split(array $rows): array
    {
        $pairs = [];
        $dels = $adds = [];
        $flush = static function () use (&$pairs, &$dels, &$adds): void {
            for ($i = 0, $n = max(count($dels), count($adds)); $i < $n; $i++) {
                $pairs[] = [$dels[$i] ?? null, $adds[$i] ?? null];
            }
            $dels = $adds = [];
        };
        foreach ($rows as $row) {
            if ($row['type'] === 'del') {
                if ($adds !== []) {
                    $flush();
                }
                $dels[] = $row;
            } elseif ($row['type'] === 'add') {
                $adds[] = $row;
            } else {
                $flush();
                $pairs[] = in_array($row['type'], ['hunk', 'note'], true) ? ['hunk' => $row] : [$row, $row];
            }
        }
        $flush();
        return $pairs;
    }

    /** Marks the changed middle of each removed line and the added line that replaced it. */
    private static function markChanges(array &$rows): void
    {
        $count = count($rows);
        for ($i = 0; $i < $count; $i++) {
            if ($rows[$i]['type'] !== 'del' || ($i > 0 && $rows[$i - 1]['type'] === 'del')) {
                continue;
            }
            $dels = [];
            for ($j = $i; $j < $count && $rows[$j]['type'] === 'del'; $j++) {
                $dels[] = $j;
            }
            $adds = [];
            for (; $j < $count && $rows[$j]['type'] === 'add'; $j++) {
                $adds[] = $j;
            }
            foreach (array_slice($dels, 0, count($adds)) as $k => $d) {
                $a = $adds[$k];
                $before = rtrim($rows[$d]['text'], "\r");
                $after = rtrim($rows[$a]['text'], "\r");
                if ($before === $after) {
                    $rows[$d]['eol'] = $rows[$a]['eol'] = true; // only the line ending differs
                    continue;
                }
                [$rows[$d]['html'], $rows[$a]['html']] = self::mark($before, $after);
            }
        }
    }

    /** @return array{0: string, 1: string} both lines escaped, the differing middle in <mark> */
    private static function mark(string $before, string $after): array
    {
        $a = preg_split('//u', $before, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($before);
        $b = preg_split('//u', $after, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($after);
        $max = min(count($a), count($b));
        for ($start = 0; $start < $max && $a[$start] === $b[$start]; $start++);
        for ($end = 0; $end < $max - $start && $a[count($a) - 1 - $end] === $b[count($b) - 1 - $end]; $end++);
        // Nothing in common: marking the whole line adds nothing.
        if ($start + $end === 0) {
            return [e($before), e($after)];
        }
        $wrap = static function (array $chars) use ($start, $end): string {
            $middle = implode('', array_slice($chars, $start, count($chars) - $start - $end));
            return e(implode('', array_slice($chars, 0, $start)))
                . ($middle !== '' ? '<mark>' . e($middle) . '</mark>' : '')
                . e(implode('', array_slice($chars, count($chars) - $end)));
        };
        return [$wrap($a), $wrap($b)];
    }
}
