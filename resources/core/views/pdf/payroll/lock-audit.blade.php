<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payroll Lock Audit {{ $report['run']['code'] ?? '' }}</title>
<style>
@page { size: A4 landscape; margin: 14mm; }
body { font-family: Helvetica, Arial, sans-serif; color: #111; font-size: 9pt; }
header { border-bottom: 2px solid #111; padding-bottom: 7pt; margin-bottom: 10pt; }
h1 { font-size: 15pt; margin: 0 0 4pt; }
.muted { color: #555; font-size: 8pt; }
.grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6pt; margin-bottom: 10pt; }
.metric { border: 1px solid #ddd; padding: 5pt; }
.metric strong { display: block; font-size: 11pt; font-variant-numeric: tabular-nums; }
.section-title { font-weight: 700; margin: 10pt 0 4pt; }
table { width: 100%; border-collapse: collapse; page-break-inside: auto; }
th, td { padding: 4pt 5pt; border-bottom: 1px solid #e5e5e5; text-align: left; vertical-align: top; }
th { background: #f5f5f5; font-size: 8pt; text-transform: uppercase; }
.amount { text-align: right; font-variant-numeric: tabular-nums; }
</style>
</head>
<body>
<header>
    <h1>Payroll Lock Audit Report</h1>
    <div class="muted">{{ $report['run']['name'] ?? '' }} · {{ $report['run']['period_name'] ?? ($report['run']['period'] ?? '') }} · Status {{ $report['run']['status'] ?? '' }}</div>
</header>

@php($controls = $report['controls'] ?? [])
@php($lock = $report['lock_state'] ?? [])
<div class="grid">
    <div class="metric"><span>Participants</span><strong>{{ $controls['participants_count'] ?? 0 }}</strong></div>
    <div class="metric"><span>Result lines</span><strong>{{ $controls['result_lines_count'] ?? 0 }}</strong></div>
    <div class="metric"><span>Audit events</span><strong>{{ $controls['audit_events_count'] ?? 0 }}</strong></div>
    <div class="metric"><span>Reviewed</span><strong>{{ ($lock['is_reviewed'] ?? false) ? 'Yes' : 'No' }}</strong></div>
    <div class="metric"><span>Locked</span><strong>{{ ($lock['is_locked'] ?? false) ? 'Yes' : 'No' }}</strong></div>
</div>

<div class="section-title">Lifecycle Timestamps</div>
<table>
    <thead><tr><th>Calculated</th><th>Reviewed</th><th>Approved</th><th>Closed</th><th>Voided</th></tr></thead>
    <tbody>
        <tr>
            <td>{{ $lock['calculated_at'] ?? '—' }}</td>
            <td>{{ $lock['reviewed_at'] ?? '—' }}</td>
            <td>{{ $lock['approved_at'] ?? '—' }}</td>
            <td>{{ $lock['closed_at'] ?? '—' }}</td>
            <td>{{ $lock['voided_at'] ?? '—' }}</td>
        </tr>
    </tbody>
</table>

<div class="section-title">Result Line Totals</div>
<table>
    <thead><tr><th>Line Type</th><th class="amount">Count</th><th class="amount">Amount</th></tr></thead>
    <tbody>
        @foreach (($report['totals_by_line_type'] ?? []) as $total)
            <tr><td>{{ $total['type'] }}</td><td class="amount">{{ $total['count'] }}</td><td class="amount">{{ number_format((float) $total['amount'], 2) }}</td></tr>
        @endforeach
    </tbody>
</table>

<div class="section-title">Participants</div>
<table>
    <thead><tr><th>Employee</th><th>Status</th><th class="amount">Gross</th><th class="amount">Deductions</th><th class="amount">Reimb.</th><th class="amount">Net</th><th class="amount">Lines</th></tr></thead>
    <tbody>
        @foreach (($report['participants'] ?? []) as $participant)
            <tr>
                <td>{{ $participant['employee']['name'] }}<br><span class="muted">{{ $participant['employee']['number'] }}</span></td>
                <td>{{ $participant['status'] }}</td>
                <td class="amount">{{ number_format((float) $participant['gross_pay'], 2) }}</td>
                <td class="amount">{{ number_format((float) $participant['total_deductions'], 2) }}</td>
                <td class="amount">{{ number_format((float) $participant['total_reimbursements'], 2) }}</td>
                <td class="amount">{{ number_format((float) $participant['net_pay'], 2) }}</td>
                <td class="amount">{{ $participant['result_lines_count'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="section-title">Audit Events</div>
<table>
    <thead><tr><th>When</th><th>Action</th><th>User</th><th>Message</th></tr></thead>
    <tbody>
        @foreach (($report['audit_events'] ?? []) as $event)
            <tr>
                <td>{{ $event['occurred_at'] ?? '—' }}</td>
                <td>{{ $event['action'] }}</td>
                <td>{{ $event['user'] ?? '—' }}</td>
                <td>{{ $event['message'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
</body>
</html>
