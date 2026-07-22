# Manifest format

`build_report.py` accepts a UTF-8 JSON object and validates the completion gate
before producing HTML. The values below are illustrative only; replace them
with the actual target, tasks, branches, findings, and screenshots from the
current audit. Use the requested URL when one is supplied; otherwise set
`meta.base_url` from the repository `.env` `APP_URL`. All screenshot paths are
resolved relative to the manifest directory.

```json
{
  "meta": {
    "title": "Target menu human tester audit",
    "target": "Target menu",
    "target_path": "target-menu",
    "tester_identity": "codex/sol-high",
    "browser_tool": "in-app Browser",
    "base_url": "https://local.blb.lara",
    "environment": "development",
    "mutation_policy": "Test data permitted; generated records are prefixed and cleaned up when safe.",
    "role": "Test user",
    "started_at": "2026-07-21T10:00:00+08:00",
    "finished_at": "2026-07-21T10:45:00+08:00",
    "auth_state": "authenticated local test user",
    "viewports": ["1440x900", "390x844"],
    "scope_note": "All destinations reachable from the named target menu.",
    "notes": ["Permission-denied state was not available locally."]
  },
  "workflow_assessment": {
    "applicable": true,
    "rationale": "Submitting the request hands work to a supervisor and returns the result to the employee."
  },
  "role_sessions": [
    {
      "alias": "employee-A",
      "role": "Employee",
      "status": "tested",
      "notes": "Submitted the workflow from the dashboard."
    },
    {
      "alias": "supervisor-A",
      "role": "Supervisor",
      "status": "tested",
      "notes": "Approved and rejected in separate workflow runs."
    }
  ],
  "workflow_paths": [
    {
      "id": "request.approve",
      "name": "Submit then approve",
      "roles": ["employee-A", "supervisor-A"],
      "starting_state": "Employee has no open request for the test date.",
      "branch": "Approve",
      "expected_handoff": "Supervisor sees the submitted request and employee sees the approved result.",
      "observed_handoff": "Supervisor queue showed the request; approval changed the employee view to Approved.",
      "status": "tested",
      "final_state": "Approved",
      "notes": "Employee sees the resulting status after supervisor action.",
      "screenshots": [
        "screenshots/request-submitted.png",
        "screenshots/request-approved.png"
      ]
    }
  ],
  "pages": [
    {
      "id": "target.index",
      "title": "Target page",
      "url": "https://local.blb.lara/target",
      "status": "tested",
      "primary_task": "Complete the page's primary user task",
      "notes": "Primary task and empty state checked.",
      "task_steps": [
        "Open the target from the menu",
        "Complete the primary action",
        "Verify the resulting state"
      ],
      "states_tested": ["default", "required validation", "success"],
      "controls_tested": ["primary action", "status filter"],
      "observed_outcome": "The saved record appeared with the expected status.",
      "screenshots": [
        "screenshots/target-index-baseline.png",
        "screenshots/target-index-success.png"
      ]
    }
  ],
  "findings": [
    {
      "id": "HT-001",
      "title": "Empty state does not explain how to recover",
      "category": "workflow",
      "severity": "medium",
      "confidence": "confirmed",
      "page_ids": ["target.index"],
      "summary": "A typo in search leaves the user with an empty table and no clear reset path.",
      "steps": ["Open the target page", "Enter an unmatched search value", "Submit the search"],
      "expected": "The page explains that there are no matches and offers a clear reset or edit path.",
      "actual": "The table is blank and the only recovery is browser back or manual editing.",
      "impact": "Users can mistake a filtered empty result for missing data and abandon the task.",
      "design_principle": "Norman feedback; write for humans",
      "recommendation": "Provide an explicit no-results message with a visible reset action.",
      "evidence": [
        {
          "label": "No-results state",
          "screenshot": "screenshots/target-index-no-results.png",
          "caption": "The current state after submitting an unmatched search."
        }
      ]
    }
  ]
}
```

Allowed page statuses are `tested`, `partially-tested`, and `blocked`.
`tested` pages require task steps, tested states, an observed outcome, and
existing screenshots. Partial and blocked pages require a concrete reason.
When `findings` is empty, add `meta.no_findings_rationale` with the actual
coverage that justifies that conclusion.

When the selected driver is not `in-app Browser`, add a concrete reason without
downgrading otherwise complete coverage solely because of the driver:

```json
{
  "browser_tool": "Playwright standalone",
  "browser_fallback_reason": "In-app Browser setup returned a browser-disconnected error after documented recovery."
}
```
