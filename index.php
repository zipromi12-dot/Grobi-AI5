<?php
// === КОНФИГУРАЦИЯ ===
ini_set('display_errors', 0);
$token = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0";
$adminId = 7640692963; 
$api = "https://api.telegram.org/bot$token";

// --- SUPABASE НАСТРОЙКИ ---
$sbUrl = "https://vqpurtindyaiwjgreqdt.supabase.co/rest/v1/bot_storage";
$sbKey = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InZxcHVydGluZHlhaXdqZ3JlcWR0Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM5MjA2NzksImV4cCI6MjA4OTQ5NjY3OX0.pRR7P3quZ7cX5EYZmHOxnx4C1gp9gMQuoMzNFa-lwM4";

// --- ФУНКЦИИ ОБЛАКА ---
function sb_req($method, $id = null, $data = null) {
    global $sbUrl, $sbKey;
    $url = $id ? "$sbUrl?id=eq.$id" : $sbUrl;
    if (!$id && !$data) $url .= "?select=id,data";
    elseif ($id && !$data) $url .= "&select=data";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $headers = ["apikey: $sbKey", "Authorization: Bearer $sbKey"];

    if ($data) {
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
    return file_get_contents($api . "/sendMessage?" . http_build_query($data));
}

// === ОБРАБОТКА ОБНОВЛЕНИЯ ===
$update = json_decode(file_get_contents("php://input"), true);
$msg = $update['message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$text = $msg['text'] ?? '';
$reply = $msg['reply_to_message'] ?? null;

// --- ЗАГРУЗКА ДАННЫХ ---
$uData = sb_req("GET", "u_$userId")[0]['data'] ?? [];
$chatConf = sb_req("GET", "conf_$chatId")[0]['data'] ?? [];
$isDev = ($userId == $adminId);
$globalData = sb_req("GET", "global_config")[0]['data'] ?? [];

// Глобальный Антиспам
if (isset($globalData['spammers'][$userId])) {
    file_get_contents($api . "/banChatMember?chat_id=$chatId&user_id=$userId");
    send($chatId, "🚫 <b>GLOBAL BAN:</b> Юзер <code>$userId</code> в спам-базе.\nПричина: " . $globalData['spammers'][$userId]['reason']);
    exit;
}

// Определение ранга
$chatMember = json_decode(file_get_contents($api . "/getChatMember?chat_id=$chatId&user_id=$userId"), true);
$isCreator = ($chatMember['result']['status'] ?? '') === 'creator';
if ($isDev) $myRank = 6;
elseif ($isCreator) $myRank = 5;
else $myRank = (int)($uData['ranks'][$chatId] ?? 0);

// Stop-User защита
$ignored = sb_req("GET", "ign_$chatId")[0]['data'] ?? [];
if ($reply && isset($ignored[$reply['from']['id']]) && $ignored[$reply['from']['id']] == $userId) {
    file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$msg['message_id']);
    exit;
}

// Фильтр ссылок
if ($myRank < 1 && (preg_match('/(https?:\/\/[^\s]+)/', $text) || preg_match('/(t\.me\/[^\s]+)/', $text) || preg_match('/@[^\s]+/', $text))) {
    $allowed = false;
    foreach (($chatConf['whitelist'] ?? []) as $link) {
        if (strpos($text, $link) !== false) { $allowed = true; break; }
    }
    if (!$allowed) {
        file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$msg['message_id']);
        send($chatId, "⚠️ <b>$name</b>, ссылки в этом чате запрещены!");
        exit;
    }
}

// === КОМАНДЫ ===

// 1. HELP
if ($text == '/help' || strpos($text, '/help@') === 0) {
    $h = "📖 <b>МЕНЮ КОМАНД GROBI</b>\n\n";
    $h .= "👤 <b>ОБЩИЕ:</b>\n/info | /ping | /admin | /support\n\n";
    $h .= "🛡 <b>МОДЕРАЦИЯ:</b>\n/del | /mute | /unmute | /warn | /unwarn | /ban\n\n";
    $h .= "📂 <b>СПИСКИ:</b>\n/mutelist | /warnlist | /banlist | /spamlist | /stop_list\n\n";
    $h .= "⚙️ <b>НАСТРОЙКИ:</b>\n/rank [0-5] | /dc [cmd] [rank] | /whitelist [link]\n\n";
    $h .= "⚒ <b>AGENTS:</b>\n/agent | /gg | /set_support";
    send($chatId, $h);
}

