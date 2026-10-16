# GradePredict AI

A full PHP website for predicting student grades from academic factors (past grades,
attendance, assignments, quizzes, participation), built to match the provided mockups:
a public landing page, login/register, a student dashboard/portal, and an admin dashboard/portal.

## Requirements

- PHP 8.0 or newer, with the `pdo_mysql` extension (enabled by default in XAMPP, WAMP, MAMP,
  and virtually all standard PHP hosting).
- A running **MySQL or MariaDB server** — e.g. start "MySQL" from the XAMPP Control Panel.
  The app connects to `localhost` with the standard XAMPP defaults (user `root`, no password)
  and automatically creates the `gradepredict_ai` database, all tables, and demo data the
  first time you load the site. If your MySQL setup uses a different host/user/password,
  edit the `DB_*` constants at the top of `config.php`.
- If you'd rather set the database up by hand instead of letting the app do it automatically,
  a standalone `schema.sql` is included — import it via phpMyAdmin or `mysql -u root -p < schema.sql`.

## UI/UX component system

On top of the core app, the front end includes a small shared design system and component library (all in `assets/css/style.css` and `assets/js/app.js`, so no extra includes are needed per page):

- **Design tokens** — an 8px spacing scale, a fluid type scale (`clamp()`-based), consistent line-heights, a documented color palette (blue primary + a slate neutral scale + semantic success/warning/danger colors), and a Plus Jakarta Sans (headings) + Inter (body) font pairing loaded from Google Fonts.
- **Toast notifications** — every success/error message (`render_alert()` / `render_flash()`) automatically appears as a toast, with a static banner as a no-JS fallback.
- **Confirmation dialogs** — destructive actions (delete student/subject/user) use a styled modal (`data-confirm="..."` attribute) instead of the browser's native `confirm()`.
- **Stepper** — the registration page is a 3-step wizard (Account → Role → Security).
- **Command palette** — press `Ctrl/Cmd+K` (or the "Quick Jump" button) anywhere to jump to any page; on the admin side it also searches students and subjects live.
- **Autocomplete search bar** — the admin top bar search searches students/subjects as you type (`admin/ajax_search.php`).
- **Pagination** — long tables (Students, Predictions, Grade History, Data Inputs) are paginated server-side.
- **Infinite scroll** — the admin Activity Logs page loads more rows as you scroll (`admin/ajax_activity_logs.php`).
- **Date picker** — Admin Reports has a native date-range filter that drives both the on-screen stats and the CSV export.
- **Skeleton loaders, spinners, tooltips, and form error/success states** — used on the dashboards, submit buttons, and inputs respectively.
- **Responsive footer** — included on every dashboard page.

## ⚠️ This version switched from SQLite to a real MySQL database

