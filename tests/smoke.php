<?php
declare(strict_types=1);
require __DIR__ . '/../app/core.php';

function assert_same(mixed $expected, mixed $actual, string $message): void {
    if ($actual !== $expected) {
        fwrite(STDERR, $message . ' Expected ' . var_export($expected, true) . ' got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

assert_same('2026-02-28', adc_next_month('2026-01-31', 31), 'Short month clamp');
assert_same('2026-03-31', adc_next_month('2026-02-28', 31), 'Preserve original day');
assert_same('2028-02-29', adc_next_month('2028-01-31', 31), 'Leap-year February');
assert_same(true, adc_valid_date('2026-10-21'), 'Valid date');
assert_same(false, adc_valid_date('2026-02-30'), 'Invalid date');
assert_same('c.is_demo = 0', adc_demo_analytics_predicate('c'), 'Demo excluded from analytics');
echo "Smoke tests passed\n";
