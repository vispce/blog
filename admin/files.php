<?php
// =========================================
// admin/files.php — 文件管理
// =========================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
auth_check();

// 提升上传限制（在 php.ini 允许的范围内动态设置）
@ini_set('upload_max_filesize', '512M');
@ini_set('post_max_size',       '520M');
@ini_set('max_execution_time',  '600');
@ini_set('memory_limit',        '512M');

// ---- 修复：确保 uploads 目录存在，防止 realpath() 返回 false 导致 base_dir = "/" ----
$uploads_path = __DIR__ . '/../uploads';
if (!is_dir($uploads_path)) {
    mkdir($uploads_path, 0755, true);
}
$base_dir = realpath($uploads_path);
if (!$base_dir || $base_dir === DIRECTORY_SEPARATOR) {
    die('uploads 目录配置错误，请检查服务器路径设置。');
}
$base_dir .= DIRECTORY_SEPARATOR;

$rel       = trim($_GET['path'] ?? '', '/\\');
// 安全：防止路径穿越
$target    = realpath($base_dir . $rel);
if (!$target || strpos($target . DIRECTORY_SEPARATOR, $base_dir) !== 0) {
    $target = $base_dir;
    $rel    = '';
}
$target .= is_dir($target) ? '' : '';

// ---- 操作处理 ----
$msg = $err = '';

// 删除音乐文件（来自 settings.php 的 AJAX 请求）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_music') {
    header('Content-Type: application/json; charset=utf-8');
    $filename = basename(trim($_POST['filename'] ?? ''));
    if (!$filename) { echo json_encode(['error' => '文件名不能为空']); exit; }
    $path = realpath($base_dir . 'music' . DIRECTORY_SEPARATOR . $filename);
    if (!$path || strpos($path, $base_dir) !== 0 || !is_file($path)) {
        echo json_encode(['error' => '文件不存在']); exit;
    }
    if (unlink($path)) {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['error' => '删除失败']);
    }
    exit;
}

// 上传文件（AJAX）：成功静默返回 200，失败返回 400 + 错误详情
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload'])) {
    $dir    = is_dir($target) ? $target : $base_dir;
    $errors = [];
    foreach ($_FILES['upload']['name'] as $i => $name) {
        $code = $_FILES['upload']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($code !== UPLOAD_ERR_OK) {
            $errors[] = basename($name) . '：' . upload_err_msg($code);
            continue;
        }
        $safe = preg_replace('/[^\w.\-]/u', '_', basename($name));
        $dest = $dir . DIRECTORY_SEPARATOR . $safe;
        // 避免覆盖
        if (file_exists($dest)) {
            $info = pathinfo($safe);
            $safe = $info['filename'] . '_' . time() . '.' . ($info['extension'] ?? 'bin');
            $dest = $dir . DIRECTORY_SEPARATOR . $safe;
        }
        if (!move_uploaded_file($_FILES['upload']['tmp_name'][$i], $dest)) {
            $errors[] = basename($name) . '：保存失败，请检查服务器 uploads 目录写权限';
        }
    }
    if ($errors) {
        http_response_code(400);
        echo implode("\n", $errors);
    } else {
        echo 'OK';
    }
    exit;
}

function upload_err_msg(int $code): string {
    $map = [
        UPLOAD_ERR_INI_SIZE   => '文件超过服务器 upload_max_filesize 限制',
        UPLOAD_ERR_FORM_SIZE  => '文件超过表单大小限制',
        UPLOAD_ERR_PARTIAL    => '文件只上传了一部分（网络中断？）',
        UPLOAD_ERR_NO_FILE    => '未收到文件内容',
        UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录缺失',
        UPLOAD_ERR_CANT_WRITE => '服务器无法写入临时文件',
        UPLOAD_ERR_EXTENSION  => 'PHP 扩展中止了上传',
    ];
    return $map[$code] ?? ('未知错误（code=' . $code . '）');
}

