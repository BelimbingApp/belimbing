#!/usr/bin/env python3
"""Build a portable, screenshot-backed HTML audit from a JSON manifest."""

from __future__ import annotations

import argparse
import base64
import html
import json
import mimetypes
import re
from pathlib import Path
from typing import Any


SEVERITY_ORDER = {"critical": 0, "high": 1, "medium": 2, "low": 3}
VALID_STATUSES = {"tested", "partially-tested", "blocked"}
FINDING_FIELDS = {
    "id",
    "title",
    "category",
    "severity",
    "confidence",
    "page_ids",
    "summary",
    "steps",
    "expected",
    "actual",
    "impact",
    "recommendation",
    "evidence",
}


def read_manifest(path: Path) -> tuple[dict[str, Any], Path]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError("Manifest root must be a JSON object")
    return data, path.parent


def esc(value: Any) -> str:
    return html.escape(str(value))


def safe_segment(value: Any) -> str:
    result = re.sub(r"[^a-z0-9]+", "-", str(value).strip().lower()).strip("-")
    return result or "unknown"


def referenced_file(root: Path, relative: Any) -> Path | None:
    if not isinstance(relative, str) or not relative.strip():
        return None
    root = root.resolve()
    path = (root / relative).resolve()
    try:
        path.relative_to(root)
    except ValueError:
        return None
    return path if path.is_file() else None


