# DevEduHub — Technical Audit Report
**Date:** 2025 | **Auditor:** Senior Engineer Review  
**Codebase scope:** 48 PHP files · 5 Python files · 2 Docker files · 4 test files

---

## 1. Project Overview

**DevEduHub** is an educational platform designed for academic institutions to manage courses, assignments, and automated code evaluation.

**Core idea:** Students submit GitHub repository links as assignment solutions. The platform auto-grades the code by cloning the repo, running it inside a Docker sandbox, executing test cases, and returning a score — all asynchronously.

**Target users:**
- Teachers: create courses, assignments, configure test cases, manually override grades
- Students: enroll in courses, view assignments, submit GitHub repos, receive grading results

**Planned phases:**
- Phase 1 — MVP: Auth, courses, assignments, submissions (✅ complete)
- Phase 2 — Auto-grading: Python grader, Docker sandbox, async queue (✅ complete)
- Phase 3 — Portfolio & platform features: student portfolios, project showcases, notifications, activity logs (❌ not started)

---

## 2. Tech Stack

| Layer | Technology | Version / Notes |
|---|---|---|
| Backend | Laravel | 10+ (inferred from `HasUuids`, Sanctum, `array_filter` patterns) |
| Auth | Laravel Sanctum | Token-based SPA auth |
| Queue | Redis + Laravel Queues | Named queues: `grading`, `notifications` |
| Database | PostgreSQL | UUID PKs via `gen_random_uuid()`, JSONB columns |
| Grader service | Python + FastAPI | v0.111.0 |
| HTTP server | Uvicorn | Standard with async support |
| Validation | Pydantic v2 | Field validators, model schemas |
| Sandboxing | Docker | Sibling-container pattern via socket mount |
| Containerisation | Docker + Docker Compose | Grader service only |
| HTTP client (Laravel) | Laravel HTTP (Guzzle) | With retry, timeout, connection timeout |
| Notifications | Laravel Notifications | Mail + Database channels |
| Testing (PHP) | PHPUnit / Laravel Feature tests | 2 test files, ~30 test cases |
| Testing (Python) | pytest | 19 tests, all passing |
| Frontend | **None yet** | No React/Vue/Blade files found |

---

## 3. Architecture

```
┌─────────────────────────────────────────────────────┐
│                   Laravel API                        │
│  Auth · Courses · Assignments · Submissions          │
│  Sanctum · RoleMiddleware · CoursePolicy             │
│              │                                       │
│   SubmissionController → GradeSubmissionJob          │
│                              │                       │
│              Redis Queue (grading)                   │
│                              │                       │
└──────────────────────────────┼──────────────────────┘
                               │ HTTP POST /grade
                               ▼
              ┌────────────────────────────────┐
              │   Python FastAPI Grader         │
              │   repo_cloner → DockerRunner    │
              │   → Tester → JSON response      │
              └────────────┬───────────────────┘
                           │ docker run (sibling)
                           ▼
              ┌────────────────────────────────┐
              │   Docker Sandbox (per submission)│
              │   --network=none               │
              │   --memory=128m --cpus=0.5     │
              │   --read-only --cap-drop=ALL   │
              └────────────────────────────────┘
```

**Pattern:** Microservices with async job queue. Laravel owns business logic and DB. Python owns execution. Communication is HTTP. No shared DB between services.

---

## 4. Backend Analysis (Laravel)

### ✅ Implemented

**Auth system**
- `AuthController`: register, login, logout, me, email verification
- `RegisterRequest` / `LoginRequest` with full validation
- `RoleMiddleware`: `->middleware('role:teacher')` pattern
- Sanctum token rotation on login (previous tokens revoked)
- `AppServiceProvider` correctly extends `AuthServiceProvider`, registers `CoursePolicy`, calls `$this->registerPolicies()`

**Models (5 core)**

| Model | Relationships | Notable |
|---|---|---|
| `User` | `taughtCourses`, `enrollments`, `enrolledCourses`, `submissions` | `isTeacher()`, `isStudent()`, `getFullNameAttribute()` |
| `Course` | `instructor`, `assignments`, `enrollments`, `students` | `hasCapacity()`, `scopeActive()` |
| `Enrollment` | `student`, `course` | Composite unique, `scopeActive()` |
| `Assignment` | `course`, `submissions` | `isPastDue()`, `isAutoGradable()`, jsonb cast |
| `Submission` | `assignment`, `student` | `applyAutoGrade()`, all status helpers, no `updated_at` (immutable) |

