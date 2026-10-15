<ul>
<?php foreach ($items as $item): ?>
<?= $this->include('item', ['item' => $item]) ?>
<?php endforeach ?>
</ul>
