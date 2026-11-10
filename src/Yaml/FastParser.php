<?php

namespace Statamic\Yaml;

use Symfony\Component\Yaml\Inline;
use Symfony\Component\Yaml\Parser;

/**
 * A faster parser for the block-style YAML that Statamic writes.
 *
 * Symfony's parser handles nested blocks recursively: for every nested block it collects
 * the block's lines and parses them again with a new parser, so each line is scanned once
 * per level of nesting. Content like Bard is deeply nested, which makes that expensive.
 *
 * This parser walks the lines once, descending into each nested block as it meets it.
 * Plain keys and the most common values are read directly. Any other key or value is
 * handed to Symfony's own inline parser, so it is interpreted exactly as Symfony would
 * interpret it, and one unusual value doesn't cost the whole document.
 *
 * Anything outside the subset it understands (comments, anchors, tags, multi-line flow
 * collections or plain scalars, invalid YAML, etc.) makes it give up, and the whole
 * document is parsed by Symfony instead. Errors are therefore always Symfony's.
 *
 * @internal
 */
class FastParser
{
    private const WORD = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';

    private const HEX = '0123456789abcdef-';

    /** First bytes of a plain scalar that Symfony always returns as a string (bar null/true/false). */
    private const PLAIN = [
        'a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1, 'f' => 1, 'g' => 1, 'h' => 1, 'i' => 1, 'j' => 1, 'k' => 1, 'l' => 1, 'm' => 1,
        'n' => 1, 'o' => 1, 'p' => 1, 'q' => 1, 'r' => 1, 's' => 1, 't' => 1, 'u' => 1, 'v' => 1, 'w' => 1, 'x' => 1, 'y' => 1, 'z' => 1,
        'A' => 1, 'B' => 1, 'C' => 1, 'D' => 1, 'E' => 1, 'F' => 1, 'G' => 1, 'H' => 1, 'I' => 1, 'J' => 1, 'K' => 1, 'L' => 1, 'M' => 1,
        'N' => 1, 'O' => 1, 'P' => 1, 'Q' => 1, 'R' => 1, 'S' => 1, 'T' => 1, 'U' => 1, 'V' => 1, 'W' => 1, 'X' => 1, 'Y' => 1, 'Z' => 1,
        '_' => 1, '/' => 1, '(' => 1, '$' => 1, '=' => 1, ';' => 1, '^' => 1, '<' => 1,
    ];

    private const RESERVED = ['null' => null, 'true' => true, 'false' => false];

    /**
     * Deeper documents are left to Symfony, which applies its own nesting limit. This is
     * well below Symfony's default limit, and far deeper than real content goes.
     */
    private const MAX_DEPTH = 64;

    private array $lines = [];

    private int $n = 0;

    private int $p = 0;

    /** Lines Symfony strips from the top (the "---" marker and blank lines). */
    private int $lead = 0;

    /** How many "- key: value" mappings are open. */
    private int $compact = 0;

    /** How many mappings and sequences are open. */
    private int $depth = 0;

    /** Whether the document has tabs, which are only understood inside literal blocks. */
    private bool $tabs = false;

    /**
     * The most keys that are remembered. The list lives as long as the process does, which
     * under Octane or in a queue worker is many requests, so it must not grow without limit.
     */
    private const MAX_KEYS = 2048;

    /**
     * Raw key text => parsed key, for keys already proven safe. What a key parses to depends
     * only on its text, so an entry is never out of date.
     */
    private static array $keys = [];

    public function __construct(private Parser $symfony = new Parser)
    {
    }

    public function parse(string $yaml, int $flags = 0): mixed
    {
        // Flags change how values are interpreted, which we leave to Symfony.
        if ($flags === 0) {
            try {
                return $this->parseBlocks($yaml);
            } catch (CannotFastParse) {
                //
            }
        }

        return $this->symfony->parse($yaml, $flags);
    }

