---
name: youth-workflow-audit
description: 'Test the Youth Organization PHP system end to end and recommend improvements. Use for workflow testing, regression checks, role and permission verification, database-backed journey audits, browser smoke tests, and prioritized UX or reliability suggestions.'
argument-hint: 'Optional: specify a journey, role, module, or suspected problem to audit'
user-invocable: true
disable-model-invocation: false
---

# Youth Workflow Audit

## Purpose

Evaluate whether a real member or officer can complete the system's important journeys, and turn observed failures or friction into specific, evidence-based recommendations. Test the running application when possible; use static inspection and PHP checks when the environment is unavailable.

## System Context

This workspace is a server-rendered PHP application for a youth organization. It uses MySQL through PDO, JSON storage for some legacy data, Bootstrap, and session-based roles: `member`, `officer`, and `admin`.

Important entry points include:

- `login.php`: registration, approval gate, authentication, and role redirects
- `homepage.php`: member landing page
- `dashboard.php`: officer/admin dashboard
- `activities.php` and `events-calendar.php`: activity discovery, creation, registration, RSVP, and status management
- `attendance.php`: attendance and participation records
- `feedback.php`: member feedback and officer/admin responses
- `profile.php` and `edit-profile.php`: member profile data
- `members.php`: approval and member administration
- `documents.php`, `announcements.php`, `meeting-agendas.php`, `funds-budget.php`, `project-proposals.php`, and `reports.php`: supporting workflows

Treat the implementation as authoritative when it differs from documentation. Do not modify production data merely to test a workflow.

## Procedure

### 1. Establish the test boundary

1. Inspect the relevant entry page, included files, database schema, and neighboring store/helper code.
2. Check whether Apache/PHP/MySQL are available and whether a browser page or test account is available.
3. Identify the target roles and the smallest set of journeys that cover the request.
4. Use disposable test records only when the environment and permissions make cleanup reliable. Never expose or invent passwords, tokens, or private user data.

### 2. Run cheap executable checks first

1. Run `php -l` on changed or relevant PHP files.
2. Check database connectivity with the repository's existing verification script when available.
3. Inspect JSON files with a parser or a PHP one-liner and report malformed or unexpectedly empty data.
4. Search forms and handlers for matching action names, authorization checks, redirect targets, and success/error feedback.
5. Check that schema enum values and columns agree with the values accepted by PHP.

Do not call a static check a passed user journey. Label it as static evidence.

### 3. Execute role-based journeys

Test each applicable journey as the least-privileged role first, then repeat the management path with an officer/admin account.

**Authentication and access**

- Register a new member with valid data.
- Confirm duplicate username, invalid username, short password, mismatch, and missing-field validation.
- Confirm inactive members cannot log in.
- Confirm active members land on `homepage.php` and privileged users land on `dashboard.php`.
- Try direct access to privileged pages as a member and confirm the request is denied or redirected.
- Log out and confirm the session is no longer usable.

**Member participation**

- Browse and filter activities.
- Register once for an open activity, verify the registration appears, and repeat to confirm duplicate handling.
- Confirm planning/completed activities cannot be registered for.
- Submit feedback, view its status, and verify a member cannot read another member's feedback.
- View and update the member's own profile fields.
- Confirm attendance and notification data identify the correct user.

**Officer/admin operations**

- Review and approve a pending member.
- Create an activity, change its status, and verify completed activities cannot be changed.
- Review registrants, attendance, feedback, reports/insights, announcements, documents, agendas, funds, and project proposals where those modules are in scope.
- Respond to feedback and verify status/timestamp changes.
- Confirm audit entries identify the actor and action.

### 4. Verify each result

For every step, compare all three surfaces when applicable:

1. The visible response: page, redirect, message, validation, or permission result.
2. The persisted state: database row, JSON record, uploaded file, or session state.
3. The next related view: list, dashboard count, notification, report, or detail page.

Record a journey as `PASS` only when the expected result is visible and persisted correctly. Use `FAIL` for incorrect behavior, `BLOCKED` for an unavailable prerequisite, and `PARTIAL` when only static evidence was possible.

### 5. Report improvements

For every failure or meaningful friction, report:

- Severity: `critical`, `high`, `medium`, or `low`
- Journey and role affected
- Exact reproduction steps
- Expected versus actual behavior
- Evidence: file/symbol, command output, URL, or screenshot
- Smallest root-cause fix
- Regression test that should be added

Prioritize security and data loss first, then broken core journeys, incorrect permissions, persistence mismatches, accessibility/usability barriers, and cosmetic polish. Do not recommend broad rewrites when a local fix is sufficient.

## Quality Gates

Before finishing an audit:

- Cover authentication and at least one member and management journey in scope.
- Test both allowed and denied paths for each important role boundary.
- Check validation, persistence, duplicate submission, empty/error states, and post-action navigation.
- Separate executable results from static inspection and unavailable-environment assumptions.
- Avoid destructive changes and clean up disposable records when possible.
- Re-run the narrowest failed check after any fix.
- End with a short prioritized list of what needs improvement and why.

## Output Format

Use this structure:

```text
Environment: [running browser / PHP-only / blocked, with reason]
Scope: [roles, modules, and journeys tested]

Results
- [PASS|FAIL|PARTIAL|BLOCKED] Journey - role: concise evidence

Findings
1. [severity] Finding title
   Impact: ...
   Evidence: ...
   Recommendation: ...

Suggested next fixes
1. Highest-value fix and regression check
2. Next fix and regression check

Coverage gaps: [short list]
```

Keep recommendations concrete and tied to a file, handler, schema object, or user journey. Do not claim browser or database behavior that was not actually observed.
