-- Admin-editable email templates (Admin > Email Templates), plus the two
-- shipped templates for the public information-request flow.
--
-- INSERT IGNORE, never ON DUPLICATE KEY UPDATE: once an admin has edited a
-- template, re-running this migration must never overwrite their wording.
-- Indexes and the UNIQUE key are declared inline so CREATE TABLE IF NOT EXISTS
-- covers them and this file stays re-runnable. Idempotent: a no-op on an
-- installation that is already current (including one created fresh from
-- schema.sql).

CREATE TABLE IF NOT EXISTS email_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  description VARCHAR(500) DEFAULT NULL COMMENT 'When this email is sent, shown to the admin',
  subject VARCHAR(255) NOT NULL,
  body_html LONGTEXT NOT NULL,
  available_variables VARCHAR(1000) DEFAULT NULL COMMENT 'JSON array of {{variable}} names the code supplies',
  updated_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_et_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO email_templates (template_key, name, description, subject, body_html, available_variables) VALUES
(
  'inquiry_family_confirmation',
  'Information request — family confirmation',
  'Sent to the family when they finish the public Request Information form.',
  'Thank you for your interest in the Bronx Conservatory of Music',
  '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#0D1B2A;">\n<p>Hello {{parent_first_name}},</p>\n<p>Thank you for asking about lessons for {{student_first_name}}. We have your request and a member of our team will be in touch soon to talk through instruments, teachers, and times.</p>\n<p>If you would like to reach us first, call <strong>{{contact_phone}}</strong> or reply to this email.</p>\n<h3 style="margin-bottom:4px;">What you told us</h3>\n<ul style="margin-top:0;">\n<li>Student: {{student_first_name}} {{student_last_name}}, age {{student_age}} ({{enrollment_status}})</li>\n<li>Instruments of interest: {{instruments_of_interest}}</li>\n<li>Term: {{semester_label}}</li>\n<li>Music theory: {{theory_knowledge}} (free theory program: {{theory_program_interest}})</li>\n<li>Prior study: {{music_background}}</li>\n<li>Questions or concerns: {{comments}}</li>\n</ul>\n<p>We look forward to making music with you.</p>\n<p>— The Bronx Conservatory of Music</p>\n</div>',
  '["parent_first_name","parent_last_name","parent_email","parent_phone","student_first_name","student_last_name","student_age","enrollment_status","instruments_of_interest","semester_label","owned_instruments","music_background","theory_program_interest","theory_knowledge","referral_source","comments","mailing_address","newsletter_opt_in","sms_consent","contact_phone","site_title","lead_admin_url"]'
),
(
  'inquiry_staff_notification',
  'Information request — staff notification',
  'Sent to the staff notification address (Admin > Settings) on every completed Request Information form.',
  'New information request — {{parent_first_name}} {{parent_last_name}}',
  '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#0D1B2A;">\n<p>Someone finished the Request Information form.</p>\n<table cellpadding="0" cellspacing="0">\n<tr><td style="padding:2px 12px 2px 0;"><strong>Parent</strong></td><td>{{parent_first_name}} {{parent_last_name}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Email</strong></td><td>{{parent_email}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Phone</strong></td><td>{{parent_phone}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Address</strong></td><td>{{mailing_address}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Student</strong></td><td>{{student_first_name}} {{student_last_name}}, age {{student_age}} ({{enrollment_status}})</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Interested in</strong></td><td>{{instruments_of_interest}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Term</strong></td><td>{{semester_label}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Owns</strong></td><td>{{owned_instruments}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Prior study</strong></td><td>{{music_background}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Theory program</strong></td><td>{{theory_program_interest}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Theory level</strong></td><td>{{theory_knowledge}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Heard about us</strong></td><td>{{referral_source}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Newsletter</strong></td><td>{{newsletter_opt_in}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Texts OK</strong></td><td>{{sms_consent}}</td></tr>\n<tr><td style="padding:2px 12px 2px 0;"><strong>Comments</strong></td><td>{{comments}}</td></tr>\n</table>\n<p><a href="{{lead_admin_url}}">Open this lead in the portal</a></p>\n</div>',
  '["parent_first_name","parent_last_name","parent_email","parent_phone","student_first_name","student_last_name","student_age","enrollment_status","instruments_of_interest","semester_label","owned_instruments","music_background","theory_program_interest","theory_knowledge","referral_source","comments","mailing_address","newsletter_opt_in","sms_consent","contact_phone","site_title","lead_admin_url"]'
);
