# Stripe setup: making payments work

How to connect the portal to the conservatory's Stripe account, test it without
spending money, and switch it on for real. Work through it top to bottom; it
takes about an hour, most of it the test pass.

You need: admin access to the Stripe Dashboard, and SSH access to the server
(to edit `config.local.php`).

## What the portal needs from Stripe

Three values, all in `config.local.php`:

| Setting | What it is | Looks like |
| --- | --- | --- |
| `STRIPE_PUBLISHABLE_KEY` | Identifies the account to the browser | `pk_test_…` / `pk_live_…` |
| `STRIPE_SECRET_KEY` | Lets the server create and look up payments | `sk_test_…` / `sk_live_…` |
| `STRIPE_WEBHOOK_SECRET` | Proves a webhook call really came from Stripe | `whsec_…` |

With the first two set, card forms appear and payments work. Leave them empty
and the portal hides online payment entirely (families are told BCM will call).

Stripe has two separate worlds, **test mode** and **live mode**. Each has its
own keys, its own webhook endpoints and its own list of payments. A site is in
whichever mode its keys belong to. All three values must come from the same
mode.

## Where money is taken

| Page | Who | What happens to the payment |
| --- | --- | --- |
| `/register/checkout.php` | A family registering | Held on the lead; moved to a student's ledger when staff convert the lead |
| `/parent/pay.php` | A parent paying a balance | One ledger credit per child |
| `/student/pay.php` | An adult student paying a balance | A ledger credit for that student |

In all three the card fields are Stripe's own, embedded in the page. Card
numbers go from the browser to Stripe and never touch the portal's server.

## Why the webhook matters

A payment is recorded in the portal by two routes, and either is enough:

1. **The browser's return trip.** After paying, the browser comes back to the
   portal, which asks Stripe whether the payment succeeded and records it.
2. **The webhook.** Stripe calls `/stripe_webhook.php` on the portal directly.

Without the webhook, a payment is missed whenever the browser never comes back:
the tab is closed, the phone loses signal, or the payment method takes days to
clear (bank debits). The family is charged and the portal shows them as unpaid.
So the webhook is required for launch, not optional.

Recording is safe to repeat: a payment is keyed by its Stripe id, so the two
routes can both fire without counting it twice.

---

## Part 1 — Stripe account settings (once)

Do these in the Dashboard in **live mode**; repeat step 2 and 3 in test mode if
you want the test pass to look the same.

1. **Business details.** Settings > Business > Public details: check the
   business name, support email and phone, and the **statement descriptor**
   (what families see on their card statement — something they will recognise,
   such as `BRONX CONSERVATORY`).
2. **Payment methods.** Settings > Payment methods. The portal offers whatever
   is switched on here, so choose deliberately:
   - **On:** Cards, Apple Pay, Google Pay. Link is optional (Stripe's saved-card
     autofill).
   - **Off:** buy-now-pay-later methods (Affirm, Klarna, Afterpay), Cash App
     Pay, Amazon Pay — unless the conservatory wants them.
   - **Bank debit (ACH):** lower fees, but the money takes several days and can
     fail afterwards. The portal handles it (the payment is recorded when it
     clears, by the webhook). Leave it off unless you want it.
3. **Apple Pay / Google Pay domains.** Settings > Payment method domains > Add
   a domain, for each address families use: `portal.bronxconservatory.org`
   (and the dev site, in test mode). Follow whatever the Dashboard asks for.
   Wallets do not appear on a domain that is not registered.
4. **Receipts.** Settings > Business > Customer emails. The portal passes the
   payer's email to Stripe, so Stripe emails a receipt for each successful
   live payment. Check the receipt branding (logo, colour) under Settings >
   Business > Branding. These receipts are sent by Stripe, not the portal, so
   the portal's email redirect (`EMAIL_REDIRECT_TO`) does **not** hold them
   back. Test mode does not email receipts.
5. **Payouts.** Settings > Business > Bank accounts and scheduling: confirm the
   bank account the money is paid out to.

## Part 2 — Test pass on the dev site (no real money)

The dev site is `dev-bronxconservatory.lillyrosenthal.org`. Never put live keys
on it.

### 2a. Keys

1. In the Dashboard, switch to **Test mode** (newer accounts: a Sandbox).
2. Developers > API keys. Copy the **Publishable key** (`pk_test_…`) and reveal
   and copy the **Secret key** (`sk_test_…`).

### 2b. Webhook endpoint

1. Still in test mode: Developers > Webhooks > Add endpoint (newer Dashboards:
   Developers > Event destinations > Add destination > Webhook endpoint).
2. **Endpoint URL:** `https://dev-bronxconservatory.lillyrosenthal.org/stripe_webhook.php`
3. **Events to send** — select exactly these two:
   - `payment_intent.succeeded`
   - `checkout.session.completed`
4. Save, open the new endpoint, and reveal the **Signing secret** (`whsec_…`).

Other events are ignored by the portal, so selecting more does no harm; it only
adds noise.

### 2c. Put them on the server

    ssh bronxconservatory@vps47444.dreamhostps.com
    vim ~/deploy/dev-bronxconservatory.lillyrosenthal.org.secrets/config.local.php

Set the three lines:

    define('STRIPE_SECRET_KEY', 'sk_test_…');
    define('STRIPE_PUBLISHABLE_KEY', 'pk_test_…');
    define('STRIPE_WEBHOOK_SECRET', 'whsec_…');