    private function parseBlocks(string $str): array
    {
        if ($str === '' || strpbrk($str, "\r\0\x0B") !== false || ! preg_match('//u', $str)) {
            $this->fail();
        }

        // Older versions of Symfony strip a document end marker from the very end, wherever it sits.
        if (str_ends_with(rtrim($str, " \n"), '...')) {
            $this->fail();
        }

        // Front matter arrives with its opening "---" line, which Symfony ignores.
        if (str_starts_with($str, "---\n")) {
            $str = substr($str, 4);
            $this->lead = 1;
        } else {
            $this->lead = 0;
        }

        $this->compact = 0;
        $this->depth = 0;
        $this->tabs = str_contains($str, "\t");
        $this->lines = $lines = explode("\n", $str);
        $this->n = $n = count($lines);

        Inline::initialize(0);

        $p = 0;
        while ($p < $n && $lines[$p] === '') {
            $p++;
        }
        $this->p = $p;
        $this->lead += $p;

        // Nothing in it, or it starts indented.
        if ($p >= $n || $lines[$p][0] === ' ') {
            $this->fail();
        }

        $first = $lines[$p];

        $result = $first[0] === '-' && (! isset($first[1]) || $first[1] === ' ')
            ? $this->parseSeq(0)
            : $this->parseMap(0, null);

        if ($this->p < $n) {
            $this->fail();
        }

        return $result;
    }

    private function fail(): never
    {
        throw new CannotFastParse;
    }

    /**
     * @param  string|null  $line  The first line, when it isn't the current one as written ("- key: value").
     */
    private function parseMap(int $ind, ?string $line): array
    {
        if (++$this->depth > self::MAX_DEPTH) {
            $this->fail();
        }

        $out = [];
        $count = 0;
        $lines = $this->lines;
        $n = $this->n;
        $p = $this->p;
        $tabs = $this->tabs;

        while (true) {
            if ($line === null) {
                if ($p >= $n) {
                    break;
                }
                $line = $lines[$p];
            }

            $i = strspn($line, ' ');

            if ($i !== $ind || ! isset($line[$i])) {
                if (! isset($line[$i])) {
                    if ($i) {
                        $this->fail(); // Whitespace-only lines can change how Symfony reads what follows.
                    }
                    $p++;
                    $line = null;

                    continue;
                }
                if ($i < $ind) {
                    break;
                }
                $this->fail();
            }

            if ($tabs && str_contains($line, "\t")) {
                $this->fail();
            }

            $pos = strpos($line, ':', $i);

            if ($pos !== false && (! isset($line[$pos + 1]) || $line[$pos + 1] === ' ')
                && ($key = self::$keys[$raw = substr($line, $i, $pos - $i)] ?? $this->key($raw)) !== null
            ) {
                if (isset($line[$pos + 2])) {
                    $v = substr($line, $pos + 2);
                    if ($v[0] === ' ' || $v[-1] === ' ') {
                        $v = trim($v, ' ');
                    }
                } else {
                    $v = '';
                }
            } else {
                [$key, $v] = $this->slowKey(substr($line, $i));
            }

            $p++;
            $line = null;

            if ($v === '') {
                // The value is the nested block that follows, if there is one.
                if ($p < $n && ($j = strspn($next = $lines[$p], ' ')) > $ind && isset($next[$j])) {
                    $this->p = $p;
                    $out[$key] = $next[$j] === '-' && (! isset($next[$j + 1]) || $next[$j + 1] === ' ')
                        ? $this->parseSeq($j)
                        : $this->parseMap($j, null);
                    $p = $this->p;
                } else {
                    $this->p = $p;
                    $out[$key] = $this->child($ind, true);
                    $p = $this->p;
                }
            } elseif (isset(self::PLAIN[$c = $v[0]])) {
                $out[$key] = strpbrk($v, ':#') !== false
                    ? $this->inline($v, $c)
                    : (isset($v[5]) || ! array_key_exists($l = strtolower($v), self::RESERVED) ? $v : self::RESERVED[$l]);
            } elseif ($c === "'" && $v[-1] === "'" && isset($v[1]) && strpos($v, "'", 1) === strlen($v) - 1) {
                $out[$key] = substr($v, 1, -1);
            } elseif ($c === '|') {
                $this->p = $p;
                $out[$key] = $this->literal($v, $ind);
                $p = $this->p;
            } else {
                $out[$key] = $this->scalar($v, $c);
            }

            if (count($out) !== ++$count) {
                $this->fail(); // A duplicate key: let Symfony report it.
            }
        }

        $this->p = $p;
        $this->depth--;

        return $out;
    }

