<?php $this->component('layout') ?>
<?php $this->slot('title', $this->yield('title')) ?>
<section><?= $this->yield() ?></section>
<?php $this->end() ?>
