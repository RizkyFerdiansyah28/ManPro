# AGENTS.md — Silansia Development Guidelines

## 1. Project Overview

Silansia is a web-based elderly monitoring and emergency response application for a nursing home. The application manages elderly residents, family accounts, caregivers, rooms, service classes, camera sources, AI-generated gait activity logs, fall detection incidents, notifications, and monthly billing.

This repository is responsible for the **web application and integration layer**, not AI model development. The AI model is developed by a separate team, and its final integration contract has not yet been confirmed.

The product is **web-only**. Do not introduce native mobile applications, AI inference code, or unrelated infrastructure without explicit approval.

## 2. Technology Stack

- Backend: Laravel 13 / PHP
- Frontend: Inertia.js + React
- Styling: Tailwind CSS 4
- Database: PostgreSQL
- Application primary keys: UUID
- Authentication: Laravel session authentication
- Build tooling: Vite
- Development environment: Windows / PowerShell / VS Code

Inspect the actual dependency versions, repository structure, file casing, scripts, and established conventions before making changes. Do not assume that optional packages, TypeScript, frontend lint scripts, or browser automation are installed.

## 3. Development Principles

1. Work within the existing repository. Never recreate the project.
2. Follow Laravel conventions for models, migrations, controllers, requests, policies, services, and tests.
3. Use UUIDs consistently for application entities and related foreign keys. Preserve Laravel infrastructure identifiers where the established schema requires them.
4. Keep controllers thin and place business logic in appropriate services.
5. Use Form Requests for complex validation.
6. Use Policies, Gates, and middleware for server-side authorization.
7. Use Eloquent relationships and avoid N+1 queries.
8. Use database transactions for operations affecting multiple related records.
9. Prefer existing dependencies over installing new packages.
10. Maintain consistent, responsive, accessible React components and layouts.
11. Prefer PascalCase for new React components and camelCase for utilities, while respecting existing file conventions and case-sensitive import paths.
12. Preserve the project's existing directory and naming conventions.
13. Use clear, descriptive names and avoid unnecessary abstractions.
14. Never expose credentials, medical notes, private video sources, or sensitive information in logs, responses, or frontend props.
15. Use **Indonesian** for progress explanations, user-facing content, and final reports. Code identifiers may remain in English.
16. Implement only the phase and scope explicitly authorized by the user.
17. Distinguish actual implementation and verification from plans, assumptions, and mock behavior.

## 4. Roles and Authentication

The application has exactly three initial roles:

- `admin`: manages users and, in future approved phases, residents, rooms, service classes, cameras, billing, and operational data.
- `perawat`: in future approved phases, monitors rooms, receives emergency alerts, and validates fall incidents.
- `keluarga`: in future approved phases, views only their associated elderly residents and entitled benefits.

These role descriptions define **intended authorization boundaries**, not permission to implement all associated features immediately.

Rules:

- No public self-registration.
- Admin provisions family and caregiver accounts.
- Use Laravel session-based authentication with CSRF protection.
- Protect routes and actions through middleware and Policies.
- Enforce family-to-resident ownership on the backend for every relevant query and action.
- Do not allow inactive users to access protected functionality, including through an existing session.
- Never trust frontend-only access restrictions.
- Do not introduce a complex dynamic RBAC system without approval.
- Do not add a web-based Admin creation or privilege escalation path.
- Keep bootstrap of the initial Admin CLI-only, without default or hardcoded credentials.
- Preserve session revocation guarantees when changing session drivers or authentication configuration; test changed behavior explicitly.

## 5. Core Domain Rules

### 5.1 Family and Elderly Residents

- One family account may be responsible for multiple elderly residents.
- Every elderly resident belongs to exactly one family account under the current domain model.
- An elderly resident has at most one active room assignment.
- An elderly resident has at most one active service enrollment.
- Calculate age from birth date; do not store a permanently calculated age.
- Scope family-facing data, reports, notifications, camera access, and billing entitlements **per elderly resident**.
- Do not expose another family's resident data through IDs, filters, exports, nested relationships, or API responses.

### 5.2 Rooms and Service Classes

