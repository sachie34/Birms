<?php
/** Small HTML helpers so every page builds forms and cards the same way. All values are escaped here. */
declare(strict_types=1);

function page_head(string $title, string $sub = '', string $actionsHtml = ''): void
{
    echo '<div class="page-head"><div><h1>' . e($title) . '</h1>' . ($sub !== '' ? '<p>' . e($sub) . '</p>' : '') . '</div>'
       . ($actionsHtml !== '' ? '<div class="d-flex gap-2 flex-wrap">' . $actionsHtml . '</div>' : '') . '</div>';
    echo page_help_html();
}

/**
 * Small, role-aware instructions shown under page titles on everyday screens.
 * This is intentionally practical: what to do first, what to do next, and what to check.
 */
function current_page_help(): ?array
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $marker = '/public/';
    $pos = strripos($script, $marker);
    $route = $pos === false ? basename($script) : substr($script, $pos + strlen($marker));
    $user = function_exists('auth_user') ? auth_user() : null;
    $role = (string) ($user['role'] ?? '');

    $general = static function (string $intro, array $steps, string $note = ''): array {
        return ['intro' => $intro, 'steps' => $steps, 'note' => $note];
    };

    $help = [
        'residents/index.php' => $general('Find a resident before creating a new record.', [
            'Search by name or household code; use the filters to narrow the list.',
            'Open the matching profile to check the details before making changes.',
            'Use Add Resident only when the person is not already registered and your role allows it.',
        ], 'Residents are archived rather than permanently deleted.'),
        'residents/form.php' => $general('Enter the resident details carefully, then save the record.', [
            'Check whether the resident already exists before creating a new profile.',
            'Complete required fields and choose the correct household, Purok and residency status.',
            'Review the information once more, then save and open the resident profile.',
        ], 'Use real, authorized records only in the official database.'),
        'residents/view.php' => $general('Use this profile to confirm a resident’s saved information.', [
            'Check the name, household, address and current residency status.',
            'Review related requests and verification history when available.',
            'Use Edit or Verify only if the action is shown and you have permission.',
        ], 'A recorded voter/ID check is manual; BIRMS does not contact COMELEC.'),
        'residents/households.php' => $general('Use households to organize residents by address and Purok.', [
            'Search for the household before adding or changing one.',
            'Open the household to check its address, Purok and linked residents.',
            'Correct the household record only after confirming the information.',
        ]),
        'residents/household_edit.php' => $general('Create or update one household record.', [
            'Enter the confirmed household address and select the correct Purok.',
            'Check the household head and related resident details.',
            'Save, then return to the household list to confirm the change.',
        ]),
        'residents/import.php' => $general('Import residents from a prepared CSV file.', [
            'Download the BIRMS template and follow its column names and format.',
            'Upload the CSV and review the preview and every validation error.',
            'Apply the import only after checking the preview; then review the imported residents.',
        ], 'The importer skips duplicates rather than overwriting existing resident data. Use fictitious data while testing.'),
        'residents/import_errors.php' => $general('Fix the rows listed in the import error file.', [
            'Download or open the error CSV and read the message for each rejected row.',
            'Correct the source CSV using the import template.',
            'Return to Resident Import and preview the corrected file before applying it.',
        ]),
        'residents/import_template.php' => $general('Use this file as the required CSV column template.', [
            'Download the template.',
            'Keep the column headers unchanged and enter one resident per row.',
            'Upload the completed CSV on Resident Import to preview it before applying.',
        ]),
        'residents/verify.php' => $general('Record the result of a manual ID or voter-list check.', [
            'Confirm that you have the correct resident profile.',
            'Enter only the result you actually checked and add a short note if needed.',
            'Save the verification record and review its date and result on the profile.',
        ], 'This records a staff check; it is not an automated COMELEC lookup.'),
        'requests/index.php' => $role === 'captain'
            ? $general('Your main task here is the final decision on eligible requests.', [
                'Open Pending Final Approval and check the resident and document details.',
                'Approve or reject only after the requirements review is marked Complete.',
                'Give a clear rejection reason, or approve the request for Ready for Pickup.',
            ], 'There is no separate saved “Approved” status; approval moves the request to Ready for Pickup.')
            : ($role === 'secretary'
                ? $general('Your main task here is to check request requirements.', [
                    'Open Needs Review and select a pending request.',
                    'Check the required supporting documents and mark the checklist Complete only when verified.',
                    'Leave incomplete requests in review and record what is missing.',
                ], 'The Captain or Administrator makes the final request decision.')
                : ($role === 'clerk'
                    ? $general('Use My Requests to follow requests you filed or are assigned to.', [
                        'Choose New Request to start a request for an existing active resident.',
                        'Open a request to check its status and any missing information.',
                        'Use the progress tracker to see what step comes next.',
                    ], 'You cannot complete the formal requirements review, approve/reject requests or issue certificates.')
                    : $general('Use the queue that matches the task you need to complete.', [
                        'Open Needs Review to check requirements, or Pending Final Approval to decide an eligible request.',
                        'Open a request and read the progress tracker and blockers.',
                        'Use only the actions permitted at the current step, then check the saved status.',
                    ], 'Secretary/Admin review requirements; Captain/Admin make the final decision.'))),
        'requests/form.php' => $general('Create a document request for the correct resident.', [
            'Find and select an existing active resident first.',
            'Choose the document type, enter the purpose and add any required supporting files.',
            'Save the request, then open Request Tracker to follow its progress.',
        ], 'If the resident or document type is missing, ask an authorized user to set it up instead of choosing a different record.'),
        'requests/view.php' => $general('Use the progress tracker to see what must happen next.', [
            'Check the resident, document type, current status and requirements.',
            'Read any warning under the tracker; it explains why an action may be blocked.',
            'Choose only an available action for your role and check the updated status after saving.',
        ], 'A name match in the blotter is only a clue for staff review, not proof of identity.'),
        'certificates/issue.php' => $general('Issue a certificate only from a request that is ready.', [
            'Confirm the resident, document type, approved wording and signatory details in the preview.',
            'Check the displayed fee and enter the correct O.R. number when required.',
            'Issue only after all blockers are cleared, then open the print/PDF copy and issuance history.',
        ], 'The current template is marked DRAFT until the barangay approves the official layout and wording.'),
        'certificates/history.php' => $general('Find a previously issued certificate.', [
            'Search by resident name, reference number or O.R. number.',
            'Check the date and document details before opening a record.',
            'Use the available print, download or export action for the record you need.',
        ]),
        'certificates/print.php' => $general('Review the issued certificate before printing or saving it.', [
            'Check the resident and certificate details on the page.',
            'Use Print to open the browser print dialog, or save as PDF if needed.',
            'Choose the correct printer or save location and keep the file private.',
        ], 'A DRAFT-marked template is not an approved official certificate.'),
        'visitors/index.php' => $general('Record visitors at the counter and connect them to a service when needed.', [
            'Enter the visitor’s details and reason for visiting.',
            'Save the visitor entry and check that it appears in the logbook.',
            'For a document request, use Start a document request and complete the request form.',
        ], 'Collect only information needed for the office task.'),
        'blotters/index.php' => $general('Use the blotter list to file and follow up incidents.', [
            'Search for an existing case before filing a new one.',
            'Choose File a Case and record the incident details and known parties accurately.',
            'Open the saved case to check its status, schedule a hearing or record an outcome if permitted.',
        ], 'A possible name match is not proof of identity. Handle case details confidentially.'),
        'blotters/form.php' => $general('Record the incident using the information actually reported.', [
            'Enter the date, place and a clear factual narrative.',
            'Add complainant/respondent details only as known; avoid guessing missing information.',
            'Save the case and open it to review the case number and next permitted action.',
        ], 'Use fictitious cases for demonstrations and testing.'),
        'blotters/view.php' => $general('Use the case page to follow an incident through its next step.', [
            'Read the incident narrative and confirm the listed parties.',
            'Check the current case status and any saved hearing or outcome.',
            'Update the status, schedule a hearing or record an outcome only if the action is available to your role.',
        ]),
        'blotters/party.php' => $general('Add or correct a complainant/respondent linked to this case.', [
            'Confirm that you opened the correct case.',
            'Enter the party details using the information provided or verified by the office.',
            'Save and return to the case to check that the party is linked correctly.',
        ], 'Do not treat a similar name as conclusive identity evidence.'),
        'blotters/lookup.php' => $general('Search existing local cases by name or case information.', [
            'Enter the most useful name or case detail and search.',
            'Compare the result with available age, address and case details.',
            'Refer a possible match to authorized staff for review before taking action.',
        ], 'A lookup result is not proof of identity and should not automatically determine a certificate decision.'),
        'blotters/hearings.php' => $role === 'clerk'
            ? $general('Use this page to view hearing schedules available to your role.', [
                'Check the case reference, date, time and venue.',
                'Open the related case if you need more context.',
                'Refer changes or hearing outcomes to an authorized Secretary or Captain.',
            ])
            : $general('Use the hearing list to manage scheduled case meetings.', [
                'Open a case and schedule a hearing with the confirmed date, time and venue.',
                'Check the list for upcoming or overdue hearings.',
                'Record the actual outcome after the hearing and verify the saved case status.',
            ]),
        'blotters/summons.php' => $general('Review the case and hearing details before preparing a summons.', [
            'Confirm the parties, case reference, hearing date, time and venue.',
            'Review the generated text carefully for missing or incorrect details.',
            'Print or save only when the barangay has approved the summons wording and format.',
        ], 'Do not treat unapproved wording as an official summons.'),
        'reports/index.php' => $general('Choose a report, then check its figures before sharing it.', [
            'Select the report type and the requested reporting period.',
            'Review the totals and the notes that explain which records are counted.',
            'Print or export CSV, then confirm the figures and recipient before sending.',
        ], 'Agency-specific DILG/Mayor/PNP templates and required fields still need approval.'),
        'admin/users.php' => $general('Use User Accounts to control who can sign in.', [
            'Create an individual account for each authorized person.',
            'Assign the role approved for that person and check it before saving.',
            'Deactivate accounts that no longer need access and never share passwords.',
        ], 'The current role matrix is provisional until the barangay approves it.'),
        'admin/user_edit.php' => $general('Update this user’s account and approved role.', [
            'Confirm the account belongs to the intended person.',
            'Change only the details and role authorized by the system owner.',
            'Save, then verify that the account has only the access it needs.',
        ], 'Avoid sharing one account across multiple people.'),
        'admin/settings.php' => $role === 'captain'
            ? $general('Review the official settings that your role is allowed to view.', [
                'Check the barangay details, document options and approved values.',
                'Report missing or incorrect settings to the Secretary/Administrator.',
                'Do not use draft certificate wording, fee rules or signatory details as official.',
            ])
            : $general('Maintain only settings approved by the barangay.', [
                'Check barangay details, Purok lists, document types, wording and fee rules.',
                'Enter the approved values exactly; leave unknown values unset rather than guessing.',
                'Save changes and revisit the related page to confirm the setting is applied.',
            ], 'The fee schedule, certificate wording and signatory policy must be approved by the barangay.'),
        'admin/ledger.php' => $role === 'captain'
            ? $general('Use this page to review recorded cash-ledger entries.', [
                'Check the amount, date, receipt reference and reconciliation state.',
                'Compare entries with the office’s actual receipt and cash records.',
                'Ask the authorized ledger manager to correct or reconcile an entry when needed.',
            ], 'A ledger status is not independent proof of a bank deposit.')
            : $general('Record and reconcile cash transactions using actual office evidence.', [
                'Match the entry to the issued document and its O.R. or official receipt.',
                'Enter the actual transaction date, collector and amount from the source record.',
                'Reconcile only after checking the office evidence, then review the saved totals.',
            ], 'The ledger records staff-entered information; it is not an online payment gateway or proof of deposit.'),
        'admin/backup.php' => $general('Create a private backup and protect the file.', [
            'Confirm the configured database is the intended working/authorized database.',
            'Download the SQL backup and store it in an approved access-controlled location.',
            'If cloud transfer is configured, verify the result and test restoration separately in a scratch database.',
        ], 'A successful download or upload does not prove that the backup can be restored.'),
        'admin/audit.php' => $general('Use the audit trail to review recorded system actions.', [
            'Set the filters or search terms to find the relevant user or action.',
            'Check the timestamp, result, target record and details together.',
            'Export only when authorized and keep the file confidential.',
        ]),
        'admin/sms.php' => $general('Review the notification queue and delivery results.', [
            'Check the provider, recipient and recorded message status.',
            'Treat Queued/Pending as accepted for processing, not proof of delivery to a phone.',
            'Retry only when the provider status and office policy allow it.',
        ], 'SMS is disabled until an administrator configures a provider and credentials. Keep API keys private.'),
        'search.php' => $general('Use global search to find records your role may view.', [
            'Enter a name, request reference, certificate/O.R. number or case detail.',
            'Open the matching result and confirm its details before acting.',
            'If a record is not shown, check spelling, filters and your access permissions.',
        ], 'Search results are limited to records your account is allowed to access.'),
        'account/password.php' => $general('Change your own password securely.', [
            'Enter your current password.',
            'Choose a strong new password that you do not use for another account.',
            'Save the change and use the new password the next time you sign in.',
        ], 'Never send your password to another user or include it in screenshots.'),
    ];

    return $help[$route] ?? null;
}

