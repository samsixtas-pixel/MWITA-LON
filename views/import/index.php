<?php
/** views/import/index.php — upload, review and commit a workbook. */

$sheetLabels = [
    'CUSTOMERS' => t('Customers'),
    'LOANS'     => t('Loans'),
    'PAYMENTS'  => t('Payments'),
];

$statusMeta = static function (string $status): array {
    return match ($status) {
        'ready'     => ['success', t('Ready')],
        'unclear'   => ['warning', t('Needs review')],
        'skipped'   => ['secondary', t('Skipped')],
        'duplicate' => ['info', t('Already in system')],
        'imported'  => ['success', t('Imported')],
        'error'     => ['danger', t('Error')],
        default     => ['secondary', t('Uploaded')],
    };
};

$batchMeta = static function (string $status): array {
    return match ($status) {
        'uploaded'   => ['secondary', t('Uploaded')],
        'analyzing'  => ['info', t('Analyzing')],
        'review'     => ['warning', t('Waiting for review')],
        'committing' => ['info', t('Importing')],
        'committed'  => ['success', t('Completed')],
        'failed'     => ['danger', t('Failed')],
        'cancelled'  => ['secondary', t('Cancelled')],
        default      => ['secondary', $status],
    };
};

$issueIcon = static function (string $level): string {
    return match ($level) {
        'error'   => 'bi-exclamation-octagon-fill text-danger',
        'warning' => 'bi-exclamation-triangle-fill text-warning',
        default   => 'bi-info-circle-fill text-primary',
    };
};

$field = static function (array $row, string $key, $default = '') {
    return $row['payload'][$key] ?? $default;
};
?>

<?php if (!$batch): ?>

  <?php if (isset($_GET['fresh'])): ?>
    <div class="alert alert-success d-flex align-items-center gap-2">
      <i class="bi bi-check2-circle"></i>
      <span><?= t('Workbook analyzed. Review anything the system marked clearly.') ?></span>
    </div>
  <?php endif; ?>

  <div class="page-head">
    <div>
      <h4><?= t('Import workbook') ?></h4>
      <p><?= t('Bring the company workbook into the system. Nothing is written to the loan book until you review it.') ?></p>
    </div>
  </div>

  <?php
    $importStrings = [
        'uploading'      => t('Uploading…'),
        'uploaded'       => t('Upload complete. Analyzing…'),
        'ready'          => t('Ready. Opening the review…'),
        'failed'         => t('Upload failed'),
        'choose'         => t('Choose an Excel workbook (.xlsx or .xls).'),
        'uploadFailed'   => t('The upload failed. Please try again.'),
        'connection'     => t('The connection dropped during upload. Check the network and try again.'),
        'analyzeFailed'  => t('The workbook could not be analyzed.'),
        'retryStored'    => t('The analysis request failed. The file is stored; open “Previous imports” to retry.'),
        'analysisDone'   => t('Analysis complete.'),
        'steps'          => [
            t('Upload complete. Reading customers, loans and payments…'),
            t('Matching customer names and merging repeat borrowers…'),
            t('Checking loan amounts and weekly schedules…'),
            t('Matching receipts to loans…'),
            t('Preparing the review list…'),
        ],
    ];
  ?>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-body">
          <div id="importDropZone" class="import-drop"
               data-endpoint="<?= e(BASE_URL . 'import.php') ?>"
               data-strings="<?= e(json_encode($importStrings, JSON_UNESCAPED_UNICODE)) ?>"
               tabindex="0" role="button"
               aria-label="<?= t('Drop the workbook here or choose a file') ?>">
            <input type="file" id="workbookFile" class="d-none"
                   accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel">

            <span class="import-drop-icon"><i class="bi bi-cloud-arrow-up-fill"></i></span>
            <h5 class="mb-1"><?= t('Drag and drop the workbook here') ?></h5>
            <p class="text-muted mb-3"><?= t('or choose the Excel file from this computer') ?></p>
            <button type="button" class="btn btn-primary" id="workbookBrowse">
              <i class="bi bi-folder2-open me-1"></i><?= t('Choose workbook') ?>
            </button>
            <div class="small text-muted mt-3">
              <?= t('Excel .xlsx or .xls · up to :mb MB', ['mb' => (int) (WorkbookImportService::MAX_BYTES / 1048576)]) ?>
            </div>
          </div>

          <div id="importProgressWrap" class="mt-4 d-none">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span class="small fw-semibold" id="importStatus"><?= t('Uploading…') ?></span>
              <span class="small text-muted" id="importPercent">1%</span>
            </div>
            <div class="progress" style="height:10px" role="progressbar" aria-label="<?= t('Upload progress') ?>"
                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
              <div class="progress-bar progress-bar-striped progress-bar-animated" id="importBar"
                   style="width:1%"></div>
            </div>

            <div id="analysisPhase" class="mt-3 d-none">
              <div class="progress" style="height:6px">
                <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" style="width:100%"></div>
              </div>
              <div class="small text-muted mt-2" id="analysisMessage">
                <?= t('Upload complete. Reading customers, loans and payments…') ?>
              </div>
            </div>

            <div id="importError" class="alert alert-danger mt-3 d-none"></div>
          </div>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header"><span class="card-title-sm"><i class="bi bi-info-circle me-1"></i><?= t('What happens next') ?></span></div>
        <div class="card-body">
          <ol class="import-steps mb-0">
            <li><strong><?= t('Upload') ?></strong> — <?= t('the workbook is stored privately on the server, so you can download it again as a backup.') ?></li>
            <li><strong><?= t('Analyze') ?></strong> — <?= t('every customer, loan and payment row is read and matched to this system.') ?></li>
            <li><strong><?= t('Review') ?></strong> — <?= t('rows that are unclear are listed with the exact fields to correct.') ?></li>
            <li><strong><?= t('Import') ?></strong> — <?= t('one transaction writes the clean data and fills the dashboard.') ?></li>
          </ol>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header"><span class="card-title-sm"><i class="bi bi-file-earmark-spreadsheet me-1"></i><?= t('Workbook sheets') ?></span></div>
        <div class="card-body">
          <p class="small text-muted"><?= t('The file must contain these sheets. Data starts on row 4.') ?></p>
          <ul class="list-unstyled small mb-0">
            <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i><strong>CUSTOMERS</strong> — <?= t('name, phone, emergency contact, registration date') ?></li>
            <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i><strong>LOANS</strong> — <?= t('customer, loan date, principal, weekly amount, weeks') ?></li>
            <li><i class="bi bi-check2 text-success me-2"></i><strong>PAYMENTS</strong> — <?= t('loan reference, payment date, amount') ?></li>
          </ul>
          <div class="alert alert-light border small mt-3 mb-0">
            <i class="bi bi-shield-lock me-1"></i>
            <?= t('Re-uploading the same workbook is safe: existing CUST-, LN- and PMT- references are reused, not duplicated.') ?>
          </div>
        </div>
      </div>
    </div>
  </div>

