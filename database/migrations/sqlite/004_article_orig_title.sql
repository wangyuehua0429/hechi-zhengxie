-- 河池政协网 · 004：原标题（引题／标题／副题）与首页模块高亮、徽标（SQLite）
-- 与 database/migrations/mysql/004_article_orig_title.sql 同构，差异仅限方言。
-- 背景：
--   1) 稿件编辑页不再有引题／副标题与摘要，改由「原标题」三项承担报纸式标题，
--      保存时拼进正文最前面，不进网页标题与首页模块；
--   2) 首页模块的「高亮」「徽标」与「置顶」同层（挂在栏目归属上），
--      同一篇稿在不同栏目可分别设置。

ALTER TABLE cms_article ADD COLUMN orig_kicker TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_article ADD COLUMN orig_title TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_article ADD COLUMN orig_subtitle TEXT NOT NULL DEFAULT '';

ALTER TABLE cms_article_channel ADD COLUMN is_highlight INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cms_article_channel ADD COLUMN badge_text TEXT NOT NULL DEFAULT '';
