# BCM Portal — Application Specification

The operations portal for the **Bronx Conservatory of Music**, a non-profit that
provides private music instruction of the highest quality to Bronx children and
adults, in their own neighborhoods, at the lowest possible tuition — making
conservatory training accessible to all.

This document describes **what the application does**: its concepts, its rules,
and what each kind of user can do. It is written to be read start-to-finish or
grepped for a specific answer.

## Where the truth lives

| Question | Look here |
| --- | --- |
| Tables, columns, keys, indexes | `schema.sql` (complete current schema, heavily commented) |
| How a rule actually behaves | the manager class in `www/lib/` (all SQL lives there) |
| What the app does and why | **this file** |
| PHP style, page/eval conventions | `docs/php-guidelines.md` |
| Human-facing documentation site | `portaldocs.bronxconservatory.org/` (Astro/Deno; published at bcmdocs.lillyrosenthal.org) |

This file deliberately contains **no schema detail** — no column lists, no
types. When this document and `schema.sql` disagree about structure, the schema
wins; when the code and this document disagree about behavior, the code wins and
this file should be corrected.

---

# 1. Core concepts

Five ideas explain most of the system. Everything else is detail.

### The semester is the organizing unit

A semester is a season (`fall`/`spring`/`summer`/`test`) plus a year. It owns its
own start/end dates, its own **fees**, its active **locations** — each with a
**declaration of which weekdays it holds classes and that day's standard
hours** — and — per location — a **calendar of class dates** (each active, or
inactive for a break, titled e.g. "Holiday Week"). A location may meet on more
than one weekday — Saturdays at both sites and Tuesday evenings at one, say —
and each date carries its own hours, defaulting to the declared ones. Imported
class dates must fall on the declared weekdays. Teachers are assigned per
(location, **day**), so a Saturday-only teacher never shows up on a Tuesday
grid. Schedules, lessons, and money all hang off a semester.

*Current semester resolution* (`SemesterManagement::resolveDefaultSemester`):
the semester containing today → else the next future one → else the most recent
past one. `test` semesters are skipped unless nothing else exists. Admins can
override the choice with the semester selector, which is session-only.

### Reservations generate lessons

A **reservation** is a standing weekly slot — teacher + location + day-of-week +
time + duration — held for one student for a whole semester. It is a *plan*, not
an event.

Confirming a reservation does two things: it **generates one lesson row per
active class date** at that location on that weekday, and it **posts the
semester's charges** to the student's ledger. Lessons are the *events*: they
carry attendance, notes, materials, substitutes, cancellations, and per-week
overrides.

The same two-level pattern covers teachers' non-teaching time: a **hold block
reservation** (lunch, a standing errand) generates dated **hold blocks**. Hold
blocks have no confirmation state and no billing — they materialize immediately
— and they occupy the slot in every conflict check.

Every hold block carries a **block type**. A plain `hold` is the teacher's own
unpaid time (lunch, an errand). The other types — `guitar_ensemble` and
`musicianship` — mark **paid group classes** taught in the slot: the teacher is
working, families pay a class fee at registration, and (unlike a lunch break)
the distinction matters to accounting. Structurally all types behave the same
today; the type drives the grid color and is the hook for future class
attendance and accounting features.

Consequences that matter constantly:

- Editing the **plan** reshapes only the **future**. Past lessons are never
  touched — they record what actually happened.
- A lesson can differ from its reservation (moved time, different room, a
  substitute) without disturbing the standing booking. The UI flags these as
  "Time moved".
- **One-off lessons** can be booked straight onto a calendar day with no
  reservation behind them. They charge nothing and aren't bound to the class
  calendar — used for make-ups, trials, and one-time extras.

### Money is a ledger

There is no balance column anywhere. A student's balance is
`SUM(debits) − SUM(credits)` over their ledger entries, so every figure is
explainable line by line. Amounts are integer cents. Almost every entry is
tagged with the semester it belongs to.

### Prospects are staged as leads

The two public forms never create live accounts. They create **leads**, which an
admin reviews and explicitly **converts** into real users, profiles, and
reservations. Nothing on the roster, the schedule, or the ledger moves until
that conversion.

### Roles are derived, not stored

There is no role column. A person is a teacher because a teacher profile row
exists, a parent because a parenthood link exists. One person can be several
things at once.

---

# 2. Code map