// 新建文件夹（成功静默，失败提示错误）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['mkdir'])) {
    $dir  = is_dir($target) ? $target : $base_dir;
    $name = preg_replace('/[^\w\-]/u', '_', trim($_POST['mkdir']));
    $msg  = '';
    if (!$name) {
        $msg = '创建失败：文件夹名不合法';
    } else {
        $np = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_dir($np)) {
            $msg = '创建失败：文件夹已存在';
        } elseif (!@mkdir($np, 0755)) {
            $msg = '创建失败：无法写入目录（' . $name . '）';
        }
    }
    header('Location: files.php?path=' . urlencode($rel) . '&msg=' . urlencode($msg)); exit;
}

// 删除
if (!empty($_GET['delete'])) {
    $item = realpath($base_dir . trim($_GET['delete'], '/\\'));
    if ($item && strpos($item . DIRECTORY_SEPARATOR, $base_dir) === 0 && $item !== rtrim($base_dir, '/\\')) {
        if (is_dir($item)) {
            // 递归删除
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($item, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) $f->isDir() ? rmdir($f) : unlink($f);
            rmdir($item);
        } else {
            unlink($item);
        }
        $msg = '已删除';
    }
    header('Location: files.php?path=' . urlencode($rel) . '&msg=' . urlencode($msg)); exit;
}

// 重命名
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['rename_path']) && isset($_POST['rename_to'])) {
    $old_rel  = trim($_POST['rename_path'], '/\\');
    $new_name = trim($_POST['rename_to']);
    // 安全校验：新名称不含路径分隔符，不为空
    if ($new_name && !preg_match('/[\/\\\\]/', $new_name)) {
        $old_full = realpath($base_dir . $old_rel);
        if ($old_full && strpos($old_full . DIRECTORY_SEPARATOR, $base_dir) === 0) {
            $dir_part = dirname($old_full);
            $new_full = $dir_part . DIRECTORY_SEPARATOR . $new_name;
            if (!file_exists($new_full)) {
                if (@rename($old_full, $new_full)) {
                    $msg = '重命名成功';
                } else {
                    $err = '重命名失败：' . (error_get_last()['message'] ?? '未知错误');
                }
            } else {
                $err = '目标名称已存在';
            }
        } else {
            $err = '源文件不存在或路径越界（路径：' . htmlspecialchars($old_rel) . '）';
        }
    } else {
        $err = '文件名不合法';
    }
    $redirect_msg = $err ?: $msg;
    $redirect_rel = isset($_POST['current_path']) ? trim($_POST['current_path'], '/\\') : $rel;
    header('Location: files.php?path=' . urlencode($redirect_rel) . '&msg=' . urlencode($redirect_msg)); exit;
}

// 下载
if (!empty($_GET['download'])) {
    $item = realpath($base_dir . trim($_GET['download'], '/\\'));
    if ($item && strpos($item . DIRECTORY_SEPARATOR, $base_dir) === 0 && is_file($item)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode(basename($item)) . '"');
        header('Content-Length: ' . filesize($item));
        readfile($item);
        exit;
    }
}

// ---- 读取目录内容 ----
$items = [];
if (is_dir($target)) {
    foreach (scandir($target) as $name) {
        if ($name === '.' || $name === '..') continue;
        $full   = $target . DIRECTORY_SEPARATOR . $name;
        $is_dir = is_dir($full);
        $sub    = $rel ? $rel . '/' . $name : $name;
        // 计算公开URL（相对于 document_root）
        $doc_root_n    = rtrim(realpath($_SERVER['DOCUMENT_ROOT']), '/\\');
        $proj_root_n   = rtrim(realpath(__DIR__ . '/..'), '/\\');
        $uploads_root  = $proj_root_n . '/uploads';
        $file_full_n   = rtrim(realpath($full) ?: $full, '/\\');
        if (strpos($file_full_n, $doc_root_n) === 0) {
            $pub_url = str_replace('\\', '/', substr($file_full_n, strlen($doc_root_n)));
        } else {
            $pub_url = '/uploads/' . ltrim($sub, '/');
        }
        $items[] = [
            'name'       => $name,
            'is_dir'     => $is_dir,
            'size'       => $is_dir ? null : filesize($full),
            'mtime'      => filemtime($full),
            'path'       => $sub,
            'ext'        => $is_dir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            'public_url' => $is_dir ? '' : $pub_url,
        ];
    }
    usort($items, function($a, $b) { return $b['is_dir'] <=> $a['is_dir'] ?: strcmp($a['name'], $b['name']); });
}

