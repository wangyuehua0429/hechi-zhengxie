-- 河池政协网 · 004：原标题（引题／标题／副题）与首页模块高亮、徽标（MySQL 8）
-- 与 database/migrations/sqlite/004_article_orig_title.sql 同构，差异仅限方言
-- （MySQL 的 TEXT 不能带默认值，这里用 VARCHAR）。

ALTER TABLE cms_article ADD COLUMN orig_kicker VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE cms_article ADD COLUMN orig_title VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE cms_article ADD COLUMN orig_subtitle VARCHAR(255) NOT NULL DEFAULT '';

ALTER TABLE cms_article_channel ADD COLUMN is_highlight TINYINT NOT NULL DEFAULT 0;
ALTER TABLE cms_article_channel ADD COLUMN badge_text VARCHAR(64) NOT NULL DEFAULT '';
