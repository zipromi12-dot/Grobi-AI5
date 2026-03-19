<?php
// === КОНФИГУРАЦИЯ ===
ini_set('display_errors', 0);
$token = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0";
$adminId = 7640692963; 
$api = "https://api.telegram.org/bot$token";

// --- SUPABASE НАСТРОЙКИ ---
$sbUrl = "https://vqpurtindyaiwjgreqdt.supabase.co/rest/v1/bot_storage";
$sbKey = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InZxcHVydGluZHlhaXdqZ3JlcWR0Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM5MjA2NzksImV4cCI6MjA4OTQ5NjY3OX0.pRR7P3quZ7cX5EYZmHOxnx4C1gp9gMQuoMzNFa-lwM4";

// --- УЛУЧШЕННАЯ ФУНКЦИЯ ОБЛАКА ---
function sb_req($method, $id = null, $data = null) {
    global $sbUrl, $sbKey;
    $url = $id ? "$sbUrl?id=eq." . urlencode($id) : $sbUrl;
    
    // Если это GET запрос без ID, получаем все данные
    if ($method == "GET" && !$id) $url .= "?select=id,data";
    elseif ($method == "GET" && $id) $url .= "&select=data";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $headers = ["apikey: $sbKey", "Authorization: Bearer $sbKey"];

    if ($method == "POST" || $data) {
        $payload = json_encode($data);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $headers[] = "Content-Type: application/json";
        $headers[] = "Prefer: resolution=merge-duplicates";
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function send($chatId, $text, $kb = null, $replyId = null) {
    global $api;
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($kb) $data['reply_markup'] = json_encode($kb);
    if ($replyId) $data['reply_to_message_id'] = $replyId;
    $url = $api . "/sendMessage?" . http_build_query($data);
    return file_get_contents($url);
}

// === ОБРАБОТКА ОБНОВЛЕНИЯ ===
$content = file_get_contents("php://input");
$update = json_decode($content, true);
$msg = $update['message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$text = $msg['text'] ?? '';
$reply = $msg['reply_to_message'] ?? null;
$name = $msg['from']['first_name'];

// --- ЗАГРУЗКА ДАННЫХ ---
$uDataRaw = sb_req("GET", "u_$userId");
$uData = $uDataRaw[0]['data'] ?? [];

$chatConfRaw = sb_req("GET", "conf_$chatId");
$chatConf = $chatConfRaw[0]['data'] ?? [];

$isDev = ($userId == $adminId);

$globalDataRaw = sb_req("GET", "global_config");
$globalData = $globalDataRaw[0]['data'] ?? [];

// --- ГЛОБАЛЬНЫЙ АНТИСПАМ ---
if (isset($globalData['spammers'][$userId])) {
    file_get_contents($api . "/banChatMember?chat_id=$chatId&user_id=$userId");
    send($chatId, "🚫 <b>GLOBAL BAN:</b> Юзер <code>$userId</code> в спам-базе.\nПричина: " . $globalData['spammers'][$userId]['reason']);
    exit;
}

// --- ОПРЕДЕЛЕНИЕ РАНГА ---
$chatMember = json_decode(file_get_contents($api . "/getChatMember?chat_id=$chatId&user_id=$userId"), true);
$status = $chatMember['result']['status'] ?? '';
$isCreator = ($status === 'creator');

if ($isDev) $myRank = 6;
elseif ($isCreator) $myRank = 5;
else $myRank = (int)($uData['ranks'][$chatId] ?? 0);

// --- STOP-USER ЗАЩИТА ---
$ignRaw = sb_req("GET", "ign_$chatId");
$ignored = $ignRaw[0]['data'] ?? [];
if ($reply && isset($ignored[$reply['from']['id']]) && $ignored[$reply['from']['id']] == $userId) {
    file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$msg['message_id']);
    exit;
}

// --- ФИЛЬТР ССЫЛОК ---
if ($myRank < 1 && (preg_match('/(https?:\/\/[^\s]+)/', $text) || preg_match('/(t\.me\/[^\s]+)/', $text) || preg_match('/@[^\s]+/', $text))) {
    $allowed = false;
    if (isset($chatConf['whitelist'])) {
        foreach ($chatConf['whitelist'] as $link) {
            if (strpos($text, $link) !== false) { $allowed = true; break; }
        }
    }
    if (!$allowed) {
        file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$msg['message_id']);
        send($chatId, "⚠️ <b>$name</b>, ссылки запрещены!");
        exit;
    }
}

// === КОМАНДЫ ===

// 1. HELP
if (strpos($text, '/help') === 0) {
    $h = "📖 <b>МЕНЮ КОМАНД</b>\n\n";
    $h .= "👤 <b>ОБЩИЕ:</b>\n/info, /ping, /admin, /support\n\n";
    $h .= "🛡 <b>МОДЕРАЦИЯ (1+):</b>\n/del, /mute, /unmute, /warn, /unwarn, /ban\n\n";
    $h .= "📂 <b>СПИСКИ:</b>\n/mutelist, /warnlist, /spamlist, /stop_list\n\n";
    $h .= "⚙️ <b>НАСТРОЙКИ (4+):</b>\n/rank, /dc, /whitelist, /unstop\n\n";
    $h .= "⚒ <b>AGENTS:</b>\n/agent, /gg, /set_support";
    send($chatId, $h);
}

// 2. СПИСКИ
if ($text == '/warnlist') {
    $all = sb_req("GET");
    $out = "⚠️ <b>Варны в чате:</b>\n";
    $found = false;
    foreach($all as $it) {
        $wid = str_replace('u_', '', $it['id']);
        if (isset($it['data']['warns'][$chatId]) && $it['data']['warns'][$chatId] > 0) {
            $out .= "• " . ($it['data']['name'] ?? "Юзер") . " [<code>$wid</code>]: <b>".$it['data']['warns'][$chatId]."/3</b>\n";
            $found = true;
        }
    }
    send($chatId, $found ? $out : "Варнов нет.");
}

if ($text == '/spamlist') {
    $out = "🚫 <b>Глобальные спамеры:</b>\n";
    if (empty($globalData['spammers'])) $out .= "Список пуст.";
    else {
        foreach ($globalData['spammers'] as $sid => $info) {
            $out .= "• <code>$sid</code> | " . $info['reason'] . " (by " . $info['by'] . ")\n";
        }
    }
    send($chatId, $out);
}

if ($text == '/stop_list') {
    $out = "🛑 <b>Stop-User Список:</b>\n";
    if (empty($ignored)) $out .= "Никто не в игноре.";
    else {
        foreach ($ignored as $target => $owner) {
            $out .= "• Юзер <code>$target</code> игнорируется <code>$owner</code>\n";
        }
    }
    send($chatId, $out);
}

// 3. УПРАВЛЕНИЕ AGENT / GG
if (strpos($text, '/gg') === 0 && ($isDev || ($uData['is_agent'] ?? false))) {
    $parts = explode(' ', $text, 3);
    $targetId = $reply ? $reply['from']['id'] : ($parts[1] ?? null);
    $reason = $parts[2] ?? ($reply ? ($parts[1] ?? "Спам") : "Спам");
    
    if ($targetId && is_numeric($targetId)) {
        $globalData['spammers'][$targetId] = ['reason' => $reason, 'by' => $userId];
        sb_req("POST", "global_config", ["id" => "global_config", "data" => $globalData]);
        file_get_contents($api . "/banChatMember?chat_id=$chatId&user_id=$targetId");
        send($chatId, "⛓ <b>GLOBAL BAN:</b> Юзер <code>$targetId</code> забанен везде.\nПричина: $reason");
    } else {
        send($chatId, "❌ Используй /gg [id] [причина] или ответом.");
    }
}

if ($text == '/agent') {
    if (($uData['is_agent'] ?? false) || $isDev) {
        $num = $uData['agent_num'] ?? "DEV-01";
        $m = "<a href='tg://user?id=$userId'>$name</a>";
        send($chatId, "🛡 <b>АГЕНТ #$num</b>\nСотрудник $m подтвердил личность.\nСтатус: <b>Верифицирован</b>");
    }
}

// 4. WHITELIST
if (preg_match('/^\/whitelist\s+([^\s]+)/', $text, $m) && $myRank >= 4) {
    $chatConf['whitelist'][] = $m[1];
    sb_req("POST", "conf_$chatId", ["id" => "conf_$chatId", "data" => $chatConf]);
    send($chatId, "✅ Ссылка <code>".$m[1]."</code> разрешена.");
}

// 5. МОДЕРАЦИЯ (ОТВЕТОМ)
if ($reply && $myRank >= 1) {
    $tId = $reply['from']['id'];
    if ($text == '/del') {
        file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$reply['message_id']);
        file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$msg['message_id']);
    }
    if ($text == '/warn') {
        $tDataRaw = sb_req("GET", "u_$tId");
        $tData = $tDataRaw[0]['data'] ?? [];
        $tData['warns'][$chatId] = ($tData['warns'][$chatId] ?? 0) + 1;
        $tData['name'] = $reply['from']['first_name'];
        if ($tData['warns'][$chatId] >= 3) {
            file_get_contents($api . "/banChatMember?chat_id=$chatId&user_id=$tId");
            $tData['warns'][$chatId] = 0;
            send($chatId, "🚫 3/3 варна. Бан юзеру.");
        } else {
            send($chatId, "⚠️ Варн: " . $tData['warns'][$chatId] . "/3");
        }
        sb_req("POST", "u_$tId", ["id" => "u_$tId", "data" => $tData]);
    }
}

