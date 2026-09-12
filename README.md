# 轻量博客系统

一个基于 PHP + MySQL 构建的轻量级个人博客，支持文章管理、专题系列、文件分享、音乐播放器、友链申请等功能，前端无需构建工具，开箱即用。

## 功能特性

- **文章系统**：支持分类、专题系列、封面图、Markdown/HTML 正文
- **全站搜索**：实时搜索，按匹配度排列结果
- **文件分享**：内置文件展示页，支持按文件夹分类浏览
- **音乐播放器**：上传 mp3/flac 等音频即可启用，跨页面持续播放
- **友链管理**：支持访客申请，后台审核
- **全局背景**：可配置 PC / 移动端独立背景图，支持模糊与透明度
- **深色模式**：跟随系统偏好，可手动切换，无闪烁
- **后台管理**：文章编辑、分类/专题管理、站点设置、文件上传一体化后台
- **邮件通知**：新友链申请自动发送邮件通知（基于 PHPMailer）

## 目录结构

```
├── index.php              # 前台首页
├── post.php               # 文章详情（覆盖层，无页面跳转）
├── database.sql           # 数据库结构 + 初始数据
├── api/
│   ├── posts.php          # 文章 REST API
│   └── collections.php    # 专题 REST API
├── admin/                 # 后台管理
│   ├── index.php          # 后台入口（重定向到 dashboard）
│   ├── login.php          # 登录页
│   ├── dashboard.php      # 控制台
│   ├── posts.php          # 文章列表
│   ├── post_edit.php      # 文章编辑器
│   ├── categories.php     # 分类管理
│   ├── collections.php    # 专题管理
│   ├── files.php          # 文件管理
│   ├── upload.php         # 文件上传接口
│   ├── settings.php       # 站点设置
│   └── password.php       # 修改密码
├── includes/
│   ├── db.php             # 数据库连接配置
│   ├── auth.php           # 后台鉴权
│   └── admin_credentials.php  # 后台账号密码
└── email/
    └── mailer.php         # 邮件发送（PHPMailer）
```

## 部署步骤

### 1. 环境要求

| 组件 | 版本要求 |
|------|----------|
| PHP | ≥ 7.4，需开启 PDO、PDO_MySQL、fileinfo 扩展 |
| MySQL | ≥ 5.7，或 MariaDB ≥ 10.3 |
| Web 服务器 | Apache / Nginx |

### 2. 导入数据库

```bash
# 创建数据库
mysql -u root -p -e "CREATE DATABASE blog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 导入结构与初始数据
mysql -u root -p blog < database.sql
```

或使用 phpMyAdmin / Navicat 直接导入 `database.sql`。

### 3. 配置数据库连接

编辑 `includes/db.php`，修改以下常量：

```php
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'blog');      // 数据库名
define('DB_USER', 'root');      // 数据库用户名
define('DB_PASS', '');          // 数据库密码
```

### 4. 配置 Web 服务器

**Nginx：**

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/blog;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;   # !!!注意这里填写你php -v后实际显示的版本号！！！
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 禁止直接访问敏感文件
    location ~ /includes/admin_credentials\.php {
        deny all;
    }
}
```

**Apache**（需开启 `mod_rewrite`）：

项目根目录创建 `.htaccess`：

```apache
Options -Indexes
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?$1 [QSA,L]

# 禁止访问敏感文件
<Files "admin_credentials.php">
    Require all denied
</Files>
```
**php安装必要拓展**
```bash
sudo apt install php-fpm php-mysql php-gd php-mbstring -y
sudo php -v   #查看php版本
```

### 5. 修改后台账号密码

**默认账号：** `admin`  
**默认密码：** `login@jac`

> ⚠️ 部署后请立即登录后台，前往「修改密码」页面更改默认密码。

也可直接编辑 `includes/admin_credentials.php`：

```php
$current_user = 'your_username';
$current_pass = 'your_password';  // 明文存储，请使用强密码并限制文件访问权限
```

### 6. 目录权限

确保以下目录对 Web 服务器进程可写：

```bash
chmod 755 uploads/
chmod 755 uploads/picture/
# 如启用音乐播放器
mkdir -p uploads/music && chmod 755 uploads/music/
```

## 后台使用

访问 `/admin/` 进入后台管理。

- **控制台**：查看文章、分类、专题数量概览
- **文章管理**：新建/编辑/删除文章，支持 HTML 富文本正文
- **分类 / 专题**：管理文章归属，专题支持封面图和描述
- **文件管理**：上传文件，支持设置文件夹、控制显示
- **站点设置**：配置站点名称、头像、简介、社交链接、全局背景、音乐播放器开关等

## 音乐播放器

将 `.mp3` / `.flac` / `.m4a` / `.ogg` / `.wav` 文件上传到 `uploads/music/` 目录，然后在后台「站点设置」中启用音乐播放器即可。播放列表自动读取该目录下的所有音频文件，跨页面连续播放不中断。

## 邮件通知配置

如需在访客提交友链申请时收到邮件通知，admin后台里添加smtp服务信息



## API 说明

### 文章接口 `GET /api/posts.php`

| 参数 | 说明 | 示例 |
|------|------|------|
| `id` | 单篇文章详情 | `?id=1` |
| `search` | 全文搜索 | `?search=关键词` |
| `category` | 按分类 slug 筛选 | `?category=design` |
| `collection` | 按专题 ID 筛选 | `?collection=2` |
| `limit` | 返回条数（默认 10） | `?limit=5` |
| `offset` | 分页偏移 | `?offset=10` |

### 专题接口 `GET /api/collections.php`

返回所有专题列表，无参数。

## 注意事项

- `includes/admin_credentials.php` 包含后台明文密码，**不要提交到公开仓库**，已在 `.gitignore` 中排除（如没有请手动添加）
- `includes/db.php` 包含数据库密码，同样建议加入 `.gitignore`
- `uploads/` 目录下的用户上传内容不应纳入版本控制

建议在项目根目录添加 `.gitignore`：

```
includes/db.php
includes/admin_credentials.php
uploads/
email/vendor/
```

## 项目地址
[昨夜书](https://catvb.com "参观博客")
