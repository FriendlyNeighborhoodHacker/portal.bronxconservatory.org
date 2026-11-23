<?php
declare(strict_types=1);

/**
 * Who counts as a Bronx resident — the one rule behind which tuition rate a
 * family pays (the semester's standard fees, or its "non-residents and/or
 * online students" fees). Used by the registration quote (LeadManagement) and
 * by the charges posted when a reservation is confirmed (Billing).
 */
class Residency {

    /**
     * An address is in the Bronx when EITHER the city is "Bronx" (any case,
     * "The Bronx" too) OR the ZIP's first five digits are 10451-10475.
     */
    public static function isBronxAddress(?string $city, ?string $zip): bool {
        $city = strtolower(trim(preg_replace('/\s+/', ' ', (string)$city) ?? ''));
        if ($city === 'bronx' || $city === 'the bronx') {
            return true;
        }
        if (preg_match('/^\s*(\d{5})/', (string)$zip, $m)) {
            $zip5 = (int)$m[1];
            return $zip5 >= 10451 && $zip5 <= 10475;
        }
        return false;
    }

    /**
     * Is this student a Bronx resident, going by the addresses on their own
     * account and their parents' accounts? True when any of them is a Bronx
     * address; false when there are addresses and none is; NULL when nobody
     * in the family has a city or ZIP on file (callers decide — billing
     * treats unknown as resident, so a missing address never raises a bill).
     */
    public static function studentIsBronxResident(int $studentUserId): ?bool {
        $st = pdo()->prepare(
            'SELECT u.address_city, u.address_zip FROM users u WHERE u.id = ?
             UNION ALL
             SELECT p.address_city, p.address_zip
             FROM parenthood ph JOIN users p ON p.id = ph.parent_user_id
             WHERE ph.child_user_id = ? AND p.is_deleted = 0'
        );
        $st->execute([$studentUserId, $studentUserId]);
        $known = false;
        foreach ($st->fetchAll() as $row) {
            $city = trim((string)($row['address_city'] ?? ''));
            $zip = trim((string)($row['address_zip'] ?? ''));
            if ($city === '' && $zip === '') {
                continue;
            }
            $known = true;
            if (self::isBronxAddress($city, $zip)) {
                return true;
            }
        }
        return $known ? false : null;
    }
}
