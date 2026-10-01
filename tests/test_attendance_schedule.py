"""API checks for the paper-sheet attendance + service/break schedule rework (run against a test database, never production)."""
import datetime, json, sys, urllib.request, urllib.error, uuid

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8080/api"
results = []


def call(method, path, body=None, token=None, form=None):
    headers = {}
    data = None
    if form is not None:
        boundary = uuid.uuid4().hex
        parts = []
        for k, v in form.items():
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n')
        parts.append(f"--{boundary}--\r\n")
        data = "".join(parts).encode()
        headers["Content-Type"] = f"multipart/form-data; boundary={boundary}"
    elif body is not None:
        data = json.dumps(body).encode()
        headers["Content-Type"] = "application/json"
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=20) as r:
            return r.status, json.loads(r.read() or b"null")
    except urllib.error.HTTPError as e:
        raw = e.read()
        try:
            return e.code, json.loads(raw)
        except Exception:
            return e.code, raw.decode(errors="replace")


def check(name, cond, extra=""):
    results.append(bool(cond))
    print(("PASS " if cond else "FAIL ") + name + (("  -> " + str(extra)[:220]) if not cond else ""))


_, e = call("POST", "/auth/login", {"email": "employee1@novahr.local", "password": "Employee@123"}); E = e["token"]; EID = e["user"]["id"]
_, a = call("POST", "/auth/login", {"email": "admin@novahr.local", "password": "Admin@123"}); A = a["token"]

# --- clock-in is gone
for m, pth in (("POST", "/attendance/clock-in"), ("POST", "/attendance/clock-out"), ("GET", "/attendance/today"),
               ("POST", "/attendance/mark-late"), ("GET", "/attendance/policy")):
    s, d = call(m, pth, {} if m == "POST" else None, token=E)
    check(f"{m} {pth} removed (404)", s == 404, (s, d))

# --- schedule shown under the clock, set by the admin
s, d = call("GET", "/attendance/schedule", token=E)
check("employee reads own service/break schedule", s == 200 and all(k in d for k in ("work_start_time", "work_end_time", "break_start_time", "break_end_time", "server_time")), d)
s, d = call("PUT", f"/employees/{EID}/schedule", {"work_start_time": "07:30", "work_end_time": "16:30", "break_start_time": "12:30", "break_end_time": "13:15"}, token=A)
check("admin sets service 07:30-16:30, break 12:30-13:15", s == 200 and d["user"]["break_end_time"] == "13:15:00", d)
s, d = call("GET", "/attendance/schedule", token=E)
check("employee sees the new schedule", s == 200 and d["work_start_time"] == "07:30:00" and d["break_start_time"] == "12:30:00", d)
s, d = call("PUT", f"/employees/{EID}/schedule", {"work_start_time": "08:00", "work_end_time": "17:00", "break_start_time": "18:00", "break_end_time": "19:00"}, token=A)
check("break outside service hours rejected (422)", s == 422, d)
s, d = call("PUT", f"/employees/{EID}/schedule", {"work_start_time": "8h", "work_end_time": "17:00", "break_start_time": "12:00", "break_end_time": "13:00"}, token=A)
check("bad time format rejected (422)", s == 422, d)
s, d = call("PUT", f"/employees/{EID}/schedule", {"work_start_time": "08:00", "work_end_time": "17:00", "break_start_time": "12:00", "break_end_time": "13:00"}, token=E)
check("employee cannot change the schedule (403)", s == 403, d)
s, d = call("POST", "/employees", {"name": "Schedule Test", "email": f"sched{uuid.uuid4().hex[:6]}@novahr.local", "password": "Employee@123"}, token=A)
check("new employee gets default 08:00-17:00, break 12:00-13:00", s == 201 and d["user"]["work_start_time"] == "08:00:00" and d["user"]["break_start_time"] == "12:00:00", d)

# --- admin records exceptions from the paper sheet
today = datetime.date.today()


def past_weekdays(ref):
    """Weekdays strictly before `ref`'s day... in `ref`'s month (ref itself included when it is not today)."""
    start = 0 if ref != today else 1
    return [ref - datetime.timedelta(days=k) for k in range(start, 25)
            if (ref - datetime.timedelta(days=k)).weekday() < 5 and (ref - datetime.timedelta(days=k)).month == ref.month]


