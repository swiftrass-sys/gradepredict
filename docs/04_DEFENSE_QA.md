# Anticipated Panel Questions

Answers are written to be accurate for this system as built. Where the honest answer is a limitation, it is stated plainly.

**Is this really AI?**
The prediction is a transparent weighted model. Five measured factors are combined with fixed weights into one grade. It is not a trained machine-learning model. We chose this because a teacher or parent can see exactly why a number came out the way it did, and because a school this size does not have enough labelled history to train a reliable model. The Model Accuracy page records final grades and compares them with predictions, which is the data that would be needed to train or tune a model later.

**Where did the weights come from and why those?**
Past grades carry the most (40%) because earlier performance is the strongest single indicator of later performance. Attendance is next (25%), then assignments (15%), quizzes (10%) and participation (10%). They are defaults, not claims of truth. Each subject can have its own weights, and the accuracy page shows whether the defaults are leaning high or low so they can be adjusted with evidence.

**How accurate is it?**
Accuracy is measured by recording final grades and comparing them with the last prediction: average error in points, share of predictions within 5 points, share landing in the same grade band, and how many students who finished below 70 were flagged beforehand. Report the figures the system shows for real final grades. Do not quote the numbers from the demo data; they are sample values.

**What is the benefit of predicting if the formula is simple?**
The value is early, consistent, visible warning. The system recomputes whenever a score changes, shows trend and confidence, and highlights students below 70 so action can happen mid-term instead of after grades are final.

**What if the input data is wrong or a student changes their own scores?**
Past grades are locked to teachers and admins. Every change to any score is recorded with who changed it and when (Grade History). A student can flag a prediction for review and an admin resolves it. Confidence stays Low until data has been recorded several times.

**Why can a program be 6 months and another 4 years?**
The school offers short courses (for example Caregiving) and degrees. Program length is stored in months, between 6 and 48, and editable by the admin. Year level choices follow it, and if a length is shortened, students beyond the last year are moved back to it.

**Who can create accounts, and how is access controlled?**
Public sign-ups are pending until an administrator, or a teacher or delegated person (for student and parent sign-ups), approves them. Teacher sign-ups need an administrator. Administrators can create accounts directly or generate many at once; those accounts get a random temporary password and must change it at first sign-in. Every page checks the role, and approvals are re-checked inside the action.

**How are passwords handled?**
They are hashed with bcrypt through PHP's password_hash and cannot be read back, including temporary ones. That is why the generator shows them once. Reset uses a one-time token that expires in 30 minutes.

**Why no email sending?**
The system runs on a local XAMPP server with no mail service configured. Reset links and generated passwords are shown on screen. The email notification switch stores the preference and is ready to be connected to SMTP.

**What stops SQL injection, XSS and CSRF?**
All queries are parameterized, all output is escaped, every form carries a per-session token that is verified on each POST, and a content security policy is sent with every response.

**Why is dark mode not on the login page?**
It is a preference for the working screens. Public pages are kept in the school's light branding.

**What happens if two people register with the same email, or an admin generates a duplicate?**
Email is unique in the database. The generator numbers duplicates (juan.delacruz2) and checks existing accounts and the same batch. A batch is created in one transaction, so it either fully succeeds or nothing is created.

**What are the limits of the system?**
No trained model, no email delivery, English only, no scheduling, LMS, admissions or document generation. See the overview for the full list.

**What would you do next?**
Collect a full term of final grades, use the accuracy page to tune the weights per subject, then evaluate a regression model on the same data and compare. Connect SMTP for notifications. Add document generation (TOR, COR).

**How is the honor designation predicted?**
For each student the system takes the final grade where one is recorded and the latest predicted grade otherwise, converts each to the 1.00 to 5.00 equivalent, and computes the GWA weighted by units. The GWA is compared with the limits (Summa up to 1.20, Magna up to 1.45, Cum Laude up to 1.75). A student is Not qualified if any subject is failing, any grade is worse than 2.50, the GWA is above the Cum Laude limit, or any enrolled subject has no grade or prediction yet. Each reason is listed. These limits are common defaults and can be changed by the registrar. It is a projection, so it changes as scores or final grades change.

**Why is a student with a good GWA marked Not qualified?**
Honors rules usually have disqualifiers besides the average. The reason column says which one applies, for example one grade worse than 2.50 or a subject without a grade yet.

**Does the sheet follow the school's grading sheet?**
It follows the usual per-subject format (student number, name, components, grade, equivalent, remarks, class summary, signature lines). If the school has an official template, send it and the columns can be matched exactly.
