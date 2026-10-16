<?php $this->component('layout') ?>
<?php $this->slot('title') ?>Page <?= e($name);
$this->end() ?>
<p>Hello <?= e($name) ?></p>
<?php $this->end() ?>
