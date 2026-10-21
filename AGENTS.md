# AGENTS.md — Silansia Development Guidelines

## Project Overview

Silansia is a web-based elderly monitoring and emergency response application for a nursing home.

The application manages elderly residents, family accounts, caregivers, rooms, service classes, camera sources, AI-generated gait activity logs, fall detection incidents, notifications, and monthly billing.

This repository is responsible for the **web application and integration layer**, not AI model development.

## Technology Stack

- Backend: Laravel 13 / PHP
- Frontend: Inertia.js + React
- Styling: Tailwind CSS 4
- Database: PostgreSQL
- Primary keys: UUID
- Authentication: Laravel session authentication
- Build tooling: Vite
- Development environment: Windows / PowerShell / VS Code

Inspect actual dependency versions and repository structure before making changes. Preserve working conventions where possible.

## Development Principles

1. Work within the existing repository. Never recreate the project.
2. Follow Laravel conventions for models, migrations, controllers, requests, policies, services, and tests.
3. Use UUIDs consistently for application entities and related foreign keys.
4. Keep controllers thin and business logic in appropriate services.
5. Use Form Requests for complex validation.
6. Use Policies and middleware for server-side authorization.
7. Use Eloquent relationships and avoid N+1 queries.
8. Use database transactions for operations affecting multiple related records.
9. Prefer existing dependencies over installing new packages.
10. Maintain consistent responsive React components and layouts.
11. Keep frontend component files in PascalCase and utility files in camelCase.
12. Preserve the project's existing directory conventions.
13. Use clear, descriptive names and avoid unnecessary abstractions.
14. Never expose credentials, medical notes, private video sources, or sensitive information in logs.
15. Use Indonesian for progress explanations, user-facing content, and final reports.

## Roles and Authentication

The application has exactly three initial roles:

- `admin`: manages users, residents, rooms, service classes, cameras, billing, and operational data.
- `perawat`: monitors rooms, receives emergency alerts, and validates fall incidents.
- `keluarga`: views only their associated elderly residents and entitled benefits.

Rules:

- No public self-registration.
- Admin provisions family and caregiver accounts.
- Use Laravel session-based authentication with CSRF protection.
- Protect routes through middleware and Policies.
- Enforce family-to-resident ownership on the backend.
- Do not allow inactive users to access protected functionality.
- Never trust frontend-only access restrictions.

## Core Domain Rules

### Family and Elderly Residents

- One family account may be responsible for multiple elderly residents.
- Every elderly resident belongs to exactly one family account.
- An elderly resident has at most one active room assignment.
- An elderly resident has at most one active service enrollment.
- Calculate age from birth date; do not store a permanently calculated age.

### Rooms and Service Classes

- Each room belongs to exactly one service class.
- Residents sharing a room must have the same service class.
- VIP / Class 1 rooms are private and have capacity 1.
- Class 2 and Class 3 rooms are shared, with configurable capacity.
- A room may have at most one camera.
- Cameras belong to rooms, not directly to residents.
- Preserve historical room assignments and service enrollments.
- Prevent overbooking and incompatible room assignments through transactional backend validation.

### Service Benefits

- VIP: private room, family live CCTV, instant verified emergency notification, daily gait report.
- Class 2: shared room, instant verified emergency notification, daily gait report, no family live CCTV.
- Class 3: shared room, weekly/monthly reports and verified incident summaries, no family live CCTV.

Benefits must be checked per elderly resident.

## AI Integration

The AI model is developed by another team. Its final output contract is not yet confirmed.

### Gait Analysis

- Runs automatically in the background.
- Does not require caregiver validation.
- Initial output is categorical, not numerical.
- Provisional categories: `walking_normal`, `walking_abnormal`, and `no_walking_detected`.
- Record gait observations as activity logs.
- Do not fabricate observations when AI data is absent.
- Do not invent walking speed, cadence, stride length, or clinical scores.
- For VIP rooms, observations may be associated with the single active resident using documented room-based identity assumptions.
- For shared rooms, observations must remain room-level when individual identity is unknown.
- Never implement facial recognition or biometric identification.

### Fall Detection

- AI fall events create pending incidents.
- Caregivers receive emergency alerts.
- Caregivers must validate fall incidents and identify the resident where possible.
- Support false alarm handling.
- Record caregiver identity, acknowledgement time, resolution time, and notes.
- Notify the correct family only after incident verification and according to service entitlements.
- Prevent duplicate events using unique external event IDs.