    /**
     * A key that hasn't been seen before. Returns null when the line needs the full key regex.
     */
    private function key(string $raw): string|int|null
    {
        if ($raw === '') {
            return null;
        }

        $c = $raw[0];

        if (strspn($raw, self::WORD) === strlen($raw)) {
            if ($c === '-' || ($c >= '0' && $c <= '9') || array_key_exists(strtolower($raw), self::RESERVED)) {
                // Could be a number, a sequence item or a reserved word.
                if ($c === '-' && ($raw === '-' || str_starts_with($raw, '---'))) {
                    return null;
                }
            } else {
                return $this->remember($raw, $raw);
            }
        }

        if (str_contains(' \'"[{!#&*?%@`|>~.', $c) || str_contains($raw, ' ') || str_starts_with($raw, '---')) {
            return null;
        }

        try {
            $key = Inline::parseScalar($raw);
        } catch (\Throwable) {
            $this->fail();
        }

        if (! is_string($key) && ! is_int($key) || $raw === '<<') {
            $this->fail();
        }

        return $this->remember($raw, $key);
    }

    private function remember(string $raw, string|int $key): string|int
    {
        if (count(self::$keys) < self::MAX_KEYS) {
            self::$keys[$raw] = $key;
        }

        return $key;
    }

    /**
     * Split a "key: value" line the way Symfony does, for keys the fast path doesn't cover.
     */
    private function slowKey(string $t): array
    {
        $c = $t[0];

        if (str_contains('#&*!?%@`{[|>', $c)
            || $c === '-' && (! isset($t[1]) || $t[1] === ' ')
            || str_starts_with($t, '---')
            || str_starts_with($t, '...')
        ) {
            $this->fail();
        }

        if (($c === '"' || $c === "'") && ! preg_match('/^(?:'.Inline::REGEX_QUOTED_STRING.') *:(?: |$)/u', $t)) {
            $this->fail();
        }

        if (! preg_match('#^(?P<key>'.Inline::REGEX_QUOTED_STRING.'|[^ \'"\[\{!].*?) *\:(?: ++(?P<value>.+))?$#u', rtrim($t, ' '), $m)) {
            $this->fail();
        }

        if (str_contains($m['key'], ' #') && $c !== '"' && $c !== "'") {
            $this->fail();
        }

        try {
            $key = Inline::parseScalar($m['key']);
        } catch (\Throwable) {
            $this->fail();
        }

        if (! is_string($key) && ! is_int($key) || $key === '<<') {
            $this->fail();
        }

        return [$key, $m['value'] ?? ''];
    }

