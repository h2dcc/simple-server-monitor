<?php
/*
 * ==========================================
 * 休闲探针 - 展示面板
 * 版本：1.0
 * 作者：Hyruo
 * 说明：本文件为服务器状态展示页，包含实时数据刷新、历史延迟图、自定义标签、流量进度条等。
 * 所有可自定义的配置集中在文件头部的“用户配置区”。
 * ==========================================
 */

date_default_timezone_set('Asia/Shanghai');

// ---------- 数据库路径 ----------
$DB_FILE = __DIR__ . "/data/status_v2.db";
if (!is_dir(__DIR__ . "/data")) {
    mkdir(__DIR__ . "/data", 0755, true);
}
$db = new SQLite3($DB_FILE);

// ---------- 初始化表结构（防止首次使用报错） ----------
$db->exec("CREATE TABLE IF NOT EXISTS node_status (
    token_md5 TEXT PRIMARY KEY,
    name TEXT,
    cpu REAL,
    mem REAL,
    disk REAL,
    last_rx INTEGER,
    last_tx INTEGER,
    ping_mobile INTEGER,
    ping_unicom INTEGER,
    ping_telecom INTEGER,
    ping_att INTEGER,
    updated_at INTEGER
)");

// 兼容旧表自动添加新列
$existing = [];
$res = $db->query("PRAGMA table_info(node_status)");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $existing[] = $row['name'];
}
if (!in_array('cpu_cores', $existing)) {
    $db->exec("ALTER TABLE node_status ADD COLUMN cpu_cores INTEGER DEFAULT 0");
}
if (!in_array('mem_total_mb', $existing)) {
    $db->exec("ALTER TABLE node_status ADD COLUMN mem_total_mb INTEGER DEFAULT 0");
}
if (!in_array('disk_total_gb', $existing)) {
    $db->exec("ALTER TABLE node_status ADD COLUMN disk_total_gb INTEGER DEFAULT 0");
}
if (!in_array('os_info', $existing)) {
    $db->exec("ALTER TABLE node_status ADD COLUMN os_info TEXT DEFAULT ''");
}
if (!in_array('uptime_seconds', $existing)) {
    $db->exec("ALTER TABLE node_status ADD COLUMN uptime_seconds INTEGER DEFAULT 0");
}

$db->exec("CREATE TABLE IF NOT EXISTS traffic_monthly (
    token_md5 TEXT,
    month_str TEXT,
    rx_total INTEGER DEFAULT 0,
    tx_total INTEGER DEFAULT 0,
    PRIMARY KEY (token_md5, month_str)
)");
$db->exec("CREATE TABLE IF NOT EXISTS node_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_md5 TEXT NOT NULL,
    recorded_at INTEGER NOT NULL,
    cpu REAL, mem REAL, disk REAL,
    ping_mobile INTEGER, ping_unicom INTEGER,
    ping_telecom INTEGER, ping_att INTEGER,
    rx_delta INTEGER DEFAULT 0,
    tx_delta INTEGER DEFAULT 0
)");

/*
 * ==========================================
 *  👤 用户配置区（请根据你的服务器信息修改）
 * ==========================================
 */

// ---------- 1. 节点定义 ----------
// 格式： '客户端 token 的 MD5 值（小写）' => '显示名称'
// 如何获取 MD5：Linux 下执行 echo -n "你的token原文" | md5sum
$TOKENS = [
    "请替换为你的token_md5_1" => "Oracle ARM Singapore",
    "请替换为你的token_md5_2" => "Oracle ARM Hyderabad",
    "请替换为你的token_md5_3" => "Oracle AMD Hyderabad",
    "请替换为你的token_md5_4" => "Oracle AMD Sanjose",
    "请替换为你的token_md5_5" => "HomeCloud"
];

// ---------- 2. 流量配额（单位：GB） ----------
$TRAFFIC_LIMIT = [
    "Oracle ARM Singapore" => 1000,
    "Oracle ARM Hyderabad" => 1000,
    "Oracle AMD Hyderabad" => 1000,
    "Oracle AMD Sanjose"  => 1000,
    "HomeCloud"           => 1000,
];

