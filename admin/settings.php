<?php
// =========================================
// admin/settings.php — 站点设置
// =========================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
auth_check();

$pdo = db();

// 确保表存在（首次使用自动建表）
$pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
    `key`      VARCHAR(100) NOT NULL PRIMARY KEY,
    `value`    TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 确保友情链接表存在
$pdo->exec("CREATE TABLE IF NOT EXISTS friend_links (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    url        VARCHAR(500) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 确保友情链接申请表存在
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
try { $pdo->exec("ALTER TABLE friend_link_applications ADD COLUMN admin_message TEXT"); } catch (Exception $e) {}
// 老库升级：friend_links 补 description 字段
try { $pdo->exec("ALTER TABLE friend_links ADD COLUMN description VARCHAR(300) DEFAULT NULL"); } catch (Exception $e) {}

// 处理友情链接的增删
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action_raw = (string)$_POST['action'];
    $action = $action_raw;
    $action_id = 0;
    if (strpos($action_raw, ':') !== false) {
        [$action, $id_part] = explode(':', $action_raw, 2);
        $action = trim($action);
        $action_id = (int)$id_part;
    }

    if ($action === 'add_friend_link') {
        $name = trim($_POST['link_name'] ?? '');
        $url  = trim($_POST['link_url']  ?? '');
        if (!$name || !$url) {
            header('Location: settings.php?section=friend_links&msg=' . urlencode('请填写链接名称和链接地址')); exit;
        }
        $pdo->prepare("INSERT INTO friend_links (name, url, sort_order) VALUES (?, ?, (SELECT COALESCE(MAX(sort_order),0)+1 FROM friend_links fl2))")
            ->execute([$name, $url]);
        header('Location: settings.php?section=friend_links&msg=' . urlencode('链接已添加')); exit;
    }
    if ($action === 'delete_friend_link') {
        $id = (int)$action_id;
        if ($id) $pdo->prepare("DELETE FROM friend_links WHERE id=?")->execute([$id]);
        header('Location: settings.php?section=friend_links&msg=' . urlencode('链接已删除')); exit;
    }
    // 审核友链申请：通过（同时自动加入友情链接）
    if ($action === 'approve_application') {
        $id = (int)$action_id;
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM friend_link_applications WHERE id=?");
            $stmt->execute([$id]);
            $app = $stmt->fetch();
            if ($app) {
                $pdo->prepare("UPDATE friend_link_applications SET status='approved' WHERE id=?")->execute([$id]);
                $pdo->prepare("INSERT INTO friend_links (name, url, description, sort_order) VALUES (?, ?, ?, (SELECT COALESCE(MAX(sort_order),0)+1 FROM friend_links fl2))")
                    ->execute([$app['site_name'], $app['site_url'], $app['description'] ?? '']);
                // 发送通过通知邮件给申请人
                require_once __DIR__ . '/../email/mailer.php';
                send_application_approved_mail($app['email'], $app['site_name'], $app['site_url']);
            }
        }
        header('Location: settings.php?section=friend_links&msg=' . urlencode('已通过并添加至友情链接')); exit;
    }
    // 拒绝申请
    if ($action === 'reject_application') {
        $id = (int)$action_id;
        $admin_message = '';
        if ($id) {
            $raw = $_POST['admin_message'][$id] ?? '';
            $admin_message = trim((string)$raw);
        }
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM friend_link_applications WHERE id=?");
            $stmt->execute([$id]);
            $app = $stmt->fetch();
            if ($app) {
                $pdo->prepare("UPDATE friend_link_applications SET status='rejected', admin_message=? WHERE id=?")
                    ->execute([$admin_message, $id]);
                // 发送拒绝通知邮件给申请人
                require_once __DIR__ . '/../email/mailer.php';
                send_application_rejected_mail($app['email'], $app['site_name'], $admin_message);
            } else {
                $pdo->prepare("UPDATE friend_link_applications SET status='rejected', admin_message=? WHERE id=?")
                    ->execute([$admin_message, $id]);
            }
        }
        header('Location: settings.php?section=friend_links&msg=' . urlencode('申请已拒绝')); exit;
    }
    // 删除申请记录
    if ($action === 'delete_application') {
        $id = (int)$action_id;
        if ($id) $pdo->prepare("DELETE FROM friend_link_applications WHERE id=?")->execute([$id]);
        header('Location: settings.php?section=friend_links&msg=' . urlencode('申请记录已删除')); exit;
    }
}

$friend_links = $pdo->query("SELECT id, name, url, description, sort_order FROM friend_links ORDER BY sort_order ASC, id ASC")->fetchAll();
$applications = $pdo->query("SELECT * FROM friend_link_applications ORDER BY created_at DESC")->fetchAll();

// 读取所有设置
function get_settings(PDO $pdo): array {
    $rows = $pdo->query("SELECT `key`,`value` FROM site_settings")->fetchAll();
    $map  = [];
    foreach ($rows as $r) $map[$r['key']] = $r['value'];
    return $map;
}

$msg = $err = '';

// ---- 保存 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'site_theme_name', 'site_favicon_url',
        'homepage_avatar', 'homepage_name', 'homepage_bio',
        'homepage_bg_url', 'homepage_bg_opacity', 'homepage_bg_blur', 'homepage_bg_position',
        'homepage_bg_mobile_enabled', 'homepage_bg_grid_enabled',
        'homepage_avatar_no_frame',
        'wechat_qr_url',
        'social_github', 'social_youtube', 'social_bilibili',
        'social_twitter', 'social_instagram',
        'social_linkedin', 'social_telegram', 'social_facebook',
        'social_threads', 'social_tiktok',
        'social_weibo', 'social_xiaohongshu', 'social_zhihu',
        'social_douyin', 'social_douban',
        'about_quote', 'about_email',
        'homepage_music_player_enabled',
        // SMTP 邮件配置
        'smtp_enabled', 'smtp_provider',
        'smtp_host', 'smtp_port', 'smtp_secure',
        'smtp_username', 'smtp_password',
        'smtp_from_email', 'smtp_from_name',
        'admin_notify_email',
    ];
    $stmt = $pdo->prepare("INSERT INTO site_settings (`key`,`value`) VALUES (?,?)
                           ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    $checkbox_fields = ['homepage_bg_mobile_enabled', 'homepage_bg_grid_enabled', 'homepage_avatar_no_frame', 'homepage_music_player_enabled', 'smtp_enabled'];
    foreach ($fields as $k) {
        if (in_array($k, $checkbox_fields)) {
            // hidden+checkbox 组合：hidden 始终提交，需判断值是否为 '1'
            $stmt->execute([$k, ($_POST[$k] ?? '0') === '1' ? '1' : '0']);
        } else {
            // smtp_password：为了安全，留空则不覆盖旧值
            if ($k === 'smtp_password') {
                $pwd = (string)($_POST[$k] ?? '');
                if (trim($pwd) === '') continue;
                $stmt->execute([$k, $pwd]);
                continue;
            }
            $stmt->execute([$k, trim($_POST[$k] ?? '')]);
        }
    }
    $msg = '设置已保存';
    header('Location: settings.php?msg=' . urlencode($msg)); exit;
}

$s = get_settings($pdo);
$g = function(string $k) use ($s) { return htmlspecialchars($s[$k] ?? '', ENT_QUOTES, 'UTF-8'); };

$page_title = '站点设置';
require '_layout.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert-success flex items-center gap-2">
  <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
  </svg>
  <?= htmlspecialchars($_GET['msg']) ?>
</div>
<?php endif; ?>

