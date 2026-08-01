<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/UserManagement.php';
require_once __DIR__ . '/StudentTeacherManagement.php';

// The teacher CSV upload (bootstrap step 2): matches rows to existing users
// by email, then by normalized cell phone; creates or updates teachers.
class TeacherCsvImport {

    /** field => human label (the mapping step's dropdown options). */
    public static function targetFields(): array {
        return [
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'suffix' => 'Suffix',
            'preferred_name' => 'Preferred Name',
            'email' => 'Email',
            'cell_phone' => 'Cell Phone Number',
            'secondary_email' => 'Secondary Email',
            'home_phone' => 'Home Phone',
            'address_street_1' => 'Address Street 1',
            'address_street_2' => 'Address Street 2',
            'address_city' => 'Address City',
            'address_state' => 'Address State',
            'address_zip' => 'Address Zip',
            'emergency_contact_name' => 'Emergency Contact Name',
            'emergency_contact_phone' => 'Emergency Contact Phone',
            'shirt_size' => 'Shirt Size',
        ];
    }

    /**
     * Validate mapped rows. Returns one entry per row:
     * ['row' => n, 'data' => [...], 'status' => 'valid'|'error',
     *  'changes' => '...', 'messages' => [...]]
     */
    public static function validateRows(array $mappedRows, array $context = []): array {
        $out = [];
        $seenEmails = [];
        foreach ($mappedRows as $i => $row) {
            $messages = [];
            $status = 'valid';
            $changes = '';

            $first = trim((string)($row['first_name'] ?? ''));
            $last = trim((string)($row['last_name'] ?? ''));
            $email = strtolower(trim((string)($row['email'] ?? '')));
            $cell = trim((string)($row['cell_phone'] ?? ''));

            if ($first === '' || $last === '') {
                $status = 'error';
                $messages[] = 'First and last name are required.';
            }
            if ($email === '' && $cell === '') {
                $status = 'error';
                $messages[] = 'An email or a cell phone number is required.';
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $status = 'error';
                $messages[] = 'Invalid email.';
            }
            if ($email !== '' && isset($seenEmails[$email])) {
                $status = 'error';
                $messages[] = 'Duplicate email within the file (row ' . $seenEmails[$email] . ').';
            }
            if ($email !== '') {
                $seenEmails[$email] = $i + 1;
            }

            $match = null;
            if ($status !== 'error') {
                $match = self::matchExistingUser($email, $cell);
                if ($match === 'ambiguous') {
                    $status = 'error';
                    $messages[] = 'Multiple existing users match that phone number.';
                    $match = null;
                }
            }

            if ($status !== 'error') {
                if ($match) {
                    $isTeacher = self::hasTeacherProfile((int)$match['id']);
                    $changes = $isTeacher
                        ? 'Update existing teacher (' . $match['first_name'] . ' ' . $match['last_name'] . ')'
                        : 'Add teacher profile to existing user (' . $match['first_name'] . ' ' . $match['last_name'] . ')';
                    $row['_match_user_id'] = (int)$match['id'];
                } else {
                    $changes = 'Create teacher';
                }
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

    /**
     * Commit the valid rows (one transaction): create or update the users,
     * ensure teacher profiles. Returns ['created' => n, 'updated' => n,
     * 'skipped' => n].
     */
    public static function commit(?UserContext $ctx, array $validatedRows, array $context = []): array {
        self::assertAdmin($ctx);
        $pdo = pdo();
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $pdo->beginTransaction();
        try {
            foreach ($validatedRows as $entry) {
                if (($entry['status'] ?? '') !== 'valid') {
                    $skipped++;
                    continue;
                }
                $row = $entry['data'];
                $userId = (int)($row['_match_user_id'] ?? 0);
                if ($userId <= 0) {
                    $email = strtolower(trim((string)($row['email'] ?? '')));
                    $st = $pdo->prepare(
                        "INSERT INTO users (first_name, last_name, email, password_hash) VALUES (?,?,?,'')"
                    );
                    $st->execute([
                        trim((string)$row['first_name']),
                        trim((string)$row['last_name']),
                        $email !== '' ? $email : null,
                    ]);
                    $userId = (int)$pdo->lastInsertId();
                    $created++;
                } else {
                    $updated++;
                }

                self::updateContactFields($userId, $row);
                $pdo->prepare('INSERT IGNORE INTO teacher_profiles (user_id) VALUES (?)')->execute([$userId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::log($ctx, 'import.teachers_committed', ['created' => $created, 'updated' => $updated, 'skipped' => $skipped]);
        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    // ── internals ─────────────────────────────────────────────────────────

    /** @return array|string|null a users row, 'ambiguous', or null */
    private static function matchExistingUser(string $email, string $cell) {
        if ($email !== '') {
            $st = pdo()->prepare('SELECT * FROM users WHERE LOWER(email)=? AND is_deleted=0 LIMIT 1');
            $st->execute([$email]);
            $row = $st->fetch();
            if ($row) {
                return $row;
            }
        }
        $digits = self::phoneDigits($cell);
        if ($digits !== '') {
            $st = pdo()->prepare(
                "SELECT * FROM users
                 WHERE is_deleted=0 AND cell_phone IS NOT NULL
                   AND RIGHT(REGEXP_REPLACE(cell_phone, '[^0-9]', ''), 10) = ?"
            );
            $st->execute([$digits]);
            $rows = $st->fetchAll();
            if (count($rows) === 1) {
                return $rows[0];
            }
            if (count($rows) > 1) {
                return 'ambiguous';
            }
        }
        return null;
    }

    /** The last 10 digits of a phone number ('' when too short to compare). */
    private static function phoneDigits(string $phone): string {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        return strlen($digits) >= 7 ? substr($digits, -10) : '';
    }

    /** Write the row's non-empty contact fields onto the user. */
    private static function updateContactFields(int $userId, array $row): void {
        $columns = [
            'suffix', 'preferred_name', 'cell_phone', 'secondary_email', 'home_phone',
            'address_street_1', 'address_street_2', 'address_city', 'address_state', 'address_zip',
            'emergency_contact_name', 'emergency_contact_phone', 'shirt_size',
        ];
        $set = ['first_name = ?', 'last_name = ?'];
        $params = [trim((string)$row['first_name']), trim((string)$row['last_name'])];
        foreach ($columns as $column) {
            $value = trim((string)($row[$column] ?? ''));
            if ($value !== '') {
                $set[] = "$column = ?";
                $params[] = $value;
            }
        }
        $email = strtolower(trim((string)($row['email'] ?? '')));
        if ($email !== '') {
            $set[] = 'email = ?';
            $params[] = $email;
        }
        $params[] = $userId;
        pdo()->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
    }

    private static function hasTeacherProfile(int $userId): bool {
        $st = pdo()->prepare('SELECT 1 FROM teacher_profiles WHERE user_id=?');
        $st->execute([$userId]);
        return (bool)$st->fetchColumn();
    }

    private static function assertAdmin(?UserContext $ctx): void {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
    }

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
