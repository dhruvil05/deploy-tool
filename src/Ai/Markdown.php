<?php

declare(strict_types=1);

namespace DeployTool\Ai;

/**
 * The assistant's Markdown as safe HTML: headings, paragraphs, nested lists (also task
 * lists), tables, quotes, rules, code blocks, and inline bold, italic, strikethrough, code
 * and links. Everything is escaped first; links only go to http(s) or the tool's own pages.
 */
final class Markdown
{
    public static function html(string $text): string
    {
        $lines = preg_split('/\r?\n/', trim($text)) ?: [];
        return self::blocks($lines);
    }

    /** @param list<string> $lines */
    private static function blocks(array $lines): string
    {
        $html = '';
        $paragraph = [];
        $flush = static function () use (&$html, &$paragraph): void {
            if ($paragraph !== []) {
                $html .= '<p>' . implode('<br>', array_map([self::class, 'inline'], $paragraph)) . '</p>';
                $paragraph = [];
            }
        };
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if (preg_match('/^\s*(```|~~~)\s*([\w+-]*)\s*$/', $line, $m)) {
                $flush();
                $code = [];
                for ($i++; $i < $count && !preg_match('/^\s*' . preg_quote($m[1], '/') . '\s*$/', $lines[$i]); $i++) {
                    $code[] = $lines[$i];
                }
                $html .= '<pre><code>' . e(implode("\n", $code)) . '</code></pre>';
                continue;
            }
            if (trim($line) === '') {
                $flush();
                continue;
            }
            if (str_contains($line, '|') && isset($lines[$i + 1]) && self::isTableRule($lines[$i + 1])) {
                $flush();
                $rows = [$line];
                $rule = self::cells($lines[$i + 1]);
                for ($i += 2; $i < $count && str_contains($lines[$i], '|') && trim($lines[$i]) !== ''; $i++) {
                    $rows[] = $lines[$i];
                }
                $i--;
                $html .= self::table($rows, $rule);
                continue;
            }
            if (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m)) {
                $flush();
                $level = min(5, strlen($m[1]) + 2); // # → h3: the chat has its own title
                $html .= "<h$level>" . self::inline($m[2]) . "</h$level>";
                continue;
            }
            if (preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line)) {
                $flush();
                $html .= '<hr>';
                continue;
            }
            if (preg_match('/^\s{0,3}>/', $line)) {
                $flush();
                $quote = [];
                for (; $i < $count && preg_match('/^\s{0,3}>\s?(.*)$/', $lines[$i], $q); $i++) {
                    $quote[] = $q[1];
                }
                $i--;
                $html .= '<blockquote>' . self::blocks($quote) . '</blockquote>';
                continue;
            }
            if (self::listItem($line) !== null) {
                $flush();
                $items = [];
                for (; $i < $count; $i++) {
                    $item = self::listItem($lines[$i]);
                    if ($item !== null) {
                        $items[] = $item;
                    } elseif (trim($lines[$i]) !== '' && $items !== [] && preg_match('/^\s{2,}\S/', $lines[$i])) {
                        $items[array_key_last($items)][2] .= "\n" . trim($lines[$i]); // a wrapped item continues
                    } else {
                        break;
                    }
                }
                $i--;
                $html .= self::lists($items);
                continue;
            }
            $paragraph[] = $line;
        }
        $flush();
        return $html;
    }

    /** @return array{0: int, 1: string, 2: string}|null indent, ul or ol, text */
    private static function listItem(string $line): ?array
    {
        if (preg_match('/^(\s*)([-*+•])\s+(.*)$/u', $line, $m)) {
            return [strlen(str_replace("\t", '    ', $m[1])), 'ul', $m[3]];
        }
        if (preg_match('/^(\s*)\d{1,3}[.)]\s+(.*)$/', $line, $m)) {
            return [strlen(str_replace("\t", '    ', $m[1])), 'ol', $m[2]];
        }
        return null;
    }

    /**
     * Nested lists from items with their indents: a deeper item opens a list inside the
     * item above it.
     *
     * @param list<array{0: int, 1: string, 2: string}> $items
     */
    private static function lists(array $items): string
    {
        $html = '';
        $stack = []; // open lists: [indent, type]
        foreach ($items as [$indent, $type, $text]) {
            while ($stack !== [] && $indent < end($stack)[0]) {
                $html .= '</li></' . array_pop($stack)[1] . '>';
            }
            if ($stack !== [] && $indent === end($stack)[0] && $type !== end($stack)[1]) {
                $html .= '</li></' . array_pop($stack)[1] . '>';
            }
            if ($stack === [] || $indent > end($stack)[0]) {
                $html .= '<' . $type . '>';
                $stack[] = [$indent, $type];
            } else {
                $html .= '</li>';
            }
            $html .= '<li>' . self::taskItem(implode('<br>', array_map([self::class, 'inline'], explode("\n", $text))));
        }
        while ($stack !== []) {
            $html .= '</li></' . array_pop($stack)[1] . '>';
        }
        return $html;
    }

    /** "[x] done" and "[ ] to do" items as ticked or empty boxes (not clickable). */
    private static function taskItem(string $html): string
    {
        if (preg_match('/^\[([ xX])\]\s+(.*)$/s', $html, $m)) {
            return '<span class="md-task' . ($m[1] === ' ' ? '' : ' is-done') . '" aria-hidden="true">' . ($m[1] === ' ' ? '' : '✓') . '</span>'
                . '<span class="sr-only">' . ($m[1] === ' ' ? 'To do: ' : 'Done: ') . '</span>' . $m[2];
        }
        return $html;
    }

    private static function isTableRule(string $line): bool
    {
        return (bool) preg_match('/^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/', $line) && str_contains($line, '-');
    }

    /** @return list<string> a row's cells; "\|" stays a literal bar */
    private static function cells(string $row): array
    {
        $row = trim($row);
        $row = (string) preg_replace('/^\||(?<!\\\\)\|$/', '', $row);
        return array_map(static fn (string $cell): string => trim(str_replace('\\|', '|', $cell)), preg_split('/(?<!\\\\)\|/', $row) ?: []);
    }

    /** @param list<string> $rows header first */
    private static function table(array $rows, array $rule): string
    {
        $align = array_map(static fn (string $cell): string => match (true) {
            str_starts_with($cell, ':') && str_ends_with($cell, ':') => 'md-center',
            str_ends_with($cell, ':') => 'md-right',
            default => '',
        }, $rule);
        // Short cells (dates, times, names) stay on one line; long ones wrap.
        $attributes = static function (int $index, string $cell) use ($align): string {
            $classes = trim(($align[$index] ?? '') . (strlen($cell) <= 24 ? ' md-nowrap' : ''));
            return $classes !== '' ? ' class="' . $classes . '"' : '';
        };
        $header = self::cells(array_shift($rows));
        $width = count($header);
        $html = '<div class="md-table"><table><thead><tr>';
        foreach ($header as $index => $cell) {
            $html .= '<th' . (($align[$index] ?? '') !== '' ? ' class="' . $align[$index] . '"' : '') . '>' . self::inline($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $cells = array_pad(array_slice(self::cells($row), 0, $width), $width, '');
            $html .= '<tr>';
            foreach ($cells as $index => $cell) {
                $html .= '<td' . $attributes($index, $cell) . '>' . self::inline($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /** Code spans stay literal; the rest gets links, bold, italic and strikethrough. */
    private static function inline(string $text): string
    {
        $parts = preg_split('/(`+[^`]+?`+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html = '';
        foreach ($parts as $part) {
            if (preg_match('/^(`+)(.+?)\1$/s', $part, $m)) {
                $html .= '<code>' . e(trim($m[2])) . '</code>';
                continue;
            }
            $part = e($part);
            $part = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
                $url = html_entity_decode($m[2], ENT_QUOTES);
                if (!preg_match('#^(https?://|index\.php\?)#i', $url)) {
                    return $m[1]; // other schemes (javascript:, file:, …) lose the link, keep the text
                }
                $external = !str_starts_with($url, 'index.php');
                return '<a href="' . e($url) . '"' . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $m[1] . '</a>';
            }, $part);
            $part = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*|__(?=\S)(.+?)(?<=\S)__/', '<strong>$1$2</strong>', $part);
            $part = (string) preg_replace('/(?<![*\w])\*(?=\S)([^*]+?)(?<=\S)\*(?!\*)|(?<![\w_])_(?=\S)([^_]+?)(?<=\S)_(?![\w_])/', '<em>$1$2</em>', $part);
            $part = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/', '<del>$1</del>', $part);
            $html .= $part;
        }
        return $html;
    }
}
