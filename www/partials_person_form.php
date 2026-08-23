<?php
// Shared pieces of the person screens — Edit Student / Edit Teacher /
// Edit Parent (admin) and My Profile / Edit Child (self-service): the
// profile-photo card, the Basic Information form fields (the spec's
// name / identifiers / address / contact / emergency-contact set), the
// matching $_POST collector for the eval side, and the read-only rendering
// of the same field set.
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Files.php';

// Photo (current photo or an initials placeholder) with the upload button
// top right, posting to the existing /upload_photo.php.
function person_photo_card_html(array $user, string $returnTo): string {
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $initials = strtoupper(substr((string)($user['first_name'] ?? ''), 0, 1) . substr((string)($user['last_name'] ?? ''), 0, 1));
    $photoUrl = Files::profilePhotoUrl($user['photo_public_file_id'] ?? null);
    $action = '/upload_photo.php?user_id=' . (int)$user['id'] . '&return_to=' . urlencode($returnTo);

    $html = '<div class="card"><div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">';
    if ($photoUrl !== '') {
        $html .= '<img src="' . h($photoUrl) . '" alt="' . h($name) . '" style="width:80px;height:80px;border-radius:50%;object-fit:cover;">';
    } else {
        $html .= '<div aria-hidden="true" style="width:80px;height:80px;border-radius:50%;background:var(--color-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:600;">' . h($initials) . '</div>';
    }
    $html .= '<form method="post" action="' . h($action) . '" enctype="multipart/form-data" class="stack" style="margin-left:auto;min-width:260px;">'
        . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<label>Upload a new photo <input type="file" name="photo" accept="image/*" required></label>'
        . '<div class="actions"><button class="button" type="submit">Upload Photo</button></div>'
        . '</form>';
    if ($photoUrl !== '') {
        $html .= '<form method="post" action="' . h($action) . '" onsubmit="return confirm(\'Remove this photo?\');">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<input type="hidden" name="action" value="delete">'
            . '<button class="button" type="submit">Remove Photo</button>'
            . '</form>';
    }
    $html .= '</div></div>';
    return $html;
}

/** The Basic Information inputs. $form: current values (user row or repost). */
function person_basic_info_fields_html(array $form): void {
    $f = fn(string $key) => h((string)($form[$key] ?? ''));
    ?>
    <h3>Basic Information</h3>
    <div class="grid-2">
      <label>First name <input type="text" name="first_name" value="<?=$f('first_name')?>" required></label>
      <label>Last name <input type="text" name="last_name" value="<?=$f('last_name')?>" required></label>
      <label>Suffix <input type="text" name="suffix" value="<?=$f('suffix')?>"></label>
      <label>Preferred name <input type="text" name="preferred_name" value="<?=$f('preferred_name')?>"></label>
    </div>
    <div class="grid-2">
      <label>Email <input type="email" name="email" value="<?=$f('email')?>"></label>
      <label>Cell phone <input type="text" name="cell_phone" value="<?=$f('cell_phone')?>"></label>
    </div>
    <h3>Address</h3>
    <div class="grid-2">
      <label>Street 1 <input type="text" name="address_street_1" value="<?=$f('address_street_1')?>"></label>
      <label>Street 2 <input type="text" name="address_street_2" value="<?=$f('address_street_2')?>"></label>
    </div>
    <div class="grid-3">
      <label>City <input type="text" name="address_city" value="<?=$f('address_city')?>"></label>
      <label>State <input type="text" name="address_state" value="<?=$f('address_state')?>"></label>
      <label>Zip <input type="text" name="address_zip" value="<?=$f('address_zip')?>"></label>
    </div>
    <h3>Contact</h3>
    <div class="grid-2">
      <label>Secondary email <input type="email" name="secondary_email" value="<?=$f('secondary_email')?>"></label>
      <label>Home phone <input type="text" name="home_phone" value="<?=$f('home_phone')?>"></label>
    </div>
    <h3>Emergency Contact</h3>
    <div class="grid-2">
      <label>Contact name <input type="text" name="emergency_contact_name" value="<?=$f('emergency_contact_name')?>"></label>
      <label>Contact phone <input type="text" name="emergency_contact_phone" value="<?=$f('emergency_contact_phone')?>"></label>
    </div>
    <?php
}

