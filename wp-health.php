<?php
/**
 * wp-health.php — ตรวจสุขภาพปลั๊กอินและธีม WordPress ทุกเว็บบนเซิร์ฟเวอร์
 *                 (หาโฟลเดอร์ว่างเปล่า + นับไฟล์/ขนาด + เช็คไฟล์หลัก)
 *
 * อ่านอย่างเดียว 100% — ไม่ลบ ไม่แก้ ไม่ย้ายไฟล์ใด ๆ
 *
 * รันตรงจาก GitHub (ไม่ต้องเซฟไฟล์):
 *   URL='https://raw.githubusercontent.com/ufavisionseoteam19/wp-health-check/main/wp-health.php'
 *   curl -s "$URL?v=$(date +%s)" | php                          # ตรวจทั้ง plugins + themes ทั้งเครื่อง
 *   curl -s "$URL?v=$(date +%s)" | php -- --plugins             # เฉพาะ plugins
 *   curl -s "$URL?v=$(date +%s)" | php -- --themes              # เฉพาะ themes
 *   curl -s "$URL?v=$(date +%s)" | php -- --user=newkeydec2025  # เฉพาะบัญชีเดียว
 *   curl -s "$URL?v=$(date +%s)" | php -- --only-issues         # เฉพาะที่มีปัญหา
 *   curl -s "$URL?v=$(date +%s)" | php -- --csv                 # ออกผลเป็น CSV
 *
 * Flags:
 *   --plugins         ตรวจเฉพาะ plugins
 *   --themes          ตรวจเฉพาะ themes
 *                     (ไม่ใส่ทั้งคู่ = ตรวจทั้งสองอย่าง)
 *   --user=NAME       สแกนเฉพาะบัญชีผู้ใช้นี้ (ค่าเริ่มต้น = ทุกบัญชีใน /home)
 *   --base=PATH       เปลี่ยนโฟลเดอร์ฐาน (ค่าเริ่มต้น = /home)
 *   --threshold=N     ถ้ามีไฟล์ <= N ถือว่าน่าสงสัย (ค่าเริ่มต้น = 3)
 *   --maxdepth=N      จำกัดความลึกการค้นจาก base (ค่าเริ่มต้น = 4)
 *   --exclude=a,b,c   ยกเว้นชื่อ plugin/theme เพิ่ม (คั่นด้วยคอมมา)
 *   --csv             แสดงผลเป็น CSV (มีคอลัมน์ type = plugin/theme)
 *   --only-issues     แสดงเฉพาะตัวที่มีปัญหา (ซ่อนตัวปกติ)
 *   --no-progress     ปิดข้อความความคืบหน้า
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

// ====== รันแบบถ่อมตัว: ลด priority เพื่อไม่ไปแย่งทรัพยากรเว็บอื่น ======
if (function_exists('proc_nice')) { @proc_nice(19); }
@exec('ionice -c3 -p ' . getmypid() . ' 2>/dev/null');

// ====== ค่าตั้งต้น ======
$BASE        = '/home';
$ONLY_USER   = null;
$THRESHOLD   = 3;
$MAXDEPTH    = 4;
$PROGRESS    = true;
$AS_CSV      = false;
$ONLY_ISSUES = false;
$DO_PLUGINS  = false;   // ถ้าไม่ระบุ --plugins/--themes เลย จะเปิดทั้งคู่
$DO_THEMES   = false;
$EXCLUDE     = [];

// ====== อ่าน argument ======
global $argv;
foreach ($argv as $a) {
    if (strpos($a, '--user=')      === 0) { $ONLY_USER = substr($a, 7); }
    elseif (strpos($a, '--base=')  === 0) { $BASE      = substr($a, 7); }
    elseif (strpos($a, '--threshold=') === 0) { $THRESHOLD = (int)substr($a, 12); }
    elseif (strpos($a, '--maxdepth=')  === 0) { $MAXDEPTH  = (int)substr($a, 11); }
    elseif (strpos($a, '--exclude=')   === 0) {
        foreach (explode(',', substr($a, 10)) as $e) {
            $e = trim($e);
            if ($e !== '') { $EXCLUDE[] = $e; }
        }
    }
    elseif ($a === '--plugins')     { $DO_PLUGINS = true; }
    elseif ($a === '--themes')      { $DO_THEMES = true; }
    elseif ($a === '--csv')         { $AS_CSV = true; }
    elseif ($a === '--only-issues') { $ONLY_ISSUES = true; }
    elseif ($a === '--no-progress') { $PROGRESS = false; }
}
// ไม่ระบุทั้งคู่ = ตรวจทั้งสองอย่าง
if (!$DO_PLUGINS && !$DO_THEMES) { $DO_PLUGINS = true; $DO_THEMES = true; }
$EXCLUDE = array_unique($EXCLUDE);
if ($AS_CSV) { $PROGRESS = false; }

// ====== ฟังก์ชันช่วย ======
function count_files($dir) {
    $n = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) { if ($f->isFile()) $n++; }
    } catch (Exception $e) { return 0; }
    return $n;
}

function dir_size($dir) {
    $size = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) { if ($f->isFile()) $size += $f->getSize(); }
    } catch (Exception $e) { return 0; }
    return $size;
}

function human_size($b) {
    if ($b >= 1073741824) return round($b/1073741824, 1) . 'G';
    if ($b >= 1048576)    return round($b/1048576, 1) . 'M';
    if ($b >= 1024)       return round($b/1024, 1) . 'K';
    return $b . 'B';
}

/** ตรวจไฟล์หลักตามชนิด:
 *  plugin → ไฟล์ .php ระดับบนสุดที่มี "Plugin Name:"
 *  theme  → ไฟล์ style.css ที่มี "Theme Name:"
 *  คืนค่า true = มีไฟล์หลักครบ */