- Each room belongs to exactly one service class.
- Residents sharing a room must have the same service class as the room and their active enrollment.
- VIP / Class 1 rooms are private and have capacity 1.
- Class 2 and Class 3 rooms are shared, with configurable capacity.
- A room may have at most one camera.
- Cameras belong to rooms, not directly to residents.
- Preserve historical room assignments and service enrollments.
- Prevent overbooking, incompatible classes, and conflicting active assignments through transactional backend validation and appropriate locking.
- Do not rely on a frontend capacity indicator or a simple pre-insert count for concurrency safety.

### 5.3 Service Benefits

- VIP: private room, family live CCTV, instant **verified** emergency notification, daily gait report.
- Class 2: shared room, instant **verified** emergency notification, daily gait report, no family live CCTV.
- Class 3: shared room, weekly/monthly gait reports and verified incident summaries, no family live CCTV and no instant family emergency notification.
- Caregiver safety alerts are independent of family notification entitlements.
- Benefits must be checked per elderly resident using the applicable service enrollment and billing state.
- Do not grant family live CCTV for shared rooms or infer permission merely from family ownership.
- Do not invent service prices; use configured values or leave them unset as permitted by the schema.

## 6. AI Integration

The AI model is developed by another team. Its final output contract is not yet confirmed. Do not silently turn provisional assumptions into a final integration contract.

### 6.1 Gait Analysis

- Runs automatically in the background once the integration is approved and implemented.
- Does not require caregiver validation.
- Initial output is categorical, not numerical.
- Provisional categories: `walking_normal`, `walking_abnormal`, and `no_walking_detected`.
- Record received gait observations as activity logs.
- Do not fabricate observations when AI data is absent.
- Do not invent walking speed, cadence, stride length, clinical scores, or unsupported health conclusions.
- For VIP rooms, observations may be associated with the single active resident using documented room-based identity assumptions, only when the relevant conditions are satisfied.
- For shared rooms, observations must remain room-level or unidentified when individual identity is unknown.
- Never implement facial recognition or biometric identification.

### 6.2 Fall Detection

- AI fall events create pending incidents.
- Caregivers receive emergency alerts without waiting for family verification or billing checks.
- Caregivers must validate fall incidents and identify the resident where possible.
- Support false-alarm handling.
- Record caregiver identity, acknowledgement time, resolution time, and notes as applicable.
- Notify the correct family only after incident verification and according to service entitlements.
- Prevent duplicate events using unique external event IDs and idempotent ingestion behavior.
- Preserve incident history and audit-relevant status changes.

### 6.3 AI API

- Provide authenticated service-to-service ingestion endpoints **only in an explicitly approved integration phase**.
- Validate payloads, timestamps, camera associations, and event types; handle retries idempotently.
- Keep unconfirmed AI contract assumptions documented and configurable where appropriate.
- Apply appropriate authentication, authorization, rate limiting, and error handling.
- Mock events are allowed for development and testing only and must be clearly labeled.
- Do not claim real AI integration is verified without testing the actual model service.

## 7. Camera and Video

- One camera maximum per room.
- Camera sources may include browser webcam, uploaded video, and future RTSP streams.
- Browser webcam access requires user permission and a supported browser context.
- Do not assume Laravel can directly access a user's laptop webcam.
- Do not implement continuous recording or production video streaming infrastructure without approval.
- Restrict private camera access using backend authorization and service entitlements.
- Family live CCTV is limited to entitled VIP residents; do not expose shared-room cameras to families.
- Never expose camera credentials, private source references, or signed access tokens unnecessarily.
- Treat uploaded video and camera references as sensitive; validate uploads and storage access.

## 8. Billing and Benefit Restrictions

- Billing is monthly and associated with each resident's applicable service enrollment.
- Payment grace period is **3 calendar days after the due date**.
- During the grace period, family benefits remain active.
- After the grace period, additional family-facing benefits are restricted for the affected resident.
- Manual payment proof uploads and Admin verification are used for the MVP.
- Verified payment restores entitled benefits.
- Benefit restrictions are evaluated per resident, not globally per family account.
- Do not permanently delete accounts or records for unpaid invoices.
- Keep historical billing, enrollment, and payment records intact.

### 8.1 Non-Negotiable Safety Rule

Billing status must **NEVER** disable:

- Fall detection
- AI safety event ingestion
- Emergency incident creation
- Caregiver emergency alerts
- Caregiver incident handling
- Essential operational monitoring
- Safety logs and staff access

Only additional family-facing benefits may be restricted. Safety workflows must not depend on payment status.