// ---- 面包屑 ----
$breadcrumbs = [['label'=>'uploads','path'=>'']];
if ($rel) {
    $parts = explode('/', $rel);
    $acc   = '';
    foreach ($parts as $p) {
        $acc .= ($acc ? '/' : '') . $p;
        $breadcrumbs[] = ['label'=>$p,'path'=>$acc];
    }
}

/**
 * 移动端文件名缩略：超过 $max 个字符时，保留首 $keep 位 + ✳ + 末 $keep 位 + 扩展名
 * 文件夹名同理但无扩展名部分
 */
function short_name(string $name, int $max = 20, int $keep = 6): string {
    if (mb_strlen($name) <= $max) return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $dot = strrpos($name, '.');
    if ($dot !== false && $dot > 0) {
        $base = mb_substr($name, 0, $dot);
        $ext  = mb_substr($name, $dot); // 含点，如 .zip
    } else {
        $base = $name;
        $ext  = '';
    }
    $short = mb_substr($base, 0, $keep) . '✳' . mb_substr($base, -$keep) . $ext;
    return htmlspecialchars($short, ENT_QUOTES, 'UTF-8');
}

function fmt_size(int $b): string {
    if ($b < 1024)       return $b . ' B';
    if ($b < 1048576)    return round($b/1024,1) . ' KB';
    return round($b/1048576,1) . ' MB';
}

$img_exts = ['jpg','jpeg','png','gif','webp','svg','ico','bmp','tif','tiff','avif','heic'];

// ---- 文件类型辅助函数 ----
function file_type_category(string $ext): string {
    $cats = [
        'vid'  => ['mp4','webm','mov','avi','mkv','flv','m4v','wmv','ogv'],
        'aud'  => ['mp3','wav','ogg','flac','aac','m4a','opus','wma'],
        'pdf'  => ['pdf'],
        'doc'  => ['doc','docx','odt','rtf','txt','md'],
        'xls'  => ['xls','xlsx','ods','csv'],
        'ppt'  => ['ppt','pptx','odp','key'],
        'zip'  => ['zip','rar','7z','tar','gz','bz2','xz','tgz','dmg','iso'],
        'code' => ['js','ts','jsx','tsx','py','php','java','c','cpp','cs','go','rs','rb','sh','bash','html','htm','css','scss','json','xml','yaml','yml','toml','sql','vue','dart','kt','swift'],
        'font' => ['ttf','otf','woff','woff2','eot'],
    ];
    foreach ($cats as $cat => $exts) {
        if (in_array($ext, $exts)) return $cat;
    }
    return 'other';
}
function file_type_bg(string $ext): string {
    $map = [
        'img'=>'#f5f3ff','vid'=>'#fff1f2','aud'=>'#ecfdf5','pdf'=>'#fff7ed',
        'doc'=>'#eff6ff','xls'=>'#f0fdf4','ppt'=>'#fff7ed','zip'=>'#fafafa',
        'code'=>'#fefce8','font'=>'#fdf4ff','other'=>'#f4f4f5',
    ];
    $cat = file_type_category($ext);
    return $map[$cat] ?? '#f4f4f5';
}
function file_type_color(string $ext): string {
    $map = [
        'img'=>'#7c3aed','vid'=>'#dc2626','aud'=>'#059669','pdf'=>'#ea580c',
        'doc'=>'#2563eb','xls'=>'#16a34a','ppt'=>'#ea580c','zip'=>'#525252',
        'code'=>'#ca8a04','font'=>'#a21caf','other'=>'#71717a',
    ];
    $cat = file_type_category($ext);
    return $map[$cat] ?? '#71717a';
}
function file_type_svg(string $ext): string {
    $cat = file_type_category($ext);
    $color = file_type_color($ext);
    $svgs = [
        'vid'  => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.069A1 1 0 0121 8.87v6.26a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>',
        'aud'  => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/></svg>',
        'pdf'  => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
        'doc'  => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
        'xls'  => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M3 14h18M10 3v18M3 3h18v18H3z"/></svg>',
        'ppt'  => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18v12a1 1 0 01-1 1H4a1 1 0 01-1-1V4z"/></svg>',
        'zip'  => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>',
        'code' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>',
        'font' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-3-3v6m-7 3h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>',
        'img'  => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>',
        'other'=> '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>',
    ];
    $svg = $svgs[$cat] ?? $svgs['other'];
    return str_replace('stroke="currentColor"', 'stroke="' . $color . '"', $svg);
}

