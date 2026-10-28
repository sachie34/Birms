<?php
/** Small HTML helpers so every page builds forms and cards the same way. All values are escaped here. */
declare(strict_types=1);

function page_head(string $title, string $sub = '', string $actionsHtml = ''): void
{
    echo '<div class="page-head"><div><h1>' . e($title) . '</h1>' . ($sub !== '' ? '<p>' . e($sub) . '</p>' : '') . '</div>'
       . ($actionsHtml !== '' ? '<div class="d-flex gap-2 flex-wrap">' . $actionsHtml . '</div>' : '') . '</div>';
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