Pages follow a `foo.php` (render) / `foo_eval.php` (POST handler, redirects
back) convention. All SQL lives in manager classes under `www/lib/`. Every write
action is recorded in the activity log.

| Area | Pages | Main classes |
| --- | --- | --- |
| Sign-in, profile | `login.php`, `forgot_password.php`, `profile/` | `UserManagement`, `UserContext`, `Application` |
| Parent | `parent/` | `Billing`, `StudentTeacherManagement`, `ScheduleTimeline` |
| Teacher | `teacher/` | `LessonManagement`, `NotesManagement`, `ResourceManagement` |
| Student | `student/` | `LessonManagement`, `ResourceManagement` |
| Admin — schedule & calendar | `admin/schedule.php`, `admin/calendar*.php` | `ScheduleGridData`, `ReservationManagement`, `HoldBlockManagement`, `ScheduleConflicts` |
| Admin — people | `admin/students.php`, `admin/teachers.php`, `admin/*_edit.php` | `StudentTeacherManagement`, `UserManagement`, `KeywordSearch` |
| Admin — money | ledger section on the student page | `Billing`, `LedgerUIManager` |
| Admin — intake | `admin/leads.php`, `admin/lead*.php`, `admin/incomplete_inquir*.php` | `LeadManagement`, `InquiryManagement` |
| Admin — semester setup | `admin/semester/`, `admin/import/`, `admin/setup/` | `SemesterManagement`, `CsvImport` + one `*CsvImport` per flow |
| Admin — maintenance (developer) | `admin/migrations.php`, `admin/activity_log.php`, `admin/email_log.php`, `admin/l/` | `MigrationRunner`, `ActivityLog`, `EmailLog`, `LogViewer` |
| Public intake | `inquiry/`, `register/` | `InquiryManagement`, `LeadManagement`, `StripeCheckout` |
| Shared UI | — | `ApplicationUI` (nav/header), `LessonDetailUIManager` (notes+materials modal), `*UIManager` |

Shared rendering worth knowing about: `schedule_grid.php` draws **both** the
abstract semester grid and the dated weekly calendar; `LessonDetailUIManager`
renders the notes-and-materials modal for **every** role, which is why the
permission rules live in one place.

---

# 3. User & role model

Five kinds of user, one `users` table, no role column.

| Role | Derived from | Gets |
| --- | --- | --- |
| **admin** | the `is_admin` flag | everything under `admin/` |
| **developer** | the `is_developer` flag **plus** `is_admin` | Admin > Maintenance (migrations, activity log, email log, server logs) |
| **teacher** | a teacher profile row | `teacher/` — teaching days, attendance, notes, materials |
| **parent** | a parenthood row as the parent | `parent/` — children's schedules, notes, family billing |
| **student** | a student profile row | `student/` — own schedule, materials, own notes |

`Application::rolesForUser()` is the single implementation.

**Roles are additive.** A teacher who is also a parent holds both. An adult who
takes lessons gets a student profile on their own user row — there is no
separate "adult student" concept. The site root routes to the first role in
priority order **admin → teacher → parent → student**, falling back to
`profile/` for someone with no role yet; the nav shows one link group per role,
with duplicate labels resolved in favor of the higher-priority role.

**Not everyone can sign in.** Email is the login identifier and is optional:
child students typically have none and simply cannot sign in — their parents see
everything on their behalf. An empty password hash means "cannot sign in yet";
a password arrives via an admin invite or the forgot-password flow. Accounts
created by lead conversion, CSV import, or the admin "Add" pages start with no
login.

**Deleting is soft.** Deleting a person flags them deleted: they cannot sign in,
hold no roles, and vanish from lists — but every row and all history remain.
Reservations and hold blocks delete the same way, keeping past lessons.

**There is no families table.** A family is the set of parenthood edges. Charges
attach to the *student*; a parent's balance is the sum over their children.

---

# 4. Money

### What gets charged, and when

Confirming a reservation posts the semester's fees as debits: **registration**,
**lessons** (the 30- or 60-minute semester fee; other durations are prorated off
the 30-minute rate), and **recital fee** — each only if the fee is non-zero and
no live debit of that type already exists for that student and semester. So a
second instrument in the same term does not re-charge registration. The
**installment plan fee** is posted only when the admin checks "include
installment fee" in the confirmation dialog. Every admin action that posts
charges (or reversal credits) first shows a line-item review dialog, and the
endpoints refuse to post without its acknowledgement.