**Controllers**

| Controller | Methods | Notes |
|---|---|---|
| `AuthController` | register, login, logout, me, verifyEmail | Full |
| `CourseController` | index, store, show, update, destroy | Teacher-scoped index |
| `AssignmentController` | index, store, show, update, destroy | Prevents deletion if submissions exist |
| `SubmissionController` | index, store, show, update, byAssignment, retry | `retry` endpoint added in Phase 2 |
| `EnrollmentController` | index, store, destroy | Re-activate dropped enrollment |
| `NotificationController` | index, unread, markRead, markAllRead | Phase 2 addition |

**Requests (5 Form Request classes)**
- `RegisterRequest`, `LoginRequest` — auth
- `StoreCourseRequest` — semester enum: Fall/Spring/Summer
- `StoreAssignmentRequest` — accepts Phase 2 jsonb fields (test_cases, docker_config)
- `StoreSubmissionRequest` — GitHub/GitLab HTTPS regex, 40-char SHA validation

**Resources (5 API Resources)**
- All models have resources
- `AssignmentResource`: hides `test_cases`/`docker_config` from students via `mergeWhen`
- `SubmissionResource`: `manual_grade_score` teacher-only

**Policies**
- `CoursePolicy`: viewAny, view, create (teacher only), update (owner), delete (owner + no active enrollments)

**Phase 2 Queue Integration**
- `GradeSubmissionJob`: 3 tries, backoff 60/120/180s, `failed()` hook
- `GraderApiService`: HTTP client with retry, response shape validation, score range check
- `GradingCompletedNotification`: mail + database channels
- `GradingFailedNotification`: mail + database channels
- `config/grader.php`: `GRADER_URL`, `GRADER_TIMEOUT`, `GRADER_CONNECT_TIMEOUT`

**Migrations (7 tables)**

| Table | Key columns |
|---|---|
| `users` | 19 fields, `github_token_encrypted` nullable |
| `courses` | `instructor_id` FK, academic_year, semester, credits |
| `enrollments` | unique[student_id, course_id], final_grade nullable |
| `assignments` | `test_cases: jsonb`, `docker_config: jsonb` (Phase 2 hooks, nullable) |
| `submissions` | Full scoring fields nullable, retry_count, is_late auto-computed |
| `notifications` | UUID PK, morphs, data JSON, read_at |
| `failed_jobs` | uuid unique, payload, exception |

**Routes (Phase 1 + 2 combined)**

```
POST   /api/auth/register
POST   /api/auth/login
GET    /api/auth/verify/{token}
POST   /api/auth/logout               [auth]
GET    /api/auth/me                   [auth]
GET    /api/courses                   [auth]
POST   /api/courses                   [auth]
GET    /api/courses/{course}          [auth]
PUT    /api/courses/{course}          [auth]
DELETE /api/courses/{course}          [auth]
GET    /api/courses/{course}/assignments [auth]
POST   /api/assignments               [auth]
GET    /api/assignments/{assignment}  [auth]
PUT    /api/assignments/{assignment}  [auth]
DELETE /api/assignments/{assignment}  [auth]
GET    /api/assignments/{id}/submissions [auth]
GET    /api/submissions               [auth]
POST   /api/submissions               [auth]
GET    /api/submissions/{id}          [auth]
PATCH  /api/submissions/{id}          [auth]
POST   /api/submissions/{id}/retry    [auth]  ← Phase 2
GET    /api/enrollments               [auth]
POST   /api/enrollments               [auth]
DELETE /api/enrollments/{id}          [auth]
GET    /api/notifications             [auth]  ← Phase 2
GET    /api/notifications/unread      [auth]  ← Phase 2
PATCH  /api/notifications/read-all    [auth]  ← Phase 2
PATCH  /api/notifications/{id}/read   [auth]  ← Phase 2
```

### ❌ Missing (Laravel)

