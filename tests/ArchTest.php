<?php

test('composer.json requires only php', function () {
	$composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

	expect(array_keys($composer['require']))->toBe(['php']);
});