// 2. СПИСКИ (Lits)
if ($text == '/mutelist') {
    $admins = json_decode(file_get_contents($api . "/getChatAdministrators?chat_id=$chatId"), true);
    $out = "🔇 <b>Список мутов:</b>\nБудет выведен список замученных пользователей (через проверку участников).";
    send($chatId, $out);
}

if ($text == '/warnlist') {
    $all = sb_req("GET");
    $out = "⚠️ <b>Варны в этом чате:</b>\n";
    foreach($all as $it) {
        if (isset($it['data']['warns'][$chatId]) && $it['data']['warns'][$chatId] > 0) {
            $out .= "• " . ($it['data']['name'] ?? "Юзер") . " [<code>".str_replace('u_', '', $it['id'])."</code>]: <b>".$it['data']['warns'][$chatId]."/3</b>\n";
        }
    }
    send($chatId, $out);
}

if ($text == '/spamlist') {
    $out = "🚫 <b>Глобальные спамеры:</b>\n";
    foreach (($globalData['spammers'] ?? []) as $sid => $info) {
        $out .= "• <code>$sid</code> | " . $info['reason'] . "\n";
    }
    send($chatId, $out);
}

// 3. GLOBAL BAN (GG)
if (strpos($text, '/gg') === 0 && ($isDev || $uData['is_agent'])) {
    $parts = explode(' ', $text, 3);
    $target = $reply ? $reply['from']['id'] : ($parts[1] ?? null);
    $reason = $parts[2] ?? ($reply ? $parts[1] : "Спам");
    if ($target) {
        $globalData['spammers'][$target] = ['reason' => $reason, 'by' => $userId];
        sb_req("POST", "global_config", ["id" => "global_config", "data" => $globalData]);
        file_get_contents($api . "/banChatMember?chat_id=$chatId&user_id=$target");
        send($chatId, "⛓ <b>GLOBAL BAN:</b> Юзер <code>$target</code> добавлен в спам-базу.");
    }
}

// 4. WHITELIST
if (preg_match('/\/whitelist\s+([^\s]+)/', $text, $m) && $myRank >= 4) {
    $chatConf['whitelist'][] = $m[1];
    sb_req("POST", "conf_$chatId", ["id" => "conf_$chatId", "data" => $chatConf]);
    send($chatId, "✅ Ссылка <b>".$m[1]."</b> добавлена в белый список.");
}

// 5. AGENT CHECK
if ($text == '/agent') {
    if ($uData['is_agent'] || $isDev) {
        $num = $uData['agent_num'] ?? "DEV-01";
        send($chatId, "🛡 <b>АГЕНТ ПОДДЕРЖКИ #$num</b>\nУполномоченный сотрудник <a href='tg://user?id=$userId'>".$msg['from']['first_name']."</a> на связи.");
    }
}

// 6. STOP-USER
if ($text == '/stop_user' && $reply) {
    $ignored[$reply['from']['id']] = $userId;
    sb_req("POST", "ign_$chatId", ["id" => "ign_$chatId", "data" => $ignored]);
    send($chatId, "🛑 <b>STOP:</b> Юзеру запрещено вам отвечать.");
}

if ($text == '/unstop' && $reply) {
    unset($ignored[$reply['from']['id']]);
    sb_req("POST", "ign_$chatId", ["id" => "ign_$chatId", "data" => $ignored]);
    send($chatId, "🔓 Ограничение Stop-User снято.");
}

// 7. МОДЕРАЦИЯ
if ($reply && $myRank >= 1) {
    $tId = $reply['from']['id'];
    if ($text == '/mute') {
        file_get_contents($api . "/restrictChatMember?chat_id=$chatId&user_id=$tId&permissions=".urlencode(json_encode(['can_send_messages'=>false])));
        send($chatId, "🔇 Мут выдан.");
    }
    if ($text == '/unmute') {
        file_get_contents($api . "/restrictChatMember?chat_id=$chatId&user_id=$tId&permissions=".urlencode(json_encode(['can_send_messages'=>true])));
        send($chatId, "🔊 Мут снят.");
    }
}

// Сохранение статистики
$uData['name'] = $msg['from']['first_name'];
$uData['stats']['total'] = ($uData['stats']['total'] ?? 0) + 1;
sb_req("POST", "u_$userId", ["id" => "u_$userId", "data" => $uData]);
