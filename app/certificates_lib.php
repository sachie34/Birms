<?php
/** Certificate rules: wording variables, what blocks issuing, O.R. numbers. */
declare(strict_types=1);

function ordinal(int $n): string
{
    $s = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][$n % 10];
    return $n . $s;
}
/** Values for the {{placeholders}}. $r needs resident + household + request columns. */
function certificate_vars(array $r, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now');
    return [
        'full_name' => strtoupper(resident_name_natural($r)), 'age' => (string) age_on((string) $r['birthdate'], $now->format('Y-m-d')), 'civil_status' => (string) $r['civil_status'],
        'address' => (string) ($r['street_address'] ?? ''), 'purok' => (string) ($r['purok'] ?? ''), 'purpose' => (string) $r['purpose'],
        'date_issued' => $now->format('j F Y'), 'day_ordinal' => ordinal((int) $now->format('j')), 'month_year' => $now->format('F Y'),
        'barangay' => (string) setting('barangay_name', ''), 'municipality' => (string) setting('municipality', ''), 'province' => (string) setting('province', ''),
        'reference_no' => (string) ($r['reference_no'] ?? ''), 'captain_name' => (string) setting('captain_name', ''), 'document_name' => (string) $r['type_name'],
    ];
}
/** Reasons a certificate cannot be issued yet. Empty array = ready. Nothing here is guessed. */
function certificate_blockers(array $r, array $vars): array
{
    $b = [];
    if (($r['requirements_status'] ?? 'Not checked') !== 'Complete') {
        $b[] = 'The request requirements have not been marked Complete. Return to Request Tracker and finish the requirements review before issuing the certificate.';
    }
    if ($r['fee_amount'] === null) { $b[] = 'The fee for ' . $r['type_name'] . ' is not set yet. The Secretary must enter it in Settings, Documents and fees (0 means free).'; }
    $body = (string) ($r['template_body'] ?? '');
    if (trim($body) === '') { $b[] = 'The official wording for ' . $r['type_name'] . ' is not set yet. The Secretary must enter it in Settings, Documents and fees.'; }
    foreach (['captain_name' => 'the Barangay Captain\'s name (Settings, Barangay details)', 'address' => 'this resident\'s household address', 'purok' => 'this resident\'s Purok'] as $tok => $what) {
        if (str_contains($body, '{{' . $tok . '}}') && $vars[$tok] === '') { $b[] = 'The wording uses {{' . $tok . '}} but ' . $what . ' is empty.'; }
    }
    if (($r['resident_archived'] ?? null) !== null) { $b[] = 'This resident is archived. Restore the resident first.'; }
    return $b;
}
function v_or_number(string $s): bool { return preg_match('/^[A-Za-z0-9-]{1,30}$/', $s) === 1; }

