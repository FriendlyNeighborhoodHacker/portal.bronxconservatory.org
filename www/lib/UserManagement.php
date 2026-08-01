<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

class UserManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    private static function str(string $v): string {
        return trim($v);
    }

    private static function normEmail(?string $email): ?string {
        if ($email === null) return null;
        $email = strtolower(trim($email));
        return $email === '' ? null : $email;
    }

    private static function boolInt($v): int {
        return !empty($v) ? 1 : 0;
    }

    // Activity logging - do not perform extra queries, just log what's provided.
    private static function log(string $action, ?int $targetUserId, array $details = []): void {
        try {
            // Actor is the currently logged in user (if any). May be null for some flows.
            $ctx = UserContext::getLoggedInUserContext();
            $meta = $details;
            if ($targetUserId !== null && !array_key_exists('target_user_id', $meta)) {
                $meta['target_user_id'] = (int)$targetUserId;
            }
            ActivityLog::log($ctx, (string)$action, (array)$meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }

    private static function assertAdmin(?UserContext $ctx): void {
        if (!$ctx || !$ctx->admin) { 
            throw new RuntimeException('Admins only'); 
        }
    }

    // Admins may update anyone; everyone else may update their own row and
    // the rows of their own children (parents keep their family's details
    // current from My Profile).
    private static function assertCanUpdate(?UserContext $ctx, int $targetUserId): void {
        require_once __DIR__ . '/StudentTeacherManagement.php';

        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        if ($ctx->admin || $ctx->id === $targetUserId) {
            return;
        }
        if (!StudentTeacherManagement::isParentOf($ctx->id, $targetUserId)) {
            throw new RuntimeException('Forbidden (assertCanUpdate)');
        }
    }

    // Find user by email for authentication. Soft-deleted users cannot sign in.
    public static function findAuthByEmail(string $email): ?array {
        $email = self::normEmail($email);
        if (!$email) return null;
        $st = self::pdo()->prepare('SELECT * FROM users WHERE email=? AND is_deleted=0 LIMIT 1');
        $st->execute([$email]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Find an existing user by email, or create a lightweight account (no
    // password, no verification email) — e.g. the parent behind a registration
    // form submission. The person can act via emailed token links, and may
    // claim the account at any time through the forgot-password flow or an
    // explicit invite.
    // $ctx may be null: the public registration form runs with no logged-in
    // user (the ActivityLog records a null actor for those writes).
    public static function findOrCreateByEmail(?UserContext $ctx, string $firstName, string $lastName, string $email): int {
        $email = self::normEmail($email);
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email is required.');
        }

        $existing = self::findAuthByEmail($email);
        if ($existing) {
            return (int)$existing['id'];
        }

        $first = self::str($firstName);
        $last = self::str($lastName);
        if ($first === '' || $last === '') {
            throw new InvalidArgumentException('First and last name are required when adding a new person.');
        }

        $st = self::pdo()->prepare(
            "INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verify_token,email_verified_at)
             VALUES (?,?,?,'',0,NULL,NULL)"
        );
        $st->execute([$first, $last, $email]);
        $id = (int)self::pdo()->lastInsertId();

        self::log('user.create', $id, ['email' => $email, 'lightweight' => true]);
        return $id;
    }

    // Send (or re-send) an account invitation to a user who cannot sign in yet:
    // generates a verification token and emails a set-your-password link.
    // Callers are responsible for authorization. $ctx may be null: the public
    // registration form invites the parent it just created.
    public static function sendAccountInvite(?UserContext $ctx, int $userId): void {
        $user = self::findById($userId);
        if (!$user) {
            throw new InvalidArgumentException('User not found.');
        }
        if (empty($user['email'])) {
            throw new InvalidArgumentException('That person has no email address.');
        }
        if ($user['password_hash'] !== '') {
            throw new InvalidArgumentException('That person already has an account with a password.');
        }

        $token = bin2hex(random_bytes(32));
        self::pdo()->prepare('UPDATE users SET email_verify_token = ? WHERE id = ?')
            ->execute([$token, $userId]);

        send_verification_email((string)$user['email'], $token, (string)$user['first_name']);

        self::log('user.invite_sent', $userId, ['email' => $user['email']]);
    }

    /** Find a user by email regardless of deleted state (admin matching). */
    public static function findByEmailAnyState(string $email): ?array {
        $email = self::normEmail($email);
        if (!$email) return null;
        $st = self::pdo()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $st->execute([$email]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * The person an admin just typed into an Add Teacher / Add Student /
     * Add Parent form: if the email already belongs to an account, adopt it —
     * restore it if soft-deleted and refresh the typed fields — instead of
     * failing with "email already exists". Otherwise create a lightweight
     * no-login account. The caller then attaches the role (teacher profile,
     * student profile, parenthood link).
     *
     * $data: first_name, last_name (required); email, cell_phone, suffix,
     * preferred_name (optional). Returns ['id' => int, 'existed' => bool].
     */
    public static function adoptOrCreatePerson(UserContext $ctx, array $data): array {
        self::assertAdmin($ctx);

        $email = self::normEmail($data['email'] ?? null);
        $existing = $email !== null ? self::findByEmailAnyState($email) : null;

        // Only the typed, non-empty extras are written — never wiping
        // whatever the existing account already has.
        $extras = [];
        foreach (['cell_phone', 'suffix', 'preferred_name'] as $key) {
            if (trim((string)($data[$key] ?? '')) !== '') {
                $extras[$key] = (string)$data[$key];
            }
        }

        if ($existing) {
            $userId = (int)$existing['id'];
            if (!empty($existing['is_deleted'])) {
                self::restoreUser($ctx, $userId);
            }
            self::updateProfile($ctx, $userId, $extras + [
                'first_name' => (string)($data['first_name'] ?? ''),
                'last_name' => (string)($data['last_name'] ?? ''),
            ]);
            return ['id' => $userId, 'existed' => true];
        }

        $userId = self::createUser($ctx, [
            'first_name' => (string)($data['first_name'] ?? ''),
            'last_name' => (string)($data['last_name'] ?? ''),
            'email' => $email ?? '',
            'no_login' => true,
        ]);
        if ($extras) {
            self::updateProfile($ctx, $userId, $extras);
        }
        return ['id' => $userId, 'existed' => false];
    }

    // Find user by ID
    public static function findById(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Create a new user (admin-created).
    // Three modes:
    //  - no_login: a person without an account (no email required, cannot sign in
    //    until an admin later adds an email and sends an activation link)
    //  - require_password_setup: sends a verification email; the user sets their own password
    //  - password: created directly with the given password, email pre-verified
    public static function createUser(UserContext $ctx, array $data): int {
        self::assertAdmin($ctx);

        $first = self::str($data['first_name'] ?? '');
        $last = self::str($data['last_name'] ?? '');
        $email = self::normEmail($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $isAdmin = self::boolInt($data['is_admin'] ?? 0);
        $requirePasswordSetup = !empty($data['require_password_setup']);
        $noLogin = !empty($data['no_login']);

        if ($first === '' || $last === '') {
            throw new InvalidArgumentException('Missing required fields for user creation.');
        }
        if (!$noLogin && !$email) {
            throw new InvalidArgumentException('Email is required for users who will sign in.');
        }

        // Check if email already exists
        if ($email && self::emailExists($email)) {
            throw new InvalidArgumentException('Email already exists.');
        }

        if ($noLogin) {
            if ($isAdmin) {
                throw new InvalidArgumentException('A person without a login cannot be an admin.');
            }
            $st = self::pdo()->prepare(
                "INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verify_token,email_verified_at)
                 VALUES (?,?,?,'',0,NULL,NULL)"
            );
            $st->execute([$first, $last, $email]);
            $id = (int)self::pdo()->lastInsertId();

            self::log('user.create', $id, ['email' => $email, 'no_login' => true]);
        } elseif ($requirePasswordSetup) {
            // Create user with empty password hash and verification token for password setup
            $hash = '';
            $token = bin2hex(random_bytes(32));
            $emailVerifiedAt = null;
            
            $st = self::pdo()->prepare(
                "INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verify_token,email_verified_at)
                 VALUES (?,?,?,?,?,?,?)"
            );
            $st->execute([$first, $last, $email, $hash, $isAdmin, $token, $emailVerifiedAt]);
            $id = (int)self::pdo()->lastInsertId();
            
            // Send verification email that will lead to password setup
            send_verification_email($email, $token, $first);
            
            self::log('user.create', $id, ['email' => $email, 'is_admin' => $isAdmin, 'requires_password_setup' => true]);
        } else {
            // Traditional user creation with password
            if ($password === '') {
                throw new InvalidArgumentException('Password is required when not using password setup flow.');
            }
            
            $hash = password_hash($password, PASSWORD_DEFAULT);
            
            $st = self::pdo()->prepare(
                "INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verify_token,email_verified_at)
                 VALUES (?,?,?,?,?,NULL,NOW())"
            );
            $st->execute([$first, $last, $email, $hash, $isAdmin]);
            $id = (int)self::pdo()->lastInsertId();
            
            self::log('user.create', $id, ['email' => $email, 'is_admin' => $isAdmin]);
        }
        
        return $id;
    }

    // Find a user by their email verification token
    public static function findByVerifyToken(string $token): ?array {
        if ($token === '') return null;
        $st = self::pdo()->prepare('SELECT * FROM users WHERE email_verify_token = ? LIMIT 1');
        $st->execute([$token]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Find a user who verified via token but still needs to set their initial password
    public static function findPendingPasswordSetupByToken(string $token): ?array {
        $user = self::findByVerifyToken($token);
        if (!$user || $user['password_hash'] !== '') return null;
        return $user;
    }

    // Complete the account-activation flow: set the initial password, mark the email
    // verified, and clear the token. Returns the updated user row, or null if the
    // token is invalid or the account already has a password.
    public static function completeInitialPasswordSetup(string $token, string $newPassword): ?array {
        $user = self::findPendingPasswordSetupByToken($token);
        if (!$user) return null;

        if ($newPassword === '') {
            throw new InvalidArgumentException('Password is required.');
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $st = self::pdo()->prepare(
            'UPDATE users SET password_hash = ?, email_verify_token = NULL, email_verified_at = NOW() WHERE id = ?'
        );
        $ok = $st->execute([$hash, (int)$user['id']]);
        if (!$ok) return null;

        ActivityLog::log(new UserContext((int)$user['id'], !empty($user['is_admin'])), 'user.initial_password_set', []);

        return self::findById((int)$user['id']);
    }

    // Verify email by token
    public static function verifyByToken(string $token): bool {
        if ($token === '') return false;
        $pdo = self::pdo();
        $st = $pdo->prepare('SELECT id FROM users WHERE email_verify_token = ? LIMIT 1');
        $st->execute([$token]);
        $row = $st->fetch();
        if (!$row) return false;

        $upd = $pdo->prepare('UPDATE users SET email_verified_at = NOW(), email_verify_token = NULL WHERE id = ?');
        return $upd->execute([(int)$row['id']]);
    }

    // Set password reset token
    public static function setPasswordResetToken(string $email): ?string {
        $email = self::normEmail($email);
        if (!$email) return null;

        $user = self::findAuthByEmail($email);
        if (!$user) return null;

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + (30 * 60)); // 30 minutes

        $st = self::pdo()->prepare(
            "UPDATE users SET password_reset_token_hash=?, password_reset_expires_at=? WHERE id=?"
        );
        $st->execute([$tokenHash, $expiresAt, (int)$user['id']]);

        // Send reset email
        send_password_reset_email($email, $token, $user['first_name'] ?? '');

        return $token;
    }

    // Verify password reset token and get user
    public static function getUserByResetToken(string $token): ?array {
        if ($token === '') return null;
        
        $tokenHash = hash('sha256', $token);
        $st = self::pdo()->prepare(
            'SELECT * FROM users WHERE password_reset_token_hash = ? AND password_reset_expires_at > NOW() LIMIT 1'
        );
        $st->execute([$tokenHash]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Complete password reset
    public static function completePasswordReset(string $token, string $newPassword): bool {
        $user = self::getUserByResetToken($token);
        if (!$user) return false;

        // Completing a reset proves control of the email address, so also mark
        // it verified — this is how a lightweight (assigned-by-email) user
        // claims a full login account.
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $st = self::pdo()->prepare(
            'UPDATE users SET password_hash=?, password_reset_token_hash=NULL, password_reset_expires_at=NULL,
                    email_verified_at=COALESCE(email_verified_at, NOW()), email_verify_token=NULL WHERE id=?'
        );
        $ok = $st->execute([$hash, (int)$user['id']]);
        
        if ($ok) {
            self::log('user.password_reset', (int)$user['id']);
        }
        
        return $ok;
    }

    // Check if email exists
    public static function emailExists(string $email): bool {
        $norm = self::normEmail($email);
        if (!$norm) return false;
        $st = self::pdo()->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $st->execute([$norm]);
        return (bool)$st->fetchColumn();
    }

    // List all users (admin only). Soft-deleted users are hidden unless asked for.
    public static function listUsers(string $search = '', bool $includeDeleted = false): array {
        $sql = 'SELECT id, first_name, last_name, email, is_admin, is_developer, is_deleted, email_verified_at, created_at,
                       (password_hash <> \'\') AS has_password FROM users';
        $where = [];
        $params = [];

        if (!$includeDeleted) {
            $where[] = 'is_deleted = 0';
        }
        if ($search !== '') {
            $where[] = '(first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)';
            $searchTerm = '%' . $search . '%';
            $params = [$searchTerm, $searchTerm, $searchTerm];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY last_name, first_name';

        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    // Update user profile. Names must be non-empty; email may be empty only
    // for users who cannot sign in (no password set); a non-empty email must
    // be valid. All other contact fields are free-form and may be cleared.
    public static function updateProfile(UserContext $ctx, int $id, array $fields): bool {
        self::assertCanUpdate($ctx, $id);

        $required = ['first_name', 'last_name'];
        $optional = [
            'suffix', 'preferred_name', 'secondary_email', 'cell_phone', 'home_phone',
            'preferred_contact_method',
            'address_street_1', 'address_street_2', 'address_city', 'address_state', 'address_zip',
            'emergency_contact_name', 'emergency_contact_phone', 'medical_notes', 'shirt_size',
        ];
        $set = [];
        $params = [];

        foreach ($required as $key) {
            if (!array_key_exists($key, $fields)) continue;
            $val = self::str((string)$fields[$key]);
            if ($val === '') {
                throw new InvalidArgumentException("$key cannot be empty");
            }
            $set[] = "$key = ?";
            $params[] = $val;
        }

        if (array_key_exists('email', $fields)) {
            $val = self::normEmail($fields['email']);
            if ($val !== null && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Valid email is required.');
            }
            if ($val === null) {
                $existing = self::findById($id);
                if ($existing && $existing['password_hash'] !== '') {
                    throw new InvalidArgumentException('Email is required for users who can sign in.');
                }
            }
            $set[] = 'email = ?';
            $params[] = $val;
        }

        foreach ($optional as $key) {
            if (!array_key_exists($key, $fields)) continue;
            $val = self::str((string)$fields[$key]);
            if ($key === 'preferred_contact_method' && $val !== '' && !in_array($val, ['email', 'phone', 'text'], true)) {
                throw new InvalidArgumentException('Unknown preferred contact method.');
            }
            $set[] = "$key = ?";
            $params[] = $val === '' ? null : $val;
        }

        if (empty($set)) return false;
        $params[] = $id;

        $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?';
        $st = self::pdo()->prepare($sql);
        $ok = $st->execute($params);
        
        if ($ok) {
            self::log('user.update', $id, ['fields' => array_keys($fields)]);
        }
        
        return $ok;
    }

    // Soft-delete a user: the row and its history stay, but they can no
    // longer sign in and disappear from lists and role resolution.
    public static function deleteUser(UserContext $ctx, int $id): bool {
        self::assertAdmin($ctx);
        if ($id === $ctx->id) {
            throw new RuntimeException('You cannot delete your own account.');
        }

        $st = self::pdo()->prepare('UPDATE users SET is_deleted = 1 WHERE id = ?');
        $ok = $st->execute([$id]);

        if ($ok) {
            self::log('user.soft_delete', $id);
        }

        return $ok;
    }

    // Restore a soft-deleted user.
    public static function restoreUser(UserContext $ctx, int $id): bool {
        self::assertAdmin($ctx);
        $st = self::pdo()->prepare('UPDATE users SET is_deleted = 0 WHERE id = ?');
        $ok = $st->execute([$id]);
        if ($ok) {
            self::log('user.restore', $id);
        }
        return $ok;
    }

    // Set admin flag
    public static function setAdminFlag(UserContext $ctx, int $id, bool $isAdmin): bool {
        self::assertAdmin($ctx);
        
        $st = self::pdo()->prepare('UPDATE users SET is_admin = ? WHERE id = ?');
        $ok = $st->execute([$isAdmin ? 1 : 0, $id]);
        
        if ($ok) {
            self::log('user.set_admin', $id, ['is_admin' => $isAdmin ? 1 : 0]);
        }
        
        return $ok;
    }

    // Set developer flag. With is_admin this unlocks Admin > Maintenance
    // (migrations, activity log, email log); on its own it grants nothing.
    public static function setDeveloperFlag(UserContext $ctx, int $id, bool $isDeveloper): bool {
        self::assertAdmin($ctx);

        $st = self::pdo()->prepare('UPDATE users SET is_developer = ? WHERE id = ?');
        $ok = $st->execute([$isDeveloper ? 1 : 0, $id]);

        if ($ok) {
            self::log('user.set_developer', $id, ['is_developer' => $isDeveloper ? 1 : 0]);
        }

        return $ok;
    }

    // Change password
    public static function changePassword(UserContext $ctx, int $id, string $newPassword): bool {
        self::assertCanUpdate($ctx, $id);
        
        if ($newPassword === '') {
            throw new InvalidArgumentException('New password is required.');
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $st = self::pdo()->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $ok = $st->execute([$hash, $id]);
        
        if ($ok) {
            self::log('user.change_password', $id);
        }
        
        return $ok;
    }

    // Set email verification token (for admin use)
    public static function setEmailVerificationToken(UserContext $ctx, int $id): string {
        self::assertAdmin($ctx);
        
        $token = bin2hex(random_bytes(32));
        $st = self::pdo()->prepare('UPDATE users SET email_verify_token = ?, email_verified_at = NULL WHERE id = ?');
        $ok = $st->execute([$token, $id]);
        
        if (!$ok) {
            throw new RuntimeException('Failed to set email verification token.');
        }
        
        self::log('user.email_verification_token_set', $id);
        return $token;
    }

    // Update user photo
    public static function updateUserPhoto(UserContext $ctx, int $id, ?int $photoPublicFileId): bool {
        self::assertCanUpdate($ctx, $id);
        
        $st = self::pdo()->prepare("UPDATE users SET photo_public_file_id = ? WHERE id = ?");
        $ok = $st->execute([$photoPublicFileId, $id]);
        
        if ($ok) {
            $action = $photoPublicFileId ? 'user.photo_update' : 'user.photo_remove';
            self::log($action, $id, ['photo_public_file_id' => $photoPublicFileId]);
        }
        
        return $ok;
    }
}
