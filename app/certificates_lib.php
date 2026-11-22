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
        'full_name' => strtoupper(resident_name_natural($r)), 'age' => (string) age_on((string) $r['birthdate'], $now->format('Y-m-d')), 'civil_status' => (string) ($r['marital_status'] ?? $r['civil_status'] ?? ''),
        'address' => (string) ($r['street_address'] ?? $r['address'] ?? ''), 'purok' => (string) ($r['purok'] ?? ''), 'purpose' => (string) $r['purpose'],
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
    foreach (['captain_name' => 'the Barangay Captain\'s name (Settings, Barangay details)', 'address' => 'this resident\'s Address field', 'purok' => 'this resident\'s Purok'] as $tok => $what) {
        if (str_contains($body, '{{' . $tok . '}}') && $vars[$tok] === '') { $b[] = 'The wording uses {{' . $tok . '}} but ' . $what . ' is empty.'; }
    }
    if (($r['resident_archived'] ?? null) !== null) { $b[] = 'This resident is archived. Restore the resident first.'; }
    if ((int) ($r['requires_chairman_signature'] ?? 1) === 1 && chairman_signature_current_absolute() === null) {
        $b[] = 'The Admin must upload the Chairman / Punong Barangay signature image in Settings before this certificate can be issued.';
    }
    return $b;
}
function v_or_number(string $s): bool { return preg_match('/^[A-Za-z0-9-]{1,30}$/', $s) === 1; }

/** Request + resident + household + document type, for issuing. */
function issuable_request(int $id): ?array
{
    return db_one('SELECT dr.request_id, dr.reference_no, dr.status, dr.msit_status, dr.requirements_status, dr.requirements_note, dr.fee_rule_id,
            dr.fee_category_snapshot, dr.fee_tier_snapshot, dr.fee_quantity, dr.fee_unit_rate, dr.quoted_fee_amount,
            dr.purpose, dr.resident_id, dr.type_id, r.last_name, r.first_name, r.middle_name, r.suffix, r.birthdate, r.civil_status, r.marital_status,
            r.archived_at AS resident_archived, COALESCE(r.address, h.street_address) AS street_address, pk.label AS purok, dt.name AS type_name, dt.requires_chairman_signature,
            COALESCE(dr.quoted_fee_amount, dt.fee_amount) AS fee_amount, dt.template_body
        FROM document_requests dr JOIN residents r ON r.resident_id = dr.resident_id JOIN document_types dt ON dt.type_id = dr.type_id
        LEFT JOIN households h ON h.household_id = r.household_id LEFT JOIN lookups pk ON pk.lookup_id = COALESCE(r.purok_id, h.purok_id) WHERE dr.request_id = ?', [$id]);
}

/**
 * Render the deliberately small, safe Markdown subset used by the document editor.
 * Escape first, then introduce only fixed formatting tags; user HTML is never trusted.
 */
function certificate_body_html(string $body): string
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $inline = static function (string $line) use ($escape): string {
        $line = $escape($line);
        $line = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $line) ?? $line;
        $line = preg_replace('/__(.+?)__/u', '<u>$1</u>', $line) ?? $line;
        $line = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/u', '<em>$1</em>', $line) ?? $line;
        return $line;
    };

    $html = [];
    $paragraph = [];
    $list = null;
    $flushParagraph = static function () use (&$paragraph, &$html, $inline): void {
        if ($paragraph) {
            $html[] = '<p>' . implode('<br>', array_map($inline, $paragraph)) . '</p>';
            $paragraph = [];
        }
    };
    $closeList = static function () use (&$list, &$html): void {
        if ($list !== null) { $html[] = '</' . $list . '>'; $list = null; }
    };

    foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') { $flushParagraph(); $closeList(); continue; }
        if (preg_match('/^---+$/', $trimmed) === 1) {
            $flushParagraph(); $closeList(); $html[] = '<hr>'; continue;
        }
        if (preg_match('/^(#{1,3})\s+(.+)$/u', $trimmed, $m) === 1) {
            $flushParagraph(); $closeList();
            $level = strlen($m[1]) + 1;
            $html[] = '<h' . $level . '>' . $inline($m[2]) . '</h' . $level . '>';
            continue;
        }
        if (preg_match('/^[-*]\s+(.+)$/u', $trimmed, $m) === 1) {
            $flushParagraph();
            if ($list !== 'ul') { $closeList(); $html[] = '<ul>'; $list = 'ul'; }
            $html[] = '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^\d+[.)]\s+(.+)$/u', $trimmed, $m) === 1) {
            $flushParagraph();
            if ($list !== 'ol') { $closeList(); $html[] = '<ol>'; $list = 'ol'; }
            $html[] = '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        $closeList();
        $paragraph[] = $line;
    }
    $flushParagraph();
    $closeList();
    return implode("\n", $html);
}