/** The "Other" inputs, shown on the self-service profile screens. */
function person_other_fields_html(array $form): void {
    ?>
    <h3>Other</h3>
    <div class="grid-2">
      <label>Shirt size <input type="text" name="shirt_size" value="<?=h((string)($form['shirt_size'] ?? ''))?>"></label>
    </div>
    <?php
}

/**
 * Collect the person fields from $_POST for updateProfile. Only keys the
 * form actually posted are returned, so a screen that omits a section (the
 * admin screens have no "Other" section) leaves those columns untouched.
 */
function person_basic_info_fields_from_post(): array {
    $fields = [];
    foreach (person_field_labels() as $key => $_label) {
        if (array_key_exists($key, $_POST)) {
            $fields[$key] = (string)$_POST[$key];
        }
    }
    return $fields;
}

/** Every person field, in display order, mapped to its human label. */
function person_field_labels(): array {
    return [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'suffix' => 'Suffix',
        'preferred_name' => 'Preferred name',
        'email' => 'Email',
        'cell_phone' => 'Cell phone',
        'address_street_1' => 'Street 1',
        'address_street_2' => 'Street 2',
        'address_city' => 'City',
        'address_state' => 'State',
        'address_zip' => 'Zip',
        'secondary_email' => 'Secondary email',
        'home_phone' => 'Home phone',
        'emergency_contact_name' => 'Contact name',
        'emergency_contact_phone' => 'Contact phone',
        'shirt_size' => 'Shirt size',
    ];
}

/**
 * The person's address formatted the way an address is written:
 * "123 Main St<br>Apt 2<br>Bronx, NY 10461". '' when nothing is recorded.
 */
function person_address_block_html(array $user): string {
    $lines = [];
    foreach (['address_street_1', 'address_street_2'] as $key) {
        $value = trim((string)($user[$key] ?? ''));
        if ($value !== '') {
            $lines[] = h($value);
        }
    }
    $city = trim((string)($user['address_city'] ?? ''));
    $state = trim((string)($user['address_state'] ?? ''));
    $zip = trim((string)($user['address_zip'] ?? ''));
    $cityLine = $city . ($city !== '' && $state !== '' ? ', ' : '') . $state . ($zip !== '' ? ' ' . $zip : '');
    if (trim($cityLine) !== '') {
        $lines[] = h(trim($cityLine));
    }
    return implode('<br>', $lines);
}

/**
 * Read-only rendering of the person field set, grouped the same way the
 * edit form groups it. Empty fields are still listed (as a muted dash) so
 * it is obvious what is missing — except the address, which renders as one
 * formatted block.
 */
function person_details_html(array $user): string {
    $labels = person_field_labels();
    $sections = [
        'Name' => ['first_name', 'last_name', 'suffix', 'preferred_name'],
        'Contact' => ['email', 'cell_phone', 'secondary_email', 'home_phone'],
        'Emergency Contact' => ['emergency_contact_name', 'emergency_contact_phone'],
        'Other' => ['shirt_size'],
    ];

    $html = '';
    foreach ($sections as $heading => $keys) {
        $html .= '<h3>' . h($heading) . '</h3><dl class="detail-grid">';
        foreach ($keys as $key) {
            $value = trim((string)($user[$key] ?? ''));
            $html .= '<dt>' . h($labels[$key]) . '</dt>';
            $html .= $value === ''
                ? '<dd class="detail-empty">—</dd>'
                : '<dd>' . h($value) . '</dd>';
        }
        if ($heading === 'Contact') {
            $address = person_address_block_html($user);
            $html .= '<dt>Address</dt>';
            $html .= $address === ''
                ? '<dd class="detail-empty">—</dd>'
                : '<dd>' . $address . '</dd>';
        }
        $html .= '</dl>';
    }
    return $html;
}
