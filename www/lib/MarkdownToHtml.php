<?php
declare(strict_types=1);

class MarkdownToHtml {
    public static function convert(string $markdown): string {
        $html = $markdown;

        // Split on paragraph breaks (blank lines)
        $paragraphs = preg_split('/\n\s*\n/', trim($html));
        $html = '';

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }

            // Check if it's an HTML block (already has HTML tags)
            if (preg_match('/^</', $para)) {
                $html .= $para . "\n";
            }
            // Check if it's a list
            elseif (preg_match('/^[\s]*[-*]\s/', $para)) {
                $html .= self::convertList($para);
            }
            // Check if it's a heading
            elseif (preg_match('/^#+\s/', $para)) {
                $html .= self::convertHeading($para);
            }
            // Regular paragraph
            else {
                $html .= self::convertParagraph($para);
            }
        }

        return $html;
    }

    private static function convertParagraph(string $para): string {
        $html = self::convertInline($para);
        return "<p>$html</p>\n";
    }

    private static function convertHeading(string $heading): string {
        if (preg_match('/^(#{1,6})\s+(.+)$/', $heading, $matches)) {
            $level = strlen($matches[1]);
            $text = self::convertInline($matches[2]);
            return "<h$level>$text</h$level>\n";
        }
        return "<p>$heading</p>\n";
    }

    private static function convertList(string $list): string {
        $lines = explode("\n", $list);
        $html = "<ul>\n";

        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^[-*]\s+(.+)$/', $line, $matches)) {
                $text = self::convertInline($matches[1]);
                $html .= "<li>$text</li>\n";
            }
        }

        $html .= "</ul>\n";
        return $html;
    }

    private static function convertInline(string $text): string {
        // Note: values are already HTML-escaped by substitute(), so we don't escape
        // the captured content from markdown patterns. We only escape literal text
        // typed by the admin that's not part of a substitution.

        // Links: [text](url) - text and URL are already escaped if from {{variables}}
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            fn($m) => '<a href="' . $m[2] . '">' . $m[1] . '</a>',
            $text
        );

        // Bold: **text** - content is already escaped
        $text = preg_replace_callback(
            '/\*\*([^*]+)\*\*/',
            fn($m) => '<strong>' . $m[1] . '</strong>',
            $text
        );

        // Italic: *text* (but not if it's part of **) - content is already escaped
        $text = preg_replace_callback(
            '/(?<!\*)\*([^*]+)\*(?!\*)/',
            fn($m) => '<em>' . $m[1] . '</em>',
            $text
        );

        // Single newlines become <br> within a paragraph
        // Note: substitute() already escaped $text, but we need to handle the
        // newlines. Using htmlentities false to avoid double-escaping.
        $text = nl2br($text, false);

        return $text;
    }
}