/** Plain-text equivalent for the dependency-free PDF writer. */
function certificate_body_plain_text(string $body): string
{
    $body = preg_replace('/^#{1,3}\s+/m', '', $body) ?? $body;
    $body = preg_replace('/^[-*]\s+/m', '• ', $body) ?? $body;
    $body = preg_replace('/^\d+[.)]\s+/m', '• ', $body) ?? $body;
    return str_replace(['**', '__', '*'], '', $body);
}

/**
 * Generate a downloadable certificate PDF. Dompdf is used when installed locally;
 * a small built-in PDF writer keeps offline/XAMPP installs usable without Composer.
 * The output is explicitly a draft until the Barangay approves the official layout.
 */
function certificate_pdf_bytes(array $issuance): string
{
    $autoload = APP_ROOT . '/vendor/autoload.php';
    if (is_file($autoload)) { require_once $autoload; }
    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $isCertificateDraft = !empty($issuance['_is_draft']);
    $draft = $isCertificateDraft ? 'DRAFT - NOT ISSUED - PENDING OFFICIAL LAYOUT APPROVAL' : 'DRAFT - PENDING OFFICIAL LAYOUT APPROVAL';
    $dateLabel = $isCertificateDraft ? 'Generated: ' : 'Issued: ';
    $feeLabel = $isCertificateDraft ? 'Quoted fee: PHP ' : 'Recorded issuance fee: PHP ';
    $orLabel = $isCertificateDraft ? 'O.R.: Not recorded' : 'O.R.: ' . (string) ($issuance['or_number'] ?? '—');
    $body = (string) ($issuance['body_text'] ?? '');
    $signature = trim((string) ($issuance['captain_name'] ?? ''));
    $signatureImage = chairman_signature_read(isset($issuance['chairman_signature_path']) ? (string) $issuance['chairman_signature_path'] : null);
    $signatureImageHtml = $signatureImage !== null
        ? '<div class="signature-image"><img src="data:image/jpeg;base64,' . base64_encode($signatureImage['bytes']) . '" alt="Chairman signature"></div>'
        : '';
    if (!empty($issuance['chairman_signature_path']) && $signatureImage === null) {
        throw new RuntimeException('The saved Chairman signature image for this issuance is unavailable. Restore the private signature file from backup before generating this PDF.');
    }
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
        . 'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:12px;color:#172033;margin:34px;}'
        . '.draft{border:2px solid #9b2c2c;color:#9b2c2c;text-align:center;font-weight:bold;padding:8px;margin-bottom:22px;}'
        . '.head{text-align:center;line-height:1.65}.title{text-align:center;font-size:20px;font-weight:bold;margin:28px 0;}'
        . '.body{font-size:13px;line-height:1.9;white-space:normal;min-height:220px;}'
        . '.body p{margin:0 0 12px;}.body h2,.body h3,.body h4{text-align:center;margin:18px 0 10px;}.body ul,.body ol{margin:8px 0 14px;padding-left:28px;}.body hr{border:0;border-top:1px solid #8793a5;margin:14px 0;}'
        . '.signature-image{text-align:center;margin:28px auto 4px;}.signature-image img{max-width:190px;max-height:80px;object-fit:contain;}'
        . '.sign{text-align:center;font-weight:bold;text-decoration:underline;margin:2px 0 4px;}'
        . '.signature-note{text-align:center;font-size:10px;color:#555;}'
        . '.muted{font-size:10px;color:#555;margin-top:28px;border-top:1px solid #aaa;padding-top:8px;}'
        . '</style></head><body><div class="draft">' . $draft . '</div>'
        . '<div class="head">Republic of the Philippines<br>Province of ' . $esc((string) setting('province', ''))
        . '<br>Municipality of ' . $esc((string) setting('municipality', '')) . '<br><strong>' . $esc(barangay_title()) . '</strong></div>'
        . '<div class="title">' . $esc((string) ($issuance['type_name'] ?? 'Certificate')) . '</div>'
        . '<div class="body">' . certificate_body_html($body) . '</div>'
        . $signatureImageHtml
        . '<div class="sign">' . $esc($signature !== '' ? $signature : 'Chairman / Punong Barangay') . '</div>'
        . '<div class="signature-note">Chairman / Punong Barangay</div>'
        . '<div class="muted">Reference: ' . $esc((string) ($issuance['reference_no'] ?? ''))
        . ' | ' . $esc($dateLabel) . $esc(isset($issuance['issued_at']) ? date('j F Y', strtotime((string) $issuance['issued_at'])) : date('j F Y'))
        . ' | ' . $esc($feeLabel) . $esc(number_format((float) ($issuance['fee_amount'] ?? 0), 2))
        . ' | ' . $esc($orLabel) . '</div></body></html>';

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
    $rawBody = str_replace(["\r\n", "\r"], "\n", certificate_body_plain_text($body));
    foreach (explode("\n", $rawBody) as $paragraph) {
        foreach (explode("\n", wordwrap($paragraph, 86, "\n", true)) as $wrapped) { $lines[] = $wrapped; }
    }
    $lines[] = '';
    $lines[] = 'Chairman / Punong Barangay: ' . ($signature !== '' ? $signature : 'Authorized signatory');
    $lines[] = 'Reference: ' . (string) ($issuance['reference_no'] ?? '');
    $lines[] = $dateLabel . (isset($issuance['issued_at']) ? date('j F Y', strtotime((string) $issuance['issued_at'])) : date('j F Y'));
    $lines[] = $feeLabel . number_format((float) ($issuance['fee_amount'] ?? 0), 2);
    $lines[] = $orLabel;

    $pages = array_chunk($lines, 50);
    if (!$pages) { $pages = [['Certificate draft']]; }
    $objects = [];
    $pageIds = [];
    $fontId = 3 + count($pages) * 2;
    $signatureImageId = $signatureImage !== null ? $fontId + 1 : null;
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
        if ($idx === 0 && $signatureImageId !== null && $signatureImage !== null) {
            $imageWidth = 150.0;
            $imageHeight = min(64.0, $imageWidth * $signatureImage['height'] / max(1, $signatureImage['width']));
            $imageY = max(50.0, $y - $imageHeight - 8.0);
            $stream .= "q\n" . number_format($imageWidth, 2, '.', '') . " 0 0 "
                . number_format($imageHeight, 2, '.', '') . " 220 "
                . number_format($imageY, 2, '.', '') . " cm\n/ChairmanSig Do\nQ\n";
        }
        $resourceEntries = '/Font << /F1 ' . $fontId . ' 0 R >>';
        if ($signatureImageId !== null) { $resourceEntries .= ' /XObject << /ChairmanSig ' . $signatureImageId . ' 0 R >>'; }
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << ' . $resourceEntries . ' >> /Contents ' . $streamId . ' 0 R >>';
        $objects[$streamId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageIds) . '] /Count ' . count($pages) . ' >>';
    $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    if ($signatureImageId !== null && $signatureImage !== null) {
        $objects[$signatureImageId] = '<< /Type /XObject /Subtype /Image /Width ' . $signatureImage['width']
            . ' /Height ' . $signatureImage['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '
            . strlen($signatureImage['bytes']) . " >>\nstream\n" . $signatureImage['bytes'] . "\nendstream";
    }
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


/** Return the automatically generated, immutable certificate draft for a request. */
function certificate_draft_for_request(int $requestId): ?array
{
    return db_one('SELECT * FROM certificate_drafts WHERE request_id = ?', [$requestId]);
}

/**
 * Generate a complete certificate draft after approval, inside the caller's transaction.
 * This deliberately creates no issuance record, O.R. entry, cash-ledger row, or Released status.
 */
function certificate_draft_create(int $requestId): array
{
    $existing = certificate_draft_for_request($requestId);
    if ($existing !== null) { return $existing; }

    $request = issuable_request($requestId);
    if ($request === null || (string) $request['status'] !== 'Ready') {
        throw new RuntimeException('Only an approved request can generate a certificate draft.');
    }

    $year = (int) date('Y');
    $certificateReference = sprintf('CERT-%d-%04d', $year, next_counter('certificate', $year));
    $requestForDraft = $request;
    $requestForDraft['reference_no'] = $certificateReference;
    $vars = certificate_vars($requestForDraft);
    $blockers = certificate_blockers($request, $vars);
    if ($blockers) {
        throw new InvalidArgumentException('The request cannot be approved until its certificate draft can be generated: ' . implode(' ', $blockers));
    }

    $requiresSignature = (int) ($request['requires_chairman_signature'] ?? 1) === 1;
    $signaturePath = $requiresSignature ? chairman_signature_current_relative() : null;
    if ($requiresSignature && chairman_signature_read($signaturePath) === null) {
        throw new InvalidArgumentException('The Chairman signature image is unavailable. Ask Admin to verify the signature in Settings before approving this request.');
    }

    $actor = audit_actor();
    $body = render_template((string) $request['template_body'], $vars);
    db_exec(
        'INSERT INTO certificate_drafts
         (request_id, resident_id, type_id, request_reference_no, certificate_reference_no, resident_name, type_name, purpose,
          fee_amount, fee_rule_id, fee_category_snapshot, fee_tier_snapshot, fee_quantity, fee_unit_rate,
          body_text, captain_name, signature_text, chairman_signature_path, generated_by, generated_by_name, generated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
        [
            $requestId, $request['resident_id'], $request['type_id'], $request['reference_no'],
            $certificateReference, resident_name_natural($request), $request['type_name'], $request['purpose'], $request['fee_amount'],
            $request['fee_rule_id'] ?? null, $request['fee_category_snapshot'] ?? null,
            $request['fee_tier_snapshot'] ?? null, $request['fee_quantity'] ?? 1,
            $request['fee_unit_rate'] ?? null, $body,
            $vars['captain_name'] !== '' ? $vars['captain_name'] : null,
            $vars['captain_name'] !== '' ? $vars['captain_name'] : null,
            $signaturePath, $actor['user_id'] ?? null,
            (string) ($actor['full_name'] ?? 'system'),
        ]
    );
    $draftId = (int) db()->lastInsertId();
    audit('certificate_draft_generated', 'Certificates', 'Success',
        'Generated certificate draft for ' . $request['reference_no'] . ' (' . $request['type_name'] . ')',
        'certificate_draft', $draftId);
    $draft = db_one('SELECT * FROM certificate_drafts WHERE draft_id = ?', [$draftId]);
    if ($draft === null) { throw new RuntimeException('The generated certificate draft could not be reloaded.'); }
    return $draft;
}
