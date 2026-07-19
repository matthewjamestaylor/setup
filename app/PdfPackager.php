<?php

declare(strict_types=1);

namespace Legends;

/**
 * Builds ONE password-protected PDF containing the whole submission:
 * the generated details-form pages followed by every uploaded document
 * (image uploads become full pages; uploaded PDFs are merged in).
 *
 * Merging and encryption are delegated to host tools:
 *   - qpdf (if available): AES-256 encryption — preferred.
 *   - ghostscript:          RC4-128 encryption (the strongest gs offers).
 * If neither tool is available, or a malformed uploaded PDF breaks the
 * merge, the caller is expected to fall back to the encrypted-ZIP Packager
 * so no submission is ever lost.
 */
final class PdfPackager
{
    public function __construct(private string $passphrase, private array $toolsCfg = [])
    {
    }

    /**
     * @return array ['path'=>string, 'filename'=>string, 'method'=>string, 'encrypted'=>bool]
     * @throws \RuntimeException when the merged/encrypted PDF cannot be produced
     */
    public function build(array $data, string $pdfPath, array $files, array $meta, string $workDir): array
    {
        if ($this->passphrase === '') {
            throw new \RuntimeException('Package passphrase is not configured.');
        }
        $gs = $this->findTool('gs', ['/usr/bin/gs', '/bin/gs', '/usr/local/bin/gs']);
        if ($gs === null) {
            throw new \RuntimeException('ghostscript is not available on this host.');
        }
        $qpdf = $this->findTool('qpdf', ['/usr/bin/qpdf', '/usr/local/bin/qpdf', '/bin/qpdf']);

        $person = Packager::personName($data);

        // Merge order: the form first, then documents in FieldMap order.
        // Every PDF input is page-counted up front: ghostscript exits 0 even
        // for corrupt PDFs (it "repairs" them into zero pages), so without
        // this accounting a broken upload would be dropped SILENTLY.
        $inputs = [$pdfPath];
        $expected = $this->pageCount($gs, $pdfPath);
        foreach ($files as $key => $f) {
            if (!is_file($f['path'])) {
                continue;
            }
            if ($f['ext'] === 'pdf') {
                $pages = $this->pageCount($gs, $f['path']);
                if ($pages < 1) {
                    throw new \RuntimeException("Uploaded PDF for {$key} has no readable pages.");
                }
                $inputs[] = $f['path'];
                $expected += $pages;
            } else {
                $inputs[] = $this->imagePagePdf($workDir, $key, $f, $data, $person);
                $expected += 1;
            }
        }

        $merged = "{$workDir}/_merged.pdf";
        $this->run(
            escapeshellarg($gs) . ' -dSAFER -dBATCH -dNOPAUSE -sDEVICE=pdfwrite -dCompatibilityLevel=1.5'
            . ' -sOutputFile=' . escapeshellarg($merged) . ' '
            . implode(' ', array_map('escapeshellarg', $inputs)),
            'merge the submission into one PDF'
        );
        $this->assertPdf($merged);
        $got = $this->pageCount($gs, $merged);
        if ($got !== $expected) {
            throw new \RuntimeException("Merged PDF has {$got} pages, expected {$expected} — a document would be lost.");
        }

        $outPath  = "{$workDir}/NHD - {$person}.pdf";
        $passArg  = escapeshellarg($this->passphrase);
        if ($qpdf !== null) {
            $this->run(
                escapeshellarg($qpdf) . " --encrypt {$passArg} {$passArg} 256 -- "
                . escapeshellarg($merged) . ' ' . escapeshellarg($outPath),
                'encrypt the PDF (qpdf/AES-256)'
            );
            $method = 'pdf-aes256';
        } else {
            $this->run(
                escapeshellarg($gs) . ' -dSAFER -dBATCH -dNOPAUSE -sDEVICE=pdfwrite'
                . " -sOwnerPassword={$passArg} -sUserPassword={$passArg}"
                . ' -dEncryptionR=3 -dKeyLength=128 -dPermissions=-4'
                . ' -sOutputFile=' . escapeshellarg($outPath) . ' ' . escapeshellarg($merged),
                'encrypt the PDF (ghostscript/RC4-128)'
            );
            $method = 'pdf-rc4-128';
        }
        $this->assertPdf($outPath);
        // The unencrypted intermediate must not outlive the request any longer
        // than necessary (the workDir shred also covers it).
        @unlink($merged);

        return [
            'path'      => $outPath,
            'filename'  => "NHD - {$person}.pdf",
            'method'    => $method,
            'encrypted' => true,
        ];
    }