### AI API

- Provide authenticated service-to-service ingestion endpoints.
- Validate payloads and handle retries idempotently.
- Keep AI contract assumptions configurable.
- Mock events are allowed for development and testing only.
- Do not claim real AI integration is verified without testing the actual model service.

## Camera and Video

- One camera maximum per room.
- Camera sources may include browser webcam, uploaded video, and future RTSP streams.
- Browser webcam access requires user permission and a supported browser context.
- Do not assume Laravel can directly access a user's laptop webcam.
- Do not implement continuous recording or a production video streaming infrastructure without approval.
- Restrict private camera access using backend authorization.
- Never expose camera credentials or private video URLs.

## Billing and Benefit Restrictions

- Billing is monthly and associated with each resident's active service enrollment.
- Payment grace period is 3 calendar days after the due date.
- During the grace period, family benefits remain active.
- After the grace period, additional family benefits are restricted for the affected resident.
- Manual payment proof uploads and Admin verification are used for the MVP.
- Verified payment restores entitled benefits.
- Benefit restrictions are evaluated per resident, not globally per family account.
- Do not permanently delete accounts or records for unpaid invoices.

### Non-Negotiable Safety Rule

Billing status must NEVER disable:

- Fall detection
- AI safety event ingestion
- Emergency incident creation
- Caregiver emergency alerts
- Caregiver incident handling
- Essential operational monitoring
- Safety logs and staff access

Only additional family-facing benefits may be restricted.

## Database Rules

- PostgreSQL is the primary database.
- Use UUID keys for application tables.
- Use foreign keys, indexes, uniqueness constraints, and check constraints where appropriate.
- Use partial unique indexes for active room assignments and active service enrollments.
- Use timezone-aware timestamps for AI observations and incident events.
- Use restrictive deletion rules for important historical records.
- Never remove incident, medical, or billing history casually.
- Avoid changing applied migrations; use new additive migrations.
- Never run destructive database reset commands without explicit approval.

## Security Requirements

- Apply least-privilege authorization.
- Validate all incoming data.
- Prevent IDOR/BOLA by scoping queries to authorized records.
- Protect sensitive medical information.
- Validate file type, file size, and storage permissions for uploads.
- Store private files outside publicly accessible paths where appropriate.
- Protect service-to-service AI ingestion with authentication and rate limiting.
- Do not expose stack traces or secrets to end users.
- Avoid logging private medical details, payment proofs, or camera credentials.
- Do not commit `.env`, secrets, or generated sensitive files.

## Exclusions — Out of Scope

Do not implement the following unless explicitly approved:

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

Do not change frameworks, databases, or core architecture without explicit approval.

## Repository Safety

- Inspect Git status before modifying files.
- Preserve unrelated or uncommitted user changes.
- Do not overwrite `.env`.
- Do not expose secrets.
- Do not execute `migrate:fresh`, `db:wipe`, or other destructive database commands.
- Do not delete existing source code without a documented reason.
- Do not deploy to production.
- Do not push commits or create remote pull requests without approval.
- Ask before destructive, irreversible, or security-sensitive actions.

## Testing and Verification

For every implementation phase:

1. Run relevant Laravel tests.
2. Verify migration compatibility.
3. Verify authentication and authorization boundaries.
4. Run available formatting and lint checks.
5. Run `npm run build` after frontend changes.
6. Fix feasible errors.
7. Report actual test results.
8. Clearly identify blocked or unverified functionality.

Do not claim features are complete if they have not been implemented or verified.

## Development Workflow

- Start by auditing the repository.
- Create an implementation plan.
- Implement changes incrementally.
- Review the result after each major phase.
- Maintain a concise progress report.
- Prefer safe, maintainable defaults.
- Stop and request approval when an important decision cannot safely be inferred.
- Do not fabricate successful test results or production integration claims.

## Required Final Documentation

At the end of the implementation task, create:

`SILANSIA_IMPLEMENTATION_REPORT.md`

The report must be in Indonesian and include:

- Repository audit
- Architecture decisions
- Database schema and migrations
- Implemented features by phase
- Important files created or modified
- Authentication and authorization
- AI integration contract and limitations
- Billing and benefit restrictions
- Testing results and actual commands
- Known issues
- Pending decisions
- Local setup and run instructions
- Final completion checklist

The report must distinguish completed, partial, blocked, and unverified work.