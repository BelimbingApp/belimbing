<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Employer Cost {{ $report['run']['code'] ?? '' }}</title>
<style>
@page { size: A4; margin: 16mm; }
body { font-family: Helvetica, Arial, sans-serif; color: #111; font-size: 10pt; }
header { border-bottom: 2px solid #111; padding-bottom: 8pt; margin-bottom: 12pt; }
h1 { font-size: 16pt; margin: 0 0 4pt; }
.muted { color: #555; font-size: 9pt; }
.grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8pt; margin-bottom: 12pt; }
.metric { border: 1px solid #ddd; padding: 6pt; }
.metric strong { display: block; font-size: 12pt; font-variant-numeric: tabular-nums; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 4pt 5pt; border-bottom: 1px solid #e5e5e5; text-align: left; }
th { background: #f5f5f5; font-size: 8pt; text-transform: uppercase; }
.amount { text-align: right; font-variant-numeric: tabular-nums; }
</style>
</head>
<body>
<header>
    <h1>Employer Cost Report</h1>
    <div class="muted">{{ $report['run']['name'] ?? '' }} · {{ $report['run']['period'] ?? '' }} · Pay date {{ $report['run']['pay_date'] ?? '' }}</div>
</header>

@php($totals = $report['totals'] ?? [])
<div class="grid">
    <div class="metric"><span>Gross pay</span><strong>{{ number_format((float) ($totals['gross_pay'] ?? 0), 2) }}</strong></div>
    <div class="metric"><span>Reimbursements</span><strong>{{ number_format((float) ($totals['reimbursements'] ?? 0), 2) }}</strong></div>
    <div class="metric"><span>Employer statutory</span><strong>{{ number_format((float) (($totals['employer_contributions'] ?? 0) + ($totals['employer_levies'] ?? 0)), 2) }}</strong></div>
    <div class="metric"><span>Total employer cost</span><strong>{{ number_format((float) ($totals['total_employer_cost'] ?? 0), 2) }}</strong></div>
</div>

<table>
    <thead>
        <tr><th>Employee</th><th class="amount">Gross</th><th class="amount">Reimb.</th><th class="amount">Contributions</th><th class="amount">Levies</th><th class="amount">Total Cost</th></tr>
    </thead>
    <tbody>
        @foreach (($report['participants'] ?? []) as $participant)
            <tr>
                <td>{{ $participant['employee']['name'] }}<br><span class="muted">{{ $participant['employee']['number'] }}</span></td>
                <td class="amount">{{ number_format((float) $participant['gross_pay'], 2) }}</td>
                <td class="amount">{{ number_format((float) $participant['reimbursements'], 2) }}</td>
                <td class="amount">{{ number_format((float) $participant['employer_contributions'], 2) }}</td>
                <td class="amount">{{ number_format((float) $participant['employer_levies'], 2) }}</td>
                <td class="amount">{{ number_format((float) $participant['total_employer_cost'], 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
</body>
</html>