function page_help_html(): string
{
    $help = current_page_help();
    if ($help === null) { return ''; }
    $html = '<details class="card-b mb-3 page-help" open><summary class="card-h" style="cursor:pointer;list-style-position:inside">'
        . '<strong><i class="bi bi-info-circle me-2" aria-hidden="true"></i>How to use this page</strong>'
        . '<span class="text-muted small ms-2">Click to show or hide</span></summary><div class="card-body-b">';
    if (!empty($help['intro'])) { $html .= '<p class="mb-2">' . e((string) $help['intro']) . '</p>'; }
    $html .= '<ol class="mb-2 ps-4">';
    foreach (($help['steps'] ?? []) as $step) { $html .= '<li class="mb-1">' . e((string) $step) . '</li>'; }
    $html .= '</ol>';
    if (!empty($help['note'])) { $html .= '<p class="small text-muted mb-0"><strong>Remember:</strong> ' . e((string) $help['note']) . '</p>'; }
    return $html . '</div></details>';
}

function btn_link(string $path, string $label, string $class = 'btn-primary', string $icon = ''): string
{
    return '<a class="btn ' . e($class) . '" href="' . e(base_url($path)) . '">' . ($icon !== '' ? '<i class="bi ' . e($icon) . ' me-1"></i>' : '') . e($label) . '</a>';
}
function card_start(string $title = '', string $sub = '', string $extraClass = ''): void
{
    echo '<div class="card-b ' . e($extraClass) . '">';
    if ($title !== '') { echo '<div class="card-h"><h2>' . e($title) . '</h2>' . ($sub !== '' ? '<p>' . e($sub) . '</p>' : '') . '</div>'; }
    echo '<div class="card-body-b">';
}
function card_end(): void { echo '</div></div>'; }
function errors_box(array $errors): string
{
    if (!$errors) { return ''; }
    $li = ''; foreach ($errors as $er) { $li .= '<li>' . e((string) $er) . '</li>'; }
    return '<div class="alert alert-danger"><ul class="mb-0">' . $li . '</ul></div>';
}
function empty_state(string $icon, string $text): string
{
    return '<div class="empty-state"><i class="bi ' . e($icon) . '"></i>' . e($text) . '</div>';
}
function f_text(string $name, string $label, string $value = '', array $o = []): string
{
    $req = !empty($o['required']); $id = 'f_' . $name;
    $a = ' id="' . e($id) . '" name="' . e($name) . '" type="' . e((string) ($o['type'] ?? 'text')) . '" value="' . e($value) . '" class="form-control"';
    foreach (['maxlength', 'minlength', 'pattern', 'placeholder', 'min', 'max', 'step', 'autocomplete', 'inputmode', 'list'] as $k) { if (isset($o[$k])) { $a .= ' ' . $k . '="' . e((string) $o[$k]) . '"'; } }
    if ($req) { $a .= ' required'; } if (!empty($o['readonly'])) { $a .= ' readonly'; } if (!empty($o['disabled'])) { $a .= ' disabled'; }
    return '<div class="' . e((string) ($o['col'] ?? 'col-md-6')) . ' mb-3"><label class="form-label' . ($req ? ' req' : '') . '" for="' . e($id) . '">' . e($label) . '</label><input' . $a . '>'
         . (isset($o['help']) ? '<div class="form-text">' . e((string) $o['help']) . '</div>' : '') . '</div>';
}
function f_select(string $name, string $label, array $options, string $selected = '', array $o = []): string
{
    $req = !empty($o['required']); $id = 'f_' . $name;
    $h = '<div class="' . e((string) ($o['col'] ?? 'col-md-6')) . ' mb-3"><label class="form-label' . ($req ? ' req' : '') . '" for="' . e($id) . '">' . e($label) . '</label>'
       . '<select class="form-select" id="' . e($id) . '" name="' . e($name) . '"' . ($req ? ' required' : '') . (!empty($o['disabled']) ? ' disabled' : '') . '>';
    if (isset($o['blank'])) { $h .= '<option value="">' . e((string) $o['blank']) . '</option>'; }
    foreach ($options as $val => $lab) { $h .= '<option value="' . e((string) $val) . '"' . ((string) $val === $selected ? ' selected' : '') . '>' . e((string) $lab) . '</option>'; }
    return $h . '</select>' . (isset($o['help']) ? '<div class="form-text">' . e((string) $o['help']) . '</div>' : '') . '</div>';
}
function f_textarea(string $name, string $label, string $value = '', array $o = []): string
{
    $req = !empty($o['required']); $id = 'f_' . $name;
    return '<div class="' . e((string) ($o['col'] ?? 'col-12')) . ' mb-3"><label class="form-label' . ($req ? ' req' : '') . '" for="' . e($id) . '">' . e($label) . '</label>'
         . '<textarea class="form-control" id="' . e($id) . '" name="' . e($name) . '" rows="' . (int) ($o['rows'] ?? 4) . '"' . (isset($o['maxlength']) ? ' maxlength="' . (int) $o['maxlength'] . '"' : '') . ($req ? ' required' : '') . (!empty($o['disabled']) ? ' disabled' : '') . '>' . e($value) . '</textarea>'
         . (isset($o['help']) ? '<div class="form-text">' . e((string) $o['help']) . '</div>' : '') . '</div>';
}
function f_check(string $name, string $label, bool $checked = false, string $col = 'col-12'): string
{
    return '<div class="' . e($col) . ' mb-3"><div class="form-check"><input class="form-check-input" type="checkbox" id="f_' . e($name) . '" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . '><label class="form-check-label" for="f_' . e($name) . '">' . e($label) . '</label></div></div>';
}
/** A one-button POST form (with CSRF token and optional confirmation dialog). */
function btn_post(string $path, array $hidden, string $label, string $class = 'btn-light border', string $confirm = '', string $icon = ''): string
{
    $h = '<form method="post" action="' . e(base_url($path)) . '" class="d-inline"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field();
    foreach ($hidden as $k => $v) { $h .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">'; }
    return $h . '<button class="btn ' . e($class) . '" type="submit">' . ($icon !== '' ? '<i class="bi ' . e($icon) . ' me-1"></i>' : '') . e($label) . '</button></form>';
}
function status_pill(string $s): string
{
    static $map = [
        'Pending' => 'amber', 'Review' => 'blue', 'Ready' => 'green', 'Claimed' => 'grey', 'Rejected' => 'red', 'Cancelled' => 'grey',
        'Active' => 'blue', 'Scheduled' => 'blue', 'Resolved' => 'green', 'Held' => 'green', 'Postponed' => 'amber',
        'Relocated' => 'grey', 'Registered' => 'green', 'Not registered' => 'grey', 'Unverified' => 'amber',
        'Verified' => 'green', 'Not found' => 'amber', 'Logged only' => 'grey', 'No number' => 'amber',
    ];
    // Persisted request enum values remain compatible with the Project Summary; display labels use the SOE2 wording.
    static $labels = ['Review' => 'Under Review', 'Ready' => 'Ready for Pickup', 'Claimed' => 'Issued'];
    return pill($labels[$s] ?? $s, $map[$s] ?? 'grey');
}
/** Active residents show green; use for a residency status specifically. */
function residency_pill(string $s): string { return $s === 'Active' ? pill('Active', 'green') : pill($s, 'grey'); }