<?php else: ?>

  <?php
    [$batchClass, $batchLabel] = $batchMeta((string) $batch['status']);
    $canCommit = $counts && $counts['unclear'] === 0 && in_array($batch['status'], ['review'], true);
    $acceptAllable = max(0, count($unclear) - $blocked);
  ?>

  <div class="page-head">
    <div>
      <h4><?= t('Import review') ?></h4>
      <p><?= e($batch['original_filename'] ?: t('Workbook')) ?> · <?= e(formatDateTime($batch['created_at'])) ?></p>
    </div>
    <div class="page-actions">
      <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>import.php">
        <i class="bi bi-arrow-left me-1"></i><?= t('All imports') ?>
      </a>
      <?php if (!empty($batch['stored_name'])): ?>
        <a class="btn btn-outline-primary btn-sm" href="<?= BASE_URL ?>import.php?download=<?= (int) $batch['id'] ?>">
          <i class="bi bi-download me-1"></i><?= t('Download original') ?>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (isset($_GET['fresh'])): ?>
    <div class="alert alert-success d-flex align-items-center gap-2">
      <i class="bi bi-check2-circle"></i>
      <span><?= t('Workbook analyzed. Review anything the system marked clearly, then import.') ?></span>
    </div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <span class="badge bg-<?= e($batchClass) ?>"><?= e($batchLabel) ?></span>
      <div class="me-auto small text-muted">
        <div><strong class="text-body"><?= e($batch['original_filename'] ?: t('Workbook')) ?></strong></div>
        <div>
          <?= t('Uploaded by :user', ['user' => e($batch['user_name'] ?: '—')]) ?>
          · <?= e(formatDateTime($batch['created_at'])) ?>
          <?php if (!empty($batch['file_size_bytes'])): ?>
            · <?= e(number_format((int) $batch['file_size_bytes'] / 1024, 1)) ?> KB
          <?php endif; ?>
        </div>
      </div>
      <div class="import-steps-inline small">
        <span class="done"><i class="bi bi-check-circle-fill"></i> <?= t('Upload') ?></span>
        <span class="<?= in_array($batch['status'], ['analyzing'], true) ? 'current' : 'done' ?>"><i class="bi bi-<?= in_array($batch['status'], ['analyzing'], true) ? 'hourglass-split' : 'check-circle-fill' ?>"></i> <?= t('Analyze') ?></span>
        <span class="<?= $batch['status'] === 'review' ? 'current' : ($batch['status'] === 'committed' ? 'done' : '') ?>"><i class="bi bi-clipboard-check"></i> <?= t('Review') ?></span>
        <span class="<?= $batch['status'] === 'committed' ? 'done' : '' ?>"><i class="bi bi-database-check"></i> <?= t('Import') ?></span>
      </div>
    </div>
  </div>

  <?php if (!empty($batch['error_message'])): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-1"></i><?= e($batch['error_message']) ?></div>
  <?php endif; ?>

  <?php if ($batch['status'] === 'committed'): ?>
    <div class="card">
      <div class="card-body text-center py-5">
        <span class="import-done-icon"><i class="bi bi-check2-circle"></i></span>
        <h5 class="mt-2"><?= t('This workbook has been imported') ?></h5>
        <p class="text-muted mb-4">
          <?= t(':c customers, :l loans and :p payments were added. Existing rows were reused.', [
                'c' => (int) ($summary['customers'] ?? 0),
                'l' => (int) ($summary['loans'] ?? 0),
                'p' => (int) ($summary['payments'] ?? 0),
              ]) ?>
        </p>
        <a class="btn btn-primary" href="<?= BASE_URL ?>dashboard.php">
          <i class="bi bi-grid-1x2 me-1"></i><?= t('Open dashboard') ?>
        </a>
        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>import.php"><?= t('Import another workbook') ?></a>
      </div>
    </div>
  <?php elseif (in_array($batch['status'], ['uploaded', 'analyzing', 'failed'], true)): ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center gap-2">
      <i class="bi bi-hourglass-split"></i>
      <span class="me-auto"><?= t('This upload has not been analyzed yet. Upload it again to start a fresh review.') ?></span>
      <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>import.php"><?= t('Upload workbook') ?></a>
    </div>
  <?php elseif ($batch['status'] === 'cancelled'): ?>
    <div class="alert alert-secondary"><?= t('This import was cancelled.') ?></div>
  <?php else: ?>

    <div class="kpi-grid mb-3">
      <div class="kpi">
        <div class="k-icon"><i class="bi bi-people-fill"></i></div>
        <div class="k-body">
          <div class="k-label"><?= t('Customers') ?></div>
          <div class="k-value"><?= (int) ($summary['CUSTOMERS'] ?? 0) ?></div>
          <div class="k-hint"><?= t(':n people after merging', ['n' => (int) ($summary['groups'] ?? 0)]) ?></div>
        </div>
      </div>
      <div class="kpi gold">
        <div class="k-icon"><i class="bi bi-cash-coin"></i></div>
        <div class="k-body">
          <div class="k-label"><?= t('Loans') ?></div>
          <div class="k-value"><?= (int) ($summary['LOANS'] ?? 0) ?></div>
          <div class="k-hint"><?= t('with weekly schedules') ?></div>
        </div>
      </div>
      <div class="kpi ink">
        <div class="k-icon"><i class="bi bi-receipt"></i></div>
        <div class="k-body">
          <div class="k-label"><?= t('Payments') ?></div>
          <div class="k-value"><?= (int) ($summary['PAYMENTS'] ?? 0) ?></div>
          <div class="k-hint"><?= t('receipts to allocate') ?></div>
        </div>
      </div>
      <div class="kpi <?= ($counts['unclear'] ?? 0) > 0 ? 'red' : '' ?>">
        <div class="k-icon"><i class="bi bi-<?= ($counts['unclear'] ?? 0) > 0 ? 'exclamation-triangle-fill' : 'check2-circle' ?>"></i></div>
        <div class="k-body">
          <div class="k-label"><?= t('Needs review') ?></div>
          <div class="k-value"><?= (int) ($counts['unclear'] ?? 0) ?></div>
          <div class="k-hint"><?= t(':n already in the system', ['n' => (int) ($counts['duplicate'] ?? 0)]) ?></div>
        </div>
      </div>
    </div>

    <?php if (!empty($unclear)): ?>
      <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
          <span class="card-title-sm"><i class="bi bi-clipboard-check me-1"></i><?= t('Rows that need a decision') ?> (<?= count($unclear) ?>)</span>
          <?php if ($acceptAllable > 0): ?>
            <form method="POST" action="<?= BASE_URL ?>import.php" class="m-0">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="accept_all">
              <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
              <button class="btn btn-sm btn-outline-success" type="submit">
                <i class="bi bi-check2-all me-1"></i><?= t('Accept :n row(s) with no blocking errors', ['n' => $acceptAllable]) ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if ($blocked > 0): ?>
            <div class="alert alert-warning small">
              <i class="bi bi-exclamation-triangle me-1"></i>
              <?= t(':n row(s) have errors that must be corrected or skipped. Warnings can be accepted.', ['n' => $blocked]) ?>
            </div>
          <?php endif; ?>

          <div class="import-review-list">
            <?php foreach ($unclear as $row): ?>
              <form method="POST" action="<?= BASE_URL ?>import.php" class="import-review-row" id="row-<?= (int) $row['id'] ?>">
                <?= Security::csrfField() ?>
                <input type="hidden" name="action" value="decide">
                <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
                <input type="hidden" name="row_id" value="<?= (int) $row['id'] ?>">

                <div class="import-review-head">
                  <span class="badge bg-dark"><?= e($sheetLabels[$row['sheet']] ?? $row['sheet']) ?></span>
                  <span class="small text-muted"><?= t('row :n', ['n' => (int) $row['row_number']]) ?></span>
                  <code class="small"><?= e((string) $row['legacy']) ?></code>
                  <span class="badge bg-warning text-dark ms-auto"><?= t('Needs review') ?></span>
                </div>

                <ul class="import-issues">
                  <?php foreach ($row['issues'] as $issue): ?>
                    <li class="<?= ($issue['level'] ?? '') === 'error' ? 'text-danger' : (($issue['level'] ?? '') === 'warning' ? 'text-warning-emphasis' : 'text-muted') ?>">
                      <i class="bi <?= e($issueIcon((string) ($issue['level'] ?? 'info'))) ?> me-1"></i>
                      <?= e((string) ($issue['message'] ?? '')) ?>
                    </li>
                  <?php endforeach; ?>
                </ul>

                <div class="row g-2">
                  <?php if ($row['sheet'] === 'CUSTOMERS'): ?>
                    <div class="col-md-4">
                      <label class="form-label small"><?= t('Full name') ?></label>
                      <input class="form-control form-control-sm" name="fields[name]" value="<?= e((string) $field($row, 'name')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small"><?= t('Phone') ?></label>
                      <input class="form-control form-control-sm" name="fields[phone]" value="<?= e((string) $field($row, 'phone')) ?>" placeholder="0744 909 605">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small"><?= t('Registration date') ?></label>
                      <input type="date" class="form-control form-control-sm" name="fields[registered_at]" value="<?= e((string) $field($row, 'registered_at')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small"><?= t('Emergency contact') ?></label>
                      <input class="form-control form-control-sm" name="fields[emergency]" value="<?= e((string) $field($row, 'emergency')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small"><?= t('Emergency phone') ?></label>
                      <input class="form-control form-control-sm" name="fields[emergency_phone]" value="<?= e((string) $field($row, 'emergency_phone')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small"><?= t('Notes') ?></label>
                      <input class="form-control form-control-sm" name="fields[notes]" value="<?= e((string) $field($row, 'notes')) ?>">
                    </div>
                  <?php elseif ($row['sheet'] === 'LOANS'): ?>
                    <div class="col-md-4">
                      <label class="form-label small"><?= t('Customer') ?></label>
                      <input class="form-control form-control-sm" name="fields[name]" value="<?= e((string) $field($row, 'name')) ?>">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label small"><?= t('Loan date') ?></label>
                      <input type="date" class="form-control form-control-sm" name="fields[loan_date]" value="<?= e((string) $field($row, 'loan_date')) ?>">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label small"><?= t('Principal') ?></label>
                      <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="fields[principal]" value="<?= e((string) $field($row, 'principal')) ?>">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label small"><?= t('Weekly') ?></label>
                      <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="fields[weekly]" value="<?= e((string) $field($row, 'weekly')) ?>">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label small"><?= t('Weeks') ?></label>
                      <input type="number" step="1" min="1" class="form-control form-control-sm" name="fields[weeks]" value="<?= e((string) $field($row, 'weeks')) ?>">
                    </div>
                  <?php else: ?>
                    <div class="col-md-4">
                      <label class="form-label small"><?= t('Loan reference') ?></label>
                      <input class="form-control form-control-sm" name="fields[loan]" value="<?= e((string) $field($row, 'loan')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small"><?= t('Payment date') ?></label>
                      <input type="date" class="form-control form-control-sm" name="fields[paid_at]" value="<?= e((string) $field($row, 'paid_at')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small"><?= t('Amount') ?></label>
                      <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="fields[amount]" value="<?= e((string) $field($row, 'amount')) ?>">
                    </div>
                  <?php endif; ?>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-2">
                  <button class="btn btn-sm btn-primary" type="submit" name="decision" value="save">
                    <i class="bi bi-check2 me-1"></i><?= t('Save and re-check') ?>
                  </button>
                  <button class="btn btn-sm btn-outline-success" type="submit" name="decision" value="accept"
                          <?= $blocked > 0 && (static function (array $issues): bool {
                              foreach ($issues as $issue) {
                                  if (($issue['level'] ?? '') === 'error') {
                                      return true;
                                  }
                              }
                              return false;
                          })($row['issues']) ? 'disabled' : '' ?>>
                    <i class="bi bi-check2-all me-1"></i><?= t('Accept as is') ?>
                  </button>
                  <button class="btn btn-sm btn-outline-secondary" type="submit" name="decision" value="skip" formnovalidate>
                    <i class="bi bi-skip-forward me-1"></i><?= t('Skip this row') ?>
                  </button>
                </div>
              </form>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($canCommit): ?>
      <div class="card import-commit-card mb-3">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
          <span class="import-done-icon sm"><i class="bi bi-shield-check"></i></span>
          <div class="me-auto">
            <h6 class="mb-1"><?= t('Everything is clear') ?></h6>
            <div class="small text-muted">
              <?= t(':n rows are ready. Importing writes customers, loans, schedules and payments in one transaction.', ['n' => count($ready)]) ?>
            </div>
          </div>
          <form method="POST" action="<?= BASE_URL ?>import.php" class="m-0"
                data-confirm="<?= e(t('Import this workbook into the live loan book now?')) ?>"
                data-confirm-title="<?= e(t('Import workbook')) ?>"
                data-confirm-button="<?= e(t('Yes, import now')) ?>">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="commit">
            <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
            <button class="btn btn-primary" type="submit">
              <i class="bi bi-database-add me-1"></i><?= t('Import :n rows', ['n' => count($ready)]) ?>
            </button>
          </form>
        </div>
      </div>
    <?php elseif ($batch['status'] === 'review'): ?>
      <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-clipboard-check"></i>
        <span><?= t('Review or skip the :n unclear row(s) before importing.', ['n' => (int) ($counts['unclear'] ?? 0)]) ?></span>
      </div>
    <?php endif; ?>

    <?php if (!empty($ready)): ?>
      <div class="card mb-3">
        <div class="card-header">
          <button class="btn btn-link p-0 card-title-sm text-decoration-none" type="button"
                  data-bs-toggle="collapse" data-bs-target="#readyRows" aria-expanded="false">
            <i class="bi bi-check2-circle me-1"></i><?= t('Ready to import') ?> (<?= count($ready) ?>) <i class="bi bi-chevron-down ms-1"></i>
          </button>
        </div>
        <div class="collapse" id="readyRows">
          <div class="table-wrap">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th><?= t('Sheet') ?></th>
                  <th><?= t('Row') ?></th>
                  <th><?= t('Reference') ?></th>
                  <th><?= t('Detail') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ready as $row): ?>
                  <tr>
                    <td><span class="badge bg-light text-dark"><?= e($sheetLabels[$row['sheet']] ?? $row['sheet']) ?></span></td>
                    <td class="text-muted small"><?= (int) $row['row_number'] ?></td>
                    <td><code class="small"><?= e((string) $row['legacy']) ?></code></td>
                    <td class="small">
                      <?php if ($row['sheet'] === 'CUSTOMERS'): ?>
                        <?= e((string) $field($row, 'name')) ?>
                        <span class="text-muted">· <?= e((string) ($field($row, 'phone') ?: t('no phone'))) ?></span>
                      <?php elseif ($row['sheet'] === 'LOANS'): ?>
                        <?= e((string) $field($row, 'name')) ?>
                        <span class="text-muted">· <?= e(money((float) $field($row, 'principal'))) ?> · <?= (int) $field($row, 'weeks') ?> <?= t('weeks') ?></span>
                      <?php else: ?>
                        <?= e((string) $field($row, 'loan')) ?>
                        <span class="text-muted">· <?= e(money((float) $field($row, 'amount'))) ?> · <?= e(formatDate((string) $field($row, 'paid_at'), '—')) ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($duplicate) || !empty($skipped)): ?>
      <div class="card mb-3">
        <div class="card-header">
          <button class="btn btn-link p-0 card-title-sm text-decoration-none" type="button"
                  data-bs-toggle="collapse" data-bs-target="#otherRows" aria-expanded="false">
            <i class="bi bi-collection me-1"></i><?= t('Reused or skipped') ?> (<?= count($duplicate) + count($skipped) ?>) <i class="bi bi-chevron-down ms-1"></i>
          </button>
        </div>
        <div class="collapse" id="otherRows">
          <div class="table-wrap">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th><?= t('Sheet') ?></th>
                  <th><?= t('Reference') ?></th>
                  <th><?= t('Status') ?></th>
                  <th><?= t('Detail') ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_merge($duplicate, $skipped) as $row): ?>
                  <?php [$statusClass, $statusLabel] = $statusMeta((string) $row['status']); ?>
                  <tr>
                    <td><span class="badge bg-light text-dark"><?= e($sheetLabels[$row['sheet']] ?? $row['sheet']) ?></span></td>
                    <td><code class="small"><?= e((string) $row['legacy']) ?></code></td>
                    <td><span class="badge bg-<?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                    <td class="small text-muted">
                      <?php
                        $messages = array_map(static fn (array $issue): string => (string) ($issue['message'] ?? ''), $row['issues']);
                        echo e(implode(' ', array_filter($messages)));
                      ?>
                    </td>
                    <td class="text-end">
                      <?php if ($row['status'] === 'skipped'): ?>
                        <form method="POST" action="<?= BASE_URL ?>import.php" class="m-0">
                          <?= Security::csrfField() ?>
                          <input type="hidden" name="action" value="decide">
                          <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
                          <input type="hidden" name="row_id" value="<?= (int) $row['id'] ?>">
                          <input type="hidden" name="decision" value="restore">
                          <button class="btn btn-sm btn-outline-secondary" type="submit"><?= t('Restore') ?></button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_URL ?>import.php" class="mb-4"
          data-confirm="<?= e(t('Cancel this import and remove the uploaded workbook?')) ?>"
          data-confirm-title="<?= e(t('Cancel import')) ?>"
          data-confirm-button="<?= e(t('Yes, cancel import')) ?>">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
      <button class="btn btn-sm btn-outline-danger" type="submit">
        <i class="bi bi-x-circle me-1"></i><?= t('Cancel this import') ?>
      </button>
    </form>
  <?php endif; ?>

