<?php
declare(strict_types=1);

// Pure CSV helpers shared by every import flow (multistep import UX per
// docs/php-guidelines.md): parse the pasted/uploaded text, suggest a
// column-to-field mapping, and apply a chosen mapping. No database access.
class CsvImport {

    /**
     * Parse CSV text into headers + rows. $delimiter: ',' or "\t".
     * The first line is the header row. Returns
     * ['headers' => string[], 'rows' => string[][]] with every row padded /
     * truncated to the header width. Blank lines are skipped.
     */
    public static function parseCsv(string $text, string $delimiter = ','): array {
        if (!in_array($delimiter, [',', "\t"], true)) {
            throw new InvalidArgumentException('Delimiter must be a comma or a tab.');
        }
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            throw new InvalidArgumentException('The import file is empty.');
        }

        $lines = explode("\n", $text);
        $headers = null;
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $fields = str_getcsv($line, $delimiter, '"', '\\');
            $fields = array_map(fn($f) => trim((string)$f), $fields);
            if ($headers === null) {
                $headers = $fields;
                continue;
            }
            $width = count($headers);
            $fields = array_slice(array_pad($fields, $width, ''), 0, $width);
            $rows[] = $fields;
        }
        if ($headers === null || count(array_filter($headers, fn($h) => $h !== '')) === 0) {
            throw new InvalidArgumentException('No header row found.');
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Suggest which target field each CSV column maps to, by normalized-name
     * matching ("Cell Phone Number" -> cell_phone_number). $targetFields is
     * [field => label]. Returns one entry per header: the field name or ''
     * (unmapped). Each target field is suggested at most once.
     */
    public static function suggestColumnMapping(array $headers, array $targetFields): array {
        $normalizedTargets = [];
        foreach ($targetFields as $field => $label) {
            $normalizedTargets[self::normalizeName((string)$field)] = (string)$field;
            $normalizedTargets[self::normalizeName((string)$label)] = (string)$field;
        }

        $mapping = [];
        $used = [];
        foreach ($headers as $header) {
            $field = $normalizedTargets[self::normalizeName((string)$header)] ?? '';
            if ($field !== '' && isset($used[$field])) {
                $field = '';
            }
            if ($field !== '') {
                $used[$field] = true;
            }
            $mapping[] = $field;
        }
        return $mapping;
    }

    /**
     * Apply a mapping (one target field or '' per column) to raw rows,
     * producing assoc rows keyed by target field. Unmapped columns drop out.
     */
    public static function applyMapping(array $rows, array $mapping): array {
        $out = [];
        foreach ($rows as $row) {
            $assoc = [];
            foreach ($mapping as $i => $field) {
                if ($field === '' || $field === null) {
                    continue;
                }
                $assoc[$field] = trim((string)($row[$i] ?? ''));
            }
            $out[] = $assoc;
        }
        return $out;
    }

    /** "Cell Phone #1" -> "cellphone1" (case/punctuation-insensitive key). */
    private static function normalizeName(string $name): string {
        return preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?? '';
    }
}
