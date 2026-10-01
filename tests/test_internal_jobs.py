import json, sys, urllib.request, urllib.error

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8080/api"
results = []

def call(method, path, body=None, token=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(BASE + path, data=data, method=method)
    req.add_header("Content-Type", "application/json")
    if token:
        req.add_header("Authorization", "Bearer " + token)
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
    results.append((name, bool(cond)))
    print(("PASS " if cond else "FAIL ") + name + (("  -> " + str(extra)[:200]) if not cond and extra else ""))

# --- employee and admin sessions
s, emp = call("POST", "/auth/login", {"email": "employee1@novahr.local", "password": "Employee@123"})
check("employee login", s == 200 and emp["user"]["role"] == "employee", emp)
E = emp["token"]
s, adm = call("POST", "/auth/login", {"email": "admin@novahr.local", "password": "Admin@123"})
check("admin login", s == 200, adm)
A = adm["token"]

s, d = call("GET", "/jobs", token=E)
check("employee sees internal job openings", s == 200 and len(d["jobs"]) >= 1, d)
job_id = d["jobs"][0]["id"]
s, d = call("GET", "/jobs")
check("job openings are NOT public (no token -> 401)", s == 401, d)
s, d = call("POST", "/candidates/apply", {"job_posting_id": job_id}, token=E)
check("employee session cannot apply directly (403)", s == 403, d)

# --- candidate session with the SAME credentials
s, cand = call("POST", "/auth/candidate-login", {"email": "employee1@novahr.local", "password": "Employee@123"})
check("candidate login with employee credentials", s == 200 and "token" in cand, cand)
C = cand["token"]
s, d = call("POST", "/auth/candidate-login", {"email": "employee1@novahr.local", "password": "wrong"})
check("candidate login rejects wrong password (401)", s == 401, d)
s, d = call("POST", "/auth/candidate-login", {"email": "admin@novahr.local", "password": "Admin@123"})
check("admin cannot sign in as candidate (403)", s == 403, d)
s, d = call("POST", "/auth/candidate-login", {"email": "candidate@example.com", "password": "Candidate@123"})
check("old external candidate account no longer exists (401)", s == 401, d)
s, d = call("GET", "/attendance/history", token=C)
check("candidate session cannot use employee features (403)", s == 403, d)

s, d = call("POST", "/candidates/apply", {"job_posting_id": job_id, "motivation": "I want to grow in the team."}, token=C)
check("candidate applies", s == 201, d)
app_id = d["application"]["id"]
s, d = call("POST", "/candidates/apply", {"job_posting_id": job_id}, token=C)
check("duplicate application rejected (409)", s == 409, d)
s, d = call("GET", "/jobs", token=C)
check("jobs list marks my application", s == 200 and any(j["id"] == job_id and j.get("my_application_status") == "applied" for j in d["jobs"]), d)

# --- another employee must not touch this application
s, other = call("POST", "/auth/candidate-login", {"email": "employee2@novahr.local", "password": "Employee@123"})
C2 = other["token"]
s, d = call("POST", "/interview/start", {"application_id": app_id}, token=C2)
check("another candidate cannot open my interview (404)", s == 404, d)

# --- AI interview
s, d = call("POST", "/interview/start", {"application_id": app_id}, token=C)
check("interview starts", s == 200 and d["questions"], d)
sid, qs = d["session"]["id"], d["questions"]
for q in qs:
    s, _ = call("POST", f"/interview/{sid}/answer", {"question": q["question_text"],
               "answer": "I use html css javascript fetch api json async console network debug devtools on real projects.",
               "expected_keywords": q.get("expected_keywords")}, token=C)
check("all answers accepted", s == 200)
s, d = call("POST", f"/interview/{sid}/complete", {}, token=C)
check("interview scored", s == 200 and d["session"]["status"] == "completed", d)
s, d = call("POST", f"/interview/{sid}/answer", {"question": "x", "answer": "again"}, token=C)
check("one attempt only: re-answer after completion rejected (409)", s == 409, d)

s, d = call("GET", "/candidates/me", token=C)
check("my applications show status + interview", s == 200 and d["applications"][0]["interview"]["status"] == "completed", d)
status_after_interview = d["applications"][0]["application"]["application_status"]

# --- admin review -> notification
s, d = call("GET", f"/candidates?job_posting_id={job_id}", token=A)
row = next((c for c in d.get("candidates", []) if c["id"] == app_id), None)
check("admin sees the employee as applicant (name/department)", s == 200 and row and row["name"] == "Fotso Alain" and row.get("department_name"), d)
s, d = call("GET", f"/interview/job/{job_id}", token=A)
check("admin sees interview score with employee name", s == 200 and any(x["candidate_name"] == "Fotso Alain" for x in d["sessions"]), d)
s, d = call("PATCH", f"/candidates/{app_id}/status", {"status": "hired"}, token=A)
check("admin marks hired", s == 200 and d["candidate"]["application_status"] == "hired", d)

s, d = call("GET", "/notifications", token=C)
titles = [n["title"] for n in d.get("notifications", [])]
check("candidate notified (received + interview + decision)", s == 200 and len(titles) >= 3 and d["unread"] >= 3, titles)
s, d2 = call("GET", "/notifications", token=E)
check("same notifications visible from the employee account", s == 200 and len(d2["notifications"]) == len(d["notifications"]), d2)
s, _ = call("POST", "/notifications/read", {}, token=C)
s, d = call("GET", "/notifications", token=C)
check("mark all read", s == 200 and d["unread"] == 0, d)
s, d = call("GET", "/notifications", token=A)
check("admin has no candidate notifications endpoint (403)", s == 403, d)

s, d = call("GET", "/candidates/faq", token=C)
check("FAQ available in portal", s == 200 and len(d["entries"]) >= 1, d)

# --- employee account still fully works
s, d = call("GET", "/attendance/schedule", token=E)
check("employee features unaffected", s == 200, d)

print(f"\nstatus after interview: {status_after_interview}")
passed = sum(1 for _, ok in results if ok)
print(f"{passed}/{len(results)} checks passed")