// 6. ПИНГ И ИНФО
if ($text == '/ping') {
    $load = sys_getloadavg();
    send($chatId, "📶 <b>Статус:</b> Online\nТвой ранг: <b>$myRank ($status)</b>");
}

if ($text == '/admin') {
    $all = sb_req("GET");
    $out = "🛡 <b>Администрация:</b>\n";
    $names = [6=>"РАЗРАБОТЧИК", 5=>"ВЛАДЕЛЬЦЫ", 1=>"МОДЕРАТОРЫ"];
    foreach ([6,5,4,3,2,1] as $lvl) {
        $found = false; $stars = str_repeat("⭐", ($lvl > 5 ? 5 : $lvl));
        $tmp = "";
        foreach ($all as $it) {
            $rid = str_replace('u_', '', $it['id']);
            $r = ($rid == $adminId) ? 6 : ($it['data']['ranks'][$chatId] ?? 0);
            if ($r == $lvl) { $tmp .= "└ " . ($it['data']['name'] ?? "Юзер") . "\n"; $found = true; }
        }
        if ($found) $out .= "\n<b>$stars " . ($names[$lvl] ?? "РАНГ $lvl") . "</b>\n" . $tmp;
    }
    send($chatId, $out);
}

// СОХРАНЕНИЕ СТАТИСТИКИ
$uData['name'] = $name;
$uData['stats']['total'] = ($uData['stats']['total'] ?? 0) + 1;
sb_req("POST", "u_$userId", ["id" => "u_$userId", "data" => $uData]);