Earlier versions of this app used a self-contained SQLite file. **This version uses a real
MySQL/MariaDB database instead** (e.g. XAMPP's bundled MySQL on `localhost`), so it behaves
like a real deployed school system and can be inspected/managed with phpMyAdmin.

If you're upgrading from an old SQLite-based copy of this app:
1. There is nothing to migrate — SQLite and MySQL are different database engines. Just deploy
   this new version fresh; it creates its own MySQL database and demo data automatically.
2. Your old `data/gradepredict.sqlite` file is no longer used at all and can be deleted.
3. The `data/` folder is now only used for error logs (`data/php-error.log`) and database
   backups (Admin → Settings → Backup & Restore).

## Branding

The app is branded for **Colegio de Santa Rita de San Carlos, Inc.**, using the school's seal
(`assets/img/logo.png`) throughout — sidebars, login/register, the landing page, and the
favicon. The landing page hero and About section use the provided dashboard/photo imagery
(`assets/img/dashboard-hero.jpg`, `assets/img/about-hero.jpg`), and the login/register side
panel uses `assets/img/auth-illustration.jpg`.

## Newest additions

- **Forced password change on first login** — accounts created by an admin (Admin → Users →
  Create User, or Admin → Teachers → Add Teacher) get a temporary password and must set their
  own before they can use the account.
- **Session idle timeout** — 30 minutes of inactivity signs a user out automatically.
- **Security headers + CSP** on every response, `display_errors` now off by default (flip
  `APP_DEBUG` to `true` at the top of `config.php` if you need to see raw errors while
  troubleshooting — errors are always logged to `data/php-error.log` either way).
- **CSV export is now formula-injection-safe** — cells starting with `=`, `+`, `-`, or `@` are
  neutralized so opening the export in Excel/Sheets can't trigger an unexpected formula.
- **Database backup & restore** — Admin → Settings → Backup & Restore. A safety copy of the
  current database is kept automatically before any restore.
- **Student "Flag for Review"** — students can flag a subject's prediction as looking wrong;
  admins see and resolve these at Admin → Grade Flags.
- **Admins and teachers can add students directly** — Admin → Students has an "Add Student"
  form (name, email, temp password, program/major/year/section) right next to the student
  list. Teachers get the same on their My Students page, scoped to their own class — entering
  an email that's already a student just enrolls that student into the class; a new email
  creates a brand-new student account and enrolls them in one step. Either way, a
  teacher/admin-created account is forced to set its own password on first login.
- **A deliberately "messy" demo student** (Kevin Bautista, BSIT 2A) is seeded alongside the
  happy-path demo student — low/inconsistent grades, no payment on record (so you can see the
  enrollment hold), and an open grade flag — useful for demoing edge cases without having to
  manufacture them live.
- **Full Notifications page** — the topbar bell links to a paginated "View All Notifications"
  page (`notifications.php`), available to every role.
- Removed the non-functional "Continue with Google/Microsoft" buttons; Terms of Service /
  Privacy Policy links now go to real pages (`terms.php`, `privacy.php`).

## Security

- **CSRF protection** — every form includes a per-session token, verified on every POST.
- **All destructive actions are POST-only** — no delete can be triggered by just visiting a URL.
- **Session security** — session ID is regenerated on every login; cookies are HttpOnly (and
  Secure automatically when served over HTTPS).
- **Login throttling** — 5 failed attempts locks an account for 10 minutes.
- **"Remember me"** — persistent login via a hashed selector/validator token (not a raw session
  ID) stored in `remember_tokens`, expires after 30 days, and is revoked on password change/logout.
- **Password reset** — real token-based flow (30-minute expiry, one-time use). Since this
  environment has no mail server configured, the reset link is shown directly on screen instead
  of emailed — swap in `mail()`/SMTP in `forgot-password.php` for production.
- **Public registration** can no longer create Administrator accounts — only existing admins can
  (via Admin → Users → Create User).
- Passwords are hashed with `password_hash()` (bcrypt), all SQL uses parameterized queries, and
  all output is escaped with `htmlspecialchars()`.
- The `data/` and `uploads/avatars/` folders are locked down via `.htaccess` (script execution
  disabled, direct listing denied).

## Roles

The app now has four real roles, each with its own portal:

- **Student** — views predictions, updates their own Attendance/Assignments/Quizzes/Participation
  (Past Grades is locked — only a teacher or admin can set it).
- **Teacher** — sees only the subject+section combinations an admin assigned them, and can edit
  grades for students in those classes only.
- **Parent** — read-only view of their linked child(ren)'s predictions. Linked either during
  registration (by student number/email) or by an admin.
- **Admin** — full access: manage students, teachers, subjects, prediction weights, class
  sections, users, reports, and system settings.

## Smarter predictions

- **Per-subject weights** — Admin → Subjects → Weights lets you customize the 40/25/15/10/10
  split per subject (must total 100%).
- **Trend indicator** — each subject prediction shows Improving / Stable / Declining based on
  its last few snapshots. This is informational only and never changes the grade itself, so the
  formula stays transparent and auditable.
- **Confidence signal** — Low/Medium/High, based on how many times data has actually been
  recorded for that student/subject, so a single data point doesn't look as certain as a
  term's worth of updates.
- **Full audit trail** — every factor-score change is logged to `grade_input_history` with who
  changed it and when.

## Other additions

- **Sortable & filterable tables** — Admin Students (sort by name/average, filter by risk),
  Admin Predictions (sort by student/subject/grade).
- **Real notifications** — a notification bell with an unread badge and dropdown, backed by a
  `notifications` table (students get notified when a teacher/admin updates their grades).
- **Interactive charts** — the trend charts on both dashboards now use Chart.js with hover
  tooltips.
- **Class sections** — Admin → Sections lets you group students and assign teachers/subjects
  per section.
- **Profile photo upload** — JPG/PNG/WEBP, validated server-side by actual file content (not
  just the extension), under 2MB.
- **Dark mode** — a toggle in Settings, persisted per-device.
- **Printable report → PDF** — Admin Reports has a "Print / PDF" button that opens a print-
  formatted page; use your browser's "Save as PDF" print destination.

## College Structure (Programs, Majors, Curriculum, Semesters)

This is now built around a real college structure rather than a flat K-12 subject list:

- **Programs**: BSIT, BSTM, BSBA, BSED, Caregiving (NC II), and BSM (seeded as *Bachelor of Science in
  Midwifery* — rename it on Admin → Programs if you meant something else, e.g. Marine Transportation).
- **Majors**: BSED has four seeded majors (Elementary, Math, Filipino, English). Any program can have majors via Admin → Programs.
- **Curriculum**: Admin → Curriculum assigns subjects (with units) to a specific Program + Major +
  Year Level + Semester. Students can only enroll in what's prescribed for *their* program/major/
  year/semester (Admin → Curriculum must be filled in for each combination you want students to enroll into).
- **Sections**: now tied to a Program + Year Level (e.g. "BSIT 2A"), not just a generic label.
- **Semester**: the active semester is a system setting (`current_semester`, defaults to "1st
  Semester") — change it at the start of each term via direct database edit or extend Admin →
  Settings with a dropdown (not yet wired to a UI control, to avoid a mid-semester mistake reflecting instantly across all students).
- **GPA**: student dashboards now also show a PH-style 1.00 (highest) – 5.00 (failing) GPA
  equivalent, computed from the same predicted percentage — lower is better, matching standard
  Philippine college transcripts.

## Registrar, Cashier, Advising & Communication modules

- **Registrar**: Admin → Programs & Majors, Admin → Curriculum, Admin → Sections, Admin → Subjects.
  Registration now asks students for their program/major/year level.
- **Cashier**: Admin → Fee Structures sets tuition per program/year/semester. Payments are recorded
  on a student's detail page (Admin → Students → [pick a student]), which also shows their running
  balance. **Enrollment hold**: if a student has an unpaid balance for the current semester, they
  cannot add new subjects (existing enrollment and grades stay fully visible). Students see their
  own balance and payment history at Student → My Finance.
- **Advising**: every student can have an assigned adviser (set via Admin → Users, or automatically
  when enrolled under a teacher-advised section). Admins/advisers can log advising notes on a
  student's detail page; students see who their adviser is (not the private notes) on their Profile page.
- **Announcements**: Admin → Announcements posts updates to the whole school, a specific program,
  or a specific section — shown as a banner on every dashboard (student/teacher/parent/admin).

## What's intentionally NOT included in this pass

Given the scope of "a full college system," the following were deliberately left out — they're
each a substantial project on their own, and bolting them on hastily would risk breaking what
already works reliably:

- **Class scheduling** with room/time-slot conflict detection
- **LMS features**: course materials/modules, real file-upload assignments with rubrics, functional quizzes
- **Direct messaging** between roles (announcements are one-way; no inbox/DM system)
- **OJT/Practicum tracking** (hours, site, supervisor evaluation)
- **Admissions module** (online application, entrance exams, decision tracking)
- **TOR/COR/document request generation and tracking**
- **Prospectus visual checklist** (the curriculum data exists; a "completed vs. remaining" visual view does not yet)
- **Licensure exam readiness index**, dropout-risk ML beyond the existing weighted formula
- **Multi-campus support, REST API, PWA/push notifications, alumni tracking**

If you want to keep building this out, I'd suggest Registrar document generation (TOR/COR) and
the Prospectus checklist next, since the underlying curriculum data is already in place for both.

## Installation

1. Make sure MySQL is running (start it from the XAMPP Control Panel, or your equivalent).
2. Upload/copy the entire `gradepredict` folder to your PHP server (or place it inside
   `htdocs`/`www` for XAMPP/WAMP/MAMP).
3. If your MySQL username/password differ from the XAMPP defaults (`root` / no password),
   edit the `DB_*` constants near the top of `config.php`.
4. Visit `index.php` in your browser (e.g. `http://localhost/gradepredict/`).
5. That's it — the `gradepredict_ai` database, all tables, and demo accounts are created
   automatically on first load. Make sure the `data/` folder is writable by the web server
   (`chmod -R 775 data` on Linux/Mac if needed) — it's used for error logs and backups.

## Demo accounts

The seeded demo accounts (one admin, one teacher, one parent and six students, including a
deliberately "messy" student for edge cases) are no longer shown on the login page. Their
emails and passwords are kept in a separate `ACCOUNTS.txt` that is not part of this project
folder. Change those passwords before using the system with real data.

## Account approval

New sign-ups from the Register page start as **pending** and cannot log in until they are approved.

- **Who approves:** admins can approve everyone. Teachers can approve student and parent sign-ups
  by default. Admin → Users has an "Allow / Remove" button to give anyone else that permission or
  take it away from a teacher. Teacher sign-ups always need an admin.
- **Where:** the **Approvals** link in the sidebar (shows a count of people waiting). Approvers also
  get a notification when someone signs up.
- **What the person sees:** logging in with a pending account says it is waiting for approval; a
  rejected one is told to contact the school. A rejected sign-up can still be approved later.
- **Existing databases** are upgraded automatically on the next page load: everyone who already
  has an account stays approved, and existing teachers get permission to approve.
- Accounts created by an admin (Admin → Users) are approved immediately.

## How predictions work

Each subject's predicted grade is a weighted average of five factors you (or an
admin) enter on the **Data Inputs** page:

- Past Grades — 40%
- Attendance — 25%
- Assignments — 15%
- Quizzes — 10%
- Participation — 10%

The weighted score becomes the subject's "Predicted Grade" and is mapped to a status
(Excellent / Very Good / Good / Fair / Poor) and a letter grade. A student's Overall
Predicted Average is the mean of all their subjects' predicted grades. Every time a
prediction is recalculated, it is stored in the `predictions` table, which powers the
Grade History pages and the "Predicted Grade Over Time" charts.

## Folder structure

```
gradepredict/
├── index.php              # Public landing page
├── login.php               # Login
├── register.php             # Account creation
├── logout.php
├── config.php               # DB bootstrap + auto schema/seed
├── includes/
│   ├── functions.php         # Prediction logic, auth guards, helpers
│   ├── sidebar_student.php
│   ├── sidebar_admin.php
│   └── topbar.php
├── student/                 # Student portal (dashboard, subjects, predictions,
│                             # grade history, data inputs, profile, settings)
├── admin/                   # Admin portal (dashboard, students, subjects,
│                             # teachers, data inputs, grade history, import,
│                             # reports, users, activity logs, settings)
├── assets/
│   ├── css/style.css
│   └── js/app.js
└── data/                    # Error logs + database backups live here (protected by .htaccess)
```

## Notes on the CSV import (Admin → Import Data)

The CSV needs a header row with these exact columns:

```
student_email,subject,past_grades,attendance,assignments,quizzes,participation
juan@student.gradepredict.ai,Mathematics,90,95,85,82,88
```

The student email must belong to an existing student account and the subject name
must match an existing subject exactly (case-insensitive header matching, exact
value matching on the row data).

## Security notes

- Passwords are hashed with PHP's `password_hash()` (bcrypt).
- All SQL uses parameterized queries (PDO prepared statements) — no raw string
  concatenation of user input into SQL.
- All user-supplied output is escaped with `htmlspecialchars()` before being
  printed into HTML.
- The `data/` folder (error logs, backups) is blocked from direct web access via
  `.htaccess`. If you deploy on Nginx or another server, add an equivalent rule
  to deny access to `/data/`.
- Your MySQL credentials live in plain text in `config.php`. Make sure this file
  is never served as plain text by your web server (it isn't, by default, since
  Apache/Nginx execute `.php` files rather than serving their source) and keep
  file permissions reasonable on shared hosting.

If you ever want to reset all data, drop the `gradepredict_ai` database (or all its
tables) via phpMyAdmin and reload the site — it will be recreated from scratch with
fresh demo data.

## Account generator

Admins can create accounts in bulk from Admin > Account generator. Paste one name per line ("Juan Dela Cruz" or "Dela Cruz, Juan"), choose a role, email style and domain, preview the generated addresses, then create. Each account gets a random temporary password and must choose a new one at first sign-in. Passwords are shown once on the results page (CSV download, copy, print). No email is sent. A single-account form is on the same page, and Users has a Generate button for the temporary password field.

## Latest changes

- **Profile and Settings** are now one shared screen (`includes/account_page.php`) for students, teachers and parents: profile information with photo change and inline edit, notification switches that save immediately, password change, language, theme, and a side column with an overview, quick actions and a help card.
- **Dark mode is limited to signed-in screens.** Login, register, the landing page, password pages, terms and privacy always stay light.
- **Program length.** Each program has a length in months (6 to 48) and can be edited on Admin > Programs. Year level choices follow it.
- **Model accuracy** (Admin > Model Accuracy). Record final grades per subject and compare them with the last prediction: average error, within 5 points, grade-band agreement and at-risk detection.
- **Documentation for the defense** is in the `docs` folder: system overview, user manual, test cases, anticipated questions and a demo script.
- **Grading sheets and honors** (Admin > Grading Sheets). Filter by school year, program, year level, semester and subject. Consolidated sheet with GWA, grading sheet per subject, honors outlook (Qualified or Not qualified with reasons), Excel workbook, CSV and print. Honor limits are editable. Students see their projected GWA on their profile.
