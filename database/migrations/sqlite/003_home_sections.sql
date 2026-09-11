-- 河池政协网 · 003：首页四大类管理（SQLite）
-- 与 database/migrations/mysql/003_home_sections.sql 同构，差异仅限方言。
-- 背景见 docs/后台首页四大类管理说明.md：
--   1) cms_article_channel 增 is_top，支持「按栏目置顶」，首页模块与栏目页共用一套顺序；
--   2) cms_home_section 存首页各稿件模块的绑定栏目与取几条，列表由稿件现算；
--   3) cms_home_slide 存首屏头条轮换（可引用稿件，也可手填外链）；
--   4) cms_home_banner 存站内横幅的固定槽位。

ALTER TABLE cms_article_channel ADD COLUMN is_top INTEGER NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS idx_ac_top ON cms_article_channel (site_id, channel_type, is_top, sort_no);

CREATE TABLE IF NOT EXISTS cms_home_section (
  section_key TEXT NOT NULL,
  site_id     INTEGER NOT NULL DEFAULT 1,
  label       TEXT NOT NULL DEFAULT '',
  scope_json  TEXT NOT NULL DEFAULT '{}',
  page_size   INTEGER NOT NULL DEFAULT 10,
  group_by    TEXT NOT NULL DEFAULT '',
  more_url    TEXT NOT NULL DEFAULT '',
  status      TEXT NOT NULL DEFAULT 'published',
  sort_no     INTEGER NOT NULL DEFAULT 0,
  created_at  TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  PRIMARY KEY (site_id, section_key)
);

CREATE TABLE IF NOT EXISTS cms_home_slide (
  slide_id   INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id    INTEGER NOT NULL DEFAULT 1,
  article_id INTEGER NOT NULL DEFAULT 0,
  title      TEXT NOT NULL DEFAULT '',
  summary    TEXT NOT NULL DEFAULT '',
  image_url  TEXT NOT NULL DEFAULT '',
  link_url   TEXT NOT NULL DEFAULT '',
  sort_no    INTEGER NOT NULL DEFAULT 0,
  status     TEXT NOT NULL DEFAULT 'published',
  created_by INTEGER NOT NULL DEFAULT 0,
  updated_by INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX IF NOT EXISTS idx_slide_sort ON cms_home_slide (site_id, status, sort_no);

CREATE TABLE IF NOT EXISTS cms_home_banner (
  banner_id  INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id    INTEGER NOT NULL DEFAULT 1,
  slot_key   TEXT NOT NULL,
  title      TEXT NOT NULL DEFAULT '',
  image_url  TEXT NOT NULL DEFAULT '',
  link_url   TEXT NOT NULL DEFAULT '',
  sort_no    INTEGER NOT NULL DEFAULT 0,
  status     TEXT NOT NULL DEFAULT 'published',
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  UNIQUE (site_id, slot_key)
);

INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'home.manage' FROM sys_role WHERE code = 'admin';