def validate_manifest(data: dict[str, Any], root: Path) -> None:
    errors: list[str] = []
    meta = data.get("meta")
    if not isinstance(meta, dict):
        raise ValueError("Manifest meta must be an object")

    for field in ("target", "target_path", "tester_identity", "browser_tool", "started_at", "finished_at"):
        if not str(meta.get(field, "")).strip():
            errors.append(f"meta.{field} is required")

    identity = str(meta.get("tester_identity", ""))
    if identity and not re.fullmatch(r"[^/\s]+/[^/\s]+-[^/\s]+", identity):
        errors.append("meta.tester_identity must use provider/model-effort format")

    browser_tool = str(meta.get("browser_tool", ""))
    if (
        browser_tool
        and browser_tool.casefold() != "in-app browser"
        and not str(meta.get("browser_fallback_reason", "")).strip()
    ):
        errors.append("meta.browser_fallback_reason is required for a fallback driver")
    assessment = data.get("workflow_assessment")
    if not isinstance(assessment, dict) or not isinstance(assessment.get("applicable"), bool):
        errors.append("workflow_assessment.applicable must be true or false")
    elif not str(assessment.get("rationale", "")).strip():
        errors.append("workflow_assessment.rationale is required")

    pages = data.get("pages")
    if not isinstance(pages, list) or not pages:
        errors.append("pages must contain the visible in-scope inventory")
        pages = []
    for index, page in enumerate(pages):
        label = f"pages[{index}]"
        if not isinstance(page, dict):
            errors.append(f"{label} must be an object")
            continue
        page_id = str(page.get("id", label))
        status = str(page.get("status", ""))
        if status not in VALID_STATUSES:
            errors.append(f"{page_id}: status must be tested, partially-tested, or blocked")
        if status == "tested":
            for field in ("task_steps", "states_tested", "screenshots"):
                if not isinstance(page.get(field), list) or not page[field]:
                    errors.append(f"{page_id}: tested page requires non-empty {field}")
            if not str(page.get("observed_outcome", "")).strip():
                errors.append(f"{page_id}: tested page requires observed_outcome")
        elif status in {"partially-tested", "blocked"} and not str(page.get("notes", "")).strip():
            errors.append(f"{page_id}: {status} page requires a reason in notes")
        for screenshot in page.get("screenshots", []) if isinstance(page.get("screenshots"), list) else []:
            if referenced_file(root, screenshot) is None:
                errors.append(f"{page_id}: screenshot does not exist or escapes the run directory: {screenshot}")

    roles = data.get("role_sessions", [])
    role_aliases = {
        str(role.get("alias"))
        for role in roles
        if isinstance(role, dict) and str(role.get("alias", "")).strip()
    }
    workflows = data.get("workflow_paths", [])
    if isinstance(assessment, dict) and assessment.get("applicable") is True and not workflows:
        errors.append("workflow_paths cannot be empty when workflow_assessment.applicable is true")
    if not isinstance(workflows, list):
        errors.append("workflow_paths must be an array")
        workflows = []
    for index, workflow in enumerate(workflows):
        label = f"workflow_paths[{index}]"
        if not isinstance(workflow, dict):
            errors.append(f"{label} must be an object")
            continue
        workflow_id = str(workflow.get("id", label))
        status = str(workflow.get("status", ""))
        if status not in VALID_STATUSES:
            errors.append(f"{workflow_id}: invalid workflow status")
        workflow_roles = workflow.get("roles")
        if not isinstance(workflow_roles, list) or not workflow_roles:
            errors.append(f"{workflow_id}: roles must identify the actor sequence")
            workflow_roles = []
        missing_roles = [str(alias) for alias in workflow_roles if str(alias) not in role_aliases]
        if missing_roles:
            errors.append(f"{workflow_id}: missing role sessions for {', '.join(missing_roles)}")
        if status == "tested":
            for field in (
                "starting_state",
                "branch",
                "expected_handoff",
                "observed_handoff",
                "final_state",
            ):
                if not str(workflow.get(field, "")).strip():
                    errors.append(f"{workflow_id}: tested workflow requires {field}")
            if not isinstance(workflow.get("screenshots"), list) or not workflow["screenshots"]:
                errors.append(f"{workflow_id}: tested workflow requires screenshot evidence")
        elif status in {"partially-tested", "blocked"} and not str(workflow.get("notes", "")).strip():
            errors.append(f"{workflow_id}: {status} workflow requires a reason in notes")
        for screenshot in workflow.get("screenshots", []) if isinstance(workflow.get("screenshots"), list) else []:
            if referenced_file(root, screenshot) is None:
                errors.append(f"{workflow_id}: screenshot does not exist or escapes the run directory: {screenshot}")

    findings = data.get("findings")
    if not isinstance(findings, list):
        errors.append("findings must be an array")
        findings = []
    if not findings and not str(meta.get("no_findings_rationale", "")).strip():
        errors.append("meta.no_findings_rationale is required when findings is empty")
    for index, finding in enumerate(findings):
        label = f"findings[{index}]"
        if not isinstance(finding, dict):
            errors.append(f"{label} must be an object")
            continue
        finding_id = str(finding.get("id", label))
        for field in sorted(FINDING_FIELDS):
            value = finding.get(field)
            if value is None or value == "" or value == []:
                errors.append(f"{finding_id}: finding requires {field}")
        evidence = finding.get("evidence", [])
        if isinstance(evidence, list):
            for item in evidence:
                screenshot = item.get("screenshot") if isinstance(item, dict) else None
                if referenced_file(root, screenshot) is None:
                    errors.append(f"{finding_id}: evidence screenshot is missing: {screenshot}")

    if errors:
        raise ValueError("Audit completion validation failed:\n- " + "\n- ".join(errors))


def lines(values: Any) -> str:
    if not values:
        return "<p class=muted>—</p>"
    items = values if isinstance(values, list) else [values]
    return "<ul>" + "".join(f"<li>{esc(item)}</li>" for item in items) + "</ul>"


def screenshot_uri(root: Path, relative: str) -> str | None:
    path = referenced_file(root, relative)
    if path is None:
        return None
    mime = mimetypes.guess_type(path.name)[0] or "application/octet-stream"
    encoded = base64.b64encode(path.read_bytes()).decode("ascii")
    return f"data:{mime};base64,{encoded}"