function has_main_header($dir, $type) {
    if ($type === 'theme') {
        $css = "$dir/style.css";
        if (!is_file($css)) return false;
        $head = @file_get_contents($css, false, null, 0, 8192);
        return ($head !== false && stripos($head, 'Theme Name:') !== false);
    }
    // plugin
    $php_files = glob("$dir/*.php");
    if (!$php_files) return false;
    foreach ($php_files as $f) {
        $head = @file_get_contents($f, false, null, 0, 8192);
        if ($head !== false && stripos($head, 'Plugin Name:') !== false) return true;
    }
    return false;
}

/** อ่านชื่อธีมแม่ (Template) จาก style.css ของ child theme
 *  คืนค่า: ชื่อ template (เช่น "blocksy") ถ้าเป็น child theme
 *          null ถ้าไม่ใช่ child theme (ไม่มี Template) หรืออ่านไม่ได้ */
function get_theme_template($dir) {
    $css = "$dir/style.css";
    if (!is_file($css)) return null;
    $head = @file_get_contents($css, false, null, 0, 8192);
    if ($head === false) return null;
    if (stripos($head, 'Theme Name:') === false) return null;
    // หาบรรทัด Template: xxx (รองรับ * หรือช่องว่างนำหน้าในคอมเมนต์ /** */)
    if (preg_match('/^[\s*]*Template:\s*(.+)$/mi', $head, $m)) {
        return trim($m[1]);
    }
    return null; // มี Theme Name แต่ไม่มี Template = ไม่ใช่ child theme
}

/** เช็คว่าโฟลเดอร์ธีมแม่มีอยู่จริงและใช้งานได้ (มี style.css ที่มี Theme Name)
 *  $themes_dir = path ของ wp-content/themes, $template = ชื่อโฟลเดอร์ธีมแม่ */
function parent_theme_ok($themes_dir, $template) {
    $parent = "$themes_dir/$template";
    if (!is_dir($parent)) return false;
    $css = "$parent/style.css";
    if (!is_file($css)) return false;
    $head = @file_get_contents($css, false, null, 0, 8192);
    return ($head !== false && stripos($head, 'Theme Name:') !== false);
}