$page_title = '文件管理';
require '_layout.php';
?>

<?php if (!empty($_GET['msg'])): ?>
<div class="alert-success"><?= htmlspecialchars($_GET['msg']) ?></div>
<?php endif; ?>

<!-- 面包屑 + 操作栏 -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
  <nav class="flex items-center flex-wrap gap-1 text-sm">
    <?php foreach ($breadcrumbs as $i => $bc): ?>
      <?php if ($i < count($breadcrumbs)-1): ?>
        <a href="files.php?path=<?= urlencode($bc['path']) ?>" class="text-zinc-400 hover:text-zinc-900 transition"><?= htmlspecialchars($bc['label']) ?></a>
        <span class="text-zinc-300">/</span>
      <?php else: ?>
        <span class="text-zinc-800 font-medium"><?= htmlspecialchars($bc['label']) ?></span>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <div class="flex gap-2 flex-wrap">
    <!-- 新建文件夹 -->
    <form method="POST" class="flex gap-1">
      <input type="text" name="mkdir" placeholder="新建文件夹" class="field text-xs py-2 w-32">
      <button type="submit" class="btn-outline text-xs py-2 px-3">创建</button>
    </form>
    <!-- 上传 -->
    <label class="btn-primary cursor-pointer text-xs" id="uploadLabel">
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
      上传文件
      <input type="file" name="upload[]" multiple class="hidden" id="fileUploadInput">
    </label>
  </div>
</div>

<!-- 拖放区 -->
<div id="fileDropzone"
     class="mb-4 border-2 border-dashed border-zinc-200 bg-zinc-50 rounded text-center py-8 transition-colors"
     ondragover="event.preventDefault();this.classList.add('border-zinc-500','bg-zinc-100')"
     ondragleave="this.classList.remove('border-zinc-500','bg-zinc-100')"
     ondrop="handleDrop(event)">
  <svg class="w-7 h-7 mx-auto text-zinc-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
  </svg>
  <p class="text-xs text-zinc-400">拖拽文件到此处，或点击上方「上传文件」</p>
</div>

<!-- 上传进度区 -->
<div id="uploadProgressArea" class="hidden mb-4 bg-white border border-zinc-200 p-4 space-y-3">
  <div class="flex items-center justify-between">
    <span class="text-[10px] text-zinc-400 tracking-widest uppercase">上传进度</span>
    <span id="uploadStatusText" class="text-[11px] text-zinc-500"></span>
  </div>
  <div class="w-full bg-zinc-100 h-1.5 overflow-hidden">
    <div id="uploadTotalBar" class="h-full bg-zinc-900 transition-all duration-150" style="width:0%"></div>
  </div>
  <div id="uploadFileList" class="space-y-2 max-h-52 overflow-y-auto pt-1"></div>
</div>

