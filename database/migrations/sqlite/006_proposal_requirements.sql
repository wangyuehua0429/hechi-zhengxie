-- 河池政协网 · 006：提案系统按提案委最新需求调整（SQLite）
-- 与 database/migrations/mysql/006_proposal_requirements.sql 同构，差异仅限方言。
-- 设计说明见 docs/提案系统设计说明.md：正文合并为一段轻量富文本（≤2000 字）、
-- 办理联系人六项必填并记忆在账号上、联名委员与建议承办单位改由子表存储、
-- 提案委改稿前留快照。旧的三段正文列保留但不再读写，避免 SQLite 重建表的风险。
-- 本文件由 Migrator 按分号切分执行，注释必须独占一行，语句内不要出现分号。

ALTER TABLE sys_member ADD COLUMN contact_name TEXT NOT NULL DEFAULT '';
ALTER TABLE sys_member ADD COLUMN contact_org TEXT NOT NULL DEFAULT '';
ALTER TABLE sys_member ADD COLUMN contact_title TEXT NOT NULL DEFAULT '';
ALTER TABLE sys_member ADD COLUMN contact_address TEXT NOT NULL DEFAULT '';
ALTER TABLE sys_member ADD COLUMN contact_postcode TEXT NOT NULL DEFAULT '';
ALTER TABLE sys_member ADD COLUMN contact_mobile TEXT NOT NULL DEFAULT '';

ALTER TABLE cms_proposal ADD COLUMN body_html TEXT NULL;
ALTER TABLE cms_proposal ADD COLUMN host_units TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_proposal ADD COLUMN contact_name TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_proposal ADD COLUMN contact_org TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_proposal ADD COLUMN contact_title TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_proposal ADD COLUMN contact_address TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_proposal ADD COLUMN contact_postcode TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_proposal ADD COLUMN edited_by INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cms_proposal ADD COLUMN edited_at TEXT NULL;

-- 转义符用 char() 拼：本文件由 Migrator 按分号切分，字符串里不能出现分号（&amp; 自带一个）
UPDATE cms_proposal SET body_html = '<p>' || replace(replace(replace(replace(trim(coalesce(problem_text, '') || char(10) || coalesce(analysis_text, '') || char(10) || coalesce(suggestion_text, '')), char(38), char(38) || 'amp' || char(59)), char(60), char(38) || 'lt' || char(59)), char(62), char(38) || 'gt' || char(59)), char(10), '<br>') || '</p>' WHERE body_html IS NULL;

CREATE TABLE IF NOT EXISTS sys_proposal_unit (
  unit_id    INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL UNIQUE,
  sort_no    INTEGER NOT NULL DEFAULT 0,
  status     TEXT NOT NULL DEFAULT 'enabled',
  remark     TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX IF NOT EXISTS idx_proposal_unit_status ON sys_proposal_unit (status, sort_no);

CREATE TABLE IF NOT EXISTS cms_proposal_unit (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  proposal_id INTEGER NOT NULL,
  unit_id     INTEGER NOT NULL DEFAULT 0,
  unit_name   TEXT NOT NULL DEFAULT '',
  sort_no     INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_proposal_unit_link ON cms_proposal_unit (proposal_id, sort_no);

CREATE TABLE IF NOT EXISTS cms_proposal_co_member (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  proposal_id INTEGER NOT NULL,
  name        TEXT NOT NULL DEFAULT '',
  org_title   TEXT NOT NULL DEFAULT '',
  mobile      TEXT NOT NULL DEFAULT '',
  sort_no     INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_proposal_comember_link ON cms_proposal_co_member (proposal_id, sort_no);

CREATE TABLE IF NOT EXISTS cms_proposal_revision (
  revision_id   INTEGER PRIMARY KEY AUTOINCREMENT,
  proposal_id   INTEGER NOT NULL,
  actor_id      INTEGER NOT NULL DEFAULT 0,
  summary       TEXT NOT NULL DEFAULT '',
  snapshot_json TEXT NULL,
  created_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX IF NOT EXISTS idx_proposal_revision_link ON cms_proposal_revision (proposal_id, revision_id);

INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'unit.manage' FROM sys_role WHERE code = 'proposal_admin';
