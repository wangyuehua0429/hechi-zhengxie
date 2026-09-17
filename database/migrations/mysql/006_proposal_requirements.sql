-- 河池政协网 · 006：提案系统按提案委最新需求调整（MySQL 8.0）
-- 与 database/migrations/sqlite/006_proposal_requirements.sql 同构，差异仅限方言。
-- 说明：MySQL 的 TEXT 列不允许写成 DEFAULT ''，因此 body_html 用 TEXT NULL，
-- 现有行由下面的 UPDATE 回填，写入侧一律给值，代码读取时按空串处理。
-- 本文件由 Migrator 按分号切分执行，注释必须独占一行，语句内不要出现分号。

ALTER TABLE sys_member ADD COLUMN contact_name VARCHAR(64) NOT NULL DEFAULT '' COMMENT '办理联系人姓名';
ALTER TABLE sys_member ADD COLUMN contact_org VARCHAR(128) NOT NULL DEFAULT '' COMMENT '办理联系人单位';
ALTER TABLE sys_member ADD COLUMN contact_title VARCHAR(128) NOT NULL DEFAULT '' COMMENT '办理联系人职务';
ALTER TABLE sys_member ADD COLUMN contact_address VARCHAR(255) NOT NULL DEFAULT '' COMMENT '联系地址';
ALTER TABLE sys_member ADD COLUMN contact_postcode VARCHAR(16) NOT NULL DEFAULT '' COMMENT '邮政编码';
ALTER TABLE sys_member ADD COLUMN contact_mobile VARCHAR(32) NOT NULL DEFAULT '' COMMENT '联系电话';

ALTER TABLE cms_proposal ADD COLUMN body_html TEXT NULL COMMENT '正文（轻量富文本，≤2000 字）';
ALTER TABLE cms_proposal ADD COLUMN host_units VARCHAR(500) NOT NULL DEFAULT '' COMMENT '建议承办单位（名称摘要，明细在 cms_proposal_unit）';
ALTER TABLE cms_proposal ADD COLUMN contact_name VARCHAR(64) NOT NULL DEFAULT '' COMMENT '办理联系人姓名快照';
ALTER TABLE cms_proposal ADD COLUMN contact_org VARCHAR(128) NOT NULL DEFAULT '' COMMENT '办理联系人单位快照';
ALTER TABLE cms_proposal ADD COLUMN contact_title VARCHAR(128) NOT NULL DEFAULT '' COMMENT '办理联系人职务快照';
ALTER TABLE cms_proposal ADD COLUMN contact_address VARCHAR(255) NOT NULL DEFAULT '' COMMENT '联系地址快照';
ALTER TABLE cms_proposal ADD COLUMN contact_postcode VARCHAR(16) NOT NULL DEFAULT '' COMMENT '邮政编码快照';
ALTER TABLE cms_proposal ADD COLUMN edited_by INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近一次改稿人（sys_user.user_id）';
ALTER TABLE cms_proposal ADD COLUMN edited_at DATETIME NULL COMMENT '最近一次改稿时间';

-- 转义符用 CHAR() 拼：本文件由 Migrator 按分号切分，字符串里不能出现分号（&amp; 自带一个）
UPDATE cms_proposal SET body_html = CONCAT('<p>', REPLACE(REPLACE(REPLACE(REPLACE(TRIM(CONCAT(COALESCE(problem_text, ''), CHAR(10), COALESCE(analysis_text, ''), CHAR(10), COALESCE(suggestion_text, ''))), CHAR(38), CONCAT(CHAR(38), 'amp', CHAR(59))), CHAR(60), CONCAT(CHAR(38), 'lt', CHAR(59))), CHAR(62), CONCAT(CHAR(38), 'gt', CHAR(59))), CHAR(10), '<br>'), '</p>') WHERE body_html IS NULL;

CREATE TABLE IF NOT EXISTS sys_proposal_unit (
  unit_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(128) NOT NULL COMMENT '市直单位名称',
  sort_no    INT          NOT NULL DEFAULT 0 COMMENT '排序，小的在前',
  status     VARCHAR(16)  NOT NULL DEFAULT 'enabled' COMMENT 'enabled/disabled',
  remark     VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (unit_id),
  UNIQUE KEY uk_proposal_unit_name (name),
  KEY idx_proposal_unit_status (status, sort_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='建议承办单位：市直单位清单，提案委自行维护';

CREATE TABLE IF NOT EXISTS cms_proposal_unit (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proposal_id INT UNSIGNED NOT NULL,
  unit_id     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '字典里已删除时为 0，名称以 unit_name 为准',
  unit_name   VARCHAR(128) NOT NULL DEFAULT '',
  sort_no     INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_proposal_unit_link (proposal_id, sort_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提案的建议承办单位（最多 5 条）';

CREATE TABLE IF NOT EXISTS cms_proposal_co_member (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proposal_id INT UNSIGNED NOT NULL,
  name        VARCHAR(64)  NOT NULL DEFAULT '',
  org_title   VARCHAR(128) NOT NULL DEFAULT '' COMMENT '单位及职务',
  mobile      VARCHAR(32)  NOT NULL DEFAULT '',
  sort_no     INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_proposal_comember_link (proposal_id, sort_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='联名提案的联名委员资料';

CREATE TABLE IF NOT EXISTS cms_proposal_revision (
  revision_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proposal_id   INT UNSIGNED NOT NULL,
  actor_id      INT UNSIGNED NOT NULL DEFAULT 0,
  summary       VARCHAR(500) NOT NULL DEFAULT '' COMMENT '改动了哪些内容',
  snapshot_json TEXT         NULL COMMENT '改稿前的关键字段快照',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (revision_id),
  KEY idx_proposal_revision_link (proposal_id, revision_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提案委改稿留痕：每次保存前存一份改前快照';

INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'unit.manage' FROM sys_role WHERE code = 'proposal_admin';