- **No Model Factories** — tests cannot use `User::factory()` without them
- **No Seeders** — no demo data for development
- **No `AssignmentPolicy`** — authorization checks are inline in controller (`abort_unless`) rather than policy classes
- **No rate limiting** on `/grade` dispatch or submission store
- **No `api.php` versioning** — no `/api/v1/` prefix
- **`password` field naming** — using `password_hash` requires overriding `getAuthPassword()` or `$authPasswordName`; not confirmed in `User` model's Authenticatable contract
- **`github_webhook` endpoint** — no incoming webhook receiver for GitHub push events (Phase 3)
- **No `Course` enrollment count cache** — `withCount` on every request, no caching

---

## 5. Frontend Analysis

**Status: Not started.**

No `.jsx`, `.tsx`, `.vue`, `.html` (application), or any frontend framework files exist in the codebase. The project is API-only at this stage.

The ERD diagrams reviewed earlier reference a full UI with course listings, assignment submission forms, and student dashboards — none of this has been scaffolded.

---

## 6. Database Analysis (PostgreSQL)

### Schema quality

**Strengths:**
- All PKs are UUIDs via `gen_random_uuid()` — no sequential ID enumeration
- Proper FK constraints with `cascadeOnDelete()` on `course_id`, `assignment_id`
- JSONB for `test_cases` and `docker_config` — flexible for Phase 2 schema evolution
- `is_late` computed at insert time — not a stored calculation that can drift
- Composite unique on `enrollments(student_id, course_id)` — DB-level guarantee
- `submissions` has no `updated_at` (intentional — submissions treated as immutable)

**Weaknesses / gaps:**
- No indexes declared beyond PKs and FKs — `submissions.submission_status`, `submissions.student_id`, `courses.is_active` will need indexes at scale
- No `CHECK` constraints on `role` column in users — relies on app-level validation only
- `github_commit_sha` is `varchar(40)` but accepts nulls — Phase 2 grader should enforce presence when `submission_type = 'github'`
- No soft deletes on any model — hard deletes with cascade could lose audit trail
- Phase 3 tables (portfolios, projects, github_webhooks, activity_logs, system_settings, course_resources, deployment_configs) not yet migrated

### Relationship map

```
users ──< courses (instructor_id)
users ──< enrollments
courses ──< enrollments
courses ──< assignments
assignments ──< submissions
users ──< submissions (student_id)
users ──< notifications (morphs)
```

---

## 7. DevOps / Environment

**Docker (Grader service):**
- `Dockerfile` for Python grader: installs git + Docker CLI, non-root user (UID 1000)
- `docker-compose.yml`: mounts `/var/run/docker.sock` for sibling-container pattern
- Health check configured (`/health` endpoint, 30s interval)

**Docker (Laravel):** No `Dockerfile` or `docker-compose.yml` for Laravel itself. Local dev relies on native PHP + PostgreSQL + Redis setup.

**Environment:**
- `.env.example` covers all required vars: `QUEUE_CONNECTION`, `REDIS_*`, `GRADER_*`, `MAIL_*`
- `pgcrypto` extension bootstrapped in `AppServiceProvider` for UUID generation

**Missing DevOps:**
- No Laravel `Dockerfile` or `docker-compose.yml` for the main backend
- No CI/CD pipeline (GitHub Actions, etc.)
- No Nginx/Caddy config for production
- No environment-specific configs (staging vs production)
- No log aggregation setup (Papertrail, Logtail, etc.)
- No health check endpoint on Laravel side

---

## 8. Progress Evaluation

| Component | Status | Completeness |
|---|---|---|
| Laravel core (models, migrations) | ✅ Complete | 95% |
| Auth system (Sanctum + roles) | ✅ Complete | 90% |
| Course CRUD | ✅ Complete | 95% |
| Assignment CRUD | ✅ Complete | 90% |
| Enrollment system | ✅ Complete | 95% |
| Submission flow | ✅ Complete | 90% |
| Laravel API Resources | ✅ Complete | 100% |
| Python grader service | ✅ Complete | 90% |
| Docker sandbox execution | ✅ Complete | 90% |
| Laravel ↔ Python integration | ✅ Complete | 85% |
| Queue + Redis async | ✅ Complete | 90% |
| Notification system | ✅ Complete | 85% |
| Feature tests | ✅ Complete | 75% |
| Frontend (React) | ❌ Not started | 0% |
| Phase 3 tables/features | ❌ Not started | 0% |
| DevOps / Docker for Laravel | ❌ Not started | 0% |
| Model factories + seeders | ❌ Missing | 0% |

**Overall backend progress: ~75%**  
**Overall project progress: ~45%** (frontend is the largest missing block)

