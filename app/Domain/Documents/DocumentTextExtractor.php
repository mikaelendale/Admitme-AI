<?php

namespace App\Domain\Documents;

use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

/**
 * Reads the text of a stored document, page by page. Scanned images need OCR, which is a configurable
 * capability (`admitme.documents.ocr_driver`); when it is not configured the failure says so plainly.
 */
final class DocumentTextExtractor
{
    public const MAX_CHARS_PER_PAGE = 20000;

    public function __construct(private readonly PdfParser $pdf) {}

    /**
     * @return list<string> Text of each page (1-based order).
     *
     * @throws DocumentUnreadableException
     */
    public function pages(string $contents, string $mimeType): array
    {
        $pages = match (true) {
            $mimeType === 'application/pdf' => $this->pdfPages($contents),
            $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => [$this->docxText($contents)],
            str_starts_with($mimeType, 'text/') => [$contents],
            str_starts_with($mimeType, 'image/') => throw new DocumentUnreadableException($this->ocrUnavailableMessage()),
            default => throw new DocumentUnreadableException('This file type can\'t be read. Upload a PDF, Word (.docx) or text file.'),
        };

        $pages = array_map(fn (string $text) => mb_substr($this->clean($text), 0, self::MAX_CHARS_PER_PAGE), $pages);

        if (implode('', array_map('trim', $pages)) === '') {
            throw new DocumentUnreadableException(
                'No readable text was found in this file — it is probably a scan or photo. '
                .($this->ocrAvailable() ? '' : 'Text recognition (OCR) is not set up, so ').'enter the values manually or upload a PDF that contains text.',
            );
        }

        return array_values($pages);
    }

    public function ocrAvailable(): bool
    {
        return filled(config('admitme.documents.ocr_driver'));
    }

    /**
     * @return list<string>
     */
    private function pdfPages(string $contents): array
    {
        try {
            $document = $this->pdf->parseContent($contents);
        } catch (Throwable) {
            throw new DocumentUnreadableException('This PDF could not be opened. It may be damaged or password-protected.');
        }

        $pages = array_map(fn ($page) => (string) $page->getText(), $document->getPages());

        return $pages !== [] ? $pages : [(string) $document->getText()];
    }

    private function docxText(string $contents): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'admitme-docx');
        file_put_contents($temp, $contents);

        try {
            $zip = new ZipArchive;

            if ($zip->open($temp) !== true || ($xml = $zip->getFromName('word/document.xml')) === false) {
                throw new DocumentUnreadableException('This Word file could not be opened. Save it again as .docx or PDF.');
            }

            $zip->close();

            $xml = preg_replace(['/<\/w:p>/', '/<w:tab\/>/', '/<w:br\/>/'], ["\n", "\t", "\n"], $xml) ?? '';

            return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        } finally {
            @unlink($temp);
        }
    }

    private function clean(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return trim((string) preg_replace("/[ \t]+/", ' ', str_replace("\r", '', $text)));
    }

    private function ocrUnavailableMessage(): string
    {
        return $this->ocrAvailable()
            ? 'Image text recognition is configured but not implemented for this driver yet. Enter the values manually or upload a PDF that contains text.'
            : 'This is an image (a scan or photo). Text recognition (OCR) is not set up, so values can\'t be read automatically. Enter them manually, or upload a PDF that contains text.';
    }
}
