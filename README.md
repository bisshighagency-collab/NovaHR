# NovaHR — Employee Management System

A working implementation of the NovaHR system: employee registration, attendance
(with absence/late justification), payroll, company announcements, internal recruitment with
AI-conducted interviews, and a company-trained AI assistant. **HTML/CSS/JavaScript**
frontend, **PHP** backend REST API, **MySQL** database.

This folder is a self-contained copy of the app (no report/diagram tooling) set up to
launch straight from VS Code — including its own database, which creates and seeds
itself the first time you start it.

## Launch it (VS Code)

1. Open this folder in VS Code (`File > Open Folder…`).
2. Press **Ctrl+Shift+B** (or `Terminal > Run Task… > Start NovaHR`).
   - **First launch only:** the MySQL terminal says *"creating the NovaHR database
     files"* — this takes **1–3 minutes**. Wait for it to print
     `NovaHR database ready on 127.0.0.1:3399`; the app starts right after.
   - Later launches take a few seconds.
3. Open **http://localhost:8080** in your browser and log in with a demo account below.

`Start NovaHR` opens two terminals side by side: **MySQL (port 3399)** and
**PHP app (port 8080)**. The PHP side runs `dev-server-router.php` through PHP's
built-in server — it serves the static frontend and forwards every `/api/...` request
to the backend, so both live at the same `localhost:8080` origin (no CORS setup).

To stop: `Terminal > Terminate Task…` and pick both tasks (or press `Ctrl+C` in each
terminal). Stopping MySQL cleanly keeps your data.

## Demo accounts

| Role | Email | Password |
|---|---|---|
| Admin | `admin@novahr.local` | `Admin@123` |
| Employee | `employee1@novahr.local` | `Employee@123` |
| Employee | `employee2@novahr.local` | `Employee@123` |

### Internal job openings (Employee → Candidate)

Job openings are posted by the admin for the company's **own employees** — they are not
public. Employees see them on their dashboard and on the **Job Openings** page, but to
apply they must switch to a separate candidate session:

1. On **Job Openings**, click **Log out & apply as candidate** (or open the
   **Candidate Portal** from the login page).
2. Sign in with the **same email and password** as the employee account
   (e.g. `employee1@novahr.local` / `Employee@123`). Admin accounts cannot sign in here.
3. Apply, take the AI interview, and follow the application. A notification appears
   when the interview is scored and whenever the admin shortlists, rejects or hires.
4. To get back to attendance, payslips or the AI assistant, click
   **Back to employee account** (or log out) and sign in on the normal login page with
   the same credentials.

### Attendance: paper sign-in sheet + justification

- Employees sign the **paper attendance sheet** every day; there is no clock-in button.
- The admin records only the exceptions (late arrivals and absences) on **Attendance → Record from the
  Sign-in Sheet**. A day with nothing recorded counts as present in payroll.
- Employees justify a recorded day (or any past day themselves) on **Attendance** with a reason and optional
  proof; the admin approves or rejects it, and an approved day is not deducted.
- The employee dashboard clock only displays the time. Under it, the employee sees **Service time** or
  **Break time** from their own service hours and break, which the admin sets on the **Employees** page.

## About the database

The launcher (`scripts/start-mysql.ps1`) runs its own private MySQL server on
**port 3399** using the MySQL that ships with Laragon
(`C:\laragon\bin\mysql\mysql-8.4.3-winx64`). Its data files are stored in
`%LOCALAPPDATA%\NovaHR\mysql-data` (`C:\Users\<you>\AppData\Local\NovaHR\mysql-data`)
**on purpose, not inside this folder** — MySQL refuses to start when its data sits on
the Windows Desktop, and Desktop/OneDrive would try to sync the files anyway.

- **App updates:** when a new version adds `database/migrations/NNN_*.sql` files, the
  launcher applies them automatically the next time it starts (your data is kept).
- **Reset to a clean database:** stop both tasks, delete the
  `%LOCALAPPDATA%\NovaHR` folder, and run `Start NovaHR` again — it re-creates and
  re-seeds everything from `database/schema.sql` and `database/seed.sql`.
- **Different MySQL location:** edit `$mysqlBase` at the top of `scripts/start-mysql.ps1`.
- **Different PHP location:** edit the `command` / `extension_dir` paths in the
  `PHP app (port 8080)` task in `.vscode/tasks.json`.
- **Using your own MySQL instead:** import `database/schema.sql` then `database/seed.sql`
  into it, and change `NOVAHR_DB_HOST` / `NOVAHR_DB_PORT` / `NOVAHR_DB_USER` /
  `NOVAHR_DB_PASS` in the `env` block of the PHP task. Run only the `PHP app` task.

### Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Login says "Database connection failed" | MySQL isn't running. Run the full `Start NovaHR` task (not only the PHP one) and wait for `NovaHR database ready`. |
| "MySQL did not start … port 3399" | Another program is using port 3399, or a previous MySQL is still running — end `mysqld.exe` in Task Manager and retry. |
| Port 8080 already in use | An old PHP server is still running — end `php.exe` in Task Manager. |

## Project layout

```
backend/              PHP REST API (index.php front controller, config/, models/,
                       controllers/, middleware/)
frontend/              Static HTML/CSS/JS — index.html, login.html, register.html,
                       employee/, admin/, candidate/
database/               schema.sql (run first), seed.sql (demo data, run second)
scripts/start-mysql.ps1 Starts the private MySQL server; creates + seeds it on first run
storage/justifications/ Uploaded justification proof files (created automatically;
                         never web-accessible — served only via the API)
dev-server-router.php   Router used only by `php -S` for local dev (see .vscode/tasks.json).
                         Not needed for a real Apache/Nginx deployment.
.vscode/tasks.json      The "Start NovaHR" task (MySQL + PHP app)
```

## Deploying for real (Apache/Nginx, not `php -S`)

- Point your web server's document root at `frontend/`.
- Point a second vhost/subdomain (or a reverse-proxy rule for `/api`) at `backend/`,
  which already ships an `.htaccess` routing everything through `backend/index.php`.
- Set `window.NOVAHR_API_BASE` before `js/api.js` loads on every frontend page if the
  backend isn't reachable at the same origin under `/api`.
- Configure the database via the `NOVAHR_DB_*` environment variables, or edit
  `backend/config/database.php` directly.

## Notes

- PHP 8+ with `pdo_mysql` and `fileinfo` enabled; MySQL 5.7+/MariaDB 10.3+.
- Passwords are bcrypt-hashed; sessions are signed JWTs (`backend/config/jwt.php`,
  8-hour expiry).
- Payroll disbursement and the AI engine are simulated (no real payment gateway or
  LLM credentials) — see the project's full internship report for the details of what
  each simulates and why.