/** ค้นหาโฟลเดอร์ wp-content ทั้งหมด (จับครั้งเดียว ใช้ได้ทั้ง plugins + themes) */
function find_wp_content_dirs($root, $maxdepth, $progress) {
    $found = [];
    $skip_names = ['node_modules', '.git', 'cache', '.cache', 'tmp', 'logs'];
    $stack = [[$root, 0]];
    while ($stack) {
        list($dir, $depth) = array_pop($stack);
        $dh = @opendir($dir);
        if ($dh === false) continue;
        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..') continue;
            $path = "$dir/$entry";
            if (!is_dir($path) || is_link($path)) continue;
            if ($entry === 'wp-content') {
                $found[] = $path;
                if ($progress && count($found) % 200 === 0) {
                    fwrite(STDERR, "  ...พบแล้ว " . count($found) . " เว็บ\r");
                }
                continue; // ไม่ไต่ลงใน wp-content (เดี๋ยวเข้า plugins/themes ตรง ๆ)
            }
            if (in_array($entry, $skip_names, true)) continue;
            if ($depth + 1 <= $maxdepth) { $stack[] = [$path, $depth + 1]; }
        }
        closedir($dh);
    }
    if ($progress && count($found) > 0) fwrite(STDERR, "                              \r");
    return $found;
}

// ====== หาเว็บทั้งหมด ======
$scan_root = $ONLY_USER ? "$BASE/$ONLY_USER" : $BASE;
if ($PROGRESS) fwrite(STDERR, "กำลังค้นหาเว็บ WordPress (ลึกไม่เกิน $MAXDEPTH ชั้น)...\n");
$wpcontent_dirs = is_dir($scan_root) ? find_wp_content_dirs($scan_root, $MAXDEPTH, $PROGRESS) : [];
sort($wpcontent_dirs);

// ชนิดที่จะตรวจ
$types = [];
if ($DO_PLUGINS) $types['plugins'] = 'plugin';
if ($DO_THEMES)  $types['themes']  = 'theme';

// ====== ส่วนหัว ======
if (!$AS_CSV) {
    $scope = [];
    if ($DO_PLUGINS) $scope[] = 'plugins';
    if ($DO_THEMES)  $scope[] = 'themes';
    echo "=======================================================\n";
    echo " WordPress Health Check — Plugins & Themes (read-only)\n";
    echo "=======================================================\n";
    echo " วันเวลา      : " . date('Y-m-d H:i:s') . "\n";
    echo " เครื่อง       : " . php_uname('n') . "\n";
    echo " โฟลเดอร์ฐาน  : $BASE\n";
    echo " ขอบเขต       : " . ($ONLY_USER ? "เฉพาะบัญชี '$ONLY_USER'" : "ทุกบัญชีใน $BASE") . "\n";
    echo " ตรวจ         : " . implode(' + ', $scope) . "\n";
    echo " เกณฑ์น่าสงสัย: มีไฟล์ <= $THRESHOLD\n";
    echo " ความลึกค้นหา : ไม่เกิน $MAXDEPTH ชั้น\n";
    echo " ยกเว้น       : " . (count($EXCLUDE) ? implode(', ', $EXCLUDE) : '(ไม่มี)') . "\n";
    echo " พบเว็บ       : " . count($wpcontent_dirs) . " เว็บ\n\n";
} else {
    echo "site,type,name,files,size_bytes,status\n";
}

if (count($wpcontent_dirs) === 0) {
    if (!$AS_CSV) echo "ไม่พบเว็บ WordPress ใด ๆ ภายใต้ $scan_root\n";
    exit(0);
}

// ====== ตัวนับสรุป (แยกตามชนิด) ======
$counters = [
    'plugin' => ['empty' => [], 'nohdr' => [], 'suspect' => [], 'total' => 0],
    'theme'  => ['incomplete' => [], 'total' => 0],
];

