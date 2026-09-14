<?php

/**
 * 门户提示页（无权限、找不到、提交被拒等）。
 *
 * @var string $heading
 * @var string $message
 * @var string $backUrl
 */

declare(strict_types=1);
?>
<section class="m-card m-narrow">
  <h1><?= hechi_e($heading) ?></h1>
  <p><?= hechi_e($message) ?></p>
  <p><a class="m-btn" href="<?= hechi_e($backUrl) ?>">返回</a></p>
</section>
