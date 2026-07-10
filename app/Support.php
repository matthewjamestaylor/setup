<?php

declare(strict_types=1);

namespace Legends;

/**
 * Small stateless helpers used across the app.
 */
final class Support
{
    /** Normalise a scalar input: trim, drop control chars, cap length. */
    public static function clean(?string $v, int $maxLen = 2000): string
    {
        if ($v === null) {
            return '';
        }
        // Remove NULs and other C0 control characters except tab/newline.
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        // Normalise newlines, trim outer whitespace.
        $v = str_replace(["\r\n", "\r"], "\n", $v);
        $v = trim($v);
        if (mb_strlen($v) > $maxLen) {
            $v = mb_substr($v, 0, $maxLen);
        }
        return $v;
    }

    /** Collapse internal runs of whitespace (for single-line fields). */
    public static function oneLine(string $v): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $v));
    }

    /** HTML-escape for safe output. */
    public static function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** SIN / card Luhn check on a digit string. */
    public static function luhn(string $digits): bool
    {
        if ($digits === '' || !ctype_digit($digits)) {
            return false;
        }
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }
        return $sum % 10 === 0;
    }

    /** Validate a strict Y-m-d calendar date; returns \DateTimeImmutable|null. */
    public static function parseDate(string $v): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        if (!checkdate($m, $d, $y)) {
            return null;
        }
        try {
            return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d));
        } catch (\Exception) {
            return null;
        }
    }

    /** HH:MM 24-hour → minutes since midnight, or null if invalid. */
    public static function parseTime(string $v): ?int
    {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $v, $m)) {
            return null;
        }
        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    /** Digits-only version of a phone number. */
    public static function phoneDigits(string $v): string
    {
        return preg_replace('/\D+/', '', $v) ?? '';
    }

    /** Pretty-print a 10-digit North American phone number. */
    public static function formatPhone(string $v): string
    {
        $d = self::phoneDigits($v);
        if (strlen($d) === 11 && $d[0] === '1') {
            $d = substr($d, 1);
        }
        if (strlen($d) === 10) {
            return sprintf('(%s) %s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6));
        }
        return $v; // leave unusual formats untouched
    }

    /** Random filesystem-safe token. */
    public static function token(int $bytes = 8): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Make an arbitrary string safe for use as a file name. */
    public static function safeFilename(string $name, string $fallback = 'file'): string
    {
        $name = basename($name);
        $ext  = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $base = (string) pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?? '';
        $base = trim($base, '._-');
        if ($base === '') {
            $base = $fallback;
        }
        $base = substr($base, 0, 60);
        $ext  = preg_replace('/[^a-z0-9]+/', '', $ext) ?? '';
        return $ext !== '' ? "{$base}.{$ext}" : $base;
    }

    // ---- Anti-abuse form token (stateless, HMAC-signed timestamp) --------

    private static function formSecret(): string
    {
        $p = (string) (\cfg('package.passphrase') ?: '');
        return hash('sha256', 'legends-onboarding-form|' . $p);
    }

    /** Create "timestamp.signature" to embed in the form. */
    public static function makeFormToken(int $ts): string
    {
        return $ts . '.' . hash_hmac('sha256', (string) $ts, self::formSecret());
    }

    /** Verify a form token: valid signature and age within [min,max] seconds. */
    public static function checkFormToken(string $token, int $minAge, int $maxAge, int $now): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$ts, $sig] = $parts;
        if (!ctype_digit($ts)) {
            return false;
        }
        $expected = hash_hmac('sha256', $ts, self::formSecret());
        if (!hash_equals($expected, $sig)) {
            return false;
        }
        $age = $now - (int) $ts;
        return $age >= $minAge && $age <= $maxAge;
    }

    /** Best-effort secure delete: overwrite the FULL length, then unlink. */
    public static function shred(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $size = @filesize($path);
        if ($size !== false && $size > 0 && $size <= 64 * 1024 * 1024) {
            $fh = @fopen($path, 'r+b');
            if ($fh) {
                $remaining = $size;
                while ($remaining > 0) {
                    $n = (int) min(1024 * 1024, $remaining);
                    @fwrite($fh, random_bytes($n));
                    $remaining -= $n;
                }
                @fflush($fh);
                @fclose($fh);
            }
        }
        @unlink($path);
    }

    /**
     * Remove stale working directories left behind by workers that were killed
     * (OOM, timeout, container stop) before their shutdown handler ran.
     */
    public static function sweepStale(string $glob, int $maxAgeSeconds): void
    {
        $cutoff = time() - $maxAgeSeconds;
        foreach (glob($glob, GLOB_ONLYDIR) ?: [] as $dir) {
            $mt = @filemtime($dir);
            if ($mt !== false && $mt < $cutoff) {
                self::shredDir($dir);
            }
        }
    }

    /** Shred every file in a working directory, then remove it. */
    public static function shredDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $f) {
            if (is_file($f)) {
                self::shred($f);
            }
        }
        @rmdir($dir);
    }

    public static function bytesHuman(int $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $b;
        while ($n >= 1024 && $i < count($u) - 1) {
            $n /= 1024;
            $i++;
        }
        return round($n, $n < 10 && $i > 0 ? 1 : 0) . ' ' . $u[$i];
    }
}
