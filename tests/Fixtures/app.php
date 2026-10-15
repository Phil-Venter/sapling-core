<?php

require __DIR__ . '/../../vendor/autoload.php';

$app = new Sapling\App(__DIR__);
$app->scan(__DIR__ . '/Scan', 'Sapling\\Tests\\Fixtures\\Scan\\');
$app->run();
