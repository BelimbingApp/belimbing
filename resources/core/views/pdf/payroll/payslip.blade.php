<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payslip {{ $payslip['period'] ?? '' }}</title>
<style>
@page { size: A4; margin: 18mm 16mm; }
body { font-family: 'Helvetica', 'Arial', sans-serif; color: #111; font-size: 11pt; }
header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 8pt; margin-bottom: 14pt; }
h1 { font-size: 16pt; margin: 0; }
.muted { color: #555; font-size: 9pt; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10pt; margin-bottom: 14pt; }
.section-title { font-weight: 600; font-size: 10pt; margin: 14pt 0 4pt; letter-spacing: 0.04em; text-transform: uppercase; color: #444; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 4pt 6pt; text-align: left; border-bottom: 1px solid #e5e5e5; }
th { background: #f5f5f5; font-size: 9pt; text-transform: uppercase; letter-spacing: 0.04em; }
td.amount, th.amount { text-align: right; font-variant-numeric: tabular-nums; }
tfoot td { font-weight: 600; border-top: 2px solid #111; }
footer { margin-top: 22pt; font-size: 8pt; color: #777; border-top: 1px solid #ddd; padding-top: 6pt; }
</style>
</head>
<body>
<header>
    <div>
        <h1>{{ $employer['name'] ?? 'Belimbing Employer' }}</h1>
        <div class="muted">Payslip for {{ $payslip['period'] ?? '—' }}</div>
    </div>
    <div class="muted" style="text-align: right;">
        <div>Payroll Run #{{ $payslip['run_id'] ?? '—' }}</div>
        <div>Generated: {{ $payslip['generated_at'] ?? now()->toIso8601String() }}</div>
    </div>
</header>

<div class="grid">
    <div>
        <div class="section-title">Employee</div>
        <div>{{ $employee['name'] ?? (auth()->user()->name ?? 'Unknown') }}</div>
        <div class="muted">{{ $employee['identifier'] ?? '' }}</div>
        <div class="muted">Rendered as user #{{ auth()->id() ?? '—' }}</div>
    </div>
    <div>
        <div class="section-title">Pay Period</div>
        <div>{{ $payslip['period_start'] ?? '—' }} → {{ $payslip['period_end'] ?? '—' }}</div>
        <div class="muted">Pay date: {{ $payslip['pay_date'] ?? '—' }}</div>
    </div>
</div>

<div class="section-title">Earnings</div>
<table>
    <thead>
        <tr><th>Item</th><th class="amount">Amount (MYR)</th></tr>
    </thead>
    <tbody>
        @foreach ($earnings ?? [] as $line)
            <tr>
                <td>{{ $line['label'] }}</td>
                <td class="amount">{{ number_format((float) $line['amount'], 2) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr><td>Gross</td><td class="amount">{{ number_format((float) ($totals['gross'] ?? 0), 2) }}</td></tr>
    </tfoot>
</table>

<div class="section-title">Deductions</div>
<table>
    <thead>
        <tr><th>Item</th><th class="amount">Amount (MYR)</th></tr>
    </thead>
    <tbody>
        @foreach ($deductions ?? [] as $line)
            <tr>
                <td>{{ $line['label'] }}</td>
                <td class="amount">{{ number_format((float) $line['amount'], 2) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr><td>Total deductions</td><td class="amount">{{ number_format((float) ($totals['deductions'] ?? 0), 2) }}</td></tr>
    </tfoot>
</table>

<div class="section-title">Net pay</div>
<table>
    <tbody>
        <tr><td>Net pay</td><td class="amount">{{ number_format((float) ($totals['net'] ?? 0), 2) }}</td></tr>
    </tbody>
</table>

<footer>
    Phase 1 spike — template and data shape are placeholders. Real payslip data wiring lands in Phase 3.
</footer>
</body>
</html>