<!-- 文件列表 -->
<div class="bg-white border border-zinc-200 overflow-hidden">
  <?php if (empty($items)): ?>
    <p class="text-zinc-400 text-sm text-center py-16">文件夹为空</p>
  <?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th class="pl-4 w-8"></th>
        <th>名称</th>
        <th class="hidden sm:table-cell">大小</th>
        <th class="hidden md:table-cell">修改时间</th>
        <th class="text-right pr-4">操作</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <!-- 图标 -->
        <td class="pl-3 w-10">
          <?php if ($item['is_dir']): ?>
            <span class="flex items-center justify-center w-9 h-9 rounded bg-zinc-50">
              <svg class="w-5 h-5 text-zinc-400" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>
            </span>
          <?php elseif (in_array($item['ext'], $img_exts)): ?>
            <span class="relative inline-block w-9 h-9 flex-shrink-0">
              <img src="../uploads/<?= htmlspecialchars($item['path']) ?>"
                   class="w-9 h-9 object-cover rounded admin-thumb"
                   data-fallback-bg="<?= htmlspecialchars(file_type_bg($item['ext'])) ?>"
                   data-fallback-svg="<?= base64_encode(file_type_svg($item['ext'])) ?>"
                   onerror="adminThumbFallback(this)">
              <span class="absolute bottom-0.5 right-0.5 bg-black/40 rounded-sm p-px flex items-center pointer-events-none">
                <svg class="w-2 h-2 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01"/></svg>
              </span>
            </span>
          <?php else: ?>
            <span class="flex items-center justify-center w-9 h-9 rounded" style="background:<?= file_type_bg($item['ext']) ?>">
              <?= file_type_svg($item['ext']) ?>
            </span>
          <?php endif; ?>
        </td>
        <!-- 名称 -->
        <td class="max-w-0 w-full">
          <?php if ($item['is_dir']): ?>
            <a href="files.php?path=<?= urlencode($item['path']) ?>" class="font-medium text-zinc-800 hover:text-zinc-500 transition block truncate">
              <span class="hidden sm:inline"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></span>
              <span class="sm:hidden"><?= short_name($item['name']) ?></span>
            </a>
          <?php else: ?>
            <span class="text-zinc-700 block truncate">
              <span class="hidden sm:inline"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></span>
              <span class="sm:hidden"><?= short_name($item['name']) ?></span>
            </span>
          <?php endif; ?>
        </td>
        <!-- 大小 -->
        <td class="hidden sm:table-cell text-zinc-400 text-xs">
          <?= $item['is_dir'] ? '—' : fmt_size($item['size']) ?>
        </td>
        <!-- 时间 -->
        <td class="hidden md:table-cell text-zinc-400 text-xs">
          <?= date('Y.m.d H:i', $item['mtime']) ?>
        </td>
        <!-- 操作 -->
        <td class="pr-3 sm:pr-4 w-px">
          <div class="flex items-center justify-end gap-1 sm:gap-3 flex-nowrap">
            <!-- 重命名：移动端显示图标，桌面端显示文字 -->
            <button onclick="openRename(this)"
                    data-path="<?= htmlspecialchars($item['path'], ENT_QUOTES, 'UTF-8') ?>"
                    data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>"
                    title="重命名"
                    class="text-zinc-400 hover:text-zinc-800 transition">
              <!-- 移动端图标 -->
              <svg class="w-4 h-4 sm:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
              <!-- 桌面端文字 -->
              <span class="hidden sm:inline text-[11px] whitespace-nowrap">重命名</span>
            </button>
            <?php if (!$item['is_dir']): ?>
            <!-- 下载：移动端图标，桌面端文字 -->
            <a href="files.php?download=<?= urlencode($item['path']) ?>"
               title="下载"
               class="text-zinc-400 hover:text-zinc-800 transition">
              <svg class="w-4 h-4 sm:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
              <span class="hidden sm:inline text-[11px] whitespace-nowrap">下载</span>
            </a>
            <?php endif; ?>
            <!-- 删除：移动端图标，桌面端文字 -->
            <a href="files.php?path=<?= urlencode($rel) ?>&delete=<?= urlencode($item['path']) ?>"
               onclick="return confirmDelete(this)"
               data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>"
               data-isdir="<?= $item['is_dir'] ? '1' : '0' ?>"
               title="删除"
               class="text-red-400 hover:text-red-600 transition">
              <svg class="w-4 h-4 sm:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              <span class="hidden sm:inline text-[11px] whitespace-nowrap">删除</span>
            </a>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- 重命名弹层 -->
