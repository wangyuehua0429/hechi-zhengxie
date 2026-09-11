<?php

/**
 * 通用提示页。
 *
 * @var string $heading
 * @var string $message
 * @var string $backUrl
 */

declare(strict_types=1);
?>
<h1><?= hechi_e($heading) ?></h1>
<div class="card">
  <p><?= hechi_e($message) ?></p>
  <p><a class="btn" href="<?= hechi_e($backUrl) ?>">返回</a></p>
</div>
