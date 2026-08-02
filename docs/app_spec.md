App Specification

Centralizes the operations for parents, teachers, and administrators at the Bronx Conservatory of Music, a non-profit that provides private music instruction of the highest quality to Bronx children and adults, in their own neighborhoods, at the lowest possible tuition, thereby making conservatory training accessible to all.

Data Model

1. Users
- identifiers: email, cell_phone_number
- name: first_name, last_name, suffix?, preferred_name?
- address: address_street_1, address_street_2, address_city, address_state, address_zip
- contact: secondary_email, home_phone, cell_phone
- emergency contact information: contact_name, contact_phone
- medical?
- other: shirt_size
- Profile photo id

2. Student Profile
- user_id
- Instruments
- Class_Of
- recital?

3. parenthood
- parent_id
- child_id
- role? (father, mother, guardian)

4. Family
family_id

4. Teacher_Profile
- instrument(s)

5. Musical Instruments:
Piano, Guitar, Voice, Violin, Viola, Cello, and Double Bass

6. Locations
- Access Bronx Charter School, Bronx Community College)


--- SEMESTERS, LESSONS, SCHECULES

We need a concept of a "semester".  It should have a season and a year.  The seasons can be "fall", or "spring", or "summer", or "test".  The years can be any date.
- The idea of a "recurring_lesson" should be a "semester_lesson_reservation".  This reserves a particular teacher and location for a student, for a semster.
- A semester should have a "start_date", an "end_date", active locations, and a list of dates per location (imported through a csv) that classes will be held.  Each date should have a start-time and an end-time, a status ("active" or "inactive", and notes "Holiday Week" to put if the status is inactive)
- A semester should be set up through a wizard by an admin.
.. Step 1: enter the meta-information for the semester (start_date, end_date, season, year)
.. Step 2: Select the active locations for the semester (from the locations in the system)
.. Step 3: Upload a list of location-dates by csv ("Location Name", "Date", "start_time", "end_time", "status" (active or inactive), "notes" (like "Holiday Week")).
.. Step 4: Upload a list of location-teacher information for the semester ("Teacher Name", "Location Name")
.. Step 5: Upload the teachers' hold blocks ("Teacher Name", "Location Name", "Day", "Start Time", "End Time", "Title")
.. Step 6: Upload the schedule itself — one row per weekly lesson slot ("Student Name", "Teacher Name", "Location Name", "Day", "Start Time", "Duration Minutes", "Status"). This is how an existing schedule is moved into the portal, so it never posts charges: confirmed rows generate their lessons, but balances carried over from the old system are loaded separately.
.. Step 7: Upload the opening charges and payments — one ledger row each ("Student Name", "Entry Type", "Amount", "Date", "Debit or Credit", "Description"). This is the other half of taking over an existing roster: the fees families already ran up and the payments they already made, on the dates they happened, so the schedule's balance colours are right from day one. Re-uploading the same file is a no-op — rows already on the ledger are skipped.

Instead of uploading a schedule, a semester can be PRE-POPULATED from a previous
semester's schedule: every student keeps their teacher, location, day and time,
and comes across as "pending_reach_out" — the organization starts from last
semester's roster and calls each family to confirm. Nothing is confirmed, no
lessons are generated and nobody is charged until an admin confirms each
reservation. A reservation whose teacher no longer teaches at that location, or
whose slot is now taken (a new hold block, say), is skipped and reported.


7. Semester
- season
- year
- start_date
- End_date
- created_at
- created_by

8. semester_lesson_reservation
- semeseter_id
- teacher_id
- location_id,
- student_id,
- status (pending_reach_out, pending_confirmation, confirmed)
- day_of_week
- start_time
- duration
- created_at
- created_by

9. semester_location_dates
- semester_id
- location_id
- date
- start_time
- end_time
- status (active / inactive)
- title (like )"Day 1" or "Holiday")

10. lesson
- semester_lesson_reservation_id
- start_datetime
- location_id_override
- substitute_teacher_id?
- lesson_number (like first, second, third, etc. in the semester)
- created_at
- created_by

11. lesson_notes
- lesson_id
- notes
- created_at
- created_by

12. lesson_resources
- lesson_id
- resource_type (file or link)
- title
- file_id (if file)
- url (if link)
- created_at
- created_by

---- other administration

13. Announcements (general announcements)
- title
- body
- valid_until
- created_at
- created_by


Pages:


1. Administrators

When an admin first logs in, there aren't any "semesters", so we need to walk through the process of bootstrapping the experience.

Step 1: Create locations.  Admin should see all active locations and confirm that they are correct.  There should be a way of adding locations or removing locations.

