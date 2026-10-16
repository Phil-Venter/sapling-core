<?php $this->component('layout') ?>
<?php $this->slot('title') ?>Broken<?php $this->end() ?>
<?php $this->slot('aside') ?>half a slot<?php throw new RuntimeException('template failed') ?>