The one other automatic charge is the **daily installment-fee sweep**
(`www/bin/apply_installment_fees.php`, see docs/cron.md): from the second day
of a semester, confirmed students who still owe part of that semester's balance
and don't already carry the fee are charged it. Nothing else charges
automatically. Not lead conversion, not the schedule CSV import (opening
balances are loaded separately so nobody is billed twice), not one-off lessons,
not cancellations, not moving a lesson's time.

### What credits a balance

Stripe payments (parent portal, student portal, and payments taken during public
registration once the lead is converted), payments recorded by hand for
checks/cash/Zelle, scholarships, and custom adjustments.

### Reversal

Un-confirming or deleting a reservation posts **offsetting credits**, never
deletes debits — but only when the student has had no lesson occur this semester
and holds no other confirmed reservation in it. Otherwise nothing is posted and
an admin adjusts by hand.

### Balances and the payment schedule

Balances are computed **per term, oldest first**, and **surplus credit rolls
forward**: overpaying spring reduces the fall balance rather than showing as a
spring credit. What families are asked to pay:

- at least **half** the term's charges by **two weeks before** the term starts,
- the **rest** by the lesson before its **half-way point** (of 14 lessons, by the 6th).

A balance that has missed either deadline is **past due** and shows in red. A
balance that simply isn't due yet is never styled as an alarm — a deliberate
choice for families who are cautious about money. Cancelled lessons count as
neither held nor elapsed for this test.

### Paying

Parents may pay **any amount** from $1 up to what they owe (never more —
prepaying an unbilled term is a phone call, not a form). A family payment is
split across children **oldest debt first**, producing one credit per child.
Card details go from the browser directly to Stripe and never touch the server.
Both the Stripe webhook and the browser's return trip try to record the payment;
schema-level idempotency makes the race harmless.

---

# 5. Public intake

Two public, no-login forms. Both end in a lead, and both feed **Admin > Leads**.

### The inquiry form (`inquiry/`) — "Request Information"

Always open. Never quotes a price, never takes payment. Four pages: contact →
address → student → details.

Its defining behavior is that it **saves early**: page 1 writes an
*uncompleted form* row immediately, so staff can call back a family who drops
off. Page 3 promotes that row into a real lead and deletes it — so an
uncompleted-form row always means "this family never told us about a student."
Any staff notes on the form are carried across as a single note on the new lead.
Finishing sends the family a confirmation and staff a notification, both from
admin-editable templates.

### The registration form (`register/`) — the enrollment wizard

Open only while the registration-semester setting points at a semester;
otherwise every page shows "Registration is currently closed." Six steps:
family → students → policies → payment plan → review → checkout.

All prices come from that semester's fees, including the per-lesson figure shown
in the marketing copy, so quoted numbers can never drift from the math. The
quote is **frozen onto the lead at submit**, so a later price change never
rewrites what a family was told. Payment (full, or fees + half tuition on the
installment plan) is taken with an embedded Stripe form and **held on the lead**
until conversion moves it onto a student's ledger. The remaining installment is
due by the semester's **second installment due date** (a semester property,
defaulted to the term's midpoint and shown wherever fees are presented); the
daily installment-fee sweep charges the plan fee to anyone still unpaid after
the semester's first day.

Abandoning checkout still leaves a lead, so the family can be called.

---

# 6. Parent experience

Nav: **Calendar**, **Billing**, and the avatar menu.

**Home (`parent/index.php`)** — announcements; one **card per child** (photo,
instruments, teacher, a "Balance due" link when they owe); the family's **next
four lessons**; the family balance; and a profile card. The child card's loudest
element is deliberately **the next lesson** — it's what a parent opens the
portal to check.

**Child page (`parent/child.php`)** — next lesson, upcoming lessons, the last few
past lessons, recent notes, and every material shared this semester.

**Calendar (`parent/calendar.php`)** — one chronological list, not a month grid,
covering every current and future semester (so next term appears as soon as it
is planned). Lessons interleave with **holiday rows** for the breaks that fall on
the child's lesson weekday. Cancelled lessons stay on the list, badged — families
should be told, not left to notice something quietly gone.

**Billing (`parent/billing.php`)** — the family total, a per-term table per child
with "Paid / Past due / Not due yet", and a full line-by-line history. Payment
offers full, half, or a custom amount, and shows how it will be split across
children before the card form.

