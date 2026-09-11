-- 河池政协网 · 003：首页四大类管理（MySQL 8.0）
-- 与 database/migrations/sqlite/003_home_sections.sql 同构，差异仅限方言：
--   * TEXT 在 MySQL 下不允许 DEFAULT，说明类字段用 VARCHAR 代替
--   * MySQL 无 CREATE INDEX IF NOT EXISTS，索引写在 CREATE TABLE 内或单独 ALTER

ALTER TABLE cms_article_channel ADD COLUMN is_top TINYINT NOT NULL DEFAULT 0 COMMENT '在本栏目置顶';
ALTER TABLE cms_article_channel ADD INDEX idx_ac_top (site_id, channel_type, is_top, sort_no);

CREATE TABLE IF NOT EXISTS cms_home_section (
  section_key VARCHAR(32)  NOT NULL COMMENT '模块键，与接口返回的顶层键一致',
  site_id     INT UNSIGNED NOT NULL DEFAULT 1,
  label       VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '模块标题文案',
  scope_json  VARCHAR(1000) NOT NULL DEFAULT '{}' COMMENT '绑定栏目：{"channels":[…]}/{"tabs":[…]}/{"parent":…}',
  page_size   INT UNSIGNED NOT NULL DEFAULT 10 COMMENT '取几条',
  group_by    VARCHAR(16)  NOT NULL DEFAULT '' COMMENT 'child=按子栏目拆 tab',
  more_url    VARCHAR(255) NOT NULL DEFAULT '' COMMENT '「更多」链接',
  status      VARCHAR(16)  NOT NULL DEFAULT 'published',
  sort_no     INT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (site_id, section_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='首页稿件模块配置（列表由稿件现算）';

CREATE TABLE IF NOT EXISTS cms_home_slide (
  slide_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id    INT UNSIGNED NOT NULL DEFAULT 1,
  article_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '引用的稿件号，0=外链条目',
  title      VARCHAR(255) NOT NULL DEFAULT '',
  summary    VARCHAR(1000) NOT NULL DEFAULT '',
  image_url  VARCHAR(255) NOT NULL DEFAULT '',
  link_url   VARCHAR(500) NOT NULL DEFAULT '',
  sort_no    INT UNSIGNED NOT NULL DEFAULT 0,
  status     VARCHAR(16)  NOT NULL DEFAULT 'published',
  created_by INT UNSIGNED NOT NULL DEFAULT 0,
  updated_by INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (slide_id),
  KEY idx_slide_sort (site_id, status, sort_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='首页首屏头条轮换';

CREATE TABLE IF NOT EXISTS cms_home_banner (
  banner_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id    INT UNSIGNED NOT NULL DEFAULT 1,
  slot_key   VARCHAR(32)  NOT NULL COMMENT '固定槽位：hero-1/hero-2/body-1…body-5',
  title      VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'alt 文案',
  image_url  VARCHAR(255) NOT NULL DEFAULT '',
  link_url   VARCHAR(500) NOT NULL DEFAULT '',
  sort_no    INT UNSIGNED NOT NULL DEFAULT 0,
  status     VARCHAR(16)  NOT NULL DEFAULT 'published',
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (banner_id),
  UNIQUE KEY uk_banner_slot (site_id, slot_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='首页站内横幅';

INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'home.manage' FROM sys_role WHERE code = 'admin';