<div id="renameModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 hidden" onclick="if(event.target===this)closeRename()">
  <div class="bg-white shadow-xl w-[92vw] max-w-sm p-6 space-y-4">
    <h3 class="text-sm font-medium text-zinc-800 tracking-wide">重命名</h3>
    <div class="space-y-1">
      <p class="text-[11px] text-zinc-400 truncate" id="renameOldDisplay"></p>
      <input id="renameInput" type="text"
             class="field w-full text-sm py-2"
             placeholder="新文件名"
             onkeydown="if(event.key==='Enter')submitRename();if(event.key==='Escape')closeRename()">
    </div>
    <div class="flex justify-end gap-2 pt-1">
      <button onclick="closeRename()" class="btn-outline text-xs py-1.5 px-4">取消</button>
      <button onclick="submitRename()" class="btn-primary text-xs py-1.5 px-4">确认</button>
    </div>
  </div>
</div>
<!-- 隐藏表单，用于提交重命名 -->
<form id="renameForm" method="POST" action="files.php" class="hidden">
  <input type="hidden" name="current_path" id="renameCurrentPath" value="<?= htmlspecialchars($rel, ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="rename_path" id="renamePathInput">
  <input type="hidden" name="rename_to"   id="renameToInput">
</form>

<script>
const currentPath = '<?= addslashes(urlencode($rel)) ?>';

// ---- 删除确认 ----
function confirmDelete(el) {
  const name  = el.dataset.name;
  const isDir = el.dataset.isdir === '1';
  let msg = '确认删除「' + name + '」？';
  if (isDir) msg += '\n文件夹内所有文件将被删除！';
  return confirm(msg);
}

// ---- 重命名 ----
let _renamePath = '';
function openRename(btn) {
  const filePath = btn.dataset.path;
  const fileName = btn.dataset.name;
  _renamePath = filePath;
  document.getElementById('renameOldDisplay').textContent = '当前名称：' + fileName;
  const input = document.getElementById('renameInput');
  input.value = fileName;
  document.getElementById('renameModal').classList.remove('hidden');
  // 自动选中文件名（不含扩展名）
  requestAnimationFrame(() => {
    input.focus();
    const dot = fileName.lastIndexOf('.');
    if (dot > 0) {
      input.setSelectionRange(0, dot);
    } else {
      input.select();
    }
  });
}
function closeRename() {
  document.getElementById('renameModal').classList.add('hidden');
  _renamePath = '';
}
function submitRename() {
  const newName = document.getElementById('renameInput').value.trim();
  if (!newName) return;
  document.getElementById('renamePathInput').value = _renamePath;
  document.getElementById('renameToInput').value   = newName;
  document.getElementById('renameForm').submit();
}

// 文件选择触发上传
document.getElementById('fileUploadInput').addEventListener('change', function() {
  if (this.files.length) startUpload(Array.from(this.files));
});

// 拖拽上传
function handleDrop(e) {
  e.preventDefault();
  document.getElementById('fileDropzone').classList.remove('border-zinc-500','bg-zinc-100');
  const files = Array.from(e.dataTransfer.files);
  if (files.length) startUpload(files);
}