    /** Render one image upload as a single captioned PDF page. */
    private function imagePagePdf(string $workDir, string $key, array $f, array $data, string $person): string
    {
        $info = @getimagesize($f['path']);
        if (!$info) {
            throw new \RuntimeException("Could not read image for {$key}.");
        }
        [$w, $h] = $info;

        $code = Packager::DOC_CODES[$key]
            ?? ($key === 'permit_document'
                ? ((($data['permit_type'] ?? '') === 'study') ? 'SP' : 'WP')
                : 'DOC');
        $caption = "{$code} — {$f['label']} — {$person}";

        $pdf = new \FPDF('P', 'mm', 'Letter');
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(120, 120, 120);
        $cap = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $caption) ?: preg_replace('/[^\x20-\x7E]/', '-', $caption);
        $pdf->Cell(0, 6, $cap, 0, 1);

        // Fit the image inside the printable box, preserving aspect ratio.
        $boxX = 12.0; $boxY = 20.0; $boxW = 215.9 - 24.0; $boxH = 279.4 - 32.0;
        $scale = min($boxW / $w, $boxH / $h);
        $dw = $w * $scale; $dh = $h * $scale;
        $pdf->Image($f['path'], $boxX + ($boxW - $dw) / 2, $boxY, $dw, $dh);

        $page = "{$workDir}/_img_{$key}.pdf";
        $pdf->Output('F', $page);
        if (!is_file($page) || filesize($page) < 200) {
            throw new \RuntimeException("Could not render the {$key} image page.");
        }
        return $page;
    }

    /**
     * Count a PDF's pages by asking ghostscript to process it against the
     * null device. A corrupt PDF prints no "Processing pages" line (while
     * still exiting 0), which surfaces here as an exception.
     */
    private function pageCount(string $gs, string $file): int
    {
        $out = [];
        $code = 1;
        @exec(
            escapeshellarg($gs) . ' -dSAFER -dBATCH -dNOPAUSE -sDEVICE=nullpage ' . escapeshellarg($file) . ' 2>&1',
            $out,
            $code
        );
        if ($code !== 0) {
            throw new \RuntimeException('A PDF in this submission could not be read (' . basename($file) . ').');
        }
        foreach ($out as $line) {
            if (preg_match('/Processing pages \d+ through (\d+)/', $line, $m)) {
                return (int) $m[1];
            }
        }
        throw new \RuntimeException('A PDF in this submission has no readable pages (' . basename($file) . ').');
    }

    private function findTool(string $name, array $candidates): ?string
    {
        $configured = (string) ($this->toolsCfg[$name] ?? '');
        if ($configured !== '' && is_executable($configured)) {
            return $configured;
        }
        foreach ($candidates as $c) {
            if (is_executable($c)) {
                return $c;
            }
        }
        $found = trim((string) @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        return ($found !== '' && is_executable($found)) ? $found : null;
    }

    private function run(string $cmd, string $what): void
    {
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $tail = implode(' | ', array_slice($out, -4));
            throw new \RuntimeException("Failed to {$what} (exit {$code}): {$tail}");
        }
    }

    private function assertPdf(string $path): void
    {
        if (!is_file($path) || filesize($path) < 1000) {
            throw new \RuntimeException('The combined PDF was not written correctly.');
        }
        $fh = fopen($path, 'rb');
        $head = $fh ? (string) fread($fh, 5) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($head !== '%PDF-') {
            throw new \RuntimeException('The combined output is not a valid PDF.');
        }
    }
}