<form method="POST" action="settings.php">
<div class="space-y-6 max-w-2xl">

  <!-- 站点身份：主题名称 + 网站图标 -->
  <div class="bg-white border border-zinc-200 p-6">
    <p class="text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-1">站点身份</p>
    <p class="text-xs text-zinc-400 mb-5">主题名称将显示在导航栏、页脚、浏览器标签页等处；网站图标用于浏览器标签页和书签。</p>

    <div class="space-y-4">
      <!-- 主题名称 -->
      <div>
        <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">主题名称</label>
        <input type="text" name="site_theme_name" value="<?= $g('site_theme_name') ?>"
               placeholder=""
               class="field text-sm">
        <p class="mt-1 text-[10px] text-zinc-400">将替换导航栏、页脚、浏览器标签页等处显示的主题名称。</p>
      </div>

      <!-- 网站图标 -->
      <div>
        <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">网站图标（Favicon）</label>
        <div class="flex gap-2">
          <input type="text" name="site_favicon_url" id="faviconInput"
                 value="<?= $g('site_favicon_url') ?>"
                 placeholder="https://… （留空则使用浏览器默认图标）"
                 class="field text-sm flex-1">
          <button type="button" onclick="document.getElementById('faviconFilePick').click()"
                  class="btn-outline whitespace-nowrap text-xs">上传图标</button>
          <input type="file" id="faviconFilePick" accept="image/*" class="hidden">
        </div>
        <p class="mt-1 text-[10px] text-zinc-400">支持 PNG / ICO / SVG / JPG，推荐使用 32×32 或 64×64 的正方形图片，设置后全站统一使用。</p>
        <!-- 图标预览 -->
        <div id="faviconPreviewWrap" class="mt-3 <?= empty($s['site_favicon_url']) ? 'hidden' : '' ?>">
          <p class="text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">预览</p>
          <div class="flex items-center gap-3">
            <img id="faviconPreview" src="<?= $g('site_favicon_url') ?>" alt="图标预览"
                 class="w-8 h-8 object-contain border border-zinc-200 rounded">
            <span class="text-xs text-zinc-400">← 实际显示在浏览器标签页的大小</span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 首页个人卡片 -->
  <div class="bg-white border border-zinc-200 p-6">
    <p class="text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-1">首页个人卡片</p>
    <p class="text-xs text-zinc-400 mb-5">替代原首页大标题，显示居中的头像 + 姓名 + 简介 + 社交图标卡片。</p>

    <div class="space-y-4">
      <!-- 头像 URL -->
      <div>
        <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">头像图片 URL</label>
        <div class="flex gap-2">
          <input type="text" name="homepage_avatar" id="hp_avatar_input"
                 value="<?= $g('homepage_avatar') ?>"
                 placeholder="https://… （留空将使用默认占位头像）"
                 class="field text-sm flex-1">
          <button type="button" onclick="document.getElementById('hpAvatarFilePick').click()"
                  class="btn-outline whitespace-nowrap text-xs">上传头像</button>
          <input type="file" id="hpAvatarFilePick" accept="image/*" class="hidden">
        </div>
        <p class="mt-1 text-[10px] text-zinc-400">填入图片直链 URL，或点击"上传头像"上传后自动填入。</p>
        <!-- 头像预览 -->
        <div id="hpAvatarPreviewWrap" class="mt-3 <?= empty($s['homepage_avatar']) ? 'hidden' : '' ?>">
          <img id="hpAvatarPreview" src="<?= $g('homepage_avatar') ?>" alt="头像预览"
               class="w-16 h-16 rounded-full object-cover border border-zinc-200">
        </div>
      </div>

      <!-- 隐藏头像边框 -->
      <div class="flex items-center gap-3">
        <label class="relative inline-flex items-center cursor-pointer">
          <input type="hidden" name="homepage_avatar_no_frame" value="0">
          <input type="checkbox" name="homepage_avatar_no_frame" value="1"
                 <?= ($s['homepage_avatar_no_frame'] ?? '0') === '1' ? 'checked' : '' ?>
                 class="sr-only peer">
          <div class="w-9 h-5 bg-zinc-200 rounded-full peer peer-checked:bg-zinc-700 transition-colors after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:w-4 after:h-4 after:bg-white after:rounded-full after:transition-all peer-checked:after:translate-x-4"></div>
        </label>
        <span class="text-xs text-zinc-600">透明头像边框（头像保持圆形，开启后边框不可见）</span>
      </div>

      <!-- 显示姓名 -->
      <div>
        <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">显示姓名</label>
        <input type="text" name="homepage_name" value="<?= $g('homepage_name') ?>"
               placeholder="Jeremy Bentham"
               class="field text-sm">
      </div>

      <!-- 简介 -->
      <div>
        <div class="flex items-center justify-between mb-1.5">
          <label class="block text-[10px] text-zinc-400 uppercase tracking-wider">个人简介（首页卡片）<span class="normal-case text-zinc-300 ml-1">(支持HTML)</span></label>
          <div class="flex gap-1">
            <?php
            $bio_btns = [
              ['B',  '<strong>','</strong>','font-bold'],
              ['I',  '<em>','</em>','italic'],
              ['A',  '<a href="">','</a>',''],
              ['P',  '<p>','</p>',''],
              ['BR', '<br>','',''],
            ];
            foreach ($bio_btns as [$lbl,$op,$cl,$cls]): ?>
            <button type="button"
                    data-open="<?= htmlspecialchars($op, ENT_QUOTES) ?>"
                    data-close="<?= htmlspecialchars($cl, ENT_QUOTES) ?>"
                    onclick="wrapBioTag(this)"
                    class="px-2 py-1 text-[11px] border border-zinc-200 bg-white hover:bg-zinc-50 text-zinc-500 transition-colors <?= $cls ?>"><?= $lbl ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <textarea id="homepage_bio" name="homepage_bio" rows="4"
                  placeholder="保持理想，步履不停。"
                  class="field text-sm font-mono resize-y"><?= $g('homepage_bio') ?></textarea>
        <p class="mt-1 text-[10px] text-zinc-400">支持HTML标签，将显示在头像下方。社交图标自动读取下方"数字足迹"中已填写的链接。</p>
      </div>
    </div>
  </div>

  <!-- 微信公众号 -->
  <div class="bg-white border border-zinc-200 p-6">
    <p class="text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-5">微信公众号</p>

    <div class="space-y-4">
      <div>
        <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">二维码图片 URL</label>
        <div class="flex gap-2">
          <input type="text" name="wechat_qr_url" value="<?= $g('wechat_qr_url') ?>"
                 placeholder="https://…（填入二维码图片链接）"
                 class="field text-sm flex-1">
          <button type="button" onclick="document.getElementById('qrFilePick').click()"
                  class="btn-outline whitespace-nowrap text-xs">上传图片</button>
          <input type="file" id="qrFilePick" accept="image/*" class="hidden">
        </div>
        <p class="mt-1 text-[10px] text-zinc-400">填入二维码图片的 URL，或点击"上传图片"上传到服务器后自动填入。</p>
      </div>

      <!-- 预览 -->
      <div id="qrPreviewWrap" class="<?= empty($s['wechat_qr_url']) ? 'hidden' : '' ?>">
        <p class="text-[10px] text-zinc-400 mb-2 uppercase tracking-wider">预览</p>
        <img id="qrPreview" src="<?= $g('wechat_qr_url') ?>" alt="二维码预览"
             class="w-24 h-24 object-cover border border-zinc-200 rounded">
      </div>
    </div>
  </div>

  <!-- 数字足迹 -->
  <div class="bg-white border border-zinc-200 p-6">
    <p class="text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-5">数字足迹</p>
    <p class="text-xs text-zinc-400 mb-4">留空则不在前台显示该链接。</p>

    <div class="space-y-4">
      <?php
      $socials = [
        'social_github'       => ['Github',       'M12 2C6.477 2 2 6.477 2 12c0 4.418 2.865 8.166 6.839 9.489.5.092.682-.217.682-.482 0-.237-.008-.866-.013-1.7-2.782.603-3.369-1.342-3.369-1.342-.454-1.155-1.11-1.462-1.11-1.462-.908-.62.069-.608.069-.608 1.003.07 1.531 1.03 1.531 1.03.892 1.529 2.341 1.087 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12c0-5.523-4.477-10-10-10z'],
        'social_youtube'      => ['Youtube',      'M21.593 7.203a2.506 2.506 0 00-1.762-1.766C18.265 5.007 12 5 12 5s-6.264-.007-7.831.404a2.56 2.56 0 00-1.766 1.778c-.413 1.566-.417 4.814-.417 4.814s-.004 3.264.406 4.814c.23.857.905 1.534 1.763 1.765 1.582.43 7.83.437 7.83.437s6.265.007 7.831-.403a2.515 2.515 0 001.767-1.763c.414-1.565.417-4.812.417-4.812s.02-3.265-.407-4.831zM9.996 15.005l.005-6 5.207 3.005-5.212 2.995z'],
        'social_bilibili'     => ['Bilibili',     'M17.813 4.653h.854c1.51.054 2.769.578 3.773 1.574 1.004.995 1.524 2.249 1.56 3.76v7.36c-.036 1.51-.556 2.769-1.56 3.773s-2.262 1.524-3.773 1.56H5.333c-1.51-.036-2.769-.556-3.773-1.56S.036 18.858 0 17.347v-7.36c.036-1.511.556-2.765 1.56-3.76 1.004-.996 2.262-1.52 3.773-1.574h.774l-1.174-1.12a1.234 1.234 0 0 1-.373-.906c0-.356.124-.658.373-.907l.027-.027c.267-.249.573-.373.92-.373.347 0 .653.124.92.373L9.653 4.44c.071.071.134.142.187.213h4.267a.836.836 0 0 1 .16-.213l2.853-2.747c.267-.249.573-.373.92-.373.347 0 .662.151.929.4.267.249.391.551.391.907 0 .355-.124.657-.373.906zM5.333 7.24c-.746.018-1.373.276-1.88.773-.506.498-.769 1.13-.786 1.894v7.52c.017.764.28 1.395.786 1.893.507.498 1.134.756 1.88.773h13.334c.746-.017 1.373-.275 1.88-.773.506-.498.769-1.129.786-1.893v-7.52c-.017-.765-.28-1.396-.786-1.894-.507-.497-1.134-.755-1.88-.773zM8 11.107c.373 0 .684.124.933.373.25.249.383.569.4.96v1.173c-.017.391-.15.711-.4.96-.249.25-.56.374-.933.374s-.684-.125-.933-.374c-.25-.249-.383-.569-.4-.96V12.44c0-.373.129-.689.386-.947.258-.257.574-.386.947-.386zm8 0c.373 0 .684.124.933.373.25.249.383.569.4.96v1.173c-.017.391-.15.711-.4.96-.249.25-.56.374-.933.374s-.684-.125-.933-.374c-.25-.249-.383-.569-.4-.96V12.44c.017-.391.15-.711.4-.96.249-.249.56-.373.933-.373z'],
        'social_twitter'      => ['Twitter / X',  'M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z'],
        'social_instagram'    => ['Instagram',    'M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z'],
        // 国外平台
        'social_linkedin'     => ['LinkedIn',     'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z'],
        'social_telegram'     => ['Telegram',     'M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z'],
        'social_facebook'     => ['Facebook',     'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z'],
        'social_threads'      => ['Threads',      'M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.868 1.205 8.62.024 12.203 0h.014c2.858.023 5.18.786 6.907 2.27 1.848 1.582 2.87 3.947 3.033 7.029l.004.082h-2.586l-.004-.069c-.155-2.449-.895-4.336-2.201-5.612-1.233-1.204-3.015-1.831-5.312-1.86-2.867.031-5.034.956-6.44 2.748-1.319 1.686-1.99 4.143-1.997 7.299.006 3.155.678 5.611 1.997 7.298 1.407 1.792 3.574 2.717 6.44 2.748 1.973-.027 3.392-.521 4.333-1.507.886-.928 1.344-2.34 1.362-4.198v-.072h-5.87v-2.33h8.442l.004.096c.024.651.028 1.319-.01 1.967-.18 2.932-1.12 5.194-2.798 6.72-1.61 1.465-3.822 2.22-6.574 2.244z'],
        'social_tiktok'       => ['TikTok',       'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z'],
        // 国内平台
        'social_weibo'        => ['微博 Weibo',    'M10.098 20.323c-3.977.391-7.414-1.406-7.672-4.02-.259-2.609 2.759-5.047 6.74-5.441 3.979-.394 7.413 1.404 7.671 4.018.259 2.6-2.759 5.049-6.739 5.443zM9.05 17.219c-.384.616-1.208.884-1.829.602-.612-.279-.793-.991-.406-1.593.379-.595 1.176-.861 1.793-.601.622.263.826.968.442 1.592zm2.73-1.511c-.141.237-.449.353-.689.253-.236-.09-.313-.361-.177-.586.138-.227.436-.346.672-.24.239.1.323.352.194.573zm2.218-5.209c-1.088-.299-2.316-.22-3.2.426L9.75 11.84c-.562.463-1.188 1.2-.896 2.194.285.882 1.284 1.538 2.375 1.498 1.37-.046 2.503-1.107 2.616-2.479.063-.74-.193-1.5-.847-1.553zM19.38 7.9c-.51-.23-1.07-.39-1.62-.45.28-.3.49-.65.59-1.04.37-1.48-.64-2.98-2.26-3.36-1.62-.38-3.26.49-3.63 1.97-.07.28-.07.56-.03.83H12.4c-.06 0-.11.01-.17.01a5.41 5.41 0 0 0-2.03.38l-.22.1C8.29 7.06 7.22 8.66 7.44 10.38c-.02-.01-.03-.01-.05-.02C5.42 9.5 3.46 9.73 2.46 11.04c-1 1.3-.6 3.16.91 4.2a7.88 7.88 0 0 0-.06.8c0 3.51 3.56 6.35 7.95 6.35 4.39 0 7.95-2.84 7.95-6.35 0-.66-.13-1.29-.38-1.89.85-.5 1.46-1.29 1.46-2.25-.01-1.44-1.28-2.65-2.9-2.65 0 0 .01 0 0 0z'],
        'social_xiaohongshu'  => ['小红书',        'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.5 10.5h-3v3a1.5 1.5 0 01-3 0v-3h-3a1.5 1.5 0 010-3h3v-3a1.5 1.5 0 013 0v3h3a1.5 1.5 0 010 3z'],
        'social_zhihu'        => ['知乎',          'M5.721 0C2.251 0 0 2.25 0 5.719V18.28C0 21.751 2.252 24 5.721 24H18.28C21.751 24 24 21.75 24 18.281V5.72C24 2.249 21.75 0 18.281 0zm6.964 6.575l-.917.008v6.31l.917-.001c.35 0 .52.193.52.526v.35c0 .335-.17.527-.52.527H9.243c-.35 0-.52-.192-.52-.527v-.35c0-.333.17-.526.52-.526l.915.001V7.478l-.915-.008c-.35 0-.52-.185-.52-.517v-.362c0-.333.17-.517.52-.517h2.942c.35 0 .52.184.52.517v.362c0 .332-.17.622-.52.622zm5.09 10.9c-.282.376-.71.563-1.138.563h-3.61v-1.348h3.19c.196 0 .313-.074.383-.17l1.546-2.117-1.546-2.118c-.07-.096-.187-.17-.383-.17h-3.19V10.77h3.61c.428 0 .856.186 1.138.562l1.97 2.7c.282.374.282.866 0 1.24l-1.97 2.203z'],
        'social_douyin'       => ['抖音 Douyin',   'M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.32 6.32 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V9.15a8.16 8.16 0 004.77 1.52V7.23a4.85 4.85 0 01-1-.54z'],
        'social_douban'       => ['豆瓣',          'M1.5 5.25h21v1.5h-21zM6 18.75l1.5-7.5h9l1.5 7.5H6zm3.75 2.25h4.5v1.5h-4.5zM10.5 2.25h3v3h-3z'],
      ];
      foreach ($socials as $key => [$label, $icon_path]): ?>
      <div>
        <label class="flex items-center gap-2 text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">
          <svg class="w-3.5 h-3.5 text-zinc-500 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
            <path d="<?= $icon_path ?>"/>
          </svg>
          <?= $label ?>
        </label>
        <input type="text" name="<?= $key ?>" value="<?= $g($key) ?>"
               placeholder="https://…（留空则隐藏）"
               class="field text-sm">
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- 友情链接 -->
  <div id="section-friend-links" class="bg-white border border-zinc-200 p-6">
    <p class="text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-1">友情链接 / Friend Links</p>
    <p class="text-xs text-zinc-400 mb-5">添加友情链接后将在侧边栏导航中显示，点击可展开。</p>

    <!-- 已有链接列表 -->
    <?php if (!empty($friend_links)): ?>
    <div class="mb-5 space-y-2">
      <?php foreach ($friend_links as $fl): ?>
      <div class="flex items-center gap-3 p-3 bg-zinc-50 border border-zinc-100 rounded">
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-zinc-800 truncate"><?= htmlspecialchars($fl['name']) ?></p>
          <p class="text-[11px] text-zinc-400 truncate"><?= htmlspecialchars($fl['url']) ?></p>
        </div>
        <button type="submit"
                name="action" value="delete_friend_link:<?= (int)$fl['id'] ?>"
                formnovalidate
                onclick="return confirm('确认删除「<?= htmlspecialchars($fl['name'], ENT_QUOTES) ?>」？')"
                class="text-[11px] text-zinc-400 hover:text-red-500 transition whitespace-nowrap">
          删除
        </button>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="text-xs text-zinc-400 italic mb-5">暂无友情链接。</p>
    <?php endif; ?>

    <!-- 添加新链接 -->
    <div class="border-t border-zinc-100 pt-5">
      <p class="text-[10px] text-zinc-400 uppercase tracking-wider mb-3">添加链接</p>
      <div class="space-y-3">
        <div class="flex gap-3">
          <div class="flex-1">
            <input type="text" name="link_name" placeholder="名称（如：张三的博客）"
                   class="field text-sm w-full">
          </div>
          <div class="flex-1">
            <input type="url" name="link_url" placeholder="https://example.com"
                   class="field text-sm w-full">
          </div>
          <button type="submit"
                  name="action" value="add_friend_link"
                  class="px-4 py-2 bg-zinc-800 text-white text-xs hover:bg-zinc-700 transition whitespace-nowrap">
            添加
          </button>
        </div>
      </div>
    </div>

    <!-- 友链申请管理 -->
    <div class="border-t border-zinc-100 mt-6 pt-5">
      <div class="flex items-center gap-2 mb-4">
        <p class="text-[10px] text-zinc-400 uppercase tracking-wider">友链申请</p>
        <?php $pending_count = count(array_filter($applications, fn($a) => $a['status'] === 'pending')); ?>
        <?php if ($pending_count > 0): ?>
        <span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-zinc-800 text-white text-[9px] font-medium"><?= $pending_count ?></span>
        <?php endif; ?>
      </div>

      <?php if (empty($applications)): ?>
      <p class="text-xs text-zinc-400 italic">暂无申请记录。</p>
      <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($applications as $app): ?>
        <?php
          $statusLabel = ['pending' => '待审核', 'approved' => '已通过', 'rejected' => '已拒绝'][$app['status']] ?? '';
          $statusClass  = ['pending' => 'text-amber-600 bg-amber-50 border-amber-200',
                           'approved'=> 'text-green-700 bg-green-50 border-green-200',
                           'rejected'=> 'text-zinc-400 bg-zinc-50 border-zinc-200'][$app['status']] ?? '';
        ?>
        <div class="p-3 bg-zinc-50 border border-zinc-100 rounded">
          <div class="flex flex-col gap-3">
            <!-- 申请信息 -->
            <div class="flex-1 min-w-0 space-y-0.5">
              <div class="flex items-center gap-2 flex-wrap">
                <p class="text-sm font-medium text-zinc-800"><?= htmlspecialchars($app['site_name']) ?></p>
                <span class="text-[10px] border px-1.5 py-0.5 rounded <?= $statusClass ?>"><?= $statusLabel ?></span>
              </div>
              <a href="<?= htmlspecialchars($app['site_url']) ?>" target="_blank" rel="noopener noreferrer"
                 class="text-[11px] text-blue-500 hover:underline truncate block max-w-full"><?= htmlspecialchars($app['site_url']) ?></a>
              <?php if ($app['description']): ?>
              <p class="text-[11px] text-zinc-500"><?= htmlspecialchars($app['description']) ?></p>
              <?php endif; ?>
              <?php if (!empty($app['admin_message'])): ?>
              <p class="text-[11px] text-zinc-500">寄语：<?= htmlspecialchars($app['admin_message']) ?></p>
              <?php endif; ?>
              <p class="text-[10px] text-zinc-400">邮箱：<?= htmlspecialchars($app['email']) ?> &nbsp;·&nbsp; <?= date('Y-m-d H:i', strtotime($app['created_at'])) ?></p>
            </div>
            <!-- 操作区：在移动端纵向排列，宽屏横向排列 -->
            <div class="flex flex-col gap-2">
              <?php if ($app['status'] === 'pending'): ?>
              <button type="submit"
                      name="action" value="approve_application:<?= (int)$app['id'] ?>"
                      formnovalidate
                      class="text-[11px] text-green-700 hover:text-green-900 border border-green-200 bg-green-50 hover:bg-green-100 px-3 py-2 rounded transition w-full text-center">
                ✓ 通过
              </button>
              <div class="space-y-1">
                <textarea name="admin_message[<?= (int)$app['id'] ?>]" rows="2"
                          placeholder="可选：寄语/拒绝原因（会发给申请者）"
                          class="w-full field text-xs resize-none"></textarea>
                <button type="submit"
                        name="action" value="reject_application:<?= (int)$app['id'] ?>"
                        formnovalidate
                        class="text-[11px] text-zinc-500 hover:text-zinc-800 border border-zinc-200 bg-white hover:bg-zinc-50 px-3 py-2 rounded transition w-full text-center">
                  拒绝并发送寄语
                </button>
              </div>
              <?php endif; ?>
              <button type="submit"
                      name="action" value="delete_application:<?= (int)$app['id'] ?>"
                      formnovalidate
                      onclick="return confirm('确认删除此申请记录？')"
                      class="text-[11px] text-zinc-300 hover:text-red-500 transition px-3 py-1.5 w-full text-center border border-transparent hover:border-red-100 rounded">
                删除记录
              </button>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- 邮件 SMTP -->
  <div class="bg-white border border-zinc-200 p-6">
    <p class="text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-1">邮件 SMTP</p>
    <p class="text-xs text-zinc-400 mb-5">用于发送「友链申请」相关通知邮件（收到 / 通过 / 拒绝 + 寄语），以及收到新申请时通知管理员。</p>

    <div class="space-y-4">
      <!-- 开关 -->
      <div class="flex items-center gap-3">
        <label class="relative inline-flex items-center cursor-pointer">
          <input type="hidden" name="smtp_enabled" value="0">
          <input type="checkbox" name="smtp_enabled" value="1"
                 <?= ($s['smtp_enabled'] ?? '0') === '1' ? 'checked' : '' ?>
                 class="sr-only peer">
          <div class="w-9 h-5 bg-zinc-200 rounded-full peer peer-checked:bg-zinc-700 transition-colors after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:w-4 after:h-4 after:bg-white after:rounded-full after:transition-all peer-checked:after:translate-x-4"></div>
        </label>
        <span class="text-xs text-zinc-600">启用邮件发送</span>
      </div>

      <!-- 服务商 -->
      <div>
        <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">服务商</label>
        <select name="smtp_provider" id="smtp_provider" class="field text-sm">
          <?php $prov = $s['smtp_provider'] ?? 'qq'; ?>
          <option value="qq"     <?= $prov === 'qq' ? 'selected' : '' ?>>QQ 邮箱</option>
          <option value="gmail"  <?= $prov === 'gmail' ? 'selected' : '' ?>>Google / Gmail</option>
          <option value="custom" <?= $prov === 'custom' ? 'selected' : '' ?>>自定义</option>
        </select>
        <p class="mt-1 text-[10px] text-zinc-400">选择 QQ / Gmail 会自动填入推荐的 Host/端口/加密方式（仍可手动改）。</p>
      </div>

      <!-- 连接信息 -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
          <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">SMTP Host</label>
          <input type="text" name="smtp_host" id="smtp_host" value="<?= $g('smtp_host') ?>" placeholder="smtp.qq.com / smtp.gmail.com"
                 class="field text-sm">
        </div>
        <div>
          <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">端口</label>
          <input type="number" name="smtp_port" id="smtp_port" value="<?= $g('smtp_port') ?>" placeholder="465 / 587"
                 class="field text-sm">
        </div>
        <div>
          <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">加密</label>
          <?php $sec = $s['smtp_secure'] ?? 'ssl'; ?>
          <select name="smtp_secure" id="smtp_secure" class="field text-sm">
            <option value="ssl"  <?= $sec === 'ssl' ? 'selected' : '' ?>>SSL (SMTPS)</option>
            <option value="tls"  <?= $sec === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
            <option value="none" <?= $sec === 'none' ? 'selected' : '' ?>>无</option>
          </select>
        </div>
      </div>

      <!-- 账号 -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">账号（用户名）</label>
          <input type="text" name="smtp_username" value="<?= $g('smtp_username') ?>" placeholder="你的邮箱地址"
                 class="field text-sm">
          <p class="mt-1 text-[10px] text-zinc-400">QQ 邮箱一般是完整邮箱；Gmail 建议使用「应用专用密码」。</p>
        </div>
        <div>
          <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">密钥 / 授权码</label>
          <input type="password" name="smtp_password" value="" placeholder="留空则不修改"
                 class="field text-sm">
          <p class="mt-1 text-[10px] text-zinc-400">为安全起见，这里不会回显旧密钥；你只有在需要更新时才填写。</p>
        </div>
      </div>

      <!-- 发件人 -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">发件人邮箱</label>
          <input type="text" name="smtp_from_email" value="<?= $g('smtp_from_email') ?>" placeholder="留空则使用账号邮箱"
                 class="field text-sm">
        </div>
        <div>
          <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">发件人昵称</label>
          <input type="text" name="smtp_from_name" value="<?= $g('smtp_from_name') ?>" placeholder="博客管理员"
                 class="field text-sm">
        </div>
      </div>

      <!-- 管理员通知邮箱 -->
      <div>
        <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">管理员通知邮箱</label>
        <input type="text" name="admin_notify_email" value="<?= $g('admin_notify_email') ?>"
               placeholder="收到新友链申请时通知此邮箱"
               class="field text-sm">
        <p class="mt-1 text-[10px] text-zinc-400">有人提交友链申请时，系统会向此邮箱发送提醒。留空则不发送管理员通知。</p>
      </div>
    </div>

    <script>
      (function(){
        var provider = document.getElementById('smtp_provider');
        var host = document.getElementById('smtp_host');
        var port = document.getElementById('smtp_port');
        var sec  = document.getElementById('smtp_secure');
        if (!provider || !host || !port || !sec) return;

        var presets = {
          qq:    { host: 'smtp.qq.com',    port: '465', secure: 'ssl' },
          gmail: { host: 'smtp.gmail.com', port: '587', secure: 'tls' }
        };

        function isEmpty(v){ return !v || String(v).trim() === ''; }

        function applyPreset(to, from){
          if (!presets[to]) return; // custom 不处理
          var pTo = presets[to];
          var pFrom = presets[from] || null;

          // 只有当当前值是空，或等于“上一个服务商”的默认值时，才自动替换
          if (isEmpty(host.value) || (pFrom && host.value === pFrom.host)) host.value = pTo.host;
          if (isEmpty(port.value) || (pFrom && String(port.value) === String(pFrom.port))) port.value = pTo.port;
          if (isEmpty(sec.value)  || (pFrom && String(sec.value)  === String(pFrom.secure))) sec.value = pTo.secure;
        }

        var last = provider.value || 'qq';
        provider.addEventListener('change', function(){
          var to = provider.value;
          applyPreset(to, last);
          last = to;
        });

        // 首次加载：如果为空则按当前 provider 补默认
        applyPreset(provider.value, provider.value);
      })();
    </script>
  </div>

  <!-- 关于我 -->
  <div class="bg-white border border-zinc-200 p-6">
    <p class="text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-5">关于我 / About</p>
    <div class="space-y-4">
      <div>
        <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">个人语录</label>
        <input type="text" name="about_quote" value="<?= $g('about_quote') ?>"
               placeholder="保持理想，步履不停。"
               class="field text-sm">
        <p class="mt-1 text-[10px] text-zinc-400">显示在网页底部 About 区域的大标题语录。</p>
      </div>
      <div>
        <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">联系邮箱</label>
        <input type="email" name="about_email" value="<?= $g('about_email') ?>"
               placeholder="you@example.com"
               class="field text-sm">
        <p class="mt-1 text-[10px] text-zinc-400">留空则隐藏联系邮箱。</p>
      </div>
    </div>
  </div>

  <!-- 主页音乐播放器 -->
  <div class="bg-white border border-zinc-200 p-6">
    <p class="text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-1">音乐播放器</p>
    <p class="text-xs text-zinc-400 mb-5">控制主页是否显示内嵌音乐播放器。播放器读取 <code class="text-zinc-500 bg-zinc-100 px-1 rounded">uploads/music/</code> 目录中的音频文件（mp3 / flac / m4a / ogg / wav），目录为空时播放器自动隐藏。</p>

    <div class="space-y-5">
      <!-- 开关 -->
      <div class="flex items-center gap-3">
        <label class="relative inline-flex items-center cursor-pointer">
          <input type="hidden" name="homepage_music_player_enabled" value="0">
          <input type="checkbox" name="homepage_music_player_enabled" value="1"
                 <?= ($s['homepage_music_player_enabled'] ?? '0') === '1' ? 'checked' : '' ?>
                 class="sr-only peer">
          <div class="w-9 h-5 bg-zinc-200 rounded-full peer peer-checked:bg-zinc-700 transition-colors after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:w-4 after:h-4 after:bg-white after:rounded-full after:transition-all peer-checked:after:translate-x-4"></div>
        </label>
        <span class="text-xs text-zinc-600">开启主页音乐播放器</span>
      </div>

      <!-- 音乐上传 -->
      <div class="border-t border-zinc-100 pt-5">
        <p class="text-[10px] text-zinc-400 uppercase tracking-wider mb-3">上传音乐文件</p>
        <div class="flex items-center gap-3">
          <button type="button" onclick="document.getElementById('musicFilePick').click()"
                  class="text-xs px-4 py-2 border border-zinc-300 hover:border-zinc-600 transition-colors">
            选择文件
          </button>
          <input id="musicFilePick" type="file" accept="audio/*,.mp3,.flac,.m4a,.ogg,.wav,.aac" class="hidden" multiple>
        </div>
        <!-- 进度列表 -->
        <div id="musicUploadList" class="mt-3 space-y-1"></div>
      </div>

      <!-- 已上传的音乐文件（可折叠） -->
      <div class="border-t border-zinc-100 pt-5">
        <?php
          $music_dir = __DIR__ . '/../uploads/music/';
          $audio_exts = ['mp3','flac','m4a','ogg','wav','aac'];
          $music_files = [];
          if (is_dir($music_dir)) {
            foreach (scandir($music_dir) as $f) {
              if ($f === '.' || $f === '..') continue;
              $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
              if (in_array($ext, $audio_exts)) $music_files[] = $f;
            }
          }
          $music_count = count($music_files);
        ?>
        <button type="button" onclick="toggleMusicList(this)"
                class="flex items-center gap-2 w-full text-left group">
          <p class="text-[10px] text-zinc-400 uppercase tracking-wider">已上传的音乐文件</p>
          <span class="text-[10px] text-zinc-400">(<?= $music_count ?>)</span>
          <svg id="musicListArrow" class="w-3 h-3 text-zinc-400 ml-auto transition-transform duration-200 <?= $music_count > 0 ? '' : 'rotate-180' ?>"
               fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
          </svg>
        </button>
        <div id="musicFileList" class="mt-3 space-y-1 <?= $music_count > 0 ? 'hidden' : '' ?>">
          <?php if (empty($music_files)): ?>
          <p class="text-xs text-zinc-400 italic" id="musicEmptyHint">暂无音乐文件</p>
          <?php else: foreach ($music_files as $mf): ?>
          <div class="flex items-center justify-between py-1.5 px-3 bg-zinc-50 border border-zinc-100 music-file-row" data-filename="<?= htmlspecialchars($mf) ?>">
            <span class="text-xs text-zinc-600 truncate max-w-xs"><?= htmlspecialchars($mf) ?></span>
            <button type="button" onclick="deleteMusicFile(this, '<?= htmlspecialchars($mf, ENT_QUOTES) ?>')"
                    class="text-[10px] text-zinc-400 hover:text-red-500 transition-colors ml-4 shrink-0">删除</button>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- 主页背景图 -->
  <div class="bg-white border border-zinc-200 p-6">
    <p class="text-[10px] tracking-[.25em] uppercase text-zinc-400 mb-1">主页背景图</p>
    <p class="text-xs text-zinc-400 mb-5">设置首页全屏背景图片，留空则使用纯白/深色背景。</p>

    <div class="space-y-4">
      <!-- 背景图 URL -->
      <div>
        <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">背景图片 URL</label>
        <div class="flex gap-2">
          <input type="text" name="homepage_bg_url" id="hp_bg_input"
                 value="<?= $g('homepage_bg_url') ?>"
                 placeholder="https://… （留空则不显示背景图）"
                 class="field text-sm flex-1">
          <button type="button" onclick="document.getElementById('hpBgFilePick').click()"
                  class="btn-outline whitespace-nowrap text-xs">上传背景图</button>
          <input type="file" id="hpBgFilePick" accept="image/*" class="hidden">
        </div>
        <p class="mt-1 text-[10px] text-zinc-400">建议使用宽幅横向图片（宽 ≥ 1920px），JPG/PNG/WebP 均可。</p>
      </div>

      <!-- 遮罩透明度 -->
      <div>
        <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">
          遮罩不透明度（0 = 无遮罩，100 = 全黑）&nbsp;
          <span id="opacityVal" class="font-medium text-zinc-600"><?= intval($s['homepage_bg_opacity'] ?? 30) ?>%</span>
        </label>
        <input type="range" name="homepage_bg_opacity" id="hp_bg_opacity"
               min="0" max="80" step="5"
               value="<?= intval($s['homepage_bg_opacity'] ?? 30) ?>"
               class="w-full accent-zinc-700 cursor-pointer">
        <p class="mt-1 text-[10px] text-zinc-400">深色遮罩让前景文字更易阅读，推荐 20–40%。</p>
      </div>

      <!-- 磨砂强度 -->
      <div>
        <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">
          磨砂模糊强度（0 = 无磨砂，20 = 强磨砂）&nbsp;
          <span id="blurVal" class="font-medium text-zinc-600"><?= intval($s['homepage_bg_blur'] ?? 0) ?>px</span>
        </label>
        <input type="range" name="homepage_bg_blur" id="hp_bg_blur"
               min="0" max="20" step="1"
               value="<?= intval($s['homepage_bg_blur'] ?? 0) ?>"
               class="w-full accent-zinc-700 cursor-pointer">
        <p class="mt-1 text-[10px] text-zinc-400">磨砂效果让背景图产生毛玻璃质感，推荐 4–10px。</p>
      </div>

      <!-- 裁剪位置 -->
      <div>
        <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">背景图裁剪位置</label>
        <div class="grid grid-cols-3 gap-1.5">
          <?php
          $positions = [
              'top left'    => '左上', 'top center'    => '上方居中', 'top right'    => '右上',
              'center left' => '左侧居中', 'center'    => '正中（默认）', 'center right' => '右侧居中',
              'bottom left' => '左下', 'bottom center' => '下方居中', 'bottom right' => '右下',
          ];
          $cur_pos = $s['homepage_bg_position'] ?? 'center';
          foreach ($positions as $val => $label): ?>
          <label class="flex items-center gap-1.5 cursor-pointer select-none">
            <input type="radio" name="homepage_bg_position" value="<?= $val ?>"
                   <?= $cur_pos === $val ? 'checked' : '' ?>
                   class="accent-zinc-700" onchange="updateBgPosition(this.value)">
            <span class="text-xs text-zinc-600"><?= $label ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <p class="mt-1 text-[10px] text-zinc-400">选择图片哪个区域保持可见，图片超出部分将被裁掉。</p>
      </div>

      <!-- 移动端开关 -->
      <div>
        <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">移动端显示</label>
        <label class="flex items-center gap-2.5 cursor-pointer select-none">
          <input type="hidden" name="homepage_bg_mobile_enabled" value="0">
          <input type="checkbox" name="homepage_bg_mobile_enabled" value="1"
                 <?= ($s['homepage_bg_mobile_enabled'] ?? '0') === '1' ? 'checked' : '' ?>
                 class="w-4 h-4 accent-zinc-700 cursor-pointer">
          <span class="text-xs text-zinc-600">在移动端也显示背景图</span>
        </label>
        <p class="mt-1 text-[10px] text-zinc-400">默认关闭，移动端将使用纯白/深色背景，节省流量并提升阅读体验。</p>
      </div>

      <!-- 网格渐变特效 -->
      <div>
        <label class="block text-[10px] text-zinc-400 mb-1.5 uppercase tracking-wider">网格渐变特效</label>
        <div class="flex items-center gap-3">
          <label class="relative inline-flex items-center cursor-pointer">
            <input type="hidden" name="homepage_bg_grid_enabled" value="0">
            <input type="checkbox" name="homepage_bg_grid_enabled" value="1"
                   <?= ($s['homepage_bg_grid_enabled'] ?? '0') === '1' ? 'checked' : '' ?>
                   class="sr-only peer">
            <div class="w-9 h-5 bg-zinc-200 rounded-full peer peer-checked:bg-zinc-700 transition-colors after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:w-4 after:h-4 after:bg-white after:rounded-full after:transition-all peer-checked:after:translate-x-4"></div>
          </label>
          <span class="text-xs text-zinc-600">开启主页背景网格渐变特效</span>
        </div>
        <p class="mt-1 text-[10px] text-zinc-400">在首页顶部叠加一层细网格线，从顶部向下逐渐淡出，亮色 / 深色模式自动适配。默认关闭。</p>
      </div>

      <!-- 背景图预览 -->
      <div id="hpBgPreviewWrap" class="<?= empty($s['homepage_bg_url']) ? 'hidden' : '' ?>">
        <p class="text-[10px] text-zinc-400 mb-2 uppercase tracking-wider">预览（含遮罩效果）</p>
        <div class="relative w-full h-40 rounded overflow-hidden border border-zinc-200">
          <img id="hpBgPreview" src="<?= $g('homepage_bg_url') ?>" alt="背景图预览"
               class="absolute inset-0 w-full h-full object-cover"
               style="filter:blur(<?= intval($s['homepage_bg_blur'] ?? 0) ?>px);transform:scale(1.05)">
          <div id="hpBgOverlay"
               class="absolute inset-0 bg-black"
               style="opacity:<?= round(intval($s['homepage_bg_opacity'] ?? 30) / 100, 2) ?>"></div>
          <div id="hpBgGridPreview"
               class="absolute inset-0 pointer-events-none <?= ($s['homepage_bg_grid_enabled'] ?? '0') === '1' ? '' : 'hidden' ?>"
               style="background-image:linear-gradient(rgba(255,255,255,.15) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.15) 1px,transparent 1px);background-size:20px 20px;-webkit-mask-image:radial-gradient(ellipse 90% 70% at 50% 0%,#000 25%,transparent 85%);mask-image:radial-gradient(ellipse 90% 70% at 50% 0%,#000 25%,transparent 85%)"></div>
          <div class="absolute inset-0 flex flex-col items-center justify-center text-white space-y-1 pointer-events-none">
            <div class="w-10 h-10 rounded-full bg-white/20 border border-white/30"></div>
            <p class="text-xs font-medium">姓名预览</p>
            <p class="text-[10px] opacity-60">个人简介预览文字</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 保存按钮 -->
  <div class="flex justify-end">
    <button type="submit" class="btn-primary">保存设置</button>
  </div>

