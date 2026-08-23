---
layout: ../../layouts/DocsLayout.astro
title: Email & Announcements
description: The email log, admin-editable email templates, and dashboard announcements.
---

# Email & Announcements

**Tables:** `emails_sent`, `email_templates`, `announcements`

## `emails_sent`

A log of **every** email the system sends, successful or not — one of the
project's standing infrastructure rules (alongside the activity log). Columns:
`sent_by_user_id` (`NULL` for system-initiated sends), `to_email`, `to_name`,
`cc_email`, `subject`, the full `body_html`, `success`, and `error_message`.
Browsable by developers in Admin &gt; Maintenance &gt; Email Log.

## `email_templates`

Transactional emails whose **wording staff can change without a deploy**
(Admin &gt; Email Templates). The division of ownership is the interesting
part:

- **The code owns** `template_key` (unique), `name`, `description`, and
  `available_variables` — a JSON array of the `{{variable}}` names the calling
  code actually supplies.
- **The admin owns** `subject` and `body_markdown`, written in Markdown and
  converted to HTML at send time.

Rendering (`EmailTemplateManagement::render`) escapes every substituted
value, so a template can never be made to emit injected markup, and an unknown
`{{placeholder}}` renders empty rather than leaking to a family.

Two templates ship seeded, both for the inquiry flow:

- `inquiry_family_confirmation` — sent to the family when they finish the
  public Request Information form, echoing back what they told us.
- `inquiry_staff_notification` — sent to the staff notification address (the
  `inquiry_notification_email` setting) on every completed form, with a link
  to open the lead in the portal.

Migrations `INSERT IGNORE` the seed rows, so an edited copy is never
overwritten by re-running a migration.

## `announcements`

General announcements shown on dashboards (the parent homepage's
Announcements card, for example) while `valid_until` has not passed. Columns:
`title`, `body`, `valid_until` (indexed), `created_by_user_id`. There is no
targeting — an active announcement is shown to everyone whose dashboard has an
announcements card.
