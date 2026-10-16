<?php $this->layout('layout') ?>
<?php $this->section('title') ?>Broken<?php $this->end() ?>
<?php $this->section('aside') ?>half a section<?php throw new RuntimeException('template failed') ?>
