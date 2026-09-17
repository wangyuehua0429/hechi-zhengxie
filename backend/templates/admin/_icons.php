<?php

/**
 * 后台内联 SVG 图标（不引第三方图标库、不依赖外部 CDN）。
 *
 * 用「返回数组」而不是定义函数：模板可能在同一请求里被包含多次，
 * 定义函数会撞上重复声明，返回数组则每次都是干净的。
 *
 * 用法：$adminIcons = require __DIR__ . '/_icons.php'; 然后 <?= $adminIcons['dashboard'] ?>
 * 约定：一律 currentColor + 描边，颜色跟随文字，尺寸写死在各自图标上。
 *
 * @return array<string, string>
 */

declare(strict_types=1);

$attrs = 'fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';

return [
    // 侧栏五个一级菜单
    'dashboard' => '<svg class="ico" width="16" height="16" viewBox="0 0 24 24" ' . $attrs . '><rect x="3.5" y="3.5" width="7" height="7" rx="1.6"></rect><rect x="13.5" y="3.5" width="7" height="7" rx="1.6"></rect><rect x="3.5" y="13.5" width="7" height="7" rx="1.6"></rect><rect x="13.5" y="13.5" width="7" height="7" rx="1.6"></rect></svg>',
    'home' => '<svg class="ico" width="16" height="16" viewBox="0 0 24 24" ' . $attrs . '><path d="M4 10.6 12 4l8 6.6V19a1 1 0 0 1-1 1h-4.2v-6H9.2v6H5a1 1 0 0 1-1-1z"></path></svg>',
    'articles' => '<svg class="ico" width="16" height="16" viewBox="0 0 24 24" ' . $attrs . '><path d="M4.5 5.5A1.5 1.5 0 0 1 6 4h7.5L19 9.5v9A1.5 1.5 0 0 1 17.5 20H6a1.5 1.5 0 0 1-1.5-1.5z"></path><path d="M13.5 4v5.5H19"></path><path d="M8 13.5h7"></path><path d="M8 16.5h4.5"></path></svg>',
    'users' => '<svg class="ico" width="16" height="16" viewBox="0 0 24 24" ' . $attrs . '><circle cx="9.5" cy="8.5" r="3"></circle><path d="M4.5 19.5a5 5 0 0 1 10 0"></path><path d="M15.5 6.3a2.8 2.8 0 0 1 0 5.4"></path><path d="M16.6 14.4a4.6 4.6 0 0 1 2.9 4.2"></path></svg>',
    'logs' => '<svg class="ico" width="16" height="16" viewBox="0 0 24 24" ' . $attrs . '><circle cx="12" cy="12" r="8"></circle><path d="M12 7.6V12l3 1.8"></path></svg>',

    // 空状态
    'empty' => '<svg width="30" height="30" viewBox="0 0 24 24" ' . $attrs . '><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h8L19 9.5v9A1.5 1.5 0 0 1 17.5 20h-12A1.5 1.5 0 0 1 4 18.5z"></path><path d="M13.5 4v6H19"></path><path d="M9 14.5h6"></path></svg>',

    // 操作结果提示
    'flash-ok' => '<svg width="18" height="18" viewBox="0 0 24 24" ' . $attrs . '><circle cx="12" cy="12" r="8.5"></circle><path d="M8.5 12.2l2.4 2.4 4.6-4.9"></path></svg>',
    'flash-error' => '<svg width="18" height="18" viewBox="0 0 24 24" ' . $attrs . '><circle cx="12" cy="12" r="8.5"></circle><path d="M12 7.8v4.8"></path><path d="M12 16.2h.01"></path></svg>',

    // 顶栏的深浅色开关（两个图标，按当前主题显示其一）
    'sun' => '<svg class="theme-icon theme-icon--sun" width="16" height="16" viewBox="0 0 24 24" ' . $attrs . '><circle cx="12" cy="12" r="4.2"></circle><path d="M12 3.6v2.2"></path><path d="M12 18.2v2.2"></path><path d="M3.6 12h2.2"></path><path d="M18.2 12h2.2"></path><path d="M6.1 6.1l1.6 1.6"></path><path d="M16.3 16.3l1.6 1.6"></path><path d="M17.9 6.1l-1.6 1.6"></path><path d="M7.7 16.3l-1.6 1.6"></path></svg>',
    'moon' => '<svg class="theme-icon theme-icon--moon" width="16" height="16" viewBox="0 0 24 24" ' . $attrs . '><path d="M20 14.4A8.2 8.2 0 0 1 9.6 4a7 7 0 1 0 10.4 10.4z"></path></svg>',
];
