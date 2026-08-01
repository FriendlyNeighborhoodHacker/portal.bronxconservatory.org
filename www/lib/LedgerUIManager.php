<?php
declare(strict_types=1);

require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/Billing.php';

/**
 * The Charges & Payments section of Edit Student: current balance, this
 * semester's line items (plus older history when it doesn't reconcile), and
 * the Apply Scholarship / Record Payment / Custom Ledger Entry modals.
 * Modals POST normal forms to their evals (PRG + flash).
 */
class LedgerUIManager {

    public static function renderSection(int $studentUserId, ?int $semesterId, string $returnTo): void {
        $balance = Billing::balanceForStudentCents($studentUserId);
        $semesterEntries = $semesterId !== null ? Billing::ledgerForStudent($studentUserId, $semesterId) : [];
        $allEntries = Billing::ledgerForStudent($studentUserId);

        // If this semester's lines don't explain the whole balance, show the
        // older history too.
        $semesterNet = 0;
        foreach ($semesterEntries as $entry) {
            $semesterNet += ($entry['accounting_type'] === 'debit' ? 1 : -1) * (int)$entry['amount_cents'];
        }
        $semesterIds = array_map(fn($e) => (int)$e['id'], $semesterEntries);
        $historicalEntries = array_values(array_filter(
            $allEntries,
            fn($e) => !in_array((int)$e['id'], $semesterIds, true)
        ));
        $showHistory = $semesterNet !== $balance && $historicalEntries;
        ?>
        <div class="card">
          <div class="page-head">
            <h3>Charges &amp; Payments</h3>
            <span class="actions">
              <button type="button" class="button" data-modal-open="ledgerPaymentModal">Record Payment</button>
              <button type="button" class="button" data-modal-open="ledgerScholarshipModal">Apply Scholarship</button>
              <button type="button" class="button" data-modal-open="ledgerCustomModal">Custom Ledger Entry</button>
            </span>
          </div>

          <p>
            <?php if ($balance > 0): ?>
              Current balance: <strong><?=h(Billing::formatCents($balance))?></strong> due.
            <?php elseif ($balance < 0): ?>
              Current balance: <strong><?=h(Billing::formatCents(-$balance))?></strong> credit.
            <?php else: ?>
              <strong>Paid in full.</strong>
            <?php endif; ?>
          </p>

          <?php self::entriesTable($semesterId !== null ? $semesterEntries : $allEntries,
              $semesterId !== null ? 'This semester' : 'All entries'); ?>

          <?php if ($showHistory): ?>
            <details>
              <summary>Earlier charges and payments (explains the rest of the balance)</summary>
              <?php self::entriesTable($historicalEntries, 'History'); ?>
            </details>
          <?php endif; ?>
        </div>
        <?php
        self::renderModals($studentUserId, $semesterId, $returnTo);
    }

    private static function entriesTable(array $entries, string $caption): void {
        if (!$entries) {
            echo '<p class="small">No charges or payments yet.</p>';
            return;
        }
        ?>
        <table class="list">
          <thead><tr><th>Date</th><th>Type</th><th>Description</th>
            <th style="text-align:right;">Charge</th><th style="text-align:right;">Credit</th></tr></thead>
          <tbody>
            <?php foreach ($entries as $entry): ?>
            <tr>
              <td class="small"><?=h($entry['entry_date'])?></td>
              <td class="small"><?=h(str_replace('_', ' ', (string)$entry['entry_type']))?>
                <?php if (!empty($entry['season'])): ?>
                  <div class="small"><?=h(ucfirst((string)$entry['season']) . ' ' . $entry['year'])?></div>
                <?php endif; ?>
              </td>
              <td><?=h((string)($entry['description'] ?? ''))?></td>
              <td style="text-align:right;"><?=$entry['accounting_type'] === 'debit' ? h(Billing::formatCents((int)$entry['amount_cents'])) : ''?></td>
              <td style="text-align:right;"><?=$entry['accounting_type'] === 'credit' ? h(Billing::formatCents((int)$entry['amount_cents'])) : ''?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php
    }

    private static function renderModals(int $studentUserId, ?int $semesterId, string $returnTo): void {
        $common = function (string $action) use ($studentUserId, $semesterId, $returnTo) {
            return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
                . '<input type="hidden" name="student_user_id" value="' . $studentUserId . '">'
                . '<input type="hidden" name="semester_id" value="' . (int)$semesterId . '">'
                . '<input type="hidden" name="return_to" value="' . h($returnTo) . '">'
                . '<input type="hidden" name="action" value="' . h($action) . '">';
        };
        ?>
        <div id="ledgerPaymentModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
          <div class="modal-content">
            <button class="close" type="button" aria-label="Close">&times;</button>
            <h3>Record Payment</h3>
            <p class="small">For payments taken outside the portal (check, cash, Zelle...).</p>
            <form method="post" action="/admin/ledger_entry_eval.php" class="stack">
              <?=$common('payment')?>
              <div class="grid-2">
                <label>Amount ($) <input type="number" name="amount" step="0.01" min="0.01" required></label>
                <label>Date <input type="date" name="entry_date" value="<?=h(date('Y-m-d'))?>" required></label>
              </div>
              <label>Description <input type="text" name="description" placeholder="Check #123" required></label>
              <div class="actions">
                <button type="submit" class="button primary">Record Payment</button>
                <button type="button" class="button" data-modal-close>Cancel</button>
              </div>
            </form>
          </div>
        </div>

        <div id="ledgerScholarshipModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
          <div class="modal-content">
            <button class="close" type="button" aria-label="Close">&times;</button>
            <h3>Apply Scholarship</h3>
            <form method="post" action="/admin/ledger_entry_eval.php" class="stack">
              <?=$common('scholarship')?>
              <label>Amount ($) <input type="number" name="amount" step="0.01" min="0.01" required></label>
              <label>Description <input type="text" name="description" placeholder="Sliding scale" required></label>
              <div class="actions">
                <button type="submit" class="button primary">Apply Scholarship</button>
                <button type="button" class="button" data-modal-close>Cancel</button>
              </div>
            </form>
          </div>
        </div>

        <div id="ledgerCustomModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
          <div class="modal-content">
            <button class="close" type="button" aria-label="Close">&times;</button>
            <h3>Custom Ledger Entry</h3>
            <p class="small">An adjustment with an explanation — e.g. a recital opt-out credit
            or a make-up charge.</p>
            <form method="post" action="/admin/ledger_entry_eval.php" class="stack">
              <?=$common('custom')?>
              <div class="grid-2">
                <label>Direction
                  <select name="accounting_type">
                    <option value="credit">Credit (reduces balance)</option>
                    <option value="debit">Charge (increases balance)</option>
                  </select>
                </label>
                <label>Amount ($) <input type="number" name="amount" step="0.01" min="0.01" required></label>
              </div>
              <label>Description <input type="text" name="description" required></label>
              <div class="actions">
                <button type="submit" class="button primary">Add Entry</button>
                <button type="button" class="button" data-modal-close>Cancel</button>
              </div>
            </form>
          </div>
        </div>
        <?php
    }
}
