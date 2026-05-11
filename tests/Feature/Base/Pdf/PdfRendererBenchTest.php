<?php

use App\Modules\Core\AI\Services\Browser\PlaywrightRunner;

beforeEach(function () {
    if (env('BLB_PDF_BENCH') !== '1') {
        $this->markTestSkipped('Set BLB_PDF_BENCH=1 to run the PDF render bench (spawns real Chromium).');
    }
});

it('measures Chromium PDF render wall-time over N iterations', function () {
    $iterations = (int) (env('BLB_PDF_BENCH_ITERATIONS') ?: 5);
    expect($iterations)->toBeGreaterThan(0);

    $html = view('pdf.payroll.payslip', [
        'employer' => ['name' => 'Bench Sdn Bhd'],
        'employee' => ['name' => 'Bench Employee', 'identifier' => 'BENCH-001'],
        'payslip' => ['period' => '2026-01', 'run_id' => 1, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'pay_date' => '2026-02-05', 'generated_at' => now()->toIso8601String()],
        'earnings' => [
            ['label' => 'Base salary', 'amount' => 5000],
            ['label' => 'Allowance', 'amount' => 300],
        ],
        'deductions' => [
            ['label' => 'EPF (employee)', 'amount' => 583],
            ['label' => 'SOCSO', 'amount' => 24.75],
            ['label' => 'EIS', 'amount' => 9.90],
        ],
        'totals' => ['gross' => 5300, 'deductions' => 617.65, 'net' => 4682.35],
    ])->render();

    $htmlPath = tempnam(sys_get_temp_dir(), 'blb_pdf_bench_').'.html';
    file_put_contents($htmlPath, $html);
    $htmlUrl = 'file:///'.str_replace('\\', '/', $htmlPath);

    $runner = app(PlaywrightRunner::class);
    $samples = [];
    $outputSize = 0;

    for ($i = 0; $i < $iterations; $i++) {
        $outputPath = tempnam(sys_get_temp_dir(), 'blb_pdf_bench_').'.pdf';
        $start = hrtime(true);
        $result = $runner->execute('pdf', [
            'url' => $htmlUrl,
            'output_path' => $outputPath,
            'format' => 'A4',
            'print_background' => true,
            'timeout_ms' => 30000,
        ]);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        expect($result['ok'] ?? false)->toBeTrue();
        expect(is_file($outputPath))->toBeTrue();
        $size = filesize($outputPath);
        expect($size)->toBeGreaterThan(0);
        $outputSize = max($outputSize, (int) $size);

        $samples[] = $elapsedMs;
        @unlink($outputPath);
    }

    @unlink($htmlPath);

    $cold = $samples[0];
    $warm = array_slice($samples, 1);
    sort($warm);
    $warmMin = $warm === [] ? null : $warm[0];
    $warmMax = $warm === [] ? null : end($warm);
    $warmMedian = $warm === [] ? null : $warm[(int) floor(count($warm) / 2)];

    fwrite(STDOUT, sprintf(
        "\n[BLB PDF Bench] iterations=%d output_size_bytes=%d cold_ms=%.1f warm_min_ms=%s warm_median_ms=%s warm_max_ms=%s\n",
        $iterations,
        $outputSize,
        $cold,
        $warmMin === null ? 'n/a' : sprintf('%.1f', $warmMin),
        $warmMedian === null ? 'n/a' : sprintf('%.1f', $warmMedian),
        $warmMax === null ? 'n/a' : sprintf('%.1f', $warmMax),
    ));
});
