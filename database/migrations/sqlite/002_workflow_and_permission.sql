-- 河池政协网 · 002：稿库状态机字段 + 用户分组与权限（SQLite）
-- 与 database/migrations/mysql/002_workflow_and_permission.sql 同构，差异仅限方言。
-- 本文件由 Migrator 按分号切分执行，注释必须独占一行，语句内不要出现分号。
ALTER TABLE cms_article ADD COLUMN submitted_at TEXT;
ALTER TABLE cms_article ADD COLUMN reviewer_id INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cms_article ADD COLUMN reviewed_at TEXT;
ALTER TABLE cms_article ADD COLUMN review_note TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_article ADD COLUMN withdrawn_at TEXT;
ALTER TABLE cms_article ADD COLUMN withdraw_reason TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_article ADD COLUMN deleted_at TEXT;
ALTER TABLE cms_article ADD COLUMN deleted_by INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cms_article ADD COLUMN status_before_delete TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_article ADD COLUMN created_by INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cms_article ADD COLUMN updated_by INTEGER NOT NULL DEFAULT 0;
ALTER TABLE sys_user ADD COLUMN dept TEXT NOT NULL DEFAULT '';
ALTER TABLE sys_user ADD COLUMN mobile TEXT NOT NULL DEFAULT '';
ALTER TABLE sys_user ADD COLUMN email TEXT NOT NULL DEFAULT '';
ALTER TABLE sys_user ADD COLUMN remark TEXT NOT NULL DEFAULT '';
ALTER TABLE sys_user ADD COLUMN pwd_changed_at TEXT;
ALTER TABLE sys_role ADD COLUMN is_system INTEGER NOT NULL DEFAULT 0;
ALTER TABLE sys_role ADD COLUMN sort_no INTEGER NOT NULL DEFAULT 0;
ALTER TABLE sys_role ADD COLUMN remark TEXT NOT NULL DEFAULT '';
CREATE TABLE IF NOT EXISTS sys_role_permission (
  role_id    INTEGER NOT NULL,
  perm_code  TEXT NOT NULL,
  PRIMARY KEY (role_id, perm_code)
);
CREATE INDEX IF NOT EXISTS idx_role_perm ON sys_role_permission (perm_code);
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