**Notes and materials** — every lesson shown to a parent opens a modal with the
lesson's note thread and its materials. Parents **can add notes** (a question for
the teacher, how practice went); materials are read-only for them.

Parents can edit their own profile and each child's.

---

# 7. Teacher experience

Nav: **Teaching Days**, **Calendar**, and the avatar menu.

**Home (`teacher/index.php`)** — one card per lesson for a **single day**. If
today has no lessons it jumps to the next day that does and retitles itself
"Upcoming Lessons". There is deliberately **no date picker**: lessons are sparse,
so navigation is by teaching day (prev/next arrows and the Teaching Days list).

Each card marks **attendance** (Present / Absent, tri-state with an "Unmark" that
confirms — a mis-tap between two lessons should not be permanent) and carries the
lesson's notes and materials inline, so a teacher never leaves the day to write
one.

**Teaching Days (`teacher/days.php`)** — the dates this teacher works, with
locations, lesson counts, and the day's time span. The answer to "am I at Bronx
CC on Saturday?"

**Calendar** — a whole-semester chronological list (with holiday rows), and a
week view that merges lessons and hold blocks.

**Materials** — teachers (and admins) add files or links to a lesson, and may
remove what they added.

**Substitutes** — the *effective teacher* of a lesson is the substitute if one is
set, otherwise the reservation's teacher, and every teacher-facing query filters
on that. So a covered lesson appears on the **substitute's** day, Teaching Days,
and calendars — and leaves the regular teacher's. The substitute may mark
attendance, write notes, and edit materials on it.

---

# 8. Student experience

Nav: **Calendar**, **Materials**, and the avatar menu. Most child students never
see this — they have no login, and their parents use the parent portal. Adult
students, and children who have been given an email, sign in normally.

**Home (`student/index.php`)** — "My Schedule": the site announcement if set,
then the **next lesson** as the loudest card, then the next few, each opening its
notes and materials.

**Calendar (`student/calendar.php`)** — the same timeline the parent sees, scoped
to themselves, with holiday rows for their own location and weekday.

**Materials (`student/materials.php`)** — everything shared this semester, in
lesson order.

Students **can add their own notes** to their lessons — "how practice went" sits
in the same thread as the teacher's account of the lesson. They cannot edit
materials.

---

# 9. Admin experience

Nav: **Schedule**, **Calendar**, **Leads**, **Students**, **Teachers**, an
**Admin** submenu, the semester selector, and the avatar menu. Leads sits in the
top bar rather than the submenu because it is a daily work queue, not a setting.

### Semester Schedule (`admin/schedule.php`)