</div>
</form>

<script>
// 简介 HTML 编辑器工具栏
function wrapBioTag(btn) {
  const open  = btn.dataset.open;
  const close = btn.dataset.close;
  const ta = document.getElementById('homepage_bio');
  const s = ta.selectionStart, e = ta.selectionEnd;
  const sel = ta.value.substring(s, e);
  ta.value = ta.value.substring(0, s) + open + sel + close + ta.value.substring(e);
  ta.focus();
  ta.selectionStart = s + open.length;
  ta.selectionEnd   = s + open.length + sel.length;
}

// 微信二维码 URL 实时预览
const qrInput   = document.querySelector('input[name="wechat_qr_url"]');
const qrPreview = document.getElementById('qrPreview');
const qrWrap    = document.getElementById('qrPreviewWrap');

qrInput.addEventListener('input', function() {
  const url = this.value.trim();
  if (url) {
    qrPreview.src = url;
    qrWrap.classList.remove('hidden');
  } else {
    qrWrap.classList.add('hidden');
  }
});

// 上传二维码图片
document.getElementById('qrFilePick').addEventListener('change', async function() {
  const file = this.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('file', file);
  const btn = document.querySelector('button[onclick*="qrFilePick"]');
  btn.textContent = '上传中…';
  btn.disabled = true;
  try {
    const res  = await fetch('upload.php', { method:'POST', body:fd });
    const data = await res.json();
    if (data.url) {
      qrInput.value = data.url;
      qrPreview.src = data.url;
      qrWrap.classList.remove('hidden');
    } else {
      alert('上传失败：' + (data.error || '未知错误'));
      console.error('服务器响应：', data);
    }
  } catch(e) {
    alert('上传出错：' + e.message);
  } finally {
    btn.textContent = '上传图片';
    btn.disabled = false;
    this.value = '';
  }
});

