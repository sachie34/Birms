<?php
/** B25: revised SRS resident photo upload, private storage, access and replacement. */
declare(strict_types=1);

function run_b25(array $S, string $base): void
{
    require_once __DIR__ . '/../app/residents_lib.php';

    $admin = $S['admin'];
    $staff = $S['clerk'];
    $tag = strtoupper(strtr(bin2hex(random_bytes(4)), '0123456789abcdef', 'abcdefghijklmnop'));
    $lastName = 'ZZB25' . $tag;
    $email = 'b25-' . strtolower($tag) . '@example.test';
    $purokLabel = 'ZZ B25 ' . $tag;
    $purokId = 0;
    $residentId = 0;
    $storedPaths = [];
    $tempPaths = [];

    $statuses = lookup_list('resident_status');
    $types = lookup_list('resident_type');
    $activeId = (int) array_search('Active', $statuses, true);
    $noneId = (int) array_search('None', $types, true);
    db_exec("INSERT IGNORE INTO lookups (list_key, label, sort_order, is_active) VALUES ('purok', ?, 9991, 1)", [$purokLabel]);
    $purokId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND label = ?", [$purokLabel]);

    $values = [
        'last_name' => $lastName,
        'first_name' => 'Photo',
        'middle_name' => 'Regression',
        'suffix' => '',
        'birthdate' => '1992-04-18',
        'sex' => 'Male',
        'marital_status' => 'Single',
        'address' => '25 B25 Photo Test Street',
        'purok_id' => (string) $purokId,
        'resident_status_id' => (string) $activeId,
        'resident_type_id' => (string) $noneId,
        'contact_number' => '09171239876',
        'email_address' => $email,
        'notification_preference' => 'email',
    ];

    try {
        $created = post_as($admin, '/residents/index.php', '/residents/form.php', $values);
        $resident = db_one('SELECT resident_id, photo_path FROM residents WHERE email_address = ? ORDER BY resident_id DESC LIMIT 1', [$email]);
        $residentId = (int) ($resident['resident_id'] ?? 0);
        $createOk = $created['status'] === 302 && $residentId > 0 && empty($resident['photo_path']);

        $jpeg1 = fx_chairman_signature_jpeg();
        $tempPaths[] = $jpeg1;
        $editPath = '/residents/form.php?id=' . $residentId;
        $firstUpload = $residentId > 0
            ? $admin->upload($editPath, ['_csrf' => $admin->token($editPath)] + $values, 'photo', $jpeg1, 'ZZ-B25-resident.jpg', 'image/jpeg')
            : ['status' => 0, 'body' => 'Resident creation failed.'];
        $afterFirst = $residentId > 0
            ? db_one('SELECT photo_path FROM residents WHERE resident_id = ?', [$residentId])
            : null;
        $path1 = is_string($afterFirst['photo_path'] ?? null) ? (string) $afterFirst['photo_path'] : '';
        if ($path1 !== '') { $storedPaths[] = $path1; }
        $adminImage = $residentId > 0 ? $admin->get('/residents/photo.php?id=' . $residentId) : ['status' => 0, 'headers' => [], 'body' => ''];
        $staffImage = $residentId > 0 ? $staff->get('/residents/photo.php?id=' . $residentId) : ['status' => 0, 'headers' => [], 'body' => ''];
        $anonymous = new Http($base);
        $anonymousImage = $residentId > 0 ? $anonymous->get('/residents/photo.php?id=' . $residentId) : ['status' => 0, 'headers' => [], 'body' => ''];
        $firstUploadOk = $createOk
            && $firstUpload['status'] === 302
            && $path1 !== ''
            && str_starts_with($path1, 'storage/resident_photos/')
            && is_file(APP_ROOT . '/' . $path1)
            && $adminImage['status'] === 200
            && ($adminImage['headers']['content-type'] ?? '') === 'image/jpeg'
            && strlen($adminImage['body']) > 100
            && $staffImage['status'] === 200
            && ($staffImage['headers']['content-type'] ?? '') === 'image/jpeg'
            && $anonymousImage['status'] === 403;
        record('B25-01', 'Admin can upload a genuine resident JPG; the photo is stored outside the public folder and served only through the authorized photo route',
            $firstUploadOk,
            'create=' . $created['status'] . '; create-message=' . (preg_match('/<div class="alert alert-danger">.*?<\\/div>/s', $created['body'], $messageMatch) ? substr(trim(strip_tags($messageMatch[0])), 0, 300) : 'no validation alert found') . '; upload=' . $firstUpload['status'] . '; admin=' . $adminImage['status']
            . '; staff=' . $staffImage['status'] . '; anonymous=' . $anonymousImage['status']
            . '; path=' . ($path1 !== '' ? 'private storage path saved' : 'missing'));

        $badImage = tempnam(sys_get_temp_dir(), 'birms-b25-bad-image-');
        if ($badImage === false || file_put_contents($badImage, 'not an image') === false) {
            throw new RuntimeException('Could not create the invalid-photo regression fixture.');
        }
        $tempPaths[] = $badImage;
        $invalidUpload = $residentId > 0
            ? $admin->upload($editPath, ['_csrf' => $admin->token($editPath)] + $values, 'photo', $badImage, 'ZZ-B25-invalid.jpg', 'image/jpeg')
            : ['status' => 0, 'body' => 'Resident creation failed.'];
        $afterInvalid = $residentId > 0
            ? db_one('SELECT photo_path FROM residents WHERE resident_id = ?', [$residentId])
            : null;
        $invalidRejected = $invalidUpload['status'] === 200
            && str_contains($invalidUpload['body'], 'image content does not match')
            && (string) ($afterInvalid['photo_path'] ?? '') === $path1
            && $path1 !== ''
            && is_file(APP_ROOT . '/' . $path1);
        record('B25-02', 'A disguised text file is rejected as a resident photo and cannot replace or delete the previously saved image',
            $invalidRejected,
            'invalid upload=' . $invalidUpload['status'] . '; previous photo preserved=' . ((string) ($afterInvalid['photo_path'] ?? '') === $path1 ? 'yes' : 'no'));

        $jpeg2 = fx_chairman_signature_jpeg();
        $tempPaths[] = $jpeg2;
        $secondUpload = $residentId > 0
            ? $admin->upload($editPath, ['_csrf' => $admin->token($editPath)] + $values, 'photo', $jpeg2, 'ZZ-B25-resident-replacement.jpg', 'image/jpeg')
            : ['status' => 0, 'body' => 'Resident creation failed.'];
        $afterSecond = $residentId > 0
            ? db_one('SELECT photo_path FROM residents WHERE resident_id = ?', [$residentId])
            : null;
        $path2 = is_string($afterSecond['photo_path'] ?? null) ? (string) $afterSecond['photo_path'] : '';
        if ($path2 !== '') { $storedPaths[] = $path2; }
        record('B25-03', 'Replacing a resident photo saves the new private image and removes the superseded file',
            $secondUpload['status'] === 302
            && $path2 !== ''
            && $path2 !== $path1
            && is_file(APP_ROOT . '/' . $path2)
            && ($path1 === '' || !is_file(APP_ROOT . '/' . $path1)),
            'replacement=' . $secondUpload['status'] . '; old removed=' . ($path1 === '' || !is_file(APP_ROOT . '/' . $path1) ? 'yes' : 'no'));
    } finally {
        foreach (array_unique($storedPaths) as $relative) {
            resident_photo_delete($relative);
        }
        if ($residentId > 0) {
            db_exec('DELETE FROM residents WHERE resident_id = ?', [$residentId]);
        }
        if ($purokId > 0) {
            db_exec("DELETE FROM lookups WHERE lookup_id = ? AND list_key = 'purok'
                     AND NOT EXISTS (SELECT 1 FROM residents WHERE purok_id = ?)", [$purokId, $purokId]);
        }
        foreach (array_unique($tempPaths) as $path) {
            if (is_file($path)) { @unlink($path); }
        }
    }
}
