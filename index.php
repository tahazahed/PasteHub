<?php
declare(strict_types=1);
session_start();

const RETENTION = 86400;
const PASSWORDLESS_UPLOAD_LIMIT = 100 * 1024 * 1024;
define('PASTEHUB_ENTRY', true);
$configFile = __DIR__ . '/config.php';
if (is_file($configFile)) require_once $configFile;
$store = __DIR__ . '/storage';
$itemsFile = $store . '/items.json';

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function iniBytes(string $value): int {
    $value = trim($value);
    if ($value === '') return 0;
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    return (int)($number * match ($unit) { 'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1 });
}
function ensureStore(string $store, string $itemsFile): void {
    if (!is_dir($store) && !mkdir($store, 0700, true) && !is_dir($store)) throw new RuntimeException('فضای ذخیره‌سازی آماده نیست.');
    if (!file_exists($itemsFile)) file_put_contents($itemsFile, '{}', LOCK_EX);
}
function withItems(string $file, callable $callback): mixed {
    $fp = fopen($file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('خطا در دسترسی به فضای ذخیره‌سازی.');
    rewind($fp);
    $items = json_decode(stream_get_contents($fp) ?: '{}', true);
    if (!is_array($items)) $items = [];
    $result = $callback($items);
    rewind($fp); ftruncate($fp, 0);
    fwrite($fp, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
    return $result;
}
function cleanExpired(array &$items, string $store): void {
    foreach ($items as $code => $item) if (($item['expires'] ?? 0) < time()) {
        if (!empty($item['stored']) && is_file($store . '/' . basename($item['stored']))) @unlink($store . '/' . basename($item['stored']));
        unset($items[$code]);
    }
}
function safeName(string $name): string {
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'file';
    return mb_substr($name, 0, 180, 'UTF-8');
}

try { ensureStore($store, $itemsFile); } catch (Throwable $e) { http_response_code(500); exit('فضای ذخیره‌سازی آماده نیست. دسترسی نوشتن پوشه storage را بررسی کنید.'); }
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));

if (isset($_GET['api'])) {
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            $postLimit = iniBytes((string)ini_get('post_max_size'));
            if ($postLimit > 0 && $contentLength > $postLimit) jsonResponse(['error' => 'حجم درخواست از محدودیت هاست بیشتر است. مقدار post_max_size در تنظیمات PHP را افزایش دهید.'], 413);
            $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '';
            if (!hash_equals($_SESSION['csrf'], $csrf)) jsonResponse(['error' => 'درخواست نامعتبر است. صفحه را تازه‌سازی کنید.'], 403);
            $kind = $_POST['kind'] ?? '';
            if (!in_array($kind, ['text', 'file'], true)) jsonResponse(['error' => 'نوع محتوا نامعتبر است.'], 400);
            $item = ['kind' => $kind, 'created' => time(), 'expires' => time() + RETENTION];
            if ($kind === 'text') {
                $text = trim((string)($_POST['text'] ?? ''));
                if ($text === '' || strlen($text) > 500000) jsonResponse(['error' => 'متن خالی است یا از ۵۰۰ کیلوبایت بیشتر است.'], 400);
                $item['text'] = $text;
            } else {
                $f = $_FILES['file'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                    $message = ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE
                        ? 'محدودیت upload_max_filesize هاست از اندازه این فایل کمتر است. تنظیمات PHP هاست را افزایش دهید.'
                        : 'بارگذاری فایل انجام نشد. محدودیت‌های PHP و هاست را بررسی کنید.';
                    jsonResponse(['error' => $message], 400);
                }
                if ($f['size'] > PASSWORDLESS_UPLOAD_LIMIT) {
                    $uploadPassword = defined('PASTEHUB_UPLOAD_PASSWORD') ? (string)PASTEHUB_UPLOAD_PASSWORD : '';
                    if ($uploadPassword === '' || $uploadPassword === 'CHANGE_ME_TO_A_LONG_RANDOM_PASSWORD') jsonResponse(['error' => 'برای فایل‌های بالای ۱۰۰ مگابایت، رمز را در فایل config.php تنظیم کنید.'], 503);
                    if (!hash_equals($uploadPassword, (string)($_POST['upload_password'] ?? ''))) jsonResponse(['error' => 'برای فایل‌های بالای ۱۰۰ مگابایت رمز بارگذاری درست را وارد کنید.'], 403);
                }
                if (!is_uploaded_file($f['tmp_name'])) jsonResponse(['error' => 'فایل بارگذاری‌شده معتبر نیست.'], 400);
                $stored = bin2hex(random_bytes(16)) . '.bin';
                if (!move_uploaded_file($f['tmp_name'], $store . '/' . $stored)) jsonResponse(['error' => 'ذخیره فایل ممکن نشد.'], 500);
                $item += ['stored' => $stored, 'name' => safeName($f['name']), 'size' => (int)$f['size'], 'mime' => 'application/octet-stream'];
            }
            $code = withItems($itemsFile, function (&$items) use ($item, $store) {
                cleanExpired($items, $store);
                for ($i = 0; $i < 30; $i++) { $code = (string)random_int(10000, 99999); if (!isset($items[$code])) { $items[$code] = $item; return $code; } }
                throw new RuntimeException('کد آزاد پیدا نشد؛ دوباره تلاش کنید.');
            });
            jsonResponse(['code' => $code, 'expires' => $item['expires']]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $code = preg_replace('/\D/', '', (string)($_GET['code'] ?? ''));
            if (!preg_match('/^\d{5}$/', $code)) jsonResponse(['error' => 'شناسه باید پنج رقم باشد.'], 400);
            $item = withItems($itemsFile, function (&$items) use ($code, $store) { cleanExpired($items, $store); return $items[$code] ?? null; });
            if (!$item) jsonResponse(['error' => 'موردی با این شناسه پیدا نشد یا منقضی شده است.'], 404);
            if (isset($_GET['download'])) {
                if (($item['kind'] ?? '') !== 'file' || empty($item['stored']) || !is_file($store . '/' . basename($item['stored']))) jsonResponse(['error' => 'فایل در دسترس نیست.'], 404);
                header('Content-Type: application/octet-stream');
                header('Content-Length: ' . filesize($store . '/' . basename($item['stored'])));
                header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($item['name'] ?? 'file'));
                header('X-Content-Type-Options: nosniff');
                readfile($store . '/' . basename($item['stored'])); exit;
            }
            unset($item['stored']); jsonResponse($item);
        }
        jsonResponse(['error' => 'روش درخواست پشتیبانی نمی‌شود.'], 405);
    } catch (Throwable $e) { jsonResponse(['error' => 'خطای سرور. دوباره تلاش کنید.'], 500); }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f7f8fc"><title>پیست هاب | انتقال سریع فایل و متن</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
@media(max-width:700px){.wrap{padding-bottom:10px}.foot{margin-top:12px}}
 .wrap{min-height:100svh;display:flex;flex-direction:column}.nav{margin-bottom:clamp(16px,4vh,38px)}.hero{margin-bottom:clamp(14px,3vh,28px)}.mode-tabs{width:min(100%,560px);margin:0 auto 14px;padding:5px;display:flex;gap:5px;background:#eaedf5;border:1px solid #e2e6f0;border-radius:14px}.mode-tab{min-width:0;flex:1;border:0;border-radius:10px;padding:10px 14px;background:transparent;color:var(--muted);font:600 14px Vazirmatn,Tahoma,sans-serif;cursor:pointer;transition:.18s}.mode-tab span{margin-right:5px;font-size:17px}.mode-tab.active{background:white;color:var(--blue);box-shadow:0 2px 8px #202b4414}.grid{display:block;flex:1 0 auto}.mode-panel{width:min(100%,560px);margin:0 auto}.mode-panel[hidden]{display:none}.receive{padding-top:26px}.foot{margin-top:auto;padding-top:14px}@media(max-width:700px){.wrap{padding:12px 12px 10px}.nav{margin-bottom:clamp(12px,2.5vh,22px)}.hero{margin-bottom:14px}.hero h1{margin:9px 0 4px;line-height:1.3}.hero p{font-size:12px}.mode-tabs{margin-bottom:10px}.mode-tab{padding:9px 7px;font-size:13px}.mode-panel{padding:16px 15px;border-radius:16px}.card-head{margin-bottom:12px}.tabs{margin-bottom:10px}.text-input,.drop{height:clamp(92px,17vh,125px)}.receive{padding-top:16px}.code-row{margin:14px 0 10px}.result{margin-top:12px;padding:12px}.foot{font-size:10px;line-height:1.8;padding-top:10px}}@media(max-height:720px){.wrap{padding-top:9px;padding-bottom:8px}.nav{margin-bottom:10px}.hero{margin-bottom:10px}.hero h1{font-size:clamp(25px,6vh,36px);margin:7px 0 3px}.hero p{font-size:12px}.mode-tabs{margin-bottom:8px;padding:4px}.mode-tab{padding:7px 10px}.mode-panel{padding-top:14px;padding-bottom:14px}.card-head{margin-bottom:10px}.text-input,.drop{height:clamp(82px,15vh,110px)}.foot{padding-top:7px}}
.password-label{display:block;margin:8px 0 5px;font-size:12px;font-weight:600;color:var(--ink)}.password-input{display:block;width:100%;min-width:0;border:1px solid var(--line);border-radius:11px;padding:10px 12px;font-size:13px;outline:none;background:#fff}.password-input:focus{border-color:#8da2ff;box-shadow:0 0 0 3px #4169f51a}@media(max-height:720px){.password-label{margin-top:6px}.password-input{padding:8px 10px}}
html{touch-action:manipulation}
</style></head>
<body><main class="wrap">
<header class="nav"><div class="brand"><div class="brand-mark">↗</div><span>پیست هاب</span></div><div class="nav-note">سریع، ساده، همیشه در دسترس</div></header>
<section class="hero"><span class="eyebrow">✦ انتقال بی‌دردسر بین دستگاه‌ها</span><h1>چیزی برای جابه‌جایی داری؟</h1><p>متن یا فایل را بفرست، با یک کد پنج‌رقمی در دستگاه دیگرت دریافتش کن.</p></section>
<div class="mode-tabs" role="tablist" aria-label="انتخاب عملیات"><button class="mode-tab active" id="sendTab" role="tab" aria-selected="true" aria-controls="sendPanel">ارسال فایل یا متن <span>↑</span></button><button class="mode-tab" id="receiveTab" role="tab" aria-selected="false" aria-controls="receivePanel">دریافت با کد <span>↓</span></button></div>
<section class="grid" id="appPanels">
<article class="card mode-panel active" id="sendPanel" role="tabpanel" aria-labelledby="sendTab"><div class="card-head"><div class="icon">⇧</div><div><h2>ارسال</h2><p>متن یا فایل خودت را به اشتراک بگذار</p></div></div>
<div class="tabs"><button class="tab active" data-kind="text">متن</button><button class="tab" data-kind="file">فایل</button></div>
<div id="textPanel"><textarea class="text-input" id="textInput" maxlength="500000" placeholder="متنت را اینجا بنویس یا پیست کن..."></textarea></div>
<div id="filePanel" hidden><label class="drop" for="fileInput"><span>⇧<strong>برای انتخاب فایل کلیک کن</strong><span>تا ۱۰۰ مگابایت بدون رمز؛ فایل بزرگ‌تر با رمز</span></span><input id="fileInput" type="file"></label><div class="file-name" id="fileName"></div><label class="password-label" for="uploadPassword">رمز فایل‌های بالای ۱۰۰ مگابایت</label><input class="password-input" id="uploadPassword" type="password" autocomplete="current-password" placeholder="رمز تنظیم‌شده در کانفیگ سرور"></div>
<button class="primary" id="sendBtn" style="margin-top:13px">ساخت کد دریافت</button><div class="upload-progress" id="uploadProgress" role="status" aria-live="polite"><div class="progress-meta"><span>در حال بارگذاری فایل…</span><strong id="uploadPercent">۰٪</strong></div><div class="progress-track" role="progressbar" aria-label="پیشرفت بارگذاری فایل" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="progress-fill" id="progressFill"></div></div></div><div class="result" id="sendResult"></div></article>
<article class="card receive mode-panel" id="receivePanel" role="tabpanel" aria-labelledby="receiveTab" hidden><div class="card-head"><div class="icon">⇩</div><div><h2>دریافت</h2><p>کدی که از دستگاه دیگر داری وارد کن</p></div></div>
<div class="code-row"><input class="code-input" id="codeInput" inputmode="numeric" maxlength="5" placeholder="00000" autocomplete="one-time-code"><button class="primary" id="receiveBtn">دریافت</button></div>
<div class="helper">شناسه پنج‌رقمی را وارد کن تا متن را ببینی یا فایل را دانلود کنی.</div><div class="result" id="receiveResult"></div></article></section>
<footer class="foot">بدون نیاز به ساخت حساب <span>•</span> حذف خودکار پس از ۲۴ ساعت <span>•</span> فایل بزرگ‌تر از ۱۰۰ مگابایت با رمز</footer>
</main><div class="alert" id="alert"></div>
<script>
const csrf=<?=json_encode($_SESSION['csrf'])?>;let kind='text';const $=s=>document.querySelector(s);function toast(s){const a=$('#alert');a.textContent=s;a.classList.add('show');setTimeout(()=>a.classList.remove('show'),2800)}
function showMode(mode){const sending=mode==='send';$('#sendPanel').hidden=!sending;$('#receivePanel').hidden=sending;$('#sendTab').classList.toggle('active',sending);$('#receiveTab').classList.toggle('active',!sending);$('#sendTab').setAttribute('aria-selected',String(sending));$('#receiveTab').setAttribute('aria-selected',String(!sending))}$('#sendTab').onclick=()=>showMode('send');$('#receiveTab').onclick=()=>showMode('receive');
document.querySelectorAll('.tab').forEach(b=>b.onclick=()=>{document.querySelectorAll('.tab').forEach(x=>x.classList.toggle('active',x===b));kind=b.dataset.kind;$('#textPanel').hidden=kind!=='text';$('#filePanel').hidden=kind!=='file';$('#sendResult').classList.remove('show')});$('#fileInput').onchange=()=>$('#fileName').textContent=$('#fileInput').files[0]?.name||'';
$('#sendBtn').onclick=async()=>{const btn=$('#sendBtn'),fd=new FormData();fd.append('csrf',csrf);fd.append('kind',kind);const progress=$('#uploadProgress'),fill=$('#progressFill'),percent=$('#uploadPercent'),track=progress.querySelector('[role="progressbar"]');if(kind==='text'){if(!$('#textInput').value.trim())return toast('اول متنی وارد کن.');fd.append('text',$('#textInput').value)}else{const f=$('#fileInput').files[0];if(!f)return toast('یک فایل انتخاب کن.');if(f.size>100*1024*1024&&!$('#uploadPassword').value)return toast('برای فایل‌های بالای ۱۰۰ مگابایت رمز بارگذاری را وارد کن.');if(f.size>100*1024*1024)fd.append('upload_password',$('#uploadPassword').value);fd.append('file',f);progress.classList.add('show');fill.style.width='0%';percent.textContent='۰٪';track.setAttribute('aria-valuenow','0')}btn.disabled=true;btn.textContent=kind==='file'?'در حال بارگذاری…':'در حال ارسال…';try{let r;if(kind==='file'){r=await new Promise((resolve,reject)=>{const xhr=new XMLHttpRequest();xhr.open('POST','?api=1');xhr.setRequestHeader('X-CSRF-Token',csrf);xhr.upload.onprogress=e=>{if(e.lengthComputable){const p=Math.min(100,Math.round(e.loaded/e.total*100));fill.style.width=p+'%';percent.textContent=new Intl.NumberFormat('fa-IR').format(p)+'٪';track.setAttribute('aria-valuenow',String(p))}};xhr.onload=()=>{try{resolve({ok:xhr.status>=200&&xhr.status<300,json:async()=>JSON.parse(xhr.responseText)})}catch(e){reject(new Error('پاسخ سرور قابل خواندن نیست.'))}};xhr.onerror=()=>reject(new Error('ارتباط با سرور قطع شد.'));xhr.onabort=()=>reject(new Error('بارگذاری لغو شد.'));xhr.send(fd)})}else r=await fetch('?api=1',{method:'POST',body:fd});const d=await r.json();if(!r.ok)throw Error(d.error);$('#sendResult').innerHTML=`کد دریافت شما:<br><strong dir="ltr" style="font-size:30px;letter-spacing:8px;color:#4169f5">${d.code}</strong><br><span class="helper">این کد را در دستگاه مقصد وارد کن. کد تا ۲۴ ساعت معتبر است.</span>`;$('#sendResult').classList.add('show');if(kind==='file'){fill.style.width='100%';percent.textContent='۱۰۰٪';track.setAttribute('aria-valuenow','100')}}catch(e){toast(e.message||'ارسال انجام نشد.');if(kind==='file')progress.classList.remove('show')}finally{btn.disabled=false;btn.textContent='ساخت کد دریافت'}};
$('#codeInput').oninput=e=>e.target.value=e.target.value.replace(/\D/g,'').slice(0,5);$('#receiveBtn').onclick=async()=>{const code=$('#codeInput').value;if(code.length!==5)return toast('شناسه باید پنج رقم باشد.');const box=$('#receiveResult'),btn=$('#receiveBtn');btn.disabled=true;btn.textContent='…';box.classList.remove('show');try{const r=await fetch(`?api=1&code=${code}`),d=await r.json();if(!r.ok)throw Error(d.error);if(d.kind==='text'){box.innerHTML='متن دریافت‌شده:<div class="result-text"></div><button class="primary" id="copyBtn" style="margin-top:5px">کپی متن</button>';box.querySelector('.result-text').textContent=d.text;$('#copyBtn').onclick=async()=>{await navigator.clipboard.writeText(d.text);toast('متن کپی شد.')}}else{box.innerHTML=`فایل آماده دریافت است:<br><strong>${escapeHtml(d.name)}</strong> <span class="helper">(${(d.size/1048576).toFixed(2)} مگابایت)</span><br><a class="download" href="?api=1&code=${code}&download=1">دانلود فایل ↓</a>`}box.classList.add('show')}catch(e){toast(e.message||'دریافت انجام نشد.')}finally{btn.disabled=false;btn.textContent='دریافت'}};function escapeHtml(s){return s.replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
</script></body></html>

