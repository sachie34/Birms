<?php
/** Public queue display. It exposes only current queue numbers and waiting counts, never resident details. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

$snapshots = queue_all_snapshots();
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="8">
  <title>BIRMS Queue Display</title>
  <link rel="stylesheet" href="<?= e(base_url('assets/vendor/bootstrap/css/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/birms.css')) ?>">
</head>
<body class="bg-light">
<main class="container-fluid py-4">
  <header class="text-center mb-4">
    <h1 class="display-5 fw-bold">Barangay Service Queue</h1>
    <p class="lead mb-0"><?= e(date('l, F j, Y')) ?> · This screen refreshes automatically</p>
  </header>
  <div class="row g-4">
    <?php foreach ($snapshots as $service => $snapshot): ?>
      <section class="col-12 col-md-4" aria-labelledby="queue-<?= e(strtolower($service)) ?>">
        <div class="card shadow-sm h-100">
          <div class="card-header text-center py-3"><h2 id="queue-<?= e(strtolower($service)) ?>" class="h3 mb-0"><?= e($service) ?></h2></div>
          <div class="card-body text-center">
            <div class="text-muted fw-semibold">NOW SERVING</div>
            <div class="display-1 fw-bold my-3" aria-live="polite"><?= $snapshot['current_number'] === null ? '—' : (int) $snapshot['current_number'] ?></div>
            <div class="border-top pt-3"><span class="text-muted">Waiting:</span> <strong class="fs-3"><?= (int) $snapshot['waiting_count'] ?></strong></div>
            <div class="small text-muted mt-2">Please watch for your number</div>
          </div>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
  <footer class="text-center text-muted mt-4"><small>BIRMS · Resident queue display</small></footer>
</main>
</body>
</html>