// 流量统计模式：'both' = 双向 (RX+TX), 'rx' = 仅入站, 'tx' = 仅出站
$TRAFFIC_MODE = 'both';

// ---------- 3. 自定义标签（每个节点最多5个，建议字符简短） ----------
$CUSTOM_TAGS = [
    "Oracle ARM Singapore" => ["🇸🇬 新加坡", "ARM 2C"],
    "Oracle ARM Hyderabad" => ["🇮🇳 印度", "ARM 2C"],
    "Oracle AMD Hyderabad" => ["🇮🇳 印度", "AMD 1C"],
    "Oracle AMD Sanjose"  => ["🇺🇸 美西", "AMD 1C"],
    "HomeCloud"           => ["🏠 家庭", "CN2 主力"],
];

// ---------- 4. 到期时间（格式：YYYY-MM-DD，留空则不显示） ----------
$EXPIRE_DATES = [
    "Oracle ARM Singapore" => "",
    "Oracle ARM Hyderabad" => "",
    "Oracle AMD Hyderabad" => "",
    "Oracle AMD Sanjose"  => "",
    "HomeCloud"           => "",
];

// ---------- 5. 其他文本标签（如价格、带宽等） ----------
$OTHER_TAGS = [
    "Oracle ARM Singapore" => ["$5/mo", "1000Mbps"],
    "Oracle ARM Hyderabad" => ["$5/mo"],
    "Oracle AMD Hyderabad" => ["$5/mo"],
    "Oracle AMD Sanjose"  => ["$8/mo", "50TB"],
    "HomeCloud"           => ["$80/yr", "500Mbps"],
];

/*
 * ==========================================
 *  以下为程序逻辑，如无特殊需求无需修改
 * ==========================================
 */

// 国旗对应的国家代码（用于 flag-icon-css）
function get_flag_class($name) {
    $map = [
        "Oracle ARM Singapore" => "sg",
        "Oracle ARM Hyderabad" => "in",
        "Oracle AMD Hyderabad" => "in",
        "Oracle AMD Sanjose"  => "us",
        "HomeCloud"           => "cn"
    ];
    return $map[$name] ?? "";
}

// 缩短系统名称
function short_os($full_os) {
    if (stripos($full_os, 'Ubuntu') !== false) return 'Ubuntu';
    if (stripos($full_os, 'Debian') !== false) return 'Debian';
    return $full_os;
}

// 格式化字节
function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// 格式化内存总量
function format_mem($mb) {
    if ($mb >= 1024) return round($mb/1024, 1) . 'GB';
    return $mb . 'MB';
}

// 在线时长简写
function format_uptime_short($seconds) {
    $days = floor($seconds / 86400);
    if ($days >= 1) return $days . '天';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    return $hours . '小时 ' . $minutes . '分钟';
}

// 到期倒计时
function days_until($date_str) {
    if (empty($date_str)) return '';
    $target = strtotime($date_str);
    if ($target === false) return '';
    $diff = $target - time();
    $days = floor($diff / 86400);
    if ($days < 0) return '已过期';
    if ($days == 0) return '今日到期';
    return '剩' . $days . '天';
}

