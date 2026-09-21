-- =========================================
-- 昨夜书博客 数据库结构 + 初始数据
-- =========================================

CREATE DATABASE IF NOT EXISTS blog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE blog;

-- -----------------------------------------
-- 管理员表
-- -----------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(50)  NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL COMMENT 'bcrypt hash',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------
-- 分类表
-- -----------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(50) NOT NULL,
    slug       VARCHAR(50) NOT NULL UNIQUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------
-- 专题表
-- -----------------------------------------
CREATE TABLE IF NOT EXISTS collections (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(100) NOT NULL,
    description TEXT,
    cover_url   VARCHAR(500),
    sort_order  INT DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------
-- 文章表
-- -----------------------------------------
CREATE TABLE IF NOT EXISTS posts (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    title         VARCHAR(200) NOT NULL,
    summary       TEXT,
    content       LONGTEXT,
    cover_url     VARCHAR(500),
    category_id   INT,
    collection_id INT,
    published_at  DATE,
    is_published  TINYINT(1) DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id)   REFERENCES categories(id)  ON DELETE SET NULL,
    FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================
-- 初始数据
-- =========================================

-- 默认管理员：admin / password
INSERT INTO admins (username, password) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');  -- 默认密码: password


-- -----------------------------------------
-- 友情链接表
-- -----------------------------------------
CREATE TABLE IF NOT EXISTS friend_links (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    url        VARCHAR(500) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -----------------------------------------
-- 友情链接申请表
-- -----------------------------------------
CREATE TABLE IF NOT EXISTS friend_link_applications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    site_name   VARCHAR(100) NOT NULL COMMENT '网站名称',
    site_url    VARCHAR(500) NOT NULL COMMENT '网站链接',
    description VARCHAR(300)          COMMENT '网站简介',
    email       VARCHAR(200) NOT NULL COMMENT '联系邮箱',
    status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' COMMENT '审核状态',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -----------------------------------------
-- 站点设置表（键值对）
-- -----------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
    `key`       VARCHAR(100) NOT NULL PRIMARY KEY,
    `value`     TEXT,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 默认值
INSERT INTO site_settings (`key`, `value`) VALUES
('homepage_avatar',  '')
,('homepage_name',   'Jeremy Bentham')
,('homepage_bio',    '保持理想，步履不停。')
,('wechat_qr_url',   '')
,('social_github',  'https://github.com/vispce-png')
,('social_youtube', 'https://www.youtube.com/@JacocI')
,('social_bilibili','https://space.bilibili.com/3493299151177900')
,('social_twitter', '')
,('social_instagram','')
,('about_quote',    '保持理想，步履不停。')
,('about_email',    'vispce@gmail.com')
ON DUPLICATE KEY UPDATE `key`=`key`;