// ---- 首页头像 URL 实时预览 ----
const hpAvatarInput   = document.getElementById('hp_avatar_input');
const hpAvatarPreview = document.getElementById('hpAvatarPreview');
const hpAvatarWrap    = document.getElementById('hpAvatarPreviewWrap');

hpAvatarInput.addEventListener('input', function() {
  const url = this.value.trim();
  if (url) {
    hpAvatarPreview.src = url;
    hpAvatarWrap.classList.remove('hidden');
  } else {
    hpAvatarWrap.classList.add('hidden');
  }
});

// 上传首页头像
document.getElementById('hpAvatarFilePick').addEventListener('change', async function() {
  const file = this.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('file', file);
  const btn = document.querySelector('button[onclick*="hpAvatarFilePick"]');
  btn.textContent = '上传中…';
  btn.disabled = true;
  try {
    const res  = await fetch('upload.php', { method:'POST', body:fd });
    const data = await res.json();
    if (data.url) {
      hpAvatarInput.value = data.url;
      hpAvatarPreview.src = data.url;
      hpAvatarWrap.classList.remove('hidden');
    } else {
      alert('上传失败：' + (data.error || '未知错误'));
    }
  } catch(e) {
    alert('上传出错：' + e.message);
  } finally {
    btn.textContent = '上传头像';
    btn.disabled = false;
    this.value = '';
  }
});
// ---- 主页背景图 URL 实时预览 ----
const hpBgInput   = document.getElementById('hp_bg_input');
const hpBgPreview = document.getElementById('hpBgPreview');
const hpBgWrap    = document.getElementById('hpBgPreviewWrap');
const hpBgOverlay = document.getElementById('hpBgOverlay');
const hpBgOpacity = document.getElementById('hp_bg_opacity');
const opacityVal  = document.getElementById('opacityVal');
const hpBgBlur    = document.getElementById('hp_bg_blur');
const blurVal     = document.getElementById('blurVal');

