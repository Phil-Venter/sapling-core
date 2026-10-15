<?php

use Sapling\CrashLog;

test('a message with line breaks stays one log line, so it cannot forge another entry', function () {
	$forged = new RuntimeException("boom\nGET /admin failed: nothing to see\r\nhere");

	[, $log] = captureErrorLog(fn() => new CrashLog(FIXTURES)->write('GET /notes', $forged));

	expect($log)->toHaveCount(1);
	expect($log[0])->toContain('GET /notes failed: RuntimeException: boom GET /admin failed: nothing to see  here in ');
});
