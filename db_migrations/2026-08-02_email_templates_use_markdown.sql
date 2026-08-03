-- Migrate email_templates from HTML body to markdown body
-- For fresh installs, schema.sql already has body_markdown with content.
-- For existing databases, this migration adds the column with a basic conversion.

ALTER TABLE email_templates ADD COLUMN body_markdown LONGTEXT DEFAULT NULL;

-- For existing templates with HTML bodies, do a basic text extraction by
-- stripping common HTML tags. Admins should review the result and refine
-- using the markdown syntax guide in the admin editor.
UPDATE email_templates
SET body_markdown =
  REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    body_html,
    '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#0D1B2A;">', ''),
    '</div>', ''),
    '<p>', ''),
    '</p>', '\n'),
    '<h3 style="margin-bottom:4px;">', '### '),
    '</h3>', '\n'),
    '<ul style="margin-top:0;">', ''),
    '</ul>', ''),
    '<li>', '- '),
    '</li>', '\n'),
    '<strong>', '**'),
    '</strong>', '**'),
    '<table cellpadding="0" cellspacing="0">', '')
WHERE body_html IS NOT NULL AND body_markdown IS NULL;

-- Make body_markdown NOT NULL, with a safe default for any that are still null
UPDATE email_templates SET body_markdown = 'Email template content' WHERE body_markdown IS NULL;
ALTER TABLE email_templates MODIFY COLUMN body_markdown LONGTEXT NOT NULL;

-- Drop the old HTML column
ALTER TABLE email_templates DROP COLUMN body_html;