hpBgInput.addEventListener('input', function() {
  const url = this.value.trim();
  if (url) {
    hpBgPreview.src = url;
    hpBgWrap.classList.remove('hidden');
  } else {
    hpBgWrap.classList.add('hidden');
  }
});

hpBgOpacity.addEventListener('input', function() {
  const v = parseInt(this.value);
  opacityVal.textContent = v + '%';
  hpBgOverlay.style.opacity = (v / 100).toFixed(2);
});

hpBgBlur.addEventListener('input', function() {
  const v = parseInt(this.value);
  blurVal.textContent = v + 'px';
  hpBgPreview.style.filter = v > 0 ? `blur(${v}px)` : '';
  hpBgPreview.style.transform = v > 0 ? 'scale(1.05)' : '';
});

// 背景图裁剪位置实时预览
function updateBgPosition(val) {
  hpBgPreview.style.objectPosition = val;
}
// 初始化预览位置
(function(){
  const checked = document.querySelector('input[name="homepage_bg_position"]:checked');
  if (checked) hpBgPreview.style.objectPosition = checked.value;
})();

// ---- 网格渐变特效实时预览 ----
const hpGridToggle  = document.querySelector('input[name="homepage_bg_grid_enabled"]');
const hpGridPreview = document.getElementById('hpBgGridPreview');
if (hpGridToggle && hpGridPreview) {
  hpGridToggle.addEventListener('change', function() {
    hpGridPreview.classList.toggle('hidden', !this.checked);
  });
}