    private function parseSeq(int $ind): array
    {
        if (++$this->depth > self::MAX_DEPTH) {
            $this->fail();
        }

        $out = [];
        $lines = $this->lines;
        $n = $this->n;
        $p = $this->p;
        $tabs = $this->tabs;

        while ($p < $n) {
            $line = $lines[$p];
            $i = strspn($line, ' ');

            if ($i !== $ind || ! isset($line[$i])) {
                if (! isset($line[$i])) {
                    if ($i) {
                        $this->fail();
                    }
                    $p++;

                    continue;
                }
                if ($i < $ind) {
                    break;
                }
                $this->fail();
            }

            if ($line[$i] !== '-') {
                break;
            }

            if (! isset($line[$i + 1])) {
                // A dash on its own: the item is the block that follows.
                $this->p = ++$p;
                if ($p < $n && ($j = strspn($next = $lines[$p], ' ')) > $ind && isset($next[$j])) {
                    $out[] = $next[$j] === '-' && (! isset($next[$j + 1]) || $next[$j + 1] === ' ')
                        ? $this->parseSeq($j)
                        : $this->parseMap($j, null);
                } else {
                    $out[] = $this->child($ind, false);
                }
                $p = $this->p;

                continue;
            }

            if ($line[$i + 1] !== ' ' || $tabs && str_contains($line, "\t")) {
                $this->fail();
            }

            $rest = trim(substr($line, $i + 2), ' ');

            if ($rest === '') {
                $this->p = $p + 1;
                $out[] = $this->child($ind, false);
                $p = $this->p;

                continue;
            }

            $c = $rest[0];

            if ($c === '-' && (! isset($rest[1]) || $rest[1] === ' ' || str_starts_with($rest, '---')) || $c === '.' && str_starts_with($rest, '...')) {
                $this->fail();
            }

            if ((str_contains($rest, ': ') || $rest[-1] === ':')
                && preg_match('#^(?P<key>'.Inline::REGEX_QUOTED_STRING.'|[^ \'"\{\[].*?) *\:(\s+(?P<value>.+?))?\s*$#u', $rest)
            ) {
                // "- key: value" starts a mapping indented to the key.
                $offset = strlen(rtrim($line, ' ')) - strlen($rest);
                $this->p = $p;
                $this->compact++;
                $out[] = $this->parseMap($offset, str_repeat(' ', $offset).$rest);
                $this->compact--;
                $p = $this->p;

                continue;
            }

            $p++;
            $out[] = $this->scalar($rest, $c);
        }

        $this->p = $p;
        $this->depth--;

        return $out;
    }

    /**
     * The nested block that follows a key or sequence item with no value of its own.
     */
    private function child(int $ind, bool $allowSameIndentSeq): ?array
    {
        $p = $this->p;

        if ($p >= $this->n) {
            return null;
        }

        $line = $this->lines[$p];

        if ($line === '') {
            // Symfony versions disagree on an empty value that is followed by a blank line,
            // unless nothing but blank lines follow.
            $n = $this->n;
            while ($p < $n && $this->lines[$p] === '') {
                $p++;
            }
            if ($p < $n) {
                $this->fail();
            }
            $this->p = $p;

            return null;
        }

        $i = strspn($line, ' ');

        if (! isset($line[$i])) {
            $this->fail();
        }

        if ($i > $ind) {
            return $line[$i] === '-' && (! isset($line[$i + 1]) || $line[$i + 1] === ' ')
                ? $this->parseSeq($i)
                : $this->parseMap($i, null);
        }

        if ($allowSameIndentSeq && $i === $ind && $line[$i] === '-' && (! isset($line[$i + 1]) || $line[$i + 1] === ' ')) {
            return $this->parseSeq($ind);
        }

        return null;
    }

    private function literal(string $header, int $ind): string
    {
        if ($this->compact) {
            // Inside "- key: value" items Symfony's handling of the final newline depends on what follows.
            $this->fail();
        }

        if ($header !== '|' && $header !== '|-') {
            return $this->literalBySymfony($header, $ind);
        }

        $lines = $this->lines;
        $n = $this->n;
        $tmp = [];
        $blockInd = null;
        $j = $this->p;

        while ($j < $n) {
            $l = $lines[$j];
            $i = strspn($l, ' ');

            if (! isset($l[$i])) {
                if ($l !== '') {
                    return $this->literalBySymfony($header, $ind);
                }
                $tmp[] = '';
                $j++;

                continue;
            }

            if ($i <= $ind) {
                break;
            }
            if ($blockInd === null) {
                $blockInd = $i;
            } elseif ($i < $blockInd) {
                return $this->literalBySymfony($header, $ind);
            }
            $tmp[] = substr($l, $blockInd);
            $j++;
        }

        if ($blockInd === null) {
            $this->fail(); // An empty block: Symfony versions disagree on what it holds.
        }

        if ($tmp[0] === '') {
            return $this->literalBySymfony($header, $ind);
        }

        while (end($tmp) === '') {
            array_pop($tmp);
        }

        if ($header === '|-') {
            $this->p = $j;

            return implode("\n", $tmp);
        }

        if ($j >= $n) {
            return $this->literalBySymfony($header, $ind);
        }

        if ($n - $j <= $this->lead) {
            // Symfony miscounts the end of the document by the lines it stripped from the top,
            // and drops this block's final newline. Leave the document to it.
            $this->fail();
        }

        $this->p = $j;

        return implode("\n", $tmp)."\n";
    }

