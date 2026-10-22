<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

final class TransactionSavepointTracker
{
    private bool $active = false;
    private array $markers = [];

    public function boundary(bool $active): void
    {
        $this->active = $active;
        $this->markers = [];
    }

    public function marker(string $name, bool $active): object
    {
        $name = self::name($name);
        if (!$active || !$this->active || $name === null || !isset($this->markers[$name])) {
            throw new \LogicException('Transaction savepoint is not tracked.');
        }
        return $this->markers[$name];
    }

    public function contains(object $marker, bool $active): bool
    {
        return $active && $this->active && in_array($marker, $this->markers, true);
    }

    public function statement(string $sql, bool $before, bool $after): void
    {
        if (!$before || !$this->active || !$after) $this->boundary($after);
        if (!$after) return;
        if ($this->markers === [] && stripos($sql, 'SAVEPOINT') === false) return;
        $tokens = self::tokens($sql);
        if ($tokens === null || $tokens === []) { $this->boundary($after); return; }
        if (end($tokens) === ['symbol', ';']) array_pop($tokens);
        if ($tokens === []) { $this->boundary($after); return; }
        if (array_any($tokens, static fn (array $token): bool => $token === ['symbol', ';'])) { $this->boundary($after); return; }
        $head = $tokens[0][0] === 'word' ? strtoupper($tokens[0][1]) : '';
        $words = array_map(static fn (array $token): string => in_array($token[0], ['word', 'identifier'], true) ? strtoupper($token[1]) : '', $tokens);
        if ($head === 'SAVEPOINT' && count($tokens) === 2 && ($name = self::identifier($tokens[1])) !== null) {
            unset($this->markers[$name]);
            $this->markers[$name] = new \stdClass();
            return;
        }
        if ($head === 'RELEASE' && count($tokens) === 3 && $words[1] === 'SAVEPOINT' && ($name = self::identifier($tokens[2])) !== null) {
            $this->trim($name, false, true);
            return;
        }
        if ($head === 'ROLLBACK') {
            $index = ($words[1] ?? '') === 'WORK' ? 2 : 1;
            if (($words[$index] ?? '') === 'TO') {
                $index++;
                if (($words[$index] ?? '') === 'SAVEPOINT') $index++;
                if (count($tokens) === $index + 1 && ($name = self::identifier($tokens[$index])) !== null) {
                    $this->trim($name, true, true);
                    return;
                }
            }
            $this->boundary($after);
            return;
        }
        if (in_array($head, ['BEGIN', 'START', 'COMMIT', 'SAVEPOINT', 'RELEASE', 'XA', 'CALL', 'EXECUTE'], true)) {
            $this->boundary($after);
            return;
        }
        if (!self::ordinaryStatement($head, $words)) $this->boundary($after);
    }

    public function failedStatement(string $sql, bool $before, bool $after, ?array $errorInfo = null): void
    {
        if (($before && !$after) || (int) ($errorInfo[1] ?? 0) === 1213) { $this->boundary($after); return; }
        if ($this->markers === []) return;
        $tokens = self::tokens($sql);
        if ($tokens === null) { $this->boundary($after); return; }
        if ($tokens !== [] && end($tokens) === ['symbol', ';']) array_pop($tokens);
        if ($tokens === [] || array_any($tokens, static fn (array $token): bool => $token === ['symbol', ';'])) { $this->boundary($after); return; }
        $head = $tokens[0][0] === 'word' ? strtoupper($tokens[0][1]) : '';
        $words = array_map(static fn (array $token): string => in_array($token[0], ['word', 'identifier'], true) ? strtoupper($token[1]) : '', $tokens);
        if ($head === 'SAVEPOINT' && count($tokens) === 2 && self::identifier($tokens[1]) !== null) return;
        if ($head === 'RELEASE' && count($tokens) === 3 && $words[1] === 'SAVEPOINT' && self::identifier($tokens[2]) !== null) return;
        if (!self::ordinaryStatement($head, $words)) $this->boundary($after);
    }

    private static function ordinaryStatement(string $head, array $words): bool
    {
        if ($head === 'SET') return array_intersect($words, ['AUTOCOMMIT', 'TRANSACTION', 'SQL_MODE']) === [];
        if (in_array($head, ['CREATE', 'DROP'], true) && ($words[1] ?? '') === 'TEMPORARY' && ($words[2] ?? '') === 'TABLE') return true;
        return in_array($head, ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'WITH', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'DO', 'VALUES', 'TABLE'], true);
    }

    private function trim(string $name, bool $keep, bool $required = false): void
    {
        $position = array_search($name, array_map('strval', array_keys($this->markers)), true);
        if ($position === false) {
            if ($required) $this->markers = [];
            return;
        }
        $this->markers = array_slice($this->markers, 0, $position + (int) $keep, true);
    }

    private static function identifier(array $token): ?string
    {
        return in_array($token[0], ['word', 'identifier'], true) ? self::name($token[1]) : null;
    }

    private static function name(string $name): ?string
    {
        return $name !== '' && preg_match('/^[A-Za-z0-9_$]+$/D', $name) === 1 ? strtolower($name) : null;
    }

    private static function tokens(string $sql): ?array
    {
        $out = [];
        $length = strlen($sql);
        for ($index = 0; $index < $length;) {
            $char = $sql[$index];
            if (ctype_space($char)) { $index++; continue; }
            if ($char === '#' || ($char === '-' && substr($sql, $index, 2) === '--' && ($index + 2 === $length || ord($sql[$index + 2]) <= 32))) {
                $end = strpos($sql, "\n", $index);
                $index = $end === false ? $length : $end + 1;
                continue;
            }
            if (substr($sql, $index, 2) === '/*') {
                if (substr($sql, $index, 3) === '/*!' || strcasecmp(substr($sql, $index, 4), '/*M!') === 0) return null;
                $end = strpos($sql, '*/', $index + 2);
                if ($end === false) return null;
                $index = $end + 2;
                continue;
            }
            if (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
                $value = '';
                $closed = false;
                for ($index++; $index < $length; $index++) {
                    $char = $sql[$index];
                    if ($char === '\\') return null;
                    if ($char === $quote) {
                        if (($sql[$index + 1] ?? '') === $quote) { $value .= $quote; $index++; continue; }
                        $index++;
                        $closed = true;
                        break;
                    }
                    $value .= $char;
                }
                if (!$closed) return null;
                $out[] = [$quote === '`' ? 'identifier' : 'literal', $value];
                continue;
            }
            if (preg_match('/\G[A-Za-z0-9_$]+/A', $sql, $match, 0, $index) === 1) {
                $out[] = ['word', $match[0]];
                $index += strlen($match[0]);
                continue;
            }
            $out[] = ['symbol', $char];
            $index++;
        }
        return $out;
    }
}
