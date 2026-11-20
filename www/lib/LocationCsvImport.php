<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/LocationManagement.php';

// The locations CSV upload (setup step 1): one row per teaching location.
// Rows are matched to existing locations by name (case-insensitive), so
// re-importing updates addresses instead of duplicating.
class LocationCsvImport {

    public static function targetFields(): array {
        return [
            'name' => 'Location Name',
            'address' => 'Address',
            'status' => 'Status',
            'online' => 'Online',
        ];
    }

    public static function validateRows(array $mappedRows, array $context = []): array {
        $existing = self::locationsByNormalizedName();

        $out = [];
        $seen = [];
        foreach ($mappedRows as $i => $row) {
            $messages = [];
            $status = 'valid';
            $changes = '';

            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                $status = 'error';
                $messages[] = 'Location name is required.';
            }

            $rowStatus = strtolower(trim((string)($row['status'] ?? '')));
            if ($rowStatus === '') {
                $rowStatus = 'active';
            }
            if (!in_array($rowStatus, ['active', 'inactive'], true)) {
                $status = 'error';
                $messages[] = 'Status must be "active" or "inactive" (blank means active).';
            }

            // Optional: yes marks a location taught by video (no address).
            $onlineRaw = strtolower(trim((string)($row['online'] ?? '')));
            $isOnline = null;
            if ($onlineRaw !== '') {
                if (in_array($onlineRaw, ['yes', 'y', 'true', '1', 'online'], true)) {
                    $isOnline = true;
                } elseif (in_array($onlineRaw, ['no', 'n', 'false', '0'], true)) {
                    $isOnline = false;
                } else {
                    $status = 'error';
                    $messages[] = 'Online must be "yes" or "no" (blank means no change).';
                }
            }

            $norm = self::norm($name);
            if ($status !== 'error' && isset($seen[$norm])) {
                $status = 'error';
                $messages[] = 'Duplicate location within the file (row ' . $seen[$norm] . ').';
            }

            if ($status !== 'error') {
                $seen[$norm] = $i + 1;
                $match = $existing[$norm] ?? null;
                if ($match) {
                    $changes = 'Update existing location (' . $match['name'] . ')';
                    $row['_match_location_id'] = (int)$match['id'];
                } else {
                    $changes = 'Create location';
                }
                $row['_status'] = $rowStatus;
                $row['_is_online'] = $isOnline;
            }

            $out[] = [
                'row' => $i + 1,
                'data' => $row,
                'status' => $status,
                'changes' => $changes,
                'messages' => $messages,
            ];
        }
        return $out;
    }

    public static function commit(?UserContext $ctx, array $validatedRows, array $context = []): array {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($validatedRows as $entry) {
            if (($entry['status'] ?? '') !== 'valid') {
                $skipped++;
                continue;
            }
            $row = $entry['data'];
            $name = trim((string)$row['name']);
            $address = trim((string)($row['address'] ?? ''));
            $isActive = ($row['_status'] ?? 'active') === 'active';
            $locationId = (int)($row['_match_location_id'] ?? 0);

            if ($locationId > 0) {
                // Keep the existing (canonical) name — it's the identity key
                // the other CSVs reference; a case-different spelling in this
                // file shouldn't silently rename it.
                $current = LocationManagement::find($locationId);
                LocationManagement::update(
                    $ctx,
                    $locationId,
                    (string)$current['name'],
                    $address !== '' ? $address : ($current['address'] ?? null),
                    $isActive,
                    $row['_is_online'] ?? null
                );
                $updated++;
            } else {
                $newId = LocationManagement::create($ctx, $name, $address !== '' ? $address : null, (bool)($row['_is_online'] ?? false));
                if (!$isActive) {
                    LocationManagement::update($ctx, $newId, $name, $address !== '' ? $address : null, false);
                }
                $created++;
            }
        }

        self::log($ctx, 'import.locations_committed', ['created' => $created, 'updated' => $updated, 'skipped' => $skipped]);
        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    // ── internals ─────────────────────────────────────────────────────────

    private static function locationsByNormalizedName(): array {
        $out = [];
        foreach (LocationManagement::all() as $location) {
            $out[self::norm((string)$location['name'])] = $location;
        }
        return $out;
    }

    private static function norm(string $name): string {
        return preg_replace('/\s+/', ' ', strtolower(trim($name))) ?? '';
    }

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