// ====== วนแต่ละเว็บ ======
foreach ($wpcontent_dirs as $wpc) {
    $site = preg_replace('#/wp-content$#', '', $wpc);
    $site_has_output = false;

    // ---------- PLUGINS (ตรรกะเดิม: นับไฟล์ครบทุกแบบ) ----------
    if ($DO_PLUGINS) {
        $container = "$wpc/plugins";
        if (is_dir($container)) {
            $entries = glob("$container/*", GLOB_ONLYDIR);
            if ($entries) {
                sort($entries);
                $rows = [];
                foreach ($entries as $d) {
                    $name = basename($d);
                    if (in_array($name, $EXCLUDE, true)) {
                        if (!$AS_CSV && !$ONLY_ISSUES) $rows[] = ['ข้าม', '-', '-', $name];
                        continue;
                    }
                    $counters['plugin']['total']++;
                    $fcount = count_files($d);
                    $bytes  = dir_size($d);
                    $size   = human_size($bytes);

                    if ($fcount === 0) {
                        $status = 'ว่าง!';
                        $counters['plugin']['empty'][] = ['name' => $name, 'site' => $site, 'files' => $fcount];
                    } elseif ($fcount <= $THRESHOLD) {
                        $status = 'สงสัย';
                        $counters['plugin']['suspect'][] = ['name' => $name, 'site' => $site, 'files' => $fcount];
                    } elseif (!has_main_header($d, 'plugin')) {
                        $status = 'ไม่มีหลัก';
                        $counters['plugin']['nohdr'][] = ['name' => $name, 'site' => $site, 'files' => $fcount];
                    } else {
                        $status = 'ปกติ';
                    }

                    if ($AS_CSV) {
                        echo "\"$site\",plugin,\"$name\",$fcount,$bytes,$status\n";
                    } else {
                        if ($ONLY_ISSUES && $status === 'ปกติ') continue;
                        $rows[] = [$status, $fcount, $size, $name];
                    }
                }
                if (!$AS_CSV && count($rows) > 0) {
                    if (!$site_has_output) {
                        echo "-------------------------------------------------------\n";
                        echo " ไซต์: $site\n";
                        echo "-------------------------------------------------------\n";
                        $site_has_output = true;
                    }
                    echo "  [PLUGIN]\n";
                    printf("  %-9s %-7s %-9s %s\n", 'สถานะ', 'ไฟล์', 'ขนาด', 'ชื่อ');
                    foreach ($rows as $r) printf("  %-9s %-7s %-9s %s\n", $r[0], $r[1], $r[2], $r[3]);
                }
            }
        }
    }

    // ---------- THEMES (ตรวจทุกเว็บ: ต้องมี blocksy + blocksy-child ครบ) ----------
    if ($DO_THEMES) {
        $tdir = "$wpc/themes";
        $blocksy_dir = "$tdir/blocksy";
        $child_dir   = "$tdir/blocksy-child";
        $counters['theme']['total']++;

        // blocksy (parent) สมบูรณ์ไหม — เช็คเบา: มีโฟลเดอร์ + style.css ที่มี Theme Name
        $parent_ok = (is_dir($blocksy_dir) && is_file("$blocksy_dir/style.css")
            && stripos(@file_get_contents("$blocksy_dir/style.css", false, null, 0, 8192) ?: '', 'Theme Name:') !== false);
        // blocksy-child สมบูรณ์ไหม
        $child_ok = (is_dir($child_dir) && is_file("$child_dir/style.css")
            && stripos(@file_get_contents("$child_dir/style.css", false, null, 0, 8192) ?: '', 'Theme Name:') !== false);

        $issues = [];
        if (!$parent_ok) $issues[] = 'blocksy (parent) หาย/เสีย';
        if (!$child_ok)  $issues[] = 'blocksy-child หาย/เสีย';

        $status = empty($issues) ? 'ปกติ' : 'ไม่ครบ';
        if (!empty($issues)) {
            $counters['theme']['incomplete'][] = ['name' => implode(' + ', $issues), 'site' => $site, 'files' => 0];
        }

        if ($AS_CSV) {
            $detail = empty($issues) ? 'blocksy+child ครบ' : implode('; ', $issues);
            echo "\"$site\",theme,\"blocksy set\",-,-,$status ($detail)\n";
        } else {
            $show = !($ONLY_ISSUES && $status === 'ปกติ');
            if ($show) {
                if (!$site_has_output) {
                    echo "-------------------------------------------------------\n";
                    echo " ไซต์: $site\n";
                    echo "-------------------------------------------------------\n";
                    $site_has_output = true;
                }
                echo "  [THEME] ";
                if (empty($issues)) {
                    echo "ปกติ — blocksy + blocksy-child ครบ\n";
                } else {
                    echo "ไม่ครบ — " . implode(', ', $issues) . "\n";
                }
            }
        }
    }

    if (!$AS_CSV && $site_has_output) echo "\n";
}

