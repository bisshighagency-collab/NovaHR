# NovaHR API test suites

Automated checks of the REST API. They create and change data (applications, interviews,
attendance records, justifications, payroll), so **run them against a test database, never the
real one**.

| Suite | Checks | What it covers |
|---|---|---|
| `test_internal_jobs.py` | 28 | internal job openings, candidate login with employee credentials, applying, AI interview (one attempt only), admin decision, notifications, access control |
| `test_attendance_schedule.py` | 29 | service & break schedule, attendance recorded from the paper sign-in sheet, justification and approval, payroll deductions, AI assistant answer |

## Run

1. Start a fresh NovaHR instance on a separate database (the demo accounts from `database/seed.sql`
   must exist), for example with the VS Code task **Start NovaHR** pointed at a new data folder
   (`NOVAHR_DATA_DIR`).
2. Run each suite with the API address (default `http://localhost:8080/api`):

```
python tests/test_internal_jobs.py http://localhost:8080/api
python tests/test_attendance_schedule.py http://localhost:8080/api
```

Each line prints `PASS` or `FAIL`, followed by a total such as `28/28 checks passed`.
Python 3 is enough: the suites use only the standard library.
