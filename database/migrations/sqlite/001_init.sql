-- 河池政协网 · 初始表结构（SQLite，本地开发与自动化检查用）
-- 与 database/migrations/mysql/001_init.sql 同构，差异仅限方言：
--   * INTEGER PRIMARY KEY AUTOINCREMENT 代替 INT AUTO_INCREMENT
--   * JSON/MEDIUMTEXT 一律用 TEXT（读写仍按 JSON 字符串处理）
--   * 不支持 ON UPDATE CURRENT_TIMESTAMP，updated_at 由应用层写入
--   * 不建 ngram 全文索引，站内检索在 SQLite 下退化为 LIKE（接口不变）

CREATE TABLE IF NOT EXISTS sys_site (
  site_id       INTEGER PRIMARY KEY AUTOINCREMENT,
  code          TEXT NOT NULL UNIQUE,
  name          TEXT NOT NULL,
  domain        TEXT NOT NULL DEFAULT '',
  owner         TEXT NOT NULL DEFAULT '',
  icp           TEXT NOT NULL DEFAULT '',
  theme         TEXT NOT NULL DEFAULT 'default',
  status        TEXT NOT NULL DEFAULT 'enabled',
  created_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS sys_channel (
  channel_id    INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id       INTEGER NOT NULL DEFAULT 1,
  type_code     TEXT NOT NULL,
  parent_type   TEXT NOT NULL DEFAULT '',
  slug          TEXT NOT NULL DEFAULT '',
  name          TEXT NOT NULL,
  inner_name    TEXT NOT NULL,
  intro         TEXT,
  layout        TEXT NOT NULL DEFAULT 'list',
  total_count   INTEGER NOT NULL DEFAULT 0,
  home_sourced  INTEGER NOT NULL DEFAULT 0,
  sort_no       INTEGER NOT NULL DEFAULT 0,
  status        TEXT NOT NULL DEFAULT 'published',
  siblings_json TEXT,
  counties_json TEXT,
  note          TEXT,
  feature_json  TEXT,
  created_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  UNIQUE (site_id, type_code)
);
CREATE INDEX IF NOT EXISTS idx_channel_parent ON sys_channel (site_id, parent_type);
CREATE INDEX IF NOT EXISTS idx_channel_layout ON sys_channel (layout);

CREATE TABLE IF NOT EXISTS cms_article (
  article_id    INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id       INTEGER NOT NULL DEFAULT 1,
  channel_type  TEXT NOT NULL,
  title         TEXT NOT NULL,
  subtitle      TEXT NOT NULL DEFAULT '',
  summary       TEXT,
  content_html  TEXT,
  source        TEXT NOT NULL DEFAULT '',
  author        TEXT NOT NULL DEFAULT '',
  editor        TEXT NOT NULL DEFAULT '',
  published_at  TEXT,
  views         TEXT NOT NULL DEFAULT '0',
  role          TEXT NOT NULL DEFAULT '',
  thumb         TEXT NOT NULL DEFAULT '',
  has_body      INTEGER NOT NULL DEFAULT 0,
  url_alias     TEXT NOT NULL DEFAULT '',
  is_top        INTEGER NOT NULL DEFAULT 0,
  is_hot        INTEGER NOT NULL DEFAULT 0,
  status        TEXT NOT NULL DEFAULT 'published',
  public_scope  TEXT NOT NULL DEFAULT 'public',
  created_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX IF NOT EXISTS idx_article_channel ON cms_article (site_id, channel_type, status, published_at);
CREATE INDEX IF NOT EXISTS idx_article_status ON cms_article (status, public_scope);

CREATE TABLE IF NOT EXISTS cms_article_image (
  image_id      INTEGER PRIMARY KEY AUTOINCREMENT,
  article_id    INTEGER NOT NULL,
  path          TEXT NOT NULL,
  sort_no       INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_image_article ON cms_article_image (article_id, sort_no);

CREATE TABLE IF NOT EXISTS cms_article_channel (
  article_id    INTEGER NOT NULL,
  site_id       INTEGER NOT NULL DEFAULT 1,
  channel_type  TEXT NOT NULL,
  sort_no       INTEGER NOT NULL DEFAULT 0,
  is_primary    INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (article_id, site_id, channel_type)
);
CREATE INDEX IF NOT EXISTS idx_ac_channel ON cms_article_channel (site_id, channel_type, sort_no);

CREATE TABLE IF NOT EXISTS cms_attachment (
  attachment_id INTEGER PRIMARY KEY AUTOINCREMENT,
  article_id    INTEGER NOT NULL,
  name          TEXT NOT NULL,
  url           TEXT NOT NULL,
  ext           TEXT NOT NULL DEFAULT '',
  size_bytes    INTEGER NOT NULL DEFAULT 0,
  sort_no       INTEGER NOT NULL DEFAULT 0,
  download_count INTEGER NOT NULL DEFAULT 0,
  created_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX IF NOT EXISTS idx_attachment_article ON cms_attachment (article_id, sort_no);

CREATE TABLE IF NOT EXISTS cms_home_block (
  block_id      INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id       INTEGER NOT NULL DEFAULT 1,
  block_key     TEXT NOT NULL,
  sort_no       INTEGER NOT NULL DEFAULT 0,
  payload_json  TEXT NOT NULL,
  updated_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  UNIQUE (site_id, block_key)
);

CREATE TABLE IF NOT EXISTS sys_url_redirect (
  redirect_id   INTEGER PRIMARY KEY AUTOINCREMENT,
  old_path      TEXT NOT NULL UNIQUE,
  new_path      TEXT NOT NULL,
  status_code   INTEGER NOT NULL DEFAULT 301,
  hits          INTEGER NOT NULL DEFAULT 0,
  created_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS sys_user (
  user_id       INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  real_name     TEXT NOT NULL DEFAULT '',
  status        TEXT NOT NULL DEFAULT 'enabled',
  last_login_at TEXT,
  created_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS sys_role (
  role_id       INTEGER PRIMARY KEY AUTOINCREMENT,
  code          TEXT NOT NULL UNIQUE,
  name          TEXT NOT NULL,
  description   TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS sys_user_role (
  user_id       INTEGER NOT NULL,
  role_id       INTEGER NOT NULL,
  PRIMARY KEY (user_id, role_id)
);

CREATE TABLE IF NOT EXISTS sys_role_channel (
  role_id       INTEGER NOT NULL,
  site_id       INTEGER NOT NULL DEFAULT 1,
  channel_type  TEXT NOT NULL,
  PRIMARY KEY (role_id, site_id, channel_type)
);

CREATE TABLE IF NOT EXISTS sys_operation_log (
  log_id        INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id       INTEGER NOT NULL DEFAULT 0,
  action        TEXT NOT NULL,
  target_type   TEXT NOT NULL DEFAULT '',
  target_id     TEXT NOT NULL DEFAULT '',
  detail_json   TEXT,
  ip            TEXT NOT NULL DEFAULT '',
  user_agent    TEXT NOT NULL DEFAULT '',
  created_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX IF NOT EXISTS idx_log_created ON sys_operation_log (created_at);
CREATE INDEX IF NOT EXISTS idx_log_user ON sys_operation_log (user_id, created_at);

CREATE TABLE IF NOT EXISTS cms_article_proof (
  article_id    INTEGER PRIMARY KEY,
  status        TEXT NOT NULL DEFAULT 'pending',
  report_no     TEXT NOT NULL DEFAULT '',
  engine        TEXT NOT NULL DEFAULT '',
  score         REAL,
  checked_at    TEXT,
  updated_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS proof_dict (
  dict_id       INTEGER PRIMARY KEY AUTOINCREMENT,
  category      TEXT NOT NULL,
  wrong_term    TEXT NOT NULL,
  right_term    TEXT NOT NULL DEFAULT '',
  note          TEXT NOT NULL DEFAULT '',
  enabled       INTEGER NOT NULL DEFAULT 1,
  updated_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  UNIQUE (category, wrong_term)
);

CREATE TABLE IF NOT EXISTS schema_migrations (
  version       TEXT PRIMARY KEY,
  applied_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