// ====== สรุปท้าย ======
if (!$AS_CSV) {
    $group_and_print = function($list, $heading) {
        if (count($list) === 0) return;
        $groups = [];
        foreach ($list as $item) { $groups[$item['name']][] = $item; }
        uasort($groups, function($a, $b) { return count($b) - count($a); });
        echo "$heading\n";
        foreach ($groups as $name => $items) {
            $n = count($items);
            echo "\n  ┌─ [$name] กระทบ $n เว็บ\n";
            foreach ($items as $it) {
                $extra = ($it['files'] > 0) ? " ({$it['files']} ไฟล์)" : "";
                echo "  │   • {$it['site']}$extra\n";
            }
            echo "  └─────────────────────────────────────────\n";
        }
        echo "\n";
    };

    echo "=======================================================\n";
    echo " สรุปผล\n";
    echo "=======================================================\n";
    echo " จำนวนเว็บที่สแกน       : " . count($wpcontent_dirs) . "\n";

    // นับ "โดเมนที่มีปัญหา" แบบไม่ซ้ำ
    $problem_sites = [];
    $total_issues  = 0;
    // plugin
    foreach (['empty', 'nohdr', 'suspect'] as $k) {
        foreach ($counters['plugin'][$k] as $it) {
            $problem_sites[$it['site']] = true; $total_issues++;
        }
    }
    // theme
    foreach ($counters['theme']['incomplete'] as $it) {
        $problem_sites[$it['site']] = true; $total_issues++;
    }
    echo " โดเมนที่มีปัญหา       : " . count($problem_sites) . " เว็บ\n";
    echo " รายการปัญหาทั้งหมด    : $total_issues รายการ\n\n";

    $any_issue = false;

    // ── สรุป PLUGINS (เดิม) ──
    if ($DO_PLUGINS) {
        $c = $counters['plugin'];
        $ne = count($c['empty']); $nh = count($c['nohdr']); $ns = count($c['suspect']);
        echo " ── ปลั๊กอิน (PLUGINS) ──\n";
        echo "   ตรวจทั้งหมด     : {$c['total']}\n";
        echo "   ว่างเปล่า       : $ne\n";
        echo "   ไม่มีไฟล์หลัก   : $nh\n";
        echo "   ไฟล์น้อยผิดปกติ : $ns\n\n";
        if ($ne + $nh + $ns > 0) $any_issue = true;
    }

    // ── สรุป THEMES (ใหม่: เช็ค blocksy + child) ──
    if ($DO_THEMES) {
        $c = $counters['theme'];
        $ni = count($c['incomplete']);
        echo " ── ธีม (THEMES) — ทุกเว็บต้องมี blocksy + blocksy-child ──\n";
        echo "   เว็บที่ตรวจ       : {$c['total']}\n";
        echo "   ชุดธีมไม่ครบ      : $ni\n\n";
        if ($ni > 0) $any_issue = true;
    }

    // ── รายละเอียด PLUGINS ──
    if ($DO_PLUGINS) {
        $c = $counters['plugin'];
        if (count($c['empty']) > 0)
            $group_and_print($c['empty'], "** [PLUGIN] โฟลเดอร์ว่างเปล่า (ต้องติดตั้งใหม่) **");
        if (count($c['nohdr']) > 0)
            $group_and_print($c['nohdr'], "** [PLUGIN] ไม่มีไฟล์หลัก (ไฟล์เยอะแต่ไฟล์หลักหาย ใช้งานไม่ได้) **");
        if (count($c['suspect']) > 0)
            $group_and_print($c['suspect'], "** [PLUGIN] ไฟล์น้อยผิดปกติ (ควรตรวจเพิ่ม) **");
    }

    // ── รายละเอียด THEMES ──
    if ($DO_THEMES && count($counters['theme']['incomplete']) > 0) {
        $group_and_print($counters['theme']['incomplete'], "** [THEME] ชุดธีม blocksy ไม่ครบ (ทุกเว็บต้องมี blocksy + blocksy-child) **");
    }

    if (!$any_issue) echo " ไม่พบความผิดปกติ plugins/themes ทุกตัวมีไฟล์ครบถ้วน\n\n";
    echo " หมายเหตุ: สคริปต์นี้อ่านอย่างเดียว ไม่แก้ไขไฟล์ใด ๆ\n";
}