Step 2: Upload teachers.  Admin should be able to browse the list of teachers and upload a CSV with teachers.  Columns should be:
- identifiers: email, cell_phone_number
- name: first_name, last_name, suffix?, preferred_name?
- address: address_street_1, address_street_2, address_city, address_state, address_zip
- contact: secondary_email, home_phone, cell_phone
- emergency contact information: contact_name, contact_phone
- other: shirt_size

Step 3: Create a semester

Step 3a: start_date, end_date, season, year
Step 3b: Select the active locations for the semester (from the locations in the system)
Step 3c: Upload a list of location-dates by csv ("Location Name", "Date", "start_time", "end_time", "status" (active or inactive), "notes" (like "Holiday Week")).
Step 3d: Upload a list of location-teacher information for the semester ("Teacher Name", "Location Name")
Step 3e: Upload the teachers' hold blocks (lunch and the like)
Step 3f: Upload the schedule ("Student Name", "Teacher Name", "Location Name", "Day", "Start Time", "Duration Minutes", "Status") — no charges are posted by this step
Step 3g: Upload the opening charges and payments ("Student Name", "Entry Type", "Amount", "Date", "Debit or Credit", "Description") — the balances families arrive with

If there are no semesters, the admin should go through this wizard.  If there is at least one semester, the system should figure out what is the "current" semester (based on the current date and start and end date).  If there is a current semester, we should default to the current semester.  Otherwise, we should default to the next semester in the future.  Otherwise we should default to the most recent semester in the past.

Here is the overall menu / nav architecture of the site.

