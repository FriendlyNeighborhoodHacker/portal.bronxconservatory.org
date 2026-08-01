<?php
declare(strict_types=1);

/**
 * LogViewer — read-only access to server log files for the admin
 * Server Logs pages (/admin/l/).
 *
 * Logs are identified by a fixed slug; the request never supplies a path.
 * Default paths assume the production layout under /home/lillydebate and can
 * be overridden per machine with the ADMIN_LOG_FILES constant in
 * config.local.php (slug => absolute path).
 *
 * Files can be huge, so reads are tail-style: at most one CHUNK_BYTES read
 * per request, paged by byte offset from the end of the file.
 */
final class LogViewer
{
    /** Bytes shown per page of the viewer. */
    public const CHUNK_BYTES = 65536;

    private const DEFAULT_LOGS = [
        'deploy-webhook' => [
            'label' => 'Deploy Webhook',
            'path'  => '/home/lillydebate/deploy_scripts/logs/portal.bronxconservatory.org.webhook.log',
        ],
        'apache-access' => [
            'label' => 'Apache Access',
            'path'  => '/home/lillydebate/logs/portal.bronxconservatory.org/http/access.log',
        ],
        'apache-error' => [
            'label' => 'Apache Error',
            'path'  => '/home/lillydebate/logs/portal.bronxconservatory.org/http/error.log',
        ],
        'php-error' => [
            'label' => 'PHP Error',
            'path'  => '/home/lillydebate/logs/portal.bronxconservatory.org/php/error.log',
        ],
    ];

    /**
     * All viewable logs, keyed by slug: ['label' => ..., 'path' => ...].
     * Paths can be overridden per machine via ADMIN_LOG_FILES in
     * config.local.php; labels always come from the built-in definitions.
     */
    public static function logs(): array
    {
        $logs = self::DEFAULT_LOGS;
        $overrides = defined('ADMIN_LOG_FILES') ? ADMIN_LOG_FILES : [];
        if (is_array($overrides)) {
            foreach ($overrides as $slug => $path) {
                if (isset($logs[$slug]) && is_string($path) && $path !== '') {
                    $logs[$slug]['path'] = $path;
                }
            }
        }
        return $logs;
    }

    /** One log definition by slug, or null if the slug is unknown. */
    public static function get(string $slug): ?array
    {
        return self::logs()[$slug] ?? null;
    }

    /**
     * File status for the index page:
     * ['exists' => bool, 'readable' => bool, 'size' => int, 'mtime' => int].
     * Errors (missing file, permissions, open_basedir) degrade to
     * exists/readable = false rather than warnings.
     */
    public static function stat(string $path): array
    {
        clearstatcache();
        $exists   = @is_file($path);
        $readable = $exists && @is_readable($path);
        return [
            'exists'   => $exists,
            'readable' => $readable,
            'size'     => $readable ? (int)@filesize($path) : 0,
            'mtime'    => $readable ? (int)@filemtime($path) : 0,
        ];
    }

    /**
     * Read one page of a log file, ending at byte offset $end (null = end of
     * file). Reads at most CHUNK_BYTES with a single fseek/fread — never the
     * whole file.
     *
     * Paging contract: the returned 'start' is aligned to a line boundary
     * (the partial first line of the chunk is dropped), so passing it back
     * as the next request's $end yields contiguous pages with no gaps or
     * duplicated lines. 'noNewline' is set when a single line exceeds the
     * chunk size and the raw chunk is returned as-is.
     *
     * @return array{ok: bool, error: ?string, text: string, start: int,
     *               end: int, size: int, clamped: bool, noNewline: bool}
     */
    public static function tail(string $path, ?int $end = null): array
    {
        $result = [
            'ok'        => false,
            'error'     => null,
            'text'      => '',
            'start'     => 0,
            'end'       => 0,
            'size'      => 0,
            'clamped'   => false,
            'noNewline' => false,
        ];

        clearstatcache();
        if (!@is_file($path)) {
            $result['error'] = 'File not found.';
            return $result;
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            $result['error'] = 'File is not readable (permission denied?).';
            return $result;
        }

        try {
            $fstat = fstat($fh);
            $size  = (int)($fstat['size'] ?? 0);
            $result['size'] = $size;

            if ($end === null) {
                $end = $size;
            } else {
                // A saved offset past EOF means the file was rotated or
                // truncated since the link was rendered — jump to newest.
                if ($end > $size) {
                    $result['clamped'] = true;
                }
                $end = min(max(0, $end), $size);
            }

            $start = max(0, $end - self::CHUNK_BYTES);
            $text  = '';
            if ($end > $start) {
                fseek($fh, $start);
                $text = (string)fread($fh, $end - $start);
            }
        } finally {
            fclose($fh);
        }

        // Align the top of the chunk to a line boundary so consecutive
        // pages abut cleanly. At start === 0 the chunk already begins at
        // the true start of the file.
        if ($start > 0 && $text !== '') {
            $nl = strpos($text, "\n");
            if ($nl !== false) {
                $text   = substr($text, $nl + 1);
                $start += $nl + 1;
            } else {
                // One line longer than the whole chunk — show it raw.
                $result['noNewline'] = true;
            }
        }

        $result['ok']    = true;
        $result['text']  = $text;
        $result['start'] = $start;
        $result['end']   = $end;
        return $result;
    }

    /** Human-readable file size, e.g. "1.4 MB". */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = (float)$bytes;
        $unit  = 'B';
        foreach ($units as $u) {
            $value /= 1024;
            $unit   = $u;
            if ($value < 1024) {
                break;
            }
        }
        return sprintf($value >= 100 ? '%.0f %s' : '%.1f %s', $value, $unit);
    }
}