// ---- 带进度的上传 ----
function startUpload(files) {
  const area     = document.getElementById('uploadProgressArea');
  const totalBar = document.getElementById('uploadTotalBar');
  const statusTx = document.getElementById('uploadStatusText');
  const fileList = document.getElementById('uploadFileList');

  area.classList.remove('hidden');
  fileList.innerHTML = '';
  totalBar.style.width = '0%';

  const rows = files.map((file, i) => {
    const id  = 'fp_' + i;
    const row = document.createElement('div');
    row.className = 'space-y-1';
    row.innerHTML = `
      <div class="flex justify-between text-[11px]">
        <span class="text-zinc-600 truncate max-w-[70%]">${escHtml(file.name)}</span>
        <span id="${id}_pct" class="text-zinc-400 flex-shrink-0 ml-2">0%</span>
      </div>
      <div class="w-full bg-zinc-100 h-1 overflow-hidden">
        <div id="${id}_bar" class="h-full bg-zinc-400 transition-all duration-100" style="width:0%"></div>
      </div>`;
    fileList.appendChild(row);
    return { file, id, row };
  });

  let doneCount = 0;
  const totalSize = files.reduce((s, f) => s + f.size, 0);
  const loadedMap = new Map(files.map(f => [f.name + f.size, 0]));
  statusTx.textContent = `0 / ${files.length} 个文件`;

  let chain = Promise.resolve();
  rows.forEach(({ file, id, row }) => {
    const fileKey = file.name + file.size;
    chain = chain.then(() => uploadOne(file, id, (loaded) => {
      const pct = Math.round(loaded / file.size * 100);
      document.getElementById(id + '_pct').textContent = pct + '%';
      document.getElementById(id + '_bar').style.width  = pct + '%';
      loadedMap.set(fileKey, loaded);
      const totalLoaded = Array.from(loadedMap.values()).reduce((a, b) => a + b, 0);
      const totalPct = totalSize > 0 ? Math.min(99, Math.round(totalLoaded / totalSize * 100)) : 0;
      totalBar.style.width = totalPct + '%';
    })).then(() => {
      doneCount++;
      document.getElementById(id + '_bar').style.background = '#18181b';
      document.getElementById(id + '_pct').textContent = '完成';
      statusTx.textContent = `${doneCount} / ${files.length} 个文件`;
      if (doneCount === files.length) {
        totalBar.style.width = '100%';
        statusTx.textContent = `全部完成，刷新中…`;
        setTimeout(() => location.reload(), 600);
      }
    }).catch((e) => {
      document.getElementById(id + '_bar').style.background = '#ef4444';
      document.getElementById(id + '_pct').textContent = '失败';
      const errEl = document.createElement('div');
      errEl.className = 'text-[11px] text-red-500';
      errEl.textContent = (e && e.message) ? e.message : '上传失败';
      row.appendChild(errEl);
    });
  });
}

function uploadOne(file, id, onProgress) {
  return new Promise((resolve, reject) => {
    const fd = new FormData();
    fd.append('upload[]', file);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'files.php?path=' + currentPath);
    xhr.upload.addEventListener('progress', e => {
      if (e.lengthComputable) onProgress(e.loaded);
    });
    xhr.addEventListener('load', () => {
      if (xhr.status >= 200 && xhr.status < 400) {
        onProgress(file.size);
        resolve();
      } else {
        reject(new Error((xhr.responseText || '').trim() || ('上传失败 (HTTP ' + xhr.status + ')')));
      }
    });
    xhr.addEventListener('error', () => reject(new Error('网络错误')));
    xhr.send(fd);
  });
}

function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<script>
function adminThumbFallback(img) {
    var bg  = img.dataset.fallbackBg  || '#f4f4f5';
    var svg = img.dataset.fallbackSvg ? atob(img.dataset.fallbackSvg) : '';
    var wrap = img.parentNode;
    wrap.style.background = bg;
    wrap.style.borderRadius = '0.25rem';
    wrap.style.display = 'flex';
    wrap.style.alignItems = 'center';
    wrap.style.justifyContent = 'center';
    img.style.display = 'none';
    // remove badge too
    var badge = wrap.querySelector('.pointer-events-none');
    if (badge) badge.style.display = 'none';
    if (svg) {
        var tmp = document.createElement('span');
        tmp.innerHTML = svg;
        wrap.appendChild(tmp.firstElementChild);
    }
}
</script>

<?php require '_layout_end.php'; ?>