/** Request + resident + household + document type, for issuing. */
function issuable_request(int $id): ?array
{
    return db_one('SELECT dr.request_id, dr.reference_no, dr.status, dr.requirements_status, dr.requirements_note, dr.fee_rule_id,
            dr.fee_category_snapshot, dr.fee_tier_snapshot, dr.fee_quantity, dr.fee_unit_rate, dr.quoted_fee_amount,
            dr.purpose, dr.resident_id, dr.type_id, r.last_name, r.first_name, r.middle_name, r.suffix, r.birthdate, r.civil_status,
            r.archived_at AS resident_archived, h.street_address, pk.label AS purok, dt.name AS type_name,
            COALESCE(dr.quoted_fee_amount, dt.fee_amount) AS fee_amount, dt.template_body
        FROM document_requests dr JOIN residents r ON r.resident_id = dr.resident_id JOIN document_types dt ON dt.type_id = dr.type_id
        LEFT JOIN households h ON h.household_id = r.household_id LEFT JOIN lookups pk ON pk.lookup_id = h.purok_id WHERE dr.request_id = ?', [$id]);
}

/**
 * Generate a downloadable certificate PDF. Dompdf is used when installed locally;
 * a small built-in PDF writer keeps offline/XAMPP installs usable without Composer.
 * The output is explicitly a draft until the Barangay approves the official layout.
 */
function certificate_pdf_bytes(array $issuance): string
{
    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $draft = 'DRAFT - PENDING OFFICIAL LAYOUT APPROVAL';
    $body = (string) ($issuance['body_text'] ?? '');
    $signature = trim((string) ($issuance['signature_text'] ?? ''));
    if ($signature === '') { $signature = trim((string) ($issuance['captain_name'] ?? '')); }
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
        . 'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:12px;color:#172033;margin:34px;}'
        . '.draft{border:2px solid #9b2c2c;color:#9b2c2c;text-align:center;font-weight:bold;padding:8px;margin-bottom:22px;}'
        . '.head{text-align:center;line-height:1.65}.title{text-align:center;font-size:20px;font-weight:bold;margin:28px 0;}'
        . '.body{font-size:13px;line-height:1.9;white-space:pre-wrap;min-height:220px;}'
        . '.sign{margin:48px 0 8px;text-align:center;font-weight:bold;text-decoration:underline;}'
        . '.muted{font-size:10px;color:#555;margin-top:28px;border-top:1px solid #aaa;padding-top:8px;}'
        . '</style></head><body><div class="draft">' . $draft . '</div>'
        . '<div class="head">Republic of the Philippines<br>Province of ' . $esc((string) setting('province', ''))
        . '<br>Municipality of ' . $esc((string) setting('municipality', '')) . '<br><strong>' . $esc(barangay_title()) . '</strong></div>'
        . '<div class="title">' . $esc((string) ($issuance['type_name'] ?? 'Certificate')) . '</div>'
        . '<div class="body">' . $esc($body) . '</div>'
        . '<div class="sign">' . $esc($signature !== '' ? $signature : 'Authorized signatory') . '</div>'
        . '<div style="text-align:center">Typed signature field — not a verified cryptographic digital signature.</div>'
        . '<div class="muted">Reference: ' . $esc((string) ($issuance['reference_no'] ?? ''))
        . ' | Issued: ' . $esc(isset($issuance['issued_at']) ? date('j F Y', strtotime((string) $issuance['issued_at'])) : date('j F Y'))
        . ' | Recorded issuance fee: PHP ' . $esc(number_format((float) ($issuance['fee_amount'] ?? 0), 2))
        . ' | O.R.: ' . $esc((string) ($issuance['or_number'] ?? '—')) . '</div></body></html>';

    if (class_exists('Dompdf\\Dompdf')) {
        $options = class_exists('Dompdf\\Options') ? new \Dompdf\Options() : null;
        if ($options !== null) { $options->set('isRemoteEnabled', false); $options->set('defaultFont', 'DejaVu Sans'); }
        $pdf = $options !== null ? new \Dompdf\Dompdf($options) : new \Dompdf\Dompdf();
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        return $pdf->output();
    }

    // Dependency-free PDF fallback: preserves the actual issuance text and metadata in a valid PDF.
    $lines = [$draft, '', (string) setting('province', '') . ' / ' . (string) setting('municipality', ''), barangay_title(), '',
        (string) ($issuance['type_name'] ?? 'Certificate'), ''];
    $rawBody = str_replace(["\r\n", "\r"], "\n", $body);
    foreach (explode("\n", $rawBody) as $paragraph) {
        foreach (explode("\n", wordwrap($paragraph, 86, "\n", true)) as $wrapped) { $lines[] = $wrapped; }
    }
    $lines[] = '';
    $lines[] = 'Typed signature: ' . ($signature !== '' ? $signature : 'Authorized signatory');
    $lines[] = 'Typed signature field - not a verified cryptographic digital signature.';
    $lines[] = 'Reference: ' . (string) ($issuance['reference_no'] ?? '');
    $lines[] = 'Issued: ' . (isset($issuance['issued_at']) ? date('j F Y', strtotime((string) $issuance['issued_at'])) : date('j F Y'));
    $lines[] = 'Recorded issuance fee: PHP ' . number_format((float) ($issuance['fee_amount'] ?? 0), 2);
    $lines[] = 'O.R.: ' . (string) ($issuance['or_number'] ?? '—');

    $pages = array_chunk($lines, 50);
    if (!$pages) { $pages = [['Certificate draft']]; }
    $objects = [];
    $pageIds = [];
    $fontId = 3 + count($pages) * 2;
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    foreach ($pages as $idx => $pageLines) {
        $pageId = 3 + ($idx * 2); $streamId = $pageId + 1; $pageIds[] = $pageId . ' 0 R';
        $stream = "BT\n/F1 10 Tf\n";
        $y = 790;
        foreach ($pageLines as $line) {
            $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $line) : (string) $line;
            if (!is_string($ascii)) { $ascii = preg_replace('/[^\x20-\x7E]/', '?', (string) $line) ?? ''; }
            $ascii = preg_replace('/[^\x20-\x7E]/', '?', $ascii) ?? '';
            $ascii = str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $ascii);
            $stream .= '1 0 0 1 48 ' . $y . ' Tm (' . $ascii . ") Tj\n";
            $y -= 14;
        }
        $stream .= "ET\n";
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' . $fontId . ' 0 R >> >> /Contents ' . $streamId . ' 0 R >>';
        $objects[$streamId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageIds) . '] /Count ' . count($pages) . ' >>';
    $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    ksort($objects);
    $pdf = "%PDF-1.4\n% BIRMS draft certificate\n"; $offsets = [0]; $maxId = max(array_keys($objects));
    for ($i = 1; $i <= $maxId; $i++) {
        $offsets[$i] = strlen($pdf);
        $pdf .= $i . " 0 obj\n" . ($objects[$i] ?? '<<>>') . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $maxId; $i++) { $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n"; }
    $pdf .= 'trailer << /Size ' . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}