<?php endif; ?>

<div class="card">
  <div class="card-header"><span class="card-title-sm"><i class="bi bi-clock-history me-1"></i><?= t('Previous imports') ?></span></div>
  <div class="table-wrap">
    <table class="table table-sm align-middle mb-0">
      <thead>
        <tr>
          <th><?= t('When') ?></th>
          <th><?= t('Workbook') ?></th>
          <th><?= t('Status') ?></th>
          <th><?= t('By') ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$batches): ?>
          <tr><td colspan="5" class="text-center text-muted small py-3"><?= t('No imports yet.') ?></td></tr>
        <?php endif; ?>
        <?php foreach ($batches as $item): ?>
            <?php [$itemClass, $itemLabel] = $batchMeta((string) $item['status']); ?>
            <tr>
              <td class="small text-muted"><?= e(formatDateTime($item['created_at'])) ?></td>
              <td class="small"><?= e((string) ($item['original_filename'] ?: t('Workbook'))) ?></td>
              <td><span class="badge bg-<?= e($itemClass) ?>"><?= e($itemLabel) ?></span></td>
              <td class="small text-muted"><?= e((string) ($item['user_name'] ?: '—')) ?></td>
              <td class="text-end text-nowrap">
                <?php if (in_array($item['status'], ['review', 'committed', 'failed', 'analyzing'], true)): ?>
                  <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>import.php?batch=<?= (int) $item['id'] ?>">
                    <?= $item['status'] === 'review' ? t('Review') : t('Open') ?>
                  </a>
                <?php endif; ?>
                <?php if (!empty($item['stored_name'])): ?>
                  <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>import.php?download=<?= (int) $item['id'] ?>" title="<?= t('Download original') ?>">
                    <i class="bi bi-download"></i>
                  </a>
                <?php endif; ?>
              </td>
            </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
