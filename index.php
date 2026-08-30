<?php
require_once __DIR__ . '/includes/db.php';

$pdo = db();

// ---- 站点设置 ----
function get_site_settings(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT `key`,`value` FROM site_settings")->fetchAll();
        $map  = [];
        foreach ($rows as $r) $map[$r['key']] = $r['value'];
        return $map;
    } catch (Exception $e) {
        return []; // 表不存在时静默降级
    }
}
$site = get_site_settings($pdo);
$ss   = function(string $k, string $default = '') use ($site) { return $site[$k] ?? $default; };

// ---- 全局背景图配置 ----
$gb_enabled  = $ss('global_bg_enabled', '0') === '1';
$gb_mode     = $ss('global_bg_mode', 'shared');
$gb_url_shared = $ss('global_bg_url', '');
$gb_url_pc     = $ss('global_bg_url_pc', '');
$gb_url_mobile = $ss('global_bg_url_mobile', '');
$gb_opacity  = intval($ss('global_bg_opacity', '30'));
$gb_blur     = intval($ss('global_bg_blur', '0'));
$gb_position = $ss('global_bg_position', 'center');
$gb_pc_on    = $ss('global_bg_pc_enabled', '1') === '1';
$gb_mob_on   = $ss('global_bg_mobile_enabled', '1') === '1';

// ---- 筛选参数 ----
$filter_category   = trim($_GET['category']   ?? '');  // slug
$filter_collection = (int)($_GET['collection'] ?? 0);  // id

// ---- 构建 WHERE 条件 ----
$where_parts = ['p.is_published = 1'];
$bind_params = [];

$current_category   = null;
$current_collection = null;

if ($filter_category !== '') {
    $cat_stmt = $pdo->prepare('SELECT id, name, slug FROM categories WHERE slug = ?');
    $cat_stmt->execute([$filter_category]);
    $current_category = $cat_stmt->fetch();
    if ($current_category) {
        $where_parts[] = 'p.category_id = ?';
        $bind_params[]  = $current_category['id'];
    }
}

if ($filter_collection > 0) {
    $col_stmt = $pdo->prepare('SELECT id, title FROM collections WHERE id = ?');
    $col_stmt->execute([$filter_collection]);
    $current_collection = $col_stmt->fetch();
    if ($current_collection) {
        $where_parts[] = 'p.collection_id = ?';
        $bind_params[]  = $current_collection['id'];
    }
}

$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

// ---- 分页参数 ----
$per_page = 5;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

// ---- 文章总数 ----
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM posts p $where_sql");
$count_stmt->execute($bind_params);
$total       = (int)$count_stmt->fetchColumn();
$total_pages = (int)ceil($total / $per_page);
$page        = min($page, max(1, $total_pages)); // clamp
$offset      = ($page - 1) * $per_page;

// ---- 当前页文章 ----
$stmt = $pdo->prepare(
    "SELECT p.id, p.title, p.summary, p.cover_url, p.published_at,
            c.name AS category_name, c.slug AS category_slug,
            col.title AS collection_title, col.id AS collection_id
     FROM posts p
     LEFT JOIN categories c ON c.id = p.category_id
     LEFT JOIN collections col ON col.id = p.collection_id
     $where_sql
     ORDER BY p.published_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute([...$bind_params, $per_page, $offset]);
$posts = $stmt->fetchAll();

// ---- 所有专题（侧边栏 + 首页展示用） ----
$collections = $pdo
    ->query('SELECT id, title, description, cover_url FROM collections ORDER BY sort_order ASC')
    ->fetchAll();
// 首页最多展示3个
$collections_home = array_slice($collections, 0, 3);

// ---- 所有分类（带文章计数） ----
$categories = $pdo
    ->query('SELECT c.id, c.name, c.slug,
             COUNT(p.id) AS post_count
             FROM categories c
             LEFT JOIN posts p ON p.category_id = c.id AND p.is_published = 1
             GROUP BY c.id
             ORDER BY c.sort_order ASC')
    ->fetchAll();

// ---- 友情链接 ----
try {
    $friend_links = $pdo
        ->query('SELECT name, url, description FROM friend_links ORDER BY sort_order ASC, id ASC')
        ->fetchAll();
} catch (Exception $e) {
    $friend_links = [];
}

// ---- 文件分享列表（uploads/share 目录） ----
$share_files = [];
$share_dir   = __DIR__ . '/uploads/share';
// 扫描一级文件夹
$share_folders = [];
if (is_dir($share_dir)) {
    foreach (scandir($share_dir) as $_sf) {
        if ($_sf === '.' || $_sf === '..') continue;
        $_sfpath = $share_dir . '/' . $_sf;
        if (!is_dir($_sfpath)) continue;
        // 统计文件夹内的文件数量和总大小
        $_count = 0; $_size = 0; $_mtime = filemtime($_sfpath);
        foreach (scandir($_sfpath) as $_ff) {
            if ($_ff === '.' || $_ff === '..') continue;
            $_ffpath = $_sfpath . '/' . $_ff;
            if (!is_file($_ffpath)) continue;
            $_count++;
            $_size += filesize($_ffpath);
            $_mtime = max($_mtime, filemtime($_ffpath));
        }
        $share_folders[] = [
            'name'  => $_sf,
            'count' => $_count,
            'size'  => $_size,
            'mtime' => $_mtime,
        ];
    }
    usort($share_folders, fn($a, $b) => $b['mtime'] - $a['mtime']);
}
// 保留旧的 $share_files 逻辑，用于覆盖层按文件夹动态输出
if (is_dir($share_dir)) {
    foreach (scandir($share_dir) as $_sf) {
        if ($_sf === '.' || $_sf === '..') continue;
        $_sfpath = $share_dir . '/' . $_sf;
        if (!is_file($_sfpath)) continue;
        $share_files[] = [
            'name'   => $_sf,
            'folder' => '',
            'ext'    => strtolower(pathinfo($_sf, PATHINFO_EXTENSION)),
            'size'   => filesize($_sfpath),
            'mtime'  => filemtime($_sfpath),
            'url'    => './uploads/share/' . rawurlencode($_sf),
        ];
    }
    // 也扫描子文件夹内的文件
    foreach ($share_folders as $_folder) {
        $_fpath = $share_dir . '/' . $_folder['name'];
        foreach (scandir($_fpath) as $_ff) {
            if ($_ff === '.' || $_ff === '..') continue;
            $_ffpath = $_fpath . '/' . $_ff;
            if (!is_file($_ffpath)) continue;
            $share_files[] = [
                'name'   => $_ff,
                'folder' => $_folder['name'],
                'ext'    => strtolower(pathinfo($_ff, PATHINFO_EXTENSION)),
                'size'   => filesize($_ffpath),
                'mtime'  => filemtime($_ffpath),
                'url'    => './uploads/share/' . rawurlencode($_folder['name']) . '/' . rawurlencode($_ff),
            ];
        }
    }
    usort($share_files, fn($a, $b) => $b['mtime'] - $a['mtime']);
}
function share_fmt_size(int $bytes): string {
    if ($bytes < 1024)       return $bytes . ' B';
    if ($bytes < 1048576)    return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}
function share_file_icon(string $ext): string {
    if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg','bmp','avif'])) return 'image';
    if (in_array($ext, ['mp4','mov','avi','mkv','webm','flv']))              return 'video';
    if (in_array($ext, ['mp3','wav','ogg','flac','aac','m4a']))              return 'audio';
    if (in_array($ext, ['pdf']))                                              return 'pdf';
    if (in_array($ext, ['zip','rar','7z','tar','gz','bz2','xz']))            return 'zip';
    if (in_array($ext, ['php','js','ts','html','css','py','java','c','cpp','go','rs','json','xml','yaml','yml','sh','sql'])) return 'code';
    if (in_array($ext, ['doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp','csv','rtf'])) return 'doc';
    return 'file';
}

// ---- 友情链接申请表自动建表 ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS friend_link_applications (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        site_name   VARCHAR(100) NOT NULL,
        site_url    VARCHAR(500) NOT NULL,
        description VARCHAR(300),
        email       VARCHAR(200) NOT NULL,
        status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        admin_message TEXT,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 老库升级：补一列“管理员寄语”（已存在则忽略）
    try { $pdo->exec("ALTER TABLE friend_link_applications ADD COLUMN admin_message TEXT"); } catch (Exception $e2) {}
} catch (Exception $e) {}

// ---- 处理友情链接申请提交 ----
$apply_success = (($_GET['apply'] ?? '') === '1');
$apply_error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_friend_link') {
    $is_ajax = (
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false)
    );

    $site_name   = trim($_POST['apply_site_name']   ?? '');
    $site_url    = trim($_POST['apply_site_url']    ?? '');
    $description = trim($_POST['apply_description'] ?? '');
    $email       = trim($_POST['apply_email']       ?? '');

    if (!$site_name || !$site_url || !$email) {
        $apply_error = '请填写网站名称、网站链接和联系邮箱。';
        if ($is_ajax) json_out(['ok' => false, 'error' => $apply_error], 400);
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $apply_error = '请输入有效的邮箱地址。';
        if ($is_ajax) json_out(['ok' => false, 'error' => $apply_error], 400);
    } elseif (!preg_match('#^https?://#i', $site_url)) {
        $apply_error = '网站链接须以 http:// 或 https:// 开头。';
        if ($is_ajax) json_out(['ok' => false, 'error' => $apply_error], 400);
    } else {
        try {
            $pdo->prepare("INSERT INTO friend_link_applications (site_name, site_url, description, email) VALUES (?,?,?,?)")
                ->execute([$site_name, $site_url, $description, $email]);
            // 邮件完全异步：先让页面立即返回成功，再在后台发送（失败写日志/控制台）
            require_once __DIR__ . '/email/mailer.php';
            send_application_received_mail($email, $site_name, $site_url);
            send_admin_new_application_mail($site_name, $site_url, $description, $email);

            if ($is_ajax) {
                json_out(['ok' => true, 'message' => '申请已提交']);
            }

            // PRG（Post/Redirect/Get）：避免刷新重复提交，同时让“提交成功”提示立即显示
            $params = $_GET;
            $params['apply'] = '1';
            $base = strtok($_SERVER['REQUEST_URI'], '?');
            header('Location: ' . $base . '?' . http_build_query($params));
            exit;
        } catch (Exception $e) {
            $apply_error = '提交失败，请稍后重试。';
            if ($is_ajax) json_out(['ok' => false, 'error' => $apply_error], 500);
        }
    }
}

function fmt_date(string $date): string { return date('Y.m.d', strtotime($date)); }
function h(string $str): string { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }

// 分页链接生成（保留筛选参数）
function page_url(int $p): string {
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}

