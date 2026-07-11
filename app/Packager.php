<?php

declare(strict_types=1);

namespace Legends;

/**
 * Bundles the generated PDF + all uploaded documents into a single
 * AES-256 encrypted ZIP that HR opens with a shared passphrase.
 *
 * If (and only if) the host's libzip lacks AES support, it falls back to a
 * plain ZIP encrypted as a whole with OpenSSL AES-256-GCM (accompanied by a
 * short decryption note). The chosen method is reported back to the caller.
 */
final class Packager
{
    public function __construct(private string $passphrase, private string $filenamePrefix)
    {
    }

    /**
     * @param array $data  validated scalar data (for naming)
     * @param string $pdfPath  path to the generated summary PDF
     * @param array $files  prepared upload files
     * @param array $meta  ['reference'=>.., 'timestamp'=>..]
     * @param string $workDir
     * @return array ['path'=>string, 'filename'=>string, 'method'=>string, 'encrypted'=>bool]
     */
    public function build(array $data, string $pdfPath, array $files, array $meta, string $workDir, string $textExport = ''): array
    {
        if ($this->passphrase === '') {
            throw new \RuntimeException('Package passphrase is not configured.');
        }
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('The PHP zip extension (ext-zip) is required to package submissions.');
        }

        $person = $this->personSlug($data);
        $date   = substr((string) ($meta['reference_date'] ?? date('Y-m-d')), 0, 10);
        $ref    = (string) ($meta['reference'] ?? Support::token(3));
        $stem   = "{$this->filenamePrefix}_{$person}_{$date}_{$ref}";

        // Ordered list of members to place in the archive.
        $members = [];
        $members[] = ['name' => 'Employee-Information.pdf', 'src' => $pdfPath];

        // AES-ZIP does not encrypt the central directory (filenames are visible
        // without the passphrase), so member names carry the document type only
        // — not the employee's name. The name lives inside the encrypted PDF and
        // manifest, and in the email subject/attachment for HR routing.
        $i = 1;
        foreach ($files as $f) {
            $labelSlug = $this->slug($f['label']);
            $name = sprintf('%02d_%s.%s', $i, $labelSlug, $f['ext']);
            $members[] = ['name' => $name, 'src' => $f['path']];
            $i++;
        }
        // Copy-paste-friendly plain-text summary (for Workday entry).
        if ($textExport !== '') {
            $members[] = ['name' => 'Employee-Information.txt', 'string' => $textExport];
        }
        // Human-readable manifest inside the archive.
        $members[] = ['name' => 'README.txt', 'string' => $this->manifest($data, $files, $meta)];

        $aesAvailable = defined('ZipArchive::EM_AES_256') && class_exists('ZipArchive');

        if ($aesAvailable) {
            $zipPath = "{$workDir}/{$stem}.zip";
            $this->buildAesZip($zipPath, $members);
            return [
                'path'      => $zipPath,
                'filename'  => "{$stem}.zip",
                'method'    => 'zip-aes256',
                'encrypted' => true,
            ];
        }

        // Fallback: plain zip → OpenSSL AES-256-GCM envelope.
        $plainZip = "{$workDir}/_plain_{$stem}.zip";
        $this->buildPlainZip($plainZip, $members);
        $encPath = "{$workDir}/{$stem}.zip.enc";
        $this->opensslEncryptFile($plainZip, $encPath);
        @unlink($plainZip);
        return [
            'path'      => $encPath,
            'filename'  => "{$stem}.zip.enc",
            'method'    => 'openssl-aes256gcm',
            'encrypted' => true,
        ];
    }

    private function buildAesZip(string $zipPath, array $members): void
    {
        @unlink($zipPath);
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the encrypted archive.');
        }
        $zip->setPassword($this->passphrase);
        foreach ($members as $m) {
            if (isset($m['string'])) {
                $zip->addFromString($m['name'], $m['string']);
            } else {
                if (!is_file($m['src'])) {
                    continue;
                }
                $zip->addFile($m['src'], $m['name']);
            }
            $zip->setEncryptionName($m['name'], \ZipArchive::EM_AES_256);
        }
        if ($zip->close() !== true) {
            throw new \RuntimeException('Failed to finalise the encrypted archive.');
        }
        if (!is_file($zipPath) || filesize($zipPath) < 100) {
            throw new \RuntimeException('The encrypted archive was not written correctly.');
        }
    }

    private function buildPlainZip(string $zipPath, array $members): void
    {
        @unlink($zipPath);
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the archive.');
        }
        foreach ($members as $m) {
            if (isset($m['string'])) {
                $zip->addFromString($m['name'], $m['string']);
            } elseif (is_file($m['src'])) {
                $zip->addFile($m['src'], $m['name']);
            }
        }
        $zip->close();
    }

    /**
     * Encrypt a file with AES-256-GCM. Output format (binary):
     *   "LGENC1\n" | salt(16) | iv(12) | tag(16) | ciphertext
     * Key = PBKDF2-SHA256(passphrase, salt, 200000, 32).
     */
    private function opensslEncryptFile(string $src, string $dest): void
    {
        $plain = (string) file_get_contents($src);
        $salt  = random_bytes(16);
        $iv    = random_bytes(12);
        $key   = hash_pbkdf2('sha256', $this->passphrase, $salt, 200000, 32, true);
        $tag   = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        file_put_contents($dest, "LGENC1\n" . $salt . $iv . $tag . $ct);
    }

    private function manifest(array $data, array $files, array $meta): string
    {
        $name = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
        $lines = [];
        $lines[] = 'LEGENDS GLOBAL — New Hire Onboarding Submission';
        $lines[] = str_repeat('=', 48);
        $lines[] = 'Employee : ' . $name;
        $lines[] = 'Submitted: ' . ($meta['timestamp'] ?? '');
        $lines[] = 'Reference: ' . ($meta['reference'] ?? '');
        $lines[] = '';
        $lines[] = 'This archive is CONFIDENTIAL and encrypted. It contains:';
        $lines[] = '  - Employee-Information.pdf  (full submission summary)';
        foreach ($files as $f) {
            $lines[] = '  - ' . $f['label'] . ' (' . strtoupper($f['ext']) . ')';
        }
        $lines[] = '';
        $lines[] = 'Handle in accordance with the Legends Global privacy policy and';
        $lines[] = 'applicable privacy legislation (PIPEDA / Ontario). Store only in';
        $lines[] = 'approved secure systems and limit access to authorized personnel.';
        return implode("\r\n", $lines);
    }

    private function personSlug(array $data): string
    {
        $last  = $this->slug((string) ($data['last_name'] ?? ''));
        $first = $this->slug((string) ($data['first_name'] ?? ''));
        $slug = trim($last . '-' . $first, '-');
        return $slug === '' ? 'NewHire' : $slug;
    }

    private function slug(string $s): string
    {
        $s = preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        return $s === '' ? 'x' : substr($s, 0, 40);
    }
}
