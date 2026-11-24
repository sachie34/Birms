<?php
/** Document request rules: who may move a request to which status and reference numbers. */
declare(strict_types=1);

/** current status => [new status => permission needed]. Ready -> Claimed happens only when a certificate is issued (B5). */
const REQUEST_TRANSITIONS = [
    // A final decision cannot be made before requirements review has begun.
    'Pending' => ['Review' => 'requests.review', 'Cancelled' => 'requests.edit'],
    'Review'  => ['Ready' => 'requests.approve', 'Rejected' => 'requests.approve', 'Cancelled' => 'requests.edit'],
];
const REQUEST_STATUSES = ['Pending', 'Review', 'Ready', 'Claimed', 'Rejected', 'Cancelled'];

function request_allowed_moves(string $status, ?string $requirementsStatus = null): array
{
    $out = [];
    foreach (REQUEST_TRANSITIONS[$status] ?? [] as $to => $perm) {
        // The UI must never offer approval/rejection unless the requirements were checked and completed.
        if (in_array($to, ['Ready', 'Rejected'], true) && $requirementsStatus !== 'Complete') {
            continue;
        }
        if (can($perm)) { $out[] = $to; }
    }
    return $out;
}

/**
 * Apply row-level privacy to Clerk request access. Other staff roles retain their
 * documented cross-request work queues; Clerks can only open requests they filed
 * or that are explicitly assigned to their account.
 */
function request_visible_to_current_user(array $request): bool
{
    $user = auth_user();
    if ($user === null) {
        return false;
    }

    $role = (string) ($user['role'] ?? '');
    if (in_array($role, ['admin', 'captain', 'secretary'], true)) {
        return true;
    }

    $userId = (int) ($user['user_id'] ?? 0);
    if ($userId < 1) { return false; }
    $ownsOrAssigned = (int) ($request['created_by'] ?? 0) === $userId
        || (int) ($request['assigned_to'] ?? 0) === $userId;
    // Staff also receives the shared final-decision queue after the Secretary has completed review.
    $readyForStaffDecision = $role === 'clerk'
        && (string) ($request['status'] ?? '') === 'Review'
        && (string) ($request['requirements_status'] ?? '') === 'Complete';
    $approvedForStaffIssue = $role === 'clerk'
        && in_array((string) ($request['status'] ?? ''), ['Ready', 'Claimed'], true);
    return $ownsOrAssigned || $readyForStaffDecision || $approvedForStaffIssue;
}
function new_request_reference(): string
{
    $y = (int) date('Y');
    return sprintf('REQ-%d-%04d', $y, next_counter('request', $y));
}

