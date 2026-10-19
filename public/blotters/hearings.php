<?php
/** Hearing agenda: what is coming up, what is overdue for an outcome, and what has already happened. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('hearings.view');
$tabs = ['upcoming' => 'Upcoming', 'overdue' => 'Outcome needed', 'past' => 'Past'];
$tab = get_str('tab'); $tab = isset($tabs[$tab]) ? $tab : 'upcoming';
$page = max(1, get_int('page') ?? 1); $per = 25;
$cond = ['upcoming' => "h.status = 'Scheduled' AND h.scheduled_at >= NOW()", 'overdue' => "h.status = 'Scheduled' AND h.scheduled_at < NOW()", 'past' => "h.status <> 'Scheduled'"];
$order = $tab === 'upcoming' ? 'h.scheduled_at ASC' : 'h.scheduled_at DESC';
$counts = [];
foreach ($cond as $k => $sql) { $counts[$k] = (int) db_val("SELECT COUNT(*) FROM hearings h WHERE $sql"); }
$total = $counts[$tab];
$rows = db_all("SELECT h.hearing_id, h.scheduled_at, h.venue, h.status, h.status_note, b.blotter_id, b.case_no,
    (SELECT GROUP_CONCAT(y.full_name ORDER BY y.party_id SEPARATOR ', ') FROM blotter_parties y WHERE y.blotter_id = b.blotter_id AND y.party_role = 'Respondent') AS respondents
    FROM hearings h JOIN blotters b ON b.blotter_id = h.blotter_id WHERE {$cond[$tab]} ORDER BY $order, h.hearing_id LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per));

render_header('Hearings', [['Hearings', null]], 'hearings');
page_head('Hearings', 'Schedule hearings from a case page. Record each outcome so the case can move on.');
echo '<ul class="nav nav-pills mb-3">';
foreach ($tabs as $k => $lab) { echo '<li class="nav-item"><a class="nav-link' . ($k === $tab ? ' active' : '') . '" href="' . e(base_url('blotters/hearings.php?tab=' . $k)) . '">' . e($lab) . ' <span class="badge text-bg-light">' . (int) $counts[$k] . '</span></a></li>'; }
echo '</ul>';
?>
<div class="card-b">
  <?php if (!$rows): ?><?= empty_state('bi-calendar-event', $tab === 'overdue' ? 'No hearing is waiting for an outcome.' : 'No hearings here.') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-b"><thead><tr><th>When</th><th>Case</th><th>Respondent</th><th>Venue</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td class="text-nowrap fw-semibold"><?= e(fmt_dt((string) $r['scheduled_at'])) ?></td>
    <td><a class="text-decoration-none" href="<?= e(base_url('blotters/view.php?id=' . (int) $r['blotter_id'])) ?>"><?= e((string) $r['case_no']) ?></a></td>
    <td><?= e((string) ($r['respondents'] ?? '—')) ?></td><td><?= e((string) ($r['venue'] ?? '—')) ?></td>
    <td><?= $tab === 'overdue' ? pill('Outcome needed', 'amber') : status_pill((string) $r['status']) ?><?= $r['status_note'] ? '<div class="small text-muted">' . e((string) $r['status_note']) . '</div>' : '' ?></td>
    <td class="text-end"><?php if ($r['status'] === 'Scheduled'): ?><a class="btn btn-sm btn-light border" href="<?= e(base_url('blotters/summons.php?id=' . (int) $r['hearing_id'])) ?>">Summons</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php render_pagination($total, $page, $per, 'blotters/hearings.php', array_filter(['tab' => $tab === 'upcoming' ? '' : $tab])); endif; ?>
</div>
<?php render_footer();