// ---------- AJAX 实时数据接口（替代 data.php） ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'realtime') {
    $result = [];
    $total_rx = 0; $total_tx = 0;
    $current_month = date('Y-m');

    foreach ($TOKENS as $token_md5 => $name) {
        $stmt = $db->prepare("SELECT * FROM node_status WHERE token_md5 = :token_md5");
        $stmt->bindValue(':token_md5', $token_md5, SQLITE3_TEXT);
        $node = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$node) {
            $result[] = ['token_md5' => $token_md5, 'online' => false, 'name' => $name];
            continue;
        }
        $is_online = (time() - $node['updated_at']) < 300; // 5分钟无数据判定离线
        $t_stmt = $db->prepare("SELECT rx_total, tx_total FROM traffic_monthly WHERE token_md5=:t AND month_str=:m");
        $t_stmt->bindValue(':t', $token_md5, SQLITE3_TEXT);
        $t_stmt->bindValue(':m', $current_month, SQLITE3_TEXT);
        $traffic = $t_stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $rx = $traffic['rx_total'] ?? 0;
        $tx = $traffic['tx_total'] ?? 0;
        $total_rx += $rx; $total_tx += $tx;

        $result[] = [
            'token_md5' => $token_md5,
            'online' => $is_online,
            'cpu' => (float)$node['cpu'],
            'mem' => (float)$node['mem'],
            'disk' => (float)$node['disk'],
            'cpu_cores' => (int)$node['cpu_cores'],
            'mem_total_mb' => (int)$node['mem_total_mb'],
            'disk_total_gb' => (int)$node['disk_total_gb'],
            'os_info' => $node['os_info'] ?? '',
            'rx_total' => $rx,
            'tx_total' => $tx,
            'uptime_seconds' => (int)$node['uptime_seconds']
        ];
    }
    header('Content-Type: application/json');
    echo json_encode([
        'nodes' => $result,
        'total_rx' => $total_rx,
        'total_tx' => $total_tx,
        'current_time' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ---------- 页面 HTML 开始 ----------
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>休闲探针</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- 国旗图标库（已切换至 jsdelivr CDN） -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flag-icon-css@3.5.0/css/flag-icon.min.css">
    <style>
        * { box-sizing: border-box; }
        body { background:#111827; color:#fff; font-family:'Segoe UI',Arial,sans-serif; margin:0; padding-bottom:60px; }
        .header { padding:20px; background:#1f2937; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); }
        .container { max-width:1200px; margin:0 auto; padding:15px; width:100%; }
        .header-inner { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; }
        .total-traffic { font-size:14px; background:#111827; padding:10px 15px; border-radius:8px; display:flex; flex-direction:column; gap:6px; align-items:flex-end; }
        .total-traffic .time-line { color:#d1d5db; }
        .total-traffic .traffic-row { display:flex; gap:15px; }
        .total-traffic .rx { color:#34d399; }
        .total-traffic .tx { color:#60a5fa; }

        .cards-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
        @media (max-width:800px) {
            .cards-grid { grid-template-columns:1fr; }
            .header-inner { flex-direction:column; text-align:center; }
            .total-traffic { align-items:center; }
        }

        .card { background:#1f2937; border-radius:12px; padding:20px; border-left:5px solid #22c55e; transition:all 0.3s; }
        .card.node-offline { border-left-color:#ef4444; opacity:0.55; filter:grayscale(30%); }
        .node-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
        .node-title { margin:0; font-size:16px; font-weight:600; display:flex; align-items:center; gap:8px; }
        .flag-icon { width:1.2em; height:1em; display:inline-block; vertical-align:middle; }
        .status-badge { font-weight:bold; font-size:14px; }
        .online { color:#22c55e; }
        .offline { color:#ef4444; }

        .card-body { display:flex; flex-direction:column; gap:15px; }
        .progress-item { display:flex; align-items:center; gap:10px; }
        .progress-label { width:50px; font-size:14px; color:#9ca3af; }
        .progress-bar-container { flex:1; background:#374151; border-radius:4px; height:20px; overflow:hidden; position:relative; }
        .progress-bar { height:100%; border-radius:4px; transition:width 0.5s; }
        .progress-bar.cpu { background:#60a5fa; }
        .progress-bar.mem { background:#34d399; }
        .progress-bar.disk { background:#fbbf24; }
        .progress-bar.traffic { background:#a78bfa; }
        .progress-text { position:absolute; top:0; right:8px; height:100%; display:flex; align-items:center; font-size:12px; color:#fff; }
        .progress-total { width:80px; font-size:13px; color:#d1d5db; text-align:right; }

        .ping-chart-box { background:#111827; border-radius:8px; padding:12px; }
        .ping-chart-box canvas { width:100% !important; height:160px !important; }

        .info-row { display:flex; justify-content:space-between; align-items:center; background:#111827; border-radius:8px; padding:10px 15px; font-size:13px; color:#d1d5db; flex-wrap:nowrap; gap:10px; }
        .info-item { display:flex; align-items:center; gap:5px; white-space:nowrap; }
        .info-item.os-item { min-width:0; overflow:hidden; text-overflow:ellipsis; flex-shrink:1; }
        .info-item.uptime-item, .info-item.traffic-item { flex-shrink:0; }
        .traffic-item .rx { color:#34d399; }
        .traffic-item .tx { color:#60a5fa; }

        /* 自定义标签 */
        .tags-row { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
        .tag { font-size:11px; background:#374151; color:#d1d5db; padding:2px 8px; border-radius:10px; white-space:nowrap; }
        .tag.expire { background:#b91c1c; color:#fff; }

        .offline-placeholder { text-align:center; padding:25px; color:#9ca3af; font-size:15px; }
        footer { text-align:center; padding:20px; color:#6b7280; font-size:13px; }
    </style>
</head>
<body>

<div class="header">
    <div class="container header-inner">
        <div>
            <h1 style="margin:0; font-size:28px;"><i class="fas fa-server"></i> 休闲探针</h1>
        </div>
        <div class="total-traffic" id="total-traffic">
            <?php
                // 计算头部总流量
                $total_rx = 0; $total_tx = 0;
                $current_month = date('Y-m');
                foreach ($TOKENS as $token_md5 => $name) {
                    $t = $db->querySingle("SELECT rx_total, tx_total FROM traffic_monthly WHERE token_md5='$token_md5' AND month_str='$current_month'", true);
                    if ($t) {
                        $total_rx += $t['rx_total'] ?? 0;
                        $total_tx += $t['tx_total'] ?? 0;
                    }
                }
            ?>
            <span class="time-line"><i class="far fa-clock"></i> <span id="current-time"><?php echo date('Y-m-d H:i:s'); ?></span> CST</span>
            <div class="traffic-row">
                <span class="rx"><i class="fas fa-download"></i> <span id="rx-value"><?php echo format_bytes($total_rx); ?></span></span>
                <span class="tx"><i class="fas fa-upload"></i> <span id="tx-value"><?php echo format_bytes($total_tx); ?></span></span>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <div class="cards-grid" id="cards-container">
<?php
$history_scripts = []; // 收集各节点的历史数据用于 JS

foreach ($TOKENS as $token_md5 => $name) {
    // 获取最新状态
    $stmt = $db->prepare("SELECT * FROM node_status WHERE token_md5 = :token_md5");
    $stmt->bindValue(':token_md5', $token_md5, SQLITE3_TEXT);
    $node = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$node) {
        echo "<div class='card node-offline' data-token='{$token_md5}'>
                <div class='node-header'>
                    <h2 class='node-title'><i class='fas fa-server'></i> <span class='node-name'>{$name}</span> <span class='flag-icon flag-icon-".get_flag_class($name)."'></span></h2>
                    <span class='status-badge offline'><i class='fas fa-circle'></i> 未初始化</span>
                </div>
              </div>";
        continue;
    }

    $is_online = (time() - $node['updated_at']) < 300; // 5分钟离线判定
    $card_class = $is_online ? "card" : "card node-offline";
    $status_text = $is_online ? "ONLINE" : "OFFLINE";
    $status_icon = $is_online ? "fa-circle online" : "fa-circle offline";
    $status_class = $is_online ? "status-badge online" : "status-badge offline";

    $cpu_cores = intval($node['cpu_cores'] ?? 0);
    $mem_total_mb = intval($node['mem_total_mb'] ?? 0);
    $disk_total_gb = intval($node['disk_total_gb'] ?? 0);
    $os_info = $node['os_info'] ?? 'Unknown';
    $uptime_seconds = intval($node['uptime_seconds'] ?? 0);

    // 读取当月流量
    $current_month = date('Y-m');
    $t_stmt = $db->prepare("SELECT rx_total, tx_total FROM traffic_monthly WHERE token_md5 = :t AND month_str = :m");
    $t_stmt->bindValue(':t', $token_md5, SQLITE3_TEXT);
    $t_stmt->bindValue(':m', $current_month, SQLITE3_TEXT);
    $traffic = $t_stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $rx_total = $traffic['rx_total'] ?? 0;
    $tx_total = $traffic['tx_total'] ?? 0;

    // 流量进度条数据
    $traffic_limit_gb = isset($TRAFFIC_LIMIT[$name]) ? $TRAFFIC_LIMIT[$name] : 1000;
    if ($TRAFFIC_MODE == 'both') {
        $used_traffic_bytes = $rx_total + $tx_total;
    } elseif ($TRAFFIC_MODE == 'rx') {
        $used_traffic_bytes = $rx_total;
    } else {
        $used_traffic_bytes = $tx_total;
    }
    $traffic_percent = ($traffic_limit_gb > 0) ? min(100, round(($used_traffic_bytes / ($traffic_limit_gb * 1073741824)) * 100, 1)) : 0;

    // 收集标签
    $tags = [];
    if (isset($CUSTOM_TAGS[$name]) && is_array($CUSTOM_TAGS[$name])) {
        $tags = $CUSTOM_TAGS[$name];
    }
    if (!empty($EXPIRE_DATES[$name])) {
        $remaining = days_until($EXPIRE_DATES[$name]);
        if (!empty($remaining)) $tags[] = $remaining;
    }
    if (isset($OTHER_TAGS[$name]) && is_array($OTHER_TAGS[$name])) {
        $tags = array_merge($tags, $OTHER_TAGS[$name]);
    }

    // 查询历史 ping 数据（自动按实际时长显示）
    $earliest = $db->querySingle("SELECT MIN(recorded_at) FROM node_history WHERE token_md5 = '$token_md5'");
    $now = time();
    $threshold = ($earliest && ($now - $earliest) > 86400) ? $now - 86400 : 0;

    $hist_stmt = $db->prepare("SELECT recorded_at, ping_mobile, ping_unicom, ping_telecom, ping_att
                               FROM node_history
                               WHERE token_md5 = :token_md5 AND recorded_at >= :threshold
                               ORDER BY recorded_at ASC");
    $hist_stmt->bindValue(':token_md5', $token_md5, SQLITE3_TEXT);
    $hist_stmt->bindValue(':threshold', $threshold, SQLITE3_INTEGER);
    $hist_res = $hist_stmt->execute();

    $history_rows = [];
    while ($row = $hist_res->fetchArray(SQLITE3_ASSOC)) {
        $history_rows[] = [
            't' => (int)$row['recorded_at'],
            'ping_mobile' => (int)$row['ping_mobile'],
            'ping_unicom' => (int)$row['ping_unicom'],
            'ping_telecom' => (int)$row['ping_telecom'],
            'ping_att' => (int)$row['ping_att'],
        ];
    }

    $history_json = json_encode($history_rows, JSON_UNESCAPED_UNICODE);
    $history_scripts[] = "window['hist_{$token_md5}'] = {$history_json};";
?>
    <div class="<?php echo $card_class; ?>" id="card-<?php echo $token_md5; ?>" data-token="<?php echo $token_md5; ?>">
        <div class="node-header">
            <h2 class="node-title">
                <i class="fas fa-server"></i> <span class="node-name"><?php echo htmlspecialchars($node['name']); ?></span>
                <span class="flag-icon flag-icon-<?php echo get_flag_class($name); ?>"></span>
            </h2>
            <span class="<?php echo $status_class; ?> status-indicator">
                <i class="fas <?php echo $status_icon; ?>"></i> <span class="status-text"><?php echo $status_text; ?></span>
            </span>
        </div>

        <?php if ($is_online): ?>
        <div class="card-body">
            <!-- CPU 进度条 -->
            <div class="progress-item">
                <div class="progress-label"><i class="fas fa-microchip"></i> CPU</div>
                <div class="progress-bar-container">
                    <div class="progress-bar cpu" style="width:<?php echo $node['cpu']; ?>%"></div>
                    <div class="progress-text cpu-value"><?php echo $node['cpu']; ?>%</div>
                </div>
                <div class="progress-total"><?php echo $cpu_cores; ?>C</div>
            </div>
            <!-- 内存进度条 -->
            <div class="progress-item">
                <div class="progress-label"><i class="fas fa-memory"></i> RAM</div>
                <div class="progress-bar-container">
                    <div class="progress-bar mem" style="width:<?php echo $node['mem']; ?>%"></div>
                    <div class="progress-text mem-value"><?php echo $node['mem']; ?>%</div>
                </div>
                <div class="progress-total"><?php echo format_mem($mem_total_mb); ?></div>
            </div>
            <!-- 磁盘进度条 -->
            <div class="progress-item">
                <div class="progress-label"><i class="fas fa-hdd"></i> Disk</div>
                <div class="progress-bar-container">
                    <div class="progress-bar disk" style="width:<?php echo $node['disk']; ?>%"></div>
                    <div class="progress-text disk-value"><?php echo $node['disk']; ?>%</div>
                </div>
                <div class="progress-total"><?php echo $disk_total_gb; ?>GB</div>
            </div>
            <!-- 流量进度条 -->
            <div class="progress-item">
                <div class="progress-label"><i class="fas fa-chart-bar"></i> 流量</div>
                <div class="progress-bar-container">
                    <div class="progress-bar traffic" style="width:<?php echo $traffic_percent; ?>%"></div>
                    <div class="progress-text traffic-value"><?php echo format_bytes($used_traffic_bytes); ?></div>
                </div>
                <div class="progress-total"><?php echo $traffic_limit_gb; ?>GB</div>
            </div>

            <!-- Ping 历史图表 -->
            <div class="ping-chart-box">
                <canvas id="ping_hist_<?php echo $token_md5; ?>"></canvas>
            </div>

            <!-- 信息行：系统、在线时长、月流量 -->
            <div class="info-row">
                <div class="info-item os-item">
                    <span class="icon"><i class="fas fa-laptop"></i></span>
                    <span class="os-text"><?php echo short_os($os_info); ?></span>
                </div>
                <div class="info-item uptime-item">
                    <span class="icon"><i class="fas fa-clock"></i></span>
                    <span class="uptime-text"><?php echo format_uptime_short($uptime_seconds); ?></span>
                </div>
                <div class="info-item traffic-item">
                    <span class="rx"><i class="fas fa-download"></i> <?php echo format_bytes($rx_total, 0); ?></span>
                    <span class="tx">| <i class="fas fa-upload"></i> <?php echo format_bytes($tx_total, 0); ?></span>
                </div>
            </div>

            <?php if (!empty($tags)): ?>
            <div class="tags-row">
                <?php foreach ($tags as $tag): ?>
                    <?php
                        $tag_class = 'tag';
                        if (strpos($tag, '剩') === 0 || strpos($tag, '今日到期') !== false || strpos($tag, '已过期') !== false) {
                            $tag_class = 'tag expire';
                        }
                    ?>
                    <span class="<?php echo $tag_class; ?>"><?php echo htmlspecialchars($tag); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="offline-placeholder">
            <i class="fas fa-exclamation-triangle fa-2x" style="color:#ef4444;"></i>
            <p>节点已离线，暂无实时数据</p>
            <!-- 离线也显示部分信息 -->
            <div class="info-row" style="margin-top:15px;">
                <div class="info-item os-item">
                    <span class="icon"><i class="fas fa-laptop"></i></span>
                    <span class="os-text"><?php echo short_os($os_info); ?></span>
                </div>
                <div class="info-item uptime-item">
                    <span class="icon"><i class="fas fa-clock"></i></span>
                    <span class="uptime-text"><?php echo format_uptime_short($uptime_seconds); ?></span>
                </div>
                <div class="info-item traffic-item">
                    <span class="rx"><i class="fas fa-download"></i> <?php echo format_bytes($rx_total, 0); ?></span>
                    <span class="tx">| <i class="fas fa-upload"></i> <?php echo format_bytes($tx_total, 0); ?></span>
                </div>
            </div>
            <?php if (!empty($tags)): ?>
            <div class="tags-row" style="margin-top:15px;">
                <?php foreach ($tags as $tag): ?>
                    <?php $tag_class = (strpos($tag, '剩') === 0 || strpos($tag, '今日到期') !== false || strpos($tag, '已过期') !== false) ? 'tag expire' : 'tag'; ?>
                    <span class="<?php echo $tag_class; ?>"><?php echo htmlspecialchars($tag); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
<?php } ?>
    </div>
</div>

<footer>
    &copy; 2026 by lawtee. (你可以在此修改页脚文字)
</footer>

<script>
<?php echo implode("\n", $history_scripts); ?>
</script>

<script>
// ============ 工具函数 ============
function formatBytes(bytes, precision = 2) {
    if (bytes === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const pow = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, pow)).toFixed(precision) + ' ' + units[Math.min(pow, units.length - 1)];
}
function formatMem(mb) {
    if (mb >= 1024) return (mb/1024).toFixed(1) + 'GB';
    return mb + 'MB';
}
function sampleData(arr, maxPoints = 200) {
    if (arr.length <= maxPoints) return arr;
    const step = Math.ceil(arr.length / maxPoints);
    const result = [];
    for (let i = 0; i < arr.length; i += step) result.push(arr[i]);
    return result;
}
function shortOs(full) {
    if (full.includes('Ubuntu')) return 'Ubuntu';
    if (full.includes('Debian')) return 'Debian';
    return full;
}
function formatUptimeShort(seconds) {
    const days = Math.floor(seconds / 86400);
    if (days >= 1) return days + '天';
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    return hours + '小时 ' + minutes + '分钟';
}
function formatTrafficInt(bytes) {
    const b = Math.round(bytes);
    if (b === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const pow = Math.floor(Math.log(b) / Math.log(1024));
    const val = (b / Math.pow(1024, pow)).toFixed(0);
    return val + ' ' + units[Math.min(pow, units.length - 1)];
}

// ============ 初始化 Ping 历史图表 ============
function initPingCharts() {
    document.querySelectorAll('.card[data-token]').forEach(card => {
        const token = card.dataset.token;
        const rawHistory = window['hist_' + token] || [];
        if (rawHistory.length === 0) return;
        const sampled = sampleData(rawHistory, 200);

        const timestamps = sampled.map(p => p.t);
        const minTime = Math.min(...timestamps);
        const maxTime = Math.max(...timestamps);

        const datasets = [
            {
                label: '移动',
                data: sampled.map(p => ({ x: p.t, y: p.ping_mobile < 0 ? null : p.ping_mobile })),
                borderColor: '#a78bfa',
                borderWidth: 1,
                tension: 0.2,
                pointRadius: 0,
                spanGaps: true   // ← 改为 true
            },
            {
                label: '联通',
                data: sampled.map(p => ({ x: p.t, y: p.ping_unicom < 0 ? null : p.ping_unicom })),
                borderColor: '#f472b6',
                borderWidth: 1,
                tension: 0.2,
                pointRadius: 0,
                spanGaps: true
            },
            {
                label: '电信',
                data: sampled.map(p => ({ x: p.t, y: p.ping_telecom < 0 ? null : p.ping_telecom })),
                borderColor: '#38bdf8',
                borderWidth: 1,
                tension: 0.2,
                pointRadius: 0,
                spanGaps: true
            },
            {
                label: 'ATT',
                data: sampled.map(p => ({ x: p.t, y: p.ping_att < 0 ? null : p.ping_att })),
                borderColor: '#fb923c',
                borderWidth: 1,
                tension: 0.2,
                pointRadius: 0,
                spanGaps: true
            }
        ];

        new Chart(document.getElementById('ping_hist_' + token), {
            type: 'line',
            data: { datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        type: 'linear',
                        min: minTime,
                        max: maxTime,
                        ticks: {
                            color: '#9ca3af',
                            maxTicksLimit: 6,
                            callback: function(value) {
                                const d = new Date(value * 1000);
                                return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
                            }
                        },
                        grid: { display: false }
                    },
                    y: {
                        min: 0,
                        ticks: {
                            color: '#9ca3af',
                            callback: v => v + ' ms'
                        },
                        grid: { color: '#374151' }
                    }
                },
                plugins: {
                    legend: { labels: { color: '#d1d5db', boxWidth: 10 } },
                    tooltip: {
                        callbacks: {
                            title: function(items) {
                                if (items.length > 0) {
                                    const ts = items[0].raw.x;
                                    const d = new Date(ts * 1000);
                                    return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
                                }
                                return '';
                            },
                            label: function(ctx) {
                                if (ctx.raw.y === null) return ctx.dataset.label + ': 超时';
                                return ctx.dataset.label + ': ' + ctx.raw.y + ' ms';
                            }
                        }
                    }
                }
            }
        });
    });
}

// ============ 自动刷新实时数据 ============
async function updateRealtimeData() {
    try {
        const res = await fetch('?ajax=realtime');
        const data = await res.json();

        document.getElementById('current-time').textContent = data.current_time;
        document.getElementById('rx-value').textContent = formatBytes(data.total_rx);
        document.getElementById('tx-value').textContent = formatBytes(data.total_tx);

        data.nodes.forEach(node => {
            const token = node.token_md5;
            const card = document.getElementById('card-' + token);
            if (!card) return;

            if (node.online) {
                card.classList.remove('node-offline');
                card.style.borderLeftColor = '#22c55e';
            } else {
                card.classList.add('node-offline');
                card.style.borderLeftColor = '#ef4444';
            }

            const statusIndicator = card.querySelector('.status-indicator');
            if (statusIndicator) {
                const icon = statusIndicator.querySelector('i');
                const text = statusIndicator.querySelector('.status-text');
                if (node.online) {
                    statusIndicator.className = 'status-badge online';
                    icon.className = 'fas fa-circle online';
                    text.textContent = 'ONLINE';
                } else {
                    statusIndicator.className = 'status-badge offline';
                    icon.className = 'fas fa-circle offline';
                    text.textContent = 'OFFLINE';
                }
            }

            // 更新进度条
            const cpuBar = card.querySelector('.progress-bar.cpu');
            const memBar = card.querySelector('.progress-bar.mem');
            const diskBar = card.querySelector('.progress-bar.disk');
            const cpuText = card.querySelector('.cpu-value');
            const memText = card.querySelector('.mem-value');
            const diskText = card.querySelector('.disk-value');
            if (cpuBar) cpuBar.style.width = node.cpu + '%';
            if (memBar) memBar.style.width = node.mem + '%';
            if (diskBar) diskBar.style.width = node.disk + '%';
            if (cpuText) cpuText.textContent = node.cpu + '%';
            if (memText) memText.textContent = node.mem + '%';
            if (diskText) diskText.textContent = node.disk + '%';

            const totals = card.querySelectorAll('.progress-total');
            if (totals[0]) totals[0].textContent = node.cpu_cores + 'C';
            if (totals[1]) totals[1].textContent = formatMem(node.mem_total_mb);
            if (totals[2]) totals[2].textContent = node.disk_total_gb + 'GB';

            // 更新流量进度条
            const trafficBar = card.querySelector('.progress-bar.traffic');
            const trafficText = card.querySelector('.traffic-value');
            const trafficTotalEl = card.querySelectorAll('.progress-total')[3];
            if (trafficBar && trafficText && trafficTotalEl) {
                const limitGB = parseInt(trafficTotalEl.textContent) || 1000;
                const mode = '<?php echo $TRAFFIC_MODE; ?>';
                let usedBytes = 0;
                if (mode === 'both') {
                    usedBytes = (node.rx_total || 0) + (node.tx_total || 0);
                } else if (mode === 'rx') {
                    usedBytes = node.rx_total || 0;
                } else {
                    usedBytes = node.tx_total || 0;
                }
                const percent = limitGB > 0 ? Math.min(100, (usedBytes / (limitGB * 1073741824)) * 100).toFixed(1) : 0;
                trafficBar.style.width = percent + '%';
                trafficText.textContent = formatBytes(usedBytes);
            }

            // 更新信息行文字
            const osEl = card.querySelector('.os-text');
            if (osEl) osEl.textContent = shortOs(node.os_info);
            const uptimeEl = card.querySelector('.uptime-text');
            if (uptimeEl) uptimeEl.textContent = formatUptimeShort(node.uptime_seconds);
            const rxEl = card.querySelector('.traffic-item .rx');
            const txEl = card.querySelector('.traffic-item .tx');
            if (rxEl) rxEl.innerHTML = `<i class="fas fa-download"></i> ${formatTrafficInt(node.rx_total)}`;
            if (txEl) txEl.innerHTML = `| <i class="fas fa-upload"></i> ${formatTrafficInt(node.tx_total)}`;
        });
    } catch (err) {
        console.error('自动刷新失败:', err);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initPingCharts();
    setInterval(updateRealtimeData, 30000);
});
</script>

</body>
</html>