// ---- 网站图标（Favicon）实时预览 ----
const faviconInput   = document.getElementById('faviconInput');
const faviconPreview = document.getElementById('faviconPreview');
const faviconWrap    = document.getElementById('faviconPreviewWrap');

faviconInput.addEventListener('input', function() {
  const url = this.value.trim();
  if (url) {
    faviconPreview.src = url;
    faviconWrap.classList.remove('hidden');
  } else {
    faviconWrap.classList.add('hidden');
  }
});

document.getElementById('faviconFilePick').addEventListener('change', async function() {
  const file = this.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('file', file);
  const btn = document.querySelector('button[onclick*="faviconFilePick"]');
  btn.textContent = '上传中…';
  btn.disabled = true;
  try {
    const res  = await fetch('upload.php', { method:'POST', body:fd });
    const data = await res.json();
    if (data.url) {
      faviconInput.value = data.url;
      faviconPreview.src = data.url;
      faviconWrap.classList.remove('hidden');
    } else {
      alert('上传失败：' + (data.error || '未知错误'));
    }
  } catch(e) {
    alert('上传出错：' + e.message);
  } finally {
    btn.textContent = '上传图标';
    btn.disabled = false;
    this.value = '';
  }
});

// 上传背景图
document.getElementById('hpBgFilePick').addEventListener('change', async function() {
  const file = this.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('file', file);
  const btn = document.querySelector('button[onclick*="hpBgFilePick"]');
  btn.textContent = '上传中…';
  btn.disabled = true;
  try {
    const res  = await fetch('upload.php', { method:'POST', body:fd });
    const data = await res.json();
    if (data.url) {
      hpBgInput.value = data.url;
      hpBgPreview.src = data.url;
      hpBgWrap.classList.remove('hidden');
    } else {
      alert('上传失败：' + (data.error || '未知错误'));
    }
  } catch(e) {
    alert('上传出错：' + e.message);
  } finally {
    btn.textContent = '上传背景图';
    btn.disabled = false;
    this.value = '';
  }
});

