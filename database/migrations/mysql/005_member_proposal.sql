-- 河池政协网 · 005：政协委员在线提交提案系统（MySQL 8.0）
-- 与 database/migrations/sqlite/005_member_proposal.sql 同构，差异仅限方言。
-- 设计说明见 docs/提案系统设计说明.md：委员账号独立成表（与后台 sys_user 隔离），
-- 提案三态（已提交／已受理／已退回）由 ProposalWorkflow 定义，附件落在 storage/proposals 下不入 webroot。
-- 本文件由 Migrator 按分号切分执行，注释必须独占一行，语句内不要出现分号。

CREATE TABLE IF NOT EXISTS sys_member (
  member_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                 VARCHAR(64)  NOT NULL COMMENT '委员姓名',
  login_name           VARCHAR(64)  NOT NULL COMMENT '登录名，默认取手机号',
  password_hash        VARCHAR(255) NOT NULL,
  must_change_password TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '首次登录须改密',
  mobile               VARCHAR(32)  NOT NULL DEFAULT '',
  sector               VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '界别',
  committee            VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '专委会',
  org_title            VARCHAR(128) NOT NULL DEFAULT '' COMMENT '单位及职务',
  term                 VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '届次',
  status               VARCHAR(16)  NOT NULL DEFAULT 'enabled' COMMENT 'enabled/disabled',
  last_login_at        DATETIME     NULL,
  failed_attempts      INT          NOT NULL DEFAULT 0,
  locked_until         DATETIME     NULL,
  remark               VARCHAR(255) NOT NULL DEFAULT '',
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (member_id),
  UNIQUE KEY uk_member_login (login_name),
  KEY idx_member_status (status, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='政协委员账号（与后台 sys_user 隔离）';

CREATE TABLE IF NOT EXISTS cms_proposal (
  proposal_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id         INT UNSIGNED NOT NULL DEFAULT 1,
  member_id       INT UNSIGNED NOT NULL COMMENT '第一提案人（提交委员账号）',
  proposer_type   VARCHAR(16)  NOT NULL DEFAULT 'personal' COMMENT 'personal/joint/collective',
  proposer_name   VARCHAR(64)  NOT NULL DEFAULT '',
  sector          VARCHAR(64)  NOT NULL DEFAULT '',
  committee       VARCHAR(64)  NOT NULL DEFAULT '',
  contact_mobile  VARCHAR(32)  NOT NULL DEFAULT '',
  co_members      VARCHAR(500) NOT NULL DEFAULT '' COMMENT '联名委员文本名单',
  collective_name VARCHAR(128) NOT NULL DEFAULT '' COMMENT '集体提案单位／界别名',
  category        VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '提案类别',
  title           VARCHAR(128) NOT NULL COMMENT '案由',
  problem_text    TEXT         NULL COMMENT '情况与问题',
  analysis_text   TEXT         NULL COMMENT '分析',
  suggestion_text TEXT         NULL COMMENT '建议',
  status          VARCHAR(16)  NOT NULL DEFAULT 'submitted' COMMENT 'submitted/accepted/returned',
  returned_reason VARCHAR(1000) NOT NULL DEFAULT '',
  reviewer_id     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '受理人（sys_user.user_id）',
  reviewed_at     DATETIME     NULL,
  review_note     VARCHAR(1000) NOT NULL DEFAULT '',
  submitted_at    DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (proposal_id),
  KEY idx_proposal_member (member_id, submitted_at),
  KEY idx_proposal_status (site_id, status, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='委员提案（本期只做提交与受理）';

CREATE TABLE IF NOT EXISTS cms_proposal_attachment (
  attachment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proposal_id   INT UNSIGNED NOT NULL,
  name          VARCHAR(255) NOT NULL COMMENT '原始文件名',
  stored_path   VARCHAR(255) NOT NULL COMMENT '相对 storage 的落盘路径，不入 webroot',
  ext           VARCHAR(16)  NOT NULL DEFAULT '',
  size_bytes    INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attachment_id),
  KEY idx_proposal_attachment (proposal_id, attachment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提案附件（仅登录后经权限校验下载）';

CREATE TABLE IF NOT EXISTS cms_proposal_log (
  log_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proposal_id INT UNSIGNED NOT NULL,
  actor_type  VARCHAR(16)  NOT NULL DEFAULT 'member' COMMENT 'member/staff',
  actor_id    INT UNSIGNED NOT NULL DEFAULT 0,
  action      VARCHAR(32)  NOT NULL,
  note        VARCHAR(1000) NOT NULL DEFAULT '',
  ip          VARCHAR(64)  NOT NULL DEFAULT '',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  KEY idx_proposal_log (proposal_id, log_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提案流转记录';

INSERT INTO sys_role (code, name, description, is_system, sort_no, remark) VALUES ('proposal_admin', '提案委', '提案收件、受理、退回与委员账号管理', 1, 50, '责任人：提案委员会办公室');
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'proposal.view' FROM sys_role WHERE code = 'proposal_admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'proposal.review' FROM sys_role WHERE code = 'proposal_admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'proposal.export' FROM sys_role WHERE code = 'proposal_admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'member.manage' FROM sys_role WHERE code = 'proposal_admin';
UPDATE cms_home_banner SET link_url = '/member' WHERE slot_key = 'hero-1' AND link_url = 'channel.html?id=501';