// 判断当前筛选状态
$is_filtered = ($current_category || $current_collection);
$filter_title = '';
if ($current_category)   $filter_title = '# ' . $current_category['name'];
if ($current_collection) $filter_title = '专题：' . $current_collection['title'];
?>
<!DOCTYPE html>
<html lang="zh-CN" class="overflow-x-hidden">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($ss('site_theme_name', ''), ENT_QUOTES, 'UTF-8') ?></title>
    <?php if (!empty($site['site_favicon_url'])): ?>
    <link rel="icon" href="<?= htmlspecialchars($site['site_favicon_url'], ENT_QUOTES, 'UTF-8') ?>">
    <?php else: ?>
    <link rel="icon" type="image/x-icon" href="./uploads/picture/20260513_154140_1a04de12.jpg">
    <?php endif; ?>
    <script>
        // 必须在任何样式表加载前执行：立即应用主题 + 暂时隐藏页面，消灭白闪/深色闪烁
        (function() {
            var saved = localStorage.getItem('theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (saved === 'dark' || (!saved && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
            // 隐藏页面直到样式就绪，防止无样式内容闪现（FOUC）
            document.documentElement.style.visibility = 'hidden';
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' }</script>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@200;400;700&family=Noto+Serif+SC:wght@500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', 'Noto Sans SC', sans-serif; background-color: #fff; color: #1a1a1a; }
        .dark body { background-color: #0f0f0f; color: #e5e5e5; }
        html.dark { background-color: #0f0f0f; }
        /* 主题切换时才启用过渡（由 JS 动态添加 theme-ready 类） */
        html.theme-ready body { transition: background-color 0.25s ease, color 0.25s ease; }
        .serif-cn { font-family: 'Noto Serif SC', serif; }
        @keyframes reveal-in {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .reveal { opacity: 0; }
        /* IntersectionObserver 触发（首次加载滚动）：用 transition */
        .reveal.active { animation: reveal-in 0.55s cubic-bezier(0.22, 1, 0.36, 1) both; }
        #searchBox { transition: all 0.38s cubic-bezier(0.85, 0, 0.15, 1); transform: translateY(-100%); opacity: 0; pointer-events: none; }
        #searchBox.active { transform: translateY(0); opacity: 1; pointer-events: auto; }
        .dark #searchBox { background-color: #0f0f0f; color: #e5e5e5; }
        #overlay { opacity: 0; pointer-events: none; z-index: 100; transition: all 0.38s ease; backdrop-filter: blur(4px); }
        #overlay.show { opacity: 1; pointer-events: auto; }
        .no-scroll { overflow: hidden; height: 100vh; }
        .bg-text { position: absolute; font-size: 18vw; opacity: 0.02; font-weight: 700; z-index: -1; pointer-events: none; white-space: nowrap; font-family: 'Noto Serif SC', serif; }

        /* Dark mode overrides */
        .dark .bg-\[\#fff\] { background-color: #0f0f0f !important; }
        .dark .bg-\[\#fafafa\] { background-color: #161616 !important; }
        .dark .bg-zinc-100 { background-color: #1a1a1a !important; }
        .dark .bg-zinc-200 { background-color: #2a2a2a !important; }
        .dark .border-zinc-100 { border-color: #2a2a2a !important; }
        .dark .border-zinc-200 { border-color: #333 !important; }
        .dark .text-zinc-400 { color: #888 !important; }
        .dark .text-zinc-500 { color: #777 !important; }
        .dark .text-zinc-300 { color: #666 !important; }
        .dark .text-\[\#1a1a1a\] { color: #e5e5e5 !important; }
        .dark .selection\:bg-zinc-100::selection { background-color: #2a2a2a; }
        /* Search box dark */
        .dark #searchInput { background: transparent; color: #e5e5e5; border-color: #333; }
        .dark #searchInput::placeholder { color: #333; }
        .dark #searchInput:focus { border-color: #e5e5e5; }
        .dark #searchResults a:hover { background-color: #1a1a1a; }
        /* Article hover dark */
        .dark .group:hover .group-hover\:text-zinc-500 { color: #aaa !important; }
        /* Pagination dark */
        .dark .border-zinc-200.hover\:border-zinc-900 { border-color: #333; color: #888; }
        .dark .bg-zinc-900 { background-color: #e5e5e5 !important; color: #0f0f0f !important; }
        /* Article cover image */
        .article-cover { transition: transform 0.55s cubic-bezier(0.22, 1, 0.36, 1); }
        .group:hover .article-cover { transform: scale(1.06); }
        .article-cover-wrap { overflow: hidden; isolation: isolate; }
        /* Read more hover underline */
        .read-more {
            display: inline-block;
            font-size: 10px;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: #1a1a1a;
            padding: 7px 14px;
            border: 1px solid #d4d4d8;
            transition: border-color 0.2s ease, color 0.2s ease;
            text-decoration: none;
        }
        .read-more:hover { border-color: #1a1a1a; }
        .dark .read-more { color: #e5e5e5; border-color: #3f3f46; }
        .dark .read-more:hover { border-color: #e5e5e5; }

        /* ---- 音乐播放器（内嵌于 hero） ---- */
        #music-player {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            opacity: 0;
            transform: translateY(6px);
            transition: opacity 0.6s ease, transform 0.6s ease;
        }
        #music-player.mp-visible { opacity: 1; transform: translateY(0); }
        /* 控制行：上一首 — 播放 — 下一首，完全对称 */
        #mp-controls {
            display: flex;
            align-items: center;
            gap: 24px;
        }
        #mp-play-btn {
            width: 40px; height: 40px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%;
            border: 1px solid currentColor;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.15s;
            flex-shrink: 0;
            opacity: 1;
        }
        #mp-play-btn:hover { opacity: 1; transform: scale(1.08); }
        #mp-play-btn svg { width: 14px; height: 14px; }
        #mp-prev-btn, #mp-next-btn {
            cursor: pointer;
            opacity: 0.6;
            transition: opacity 0.2s;
            display: flex; align-items: center;
            width: 40px; height: 40px;
            justify-content: center;
        }
        #mp-prev-btn:hover, #mp-next-btn:hover { opacity: 0.8; }
        #mp-prev-btn svg, #mp-next-btn svg { width: 13px; height: 13px; }
        /* 曲名行：左侧音符 — 居中曲名 — 右侧音符，三等分 */
        #mp-meta {
            display: flex;
            align-items: center;
            width: 100%;
            justify-content: center;
            gap: 0;
        }
        .mp-bars-side {
            display: none;
            align-items: flex-end;
            gap: 2px;
            height: 10px;
            opacity: 0.6;
            flex-shrink: 0;
            width: 20px;
        }
        .mp-bars-side.active { display: flex; }
        .mp-bars-side span {
            display: block; width: 2px; border-radius: 1px;
            background: currentColor;
            animation: mp-bounce 0.9s infinite ease-in-out;
        }
        #mp-bars-left span:nth-child(1){height:5px;animation-delay:0.36s}
        #mp-bars-left span:nth-child(2){height:10px;animation-delay:0.18s}
        #mp-bars-left span:nth-child(3){height:6px;animation-delay:0s}
        #mp-bars-right span:nth-child(1){height:6px;animation-delay:0s}
        #mp-bars-right span:nth-child(2){height:10px;animation-delay:0.18s}
        #mp-bars-right span:nth-child(3){height:5px;animation-delay:0.36s}
        #mp-track-name {
            font-size: 10px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            opacity: 0.65;
            max-width: 160px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            margin: 0 10px;
            text-align: center;
        }
        /* 进度条（曲名下方独立一行） */
        #mp-progress-wrap {
            width: 120px; height: 1px;
            background: currentColor;
            opacity: 0.3;
            cursor: pointer;
            position: relative;
        }
        #mp-progress-bar {
            position: absolute; top: 0; left: 0;
            height: 100%; width: 0%;
            background: currentColor;
            opacity: 1;
            transition: width 0.25s linear;
        }
        @keyframes mp-bounce { 0%,100%{transform:scaleY(0.3)} 50%{transform:scaleY(1)} }

        /* ---- 文章卡片 ---- */
        .article-card {
            position: relative;
            padding: 56px 0;
            transition: background 0.2s ease;
        }
        .article-card::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0;
            width: 100%; height: 1px;
            background: linear-gradient(to right, transparent, #d4d4d8 20%, #d4d4d8 80%, transparent);
        }
        .article-card:last-child::after { display: none; }
        .dark .article-card::after {
            background: linear-gradient(to right, transparent, #2a2a2a 20%, #2a2a2a 80%, transparent);
        }
        .article-card-inner {
            transition: transform 0.35s cubic-bezier(0.22,1,0.36,1);
        }
        .article-card:hover .article-card-inner {
            transform: translateX(0);
        }
        .article-no {
            font-size: 10px;
            letter-spacing: 0.25em;
            color: #d4d4d8;
            font-variant-numeric: tabular-nums;
            line-height: 1;
            user-select: none;
            padding-top: 4px;
        }
        .dark .article-no { color: #333; }

        /* ---- hero 简介文字渐变特效（zyyo/text.html）----
           ① 铺一层渐变背景
           ② background-clip: text 把背景裁剪成文字形状
           ③ 文字填充色透明，渐变透出来
           ④ 背景拉宽到 200%，配合动画让渐变流动
        */
        .hp-bio-gradient {
            background-image: linear-gradient(120deg, #bd34fe, #e0321b 30%, #41d1ff 60%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent !important;
            color: transparent;
            background-size: 200%;
            background-position: 0%;
            animation: hp-bio-flow 10s ease-in-out infinite;
        }
        @keyframes hp-bio-flow {
            0%   { background-position: 100%; }
            25%  { background-position: 50%; }
            50%  { background-position: 0%; }
            75%  { background-position: 50%; }
            100% { background-position: 100%; }
        }
    </style>
</head>
<body class="selection:bg-zinc-100">

<?php if ($gb_enabled): ?>
<?php
// 决定 PC / 移动端各自用哪张图
$_gb_url_pc_final  = ($gb_mode === 'split') ? $gb_url_pc   : $gb_url_shared;
$_gb_url_mob_final = ($gb_mode === 'split') ? $gb_url_mobile : $gb_url_shared;
// 分开模式下的端口开关；共用模式下视为两端都开
$_gb_pc_show  = ($gb_mode === 'shared') ? true  : $gb_pc_on;
$_gb_mob_show = ($gb_mode === 'shared') ? true  : $gb_mob_on;
$_gb_blur_css = $gb_blur > 0 ? 'filter:blur(' . $gb_blur . 'px);transform:scale(1.05)' : '';
?>
<!-- 全局背景图层 -->
<div id="global-bg-wrap" aria-hidden="true" style="position:fixed;inset:0;z-index:-2;overflow:hidden;pointer-events:none;">
  <?php if ($_gb_pc_final = $_gb_url_pc_final): ?>
  <div id="gbLayerPc"
       style="position:absolute;inset:0;background-image:url(<?= htmlspecialchars($_gb_pc_final,ENT_QUOTES,'UTF-8') ?>);background-size:cover;background-position:<?= htmlspecialchars($gb_position,ENT_QUOTES,'UTF-8') ?>;<?= $_gb_blur_css ?>"></div>
  <?php endif; ?>
  <?php if ($_gb_mob_final = $_gb_url_mob_final): ?>
  <div id="gbLayerMobile"
       style="position:absolute;inset:0;background-image:url(<?= htmlspecialchars($_gb_mob_final,ENT_QUOTES,'UTF-8') ?>);background-size:cover;background-position:<?= htmlspecialchars($gb_position,ENT_QUOTES,'UTF-8') ?>;<?= $_gb_blur_css ?>"></div>
  <?php endif; ?>
  <div id="gbOverlay" style="position:absolute;inset:0;background:#000;opacity:<?= round($gb_opacity/100,2) ?>"></div>
</div>
<style>
/* 全局背景 body 透明 */
body { background-color: transparent !important; }
.dark body { background-color: transparent !important; }
html.dark { background-color: transparent !important; }
/* PC 端 */
#gbLayerPc     { display: <?= ($_gb_pc_show && $_gb_url_pc_final)  ? 'block' : 'none' ?>; }
#gbLayerMobile { display: none; }  /* 默认隐藏移动端图层 */
@media (max-width: 767px) {
  #gbLayerPc     { display: none !important; }
  #gbLayerMobile { display: <?= ($_gb_mob_show && $_gb_url_mob_final) ? 'block' : 'none' ?> !important; }
}
</style>
<?php endif; ?>

    <div id="overlay" class="fixed inset-0 bg-black/20"></div>

    <!-- 搜索浮层 -->
    <div id="searchBox" class="fixed inset-0 bg-white z-[150] flex flex-col items-center justify-center px-6">
        <button id="closeSearch" class="absolute top-10 right-10 text-sm tracking-widest uppercase p-4 hover:rotate-90 transition-transform">关闭 ✕</button>
        <div class="w-full max-w-4xl">
            <input id="searchInput" type="text" placeholder="输入关键词，按回车探索..."
                   class="serif-cn text-3xl md:text-6xl border-b border-zinc-100 outline-none w-full py-6 text-center placeholder:text-zinc-100 focus:border-zinc-900 transition-colors">
            <div id="searchResults" class="mt-10 space-y-4 max-h-[50vh] overflow-y-auto text-left hidden"></div>
            <div id="searchHints" class="flex justify-center space-x-6 mt-8 text-[10px] tracking-widest text-zinc-400 uppercase flex-wrap gap-y-2">
                <span>热门搜索:</span>
                <?php foreach ($categories as $cat): ?>
                <a href="?category=<?= h($cat['slug']) ?>" class="hover:text-black"># <?= h($cat['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- 侧边菜单 -->
    <div id="sidebar" class="fixed inset-y-0 right-0 w-full md:w-[500px] bg-zinc-950 text-white z-[110] translate-x-full flex flex-col p-8 md:p-16 overflow-y-auto transition-transform duration-500">
        <div class="flex justify-between items-center mb-12">
            <span class="text-[10px] tracking-[0.4em] opacity-40 uppercase">Navigation</span>
            <button id="closeMenu" class="text-sm p-2 hover:rotate-90 transition-transform">✕</button>
        </div>
        <nav class="space-y-2 mb-12">

            <!-- 文章归档 (可展开分类) -->
            <div class="nav-group">
                <button class="nav-toggle serif-cn text-4xl w-full text-left flex items-center justify-between group py-2 hover:text-zinc-400 transition"
                        data-target="nav-categories">
                    <span>文章归档</span>
                    <svg class="nav-arrow w-5 h-5 opacity-30 flex-shrink-0 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div id="nav-categories" class="nav-sub overflow-hidden max-h-0 transition-all duration-500 ease-in-out">
                    <div class="pt-3 pb-4 pl-1 space-y-1">
                        <a href="index.php"
                           class="flex items-center justify-between text-sm font-light text-zinc-400 hover:text-white py-2 border-b border-white/5 transition <?= !$filter_category && !$filter_collection ? 'text-white' : '' ?>">
                            <span>全部文章</span>
                            <span class="text-[10px] text-zinc-600"><?= $total ?> 篇</span>
                        </a>
                        <?php foreach ($categories as $cat): ?>
                        <a href="?category=<?= h($cat['slug']) ?>"
                           class="flex items-center justify-between text-sm font-light text-zinc-400 hover:text-white py-2 border-b border-white/5 transition <?= ($filter_category === $cat['slug']) ? 'text-white' : '' ?>">
                            <span># <?= h($cat['name']) ?></span>
                            <span class="text-[10px] text-zinc-600"><?= (int)$cat['post_count'] ?> 篇</span>
                        </a>
                        <?php endforeach; ?>
                        <?php if (empty($categories)): ?>
                        <p class="text-xs text-zinc-600 py-2">暂无分类</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 专题系列 (可展开专题) -->
            <div class="nav-group">
                <button class="nav-toggle serif-cn text-4xl w-full text-left flex items-center justify-between group py-2 hover:text-zinc-400 transition"
                        data-target="nav-collections">
                    <span>专题系列</span>
                    <svg class="nav-arrow w-5 h-5 opacity-30 flex-shrink-0 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div id="nav-collections" class="nav-sub overflow-hidden max-h-0 transition-all duration-500 ease-in-out">
                    <div class="pt-3 pb-4 pl-1 space-y-1">
                        <?php foreach ($collections as $col): ?>
                        <a href="?collection=<?= (int)$col['id'] ?>"
                           class="flex items-center justify-between text-sm font-light text-zinc-400 hover:text-white py-2 border-b border-white/5 transition <?= ($filter_collection === (int)$col['id']) ? 'text-white' : '' ?>">
                            <span><?= h($col['title']) ?></span>
                            <svg class="w-3 h-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </a>
                        <?php endforeach; ?>
                        <?php if (empty($collections)): ?>
                        <p class="text-xs text-zinc-600 py-2">暂无专题</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 友情链接 -->
            <div class="nav-group">
                <button class="nav-toggle serif-cn text-4xl w-full text-left flex items-center justify-between group py-2 hover:text-zinc-400 transition"
                        data-target="nav-friend-links">
                    <span>友情链接</span>
                    <svg class="nav-arrow w-5 h-5 opacity-30 flex-shrink-0 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div id="nav-friend-links" class="nav-sub overflow-hidden max-h-0 transition-all duration-500 ease-in-out">
                    <div class="pt-3 pb-4 pl-1 space-y-1">
                        <?php if (!empty($friend_links)): ?>
                        <?php foreach ($friend_links as $link): ?>
                        <a href="<?= h($link['url']) ?>" target="_blank" rel="noopener noreferrer"
                           class="flex items-center justify-between text-sm font-light text-zinc-400 hover:text-white py-2 border-b border-white/5 transition group/link">
                            <span class="flex items-baseline gap-2 min-w-0 flex-1 mr-2">
                                <span class="flex-shrink-0"><?= h($link['name']) ?></span>
                                <?php if (!empty($link['description'])): ?>
                                <span class="text-[11px] text-zinc-600 group-hover/link:text-zinc-400 transition truncate"><?= h($link['description']) ?></span>
                                <?php endif; ?>
                            </span>
                            <svg class="w-3 h-3 opacity-30 group-hover/link:opacity-70 transition flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                            </svg>
                        </a>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        <!-- 申请友链按钮 -->
                        <button onclick="document.getElementById('friendLinkModal').classList.remove('hidden')"
                                class="flex items-center gap-1.5 text-[11px] text-zinc-500 hover:text-zinc-300 transition mt-3 group/apply">
                            <svg class="w-3 h-3 opacity-50 group-hover/apply:opacity-80 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            <span>申请友情链接</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- 申请友链模态框 -->
            <div id="friendLinkModal" class="hidden fixed inset-0 z-[200] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
                <div class="bg-white border border-zinc-200 w-full max-w-md p-7 relative rounded-sm shadow-xl">
                    <button onclick="document.getElementById('friendLinkModal').classList.add('hidden')"
                            class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-800 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                    <p class="serif-cn text-2xl text-zinc-900 mb-1">申请友情链接</p>
                    <p class="text-[11px] text-zinc-400 mb-6">填写信息后提交，审核通过即展示。</p>

                    <div id="applySuccess" class="text-center py-6 <?= $apply_success ? '' : 'hidden' ?>">
                        <svg class="w-10 h-10 text-zinc-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <p class="text-sm font-medium text-zinc-800">申请已提交，感谢你的关注！</p>
                        <p class="text-xs text-zinc-500 mt-1.5">审核通过后将展示在友情链接中。</p>
                        <button onclick="document.getElementById('friendLinkModal').classList.add('hidden')"
                                class="mt-5 px-5 py-2 bg-zinc-900 hover:bg-zinc-700 text-white text-xs transition rounded-sm">
                            关闭
                        </button>
                    </div>

                    <div id="applyFormWrap" class="<?= $apply_success ? 'hidden' : '' ?>">
                        <p id="applyError" class="text-xs text-red-600 bg-red-50 border border-red-200 px-3 py-2 mb-4 rounded-sm <?= $apply_error ? '' : 'hidden' ?>">
                            <?= $apply_error ? h($apply_error) : '' ?>
                        </p>
                        <form id="friendLinkForm" method="POST" action="" class="space-y-4">
                        <input type="hidden" name="action" value="apply_friend_link">
                        <div>
                            <label class="block text-[10px] text-zinc-500 uppercase tracking-wider mb-1.5">网站名称 <span class="text-red-500">*</span></label>
                            <input type="text" name="apply_site_name" required
                                   value="<?= h($_POST['apply_site_name'] ?? '') ?>"
                                   placeholder="例：张三的小站"
                                   class="w-full bg-zinc-50 border border-zinc-200 text-zinc-900 text-sm px-3 py-2 placeholder-zinc-400 focus:outline-none focus:border-zinc-500 transition rounded-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] text-zinc-500 uppercase tracking-wider mb-1.5">网站链接 <span class="text-red-500">*</span></label>
                            <input type="url" name="apply_site_url" required
                                   value="<?= h($_POST['apply_site_url'] ?? '') ?>"
                                   placeholder="https://example.com"
                                   class="w-full bg-zinc-50 border border-zinc-200 text-zinc-900 text-sm px-3 py-2 placeholder-zinc-400 focus:outline-none focus:border-zinc-500 transition rounded-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] text-zinc-500 uppercase tracking-wider mb-1.5">网站简介</label>
                            <textarea name="apply_description" rows="2"
                                      placeholder="一两句话介绍你的网站（选填）"
                                      class="w-full bg-zinc-50 border border-zinc-200 text-zinc-900 text-sm px-3 py-2 placeholder-zinc-400 focus:outline-none focus:border-zinc-500 transition resize-none rounded-sm"><?= h($_POST['apply_description'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label class="block text-[10px] text-zinc-500 uppercase tracking-wider mb-1.5">联系邮箱 <span class="text-red-500">*</span></label>
                            <input type="email" name="apply_email" required
                                   value="<?= h($_POST['apply_email'] ?? '') ?>"
                                   placeholder="you@example.com"
                                   class="w-full bg-zinc-50 border border-zinc-200 text-zinc-900 text-sm px-3 py-2 placeholder-zinc-400 focus:outline-none focus:border-zinc-500 transition rounded-sm">
                        </div>
                        <div class="flex gap-3 pt-1">
                            <button type="submit"
                                    id="applySubmitBtn"
                                    class="flex-1 py-2.5 bg-zinc-900 text-white text-xs font-medium hover:bg-zinc-700 transition rounded-sm">
                                提交申请
                            </button>
                            <button type="button"
                                    onclick="document.getElementById('friendLinkModal').classList.add('hidden')"
                                    class="px-5 py-2.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-600 text-xs transition rounded-sm">
                                取消
                            </button>
                        </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php if ($apply_success || $apply_error): ?>
            <script>document.addEventListener('DOMContentLoaded',function(){document.getElementById('friendLinkModal').classList.remove('hidden');});</script>
            <?php endif; ?>

            <script>
            // 防止浏览器默认表单提交导致刷新/重复提交：改用 fetch 异步提交，并在前端做“提交中”锁定
            document.addEventListener('DOMContentLoaded', function () {
                var form = document.getElementById('friendLinkForm');
                if (!form) return;

                var btn = document.getElementById('applySubmitBtn');
                var errBox = document.getElementById('applyError');
                var wrap = document.getElementById('applyFormWrap');
                var okBox = document.getElementById('applySuccess');

                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (form.dataset.submitting === '1') return;
                    form.dataset.submitting = '1';

                    if (errBox) { errBox.classList.add('hidden'); errBox.textContent = ''; }
                    if (btn) { btn.disabled = true; btn.textContent = '提交中…'; btn.classList.add('opacity-70'); }

                    var fd = new FormData(form);
                    var url = window.location.href.split('#')[0];

                    fetch(url, {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                    })
                    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
                    .then(function (res) {
                        if (res.ok && res.json && res.json.ok) {
                            if (wrap) wrap.classList.add('hidden');
                            if (okBox) okBox.classList.remove('hidden');
                            return;
                        }
                        var msg = (res.json && res.json.error) ? res.json.error : '提交失败，请稍后重试。';
                        if (errBox) { errBox.textContent = msg; errBox.classList.remove('hidden'); }
                    })
                    .catch(function () {
                        if (errBox) { errBox.textContent = '网络异常，请稍后重试。'; errBox.classList.remove('hidden'); }
                    })
                    .finally(function () {
                        form.dataset.submitting = '0';
                        if (btn) { btn.disabled = false; btn.textContent = '提交申请'; btn.classList.remove('opacity-70'); }
                    });
                });
            });
            </script>

            <!-- 文件分享 (手风琴) -->
            <div class="nav-group">
                <button class="nav-toggle serif-cn text-4xl w-full text-left flex items-center justify-between group py-2 hover:text-zinc-400 transition"
                        data-target="nav-share-folders">
                    <span>文件分享</span>
                    <svg class="nav-arrow w-5 h-5 opacity-30 flex-shrink-0 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div id="nav-share-folders" class="nav-sub overflow-hidden max-h-0 transition-all duration-500 ease-in-out">
                    <div class="pt-3 pb-4 pl-1 space-y-1">
                        <?php if (!empty($share_folders)): ?>
                        <?php foreach ($share_folders as $sf_folder): ?>
                        <button onclick="openFilesOverlay('<?= addslashes(h($sf_folder['name'])) ?>'); document.getElementById('closeMenu').click();"
                                class="flex items-center justify-between w-full text-left text-sm font-light text-zinc-400 hover:text-white py-2 border-b border-white/5 transition">
                            <span class="flex items-center gap-2">
                                <svg class="w-3.5 h-3.5 opacity-40 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                                </svg>
                                <span><?= h($sf_folder['name']) ?></span>
                            </span>
                            <span class="text-[10px] text-zinc-600"><?= (int)$sf_folder['count'] ?> 个文件</span>
                        </button>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <p class="text-xs text-zinc-600 py-2">暂无文件夹</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 关于我 -->
            <div class="py-2">
                <a href="#about" onclick="document.getElementById('closeMenu').click()"
                   class="serif-cn text-4xl block hover:text-zinc-400 transition">关于我</a>
            </div>

        </nav>
        <div class="mt-auto grid grid-cols-2 gap-8 border-t border-zinc-800 pt-10">
            <div>
                <p class="text-[10px] text-zinc-500 uppercase mb-4 tracking-widest">微信公众号</p>
                <?php if ($ss('wechat_qr_url')): ?>
                <img src="<?= htmlspecialchars($ss('wechat_qr_url')) ?>" alt="微信公众号二维码"
                     class="w-24 h-24 object-cover rounded border border-white/10">
                <?php else: ?>
                <div class="w-24 h-24 bg-white/5 flex items-center justify-center rounded text-[10px] text-zinc-500 border border-white/5 italic">[扫码关注]</div>
                <?php endif; ?>
            </div>
            <div>
                <p class="text-[10px] text-zinc-500 uppercase mb-4 tracking-widest">数字足迹</p>
                <div class="flex flex-col space-y-2 text-sm font-light text-zinc-400">
                    <?php
                    $socials_cfg = [
                        'social_github'      => ['label'=>'Github',     'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/></svg>'],
                        'social_youtube'     => ['label'=>'Youtube',    'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>'],
                        'social_bilibili'    => ['label'=>'Bilibili',   'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M17.813 4.653h.854c1.51.054 2.769.578 3.773 1.574 1.004.995 1.524 2.249 1.56 3.76v7.36c-.036 1.51-.556 2.769-1.56 3.773s-2.262 1.524-3.773 1.56H5.333c-1.51-.036-2.769-.556-3.773-1.56S.036 18.858 0 17.347v-7.36c.036-1.511.556-2.765 1.56-3.76 1.004-.996 2.262-1.52 3.773-1.574h.774l-1.174-1.12a1.234 1.234 0 0 1-.373-.906c0-.356.124-.658.373-.907l.027-.027c.267-.249.573-.373.92-.373.347 0 .653.124.92.373L9.653 4.44c.071.071.134.142.187.213h4.267a.836.836 0 0 1 .16-.213l2.853-2.747c.267-.249.573-.373.92-.373.347 0 .662.151.929.4.267.249.391.551.391.907 0 .355-.124.657-.373.906zM5.333 7.24c-.746.018-1.373.276-1.88.773-.506.498-.769 1.13-.786 1.894v7.52c.017.764.28 1.395.786 1.893.507.498 1.134.756 1.88.773h13.334c.746-.017 1.373-.275 1.88-.773.506-.498.769-1.129.786-1.893v-7.52c-.017-.765-.28-1.396-.786-1.894-.507-.497-1.134-.755-1.88-.773zM8 11.107c.373 0 .684.124.933.373.25.249.383.569.4.96v1.173c-.017.391-.15.711-.4.96-.249.25-.56.374-.933.374s-.684-.125-.933-.374c-.25-.249-.383-.569-.4-.96V12.44c0-.373.129-.689.386-.947.258-.257.574-.386.947-.386zm8 0c.373 0 .684.124.933.373.25.249.383.569.4.96v1.173c-.017.391-.15.711-.4.96-.249.25-.56.374-.933.374s-.684-.125-.933-.374c-.25-.249-.383-.569-.4-.96V12.44c.017-.391.15-.711.4-.96.249-.249.56-.373.933-.373z"/></svg>'],
                        'social_twitter'     => ['label'=>'Twitter / X','icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>'],
                        'social_instagram'   => ['label'=>'Instagram',  'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>'],
                        'social_linkedin'    => ['label'=>'LinkedIn',   'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>'],
                        'social_telegram'    => ['label'=>'Telegram',   'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>'],
                        'social_facebook'    => ['label'=>'Facebook',   'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>'],
                        'social_threads'     => ['label'=>'Threads',    'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.868 1.205 8.62.024 12.203 0h.014c2.858.023 5.18.786 6.907 2.27 1.848 1.582 2.87 3.947 3.033 7.029l.004.082h-2.586l-.004-.069c-.155-2.449-.895-4.336-2.201-5.612-1.233-1.204-3.015-1.831-5.312-1.86-2.867.031-5.034.956-6.44 2.748-1.319 1.686-1.99 4.143-1.997 7.299.006 3.155.678 5.611 1.997 7.298 1.407 1.792 3.574 2.717 6.44 2.748 1.973-.027 3.392-.521 4.333-1.507.886-.928 1.344-2.34 1.362-4.198v-.072h-5.87v-2.33h8.442l.004.096c.024.651.028 1.319-.01 1.967-.18 2.932-1.12 5.194-2.798 6.72-1.61 1.465-3.822 2.22-6.574 2.244z"/></svg>'],
                        'social_tiktok'      => ['label'=>'TikTok',     'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>'],
                        'social_weibo'       => ['label'=>'微博',        'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M10.098 20.323c-3.977.391-7.414-1.406-7.672-4.02-.259-2.609 2.759-5.047 6.74-5.441 3.979-.394 7.413 1.404 7.671 4.018.259 2.6-2.759 5.049-6.739 5.443zM9.05 17.219c-.384.616-1.208.884-1.829.602-.612-.279-.793-.991-.406-1.593.379-.595 1.176-.861 1.793-.601.622.263.826.968.442 1.592zm2.73-1.511c-.141.237-.449.353-.689.253-.236-.09-.313-.361-.177-.586.138-.227.436-.346.672-.24.239.1.323.352.194.573zm2.218-5.209c-1.088-.299-2.316-.22-3.2.426L9.75 11.84c-.562.463-1.188 1.2-.896 2.194.285.882 1.284 1.538 2.375 1.498 1.37-.046 2.503-1.107 2.616-2.479.063-.74-.193-1.5-.847-1.553zM19.38 7.9c-.51-.23-1.07-.39-1.62-.45.28-.3.49-.65.59-1.04.37-1.48-.64-2.98-2.26-3.36-1.62-.38-3.26.49-3.63 1.97-.07.28-.07.56-.03.83H12.4c-.06 0-.11.01-.17.01a5.41 5.41 0 0 0-2.03.38l-.22.1C8.29 7.06 7.22 8.66 7.44 10.38c-.02-.01-.03-.01-.05-.02C5.42 9.5 3.46 9.73 2.46 11.04c-1 1.3-.6 3.16.91 4.2a7.88 7.88 0 0 0-.06.8c0 3.51 3.56 6.35 7.95 6.35 4.39 0 7.95-2.84 7.95-6.35 0-.66-.13-1.29-.38-1.89.85-.5 1.46-1.29 1.46-2.25-.01-1.44-1.28-2.65-2.9-2.65 0 0 .01 0 0 0z"/></svg>'],
                        'social_xiaohongshu' => ['label'=>'小红书',      'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.5 10.5h-3v3a1.5 1.5 0 01-3 0v-3h-3a1.5 1.5 0 010-3h3v-3a1.5 1.5 0 013 0v3h3a1.5 1.5 0 010 3z"/></svg>'],
                        'social_zhihu'       => ['label'=>'知乎',        'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M5.721 0C2.251 0 0 2.25 0 5.719V18.28C0 21.751 2.252 24 5.721 24H18.28C21.751 24 24 21.75 24 18.281V5.72C24 2.249 21.75 0 18.281 0zm6.964 6.575l-.917.008v6.31l.917-.001c.35 0 .52.193.52.526v.35c0 .335-.17.527-.52.527H9.243c-.35 0-.52-.192-.52-.527v-.35c0-.333.17-.526.52-.526l.915.001V7.478l-.915-.008c-.35 0-.52-.185-.52-.517v-.362c0-.333.17-.517.52-.517h2.942c.35 0 .52.184.52.517v.362c0 .332-.17.622-.52.622zm5.09 10.9c-.282.376-.71.563-1.138.563h-3.61v-1.348h3.19c.196 0 .313-.074.383-.17l1.546-2.117-1.546-2.118c-.07-.096-.187-.17-.383-.17h-3.19V10.77h3.61c.428 0 .856.186 1.138.562l1.97 2.7c.282.374.282.866 0 1.24l-1.97 2.203z"/></svg>'],
                        'social_douyin'      => ['label'=>'抖音',        'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.32 6.32 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V9.15a8.16 8.16 0 004.77 1.52V7.23a4.85 4.85 0 01-1-.54z"/></svg>'],
                        'social_douban'      => ['label'=>'豆瓣',        'icon'=>'<svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M1.5 5.25h21v1.5h-21zM6 18.75l1.5-7.5h9l1.5 7.5H6zm3.75 2.25h4.5v1.5h-4.5zM10.5 2.25h3v3h-3z"/></svg>'],
                    ];
                    foreach ($socials_cfg as $k => $cfg):
                        $url = $ss($k);
                        if (!$url) continue;
                    ?>
                    <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener"
                       class="flex items-center gap-2.5 hover:text-white transition-colors">
                        <?= $cfg['icon'] ?>
                        <span><?= $cfg['label'] ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 导航栏 -->
    <nav id="topNav" class="fixed top-0 w-full flex justify-between items-center px-6 md:px-16 py-5 md:py-8 z-50 <?= $hero_has_bg ? 'mix-blend-difference text-white' : 'text-[#1a1a1a] dark:text-white' ?>">
        <div class="text-2xl md:text-3xl font-bold tracking-tighter serif-cn italic cursor-pointer">
            <a href="index.php"><?= htmlspecialchars($ss('site_theme_name', ''), ENT_QUOTES, 'UTF-8') ?></a>
        </div>
        <div class="flex items-center space-x-6 md:space-x-10">
            <!-- 深色模式切换 -->
            <button id="themeToggle" class="p-2 hover:opacity-50 transition" aria-label="切换主题">
                <svg id="iconSun" class="w-5 h-5 md:w-6 md:h-6 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z"/></svg>
                <svg id="iconMoon" class="w-5 h-5 md:w-6 md:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12.79A9 9 0 1111.21 3a7 7 0 009.79 9.79z"/></svg>
            </button>
            <button id="openSearch" class="p-2 hover:opacity-50 transition">
                <svg class="w-5 h-5 md:w-6 md:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" stroke-width="1.5"/></svg>
            </button>
            <button id="openMenu" class="group flex items-center space-x-3">
                <span class="text-[10px] md:text-xs uppercase tracking-[0.2em] font-light">目录</span>
                <div class="w-6 h-4 flex flex-col justify-between items-end">
                    <div class="w-6 h-[1px] bg-current"></div>
                    <div class="w-4 h-[1px] bg-current group-hover:w-6 transition-all"></div>
                </div>
            </button>
        </div>
    </nav>

    <!-- Hero (只在第一页且未筛选时显示) -->
    <?php
    // 首页个人卡片数据（从 site_settings 读取，有默认值）
    $hp_avatar  = $ss('homepage_avatar', 'https://i.pravatar.cc/200?img=68');
    $hp_name    = $ss('homepage_name', 'Jeremy Bentham');
    $hp_bio     = $ss('homepage_bio', '保持理想，步履不停。');
    $hp_bg_url  = $ss('homepage_bg_url', '');
    $hp_bg_opacity = intval($ss('homepage_bg_opacity', '30'));
    $hp_bg_blur = intval($ss('homepage_bg_blur', '0'));
    $hp_bg_position = $ss('homepage_bg_position', 'center');
    $hp_bg_mobile_enabled = $ss('homepage_bg_mobile_enabled', '0') === '1';
    // 全局背景图启用时，压制主页背景图（全局背景已在固定层渲染，避免重复）
    if ($gb_enabled) { $hp_bg_url = ''; }
    // hero 文字白色判断：主页背景图 或 全局背景图 任一有效时均用白色
    $hero_has_bg = !empty($ss('homepage_bg_url','')) || $gb_enabled;
    $hp_avatar_no_frame = $ss('homepage_avatar_no_frame', '0') === '1';
    $hp_music_player_enabled = $ss('homepage_music_player_enabled', '0') === '1';

    // 社交链接配置（用于首页图标展示）
    $hp_socials = [
        'social_github'      => ['label'=>'GitHub',     'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/></svg>'],
        'social_bilibili'    => ['label'=>'Bilibili',   'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.813 4.653h.854c1.51.054 2.769.578 3.773 1.574 1.004.995 1.524 2.249 1.56 3.76v7.36c-.036 1.51-.556 2.769-1.56 3.773s-2.262 1.524-3.773 1.56H5.333c-1.51-.036-2.769-.556-3.773-1.56S.036 18.858 0 17.347v-7.36c.036-1.511.556-2.765 1.56-3.76 1.004-.996 2.262-1.52 3.773-1.574h.774l-1.174-1.12a1.234 1.234 0 0 1-.373-.906c0-.356.124-.658.373-.907l.027-.027c.267-.249.573-.373.92-.373.347 0 .653.124.92.373L9.653 4.44c.071.071.134.142.187.213h4.267a.836.836 0 0 1 .16-.213l2.853-2.747c.267-.249.573-.373.92-.373.347 0 .662.151.929.4.267.249.391.551.391.907 0 .355-.124.657-.373.906zM5.333 7.24c-.746.018-1.373.276-1.88.773-.506.498-.769 1.13-.786 1.894v7.52c.017.764.28 1.395.786 1.893.507.498 1.134.756 1.88.773h13.334c.746-.017 1.373-.275 1.88-.773.506-.498.769-1.129.786-1.893v-7.52c-.017-.765-.28-1.396-.786-1.894-.507-.497-1.134-.755-1.88-.773zM8 11.107c.373 0 .684.124.933.373.25.249.383.569.4.96v1.173c-.017.391-.15.711-.4.96-.249.25-.56.374-.933.374s-.684-.125-.933-.374c-.25-.249-.383-.569-.4-.96V12.44c0-.373.129-.689.386-.947.258-.257.574-.386.947-.386zm8 0c.373 0 .684.124.933.373.25.249.383.569.4.96v1.173c-.017.391-.15.711-.4.96-.249.25-.56.374-.933.374s-.684-.125-.933-.374c-.25-.249-.383-.569-.4-.96V12.44c.017-.391.15-.711.4-.96.249-.249.56-.373.933-.373z"/></svg>'],
        'social_instagram'   => ['label'=>'Instagram',  'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>'],
        'social_twitter'     => ['label'=>'Twitter',    'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>'],
        'social_youtube'     => ['label'=>'YouTube',    'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M21.593 7.203a2.506 2.506 0 00-1.762-1.766C18.265 5.007 12 5 12 5s-6.264-.007-7.831.404a2.56 2.56 0 00-1.766 1.778c-.413 1.566-.417 4.814-.417 4.814s-.004 3.264.406 4.814c.23.857.905 1.534 1.763 1.765 1.582.43 7.83.437 7.83.437s6.265.007 7.831-.403a2.515 2.515 0 001.767-1.763c.414-1.565.417-4.812.417-4.812s.02-3.265-.407-4.831zM9.996 15.005l.005-6 5.207 3.005-5.212 2.995z"/></svg>'],
        'social_linkedin'    => ['label'=>'LinkedIn',   'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>'],
        'social_telegram'    => ['label'=>'Telegram',   'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>'],
        'social_facebook'    => ['label'=>'Facebook',   'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>'],
        'social_threads'     => ['label'=>'Threads',    'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.868 1.205 8.62.024 12.203 0h.014c2.858.023 5.18.786 6.907 2.27 1.848 1.582 2.87 3.947 3.033 7.029l.004.082h-2.586l-.004-.069c-.155-2.449-.895-4.336-2.201-5.612-1.233-1.204-3.015-1.831-5.312-1.86-2.867.031-5.034.956-6.44 2.748-1.319 1.686-1.99 4.143-1.997 7.299.006 3.155.678 5.611 1.997 7.298 1.407 1.792 3.574 2.717 6.44 2.748 1.973-.027 3.392-.521 4.333-1.507.886-.928 1.344-2.34 1.362-4.198v-.072h-5.87v-2.33h8.442l.004.096c.024.651.028 1.319-.01 1.967-.18 2.932-1.12 5.194-2.798 6.72-1.61 1.465-3.822 2.22-6.574 2.244z"/></svg>'],
        'social_tiktok'      => ['label'=>'TikTok',     'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>'],
        'social_weibo'       => ['label'=>'微博',        'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M10.098 20.323c-3.977.391-7.414-1.406-7.672-4.02-.259-2.609 2.759-5.047 6.74-5.441 3.979-.394 7.413 1.404 7.671 4.018.259 2.6-2.759 5.049-6.739 5.443zM9.05 17.219c-.384.616-1.208.884-1.829.602-.612-.279-.793-.991-.406-1.593.379-.595 1.176-.861 1.793-.601.622.263.826.968.442 1.592zm2.73-1.511c-.141.237-.449.353-.689.253-.236-.09-.313-.361-.177-.586.138-.227.436-.346.672-.24.239.1.323.352.194.573zm2.218-5.209c-1.088-.299-2.316-.22-3.2.426L9.75 11.84c-.562.463-1.188 1.2-.896 2.194.285.882 1.284 1.538 2.375 1.498 1.37-.046 2.503-1.107 2.616-2.479.063-.74-.193-1.5-.847-1.553zM19.38 7.9c-.51-.23-1.07-.39-1.62-.45.28-.3.49-.65.59-1.04.37-1.48-.64-2.98-2.26-3.36-1.62-.38-3.26.49-3.63 1.97-.07.28-.07.56-.03.83H12.4c-.06 0-.11.01-.17.01a5.41 5.41 0 0 0-2.03.38l-.22.1C8.29 7.06 7.22 8.66 7.44 10.38c-.02-.01-.03-.01-.05-.02C5.42 9.5 3.46 9.73 2.46 11.04c-1 1.3-.6 3.16.91 4.2a7.88 7.88 0 0 0-.06.8c0 3.51 3.56 6.35 7.95 6.35 4.39 0 7.95-2.84 7.95-6.35 0-.66-.13-1.29-.38-1.89.85-.5 1.46-1.29 1.46-2.25-.01-1.44-1.28-2.65-2.9-2.65 0 0 .01 0 0 0z"/></svg>'],
        'social_xiaohongshu' => ['label'=>'小红书',      'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.5 10.5h-3v3a1.5 1.5 0 01-3 0v-3h-3a1.5 1.5 0 010-3h3v-3a1.5 1.5 0 013 0v3h3a1.5 1.5 0 010 3z"/></svg>'],
        'social_zhihu'       => ['label'=>'知乎',        'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M5.721 0C2.251 0 0 2.25 0 5.719V18.28C0 21.751 2.252 24 5.721 24H18.28C21.751 24 24 21.75 24 18.281V5.72C24 2.249 21.75 0 18.281 0zm6.964 6.575l-.917.008v6.31l.917-.001c.35 0 .52.193.52.526v.35c0 .335-.17.527-.52.527H9.243c-.35 0-.52-.192-.52-.527v-.35c0-.333.17-.526.52-.526l.915.001V7.478l-.915-.008c-.35 0-.52-.185-.52-.517v-.362c0-.333.17-.517.52-.517h2.942c.35 0 .52.184.52.517v.362c0 .332-.17.622-.52.622zm5.09 10.9c-.282.376-.71.563-1.138.563h-3.61v-1.348h3.19c.196 0 .313-.074.383-.17l1.546-2.117-1.546-2.118c-.07-.096-.187-.17-.383-.17h-3.19V10.77h3.61c.428 0 .856.186 1.138.562l1.97 2.7c.282.374.282.866 0 1.24l-1.97 2.203z"/></svg>'],
        'social_douyin'      => ['label'=>'抖音',        'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.32 6.32 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V9.15a8.16 8.16 0 004.77 1.52V7.23a4.85 4.85 0 01-1-.54z"/></svg>'],
        'social_douban'      => ['label'=>'豆瓣',        'icon'=>'<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M1.5 5.25h21v1.5h-21zM6 18.75l1.5-7.5h9l1.5 7.5H6zm3.75 2.25h4.5v1.5h-4.5zM10.5 2.25h3v3h-3z"/></svg>'],
    ]
    ?>
    <?php
    // 背景图始终放在独立 div 层，不用 header inline style，方便移动端通过 CSS 隐藏
    $header_extra_classes = 'bg-[#fff] dark:bg-zinc-950';
    // 有背景图时 header 本身不设背景色（背景由子 div 层承载）
    if ($hp_bg_url || $gb_enabled) {
        $header_extra_classes = '';
    }
    ?>

    <?php if ($hp_bg_url && !$hp_bg_mobile_enabled): ?>
    <style>
    /* 移动端：隐藏背景图层和遮罩，恢复 header 默认背景色 */
    @media (max-width: 767px) {
        #site-header { background-color: #fff; }
        .hp-bg-layer { display: none !important; }
        .hp-bg-overlay { display: none !important; }
        .hp-ring-bg { --tw-ring-color: transparent !important; }
        /* 亮色模式下文字改为深色 */
        .hp-bg-text-white { color: #1a1a1a !important; }
        .hp-bg-text-white-70 { color: rgb(113 113 122) !important; }
        .hp-bg-text-white-50 { color: rgb(161 161 170) !important; }
        .hp-bg-text-white-40 { color: rgb(212 212 216) !important; }
        .hp-bg-text-white-25 { color: rgb(228 228 231) !important; }
    }
    /* 移动端深色模式：背景深色，文字用亮色（写在亮色规则后面，选择器更具体，确保覆盖） */
    @media (max-width: 767px) {
        html.dark #site-header { background-color: #030712; }
        html.dark #site-header .hp-bg-text-white { color: #e5e5e5 !important; }
        html.dark #site-header .hp-bg-text-white-70 { color: rgba(229,229,229,0.7) !important; }
        html.dark #site-header .hp-bg-text-white-50 { color: rgba(229,229,229,0.5) !important; }
        html.dark #site-header .hp-bg-text-white-40 { color: rgba(229,229,229,0.4) !important; }
        html.dark #site-header .hp-bg-text-white-25 { color: rgba(229,229,229,0.25) !important; }
    }
    </style>
    <?php endif; ?>

    <?php
    // 全局背景启用但移动端不显示时，移动端 hero 需恢复默认文字色
    $_gb_mob_hidden = $gb_enabled && ($gb_mode === 'split') && !$gb_mob_on;
    $_gb_mob_shared_hidden = $gb_enabled && ($gb_mode === 'shared') && false; // 共用模式两端都显示
    if ($gb_enabled && ($gb_mode === 'split') && !$gb_mob_on):
    ?><style>
    @media (max-width: 767px) {
        body { background-color: #fff !important; }
        html.dark body { background-color: #0f0f0f !important; }
        #site-header { background-color: #fff; }
        html.dark #site-header { background-color: #030712; }
        .hp-bg-text-white { color: #1a1a1a !important; }
        .hp-bg-text-white-70 { color: rgb(113 113 122) !important; }
        .hp-bg-text-white-50 { color: rgb(161 161 170) !important; }
        .hp-bg-text-white-40 { color: rgb(212 212 216) !important; }
        .hp-bg-text-white-25 { color: rgb(228 228 231) !important; }
        html.dark .hp-bg-text-white { color: #e5e5e5 !important; }
        html.dark .hp-bg-text-white-70 { color: rgba(229,229,229,0.7) !important; }
    }
    </style>
    <?php endif; ?>

    <header id="site-header" class="<?= ($page > 1 || $is_filtered) ? 'hidden' : 'min-h-screen flex items-center justify-center' ?> px-6 relative overflow-hidden <?= $header_extra_classes ?>">
        <?php if ($hp_bg_url): ?>
        <!-- 背景图层（独立 div，移动端可通过 CSS 隐藏） -->
        <div class="hp-bg-layer absolute inset-0" style="background-image:url(<?= h($hp_bg_url) ?>);background-size:cover;background-position:<?= h($hp_bg_position) ?><?= $hp_bg_blur > 0 ? ';filter:blur(' . $hp_bg_blur . 'px);transform:scale(1.05)' : '' ?>"></div>
        <!-- 遮罩层 -->
        <div class="hp-bg-overlay absolute inset-0 bg-black" style="opacity:<?= round($hp_bg_opacity / 100, 2) ?>"></div>
        <?php endif; ?>
        <div class="flex flex-col items-center text-center reveal relative z-10">
            <!-- 头像 -->
            <?php if (!$hp_avatar_hidden): ?>
            <div class="mb-6">
                <img src="<?= h($hp_avatar) ?>" alt="<?= h($hp_name) ?>"
                     class="w-24 h-24 object-cover rounded-full <?= ($hp_bg_url && !$hp_avatar_no_frame) ? 'ring-2 ring-white/30 hp-ring-bg' : 'ring-2 ring-transparent' ?>"
                     onerror="this.src='https://i.pravatar.cc/200?img=68'">
            </div>
            <?php endif; ?>
            <!-- 姓名 -->
            <h1 class="serif-cn text-2xl md:text-3xl font-medium mb-4 <?= $hero_has_bg ? 'text-white hp-bg-text-white' : 'text-[#1a1a1a]' ?>"><?= h($hp_name) ?></h1>
            <!-- 简介 -->
            <p class="hp-bio-gradient text-sm font-light max-w-sm leading-relaxed mb-6 <?= $hero_has_bg ? 'text-white/70 hp-bg-text-white-70' : 'text-zinc-500' ?>">
                <?= $hp_bio ?>
            </p>
            <!-- 音乐播放器（内嵌，社交图标上方） -->
            <?php if ($hp_music_player_enabled): ?>
            <div id="music-player" class="mt-2 mb-5 <?= $hero_has_bg ? 'text-white hp-bg-text-white' : 'text-[#1a1a1a]' ?>">
                <!-- 控制行：对称布局 上一首 · 播放 · 下一首 -->
                <div id="mp-controls">
                    <div id="mp-prev-btn" title="上一首">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 5l-7 7 7 7M5 5v14"/>
                        </svg>
                    </div>
                    <div id="mp-play-btn" title="播放/暂停">
                        <svg id="mp-icon-play" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M8 5v14l11-7z"/>
                        </svg>
                        <svg id="mp-icon-pause" fill="currentColor" viewBox="0 0 24 24" style="display:none">
                            <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>
                        </svg>
                    </div>
                    <div id="mp-next-btn" title="下一首">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 5l7 7-7 7M19 5v14"/>
                        </svg>
                    </div>
                </div>
                <!-- 曲名行：左侧音符 · 居中曲名 · 右侧音符 -->
                <div id="mp-meta">
                    <div id="mp-bars-left" class="mp-bars-side">
                        <span></span><span></span><span></span>
                    </div>
                    <span id="mp-track-name">— —</span>
                    <div id="mp-bars-right" class="mp-bars-side">
                        <span></span><span></span><span></span>
                    </div>
                </div>
                <!-- 进度条 -->
                <div id="mp-progress-wrap" title="进度">
                    <div id="mp-progress-bar"></div>
                </div>
            </div>
            <?php endif; ?>
            <!-- 社交图标 -->
            <div class="flex items-center gap-5 <?= $hero_has_bg ? 'text-white hp-bg-text-white' : 'text-[#1a1a1a]' ?>">
                <?php foreach ($hp_socials as $key => $cfg):
                    $url = $ss($key);
                    if (!$url) continue; ?>
                <a href="<?= h($url) ?>" target="_blank" rel="noopener"
                   title="<?= h($cfg['label']) ?>"
                   class="hover:opacity-40 transition-opacity duration-200">
                    <?= $cfg['icon'] ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- 下滑提示 -->
        <div id="scrollHint" class="absolute left-1/2 -translate-x-1/2 flex flex-col items-center space-y-2 transition-opacity duration-700 z-10" style="bottom: max(2.5rem, env(safe-area-inset-bottom, 0px) + 1.5rem);">
            <span class="text-[10px] tracking-[0.3em] <?= $hero_has_bg ? 'text-white/50 hp-bg-text-white-50' : 'text-zinc-400' ?> uppercase">向下探索</span>
            <div class="flex flex-col items-center space-y-1">
                <svg class="w-4 h-4 <?= $hero_has_bg ? 'text-white/40 hp-bg-text-white-40' : 'text-zinc-300' ?> animate-bounce" style="animation-delay:0s" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7"/>
                </svg>
                <svg class="w-4 h-4 <?= $hero_has_bg ? 'text-white/25 hp-bg-text-white-25' : 'text-zinc-200' ?> animate-bounce" style="animation-delay:0.15s" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7"/>
                </svg>
            </div>
        </div>
    </header>

    <!-- 精选专题（只在第一页且未筛选时显示） -->
    <?php if ($page === 1 && !$is_filtered): ?>
    <section id="collections-section" class="px-6 md:px-16 py-24 bg-[#fafafa]">
        <div class="max-w-7xl mx-auto">
            <div class="flex items-baseline justify-between mb-16 border-b border-zinc-200 pb-4">
                <h2 class="serif-cn text-2xl">精选专题</h2>
                <span class="text-[10px] text-zinc-400 tracking-widest uppercase">Collections</span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-12">
                <?php foreach ($collections_home as $i => $col): ?>
                <a href="?collection=<?= (int)$col['id'] ?>" class="reveal group cursor-pointer block" <?= $i > 0 ? 'style="transition-delay:' . ($i * 0.1) . 's;"' : '' ?>>
                    <div class="aspect-[16/10] overflow-hidden bg-zinc-200 mb-6">
                        <?php if ($col['cover_url']): ?>
                        <img src="<?= h($col['cover_url']) ?>" alt="<?= h($col['title']) ?>"
                             class="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-105" loading="lazy">
                        <?php endif; ?>
                    </div>
                    <h3 class="serif-cn text-xl mb-3 group-hover:text-zinc-500 transition"># <?= h($col['title']) ?></h3>
                    <p class="text-xs text-zinc-400 font-light leading-relaxed"><?= h($col['description']) ?></p>
                </a>
                <?php endforeach; ?>
                <?php if (empty($collections_home)): ?>
                <p class="col-span-3 text-zinc-400 text-sm">暂无专题。</p>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- 文章列表 -->
    <main class="px-6 md:px-16 py-32">
        <div class="max-w-5xl mx-auto">

            <!-- 筛选状态提示条 -->
            <?php if ($is_filtered): ?>
            <div class="flex items-center justify-between mb-16 pb-6 border-b border-zinc-100">
                <div>
                    <p class="text-[10px] tracking-widest text-zinc-400 uppercase mb-1">当前筛选</p>
                    <h2 class="serif-cn text-2xl"><?= h($filter_title) ?></h2>
                    <p class="text-xs text-zinc-400 mt-1"><?= $total ?> 篇文章</p>
                </div>
                <a href="index.php" class="text-[10px] tracking-[0.2em] uppercase border-b border-zinc-200 pb-1 hover:border-zinc-900 transition">
                    清除筛选 ✕
                </a>
            </div>
            <?php endif; ?>

            <!-- 文章 -->
            <div>
                <?php if (empty($posts)): ?>
                <p class="text-zinc-400 text-sm">该筛选条件下暂无文章。</p>
                <?php endif; ?>

                <?php foreach ($posts as $i => $post): ?>
                <?php $has_cover = !empty($post['cover_url']); ?>
                <article class="article-card reveal group">
                    <div class="article-card-inner <?= $has_cover ? 'grid grid-cols-1 md:grid-cols-12 gap-8 items-center' : 'flex gap-8' ?>">
                        <?php if (!$has_cover): ?>
                        <div class="article-no hidden md:block w-8 flex-shrink-0 text-right"><?= str_pad($i + 1, 2, '0', STR_PAD_LEFT) ?></div>
                        <?php endif; ?>
                        <?php if ($has_cover): ?>
                        <div class="md:col-span-5 aspect-[4/3] overflow-hidden article-cover-wrap">
                            <img src="<?= h($post['cover_url']) ?>" alt="<?= h($post['title']) ?>"
                                 class="w-full h-full object-cover article-cover">
                        </div>
                        <div class="md:col-span-7 space-y-4">
                        <?php else: ?>
                        <div class="flex-1 space-y-4">
                        <?php endif; ?>
                            <div class="flex items-center flex-wrap gap-x-3 gap-y-1 text-[10px] tracking-widest text-zinc-400 uppercase">
                                <span><?= h(fmt_date($post['published_at'])) ?></span>
                                <?php if ($post['category_name']): ?>
                                <span class="w-4 h-[1px] bg-zinc-100"></span>
                                <a href="?category=<?= h($post['category_slug']) ?>" class="hover:text-zinc-700 transition">
                                    <?= h($post['category_name']) ?>
                                </a>
                                <?php endif; ?>
                                <?php if ($post['collection_title']): ?>
                                <span class="w-4 h-[1px] bg-zinc-100"></span>
                                <a href="?collection=<?= (int)$post['collection_id'] ?>" class="hover:text-zinc-700 transition italic normal-case">
                                    # <?= h($post['collection_title']) ?>
                                </a>
                                <?php endif; ?>
                            </div>
                            <h3 class="serif-cn text-3xl group-hover:text-zinc-500 transition cursor-pointer">
                                <?= h($post['title']) ?>
                            </h3>
                            <p class="text-zinc-500 text-sm font-light leading-relaxed"><?= h($post['summary']) ?></p>
                            <a href="post.php?id=<?= (int)$post['id'] ?>"
                               onclick="openPost(<?= (int)$post['id'] ?>, event)"
                               class="read-more">
                                阅读全文
                            </a>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>

            <!-- ======= 分页 ======= -->
            <?php if ($total_pages > 1): ?>
            <nav class="mt-32 flex items-center justify-center space-x-2" aria-label="分页">

                <?php if ($page > 1): ?>
                <a href="<?= h(page_url($page - 1)) ?>"
                   class="w-10 h-10 flex items-center justify-center border border-zinc-200 text-zinc-400 hover:border-zinc-900 hover:text-zinc-900 transition text-sm">
                    ←
                </a>
                <?php else: ?>
                <span class="w-10 h-10 flex items-center justify-center border border-zinc-100 text-zinc-200 text-sm cursor-default">←</span>
                <?php endif; ?>

                <?php
                // 显示页码窗口：始终显示首、末页，及当前页前后各1页
                $shown = [];
                for ($i = 1; $i <= $total_pages; $i++) {
                    if ($i === 1 || $i === $total_pages || abs($i - $page) <= 1) {
                        $shown[] = $i;
                    }
                }
                $prev_shown = null;
                foreach ($shown as $p_num):
                    if ($prev_shown !== null && $p_num - $prev_shown > 1): ?>
                        <span class="text-zinc-300 text-xs px-1">···</span>
                    <?php endif;
                    if ($p_num === $page): ?>
                        <span class="w-10 h-10 flex items-center justify-center bg-zinc-900 text-white text-sm"><?= $p_num ?></span>
                    <?php else: ?>
                        <a href="<?= h(page_url($p_num)) ?>"
                           class="w-10 h-10 flex items-center justify-center border border-zinc-200 text-zinc-400 hover:border-zinc-900 hover:text-zinc-900 transition text-sm">
                            <?= $p_num ?>
                        </a>
                    <?php endif;
                    $prev_shown = $p_num;
                endforeach; ?>

                <?php if ($page < $total_pages): ?>
                <a href="<?= h(page_url($page + 1)) ?>"
                   class="w-10 h-10 flex items-center justify-center border border-zinc-200 text-zinc-400 hover:border-zinc-900 hover:text-zinc-900 transition text-sm">
                    →
                </a>
                <?php else: ?>
                <span class="w-10 h-10 flex items-center justify-center border border-zinc-100 text-zinc-200 text-sm cursor-default">→</span>
                <?php endif; ?>

            </nav>
            <p class="text-center text-[10px] text-zinc-300 tracking-widest mt-6 uppercase">
                第 <?= $page ?> 页 / 共 <?= $total_pages ?> 页 · <?= $total ?> 篇文章
            </p>
            <?php endif; ?>

        </div>
    </main>

    <!-- 底部 / 关于我 -->
    <footer id="about" class="<?= ($page > 1 || $is_filtered) ? 'hidden' : '' ?> bg-zinc-950 text-white px-6 md:px-16 py-24">
        <div class="max-w-7xl mx-auto">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-16 mb-20">
                <div>
                    <p class="text-[10px] tracking-[0.4em] text-white/20 uppercase mb-4">About</p>
                    <h2 class="serif-cn text-4xl mb-8 italic"><?= htmlspecialchars($ss('about_quote', '保持理想，步履不停。')) ?></h2>
                    <p class="text-zinc-500 text-sm font-light leading-relaxed max-w-sm">对设计、摄影或科技有独特见解？欢迎通过邮件交流，或在社交媒体上订阅动态。</p>
                </div>
                <div class="flex md:justify-end items-end">
                    <div class="text-right">
                        <?php if ($ss('about_email')): ?>
                        <p class="text-[10px] tracking-widest opacity-30 mb-2 uppercase">Contact</p>
                        <p class="text-sm font-light"><a href="mailto:<?= htmlspecialchars($ss('about_email')) ?>"><?= htmlspecialchars($ss('about_email')) ?></a></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="border-t border-zinc-900 pt-10 flex flex-col md:flex-row justify-between items-center text-[10px] tracking-[0.2em] opacity-20">
                <p>© 2026 <?= htmlspecialchars($ss('site_theme_name', ''), ENT_QUOTES, 'UTF-8') ?>. 粤ICP备12345678号</p>
                <div class="mt-4 md:mt-0 flex space-x-6 uppercase">
                    <a href="#">隐私政策</a>
                    <a href="#">使用协议</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- 文章覆盖层（AJAX 打开，不刷新页面，音乐不中断） -->
    <div id="post-overlay" style="display:none;position:fixed;inset:0;z-index:800;overflow-y:auto;opacity:0;transition:opacity 0.45s cubic-bezier(0.22,1,0.36,1);overscroll-behavior:contain;-webkit-overflow-scrolling:touch;">
        <button onclick="closePostOverlay()" style="position:sticky;top:24px;float:right;margin:24px 24px 0 0;font-size:10px;letter-spacing:.2em;text-transform:uppercase;opacity:.4;cursor:pointer;background:none;border:none;color:inherit;z-index:2;">✕ 关闭</button>
        <div style="max-width:680px;margin:0 auto;padding:80px 24px 80px;clear:both;">
            <div id="post-overlay-content"></div>
        </div>
    </div>

    <?php if ($hp_music_player_enabled): ?>
    <audio id="mp-audio" preload="none"></audio>
    <?php endif; ?>

    <!-- 文件分享覆盖层（不跳转页面，音乐连续播放） -->
    <!-- 文件数据（供 JS 过滤用） -->
    <script>
    var SHARE_FILES_DATA = <?php
        $js_files = [];
        foreach ($share_files as $_sf) {
            $js_files[] = [
                'name'   => $_sf['name'],
                'folder' => $_sf['folder'],
                'ext'    => $_sf['ext'],
                'size'   => $_sf['size'],
                'url'    => $_sf['url'],
            ];
        }
        echo json_encode($js_files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;
    var SHARE_FOLDERS_DATA = <?php
        $js_folders = [];
        foreach ($share_folders as $_f) {
            $js_folders[] = ['name' => $_f['name'], 'count' => $_f['count'], 'size' => $_f['size']];
        }
        echo json_encode($js_folders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;
    </script>

    <div id="files-overlay" style="display:none;position:fixed;inset:0;z-index:800;overflow-y:auto;opacity:0;transition:opacity 0.45s cubic-bezier(0.22,1,0.36,1);overscroll-behavior:contain;-webkit-overflow-scrolling:touch;">
        <button onclick="closeFilesOverlay()" style="position:sticky;top:24px;float:right;margin:24px 24px 0 0;font-size:10px;letter-spacing:.2em;text-transform:uppercase;opacity:.4;cursor:pointer;background:none;border:none;color:inherit;z-index:2;">✕ 关闭</button>
        <div style="max-width:680px;margin:0 auto;padding:80px 24px 80px;clear:both;">
            <!-- 页头 -->
            <div style="margin-bottom:40px;">
                <p style="font-size:10px;letter-spacing:.4em;text-transform:uppercase;opacity:.4;margin-bottom:12px;" id="files-overlay-label">Downloads</p>
                <h1 id="files-overlay-title" style="font-family:'Noto Serif SC',serif;font-size:clamp(28px,6vw,48px);line-height:1.2;margin-bottom:14px;">文件分享</h1>
                <p style="font-size:13px;font-weight:300;opacity:.45;line-height:1.8;" id="files-overlay-desc">公开分享的文件，点击即可下载。</p>
            </div>
            <!-- 动态内容区 -->
            <div id="files-overlay-body"></div>
        </div>
    </div>
    <style>
        @media (max-width: 540px) {
            .files-size-col { display: none !important; }
        }
    </style>



    <script>
        const sidebar    = document.getElementById('sidebar');
        const overlay    = document.getElementById('overlay');
        const openBtn    = document.getElementById('openMenu');
        const closeBtn   = document.getElementById('closeMenu');
        const searchBox  = document.getElementById('searchBox');
        const openSearch = document.getElementById('openSearch');
        const closeSearch= document.getElementById('closeSearch');
        const searchInput= document.getElementById('searchInput');
        const searchResults = document.getElementById('searchResults');
        const searchHints   = document.getElementById('searchHints');

        // 页面样式就绪后恢复可见，并启用主题切换过渡
        document.documentElement.style.visibility = '';
        document.documentElement.classList.add('theme-ready');

        let _lockScrollY = 0;
        function toggleLock(v) {
            if (v) {
                _lockScrollY = window.scrollY;
                // 用 overflow:hidden 替代 position:fixed，避免 iOS Safari 页面跳动/卡死
                document.documentElement.style.overflow = 'hidden';
                document.documentElement.style.overscrollBehavior = 'none';
            } else {
                document.documentElement.style.overflow = '';
                document.documentElement.style.overscrollBehavior = '';
                // 仅在位置偏移时才恢复滚动位置
                if (Math.abs(window.scrollY - _lockScrollY) > 1) {
                    window.scrollTo(0, _lockScrollY);
                }
            }
        }

        openBtn.onclick  = () => { sidebar.classList.remove('translate-x-full'); overlay.classList.add('show'); toggleLock(true); };
        closeBtn.onclick = () => { sidebar.classList.add('translate-x-full'); overlay.classList.remove('show'); toggleLock(false); };
        overlay.onclick  = () => { sidebar.classList.add('translate-x-full'); overlay.classList.remove('show'); toggleLock(false); };

        openSearch.onclick  = () => { searchBox.classList.add('active'); toggleLock(true); searchInput.focus(); };
        closeSearch.onclick = () => { searchBox.classList.remove('active'); toggleLock(false); clearSearch(); };

        // ---- 手风琴侧边导航 ----
        document.querySelectorAll('.nav-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.dataset.target;
                const sub = document.getElementById(targetId);
                const arrow = btn.querySelector('.nav-arrow');
                const isOpen = sub.style.maxHeight && sub.style.maxHeight !== '0px';

                // 关闭所有
                document.querySelectorAll('.nav-sub').forEach(el => {
                    el.style.maxHeight = '0px';
                });
                document.querySelectorAll('.nav-arrow').forEach(el => {
                    el.style.transform = 'rotate(0deg)';
                });

                // 切换当前
                if (!isOpen) {
                    sub.style.maxHeight = sub.scrollHeight + 'px';
                    arrow.style.transform = 'rotate(180deg)';
                }
            });
        });

        // 页面加载时自动展开有激活项的分组
        <?php if ($current_category): ?>
        (function() {
            const el = document.getElementById('nav-categories');
            const arrow = document.querySelector('[data-target="nav-categories"] .nav-arrow');
            if (el) { el.style.maxHeight = el.scrollHeight + 'px'; arrow.style.transform = 'rotate(180deg)'; }
        })();
        <?php elseif ($current_collection): ?>
        (function() {
            const el = document.getElementById('nav-collections');
            const arrow = document.querySelector('[data-target="nav-collections"] .nav-arrow');
            if (el) { el.style.maxHeight = el.scrollHeight + 'px'; arrow.style.transform = 'rotate(180deg)'; }
        })();
        <?php endif; ?>

        let searchTimer = null;
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimer);
            const q = searchInput.value.trim();
            if (!q) { clearSearch(); return; }
            searchTimer = setTimeout(() => doSearch(q), 300);
        });
        searchInput.addEventListener('keydown', e => { if (e.key === 'Enter' && searchInput.value.trim()) doSearch(searchInput.value.trim()); });

        function highlight(text, q) {
            if (!text || !q) return text || '';
            const escaped = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            return text.replace(new RegExp(escaped, 'gi'), m => `<mark class="bg-zinc-200 dark:bg-zinc-700 text-inherit rounded px-0.5">${m}</mark>`);
        }

        async function doSearch(q) {
            searchResults.innerHTML = '<p class="text-zinc-400 text-sm text-center py-8 tracking-widest">搜索中...</p>';
            searchResults.classList.remove('hidden');
            searchHints.classList.add('hidden');
            try {
                const resp = await fetch(`api/posts.php?search=${encodeURIComponent(q)}`);
                const data = await resp.json();
                if (!data.length) {
                    searchResults.innerHTML = `<p class="text-zinc-400 text-sm text-center py-8 tracking-widest">未找到与「${q}」相关的文章</p>`;
                    return;
                }
                searchResults.innerHTML = data.map(p => {
                    const summary = (p.summary || '').substring(0, 80);
                    const tags = [p.category_name, p.collection_title].filter(Boolean);
                    const tagHtml = tags.map(t =>
                        `<span class="inline-block text-[9px] tracking-widest uppercase border border-zinc-200 dark:border-zinc-700 px-2 py-0.5 text-zinc-400">${highlight(t, q)}</span>`
                    ).join('');
                    return `
                    <a href="post.php?id=${p.id}" onclick="openPost(${p.id}, event)" class="flex items-start gap-4 py-5 border-b border-zinc-100 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-900 px-3 transition group cursor-pointer">
                        ${p.cover_url
                            ? `<img src="${p.cover_url}" class="w-20 h-14 object-cover flex-shrink-0 transition-transform duration-300 group-hover:scale-105">`
                            : `<div class="w-20 h-14 bg-zinc-100 dark:bg-zinc-800 flex-shrink-0"></div>`
                        }
                        <div class="min-w-0">
                            <div class="flex flex-wrap gap-1.5 mb-2">${tagHtml}</div>
                            <p class="serif-cn text-base leading-snug mb-1">${highlight(p.title, q)}</p>
                            <p class="text-[11px] text-zinc-400 font-light leading-relaxed line-clamp-2">${highlight(summary, q)}${summary.length >= 80 ? '…' : ''}</p>
                            <p class="text-[10px] text-zinc-300 dark:text-zinc-600 mt-1.5 tracking-widest">${p.published_at ? p.published_at.substring(0,10).replace(/-/g,'.') : ''}</p>
                        </div>
                    </a>`;
                }).join('');
                // 结果头部
                searchResults.insertAdjacentHTML('afterbegin',
                    `<p class="text-[10px] tracking-widest text-zinc-300 dark:text-zinc-600 uppercase px-3 pb-3">找到 ${data.length} 篇相关文章，按匹配度排列</p>`
                );
            } catch(e) {
                searchResults.innerHTML = '<p class="text-zinc-400 text-sm text-center py-8">搜索出错，请稍后重试</p>';
            }
        }

        function clearSearch() {
            searchResults.innerHTML = '';
            searchResults.classList.add('hidden');
            searchHints.classList.remove('hidden');
            searchInput.value = '';
        }

        const observer = new IntersectionObserver(entries => {
            entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('active'); });
        }, { threshold: 0.1 });
        document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

        // ---- 深色 / 浅色模式 ----
        const html       = document.documentElement;
        const themeBtn   = document.getElementById('themeToggle');
        const iconSun    = document.getElementById('iconSun');
        const iconMoon   = document.getElementById('iconMoon');

        function applyTheme(dark) {
            if (dark) {
                html.classList.add('dark');
                iconSun.classList.remove('hidden');
                iconMoon.classList.add('hidden');
            } else {
                html.classList.remove('dark');
                iconSun.classList.add('hidden');
                iconMoon.classList.remove('hidden');
            }
        }

        // 读取本地存储或系统偏好（此时 theme-ready 已加，切换会有过渡）
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        applyTheme(savedTheme === 'dark' || (!savedTheme && prefersDark));

        themeBtn.addEventListener('click', () => {
            const isDark = html.classList.contains('dark');
            applyTheme(!isDark);
            localStorage.setItem('theme', !isDark ? 'dark' : 'light');
        });

        // ---- 文章覆盖层（不跳转页面，音乐连续播放）----
        const postOverlay = document.getElementById('post-overlay');
        const postOverlayContent = document.getElementById('post-overlay-content');

        function applyOverlayTheme() {
            const dark = document.documentElement.classList.contains('dark');
            postOverlay.style.backgroundColor = dark ? '#0f0f0f' : '#fff';
            postOverlay.style.color = dark ? '#e5e5e5' : '#1a1a1a';
        }

        let prePostUrl = location.href; // 记录打开文章前的 URL（含筛选参数）

        async function openPost(id, e) {
            if (e) e.preventDefault();
            applyOverlayTheme();
            toggleLock(true);
            prePostUrl = location.href; // 保存当前 URL
            history.pushState({ postId: id }, '', 'post.php?id=' + id);

            // 内容先清空、整体透明，display:block 但不可见，避免任何闪烁
            postOverlayContent.style.transition = 'none';
            postOverlayContent.style.opacity = '0';
            postOverlayContent.style.transform = 'translateY(20px)';
            postOverlayContent.innerHTML = '';
            postOverlay.style.transition = 'none';
            postOverlay.style.opacity = '0';
            postOverlay.style.display = 'block';
            postOverlay.scrollTop = 0;

            try {
                const resp = await fetch('api/posts.php?id=' + id);
                const post = await resp.json();
                if (post.error) { postOverlayContent.innerHTML = '<p>文章不存在</p>'; return; }

                const date = post.published_at ? post.published_at.substring(0,10).replace(/-/g,'.') : '';
                const tags = [post.category_name, post.collection_title].filter(Boolean)
                    .map(t => `<span style="font-size:9px;letter-spacing:.2em;text-transform:uppercase;opacity:.5">${t}</span>`)
                    .join('<span style="opacity:.2;margin:0 8px">·</span>');

                postOverlayContent.innerHTML = `
                    <div style="font-size:10px;letter-spacing:.2em;text-transform:uppercase;opacity:.4;margin-bottom:28px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <span>${date}</span>
                        ${tags ? '<span style="opacity:.3">—</span>' + tags : ''}
                    </div>
                    <h1 style="font-family:'Noto Serif SC',serif;font-size:clamp(28px,5vw,44px);line-height:1.25;margin-bottom:36px;">${post.title}</h1>
                    ${post.summary ? `<p style="opacity:.5;font-size:14px;font-weight:300;line-height:1.8;border-left:2px solid currentColor;padding-left:20px;margin-bottom:40px;opacity:.45;">${post.summary}</p>` : ''}
                    ${post.cover_url ? `<div style="margin-bottom:40px;aspect-ratio:16/9;overflow:hidden;"><img src="${post.cover_url}" style="width:100%;height:100%;object-fit:cover;transition:transform 0.8s ease;"></div>` : ''}
                    <div style="font-size:15px;font-weight:300;line-height:2;color:inherit;opacity:.85;">${post.content || '<p style="opacity:.4;font-style:italic;">（正文暂未录入）</p>'}</div>
                    <div style="margin-top:60px;padding-top:32px;border-top:1px solid currentColor;opacity:.1;"></div>
                    <button onclick="closePostOverlay()" style="margin-top:24px;font-size:10px;letter-spacing:.2em;text-transform:uppercase;opacity:.35;cursor:pointer;background:none;border:none;color:inherit;padding:0;">✕ 关闭文章</button>
                `;

                // 双 rAF：等浏览器完成 layout 再启动过渡，彻底消灭闪烁
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    const ease = 'cubic-bezier(0.22,1,0.36,1)';
                    postOverlay.style.transition = `opacity 0.42s ${ease}`;
                    postOverlay.style.opacity = '1';
                    postOverlayContent.style.transition = `opacity 0.55s ${ease}, transform 0.55s ${ease}`;
                    postOverlayContent.style.opacity = '1';
                    postOverlayContent.style.transform = 'translateY(0)';
                }));
            } catch(err) {
                postOverlayContent.innerHTML = '<p style="opacity:.4">加载失败，请重试。</p>';
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    postOverlay.style.transition = 'opacity 0.42s ease';
                    postOverlay.style.opacity = '1';
                }));
            }
        }

        function returnHome() {
            history.pushState({}, '', 'index.php');
            // 直接调用 loadPosts，由它统一管理所有区块的显示/隐藏，
            // 避免此处手动操控 DOM 与 loadPosts 内部逻辑产生竞态冲突。
            // loadPosts 传空参数时会走"首页"分支，重建 hero、专题、文章列表。
            loadPosts(new URLSearchParams());
        }

        function closePostOverlay() {
            const ease = 'cubic-bezier(0.22,1,0.36,1)';
            postOverlayContent.style.transition = `opacity 0.28s ${ease}, transform 0.28s ${ease}`;
            postOverlayContent.style.opacity = '0';
            postOverlayContent.style.transform = 'translateY(12px)';
            postOverlay.style.transition = `opacity 0.38s ${ease}`;
            postOverlay.style.opacity = '0';
            // 用 transitionend 替代硬编码 setTimeout，动画结束才清理，不阻塞交互
            postOverlay.addEventListener('transitionend', function _cleanup(ev) {
                if (ev.propertyName !== 'opacity') return;
                postOverlay.removeEventListener('transitionend', _cleanup);
                postOverlay.style.display = 'none';
                postOverlayContent.innerHTML = '';
            });
            toggleLock(false);
            history.pushState({}, '', prePostUrl); // 恢复打开文章前的 URL
        }

        // ESC 关闭
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && postOverlay.style.display !== 'none') closePostOverlay(); });

        // 浏览器后退/前进 —— 统一处理覆盖层和筛选状态
        window.addEventListener('popstate', e => {
            // 1. 文章覆盖层打开时，后退 = 关闭覆盖层
            if (postOverlay.style.display !== 'none') {
                closePostOverlay();
                return;
            }
            // 2. 筛选/分页状态变化时，重新加载文章列表
            // 只在首页路径触发，避免误触
            const url = new URL(location.href);
            const isHomePath = url.pathname.endsWith('index.php') || url.pathname.endsWith('/');
            if (!isHomePath) return;
            loadPosts(url.searchParams);
        });

        // 点击覆盖层边缘关闭
        postOverlay.addEventListener('click', e => { if (e.target === postOverlay) closePostOverlay(); });

        // ---- 筛选 & 分页：拦截所有 ?category / ?collection / ?page 链接，AJAX 刷新文章列表 ----
        const mainEl = document.querySelector('main');

        async function loadPosts(params) {
            const apiParams = new URLSearchParams();
            const perPage = 5;
            const page = parseInt(params.get('page') || '1');
            apiParams.set('limit', perPage);
            apiParams.set('offset', (page - 1) * perPage);
            if (params.get('category'))   apiParams.set('category',   params.get('category'));
            if (params.get('collection')) apiParams.set('collection', params.get('collection'));

            const isHome = !params.get('category') && !params.get('collection') && page === 1;
            const siteHeader = document.getElementById('site-header');
            const aboutFooter = document.getElementById('about');

            if (isHome) {
                // 返回首页第一页：恢复 hero 和 footer
                // 1. 先移除 display:none（含 PHP 初始渲染时加的 Tailwind hidden 类）
                // 2. 强制 opacity:0（transition:none），再用 rAF 触发淡入，否则不产生过渡
                const restoreEls = [siteHeader, aboutFooter].filter(Boolean);
                const existingCs = document.getElementById('collections-section');
                if (existingCs) restoreEls.push(existingCs);

                restoreEls.forEach(el => {
                    el.classList.remove('hidden');   // 移除 PHP 渲染时可能带的 Tailwind hidden
                    el.style.display = '';           // 清除 JS 设的 display:none
                    el.style.transition = 'none';
                    el.style.opacity = '0';
                });
                requestAnimationFrame(() => {
                    restoreEls.forEach(el => {
                        el.style.transition = 'opacity 0.4s ease';
                        el.style.opacity = '1';
                    });
                });
            } else {
                // 翻页或筛选状态：隐藏 hero/专题/footer
                // 必须用 display:none 脱离文档流，否则 min-h-screen 的 hero 会撑出一整屏空白
                const collectionsSection = document.getElementById('collections-section');
                [siteHeader, collectionsSection, aboutFooter].forEach(el => {
                    if (!el) return;
                    el.style.display = 'none';
                });
            }

            window.scrollTo({ top: 0 });
            mainEl.style.transition = 'opacity 0.25s ease';
            mainEl.style.opacity = '0.15';

            try {
                const resp = await fetch('api/posts.php?' + apiParams);
                const data = await resp.json();
                const posts = data.posts || [];
                const total = data.total || 0;
                const totalPages = Math.ceil(total / perPage);

                let filterTitle = '';
                if (params.get('category'))   filterTitle = '分类：' + params.get('category');
                if (params.get('collection')) filterTitle = '专题';

                const cardsHtml = posts.length === 0
                    ? '<p class="text-zinc-400 text-sm">该筛选条件下暂无文章。</p>'
                    : posts.map((post, i) => {
                        const hasCover = !!post.cover_url;
                        const date = (post.published_at || '').substring(0,10).replace(/-/g,'.');
                        const catLink = post.category_slug ? `<a href="?category=${post.category_slug}" class="hover:text-zinc-700 transition">${post.category_name}</a>` : '';
                        const colLink = post.collection_id ? `<a href="?collection=${post.collection_id}" class="hover:text-zinc-700 transition italic normal-case"># ${post.collection_title}</a>` : '';
                        const sep = '<span class="w-4 h-[1px] bg-zinc-100 inline-block align-middle mx-1"></span>';
                        const meta = [date, catLink, colLink].filter(Boolean).join(sep);
                        const num = String(i + 1).padStart(2, '0');
                        const noCol = hasCover ? '' : `<div class="article-no hidden md:block w-8 flex-shrink-0 text-right">${num}</div>`;
                        return `
                        <article class="article-card reveal group">
                            <div class="article-card-inner ${hasCover ? 'grid grid-cols-1 md:grid-cols-12 gap-8 items-center' : 'flex gap-8'}">
                                ${noCol}
                                ${hasCover ? `<div class="md:col-span-5 aspect-[4/3] overflow-hidden article-cover-wrap"><img src="${post.cover_url}" alt="" class="w-full h-full object-cover article-cover"></div>` : ''}
                                <div class="${hasCover ? 'md:col-span-7 ' : 'flex-1 '}space-y-4">
                                    <div class="flex items-center flex-wrap gap-x-3 gap-y-1 text-[10px] tracking-widest text-zinc-400 uppercase">${meta}</div>
                                    <h3 class="serif-cn text-3xl group-hover:text-zinc-500 transition cursor-pointer">${post.title}</h3>
                                    <p class="text-zinc-500 text-sm font-light leading-relaxed">${post.summary || ''}</p>
                                    <a href="post.php?id=${post.id}" onclick="openPost(${post.id}, event)" class="read-more">阅读全文</a>
                                </div>
                            </div>
                        </article>`;
                    }).join('');

                const filterQS = params.get('category') ? `&category=${params.get('category')}` : params.get('collection') ? `&collection=${params.get('collection')}` : '';
                let pagerHtml = '';
                if (totalPages > 1) {
                    const shown = [];
                    for (let i = 1; i <= totalPages; i++) {
                        if (i === 1 || i === totalPages || Math.abs(i - page) <= 1) shown.push(i);
                    }
                    let prev = null;
                    const pageLinks = shown.map(p => {
                        const gap = (prev !== null && p - prev > 1) ? '<span class="text-zinc-300 text-xs px-1">···</span>' : '';
                        prev = p;
                        if (p === page) return gap + `<span class="w-10 h-10 flex items-center justify-center bg-zinc-900 text-white text-sm">${p}</span>`;
                        return gap + `<a href="?page=${p}${filterQS}" class="w-10 h-10 flex items-center justify-center border border-zinc-200 text-zinc-400 hover:border-zinc-900 hover:text-zinc-900 transition text-sm">${p}</a>`;
                    }).join('');
                    const prevBtn = page > 1 ? `<a href="?page=${page-1}${filterQS}" class="w-10 h-10 flex items-center justify-center border border-zinc-200 text-zinc-400 hover:border-zinc-900 hover:text-zinc-900 transition text-sm">←</a>` : `<span class="w-10 h-10 flex items-center justify-center border border-zinc-100 text-zinc-200 text-sm cursor-default">←</span>`;
                    const nextBtn = page < totalPages ? `<a href="?page=${page+1}${filterQS}" class="w-10 h-10 flex items-center justify-center border border-zinc-200 text-zinc-400 hover:border-zinc-900 hover:text-zinc-900 transition text-sm">→</a>` : `<span class="w-10 h-10 flex items-center justify-center border border-zinc-100 text-zinc-200 text-sm cursor-default">→</span>`;
                    pagerHtml = `<nav class="mt-32 flex items-center justify-center space-x-2">${prevBtn}${pageLinks}${nextBtn}</nav>`;
                }

                const isFiltered = params.get('category') || params.get('collection');
                const filterBar = isFiltered ? `
                    <div class="flex items-center justify-between mb-16 pb-6 border-b border-zinc-100">
                        <div>
                            <p class="text-[10px] tracking-widest text-zinc-400 uppercase mb-1">当前筛选</p>
                            <h2 class="serif-cn text-2xl">${filterTitle}</h2>
                            <p class="text-xs text-zinc-400 mt-1">${total} 篇文章</p>
                        </div>
                        <button onclick="returnHome()" class="text-[10px] tracking-[0.2em] uppercase border-b border-zinc-200 pb-1 hover:border-zinc-900 transition">清除筛选 ✕</button>
                    </div>` : isHome ? `
                    <div class="mb-16 pb-6 border-b border-zinc-100">
                        <p class="text-[10px] tracking-widest text-zinc-400 uppercase mb-1">文章归档</p>
                        <p class="text-xs text-zinc-400 mt-1">${total} 篇文章</p>
                    </div>` : `
                    <div class="flex items-center justify-between mb-16 pb-6 border-b border-zinc-100">
                        <div>
                            <p class="text-[10px] tracking-widest text-zinc-400 uppercase mb-1">文章归档</p>
                            <p class="text-xs text-zinc-400 mt-1">${total} 篇文章</p>
                        </div>
                        <button onclick="returnHome()" class="text-[10px] tracking-[0.2em] uppercase border-b border-zinc-200 pb-1 hover:border-zinc-900 dark:border-zinc-700 transition">← 返回首页</button>
                    </div>`;

                mainEl.innerHTML = `<div class="max-w-5xl mx-auto">${filterBar}<div>${cardsHtml}</div>${pagerHtml}</div>`;

                // 首页状态：如果 collections-section 不在 DOM 里（从筛选页返回时），动态重建它
                if (isHome && !document.getElementById('collections-section')) {
                    try {
                        const colResp = await fetch('api/collections.php');
                        const cols = await colResp.json();
                        const top3 = cols.slice(0, 3);
                        if (top3.length > 0) {
                            const colItemsHtml = top3.map((col, i) => `
                                <a href="?collection=${col.id}" class="reveal group cursor-pointer block" ${i > 0 ? `style="transition-delay:${i * 0.1}s;"` : ''}>
                                    <div class="aspect-[16/10] overflow-hidden bg-zinc-200 mb-6">
                                        ${col.cover_url ? `<img src="${col.cover_url}" alt="${col.title}" class="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-105" loading="lazy">` : ''}
                                    </div>
                                    <h3 class="serif-cn text-xl mb-3 group-hover:text-zinc-500 transition"># ${col.title}</h3>
                                    <p class="text-xs text-zinc-400 font-light leading-relaxed">${col.description || ''}</p>
                                </a>`).join('');
                            const csEl = document.createElement('section');
                            csEl.id = 'collections-section';
                            csEl.className = 'px-6 md:px-16 py-24 bg-[#fafafa]';
                            csEl.innerHTML = `<div class="max-w-7xl mx-auto">
                                <div class="flex items-baseline justify-between mb-16 border-b border-zinc-200 pb-4">
                                    <h2 class="serif-cn text-2xl">精选专题</h2>
                                    <span class="text-[10px] text-zinc-400 tracking-widest uppercase">Collections</span>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-12">${colItemsHtml}</div>
                            </div>`;
                            csEl.style.opacity = '0';
                            csEl.style.transition = 'opacity 0.4s ease';
                            mainEl.insertAdjacentElement('beforebegin', csEl);
                            requestAnimationFrame(() => { csEl.style.opacity = '1'; });
                        }
                    } catch(e) { /* 专题加载失败不影响主流程 */ }
                }

                // 内容淡入
                requestAnimationFrame(() => {
                    mainEl.style.transition = 'opacity 0.4s ease';
                    mainEl.style.opacity = '1';
                });

                // 文章卡片错落淡入：用 CSS animation-delay 替代多个 setTimeout，避免定时器堆积导致卡顿
                document.querySelectorAll('main .reveal').forEach((el, i) => {
                    el.style.animationDelay = (i * 0.08) + 's';
                    el.classList.add('active');
                });

            } catch(e) {
                mainEl.style.opacity = '1';
            }
        }

        // 事件委托：拦截所有筛选 / 分页链接（排除文章、admin 等跨页跳转）
        document.addEventListener('click', e => {
            if (postOverlay.style.display !== 'none') return;
            const a = e.target.closest('a[href]');
            if (!a) return;
            const href = a.getAttribute('href');
            if (!href || href.startsWith('http') || href.startsWith('mailto') || href.startsWith('#')) return;
            const url = new URL(href, location.href);
            // 只拦截指向首页（index.php 或同路径）的链接
            const isHomePath = url.pathname === location.pathname
                || url.pathname.endsWith('/index.php')
                || url.pathname.endsWith('/');
            if (!isHomePath) return;
            // 拦截所有指向首页的链接（含筛选、分页、清除筛选、全部文章）
            e.preventDefault();
            // 关闭侧边栏（如果开着）
            sidebar.classList.add('translate-x-full');
            overlay.classList.remove('show');
            toggleLock(false);
            history.pushState({}, '', href);
            loadPosts(url.searchParams);
        });

        const scrollHint = document.getElementById('scrollHint');
        if (scrollHint) {
            // 动态调整位置：确保提示在可见区域内，极端情况下自动隐藏
            function positionScrollHint() {
                const vh = window.innerHeight;
                const hintH = scrollHint.offsetHeight || 48;
                const header = scrollHint.closest('header');
                const headerH = header ? header.offsetHeight : vh;

                // 可视区域过小（横屏手机等）：直接隐藏，避免遮挡内容
                if (vh < 300 || headerH < vh * 0.6) {
                    scrollHint.style.opacity = '0';
                    scrollHint.style.pointerEvents = 'none';
                    return;
                }

                // 边界保护：确保提示不超出 header 顶部（极端窄屏）
                const currentBottom = parseFloat(getComputedStyle(scrollHint).bottom) || 40;
                const maxBottom = headerH - hintH - 16;
                if (currentBottom > maxBottom) {
                    scrollHint.style.bottom = Math.max(12, maxBottom) + 'px';
                }
            }

            positionScrollHint();
            window.addEventListener('resize', positionScrollHint, { passive: true });

            let hintHidden = false;
            window.addEventListener('scroll', () => {
                if (!hintHidden && window.scrollY > 60) {
                    hintHidden = true;
                    scrollHint.style.opacity = '0';
                    scrollHint.style.pointerEvents = 'none';
                }
            }, { passive: true });
        }

        // ---- 音乐播放器 ----
        <?php if ($hp_music_player_enabled): ?>
        (function() {
            const player     = document.getElementById('music-player');
            const audio      = document.getElementById('mp-audio');
            const playBtn    = document.getElementById('mp-play-btn');
            const prevBtn    = document.getElementById('mp-prev-btn');
            const nextBtn    = document.getElementById('mp-next-btn');
            const trackName  = document.getElementById('mp-track-name');
            const progressWrap = document.getElementById('mp-progress-wrap');
            const progressBar  = document.getElementById('mp-progress-bar');
            const iconPlay   = document.getElementById('mp-icon-play');
            const iconPause  = document.getElementById('mp-icon-pause');
            const barsLeft   = document.getElementById('mp-bars-left');
            const barsRight  = document.getElementById('mp-bars-right');

            if (!player) return;

            let playlist = [];
            let current  = 0;
            let isPlaying = false;

            const rawPlaylist = <?php
                $musicDir = __DIR__ . '/uploads/music/';
                $mp3s = [];
                if (is_dir($musicDir)) {
                    foreach (['*.mp3','*.flac','*.m4a','*.ogg','*.wav'] as $ext) {
                        foreach (glob($musicDir . $ext) as $f) {
                            $mp3s[] = 'uploads/music/' . basename($f);
                        }
                    }
                }
                echo json_encode($mp3s);
            ?>;

            if (!rawPlaylist || rawPlaylist.length === 0) {
                player.style.display = 'none';
                return;
            }

            playlist = rawPlaylist;
            current = Math.floor(Math.random() * playlist.length);

            function getTrackTitle(path) {
                const name = path.split('/').pop().replace(/\.[^.]+$/, '');
                return name.replace(/^\d{8}_\d+_/, '').replace(/_/g, ' ') || name;
            }

            function loadTrack(idx, autoPlay) {
                audio.src = playlist[idx];
                trackName.textContent = getTrackTitle(playlist[idx]);
                progressBar.style.width = '0%';
                if (autoPlay) {
                    audio.play().then(() => setPlaying(true)).catch(() => setPlaying(false));
                }
            }

            function setPlaying(v) {
                isPlaying = v;
                iconPlay.style.display  = v ? 'none' : '';
                iconPause.style.display = v ? '' : 'none';
                barsLeft.classList.toggle('active', v);
                barsRight.classList.toggle('active', v);
            }

            // ---- 状态持久化 ----
            const STORAGE_KEY = 'mp_state';

            function saveState() {
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify({
                        playlist,
                        src: audio.src,
                        idx: current,
                        time: audio.currentTime,
                        playing: isPlaying
                    }));
                } catch(e) {}
            }

            function restoreState() {
                try {
                    const s = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
                    if (!s) return false;
                    const name = s.src ? s.src.split('/').pop() : '';
                    const idx = playlist.findIndex(p => p.split('/').pop() === name);
                    if (idx === -1) return false;
                    current = idx;
                    audio.src = playlist[current];
                    trackName.textContent = getTrackTitle(playlist[current]);
                    progressBar.style.width = '0%';
                    audio.addEventListener('loadedmetadata', () => {
                        if (s.time) audio.currentTime = s.time;
                        if (s.playing) {
                            audio.play().then(() => setPlaying(true)).catch(() => setPlaying(false));
                        } else {
                            setPlaying(false);
                        }
                    }, { once: true });
                    return true;
                } catch(e) { return false; }
            }

            // 离开页面前保存状态
            window.addEventListener('pagehide', saveState);
            window.addEventListener('beforeunload', saveState);

            // 播放时定期保存进度
            audio.addEventListener('timeupdate', () => {
                if (!audio.duration) return;
                progressBar.style.width = (audio.currentTime / audio.duration * 100) + '%';
                if (Math.floor(audio.currentTime) % 5 === 0) saveState();
            });

            if (!restoreState()) {
                loadTrack(current, false);
                setPlaying(false);
            }
            setTimeout(() => player.classList.add('mp-visible'), 600);

            playBtn.addEventListener('click', () => {
                if (isPlaying) {
                    audio.pause();
                    setPlaying(false);
                } else {
                    audio.play().then(() => setPlaying(true)).catch(() => {});
                }
            });

            prevBtn.addEventListener('click', () => {
                current = (current - 1 + playlist.length) % playlist.length;
                loadTrack(current, isPlaying);
            });

            nextBtn.addEventListener('click', () => {
                current = (current + 1) % playlist.length;
                loadTrack(current, isPlaying);
            });

            audio.addEventListener('ended', () => {
                current = (current + 1) % playlist.length;
                loadTrack(current, true);
            });

            progressWrap.addEventListener('click', (e) => {
                const rect = progressWrap.getBoundingClientRect();
                const ratio = (e.clientX - rect.left) / rect.width;
                if (audio.duration) audio.currentTime = ratio * audio.duration;
            });
        })();
        <?php endif; ?>
    </script>

    <!-- 文件分享覆盖层脚本（独立块，避免与音乐播放器条件块产生作用域冲突） -->
    <script>
    (function() {
        var filesOverlay = document.getElementById('files-overlay');
        if (!filesOverlay) return;

        function applyFilesTheme() {
            var dark = document.documentElement.classList.contains('dark');
            filesOverlay.style.backgroundColor = dark ? '#0f0f0f' : '#fff';
            filesOverlay.style.color = dark ? '#e5e5e5' : '#1a1a1a';
        }

        // 文件类型分类
        var FILE_CATS = {
            img:  ['jpg','jpeg','png','gif','webp','svg','bmp','ico','tif','tiff','avif','heic'],
            vid:  ['mp4','webm','mov','avi','mkv','flv','m4v','wmv','ogv'],
            aud:  ['mp3','wav','ogg','flac','aac','m4a','opus','wma'],
            pdf:  ['pdf'],
            doc:  ['doc','docx','odt','rtf','txt','md'],
            xls:  ['xls','xlsx','ods','csv'],
            ppt:  ['ppt','pptx','odp','key'],
            zip:  ['zip','rar','7z','tar','gz','bz2','xz','tgz','dmg','iso'],
            code: ['js','ts','jsx','tsx','py','php','java','c','cpp','cs','go','rs','rb','sh','bash','zsh','html','htm','css','scss','less','json','xml','yaml','yml','toml','sql','vue','dart','kt','swift','r','lua'],
            font: ['ttf','otf','woff','woff2','eot'],
        };
        function fileCategory(ext) {
            for (var cat in FILE_CATS) { if (FILE_CATS[cat].includes(ext)) return cat; }
            return 'other';
        }

        // SVG 图标（16×16 行内用）
        var FILE_SVGS = {
            img:  '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>',
            vid:  '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.069A1 1 0 0121 8.87v6.26a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>',
            aud:  '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/></svg>',
            pdf:  '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
            doc:  '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
            xls:  '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M3 14h18M10 3v18M3 3h18v18H3z"/></svg>',
            ppt:  '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18v12a1 1 0 01-1 1H4a1 1 0 01-1-1V4z"/></svg>',
            zip:  '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>',
            code: '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>',
            font: '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-3-3v6m-7 3h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>',
            other:'<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>',
        };
        // 类型标签颜色（行内覆盖层用）
        var CAT_COLORS = {
            img: '#a78bfa', vid: '#f87171', aud: '#34d399', pdf: '#f97316',
            doc: '#60a5fa', xls: '#4ade80', ppt: '#fb923c', zip: '#a3a3a3',
            code:'#facc15', font:'#e879f9', other:'#71717a',
        };
        function fileIcon(ext) { return FILE_SVGS[fileCategory(ext)] || FILE_SVGS.other; }

        function fmtSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes/1024).toFixed(1) + ' KB';
            if (bytes < 1073741824) return (bytes/1048576).toFixed(1) + ' MB';
            return (bytes/1073741824).toFixed(2) + ' GB';
        }

        function renderFilesOverlay(folderName) {
            var body = document.getElementById('files-overlay-body');
            var title = document.getElementById('files-overlay-title');
            var desc = document.getElementById('files-overlay-desc');
            var label = document.getElementById('files-overlay-label');
            if (!body) return;

            var files = (typeof SHARE_FILES_DATA !== 'undefined') ? SHARE_FILES_DATA : [];
            var filtered = folderName ? files.filter(function(f){ return f.folder === folderName; }) : files;

            if (folderName) {
                label.textContent = 'Downloads / ' + folderName;
                title.textContent = folderName;
                desc.textContent = '点击文件即可下载。';
            } else {
                label.textContent = 'Downloads';
                title.textContent = '文件分享';
                desc.textContent = '公开分享的文件，点击即可下载。';
            }

            var html = '';
            if (filtered.length > 0) {
                var totalSize = filtered.reduce(function(s, f){ return s + f.size; }, 0);
                html += '<div style="display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;border-bottom:1px solid currentColor;opacity:.12;margin-bottom:0;"></div>';
                html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-bottom:1px solid currentColor;">';
                html += '<span style="font-size:10px;letter-spacing:.15em;text-transform:uppercase;opacity:.35;">共 ' + filtered.length + ' 个文件</span>';
                html += '<span style="font-size:10px;letter-spacing:.12em;text-transform:uppercase;opacity:.35;">' + fmtSize(totalSize) + '</span>';
                html += '</div>';
                filtered.forEach(function(sf) {
                    html += '<a href="' + sf.url + '" download="' + sf.name + '" title="' + sf.name + '"'
                        + ' style="display:grid;grid-template-columns:2.5rem 1fr auto auto;align-items:center;gap:0 14px;padding:12px 0;border-bottom:1px solid currentColor;opacity:1;text-decoration:none;color:inherit;transition:opacity 0.15s;"'
                        + ' onmouseover="this.style.opacity=\'.6\'" onmouseout="this.style.opacity=\'1\'">';
                    var cat = fileCategory(sf.ext);
                    var isImg = (cat === 'img');
                    // 图标列：图片显示缩略图+小SVG角标，其他显示大SVG
                    if (isImg) {
                        html += '<span style="position:relative;width:2.5rem;height:2.5rem;flex-shrink:0;display:block;">'
                            + '<img src="' + sf.url + '" alt="" style="width:2.5rem;height:2.5rem;object-fit:cover;border-radius:3px;display:block;" onerror="this.style.display=\'none\'">'
                            + '<span style="position:absolute;bottom:1px;right:1px;background:rgba(0,0,0,.55);border-radius:2px;padding:1px 2px;display:flex;align-items:center;">'
                            + FILE_SVGS.img.replace('width="16" height="16"','width="9" height="9"').replace('stroke="currentColor"','stroke="#fff"')
                            + '</span></span>';
                    } else {
                        var col = CAT_COLORS[cat] || CAT_COLORS.other;
                        html += '<span style="display:flex;align-items:center;justify-content:center;width:2.5rem;height:2.5rem;flex-shrink:0;border-radius:3px;background:rgba(255,255,255,.04);">'
                            + FILE_SVGS[cat].replace('width="16" height="16"','width="18" height="18"').replace('stroke="currentColor"','stroke="' + col + '"')
                            + '</span>';
                    }
                    html += '<span style="font-size:14px;font-weight:300;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;">' + sf.name + '</span>';
                    html += '<span class="files-size-col" style="font-size:10px;letter-spacing:.08em;opacity:.3;white-space:nowrap;text-align:right;min-width:52px;">' + fmtSize(sf.size) + '</span>';
                    html += '<span style="display:inline-flex;align-items:center;gap:5px;font-size:10px;letter-spacing:.18em;text-transform:uppercase;opacity:.4;white-space:nowrap;">';
                    html += '<svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>';
                    if (sf.ext) html += '<span style="font-size:9px;border:1px solid currentColor;padding:1px 4px;opacity:.6;">' + sf.ext + '</span>';
                    html += '</span></a>';
                });
                html += '<p style="margin-top:48px;font-size:10px;letter-spacing:.18em;text-transform:uppercase;opacity:.2;text-align:center;">仅提供公开文件的访问与下载</p>';
            } else {
                html += '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:80px 0;opacity:.25;">';
                html += '<svg width="40" height="40" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-bottom:16px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>';
                html += '<p style="font-size:11px;letter-spacing:.2em;text-transform:uppercase;">暂无文件</p></div>';
            }
            body.innerHTML = html;
        }

        window.openFilesOverlay = function(folderName) {
            renderFilesOverlay(folderName || '');
            applyFilesTheme();
            if (typeof toggleLock === 'function') toggleLock(true);
            var hash = folderName ? '#files-' + encodeURIComponent(folderName) : '#files';
            history.pushState({ filesOpen: true }, '', hash);
            filesOverlay.style.transition = 'none';
            filesOverlay.style.opacity = '0';
            filesOverlay.style.display = 'block';
            filesOverlay.scrollTop = 0;
            requestAnimationFrame(function() { requestAnimationFrame(function() {
                filesOverlay.style.transition = 'opacity 0.42s cubic-bezier(0.22,1,0.36,1)';
                filesOverlay.style.opacity = '1';
            }); });
        };

        window.closeFilesOverlay = function() {
            filesOverlay.style.transition = 'opacity 0.38s cubic-bezier(0.22,1,0.36,1)';
            filesOverlay.style.opacity = '0';
            setTimeout(function() { filesOverlay.style.display = 'none'; }, 400);
            if (typeof toggleLock === 'function') toggleLock(false);
            history.pushState({}, '', location.pathname + location.search);
        };

        // ESC 关闭
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && filesOverlay.style.display !== 'none') window.closeFilesOverlay();
        });

        // 不绑定点击空白关闭，避免误触

        // 浏览器后退
        window.addEventListener('popstate', function() {
            if (filesOverlay.style.display !== 'none') { window.closeFilesOverlay(); }
        });

        // 页面加载时 hash 为 #files 或 #files-{folder} 则自动打开
        (function() {
            var h = location.hash;
            if (h === '#files') {
                window.openFilesOverlay('');
            } else if (h.indexOf('#files-') === 0) {
                window.openFilesOverlay(decodeURIComponent(h.slice(7)));
            }
        })();
    })();
    </script>
</body>
</html>
