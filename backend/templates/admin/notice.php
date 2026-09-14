<?php

/**
 * 滚动公告：首页搜索框左侧那条滚动要闻（home.meta.marquee）。
 *
 * @var string $marquee
 * @var bool $hidden
 * @var int $maxLength
 */

declare(strict_types=1);

$length = mb_strlen($marquee, 'UTF-8');
?>
<div class="page-head">
  <div class="page-title">
    <h1>滚动公告</h1>
    <p class="subtitle">
      首页导航条下方、搜索框左侧那条滚动文字就是这里维护的（前台读 <code>home.meta.marquee</code>）。
      留空则前台显示「暂无要闻」，滚条下方就是搜索框与首屏头条。
    </p>
  </div>
  <div class="head-actions">
    <a class="btn" href="/" target="_blank" rel="noopener">打开前台首页</a>
    <a class="btn btn-ghost" href="/admin/nav">导航栏目</a>
  </div>
</div>

<section class="card">
  <div class="card-head">
    <h2>公告文字</h2>
    <span class="tag tag-<?= $hidden ? 'offline' : 'published' ?>"><?= $hidden ? '已停用' : '显示中' ?></span>
  </div>
  <p class="hint-line">
    公告在首页是一条横向滚动的文字，换行与连续空格会被收成单个空格；文字越长，滚完一圈用的时间越久。
    当前 <?= $length ?> / <?= (int) $maxLength ?> 字。
  </p>
  <form method="post" action="/admin/notice" class="edit-form"
        data-confirm="保存后首页滚动公告立刻更新，确认保存？">
    <?= $csrf ?>
    <label class="full">
      <span class="visually-hidden">公告文字</span>
      <textarea name="marquee" rows="4" maxlength="<?= (int) $maxLength ?>"
                placeholder="例如：9月10日，市政协召开五届第68次主席会议，研究部署近期重点工作。"><?= hechi_e($marquee) ?></textarea>
    </label>
    <label class="check-line">
      <input type="checkbox" name="hidden" value="1"<?= $hidden ? ' checked' : '' ?>>
      停用并隐藏这条滚条（连喇叭图标一起收掉）
    </label>
    <p class="hint-line">
      停用后前台首页不再显示滚条（连喇叭图标一起收掉），那一行只剩搜索框：位置、宽度都不变，
      仍靠右、仍在原处（宽度由 341px 放宽到 560px），横栏高度也不变；公告文字仍保留在这里，取消勾选保存即恢复。
    </p>
    <div class="actions">
      <button type="submit" class="btn-primary">保存公告</button>
      <span class="muted">最多 <?= (int) $maxLength ?> 字；清空文字后前台显示「暂无要闻」。</span>
    </div>
  </form>
</section>

<section class="card">
  <div class="card-head">
    <h2>前台效果</h2>
    <span class="muted"><?= $hidden ? '已停用' : '静态预览' ?></span>
  </div>
  <?php if ($hidden): ?>
    <div class="notice-preview is-empty">
      <span class="notice-preview-text">已停用：前台首页不显示这条滚条（连喇叭图标一起收掉），那一行只剩搜索框，位置与宽度不变。</span>
    </div>
  <?php else: ?>
    <div class="notice-preview<?= $marquee === '' ? ' is-empty' : '' ?>">
      <span class="notice-preview-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5"/>
          <circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5"/>
          <path d="M19.1 4.9c3.9 3.9 3.9 10.2 0 14.1"/>
        </svg>
      </span>
      <span class="notice-preview-text"><?= $marquee === '' ? '暂无要闻' : hechi_e($marquee) ?></span>
    </div>
  <?php endif; ?>
</section>