def evidence_html(evidence: Any, root: Path) -> str:
    if not evidence:
        return '<p class="muted">No screenshot supplied.</p>'
    cards = []
    for item in evidence:
        if not isinstance(item, dict):
            continue
        label = esc(item.get("label", "Evidence"))
        caption = esc(item.get("caption", ""))
        relative = str(item.get("screenshot", ""))
        uri = screenshot_uri(root, relative) if relative else None
        if uri:
            media = f'<img loading="lazy" src="{uri}" alt="{label}">' 
        else:
            media = f'<div class="missing">Screenshot unavailable:<br><code>{esc(relative)}</code></div>'
        cards.append(f'<figure>{media}<figcaption><strong>{label}</strong> {caption}</figcaption></figure>')
    return '<div class="evidence-grid">' + "".join(cards) + "</div>"


def screenshots_html(screenshots: Any, root: Path, label: str) -> str:
    if not isinstance(screenshots, list) or not screenshots:
        return '<p class="muted">No screenshot supplied.</p>'
    evidence = [
        {
            "label": f"{label} — {Path(str(path)).stem}",
            "screenshot": path,
            "caption": "",
        }
        for path in screenshots
    ]
    return evidence_html(evidence, root)


def page_row(page: dict[str, Any]) -> str:
    status = str(page.get("status", "unknown"))
    return (
        f'<tr><td><code>{esc(page.get("id", ""))}</code></td>'
        f'<td>{esc(page.get("title", ""))}</td>'
        f'<td><span class="status {esc(status)}">{esc(status)}</span></td>'
        f'<td>{esc(page.get("primary_task", ""))}</td>'
        f'<td>{esc(page.get("notes", ""))}</td></tr>'
    )


def role_row(role: dict[str, Any]) -> str:
    return (
        f'<tr><td><code>{esc(role.get("alias", ""))}</code></td>'
        f'<td>{esc(role.get("role", ""))}</td>'
        f'<td><span class="status {esc(role.get("status", "unknown"))}">{esc(role.get("status", "unknown"))}</span></td>'
        f'<td>{esc(role.get("notes", ""))}</td></tr>'
    )


def workflow_row(workflow: dict[str, Any]) -> str:
    roles = workflow.get("roles", [])
    if isinstance(roles, list):
        roles = ", ".join(str(role) for role in roles)
    return (
        f'<tr><td><code>{esc(workflow.get("id", ""))}</code></td>'
        f'<td>{esc(workflow.get("name", ""))}</td>'
        f'<td>{esc(roles)}</td>'
        f'<td>{esc(workflow.get("starting_state", ""))}</td>'
        f'<td>{esc(workflow.get("branch", ""))}</td>'
        f'<td>{esc(workflow.get("expected_handoff", ""))}</td>'
        f'<td>{esc(workflow.get("observed_handoff", ""))}</td>'
        f'<td><span class="status {esc(workflow.get("status", "unknown"))}">{esc(workflow.get("status", "unknown"))}</span></td>'
        f'<td>{esc(workflow.get("final_state", ""))}</td>'
        f'<td>{esc(workflow.get("notes", ""))}</td></tr>'
    )


def page_evidence(page: dict[str, Any], root: Path) -> str:
    return (
        f'<article class="panel"><h3>{esc(page.get("title", page.get("id", "Page")))}</h3>'
        f'<p class="muted"><code>{esc(page.get("id", ""))}</code> — {esc(page.get("observed_outcome", page.get("notes", "")))}</p>'
        f'<div class="columns"><section><h4>Task steps</h4>{lines(page.get("task_steps"))}</section>'
        f'<section><h4>States and controls</h4>{lines(page.get("states_tested"))}{lines(page.get("controls_tested"))}</section></div>'
        f'{screenshots_html(page.get("screenshots"), root, str(page.get("title", "Page")))}</article>'
    )


def workflow_evidence(workflow: dict[str, Any], root: Path) -> str:
    return (
        f'<article class="panel"><h3>{esc(workflow.get("name", workflow.get("id", "Workflow")))}</h3>'
        f'<p class="muted">{esc(workflow.get("observed_handoff", workflow.get("notes", "")))}</p>'
        f'{screenshots_html(workflow.get("screenshots"), root, str(workflow.get("name", "Workflow")))}</article>'
    )