The weekly pattern, drawn as **one grid per class day**, stacked (Saturdays on
top, then on through the week): **columns are the (location, teacher) pairs
assigned to that day**, rows are 30-minute slots over that day's real opening
hours. A teacher who works Saturdays but not Tuesdays simply has no Tuesday
column, and a Saturday-only location does not appear in the Tuesday grid at
all. Each cell is a reservation, a hold block, or empty. Clicking an empty
cell books one; clicking a filled one edits length or status, or deletes it.
An **Edit** toggle enables dragging a reservation to another slot (off by
default, so a stray gesture cannot move a family's lesson).

Cell colors are the point of the page — they encode who has paid:

| Cell | Meaning |
| --- | --- |
| White, italic gray | Pending reach out |
| White, normal | Pending confirmation |
| Blue pastel | Confirmed, nothing owed |
| Yellow pastel | Confirmed, owes the full term |
| Purple pastel | Confirmed, owes half the term |
| Dark blue | Confirmed, owes some other amount |
| Gray | Hold block (unpaid teacher time) |
| Green pastel | Guitar Ensemble class |
| Rose pastel | Musicianship Skills class |

### Calendar

**Semester view** (`admin/calendar.php`) lists every class date — active days and
breaks — and is not a month grid, because a semester that meets one day a week
would waste one. **Week view** (`admin/calendar_week.php`) is the same grid as the
schedule but populated with **real lessons**, placed under their *effective*
teacher and location so substituted and relocated lessons appear where they
actually happen. Cancelled lessons are omitted here (the slot is genuinely free
again) while remaining visible to the family and teacher.

### People

**Students** and **Teachers** lists filter by keyword (prefix tokens across
names, phones, addresses) and, behind a `[+]`, by teacher/instrument. The **Edit
Student** page is the hub: profile, parents, instruments, this semester's
reservations, recent notes, and the **Charges & Payments** ledger section.
**Edit Parent** manages children (create new, or link existing). All deletes are
soft, behind a confirmation.

### Admin submenu

Announcements, Semesters, Users, Locations, Settings, Email Templates — plus
**Maintenance** for developers only (migrations, activity log, email log, server
logs).

Settings worth knowing: which semester public registration is open for (empty =
closed), the inquiry form's term choices and staff notification address, the
site base URL used for links inside emails, and the footer phone number.

---

# 10. Key admin flows

### Create a semester

Eight steps: meta info **and fees** → active locations → then six CSV imports
(class days, class dates, location-teachers, hold blocks, the schedule,
opening charges/payments). Every import is Upload → Map columns → Validate → Commit,
validates row by row with a plain-English description of what each row will do,
skips bad rows rather than failing, and can be re-run later from Admin >
Semesters.

Two rules make taking over an existing roster safe: the **schedule import never
posts charges** (confirmed rows still generate lessons), and the **ledger import
is idempotent** (re-uploading the same file changes nothing).

Alternatively, **pre-populate from the previous semester**: every reservation
comes across as *pending reach out*, keeping teacher/location/day/time. Nothing
is confirmed, generated, or charged until an admin confirms each one — the
organization starts from last term's roster and calls each family. Rows whose
teacher no longer teaches there, or whose slot is now taken, are skipped and
reported.

### Confirm a reservation

The single most consequential admin action: generates the term's lessons and
posts the term's charges (§4). Reverting deletes **future** lessons only and may
post reversal credits; deleting soft-deletes and does the same.

### Process and convert leads

Leads carry a status (new → contacted → scheduled → converted/declined) and an
**append-only** note history, where a note can record a status change in the same
save, so two admins can never clobber each other. Uncompleted inquiry forms live
on a second tab as a call list; an admin can finish one on the family's behalf.

**Converting** creates the parent (adopting an existing account by email, filling
blanks only — never overwriting), creates each student with profile, instrument,
and parent link, optionally places a reservation via the same grid the schedule
uses, and moves any payment held on the lead onto a chosen student's ledger.
Reservations are created **pending reach out**, so **conversion charges nothing**.
The whole thing is idempotent: already-converted students are skipped, the
payment is guarded by its Stripe reference, and a re-run continues where it
stopped.

After conversion, "Send Account Invite" is the explicit step that gives the
parent a login.

### Record an offline payment

On the student's Charges & Payments card: **Record Payment** (check/cash/Zelle),
**Apply Scholarship**, or **Custom Ledger Entry**. Note there is deliberately no
idempotency key here — submitting twice records two payments.

### Move a lesson's standing time

Drag the reservation on the schedule grid in Edit mode. Conflict-checked against
the teacher's whole diary, at every location. If the **day of week stays the
same**, future lessons are retimed in place and keep their notes, attendance, and
substitutes; if the **day changes**, future lessons are regenerated against the
new day's calendar and per-occurrence data on them is lost. Past lessons never
move. No money moves.

### Change a duration

**One lesson**: the length select on the calendar modal — conflict-checked, no
billing. **The standing reservation, once confirmed**: the app refuses to just
apply it and routes to a **duration-change accounting page** showing lessons
used/remaining, a computed refund and new charge (both editable — the numbers are
a default, not a verdict), which posts the two ledger entries and then resizes
future lessons.

### Assign a substitute

Per lesson occurrence, from the calendar modal (or by dragging onto another
teacher's column). The substitute must actually be free — the point of naming
cover is that they can be there. See §7 for what changes in everyone's views. No
email is sent; visibility is entirely through the views.

### Cancel, mark missed, move a room, book a one-off

All per-occurrence, from the weekly calendar. **Cancelling** stamps the row
(never deletes), frees the slot, keeps it visible to family and teacher, and has
**no un-cancel** — if the week is back on, book a one-off in its place. A
**missed** mark triggers nothing automatic: no make-up, no ledger entry. A
**location override** moves one week only. A **one-off lesson** is conflict-checked
like anything else but belongs to no reservation and charges nothing.

---

# 11. Email

Every email the app sends goes through one function in `www/mailer.php`, and
every attempt — success or failure — is recorded in the email log. Two templates
(the inquiry family confirmation and staff notification) are **admin-editable**
under Admin > Email Templates, with escaping such that neither an admin's
wording nor a family's answer can inject markup; the rest are hard-coded.

**Every email sent to a parent, student, or teacher by an admin action announces
itself first** — the UI states "This will send an email to *address*" before
sending, and the confirmation afterwards names the recipient. That rule exists so
staff always know when a family is about to be contacted.

Send points:

| Trigger | To | Announced |
| --- | --- | --- |
| Public registration submitted | the family | self-service |
| Inquiry form finished | the family | self-service |
| Inquiry form finished | staff notification address | internal |
| Forgot password | the account holder | self-service |
| Admin adds a user with login | the new user | yes (radio label) |
| Admin sends verification / activation | that user | yes (confirm names the address) |
| Admin sends password reset | that user | yes (confirm names the address) |
| Admin sends a lead account invite | the parent | yes (confirm names the address) |
| Email template test send | the admin themselves | self |
| Mail test page | the admin themselves | self |
| Stripe payment receipts | the payer | sent by Stripe, not by us |

Flows that deliberately send **nothing**: lead conversion, adding a teacher /
student / parent, completing an inquiry on a family's behalf, and every
scheduling change including substitutes and cancellations.

**Demo safety.** Setting `EMAIL_REDIRECT_TO` in `config.local.php` delivers every
outgoing email to that one address instead of its real recipient, tagged
`[Redirected — was to …]` in the subject; `EMAIL_REDIRECT_ALLOWLIST` lets named
addresses through. Enforced at the single send function, so no code path can
bypass it. Stripe's own receipts are not covered.

---

# 12. Design Notes

## Design principles

Three principles, from BCM leadership, guide every screen.

**Warm, not institutional.** BCM's families are often navigating systems that
feel cold or bureaucratic. The portal should feel like an extension of the
conservatory itself — welcoming, personal, and human. Real photos, warm
language, the BCM gold-and-navy brand, and a visible phone number —
**(718) 841-7415** — on every page.

**Simple enough for a phone between lessons.** Teachers check the portal between
students, parents check it on the bus, students check it on a phone. Every screen
must make sense in under 10 seconds. *Four cards for parents. Three for students.
One main view for teachers.*

**Earn trust before asking for money.** Many BCM families are economically
challenged and cautious about online payments. The portal must establish
legitimacy — real organization, real people, real mission — before presenting a
payment button. **"Pay Now" is the last thing in the flow, not the first.**

## Colors

The portal extends the visual identity of `bronxconservatory.org`. Clean and
modern: white backgrounds, dark text, soft pastel accents. Friendly, uncluttered.

| Token | Use |
| --- | --- |
| `#0062DC` blue | Buttons, headers, links, accents |
| `#FFDC2B` gold | **Primary CTAs only** — Register, Pay Now, Submit |
| `#00132A` navy | Footer and contrast areas, with white text |
| `#FFFFFF` white | Page background |
| Warm grays | Secondary text, borders, subtle backgrounds |
| `#FF724C` coral | Occasional accent, when something needs spicing up |

**The schedule and calendar colors are excluded from all of this.** They encode
specific meanings (§9) and must not be restyled for aesthetic reasons.

---

# 13. Conventions

- **`foo.php` renders, `foo_eval.php` handles the POST** and redirects back with
  a flash message. See `docs/php-guidelines.md`.
- **All SQL lives in `www/lib/` manager classes.** Pages call managers.
- **Every write is logged** to the activity log; every email attempt to the email
  log. Both are browsable under Admin > Maintenance by developers.
- **Notes are append-only** — lesson notes, lead notes, and uncompleted-form
  notes are never edited or deleted, and always show who wrote them and when.
- **Permission checks live next to the data.** Who may see a lesson is one
  function (admins, its effective teacher, the student, their parents), and the
  notes/materials modal, the download endpoint, and every page reuse it.
- **Conflict checks are server-side and cover the teacher's whole diary**, across
  locations, including hold blocks and future occurrences. The client only
  pre-checks the obvious.
- **Idempotency where it counts**: lesson generation, confirmation charges,
  Stripe payments, lead conversion, and the ledger CSV import can all be re-run
  safely.
- **Migrations** live in `db_migrations/` (outside the web root) and are applied
  from Admin > Migrations. `schema.sql` is always the current complete schema;
  update both when changing structure.
- **Tests**: `php unit-tests/tools/phpunit.phar -c unit-tests/phpunit.xml`. The
  test database is rebuilt from `schema.sql` on every run, so a schema change
  that breaks the suite is a real break.