Top menu bar: Navy background, white text.
- Top left: Name (linked to homepage) "Bronx Conservatory of Music" (shortens to just "BCM" on mobile... we'll eventually replace with a logo for mobile)
- Top right: menu
Submenu (which pushes down the content if visible)
Page Content
Footer (For now just "Copyright Bronx Conservatory of Music 2026" but will include phone numbers, help links, etc.)

Here are the different menu items based on the role.

1. Admins (if there is at least one semester)
- [Schedule] [Calendar] [Students] [Teachers] [Admin] [Semester selector] [Profile photo]
- Schedule takes the admin to the "Semester schedule" page.
- Students goes to a filterable list of students by keyword (start of token search)
- Teachers goes to a filterable list of teachers by keyword (start of token search)

"Semester Schedule"
- A grid. (for now this will only make sense on desktop)
- The columns should be for location-teacher pairs.  Column labels are:
   Location 1                                 Location 2
   Teacher 1-1  Teacher 1-2   Teacher 1-3  .. Teacher 2-1  Teacher 2-2 ....
- Each row is a timeslot.  The row labels are: SA 9:00 am, SA 9:30 am, ... 4:30pm.
- Each cell in the grid is a semester_lesson_reservation, or an empty cell.
- Clicking on an empty cell opens a modal to add a "semester_lesson_registration" - for that location and teacher column, at that time... they just need to fill in the student
- Clicking on an existing semester_lesson_reservation opens a modal that allows the administrator to edt the reservation.
... They may: change the status of the reservation
... They may: delete the reservation
- The status we show in the UI for a semester_lesson_reservation is either:
... Pending reach out
... Pending confirmation (if the status is pending)
... Confirmed (if the status is confirmed)
... Balance Due (if the reservation is confirmed and the user has a balance due)
Deleting the semester_lesson_reservation should mark the reservation as "deleted" and also delete all lessons in the future.  Any lessons that have happened in the past should remain unchanged.
- When the status changes to "confirmed" from "pending" we should generate the lessons from the semester_lesson_reservation.  If it goes backwards, we should delete the lessons from the semester_lesson_reservation.
- Eventually, we want to color-code these:
... Pending reach out: White background, italicized gray text
... Pending confirmation: White background, normal black text
... Confirmed with no outstanding balance: blue pastel background.
... confirmed with full semester outstanding balance: yellow pastel background.
... confirmed with half semester outstnading balance: purple pastel background
... confirmed with custom outstanding balance: dark blue background.

"Calendar" (Admin view)
- Different views: 
... monthly view which allows an admin to select a date.  On the calendar the user should see "session_locations" (just the "location name" and "title")  The user should be able to navigate between different months (within the semester selected)
... Weekly view: just like the semester schedule view but for a real week showing lessons insead of the abstract schedule.
... Clicking on a cell allows editing the "lesson" via a modal dialog:
A leson can be rescheduled within the day (so changing the time to a time not occupied by another lesson)
A lesson can be marked as "missed".
A lesson can be given a "substitute teacher".
An adminsitrator can add a "lesson note"

"Students" (Admin view)
- All the students in the system
- Filter by keyword (prefix token of student and parent names, phone numbers, address line)
- Filter by teacher (under a [+] filter)
- Filter by instrument (under the [+])
- Columnns: Name, Parent(s), Actions
- Name should be first name, last name
- Parents should be Name on the first line, phone number and email on the next line, with one set per parent
- Actions should be "Edit" button, taking to the student_edit screen.
- Add Student button in the top right

"Teachers" (Admin View)
- All the teachers in the system
- Filter by keyword (prefix token of student and parent names, phone numbers, address line)
- Filter by instrument (under the [+])
- Columnns: Name, Contact, Actions
- Name should be first name, last name
- Contact: Email and phone
- Actions should be "Edit" button, taking to the teacher_edit screen.
- Add Teacher button in the top right

"Edit Student" screen
- Profile photo (show current photo or placeholder on left, upload button on top right)
- Basic Information
... name: first_name, last_name, suffix?, preferred_name?
... identifiers: email, cell_phone_number
... address: address_street_1, address_street_2, address_city, address_state, address_zip
... contact: secondary_email, home_phone, cell_phone
... emergency contact information: contact_name, contact_phone
- Parents: List parents, top rght of section: "Add Parent" button
- Instruments
- Charges and payments
... Shows current balance
... shows line-item breakdown of this semester's charges and payments.
... If these do not add up to the balance, shows historical charges and paymetns to explain.
... administrator should be able to "apply scholarship" to the account or "create a custom ledger entry"
- [Delete this student] with confirmation modal.  Flags the user as deleted (doesn't actually delete)

"Edit Teacher" screen
- Profile photo (show current photo or placeholder on left, upload button on top right)
- Basic Information
... name: first_name, last_name, suffix?, preferred_name?
... identifiers: email, cell_phone_number
... address: address_street_1, address_street_2, address_city, address_state, address_zip
... contact: secondary_email, home_phone, cell_phone
... emergency contact information: contact_name, contact_phone
- Students: List students in current semester (with links to students)
- [Delete this teacher] with confirmation modal.  Flags the user as deleted (doesn't actually delete)

"Edit Parent" screen
- Profile photo (show current photo or placeholder on left, upload button on top right)
- Basic Information
... name: first_name, last_name, suffix?, preferred_name?
... identifiers: email, cell_phone_number
... address: address_street_1, address_street_2, address_city, address_state, address_zip
... contact: secondary_email, home_phone, cell_phone
... emergency contact information: contact_name, contact_phone
- Children: List children with links to their profiles and "Add Child", which opens dialog with two top-level buttons: "Add New Child" and "Link existing child".
... Add New Child: enter first name, last name, suffix, preferred name, grade (class of)
... Link existing child: Typeahead search for child with "Link Child" button.
(Use pattern from cub_scouts)
- [Delete this parent] with confirmation modal.  Flags the user as deleted (doesn't actually delete)

There should be a place in the admin menu that goes to a "Maintenance" section that is only available for a "developer".  So, there should be an "is_developer" field in the user's table, and if they are a developer and an admin, they should see the maintenance screens (like email_log, activity_log, server_logs when we build that, etc.)


1. Homepage - Parents
- Announcements: Recent active announcements in chronological order
- My children: shows childs' profile photo, name, instrument, teacher - clicking on this sees their child's schedule, recent teacher notes, materials.  (See the cub_scouts app for this homepage widget...)
... When the next lesson is, is the loudest thing on the card - it is what a parent opens the portal to check.
... A child who owes money shows "Balance due: $X", linking to Balance & Payments.  It is red only when the family has fallen behind the payment schedule below - a balance that is simply not due yet is not an alarm.
- Upcoming Lessons: the family's next four lessons (date, time, child, location), each with Notes and Materials links that open that lesson in a modal.
- Balance & Pahments: 
... "You are paid in full.  Thank you!"
... OR "You have a balance of $X.  Click here to pay the balance."
- My Profile

Money is tracked per term: every ledger entry carries the semester it belongs
to, and a term's surplus credit rolls forward into the next one.  What families
are asked to pay is
... at least half the term's charges by two weeks before the term starts, and
... the rest by the lesson before its half-way point (of 14 lessons, by the 6th).
A balance that has missed either of those is "past due" and shows in red.  On
Balance & Payments a parent can pay any amount up to what they owe, by card
(Stripe Elements, so card details never reach our server); the payment is
applied to the oldest debt first, child by child.

The parents menu should be:
[Calendar] [Billing] [Profile photo (to edit profile)]

2. Homepage - Students
- My Schedule: Upcoming classes (note breaks)
... shows all upcoming lessons and inactive semester_location_dates for the current semester that are on the same weekday is the person's semester_lesson_reservation, so that they know when holidays are.
... every lesson opens its notes and materials in a modal, where the student can add a note of their own.
- Recent Lessons: the last few lessons, which is where the notes and materials from them are.
- Notes (most recent first)
- My Materials (shows resources linked to lessons this semester, in order of the chronological date of the lesson)

The students menu should be:
[Calendar] [Materials] [Profile photo (to edit profile)]

3. Homepage - Teachers
- Today's lessons: one card per lesson of a single day, in chronological order.  Each card shows time, student name, class name, Room / location.  Online lessons should be tagged with an icon.  It should be easy to mark that the student attended the lesson (or missed the lesson) - and to take that mark back off again ("Unmark", with a confirmation), because a mis-tap between two lessons should not be permanent.
... Lessons are sparse, so when there are none today the page shows the next day that has any, under the title "Upcoming Lessons".  There is no date picker for the same reason - most dates have nothing on them.  Navigation is by teaching day: arrows to the previous/next one, and the Teaching Days view for the list.
... Each card carries the lesson's notes and its materials, so the teacher never has to leave the day to write one.  "Add a resource" attaches a file or a link (each with a title) in a modal.
- Teaching Days: just the dates a teacher works, with where they are and how many lessons - the view that answers "am I at Bronx CC on Saturday?".  Each day opens the hour-by-hour view.
- [Calendar]

The teachers' menu should be:
[Teaching Days] [Calendar] [Profile photo (to edit profile)]

## Lesson notes

Notes belong to a lesson, not to a person: each one is its own row, kept for
good, and shown with who wrote it and when.  Anyone who may see a lesson may
add a note to it - its teacher, an admin, the student, and their parents - so
"she has a cold, can we make this up?" and the teacher's account of the lesson
sit in the same short thread.  Notes are saved with a button, never
auto-saved: the teacher should know it was written down.

The family reaches them from their schedule - every lesson on the student's
and the parent's pages, past and upcoming, opens its notes and materials in a
modal that they can add to.

The teacher calendar should be like the admin calendar for the monthly view.
The weekly view should be just a list of lessons that week in chronological order.
The teacher should be able to click on a lesson from this view

## Payments

We need to do light accounting so we can explain balances.
"ledger_entries"
- for_student_id
- date
- accounting_type (debit or credit)
- entry_type (registration, lessons, recital_fee, payment, scholarship_application, other)
- amount
- semester_id
- description
- created_at
- created_by`

Transactions:

1. Confirming the registration of a semester: 
- Registration, amount should be the "registration cost" set in settings.
- Lessons, amount should be the "semester lesson cost" set in settings.
- recital fee, amount should be in the "recital fee" set in settings.

2. Payment
- amount should be whatever they paid

3. Scholarship application
- scholarship application, amount should be specified by an administrator

4. other
- should be specified by an administrator.

We will use Stripe to facilitate payments.


## Family Portal — Design Specification

**Version 2.0 — April 07, 2026**

*Informed by brainstorming session with BCM leadership*

---

# 1. Design Principles

Every decision in this spec is guided by three principles drawn from BCM's mission and the needs of the families it serves.

### Warm, not institutional.

BCM's families are often navigating systems that feel cold or bureaucratic. The portal should feel like an extension of the conservatory itself — welcoming, personal, and human. Real photos, warm language, the BCM gold-and-navy brand, and a visible phone number on every page.

### Simple enough for a phone between lessons.

Teachers check the portal between students. Parents check it on the bus. Students check it on a phone. Every screen must make sense in under 10 seconds.

**Four cards for parents. Three for students. One main view for teachers.**

### Earn trust before asking for money.

Many BCM families are economically challenged and cautious about online payments. The portal must establish legitimacy — real organization, real people, real mission — before presenting a payment button.

The **"Pay Now"** button is the last thing in the flow, not the first.

---

# 2. Brand & Visual Identity

The portal extends BCM's existing visual identity from `bronxconservatory.org`.

# Design Notes

## Colors.
The app colors should primarily feature a clean, modern aesthetic. 

Buttons should be #0062DC — used for headers, links, and accents

The background should be #FFFFFF, keeps everything clean and breathable

payment buttons should be #FFDC2B (from "Register Now" button) — used exclusively for primary CTAs: Register, Pay Now, Submit

the footer and contrast areas should be #00132A with white text

* **Warm grays:** For secondary text, borders, and subtle backgrounds

, and you should use #FF724C whenever you want to spice it up a bit. 

The colors in the schedule and calendar should remain exactly how they are as they dictate specific meanings within the web app.

The interface should be generally light with white backgrounds, dark text, and soft pastel accents (specific collors mentioned above) for chat bubbles and UI elements to encourage a clean, organized, and inviting community atmosphere. App Interface: White backgrounds. Overall Vibe: Friendly, modern, and uncluttered.

Style should be guided by the slogan "warm, not institutional."

BCM's families are often navigating systems that feel cold or bureaucratic. The portal should feel like an extension of the conservatory itself — welcoming, personal, and human. Real photos, warm language, the BCM gold-and-navy brand, and a visible phone number on every page.

* **Phone number:** `(718) 841-7415` visible in the footer of every page and prominently on the login/registration page.