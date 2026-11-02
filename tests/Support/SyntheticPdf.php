<?php

namespace Tests\Support;

/**
 * Builds small, valid text PDFs for tests (one Helvetica text block per page). Synthetic content only.
 */
final class SyntheticPdf
{
    /**
     * @param  list<list<string>>  $pages  Lines of text for each page; an empty list makes a page with no text layer.
     */
    public static function make(array $pages): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $kids = [];
        $next = 4;

        foreach ($pages as $lines) {
            $pageObject = $next++;
            $contentObject = $next++;

            $stream = "BT /F1 12 Tf 72 720 Td 16 TL\n";
            foreach ($lines as $line) {
                $stream .= '('.self::escape($line).") Tj T*\n";
            }
            $stream .= 'ET';

            $objects[$contentObject] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
            $objects[$pageObject] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents {$contentObject} 0 R /Resources << /Font << /F1 3 0 R >> >> >>";
            $kids[] = "{$pageObject} 0 R";
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
