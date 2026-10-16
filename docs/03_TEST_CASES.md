# GradePredict AI: Test Cases

Status key: Passed (automated or browser) means it was run by script against a test copy of the system (same PHP code, SQLite instead of MySQL). Run on XAMPP means it can only be checked on the real MySQL installation and must be done before the defense.

| No. | Area | Test | Expected | Status |
|---|---|---|---|---|
| 1 | Prediction | Scores 90/90/90/90/90 with default weights | 90.0, Excellent | Passed (automated, 26 checks incl. clamping, bands, custom weights) |
| 2 | Prediction | Weights that do not total 100% | Rejected with a message | Passed (browser). Saving valid weights uses MySQL-only SQL: run on XAMPP |
| 3 | Accuracy | accuracy_metrics on a known set (MAE, RMSE, bias, within 5, band match, at-risk table) | Values match hand calculation | Passed (automated, 11 checks) |
| 4 | Approval | New sign-up cannot log in | Message that it awaits approval | Passed (automated, 24 checks) |
| 5 | Approval | Teacher approves a student, cannot approve a teacher | Student approved; teacher refused | Passed (automated) |
| 6 | Approval | Admin removes a user's approval permission | That user can no longer approve | Passed (automated) |
| 7 | Generator | Name parsing (Juan Dela Cruz, "Dela Cruz, Juan", accents, Jr., ALL CAPS) | Correct given and last names | Passed (automated, 76 checks across generator) |
| 8 | Generator | Two people with the same name | juan.delacruz, juan.delacruz2 | Passed (automated) |
| 9 | Generator | Temporary password strength | At least 10 characters with upper, lower and digit | Passed (automated) |
| 10 | Generator | Batch with an error midway | Nothing created (all or nothing) | Passed (automated) |
| 11 | Generator | Replay the same Create request | Second request creates nothing | Passed (browser) |
| 12 | Program length | Edit a program to 8 months | Saved; shows "8 months" | Passed (browser) |
| 13 | Program length | Length below 6 months or above 4 years | Rejected with a message | Passed (browser) |
| 14 | Program length | 2-year program | Year level offers Year 1 to 2, crafted Year 5 saved as 2 | Passed (browser) |
| 15 | Profile and Settings | Edit name | Saved and shown | Passed (browser) |
| 16 | Profile and Settings | Email already used by another account | Rejected | Passed (browser) |
| 17 | Profile and Settings | Upload photo | Avatar updates | Passed (browser) |
| 18 | Profile and Settings | Wrong current password | Rejected | Passed (browser) |
| 19 | Profile and Settings | Turn email notifications off, reload | Stays off | Passed (browser) |
| 20 | Dark mode | Saved dark choice, open login, register, landing, forgot password, terms, privacy | All stay light | Passed (browser) |
| 21 | Dark mode | Same choice, open the dashboard | Dark | Passed (browser) |
| 22 | Header | Account with 9+ unread notifications | Header intact, bell shows 9+ | Passed (browser) |
| 23 | Page health | Every page for every role loads without PHP errors | No warnings or 500 | Passed (automated, all pages) |
| 24 | Contrast | Text contrast and bright surfaces on 40 signed-in pages, light and dark | No failures | Passed (automated) |
| 25 | Security | POST without or with a wrong CSRF token | 403, nothing changed | Passed (browser) |
| 26 | Security | Student opens admin, teacher and parent URLs, or posts a role change to an admin page | Redirected, role unchanged | Passed (browser) |
| 27 | Security | 5 wrong passwords, then the right one | Locked for 10 minutes | Passed (browser) |
| 28 | Backup | Backup then restore | Data returns | Run on XAMPP (uses SHOW CREATE TABLE). The SQL splitter itself passed |
| 29 | Upgrade | Open the new version on an existing database | New columns and the final_grades table are added, nothing lost | Run on XAMPP (ALTER TABLE on MySQL) |
| 30 | GWA | Weighted by units, rounding to 2 places | (1.00 x 3 + 2.00 x 1) / 4 = 1.25 | Passed (automated, 15 checks) |
| 31 | Honors | 1.08, 1.33 and 1.67 GWA | Summa, Magna, Cum Laude | Passed (automated) |
| 32 | Honors | Limit is inclusive | GWA exactly 1.20 is Summa Cum Laude | Passed (automated) |
| 33 | Honors | Failing grade, grade worse than the lowest allowed, GWA above 1.75, missing grades | Not qualified, each reason listed | Passed (automated) |
| 34 | Grading sheets | Filters by program, year level, semester, subject | Row counts change, empty state when nothing matches | Passed (browser) |
| 35 | Grading sheets | Excel workbook with no filters | Consolidated, Honors and one sheet per subject; opens in a spreadsheet reader | Passed (read back with openpyxl). Open once in Excel |
| 36 | Grading sheets | CSV and print view | Same data as the screen | Passed (CSV). Print: check on the school printer |
| 37 | Honors | Edit the honor rules with an invalid order | Rejected; valid values saved | Passed (browser) |