Then run the deploy script so the file is copied into the live web root:

    ~/deploy/dev-bronxconservatory.lillyrosenthal.org.sh

Editing the copy in the secrets directory is not enough on its own — the site
reads the copy the deploy script puts in the web root.

### 2d. Test cards

Any future expiry date, any three-digit CVC, any ZIP.

| Card number | Result |
| --- | --- |
| 4242 4242 4242 4242 | Succeeds |
| 4000 0000 0000 0002 | Declined |
| 4000 0025 0000 3155 | Asks for the bank's extra verification, then succeeds |

### 2e. What to test

Registration must be open on the dev site (Admin > Settings > registration
semester).

1. **Registration, pay in full.** Go through `/register/` and pay with
   4242…. Expect: the thank-you page says "payment received"; in the portal,
   Admin > Leads shows the lead as paid with the right amount; in Stripe
   (test mode) Payments shows it as Succeeded.
2. **Registration, installment plan.** Choose the installment plan. Expect the
   amount charged to be the fees plus half the tuition.
3. **Declined card.** Use 4000 0000 0000 0002. Expect an error on the checkout
   page, nothing recorded, and a second attempt with 4242… to work.
4. **Back from checkout.** On the checkout page press Back, change the lesson
   length, continue. Expect the new total on checkout, and one lead (not two)
   in Admin > Leads.
5. **Convert the paid lead.** Admin > Leads > the lead > Convert. Expect the
   payment to appear on the chosen student's ledger as a credit, once.
6. **Parent balance.** Confirm a reservation for a student with a parent
   account so there is a balance, sign in as the parent, and pay part of it at
   Balance & Payments. Expect a credit on the child's ledger for that amount.
7. **The webhook on its own.** This is the one that proves the webhook works.
   Start a registration payment with 4000 0025 0000 3155, complete the bank
   verification step, and **close the tab as soon as it is approved**, before
   the thank-you page loads. Wait a minute, then look at the lead in Admin >
   Leads: it should be paid. If it is not, the webhook is not working (see
   Troubleshooting).
8. **Stripe's view of the webhook.** Developers > Webhooks > the endpoint.
   Recent deliveries should all show **200**.

## Part 3 — Going live on production

Do this when the test pass is clean.

1. Switch the Dashboard to **Live mode**.
2. Developers > API keys: copy the live publishable key (`pk_live_…`) and
   create or reveal the live secret key (`sk_live_…`). A live secret key is
   shown once; if nobody has it saved, roll it to get a new one.
3. Add a **live** webhook endpoint, exactly as in 2b, with the URL
   `https://portal.bronxconservatory.org/stripe_webhook.php` and the same two
   events. Copy its signing secret — it is different from the test one.
4. Put the three live values in
   `~/deploy/portal.bronxconservatory.org.secrets/config.local.php` and run
   `~/deploy/portal.bronxconservatory.org.sh`.
5. Check Part 1 was done in live mode (payment methods, domains, receipts).
6. **One real payment.** There is no test card in live mode. Make one small
   real payment with your own card — the simplest is to give a test student a
   small charge (Admin > student > Charges and payments > add a $1 custom
   charge), sign in as their parent and pay it. Confirm it shows in the portal
   and in Stripe, and that the webhook delivery shows 200. Then refund it in
   Stripe (Payments > the payment > Refund) and remove the test charge and
   credit from the ledger.

Keep the live secret key and signing secret out of email, chat and git. They
belong only in `config.local.php` on the server.

## Day to day

- **Refunds** are made in the Stripe Dashboard. The portal is not told about
  them: after refunding, add a matching charge on the student's ledger by hand
  (Charges and payments > custom entry) so the balance is right.
- **Disputes / chargebacks** arrive by email from Stripe and are answered in
  the Dashboard. The same manual ledger adjustment applies if one is lost.
- **Reconciling.** Every online payment on a ledger says "Online payment
  (Stripe)" and is listed as "Online card payment" in the Entered by column.
  The Stripe Dashboard's Payments list is the other side.
- **Fees.** Stripe deducts its fee from the payout, not from what the family
  pays. The ledger shows the full amount the family paid.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| No card form; the page says BCM will call about payment | One of the two keys is empty, or the edited config was not deployed (run the deploy script) |
| Card form appears, payment fails immediately with an authentication error | Publishable and secret keys are from different modes or different accounts |
| Paid in Stripe, unpaid in the portal | Webhook not delivering, and the browser did not come back. Check the endpoint's deliveries in Stripe |
| Webhook deliveries show **400 Bad signature** | `STRIPE_WEBHOOK_SECRET` is from a different endpoint or mode. Each endpoint has its own secret |
| Webhook deliveries show **500 Webhook secret not configured** | `STRIPE_WEBHOOK_SECRET` is empty on that site |
| Webhook deliveries show 404 or time out | Wrong URL (it must end `/stripe_webhook.php`), or the site is down |
| Apple Pay does not appear | Domain not registered in that mode, Apple Pay switched off, or the customer is not in Safari with a card in Wallet |
| Real cards rejected | The site has test keys |
| Test cards rejected | The site has live keys |

A payment that reached Stripe but not the portal can be resent: in the
endpoint's delivery list, open the event and press **Resend**. Server-side
errors are in Admin > Maintenance > Server Logs.
