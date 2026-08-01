# Sample data for the setup flow

Realistic test CSVs for walking through first-time setup end to end, then
adding a second semester on top of the same roster.

- **general/** — data that outlives any one semester: locations, teachers,
  students and parents. Upload once.
- **fall_semester/** — the semester-scoped files for Fall 2026, including the
  schedule itself.
- **spring_semester/** — the same setup files for Spring 2027, so you can
  create a second semester against the same people and locations. There is
  deliberately no schedule file here: spring's schedule comes from
  pre-populating off fall.

## 1. General setup

Reset first with `./reset_db.sh`, then upload in this order:

0. **general/locations.csv** — setup Step 1 > Upload Locations CSV. Creates the
   two locations every later file references by name.
1. **general/teachers.csv** — Admin > Teachers > Upload CSV (or setup Step 2)
2. **general/people.csv** — Admin > Students > Upload Students & Parents CSV
   (optional setup stage). One file for the whole roster: parent rows carry
   contact/address details, student rows carry grade/instruments and list
   their parents by name (or email — see Fatima's row) in the Parents
   column. Siblings share the same parent rows; row order doesn't matter.

## 2. Fall 2026

3. Create the semester (Fall 2026, 2026-09-08 – 2026-12-20), pick both
   locations, then:
4. **fall_semester/location_dates.csv** — semester wizard step 3 (Saturdays
   Sep 12 – Dec 19, 9:00 am – 5:00 pm at both locations, with Thanksgiving
   weekend inactive)
5. **fall_semester/location_teachers.csv** — semester wizard step 4 (the
   schedule grid's columns)
6. **fall_semester/hold_blocks.csv** — Admin > Semesters > Import Hold Blocks.
   Gives every teacher a Saturday lunch, 12:00–1:30 pm, so those cells are held
   instead of bookable. James Okafor teaches at both locations but gets only one
   lunch: a teacher can't be in two places at once, and the importer rejects
   the second row if you add it.

   Import this BEFORE the schedule. A teacher may only ever hold one
   commitment at a time, so if a student is already booked at 12:30 the
   validation step rejects that teacher's lunch and tells you which student,
   time and location is in the way.

7. **fall_semester/semester_location_reservations.csv** — the last wizard step
   (Admin > Semesters > Import Schedule). One row per weekly lesson slot: all
   14 students, one slot each, 10 confirmed and 4 still pending. Confirmed rows
   generate their lessons, but **no charges are posted** — this file is how an
   existing schedule moves into the portal, and those families' balances came
   from wherever they were kept before.

   Things it exercises: 45- and 60-minute lessons alongside the usual 30;
   siblings booked at the same hour with different teachers (the Ramos and Cruz
   pairs); James Okafor teaching at Access in the morning and Bronx Community
   College after his lunch block.

Then open the Semester Schedule — the grid is full — and click empty cells to
add more.

## 3. Spring 2027 (the second semester)

The same setup steps against the same roster, which is the point — nothing in
**general/** gets re-uploaded. Create the semester (Spring 2027, 2027-01-25 –
2027-05-23), pick both locations, then upload
**spring_semester/location_dates.csv**, **location_teachers.csv** and
**hold_blocks.csv**.

The spring files deliberately differ from fall so the two semesters can't be
confused for each other, and so semester-scoped data is visibly scoped:

- 17 Saturdays, Jan 30 – May 22, with Midwinter Recess (Feb 20) and Spring
  Break (Apr 3) inactive — 15 class days.
- Bronx Community College runs a shorter day in spring (10:00 am – 4:00 pm);
  Access Bronx Charter School stays 9:00 am – 5:00 pm.
- Grace Lin picks up a second location, so spring has two teachers spanning
  both sites (Okafor and Lin) — each still gets exactly one lunch row.
- Marisol Vega has a second hold block, a 9:00–9:30 am faculty meeting, since
  a teacher may hold several non-overlapping blocks in a week.

Then, instead of uploading a schedule: **Admin > Semesters > Pre-populate from
Previous**, with Fall 2026 as the source. All 14 students come across keeping
their teacher, location, day and time, every one of them **pending reach out** —
the list to call through. Nothing is confirmed, no lessons are generated and
nobody is charged. Re-running it is safe: rows already carried over are skipped
rather than duplicated, and anything that no longer fits (a teacher who left
that location, a slot a new hold block now covers) is listed as an error and
left out.
