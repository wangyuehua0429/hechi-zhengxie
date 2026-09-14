-- 河池政协网 · 005：政协委员在线提交提案系统（SQLite）
-- 与 database/migrations/mysql/005_member_proposal.sql 同构，差异仅限方言。
-- 设计说明见 docs/提案系统设计说明.md：委员账号独立成表（与后台 sys_user 隔离），
-- 提案三态（已提交／已受理／已退回）由 ProposalWorkflow 定义，附件落在 storage/proposals 下不入 webroot。
-- 本文件由 Migrator 按分号切分执行，注释必须独占一行，语句内不要出现分号。

CREATE TABLE IF NOT EXISTS sys_member (
  member_id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name                 TEXT NOT NULL,
  login_name           TEXT NOT NULL UNIQUE,
  password_hash        TEXT NOT NULL,
  must_change_password INTEGER NOT NULL DEFAULT 1,
  mobile               TEXT NOT NULL DEFAULT '',
  sector               TEXT NOT NULL DEFAULT '',
  committee            TEXT NOT NULL DEFAULT '',
  org_title            TEXT NOT NULL DEFAULT '',
  term                 TEXT NOT NULL DEFAULT '',
  status               TEXT NOT NULL DEFAULT 'enabled',
  last_login_at        TEXT,
  failed_attempts      INTEGER NOT NULL DEFAULT 0,
  locked_until         TEXT,
  remark               TEXT NOT NULL DEFAULT '',
  created_at           TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at           TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX IF NOT EXISTS idx_member_status ON sys_member (status, name);

CREATE TABLE IF NOT EXISTS cms_proposal (
  proposal_id     INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id         INTEGER NOT NULL DEFAULT 1,
  member_id       INTEGER NOT NULL,
  proposer_type   TEXT NOT NULL DEFAULT 'personal',
  proposer_name   TEXT NOT NULL DEFAULT '',
  sector          TEXT NOT NULL DEFAULT '',
  committee       TEXT NOT NULL DEFAULT '',
  contact_mobile  TEXT NOT NULL DEFAULT '',
  co_members      TEXT NOT NULL DEFAULT '',
  collective_name TEXT NOT NULL DEFAULT '',
  category        TEXT NOT NULL DEFAULT '',
  title           TEXT NOT NULL,
  problem_text    TEXT NOT NULL DEFAULT '',
  analysis_text   TEXT NOT NULL DEFAULT '',
  suggestion_text TEXT NOT NULL DEFAULT '',
  status          TEXT NOT NULL DEFAULT 'submitted',
  returned_reason TEXT NOT NULL DEFAULT '',
  reviewer_id     INTEGER NOT NULL DEFAULT 0,
  reviewed_at     TEXT,
  review_note     TEXT NOT NULL DEFAULT '',
  submitted_at    TEXT,
  created_at      TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
  updated_at      TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX IF NOT EXISTS idx_proposal_member ON cms_proposal (member_id, submitted_at);
CREATE INDEX IF NOT EXISTS idx_proposal_status ON cms_proposal (site_id, status, submitted_at);

CREATE TABLE IF NOT EXISTS cms_proposal_attachment (
  attachment_id INTEGER PRIMARY KEY AUTOINCREMENT,
  proposal_id   INTEGER NOT NULL,
  name          TEXT NOT NULL,
  stored_path   TEXT NOT NULL,
  ext           TEXT NOT NULL DEFAULT '',
  size_bytes    INTEGER NOT NULL DEFAULT 0,
  created_at    TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX IF NOT EXISTS idx_proposal_attachment ON cms_proposal_attachment (proposal_id, attachment_id);

CREATE TABLE IF NOT EXISTS cms_proposal_log (
  log_id      INTEGER PRIMARY KEY AUTOINCREMENT,
  proposal_id INTEGER NOT NULL,
  actor_type  TEXT NOT NULL DEFAULT 'member',
  actor_id    INTEGER NOT NULL DEFAULT 0,
  action      TEXT NOT NULL,
  note        TEXT NOT NULL DEFAULT '',
  ip          TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX IF NOT EXISTS idx_proposal_log ON cms_proposal_log (proposal_id, log_id);

INSERT INTO sys_role (code, name, description, is_system, sort_no, remark) VALUES ('proposal_admin', '提案委', '提案收件、受理、退回与委员账号管理', 1, 50, '责任人：提案委员会办公室');
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'proposal.view' FROM sys_role WHERE code = 'proposal_admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'proposal.review' FROM sys_role WHERE code = 'proposal_admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'proposal.export' FROM sys_role WHERE code = 'proposal_admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'member.manage' FROM sys_role WHERE code = 'proposal_admin';
UPDATE cms_home_banner SET link_url = '/member' WHERE slot_key = 'hero-1' AND link_url = 'channel.html?id=501';