    /**
     * Parse a literal block by handing it to Symfony as a small document of its own.
     */
    private function literalBySymfony(string $header, int $ind): string
    {
        if ($header !== '|' && $header !== '|-' && $header !== '|+') {
            $this->fail(); // Indentation indicators, comments, etc.
        }

        $lines = $this->lines;
        $n = $this->n;
        $block = ['k: '.$header];

        for ($j = $this->p; $j < $n; $j++) {
            $l = $lines[$j];
            $i = strspn($l, ' ');

            if (isset($l[$i]) && $i <= $ind) {
                break;
            }

            // Keep any spaces beyond the key's indentation, even on otherwise blank lines.
            $block[] = strlen($l) > $ind ? substr($l, $ind) : '';
        }

        if ($j < $n && $n - $j <= $this->lead && $header !== '|-') {
            $this->fail(); // See literal().
        }

        try {
            // The block's last line only ends with a newline if more lines followed it.
            $value = $this->symfony->parse(implode("\n", $block).($j < $n ? "\n" : ''))['k'];
        } catch (\Throwable) {
            $this->fail();
        }

        if (! is_string($value) || trim($value, "\n") === '') {
            $this->fail();
        }

        $this->p = $j;

        return $value;
    }

    private function scalar(string $v, string $c): mixed
    {
        if ($c === "'") {
            $len = strlen($v);
            if ($len > 1 && $v[$len - 1] === "'") {
                $in = substr($v, 1, -1);
                if (! str_contains($in, "'")) {
                    return $in;
                }
                if (! str_contains(str_replace("''", '', $in), "'")) {
                    return str_replace("''", "'", $in);
                }
            }
        } elseif ($c >= '0' && $c <= '9') {
            $len = strlen($v);
            if ($len < 19 && ctype_digit($v) && ($c !== '0' || $len === 1)) {
                return (int) $v;
            }
            if ($len === 36 && $v[8] === '-' && strspn($v, self::HEX) === 36) {
                return $v;
            }
        } elseif ($c === '"') {
            $len = strlen($v);
            if ($len > 1 && $v[$len - 1] === '"' && strpos($v, '"', 1) === $len - 1 && ! str_contains($v, '\\')) {
                return substr($v, 1, -1);
            }
        } elseif (isset(self::PLAIN[$c]) || $c >= "\x80") {
            if (strpbrk($v, ':#') === false) {
                return isset($v[5]) || ! array_key_exists($l = strtolower($v), self::RESERVED) ? $v : self::RESERVED[$l];
            }
        } elseif ($v === '[]' || $v === '{}') {
            return [];
        }

        return $this->inline($v, $c);
    }

    /**
     * Anything the shortcuts above don't cover: Symfony's inline parser reads the value.
     */
    private function inline(string $v, string $c): mixed
    {
        $quoted = $c === '"' || $c === "'";

        if (str_contains('&*!|>#?', $c)
            // Symfony reads flow collections with a stricter lexer than Inline, so only the simplest are taken.
            || $c === '{' && ! preg_match('/^\{ *\}$/', $v)
            || $c === '[' && ! preg_match('/^\[ *(?:[A-Za-z0-9_.\/-]+(?: *, *[A-Za-z0-9_.\/-]+)*)? *\]$/', $v)
            // Symfony rejects some plain values containing a colon.
            || ! $quoted && (str_contains($v, ': ') || str_ends_with($v, ':'))
        ) {
            $this->fail();
        }

        if ($quoted && ! preg_match('/^'.Inline::REGEX_QUOTED_STRING.'$/', $v)) {
            $this->fail(); // Multi-line, or followed by something; older Symfony's Inline lets that through.
        }

        try {
            $parsed = Inline::parse($v);
        } catch (\Throwable) {
            $this->fail(); // e.g. a quoted string spanning multiple lines.
        }

        if (is_object($parsed)) {
            $this->fail();
        }

        return $parsed;
    }
}
