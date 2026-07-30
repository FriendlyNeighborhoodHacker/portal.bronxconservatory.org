
# 3. Registration Flow

Registration is the most critical user journey. It must handle two distinct family types with a single form, capture scheduling preferences upfront, and build trust throughout.

## 3.1 The Registration Form

One form serves all families — new and returning, ready-to-pay and needs-conversation. It replaces the current Wufoo form.

### Form Fields

* **Parent / Guardian Information:** First name, last name, email, phone, preferred contact method (phone/email/text), home address, relationship to student(s)
* **Student Information (repeatable for siblings):** First name, last name, date of birth, instrument(s) of interest, previous experience (none / beginner / intermediate / advanced), current school name and grade
* **Scheduling Preferences:** Preferred day(s) of week, preferred time window (morning / afternoon / evening), preferred location (list BCM locations), teacher gender preference (no preference / female / male), any scheduling constraints (free text — e.g., "siblings need back-to-back")
* **Emergency & Medical:** Emergency contact name and phone, medical conditions or allergies (optional), consent checkboxes (photo release, terms and conditions, liability waiver)
* **How Did You Hear About Us?:** Dropdown: friend/family, school, community organization, social media, website, other

## 3.2 Two Paths from the Same Form

At the bottom of the form, the parent sees two clear options:

### Option A: "Register & Pay Now" (gold button)

For returning families and new families ready to commit. Submits the form and goes directly to Stripe Checkout for payment.

### Option B: "Register — I’d Like to Talk First" (outlined button)

For families who want a conversation before paying. Submits the form, creates the family record, and places them in the admin’s follow-up queue.

Both options create the same family/student records in the database. The only difference is the family's status:

* **Payment Pending** — fast path
* **Needs Follow-Up** — conversation path

## 3.3 The Conversation Path

1. Family submits form with **"I'd like to talk first."** System creates family record with status **"Needs Follow-Up."**

2. Admin sees the family in their Action Queue with all preferences pre-loaded:

   > "Martinez family — 2 students, piano, Saturdays 9-11am, Crotona, prefers female teacher."

3. Admin calls the family. During or after the call, admin adds internal notes to the family record:

   > "Spoke with mom Maria, very interested, worried about cost. Explained sliding scale. Wants to visit Crotona Saturday."

4. Admin updates family status to **"Schedule Assigned"** and enters their preliminary schedule.

5. System sends the family an email:

   > "Great news! We have a spot for your family at BCM. Here's your schedule — click below to complete enrollment."

   Email includes a direct link to review schedule and pay.

6. Family clicks the link, reviews their schedule, and pays through Stripe Checkout (or calls to arrange cash payment).

7. Admin marks enrollment complete. Family's portal dashboard is now active.

## 3.4 Returning Family Re-Enrollment

Returning families log into their existing account and see a **"Re-Enroll for [Next Semester]"** card on their dashboard.

Tapping it shows a pre-filled form with their current info for review. They can update anything that's changed, confirm which children are re-enrolling and in which instruments, review scheduling preferences, and pay.

For most returning families, this takes under 2 minutes.