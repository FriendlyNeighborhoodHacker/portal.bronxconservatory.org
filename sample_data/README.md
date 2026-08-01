# Sample data for the setup flow

Realistic test CSVs for walking through first-time setup end to end.
Reset first with `./reset_db.sh`, then upload in this order:

0. **locations.csv** — setup Step 1 > Upload Locations CSV. Creates the two
   locations every later file references by name.
1. **teachers.csv** — Admin > Teachers > Upload CSV (or setup Step 2)
2. **people.csv** — Admin > Students > Upload Students & Parents CSV
   (optional setup stage). One file for the whole roster: parent rows carry
   contact/address details, student rows carry grade/instruments and list
   their parents by name (or email — see Fatima's row) in the Parents
   column. Siblings share the same parent rows; row order doesn't matter.
3. Create the semester (Fall 2026, 2026-09-08 – 2026-12-20), pick both
   locations, then:
4. **location_dates.csv** — semester wizard step 3 (Saturdays Sep 12 – Dec 19,
   with Thanksgiving weekend inactive)
5. **location_teachers.csv** — semester wizard step 4 (the schedule grid's
   columns)
6. **hold_blocks.csv** — Admin > Semesters > Import Hold Blocks. Gives every
   teacher a Saturday lunch, 12:00–1:30 pm, at each location they teach at,
   so those cells are held instead of bookable.

Then open the Semester Schedule and click empty cells to reserve slots for
the imported students.
