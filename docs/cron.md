# Scheduled tasks (cron)

The portal has one scheduled task. CLI entry points live in `www/bin/` and
contain no business logic — everything they do goes through the lib classes,
so the same run can be triggered (and previewed) from Admin > Maintenance.

## Daily installment-fee sweep

`www/bin/apply_installment_fees.php` posts the semester's installment plan fee
to every confirmed student who, from the second day of the semester on, still
owes part of that semester's balance and has not already been charged the fee
(whether at confirmation time, through registration, or by an earlier run).
It is idempotent: the live-debit check makes a second run post nothing new.

```
# BCM portal: daily installment-fee sweep (2:15 AM server time)
15 2 * * * php /path/to/portal.bronxconservatory.org/www/bin/apply_installment_fees.php >> /var/log/bcm_installment_fees.log 2>&1
```

Flags:

- `--dry-run` — print who would be charged without writing anything.
- `--today=YYYY-MM-DD` — pretend today is another date (testing).

Ledger entries written by the sweep carry a NULL `created_by_user_id` and an
activity-log entry (`billing.installment_fee_auto_applied` per charge, plus a
`billing.installment_fee_run` summary), the same way Stripe webhook writes do.

Admins can preview today's sweep (a dry run) and run it by hand from
Admin > Maintenance > Installment Fee Sweep (`/admin/installment_fees.php`).