def finding_card(finding: dict[str, Any], root: Path) -> str:
    severity = str(finding.get("severity", "unknown"))
    tags = (
        f'<span class="tag {esc(severity)}">{esc(finding.get("category", "finding"))}</span>'
        f'<span class="tag {esc(severity)}">{esc(severity)}</span>'
        f'<span class="tag">{esc(finding.get("confidence", "unknown"))}</span>'
    )
    page_ids = ", ".join(str(item) for item in finding.get("page_ids", []))
    return f'''<article class="finding {esc(severity)}" id="{esc(finding.get("id", "finding"))}">
      <div class="finding-head"><span class="finding-id">{esc(finding.get("id", ""))}</span><div>{tags}</div></div>
      <h3>{esc(finding.get("title", "Untitled finding"))}</h3>
      <p class="summary">{esc(finding.get("summary", ""))}</p>
      <dl class="facts"><dt>Pages</dt><dd><code>{esc(page_ids or "—")}</code></dd>
      <dt>Expected</dt><dd>{esc(finding.get("expected", "—"))}</dd>
      <dt>Actual</dt><dd>{esc(finding.get("actual", "—"))}</dd>
      <dt>Impact</dt><dd>{esc(finding.get("impact", "—"))}</dd>
      <dt>Design signal</dt><dd>{esc(finding.get("design_principle", "—"))}</dd></dl>
      <div class="columns"><section><h4>Reproduction</h4>{lines(finding.get("steps"))}</section>
      <section><h4>Recommendation</h4><p>{esc(finding.get("recommendation", "—"))}</p></section></div>
      <section><h4>Evidence</h4>{evidence_html(finding.get("evidence"), root)}</section>
    </article>'''


