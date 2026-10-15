<ul>
<?php foreach ($items as $item): ?>
<?= $this->render('item', ['item' => $item]) ?>
<?php endforeach ?>
</ul>
