# Sample data for the setup flow

Realistic test CSVs for walking through first-time setup end to end, then
adding a second semester on top of the same roster.

- **general/** — data that outlives any one semester: locations, teachers,
  students and parents. Upload once.
- **fall_semester/** — the semester-scoped files for Fall 2026.
- **spring_semester/** — the same three files for Spring 2027, so you can
  create a second semester against the same people and locations.

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

   Import this BEFORE reserving lesson slots. A teacher may only ever hold
   one commitment at a time, so if a student is already booked at 12:30 the
   validation step rejects that teacher's lunch and tells you which student,
   time and location is in the way.

Then open the Semester Schedule and click empty cells to reserve slots for
the imported students.

## 3. Spring 2027 (the second semester)

Same three steps against the same roster, which is the point — nothing in
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