def build(data: dict[str, Any], root: Path) -> str:
    meta = data.get("meta", {})
    role_sessions = data.get("role_sessions", [])
    workflow_paths = data.get("workflow_paths", [])
    pages = data.get("pages", [])
    findings = sorted(
        data.get("findings", []),
        key=lambda item: (SEVERITY_ORDER.get(str(item.get("severity")), 9), str(item.get("id", ""))),
    )
    tested = sum(1 for page in pages if page.get("status") == "tested")
    partial = sum(1 for page in pages if page.get("status") == "partially-tested")
    blocked = sum(1 for page in pages if page.get("status") == "blocked")
    title = esc(meta.get("title", "Human tester audit"))
    notes = lines(meta.get("notes"))
    viewports = meta.get("viewports", "—")
    if isinstance(viewports, list):
        viewports = ", ".join(str(item) for item in viewports)
    finding_markup = "".join(finding_card(item, root) for item in findings)
    if not finding_markup:
        finding_markup = (
            '<div class="panel"><p><strong>No findings recorded.</strong></p>'
            f'<p>{esc(meta.get("no_findings_rationale", ""))}</p></div>'
        )
    role_markup = "".join(role_row(role) for role in role_sessions)
    workflow_markup = "".join(workflow_row(workflow) for workflow in workflow_paths)
    page_evidence_markup = "".join(page_evidence(page, root) for page in pages)
    workflow_evidence_markup = "".join(workflow_evidence(workflow, root) for workflow in workflow_paths)
    role_section = '' if not role_markup else f'''<section><h2>Role coverage</h2><div class="panel"><table><thead><tr><th>Alias</th><th>Role</th><th>Status</th><th>Notes</th></tr></thead><tbody>{role_markup}</tbody></table></div></section>'''
    workflow_section = '' if not workflow_markup else f'''<section><h2>Workflow paths</h2><div class="panel"><table><thead><tr><th>ID</th><th>Path</th><th>Actors</th><th>Starting state</th><th>Branch</th><th>Expected handoff</th><th>Observed handoff</th><th>Status</th><th>Final state</th><th>Notes</th></tr></thead><tbody>{workflow_markup}</tbody></table></div></section>'''
    assessment = data.get("workflow_assessment", {})
    assessment_label = "Applicable" if assessment.get("applicable") else "Not applicable"
    fallback_reason = meta.get("browser_fallback_reason")
    fallback_markup = (
        ""
        if not fallback_reason
        else f'<p><strong>Browser fallback:</strong> {esc(fallback_reason)}</p>'
    )
    return f'''<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{title}</title><style>
:root{{--ink:#2e2b28;--muted:#756f69;--line:#e4ddd5;--paper:#fffdf9;--wash:#f5efe8;--accent:#b8583b;--critical:#8f2631;--high:#ad4a2d;--medium:#b57824;--low:#65735a}}
*{{box-sizing:border-box}} body{{margin:0;background:var(--wash);color:var(--ink);font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}} main{{max-width:1180px;margin:auto;padding:36px 24px 72px}} h1,h2,h3,h4{{line-height:1.2;margin:0 0 12px}} h1{{font-size:32px;letter-spacing:-.03em}} h2{{font-size:22px;margin-top:34px}} h3{{font-size:20px}} h4{{font-size:14px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}} p{{margin:0 0 12px}} .muted{{color:var(--muted)}} .hero,.finding,.panel{{background:var(--paper);border:1px solid var(--line);border-radius:14px;box-shadow:0 4px 18px #4b352012}} .hero{{padding:26px 28px}} .meta{{display:flex;flex-wrap:wrap;gap:8px 22px;color:var(--muted);margin-top:8px}} .meta strong{{color:var(--ink)}} .stats{{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-top:22px}} .stat{{padding:14px 16px;background:#faf6f0;border:1px solid var(--line);border-radius:10px}} .stat b{{display:block;font-size:25px}} .stat span{{font-size:12px;color:var(--muted)}} .panel{{padding:20px;margin-top:18px;overflow:auto}} table{{width:100%;border-collapse:collapse;min-width:720px}} th,td{{padding:11px 10px;text-align:left;vertical-align:top;border-bottom:1px solid var(--line)}} th{{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}} code{{font:12px ui-monospace,SFMono-Regular,Consolas,monospace;background:#f0e9e1;padding:2px 5px;border-radius:4px}} .status,.tag{{display:inline-block;padding:3px 8px;border-radius:99px;font-size:11px;font-weight:700;letter-spacing:.02em}} .status.tested{{background:#e4f1e4;color:#416443}} .status.partially-tested{{background:#fff0cf;color:#835e18}} .status.blocked{{background:#f8dddd;color:#8b3030}} .tag{{background:#ece7e0;color:var(--muted);margin-left:5px}} .tag.critical{{background:#f5dce0;color:var(--critical)}} .tag.high{{background:#f8e0d7;color:var(--high)}} .tag.medium{{background:#f8ebce;color:var(--medium)}} .tag.low{{background:#e5eee2;color:var(--low)}} .finding{{padding:22px 24px;margin:16px 0;border-left:5px solid var(--line)}} .finding.critical{{border-left-color:var(--critical)}} .finding.high{{border-left-color:var(--high)}} .finding.medium{{border-left-color:var(--medium)}} .finding.low{{border-left-color:var(--low)}} .finding-head{{display:flex;justify-content:space-between;gap:12px;align-items:center}} .finding-id{{font:700 12px ui-monospace,monospace;color:var(--muted)}} .summary{{font-size:16px}} .facts{{display:grid;grid-template-columns:130px 1fr;gap:7px 14px;margin:18px 0;padding:14px;background:#faf6f0;border-radius:9px}} .facts dt{{font-weight:700;color:var(--muted)}} .facts dd{{margin:0}} .columns{{display:grid;grid-template-columns:1fr 1fr;gap:24px}} ul{{margin:0;padding-left:20px}} .evidence-grid{{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}} figure{{margin:0;border:1px solid var(--line);border-radius:9px;overflow:hidden;background:#f6f1eb}} figure img{{display:block;width:100%;height:auto;background:#e8e0d7}} figcaption{{padding:9px 11px;font-size:12px}} .missing{{padding:30px 14px;text-align:center;color:var(--muted);font-size:12px}} a{{color:var(--accent)}} @media(max-width:720px){{main{{padding:20px 12px 48px}} h1{{font-size:26px}} .stats{{grid-template-columns:repeat(2,1fr)}} .columns{{grid-template-columns:1fr}} .finding-head{{align-items:flex-start;flex-direction:column}} .facts{{grid-template-columns:1fr;gap:2px}} .facts dd{{margin-bottom:8px}}}}
</style></head><body><main>
<header class="hero"><h1>{title}</h1><p class="muted">Screenshot-backed exploratory QA report. Findings are observations against the stated test contract.</p>
<div class="meta"><span><strong>Tested by:</strong> <code>{esc(meta.get("tester_identity", "—"))}</code></span><span><strong>Browser:</strong> {esc(meta.get("browser_tool", "—"))}</span><span><strong>Target:</strong> {esc(meta.get("target", "—"))}</span><span><strong>Target path:</strong> <code>{esc(meta.get("target_path", "—"))}</code></span><span><strong>Environment:</strong> {esc(meta.get("environment", "—"))}</span><span><strong>Role:</strong> {esc(meta.get("role", "—"))}</span><span><strong>Base URL:</strong> {esc(meta.get("base_url", "—"))}</span><span><strong>Viewports:</strong> {esc(viewports)}</span><span><strong>Started:</strong> {esc(meta.get("started_at", "—"))}</span><span><strong>Finished:</strong> {esc(meta.get("finished_at", "—"))}</span></div>
<div class="stats"><div class="stat"><b>{len(pages)}</b><span>pages in scope</span></div><div class="stat"><b>{tested}</b><span>tested</span></div><div class="stat"><b>{partial}</b><span>partially tested</span></div><div class="stat"><b>{blocked}</b><span>blocked</span></div><div class="stat"><b>{len(findings)}</b><span>findings</span></div></div></header>
<section><h2>Scope and notes</h2><div class="panel"><p>{esc(meta.get("scope_note", "—"))}</p><p><strong>Mutation policy:</strong> {esc(meta.get("mutation_policy", "—"))}</p>{fallback_markup}<p><strong>Workflow assessment:</strong> {assessment_label} — {esc(assessment.get("rationale", "—"))}</p>{notes}</div></section>
{role_section}{workflow_section}
<section><h2>Page coverage</h2><div class="panel"><table><thead><tr><th>ID</th><th>Page</th><th>Status</th><th>Primary task</th><th>Notes</th></tr></thead><tbody>{"".join(page_row(page) for page in pages)}</tbody></table></div></section>
<section><h2>Page evidence</h2>{page_evidence_markup}</section>
{'' if not workflow_evidence_markup else f'<section><h2>Workflow evidence</h2>{workflow_evidence_markup}</section>'}
<section><h2>Findings</h2>{finding_markup}</section>
</main></body></html>'''


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", required=True, type=Path)
    parser.add_argument("--output", type=Path, help="Optional; defaults to an identity-bearing filename")
    args = parser.parse_args()
    data, root = read_manifest(args.manifest)
    validate_manifest(data, root)
    meta = data["meta"]
    identity_slug = safe_segment(meta["tester_identity"])
    target_slug = safe_segment(Path(str(meta["target_path"])).name)
    output = args.output or root / f"{target_slug}-{root.name}-{identity_slug}.html"
    if identity_slug not in output.stem:
        raise ValueError(f"Report filename must include tester identity slug: {identity_slug}")
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(build(data, root), encoding="utf-8")
    print(f"Wrote {output} ({len(data.get('findings', []))} findings)")


if __name__ == "__main__":
    main()