**Current phase:** Phase 2 complete. Ready to begin Phase 3 or frontend.

---

## 9. Missing Parts

### Critical
1. **Frontend (React)** — zero UI code exists; this is the entire user-facing layer
2. **Model Factories** — `StudentJourneyTest` calls `User::factory()` but no factory file exists; all tests will fail without them
3. **`AssignmentPolicy`** — authorization is inline in controllers; should be extracted

### Important
4. **Database indexes** — `submission_status`, `student_id` on submissions need indexes for query performance
5. **Rate limiting** — no throttle on submission creation or retry endpoints
6. **Laravel Dockerfile** — cannot containerise the backend for deployment
7. **CI/CD** — no automated test runs on push
8. **Password field alignment** — `password_hash` naming needs explicit `$authPasswordName` in `User` model (present but verify Sanctum token resolution works)

### Nice to have
9. **Seeders** — no demo data for local development or staging
10. **API versioning** (`/api/v1/`) — no prefix, harder to evolve
11. **Soft deletes** — hard cascade deletes lose audit history
12. **Phase 3 migrations** — portfolios, projects, github_webhooks not migrated
13. **GitHub webhook receiver** — no endpoint to auto-trigger grading on push
14. **grader.py version pinning** — `grader_v2` vs `grader` duplication should be resolved
15. **CORS restriction** — grader has `allow_origins=["*"]`, should be locked to Laravel origin

---

## 10. Next Steps (Prioritized)

### Priority 1 — Unblock testing (1–2 days)
```
1. Create Model Factories:
   - UserFactory (role: teacher/student)
   - CourseFactory
   - AssignmentFactory (with/without test_cases)
   - SubmissionFactory

2. Create Seeders:
   - DatabaseSeeder → runs all seeders
   - Demo teacher + 2 students + 1 course + 2 assignments
```

### Priority 2 — Production stability (2–3 days)
```
3. Add database indexes:
   - submissions: (student_id, submission_status, assignment_id)
   - courses: (is_active, instructor_id)
   - assignments: (course_id, is_published, due_date)

4. Create AssignmentPolicy (move abort_unless → policy)

5. Add rate limiting:
   - POST /submissions: 10/minute per user
   - POST /submissions/{id}/retry: 3/hour per user

6. Create Laravel Dockerfile + docker-compose.yml:
   - php:8.2-fpm + Nginx + Redis + PostgreSQL
```

### Priority 3 — Frontend (2–3 weeks)
```
7. Scaffold React app (Vite + React + TypeScript):
   src/
   ├── pages/
   │   ├── auth/          Login.tsx, Register.tsx
   │   ├── courses/       CourseList.tsx, CourseDetail.tsx
   │   ├── assignments/   AssignmentDetail.tsx
   │   ├── submissions/   SubmitForm.tsx, SubmissionStatus.tsx
   │   └── notifications/ NotificationPanel.tsx
   ├── context/           AuthContext.tsx
   ├── api/               client.ts (axios + token)
   └── components/        CourseCard.tsx, RoleGuard.tsx

8. Implement API integration (Axios):
   - Auth flow (register → login → token storage)
   - Course listing + enrollment
   - Assignment submission form (GitHub URL input)
   - Real-time submission status polling
   - Notification badge + dropdown
```

### Priority 4 — Phase 3 foundations (1 week)
```
9. Create Phase 3 migrations:
   - portfolios, projects, deployment_configs
   - github_webhooks, activity_logs
   - system_settings, course_resources

10. GitHub webhook receiver:
    POST /api/webhooks/github
    - Verify X-Hub-Signature-256
    - Auto-dispatch GradeSubmissionJob on push event
```

### Priority 5 — CI/CD (3 days)
```
11. GitHub Actions pipeline:
    - php artisan test (on pull_request)
    - pytest test_grader.py (on pull_request)
    - docker build (on main push)
    - Deploy to staging (on tag)
```

---

## Summary

DevEduHub has a solid, well-structured backend covering the full submission-to-grading pipeline across two services. The architecture is clean, the security model is correctly implemented (role-based access, Docker sandbox isolation), and the async queue integration is production-ready. The primary gap is the complete absence of a frontend — the API exists and is functional, but no user can interact with it without building the React layer. The second most urgent gap is model factories, which block all automated testing.
