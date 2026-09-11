-- 河池政协网 · 初始表结构（MySQL 8.0）
-- 依据：docs/架构与实施说明.md 第 2 节数据模型、docs/api-contract.md 第 3 节数据对象
-- 说明：ID 沿用旧库口径——稿件 article_id = 旧库 rd_news.ID，栏目 type_code = 旧库栏目号；
--       为后续信创切换预留，SQL 只写在仓储层与迁移脚本里，业务代码不拼方言。

CREATE TABLE IF NOT EXISTS sys_site (
  site_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(32)  NOT NULL COMMENT '站点标识，主站为 main',
  name          VARCHAR(128) NOT NULL,
  domain        VARCHAR(128) NOT NULL DEFAULT '',
  owner         VARCHAR(128) NOT NULL DEFAULT '' COMMENT '主办单位',
  icp           VARCHAR(64)  NOT NULL DEFAULT '',
  theme         VARCHAR(64)  NOT NULL DEFAULT 'default' COMMENT '模板主题',
  status        VARCHAR(16)  NOT NULL DEFAULT 'enabled',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (site_id),
  UNIQUE KEY uk_site_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站点（多站点预留，本期只启用主站）';

CREATE TABLE IF NOT EXISTS sys_channel (
  channel_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id       INT UNSIGNED NOT NULL DEFAULT 1,
  type_code     VARCHAR(32)  NOT NULL COMMENT '旧库栏目号，前端 ?id= 用值',
  parent_type   VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '所属一级栏目号',
  slug          VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '拼音标识，静态化路径用',
  name          VARCHAR(128) NOT NULL COMMENT '一级栏目名',
  inner_name    VARCHAR(128) NOT NULL COMMENT '当前子栏目名',
  intro         TEXT         NULL,
  layout        VARCHAR(16)  NOT NULL DEFAULT 'list' COMMENT 'list/leaders/about/county/gallery/video/topic/interactive',
  total_count   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '栏目全量条数',
  home_sourced  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '数据取自首页模块，不参与侧栏汇总',
  sort_no       INT          NOT NULL DEFAULT 0,
  status        VARCHAR(16)  NOT NULL DEFAULT 'published' COMMENT 'published/offline',
  siblings_json JSON         NULL COMMENT '同级子栏目 [{type,name,url,active}]',
  counties_json JSON         NULL COMMENT '县区站点入口（layout=county）',
  note          TEXT         NULL COMMENT '互动栏目说明（layout=interactive）',
  feature_json  JSON         NULL COMMENT '一页式栏目简介块（layout=about）',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (channel_id),
  UNIQUE KEY uk_channel_type (site_id, type_code),
  KEY idx_channel_parent (site_id, parent_type),
  KEY idx_channel_layout (layout)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='栏目（17 个一级栏目 + 二级子栏目）';

CREATE TABLE IF NOT EXISTS cms_article (
  article_id    INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '沿用旧库 rd_news.ID',
  site_id       INT UNSIGNED NOT NULL DEFAULT 1,
  channel_type  VARCHAR(32)  NOT NULL COMMENT '所属栏目号（旧库 rd_news.Type）',
  title         VARCHAR(255) NOT NULL,
  subtitle      VARCHAR(255) NOT NULL DEFAULT '',
  summary       TEXT         NULL,
  content_html  MEDIUMTEXT   NULL COMMENT '正文 HTML，入库前清洗内联样式',
  source        VARCHAR(128) NOT NULL DEFAULT '',
  author        VARCHAR(128) NOT NULL DEFAULT '',
  editor        VARCHAR(128) NOT NULL DEFAULT '',
  published_at  DATETIME     NULL,
  views         VARCHAR(32)  NOT NULL DEFAULT '0' COMMENT '旧库为字符串，保持原样',
  role          VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '领导职务（layout=leaders）',
  thumb         VARCHAR(255) NOT NULL DEFAULT '' COMMENT '列表缩略图',
  has_body      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否已入库正文',
  url_alias     VARCHAR(160) NOT NULL DEFAULT '',
  is_top        TINYINT(1)   NOT NULL DEFAULT 0,
  is_hot        TINYINT(1)   NOT NULL DEFAULT 0,
  status        VARCHAR(16)  NOT NULL DEFAULT 'published' COMMENT 'draft/published/offline',
  public_scope  VARCHAR(16)  NOT NULL DEFAULT 'public' COMMENT 'public（近 3 年公开）/archive（后台留存）',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (article_id),
  KEY idx_article_channel (site_id, channel_type, status, published_at),
  KEY idx_article_status (status, public_scope),
  FULLTEXT KEY ft_article_title (title, summary) WITH PARSER ngram
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='稿件主表';

CREATE TABLE IF NOT EXISTS cms_article_image (
  image_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id    INT UNSIGNED NOT NULL,
  path          VARCHAR(255) NOT NULL COMMENT '相对路径或对象存储 key',
  sort_no       INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (image_id),
  KEY idx_image_article (article_id, sort_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='正文图片（图集灯箱与图片新闻用）';

CREATE TABLE IF NOT EXISTS cms_article_channel (
  article_id    INT UNSIGNED NOT NULL,
  site_id       INT UNSIGNED NOT NULL DEFAULT 1,
  channel_type  VARCHAR(32)  NOT NULL,
  sort_no       INT          NOT NULL DEFAULT 0 COMMENT '该栏目下的排序位（旧站编辑顺序，1 起）',
  is_primary    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否主栏目（详情页面包屑取主栏目）',
  PRIMARY KEY (article_id, site_id, channel_type),
  KEY idx_ac_channel (site_id, channel_type, sort_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='稿件栏目归属：一篇稿件可同时挂在多个栏目（如县区动态同时进「县区政协工作动态」与「县（区）政协」）';

CREATE TABLE IF NOT EXISTS cms_attachment (
  attachment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id    INT UNSIGNED NOT NULL,
  name          VARCHAR(255) NOT NULL,
  url           VARCHAR(512) NOT NULL COMMENT '迁移期指向旧站原件，正式期指向对象存储',
  ext           VARCHAR(16)  NOT NULL DEFAULT '',
  size_bytes    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sort_no       INT          NOT NULL DEFAULT 0,
  download_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attachment_id),
  KEY idx_attachment_article (article_id, sort_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='附件（doc/pdf/xls 等原件）';

CREATE TABLE IF NOT EXISTS cms_home_block (
  block_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id       INT UNSIGNED NOT NULL DEFAULT 1,
  block_key     VARCHAR(32)  NOT NULL COMMENT 'home.json 顶层键：nav/slides/zxdt/...',
  sort_no       INT          NOT NULL DEFAULT 0,
  payload_json  JSON         NOT NULL COMMENT '该模块的完整数据，结构与 home.json 同构',
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (block_id),
  UNIQUE KEY uk_home_block (site_id, block_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='首页模块（原型期按模块整块存 JSON，正式版按模块建模）';

CREATE TABLE IF NOT EXISTS sys_url_redirect (
  redirect_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  old_path      VARCHAR(512) NOT NULL COMMENT '旧站路径（含 query）',
  new_path      VARCHAR(512) NOT NULL COMMENT '新站路径',
  status_code   SMALLINT     NOT NULL DEFAULT 301,
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (redirect_id),
  UNIQUE KEY uk_redirect_old (old_path(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='旧地址 301 映射（保 SEO 与外链）';

CREATE TABLE IF NOT EXISTS sys_user (
  user_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(64)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  real_name     VARCHAR(64)  NOT NULL DEFAULT '',
  status        VARCHAR(16)  NOT NULL DEFAULT 'enabled' COMMENT 'enabled/disabled',
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uk_user_name (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台用户';

CREATE TABLE IF NOT EXISTS sys_role (
  role_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(32)  NOT NULL,
  name          VARCHAR(64)  NOT NULL,
  description   VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (role_id),
  UNIQUE KEY uk_role_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色';

CREATE TABLE IF NOT EXISTS sys_user_role (
  user_id       INT UNSIGNED NOT NULL,
  role_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户与角色';

CREATE TABLE IF NOT EXISTS sys_role_channel (
  role_id       INT UNSIGNED NOT NULL,
  site_id       INT UNSIGNED NOT NULL DEFAULT 1,
  channel_type  VARCHAR(32)  NOT NULL,
  PRIMARY KEY (role_id, site_id, channel_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='权限到「站点 × 栏目」（稿件终审仍在系统外把关）';

CREATE TABLE IF NOT EXISTS sys_operation_log (
  log_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL DEFAULT 0,
  action        VARCHAR(64)  NOT NULL COMMENT 'login/article.update/publish.channel 等',
  target_type   VARCHAR(32)  NOT NULL DEFAULT '',
  target_id     VARCHAR(64)  NOT NULL DEFAULT '',
  detail_json   JSON         NULL,
  ip            VARCHAR(64)  NOT NULL DEFAULT '',
  user_agent    VARCHAR(255) NOT NULL DEFAULT '',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  KEY idx_log_created (created_at),
  KEY idx_log_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作与登录日志（留存 180 天）';

CREATE TABLE IF NOT EXISTS cms_article_proof (
  article_id    INT UNSIGNED NOT NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'pending' COMMENT 'pending/running/passed/failed',
  report_no     VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '《智能编校单》编号',
  engine        VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '厂商与版本',
  score         DECIMAL(5,2) NULL,
  checked_at    DATETIME     NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='编校扩展：文章编校状态';

CREATE TABLE IF NOT EXISTS proof_dict (
  dict_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category      VARCHAR(32)  NOT NULL COMMENT 'leader/region/taboo/local',
  wrong_term    VARCHAR(128) NOT NULL,
  right_term    VARCHAR(128) NOT NULL DEFAULT '',
  note          VARCHAR(255) NOT NULL DEFAULT '',
  enabled       TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (dict_id),
  UNIQUE KEY uk_dict_term (category, wrong_term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='本地编校词库：领导、行政区划、忌讳词、本地提法';

CREATE TABLE IF NOT EXISTS schema_migrations (
  version       VARCHAR(64)  NOT NULL,
  applied_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='迁移记录';