## 9. Database Rules

- PostgreSQL is the primary database.
- Use UUID keys for application tables and domain relationships; preserve existing Laravel infrastructure key conventions.
- Use foreign keys, indexes, uniqueness constraints, and CHECK constraints where appropriate.
- Use partial unique indexes for active room assignments and active service enrollments.
- Use timezone-aware timestamps for AI observations and incident events.
- Use restrictive deletion rules for important historical records.
- Never remove incident, medical, or billing history casually.
- Avoid changing applied migrations; use new additive migrations.
- Preserve existing Phase 1 constraints, including monetary validity and PostgreSQL `NaN` rejection.
- Never run destructive database reset commands on the development or production database without explicit approval.
- When a schema change is proposed, inspect current migration status and data preservation implications first.
- Use database transactions and suitable row/table locking for concurrency-sensitive workflows.

## 10. Security Requirements

- Apply least-privilege authorization.
- Validate all incoming data.
- Prevent IDOR/BOLA by scoping queries to authorized records.
- Protect sensitive medical and personal information.
- Validate file type, file size, and storage permissions for uploads.
- Store private files outside publicly accessible paths where appropriate.
- Protect service-to-service AI ingestion with authentication and rate limiting when implemented.
- Do not expose stack traces or secrets to end users.
- Avoid logging private medical details, payment proofs, camera credentials, or plaintext passwords.
- Do not commit `.env`, secrets, credentials, or generated sensitive files.
- Use secure password hashing, session handling, CSRF protection, and appropriate throttling.
- Never trust client-provided role, account status, family ownership, or service entitlement flags.
- Do not expose hidden model attributes or sensitive relations through Inertia shared props.
- Require explicit approval for changes that weaken existing security guarantees.

## 11. Exclusions — Out of Scope Unless Approved

- AI model training, retraining, or fine-tuning
- YOLO inference implementation
- Facial recognition or biometric identification
- Automatic medical diagnosis
- Native Android or iOS applications
- Social login or public registration
- Complex dynamic RBAC systems
- Payment gateways or automatic bank reconciliation
- Custom CCTV drivers or camera firmware
- Production RTSP/media streaming infrastructure
- Continuous webcam recording
- Telemedicine, medication management, or hospital integration
- Multi-tenant SaaS architecture
- Microservices or unnecessary infrastructure
- Advanced numerical gait analytics unsupported by the AI model
- Unrelated application features or unnecessary dependencies

Do not change frameworks, databases, or core architecture without explicit approval. Features described in this document are **requirements for their future approved phases**, not blanket authorization to implement them now.

## 12. Repository Safety

- Inspect Git status and current branch before modifying files.
- Preserve unrelated or uncommitted user changes.
- Do not overwrite `.env`.
- Do not expose secrets in logs, reports, diffs, or responses.
- Do not execute `migrate:fresh`, `db:wipe`, `migrate:reset`, or destructive migration/cleanup commands against development or production databases.
- Do not delete existing source code without a documented reason.
- Do not deploy to production.
- Do not commit, push, or create remote pull requests without approval.
- Ask before destructive, irreversible, or security-sensitive actions.
- Avoid modifying unrelated files or reports.
- Before using database commands, identify the active connection, database, and environment.

## 13. Testing and Verification

For every implementation phase:

1. Audit the relevant source code and existing tests.
2. Run relevant Laravel tests using an **isolated test database**.
3. Preserve and use the existing PostgreSQL test runner and its ownership/connection guards where applicable.
4. Never point destructive test setup, rollback, or cleanup at the development database.
5. Verify migration compatibility and data-preservation behavior.
6. Test authentication, active-account checks, authorization boundaries, and IDOR/BOLA where relevant.
7. Run available formatting and lint checks, such as Laravel Pint.
8. Run `npm run build` (or the repository's actual equivalent) after frontend changes.
9. Run TypeScript checks only if TypeScript and the relevant scripts are configured.
10. Fix feasible errors and rerun affected checks.
11. Report exact commands, pass/fail counts, assertions, skips, warnings, and known limitations.
12. Clearly distinguish HTTP/Inertia tests and successful builds from real-browser visual/E2E verification.
13. Do not claim production readiness without production verification.

Do not claim features are complete or verified when they have not been implemented or tested. Do not count skipped tests as passed.

## 14. Development Workflow

1. Read this `AGENTS.md` and any relevant phase-specific instructions.
2. Audit repository status, actual implementation, migrations, dependencies, and existing documentation.
3. Propose a scoped implementation plan and identify major risks.
4. Implement only the explicitly authorized phase, incrementally.
5. Preserve previous phase behavior and regression tests.
6. Review changed files and perform relevant verification.
7. Generate the required report in `docs/report/`.
8. Summarize the result, remaining limitations, and next recommended phase in Indonesian.
9. Stop and wait for user review and approval before proceeding to the next phase.

If an important decision cannot safely be inferred, stop and request approval. Do not fabricate successful test results or production integration claims.

## 15. Report Management and Required Documentation

All Codex-generated implementation reports, code reviews, audit findings, remediation reports, and phase completion reports must be stored in:

`docs/report/`

This path is relative to the **repository root**. The directory is intended for personal development documentation and may be ignored by Git.

### 15.1 Report Storage Rules

1. Create `docs/report/` if it does not exist.
2. Never create new implementation, audit, review, remediation, or phase reports directly in the repository root.
3. Use clear, phase-specific filenames.
4. Preserve previous reports as historical documentation.
5. Do not overwrite an earlier phase's report unless explicitly requested.
6. Do not modify `.gitignore` without explicit approval.
7. Keep technical documentation such as ERD diagrams, database guides, API contracts, and application setup instructions in their existing tracked documentation locations.
8. Do not move, rename, or delete existing reports without explicit approval.
9. Do not create or update a duplicate root-level `SILANSIA_IMPLEMENTATION_REPORT.md`.
10. At the end of each task, summarize results in the Codex conversation and identify the generated report path.
11. Do not include real credentials, secrets, private video URLs, or sensitive personal data in reports.
12. If `docs/report/` is Git-ignored, do not attempt to force-add it to Git without approval.

### 15.2 Report Naming Convention

Use the following filenames:

- Phase implementation: `docs/report/SILANSIA_PHASE_{N}_REPORT.md`
- Phase code review: `docs/report/SILANSIA_PHASE_{N}_CODE_REVIEW.md`
- Phase remediation: `docs/report/SILANSIA_PHASE_{N}_FIX_REPORT.md`
- Phase audit, when separate: `docs/report/SILANSIA_PHASE_{N}_AUDIT_REPORT.md`

Replace `{N}` with the actual phase number, for example:

- `docs/report/SILANSIA_PHASE_1_REPORT.md`
- `docs/report/SILANSIA_PHASE_1_CODE_REVIEW.md`
- `docs/report/SILANSIA_PHASE_1_FIX_REPORT.md`
- `docs/report/SILANSIA_PHASE_2_REPORT.md`
- `docs/report/SILANSIA_PHASE_2_CODE_REVIEW.md`
- `docs/report/SILANSIA_PHASE_3_REPORT.md`

For additional reports, choose a descriptive filename within `docs/report/` and avoid overwriting existing work.

### 15.3 Required Report Contents

All reports must be written in Indonesian and include, as applicable:

1. Repository audit and initial conditions
2. Scope and phase boundaries
3. Architecture decisions and rationale
4. Database schema and migration changes, or explicit confirmation that none were needed
5. Features implemented in the current phase
6. Important files created or modified
7. Authentication and authorization changes, if applicable
8. AI integration contracts and limitations, if applicable
9. Billing and benefit restrictions, if applicable
10. Testing results and actual commands executed
11. Security considerations and verification
12. Known issues, limitations, and unverified behavior
13. Pending decisions and manual checks
14. Local setup and run instructions, when applicable
15. Final completion checklist
16. Recommendations for the next phase

### 15.4 Reporting Quality Requirements

- Clearly distinguish **completed**, **partial**, **blocked**, and **unverified** functionality.
- Report actual test results; never fabricate successful execution.
- Explain tests that were skipped or could not be performed.
- Identify whether findings are based on code inspection, automated tests, manual browser testing, or external integration tests.
- Do not claim production readiness without production verification.
- Preserve previous phase reports and do not modify unrelated reports.
- Keep historical results distinguishable from the latest results when updating a report.
- Stop after completing the authorized phase and wait for user approval.

The final Codex response must summarize the work and identify the report path, for example:

`docs/report/SILANSIA_PHASE_3_REPORT.md`
