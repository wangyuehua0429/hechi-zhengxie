-- 河池政协网 · 002：稿库状态机字段 + 用户分组与权限（MySQL 8.0）
-- 与 database/migrations/sqlite/002_workflow_and_permission.sql 同构，差异仅限方言：
--   * TEXT 在 MySQL 下不允许 DEFAULT，说明类字段用 VARCHAR 代替
--   * MySQL 无 CREATE INDEX IF NOT EXISTS，索引写在 CREATE TABLE 内或单独 ALTER
ALTER TABLE cms_article ADD COLUMN submitted_at DATETIME NULL COMMENT '最近一次提交审核时间';
ALTER TABLE cms_article ADD COLUMN reviewer_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '审核／发布人 sys_user.user_id';
ALTER TABLE cms_article ADD COLUMN reviewed_at DATETIME NULL COMMENT '最近一次审核时间';
ALTER TABLE cms_article ADD COLUMN review_note VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '退回意见或审核备注';
ALTER TABLE cms_article ADD COLUMN withdrawn_at DATETIME NULL COMMENT '撤回时间';
ALTER TABLE cms_article ADD COLUMN withdraw_reason VARCHAR(500) NOT NULL DEFAULT '' COMMENT '撤回原因';
ALTER TABLE cms_article ADD COLUMN deleted_at DATETIME NULL COMMENT '软删除时间';
ALTER TABLE cms_article ADD COLUMN deleted_by INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '删除人';
ALTER TABLE cms_article ADD COLUMN status_before_delete VARCHAR(16) NOT NULL DEFAULT '' COMMENT '删除前状态，供恢复';
ALTER TABLE cms_article ADD COLUMN created_by INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人 sys_user.user_id';
ALTER TABLE cms_article ADD COLUMN updated_by INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后修改人';
ALTER TABLE sys_user ADD COLUMN dept VARCHAR(64) NOT NULL DEFAULT '' COMMENT '所属部门（组织分组，不参与鉴权）';
ALTER TABLE sys_user ADD COLUMN mobile VARCHAR(32) NOT NULL DEFAULT '';
ALTER TABLE sys_user ADD COLUMN email VARCHAR(128) NOT NULL DEFAULT '';
ALTER TABLE sys_user ADD COLUMN remark VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE sys_user ADD COLUMN pwd_changed_at DATETIME NULL COMMENT '最近改密时间，NULL 表示建号后未改过';
ALTER TABLE sys_role ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 COMMENT '内置角色不允许改权限码';
ALTER TABLE sys_role ADD COLUMN sort_no INT NOT NULL DEFAULT 0;
ALTER TABLE sys_role ADD COLUMN remark VARCHAR(255) NOT NULL DEFAULT '';
CREATE TABLE IF NOT EXISTS sys_role_permission (
  role_id    INT UNSIGNED NOT NULL,
  perm_code  VARCHAR(64)  NOT NULL,
  PRIMARY KEY (role_id, perm_code),
  KEY idx_role_perm (perm_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色与权限码';
UPDATE cms_article SET status = 'withdrawn' WHERE status = 'offline';
INSERT INTO sys_role (code, name, description, is_system, sort_no, remark) VALUES ('editor', '栏目编辑', '本栏目稿件的新建、编辑、提交与删除', 1, 10, '责任人：栏目内容维护');
INSERT INTO sys_role (code, name, description, is_system, sort_no, remark) VALUES ('reviewer', '审核', '待审稿件的通过或退回', 1, 20, '责任人：内容把关');
INSERT INTO sys_role (code, name, description, is_system, sort_no, remark) VALUES ('publisher', '发布', '发布、撤回、重新发布与静态页生成', 1, 30, '责任人：值班发布');
INSERT INTO sys_role (code, name, description, is_system, sort_no, remark) VALUES ('admin', '管理员', '全部权限，含用户与角色管理', 1, 40, '责任人：系统管理');
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'article.edit' FROM sys_role WHERE code = 'editor';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'article.submit' FROM sys_role WHERE code = 'editor';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'article.delete' FROM sys_role WHERE code = 'editor';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'article.review' FROM sys_role WHERE code = 'reviewer';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'article.publish' FROM sys_role WHERE code = 'publisher';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'article.restore' FROM sys_role WHERE code = 'publisher';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'publish.run' FROM sys_role WHERE code = 'publisher';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'article.edit' FROM sys_role WHERE code = 'admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'article.submit' FROM sys_role WHERE code = 'admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'article.review' FROM sys_role WHERE code = 'admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'article.publish' FROM sys_role WHERE code = 'admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'article.delete' FROM sys_role WHERE code = 'admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'article.restore' FROM sys_role WHERE code = 'admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'article.purge' FROM sys_role WHERE code = 'admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'channel.manage' FROM sys_role WHERE code = 'admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'publish.run' FROM sys_role WHERE code = 'admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'user.manage' FROM sys_role WHERE code = 'admin';
INSERT INTO sys_role_permission (role_id, perm_code) SELECT role_id, 'log.view' FROM sys_role WHERE code = 'admin';
INSERT INTO sys_user_role (user_id, role_id) SELECT u.user_id, r.role_id FROM sys_user u, sys_role r WHERE r.code = 'admin';
