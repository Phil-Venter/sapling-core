<?php

use Sapling\CrashLog;
use Sapling\Http\HttpException;

test('a message with line breaks stays one log line', function () {
	$forged = new RuntimeException("boom\nGET /admin failed: nothing to see\r\nhere");

	[, $log] = captureErrorLog(fn() => new CrashLog(FIXTURES)->write('GET /notes', $forged));

	expect($log)->toHaveCount(1);
	expect($log[0])->toContain('GET /notes failed: RuntimeException: boom GET /admin failed: nothing to see  here in ');
});

test('a previous exception follows as the cause', function () {
	$refused = new RuntimeException('Connection refused');
	$down = new HttpException(503, 'Billing API is down', previous: $refused);

	[, $log] = captureErrorLog(fn() => new CrashLog(FIXTURES)->write('GET /invoices', $down));

	expect($log)->toHaveCount(1);
	expect($log[0])->toContain('GET /invoices failed: 503: Billing API is down in ');
	expect($log[0])->toContain(', caused by RuntimeException: Connection refused in ' . __FILE__ . ':');
});
