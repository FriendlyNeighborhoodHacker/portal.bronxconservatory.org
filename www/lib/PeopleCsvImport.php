<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/UserManagement.php';
require_once __DIR__ . '/StudentTeacherManagement.php';
require_once __DIR__ . '/InstrumentCatalog.php';

/**
 * The combined students & parents CSV (optional setup stage). One row per
 * person — parents and students share the same columns — plus an optional
 * "Parents" column on student rows: a comma/semicolon-separated list of
 * parent identifiers (a "First Last" name or an email address). Identifiers
 * resolve against other rows in the same file first, then against people
 * already in the system, so one file builds the whole family graph and row
 * order never matters.
 *
 * Roles fall out of the references: a row someone lists as a parent is a
 * parent; every other row is a student (a referenced row with student
 * fields — grade, instruments, ... — becomes both, e.g. an adult student
 * who is also a parent).
 */
class PeopleCsvImport {

    private const STUDENT_FIELDS = ['date_of_birth', 'class_of', 'grade', 'school_name', 'instruments'];

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
            'date_of_birth' => 'Date of Birth',
            'class_of' => 'Class Of',
            'grade' => 'Grade',
            'school_name' => 'School Name',
            'instruments' => 'Instruments',
            'parents' => 'Parents',
        ];
    }

    public static function validateRows(array $mappedRows, array $context = []): array {
        $catalog = self::instrumentIdsByNormalizedName();

        // ── Pass 1: per-row basics + in-file identity maps ────────────────
        $out = [];
        $emailToRow = [];   // email => row index
        $nameToRows = [];   // "first last" => [row indexes]
        foreach ($mappedRows as $i => $row) {
            $messages = [];
            $status = 'valid';

            $first = trim((string)($row['first_name'] ?? ''));
            $last = trim((string)($row['last_name'] ?? ''));
            $email = strtolower(trim((string)($row['email'] ?? '')));

            if ($first === '' || $last === '') {
                $status = 'error';
                $messages[] = 'First and last name are required.';
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $status = 'error';
                $messages[] = 'Invalid email.';
            }
            if ($email !== '') {
                if (isset($emailToRow[$email])) {
                    $status = 'error';
                    $messages[] = 'Duplicate email within the file (row ' . ($emailToRow[$email] + 1) . ').';
                } else {
                    $emailToRow[$email] = $i;
                }
            }
            $nameToRows[self::norm($first . ' ' . $last)][] = $i;

            $instrumentIds = [];
            foreach (self::splitList((string)($row['instruments'] ?? '')) as $name) {
                $id = $catalog[self::norm($name)] ?? null;
                if ($id === null) {
                    $status = 'error';
                    $messages[] = 'Unknown instrument "' . $name . '".';
                } else {
                    $instrumentIds[] = $id;
                }
            }
            $row['_instrument_ids'] = $instrumentIds;

            $dob = trim((string)($row['date_of_birth'] ?? ''));
            if ($dob !== '') {
                $parsed = self::parseDate($dob);
                if ($parsed === null) {
                    $status = 'error';
                    $messages[] = 'Invalid date of birth "' . $dob . '".';
                } else {
                    $row['date_of_birth'] = $parsed;
                }
            }

            $out[] = ['row' => $i + 1, 'data' => $row, 'status' => $status, 'changes' => '', 'messages' => $messages];
        }

        // ── Pass 2: resolve Parents references; mark parent rows ──────────
        $isParentRow = [];
        foreach ($out as $i => &$entry) {
            $refs = [];
            foreach (self::splitList((string)($entry['data']['parents'] ?? '')) as $identifier) {
                $ref = self::resolveParentIdentifier($identifier, $i, $emailToRow, $nameToRows, $out);
                if (isset($ref['error'])) {
                    $entry['status'] = 'error';
                    $entry['messages'][] = $ref['error'];
                    continue;
                }
                if (isset($ref['row'])) {
                    $isParentRow[$ref['row']] = true;
                }
                $refs[] = $ref;
            }
            $entry['data']['_parent_refs'] = $refs;
        }
        unset($entry);

        // ── Pass 3: roles, existing-user matching, change summaries ───────
        foreach ($out as $i => &$entry) {
            if ($entry['status'] === 'error') {
                continue;
            }
            $row = $entry['data'];
            $isParent = !empty($isParentRow[$i]);
            $hasStudentFields = false;
            foreach (self::STUDENT_FIELDS as $field) {
                if (trim((string)($row[$field] ?? '')) !== '') {
                    $hasStudentFields = true;
                    break;
                }
            }
            // Referenced rows are parents; everyone else is a student. A
            // referenced row with student fields is both.
            $isStudent = !$isParent || $hasStudentFields;

            $match = self::matchExistingUser(
                strtolower(trim((string)($row['email'] ?? ''))),
                trim((string)($row['cell_phone'] ?? '')),
                trim((string)($row['first_name'] ?? '')),
                trim((string)($row['last_name'] ?? '')),
                $isParent,
                $isStudent
            );
            if ($match === 'ambiguous') {
                $entry['status'] = 'error';
                $entry['messages'][] = 'Multiple existing people match this row.';
                continue;
            }

            $roleLabel = $isParent && $isStudent ? 'parent + student' : ($isParent ? 'parent' : 'student');
            $changeBits = [];
            if ($match) {
                $entry['data']['_match_user_id'] = (int)$match['id'];
                $changeBits[] = 'Update existing person (' . $match['first_name'] . ' ' . $match['last_name'] . ') as ' . $roleLabel;
            } else {
                $changeBits[] = 'Create ' . $roleLabel;
            }
            foreach ($entry['data']['_parent_refs'] as $ref) {
                $changeBits[] = 'link parent ' . $ref['label'];
            }
            $entry['changes'] = implode('; ', $changeBits);
            $entry['data']['_is_parent'] = $isParent;
            $entry['data']['_is_student'] = $isStudent;
        }
        unset($entry);

        // A student whose parent's own row has errors can't be linked safely.
        foreach ($out as &$entry) {
            if ($entry['status'] === 'error') {
                continue;
            }
            foreach ((array)($entry['data']['_parent_refs'] ?? []) as $ref) {
                if (isset($ref['row']) && $out[$ref['row']]['status'] === 'error') {
                    $entry['status'] = 'error';
                    $entry['messages'][] = 'Parent "' . $ref['label'] . '" (row ' . ($ref['row'] + 1) . ') has errors.';
                }
            }
        }
        unset($entry);

        return $out;
    }

    /** Commit the valid rows (people first, then links) in one transaction. */
    public static function commit(?UserContext $ctx, array $validatedRows, array $context = []): array {
        self::assertAdmin($ctx);
        $pdo = pdo();
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $pdo->beginTransaction();
        try {
            // First pass: create/update every person; remember row => user id.
            $userIdByRow = [];
            foreach ($validatedRows as $i => $entry) {
                if (($entry['status'] ?? '') !== 'valid') {
                    $skipped++;
                    continue;
                }
                $row = $entry['data'];
                $userId = (int)($row['_match_user_id'] ?? 0);
                if ($userId <= 0) {
                    $email = strtolower(trim((string)($row['email'] ?? '')));
                    $st = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password_hash) VALUES (?,?,?,'')");
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
                $userIdByRow[$i] = $userId;

                self::updateContactFields($userId, $row);
                if (!empty($row['_is_student'])) {
                    StudentTeacherManagement::ensureStudentProfile($ctx, $userId, [
                        'date_of_birth' => (string)($row['date_of_birth'] ?? ''),
                        'class_of' => (string)($row['class_of'] ?? ''),
                        'grade' => (string)($row['grade'] ?? ''),
                        'school_name' => (string)($row['school_name'] ?? ''),
                    ]);
                    if (!empty($row['_instrument_ids'])) {
                        InstrumentCatalog::addStudentInstruments($ctx, $userId, $row['_instrument_ids']);
                    }
                }
            }

            // Second pass: parent links (every referenced person now has an id).
            foreach ($validatedRows as $i => $entry) {
                if (($entry['status'] ?? '') !== 'valid' || !isset($userIdByRow[$i])) {
                    continue;
                }
                foreach ((array)($entry['data']['_parent_refs'] ?? []) as $ref) {
                    $parentId = isset($ref['row']) ? ($userIdByRow[$ref['row']] ?? null) : (int)$ref['user_id'];
                    if ($parentId) {
                        StudentTeacherManagement::linkParentChild($ctx, (int)$parentId, $userIdByRow[$i], null);
                    }
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::log($ctx, 'import.people_committed', ['created' => $created, 'updated' => $updated, 'skipped' => $skipped]);
        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * One item from a Parents column: an email (contains @) or a "First
     * Last" name. Resolved against the file's rows first, then existing
     * people. Returns ['row' => idx, 'label' => ...] |
     * ['user_id' => id, 'label' => ...] | ['error' => message].
     */
    private static function resolveParentIdentifier(string $identifier, int $ownRow, array $emailToRow, array $nameToRows, array $entries) {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return ['error' => 'Empty parent identifier.'];
        }

        if (strpos($identifier, '@') !== false) {
            $email = strtolower($identifier);
            if (isset($emailToRow[$email])) {
                $rowIdx = $emailToRow[$email];
                if ($rowIdx === $ownRow) {
                    return ['error' => 'A person cannot be their own parent (' . $identifier . ').'];
                }
                return ['row' => $rowIdx, 'label' => self::rowLabel($entries[$rowIdx])];
            }
            $st = pdo()->prepare('SELECT * FROM users WHERE LOWER(email)=? AND is_deleted=0 LIMIT 1');
            $st->execute([$email]);
            $user = $st->fetch();
            if ($user) {
                return ['user_id' => (int)$user['id'], 'label' => $user['first_name'] . ' ' . $user['last_name']];
            }
            return ['error' => 'Parent "' . $identifier . '" not found — add a row for them or use an existing person\'s email.'];
        }

        $norm = self::norm($identifier);
        $rowMatches = array_values(array_filter($nameToRows[$norm] ?? [], fn($idx) => $idx !== $ownRow));
        if (count($rowMatches) === 1) {
            return ['row' => $rowMatches[0], 'label' => self::rowLabel($entries[$rowMatches[0]])];
        }
        if (count($rowMatches) > 1) {
            return ['error' => 'Multiple rows in this file are named "' . $identifier . '" — use their emails instead.'];
        }
        // Not in the file: match existing people by name, parents first so a
        // same-named student is never picked as someone's parent.
        foreach ([true, false] as $parentsOnly) {
            $sql = $parentsOnly
                ? "SELECT DISTINCT u.* FROM parenthood ph JOIN users u ON u.id = ph.parent_user_id
                   WHERE u.is_deleted=0 AND LOWER(CONCAT(u.first_name,' ',u.last_name)) = ?"
                : "SELECT u.* FROM users u
                   LEFT JOIN student_profiles sp ON sp.user_id = u.id
                   WHERE u.is_deleted=0 AND sp.user_id IS NULL
                     AND LOWER(CONCAT(u.first_name,' ',u.last_name)) = ?";
            $st = pdo()->prepare($sql);
            $st->execute([$norm]);
            $rows = $st->fetchAll();
            if (count($rows) === 1) {
                return ['user_id' => (int)$rows[0]['id'], 'label' => $rows[0]['first_name'] . ' ' . $rows[0]['last_name']];
            }
            if (count($rows) > 1) {
                return ['error' => 'Multiple existing people are named "' . $identifier . '" — use an email instead.'];
            }
        }
        return ['error' => 'Parent "' . $identifier . '" not found — add a row for them or check the spelling.'];
    }

    private static function rowLabel(array $entry): string {
        return trim((string)($entry['data']['first_name'] ?? '') . ' ' . (string)($entry['data']['last_name'] ?? ''));
    }

    /**
     * Match a row to an existing user: email, then normalized cell phone,
     * then exact full name — scoped to the role the row is becoming
     * (parents match among parents, students among students) so nobody is
     * hijacked by a same-named person of another kind.
     * @return array|string|null a users row, 'ambiguous', or null
     */
    private static function matchExistingUser(string $email, string $cell, string $first, string $last, bool $isParent, bool $isStudent) {
        if ($email !== '') {
            $st = pdo()->prepare('SELECT * FROM users WHERE LOWER(email)=? AND is_deleted=0 LIMIT 1');
            $st->execute([$email]);
            $row = $st->fetch();
            if ($row) {
                return $row;
            }
        }
        $digits = preg_replace('/\D/', '', $cell) ?? '';
        if (strlen($digits) >= 7) {
            $st = pdo()->prepare(
                "SELECT * FROM users
                 WHERE is_deleted=0 AND cell_phone IS NOT NULL
                   AND RIGHT(REGEXP_REPLACE(cell_phone, '[^0-9]', ''), 10) = ?"
            );
            $st->execute([substr($digits, -10)]);
            $rows = $st->fetchAll();
            if (count($rows) === 1) {
                return $rows[0];
            }
            if (count($rows) > 1) {
                return 'ambiguous';
            }
        }
        if ($email === '' && $digits === '') {
            $norm = self::norm($first . ' ' . $last);
            $matches = [];
            if ($isStudent) {
                $st = pdo()->prepare(
                    "SELECT u.* FROM student_profiles sp JOIN users u ON u.id = sp.user_id
                     WHERE u.is_deleted=0 AND LOWER(CONCAT(u.first_name,' ',u.last_name)) = ?"
                );
                $st->execute([$norm]);
                $matches = array_merge($matches, $st->fetchAll());
            }
            if ($isParent) {
                $st = pdo()->prepare(
                    "SELECT DISTINCT u.* FROM parenthood ph JOIN users u ON u.id = ph.parent_user_id
                     WHERE u.is_deleted=0 AND LOWER(CONCAT(u.first_name,' ',u.last_name)) = ?"
                );
                $st->execute([$norm]);
                $matches = array_merge($matches, $st->fetchAll());
            }
            $ids = array_unique(array_map(fn($r) => (int)$r['id'], $matches));
            if (count($ids) === 1) {
                return $matches[0];
            }
            if (count($ids) > 1) {
                return 'ambiguous';
            }
        }
        return null;
    }

    private static function updateContactFields(int $userId, array $row): void {
        $columns = [
            'suffix', 'preferred_name', 'cell_phone', 'secondary_email', 'home_phone',
            'address_street_1', 'address_street_2', 'address_city', 'address_state', 'address_zip',
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

    private static function instrumentIdsByNormalizedName(): array {
        $out = [];
        foreach (InstrumentCatalog::all() as $instrument) {
            $out[self::norm((string)$instrument['name'])] = (int)$instrument['id'];
        }
        return $out;
    }

    /** "Piano; Violin" or "Rosa Ramos, Hector Ramos" -> trimmed items. */
    public static function splitList(string $raw): array {
        $parts = preg_split('/[;,]/', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
    }

    private static function norm(string $name): string {
        return preg_replace('/\s+/', ' ', strtolower(trim($name))) ?? '';
    }

    /** Accepts "2014-03-05" and "3/5/2014"; returns Y-m-d or null. */
    public static function parseDate(string $raw): ?string {
        $raw = trim($raw);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m)) {
            return checkdate((int)$m[1], (int)$m[2], (int)$m[3])
                ? sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[1], (int)$m[2])
                : null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            [$y, $mo, $d] = array_map('intval', explode('-', $raw));
            return checkdate($mo, $d, $y) ? $raw : null;
        }
        return null;
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
