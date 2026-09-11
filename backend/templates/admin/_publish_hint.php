<?php

/**
 * 首页四大类页面的公共提示：前台走实时接口，静态化产物要单独发布。
 *
 * 这个提示是有来历的：改完轮播后前台没变，十有八九是看的不是走接口的站点页面，
 * 而是阶段 A 的样例快照（frontend/home/data/*.json）或上一次的发布产物。
 */

declare(strict_types=1);
?>
<p class="muted publish-hint">
  <strong>改动立即生效：</strong>站点页面（首页与内页）走实时接口，保存后刷新页面就能看到。
  静态化产物——数据快照、栏目页与稿件静态页、sitemap——不会自动跟着更新，需要时回「概览」点一次「立即发布全站」。
  <br>
  排查用：在站点页面控制台执行 <code>SITE_DATA.mode()</code>，返回 <code>api</code> 说明走的是实时接口；
  返回 <code>static</code> 说明读的是旧快照（地址栏加 <code>?api=1</code> 可强制走接口）。
</p>
