/**
 * Navigation definition for the docs sidebar.
 *
 * The order and grouping here drive both the desktop sidebar and the mobile
 * "Browse" overlay. Slugs are relative to the site root (no leading slash
 * here; it's added in the components).
 */

export interface NavItem {
  title: string;
  slug: string;
  children?: NavItem[];
  /** When true, the children are shown only while the user is in the section. */
  collapsible?: boolean;
}

export const navItems: NavItem[] = [
  { title: 'Overview', slug: '' },
  {
    title: 'Data Model',
    slug: 'data-model',
    children: [
      { title: 'Users, Roles & Profiles', slug: 'data-model/users-roles-profiles' },
      { title: 'Semesters & Locations', slug: 'data-model/semesters-locations' },
      { title: 'Reservations, Lessons & Hold Blocks', slug: 'data-model/reservations-lessons-hold-blocks' },
      { title: 'Lesson Notes & Resources', slug: 'data-model/lesson-notes-resources' },
      { title: 'Billing & Payments', slug: 'data-model/billing-payments' },
      { title: 'Leads & Intake', slug: 'data-model/leads-intake' },
      { title: 'Email & Announcements', slug: 'data-model/email-announcements' },
      { title: 'Files & Infrastructure', slug: 'data-model/files-infrastructure' },
    ],
  },
  { title: 'User Model', slug: 'user-model' },
  {
    title: 'User Registration',
    slug: 'registration',
    children: [
      { title: 'Inquiry Form', slug: 'registration/inquiry-form' },
      { title: 'Registration Form', slug: 'registration/registration-form' },
    ],
  },
  { title: 'Parent Experience', slug: 'parent-experience' },
  { title: 'Teacher Experience', slug: 'teacher-experience' },
  { title: 'Student Experience', slug: 'student-experience' },
  {
    title: 'Admin Experience',
    slug: 'admin-experience',
    children: [
      { title: 'Create a Semester', slug: 'admin-experience/create-a-semester' },
      { title: 'View the Semester Schedule', slug: 'admin-experience/view-semester-schedule' },
      { title: 'View the Calendar & a Week', slug: 'admin-experience/view-week-schedule' },
      { title: 'View a Student', slug: 'admin-experience/view-a-student' },
      { title: 'Process Leads & Uncompleted Forms', slug: 'admin-experience/process-leads' },
      { title: 'Convert a Lead to a Family', slug: 'admin-experience/convert-a-lead' },
      { title: 'Accept a Check & Ledger Entries', slug: 'admin-experience/accept-a-check' },
      { title: "Move a Lesson's Time", slug: 'admin-experience/move-a-lesson' },
      { title: "Change a Lesson's Duration", slug: 'admin-experience/change-lesson-duration' },
      { title: 'Assign a Substitute Teacher', slug: 'admin-experience/assign-a-substitute' },
      { title: 'Cancellations & One-Off Changes', slug: 'admin-experience/cancellations-and-one-offs' },
    ],
  },
  { title: 'Sample Data', slug: 'sample-data' },
];

/**
 * Normalize a path to a comparable slug:
 *   "/overview/"  -> "overview"
 *   "/"           -> ""
 */
export function pathToSlug(pathname: string): string {
  return pathname.replace(/^\/+|\/+$/g, '');
}