# early in a month there may be fewer than 4 past weekdays: then test on the previous month
ref = today if len(past_weekdays(today)) >= 4 else today.replace(day=1) - datetime.timedelta(days=1)
weekdays = past_weekdays(ref)
d_late, d_abs, d_self, d_present = [str(x) for x in weekdays[:4]]
s, d = call("POST", "/attendance/record", {"employee_id": EID, "date": d_late, "status": "late", "arrival_time": "08:40"}, token=A)
check("admin records a late arrival with its time", s == 201 and d["entry"]["status"] == "late" and d["entry"]["clock_in"].endswith("08:40:00"), d)
s, d = call("POST", "/attendance/record", {"employee_id": EID, "date": d_abs, "status": "absent"}, token=A)
check("admin records an absence", s == 201 and d["entry"]["status"] == "absent", d)
s, d = call("POST", "/attendance/record", {"employee_id": EID, "date": str(today + datetime.timedelta(days=2)), "status": "absent"}, token=A)
check("future date refused (422)", s == 422, d)
s, d = call("POST", "/attendance/record", {"employee_id": EID, "date": d_late, "status": "late"}, token=E)
check("employee cannot record attendance (403)", s == 403, d)
s, d = call("POST", "/attendance/record", {"employee_id": EID, "date": d_present, "status": "late"}, token=A)
s, d = call("POST", "/attendance/record", {"employee_id": EID, "date": d_present, "status": "present"}, token=A)
check("recording the same day again corrects it", s == 201 and d["entry"]["status"] == "present", d)

s, d = call("GET", "/attendance/history", token=E)
days = {h["work_date"]: h for h in d["history"]}
check("employee sees the recorded late and absent days", d_late in days and d_abs in days, d)

# --- justification
s, d = call("POST", "/attendance/justify", token=E, form={"date": d_late, "type": "late", "reason": "Taxi broke down"})
check("employee justifies the recorded late day", s == 200 and d["entry"]["justification_status"] == "pending", d)
s, d = call("POST", "/attendance/justify", token=E, form={"date": d_abs, "type": "absent", "reason": "Sick, doctor's note"})
check("employee justifies the recorded absence", s == 200 and d["entry"]["status"] == "absent", d)
s, d = call("POST", "/attendance/justify", token=E, form={"date": d_self, "type": "late", "reason": "Road blocked"})
check("employee reports an unrecorded day as a late arrival", s == 200 and d["entry"]["status"] == "late" and d["entry"]["justification_status"] == "pending", d)
s, d = call("POST", "/attendance/justify", token=E, form={"date": d_present, "type": "late", "reason": "x"})
check("a day recorded present cannot be justified (422)", s == 422, d)
abs_id = days[d_abs]["id"]
s, d = call("PATCH", f"/attendance/{abs_id}/justification", {"decision": "approved"}, token=A)
check("admin approves the absence", s == 200 and d["entry"]["justification_status"] == "approved", d)

s, d = call("GET", "/admin/late-today", token=A)
check("admin dashboard lists justifications to review", s == 200 and len(d["pending_justifications"]) >= 2, d)
s, d = call("GET", "/admin/dashboard", token=A)
check("dashboard: present today + justifications to review", s == 200 and "justifications_pending" in d and d["present_today"] >= 1, d)

# --- payroll: no record = present; late/absent deducted unless approved
period = ref.strftime("%Y-%m")
s, d = call("POST", "/payroll/generate", {"period": period}, token=A)
row = next(p for p in d["payroll"] if p["employee_id"] == EID)
worked = [x for x in (datetime.date(ref.year, ref.month, 1) + datetime.timedelta(days=k) for k in range(31))
          if x.month == ref.month and x.weekday() < 5 and x <= today]
exp_present = len(worked) - 1           # the approved absence is not a present day
check("payroll: unrecorded days count as present", row["days_present"] == exp_present, (row, exp_present))
check("payroll: 2 unexcused late days, 0 absences (absence excused)", row["late_count"] == 2 and row["absent_count"] == 0, row)
check("payroll: deductions = 2 x 3000 FCFA", float(row["deductions"]) == 6000.0, row)

# --- AI assistant knows the new process
s, d = call("POST", "/ai/ask", {"question": "How do I justify an absence?"}, token=E)
check("AI assistant answers with the justification topic", s == 200 and "sheet" in d["answer"].lower(), d)

print(f"\n{sum(results)}/{len(results)} checks passed")