// ---- 音乐文件列表折叠 ----
function toggleMusicList(btn) {
  const list  = document.getElementById('musicFileList');
  const arrow = document.getElementById('musicListArrow');
  const hidden = list.classList.toggle('hidden');
  arrow.style.transform = hidden ? '' : 'rotate(180deg)';
}

function updateMusicCount(delta) {
  const btn = document.querySelector('button[onclick="toggleMusicList(this)"]');
  if (!btn) return;
  const span = btn.querySelector('span');
  if (!span) return;
  const cur = parseInt(span.textContent.replace(/\D/g, '')) || 0;
  span.textContent = '(' + Math.max(0, cur + delta) + ')';
}

// ---- 音乐上传 ----
document.getElementById('musicFilePick').addEventListener('change', async function() {
  const files = Array.from(this.files);
  if (!files.length) return;
  const list = document.getElementById('musicUploadList');
  const emptyHint = document.getElementById('musicEmptyHint');

  for (const file of files) {
    const row = document.createElement('div');
    row.className = 'flex items-center gap-2 py-1.5 px-3 bg-zinc-50 border border-zinc-100';
    row.innerHTML = `<span class="text-xs text-zinc-500 truncate max-w-xs flex-1">${file.name}</span><span class="text-[10px] text-zinc-400 shrink-0">上传中…</span>`;
    list.appendChild(row);

    const fd = new FormData();
    fd.append('file', file);
    fd.append('type', 'music');

    try {
      const res  = await fetch('upload.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.url) {
        // 加入文件列表
        const fileList = document.getElementById('musicFileList');
        if (emptyHint) emptyHint.remove();
        const fileRow = document.createElement('div');
        fileRow.className = 'flex items-center justify-between py-1.5 px-3 bg-zinc-50 border border-zinc-100 music-file-row';
        fileRow.dataset.filename = data.filename;
        fileRow.innerHTML = `<span class="text-xs text-zinc-600 truncate max-w-xs">${data.filename}</span><button type="button" onclick="deleteMusicFile(this,'${data.filename.replace(/'/g,"\'")}')" class="text-[10px] text-zinc-400 hover:text-red-500 transition-colors ml-4 shrink-0">删除</button>`;
        fileList.appendChild(fileRow);
        updateMusicCount(1);
        // 上传后自动展开列表
        const ml = document.getElementById('musicFileList');
        const arrow = document.getElementById('musicListArrow');
        if (ml.classList.contains('hidden')) { ml.classList.remove('hidden'); arrow.style.transform = 'rotate(180deg)'; }
        row.querySelector('span:last-child').textContent = '✓ 完成';
        row.querySelector('span:last-child').classList.replace('text-zinc-400','text-green-600');
        setTimeout(() => row.remove(), 1500);
      } else {
        row.querySelector('span:last-child').textContent = '失败：' + (data.error || '未知');
        row.querySelector('span:last-child').classList.replace('text-zinc-400','text-red-500');
        setTimeout(() => row.remove(), 3000);
      }
    } catch(e) {
      row.querySelector('span:last-child').textContent = '出错：' + e.message;
      setTimeout(() => row.remove(), 3000);
    }
  }
  this.value = '';
});

// ---- 删除音乐文件 ----
async function deleteMusicFile(btn, filename) {
  if (!confirm('确定删除「' + filename + '」？')) return;
  btn.textContent = '删除中…';
  btn.disabled = true;
  try {
    const fd = new FormData();
    fd.append('action', 'delete_music');
    fd.append('filename', filename);
    const res  = await fetch('files.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      const row = btn.closest('.music-file-row');
      row.remove();
      updateMusicCount(-1);
      // 如果没有文件了，显示空提示
      const fileList = document.getElementById('musicFileList');
      if (!fileList.querySelector('.music-file-row')) {
        const hint = document.createElement('p');
        hint.id = 'musicEmptyHint';
        hint.className = 'text-xs text-zinc-400 italic';
        hint.textContent = '暂无音乐文件';
        fileList.appendChild(hint);
      }
    } else {
      alert('删除失败：' + (data.error || '未知'));
      btn.textContent = '删除';
      btn.disabled = false;
    }
  } catch(e) {
    alert('删除出错：' + e.message);
    btn.textContent = '删除';
    btn.disabled = false;
  }
}
</script>

<?php require '_layout_end.php'; ?>
