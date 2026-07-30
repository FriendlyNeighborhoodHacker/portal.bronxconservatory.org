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

4. Teacher_Profile
- instrument(s)

5. Musical Instruments:
Piano, Guitar, Voice, Violin, Viola, Cello, and Double Bass

6. Locations
- Access Bronx Charter School, Bronx Community College)

7. Room availability?
8. Teacher availability?

9. Lesson
- Recurring?
- Lesson_Type (individual, group)
- Name
- Location
- Date and time
- Teacher
- Student
- Status
- parent_id
- attended?
- teacher_override (how we mark a substitute teacher)

10. Recurring_Lessons

11. Group_lesson_student_memberships (lesson_id, student_id, attended?)
12. Group_lesson_teacher_memberships

13. Student_notes
14. Teacher_notes
15. Parent_notes
16. Lesson_notes: notes the teacher writes after the lesson.
17. Lesson_resources: like voice recordings and sheet musics
18. Announcements

Pages:

1. Homepage - Parents
- My children: shows childs' name, instrument, teacher - clicking on this sees their child's schedule, recent teacher notes, materials.
- Balance & Pahments: At a glance installment plan ???? Paid, Due [Pay Now] button
- Messages: Announcements about holiday schedules, recital information, general updates
- My Profile

2. Homepage - Students
- My Schedule: Upcoming classes (note breaks)
- Teacher Notes (most recent first)
- My Materials

3. Homepage - Teachers
- Today's lessons: Simple list of today's lessons in chronological order.  Each row shows time, student name, class name, Room / location.  Online lessons should be tagged with an icon.  Can log a note by clicking on a log link here.  auto-saves


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

* **Primary blue:** `#4A7FC1` (from BCM logo) — used for headers, links, and accents
* **Navy:** `#0D1B2A` (from website footer) — used for sidebar, footer, and contrast areas
* **Gold/Yellow:** `#E8C547` (from "Register Now" button) — used exclusively for primary CTAs: Register, Pay Now, Submit
* **White:** `#FFFFFF` — primary background, keeps everything clean and breathable
* **Warm grays:** For secondary text, borders, and subtle backgrounds
* **Typography:** Clean sans-serif (the site uses a modern sans). Portal should match.
* **Imagery:** Real photos of BCM students and teachers where possible, especially on the landing page and registration flow.
* **Logo:** BCM treble-clef logo in the top left of every portal page.
* **Phone number:** `(718) 841-7415` visible in the footer of every page and prominently on the login/registration page.