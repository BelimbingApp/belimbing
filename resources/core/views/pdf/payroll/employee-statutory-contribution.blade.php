<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Employee Statutory Contributions {{ $report['run']['code'] ?? '' }}</title>
<style>
@page { size: A4 landscape; margin: 14mm; }
body { font-family: Helvetica, Arial, sans-serif; color: #111; font-size: 9pt; }
header { border-bottom: 2px solid #111; padding-bottom: 7pt; margin-bottom: 10pt; }
h1 { font-size: 15pt; margin: 0 0 4pt; }
.muted { color: #555; font-size: 8pt; }
.section-title { font-weight: 700; margin: 12pt 0 4pt; }
table { width: 100%; border-collapse: collapse; page-break-inside: auto; }
th, td { padding: 4pt 5pt; border-bottom: 1px solid #e5e5e5; text-align: left; vertical-align: top; }
th { background: #f5f5f5; font-size: 8pt; text-transform: uppercase; }
.amount { text-align: right; font-variant-numeric: tabular-nums; }
</style>
</head>
<body>
<header>
    <h1>Employee Statutory Contributions</h1>
    <div class="muted">{{ $report['run']['name'] ?? '' }} · {{ $report['run']['period'] ?? '' }} · Pay date {{ $report['run']['pay_date'] ?? '' }}</div>
</header>

<div class="section-title">Totals By Code</div>
<table>
    <thead><tr><th>Code</th><th>Label</th><th>Type</th><th class="amount">Amount</th></tr></thead>
    <tbody>
        @foreach (($report['totals_by_code'] ?? []) as $total)
            <tr>
                <td>{{ $total['code'] }}</td>
                <td>{{ $total['label'] }}</td>
                <td>{{ $total['type'] }}</td>
                <td class="amount">{{ number_format((float) $total['amount'], 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="section-title">Employee Lines</div>
<table>
    <thead><tr><th>Employee</th><th>Code</th><th>Label</th><th>Rule</th><th>Version</th><th class="amount">Amount</th></tr></thead>
    <tbody>
        @foreach (($report['participants'] ?? []) as $participant)
            @foreach (($participant['lines'] ?? []) as $line)
                <tr>
                    <td>{{ $participant['employee']['name'] }}<br><span class="muted">{{ $participant['employee']['number'] }}</span></td>
                    <td>{{ $line['code'] }}</td>
                    <td>{{ $line['label'] }}</td>
                    <td>{{ $line['source_rule'] }}</td>
                    <td>{{ $line['source_version'] }}</td>
                    <td class="amount">{{ number_format((float) $line['amount'], 2) }}</td>
                </tr>
            @endforeach
        @endforeach
    </tbody>
</table>
</body>
</html>