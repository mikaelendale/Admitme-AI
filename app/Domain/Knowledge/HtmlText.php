<?php

namespace App\Domain\Knowledge;

/**
 * Plain readable text from a fetched page, and passage chunking for search. Page content is data only.
 */
final class HtmlText
{
    public static function title(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match) !== 1) {
            return null;
        }

        $title = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $title !== '' ? mb_substr($title, 0, 300) : null;
    }

    public static function text(string $html): string
    {
        $html = (string) preg_replace('/<(script|style|noscript|svg|template|iframe)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        $html = (string) preg_replace('/<!--.*?-->/s', ' ', $html);
        $html = (string) preg_replace('/<(br|hr)\b[^>]*>/i', "\n", $html);
        $html = (string) preg_replace('/<\/(p|div|li|h[1-6]|tr|section|article|header|footer|table|ul|ol|dd|dt|blockquote)>/i', "\n\n", $html);
        $html = (string) preg_replace('/<(td|th)\b[^>]*>/i', ' | ', $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
        $text = (string) preg_replace("/ *\n */", "\n", $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * @return list<string>
     */
    public static function passages(string $text, int $maxChars = 1000): array
    {
        $chunks = [];
        $current = '';

        foreach (preg_split("/\n{2,}/", $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if ($current !== '' && mb_strlen($current) + mb_strlen($paragraph) + 2 > $maxChars) {
                $chunks[] = $current;
                $current = '';
            }

            // A single long paragraph is split on sentence boundaries...
            while (mb_strlen($paragraph) > $maxChars) {
                $cut = mb_strrpos(mb_substr($paragraph, 0, $maxChars), '. ') ?: $maxChars;
                $chunks[] = trim(mb_substr($paragraph, 0, $cut + 1));
                $paragraph = trim(mb_substr($paragraph, $cut + 1));
            }

            $current = $current === '' ? $paragraph : $current."\n\n".$paragraph;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
