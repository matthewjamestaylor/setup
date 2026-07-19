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
    /**
     * Two-letter document codes used in attachment filenames, per HR's filing
     * convention: "<CODE> - <Employee Name>.<ext>" inside Attachments/.
     * permit_document maps to WP or SP depending on the permit type.
     */
    public const DOC_CODES = [
        'headshot'            => 'HS',
        'dd_document'         => 'DD',
        'sin_document'        => 'WE',
        'gov_document'        => 'ID',
        'ircc_document'       => 'EP',
        'smartserve_document' => 'SS',
        'foodsafety_document' => 'FS',
        'jhsc1_document'      => 'J1',
        'jhsc2_document'      => 'J2',
    ];

    public function __construct(private string $passphrase)
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
    public function build(array $data, string $pdfPath, array $files, array $meta, string $workDir): array
    {
        if ($this->passphrase === '') {
            throw new \RuntimeException('Package passphrase is not configured.');
        }
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('The PHP zip extension (ext-zip) is required to package submissions.');
        }

        $person = self::personName($data);
        $stem   = "NHD - {$person}";

        // Archive layout (HR filing convention):
        //   <Name>.pdf                         — the details form
        //   Attachments/<CODE> - <Name>.<ext>  — each uploaded document
        // Note: AES-ZIP leaves member names visible without the passphrase;
        // carrying the employee's name in them is HR's explicit choice.
        $members = [];
        $members[] = ['name' => "{$person}.pdf", 'src' => $pdfPath];
        foreach ($files as $key => $f) {
            $code = self::DOC_CODES[$key]
                ?? ($key === 'permit_document'
                    ? (($data['permit_type'] ?? '') === 'study' ? 'SP' : 'WP')
                    : strtoupper(substr($this->slug($f['label']), 0, 2)));
            $members[] = ['name' => "Attachments/{$code} - {$person}.{$f['ext']}", 'src' => $f['path']];
        }

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

    /** "First Last", stripped of filesystem-invalid characters. */
    public static function personName(array $data): string
    {
        $name = Support::oneLine(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
        $name = trim((string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/', '', $name));
        return $name === '' ? 'New Hire' : mb_substr($name, 0, 60);
    }

    private function slug(string $s): string
    {
        $s = preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        return $s === '' ? 'x' : substr($s, 0, 40);
    }
}
