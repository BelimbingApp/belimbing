<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payslip {{ $payslip['period']['code'] ?? '' }}</title>
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
        <div class="muted">Payslip for {{ $payslip['period']['name'] ?? ($payslip['period']['code'] ?? '—') }}</div>
    </div>
    <div class="muted" style="text-align: right;">
        <div>Employee #{{ $payslip['employee']['number'] ?? '—' }}</div>
        <div>Generated: {{ now()->toIso8601String() }}</div>
    </div>
</header>

<div class="grid">
    <div>
        <div class="section-title">Employee</div>
        <div>{{ $payslip['employee']['name'] ?? 'Unknown' }}</div>
        <div class="muted">{{ $payslip['employee']['number'] ?? '' }}</div>
    </div>
    <div>
        <div class="section-title">Pay Period</div>
        <div>{{ $payslip['period']['starts_on'] ?? '—' }} to {{ $payslip['period']['ends_on'] ?? '—' }}</div>
        <div class="muted">Pay date: {{ $payslip['period']['pay_date'] ?? '—' }}</div>
    </div>
</div>

<div class="section-title">Earnings</div>
<table>
    <thead>
        <tr><th>Item</th><th class="amount">Amount (MYR)</th></tr>
    </thead>
    <tbody>
        @forelse (($payslip['sections']['earnings'] ?? []) as $line)
            <tr>
                <td>{{ $line['label'] }}</td>
                <td class="amount">{{ number_format((float) $line['amount'], 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="2" class="muted">No earnings</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr><td>Gross</td><td class="amount">{{ number_format((float) ($payslip['summary']['gross_pay'] ?? 0), 2) }}</td></tr>
    </tfoot>
</table>

<div class="section-title">Deductions</div>
<table>
    <thead>
        <tr><th>Item</th><th class="amount">Amount (MYR)</th></tr>
    </thead>
    <tbody>
        @foreach (array_merge($payslip['sections']['employee_deductions'] ?? [], $payslip['sections']['employee_contributions'] ?? [], $payslip['sections']['taxes'] ?? []) as $line)
            <tr>
                <td>{{ $line['label'] }}</td>
                <td class="amount">{{ number_format((float) $line['amount'], 2) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr><td>Total deductions</td><td class="amount">{{ number_format((float) ($payslip['summary']['total_deductions'] ?? 0), 2) }}</td></tr>
    </tfoot>
</table>

@if (($payslip['sections']['reimbursements'] ?? []) !== [])
<div class="section-title">Reimbursements</div>
<table>
    <thead><tr><th>Item</th><th class="amount">Amount ({{ $payslip['currency'] ?? 'MYR' }})</th></tr></thead>
    <tbody>
        @foreach (($payslip['sections']['reimbursements'] ?? []) as $line)
            <tr><td>{{ $line['label'] }}</td><td class="amount">{{ number_format((float) $line['amount'], 2) }}</td></tr>
        @endforeach
    </tbody>
    <tfoot><tr><td>Total reimbursements</td><td class="amount">{{ number_format((float) ($payslip['summary']['total_reimbursements'] ?? 0), 2) }}</td></tr></tfoot>
</table>
@endif

@if (($payslip['sections']['employer_contributions'] ?? []) !== [] || ($payslip['sections']['employer_levies'] ?? []) !== [])
<div class="section-title">Employer Contributions And Levies</div>
<table>
    <thead><tr><th>Item</th><th class="amount">Amount ({{ $payslip['currency'] ?? 'MYR' }})</th></tr></thead>
    <tbody>
        @foreach (array_merge($payslip['sections']['employer_contributions'] ?? [], $payslip['sections']['employer_levies'] ?? []) as $line)
            <tr><td>{{ $line['label'] }}</td><td class="amount">{{ number_format((float) $line['amount'], 2) }}</td></tr>
        @endforeach
    </tbody>
    <tfoot><tr><td>Total employer statutory cost</td><td class="amount">{{ number_format((float) (($payslip['summary']['employer_contributions'] ?? 0) + ($payslip['summary']['employer_levies'] ?? 0)), 2) }}</td></tr></tfoot>
</table>
@endif

<div class="section-title">Net pay</div>
<table>
    <thead>
        <tr><th>Item</th><th class="amount">Amount (MYR)</th></tr>
    </thead>
    <tbody>
        <tr><td>Net pay</td><td class="amount">{{ number_format((float) ($payslip['summary']['net_pay'] ?? 0), 2) }}</td></tr>
    </tbody>
</table>

<footer>
    Generated from immutable payroll result lines. Employer contributions and levies are shown for disclosure and are not deducted from net pay.
</footer>
</body>
</html>
