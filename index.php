<?php
// === КОНФИГУРАЦИЯ СИСТЕМЫ ===
ini_set('display_errors', 0);
$token = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0";
$adminId = 7640692963; 
$api = "https://api.telegram.org/bot$token";
$version = "2.5.0-STABLE";

// --- SUPABASE ENGINE ---
function sb_req($method, $id = null, $data = null) {
    global $sbUrl, $sbKey;
    $sbUrl = "https://vqpurtindyaiwjgreqdt.supabase.co/rest/v1/bot_storage";
    $sbKey = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InZxcHVydGluZHlhaXdqZ3JlcWR0Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM5MjA2NzksImV4cCI6MjA4OTQ5NjY3OX0.pRR7P3quZ7cX5EYZmHOxnx4C1gp9gMQuoMzNFa-lwM4";
    
    $url = $id ? "$sbUrl?id=eq." . urlencode($id) : $sbUrl;
    if ($method == "GET") $url .= ($id ? "&select=data" : "?select=id,data");

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $headers = ["apikey: $sbKey", "Authorization: Bearer $sbKey"];

    if ($method == "POST") {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
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

// === ЛОГИКА ОБРАБОТКИ ===
$update = json_decode(file_get_contents("php://input"), true);

// 1. Приветствие новых участников
if (isset($update['message']['new_chat_members'])) {
    $cId = $update['message']['chat']['id'];
    $conf = sb_req("GET", "conf_$cId")[0]['data'] ?? [];
    if (isset($conf['welcome'])) {
        $user = $update['message']['new_chat_members'][0]['first_name'];
        $text = str_replace("{name}", $user, $conf['welcome']);
        send($cId, $text);
    }
    exit;
}

$msg = $update['message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$text = $msg['text'] ?? '';
$reply = $msg['reply_to_message'] ?? null;
$name = $msg['from']['first_name'];

// --- ЗАГРУЗКА СОСТОЯНИЯ ---
$uData = sb_req("GET", "u_$userId")[0]['data'] ?? [];
$chatConf = sb_req("GET", "conf_$chatId")[0]['data'] ?? [];
$globalData = sb_req("GET", "global_config")[0]['data'] ?? [];
$isDev = ($userId == $adminId);

// Ранг и права
$chatMember = json_decode(file_get_contents($api . "/getChatMember?chat_id=$chatId&user_id=$userId"), true);
$status = $chatMember['result']['status'] ?? '';
if ($isDev) $myRank = 6;
elseif ($status === 'creator') $myRank = 5;
else $myRank = (int)($uData['ranks'][$chatId] ?? 0);

// Глобальный бан
if (isset($globalData['spammers'][$userId])) {
    file_get_contents($api . "/banChatMember?chat_id=$chatId&user_id=$userId");
    exit;
}

// === КОМАНДЫ ===

// ПИНГ (Расширенный)
if ($text == '/ping') {
    $start = microtime(true);
    $dbStatus = (!empty($globalData)) ? "Connected ✅" : "Error ❌";
    $latency = round((microtime(true) - $start) * 1000);
    $p = "📶 <b>SYSTEM STATUS</b>\n";
    $p .= "━━━━━━━━━━━━━━━\n";
    $p .= "🛰 Latency: <code>{$latency}ms</code>\n";
    $p .= "🗄 Database: <code>$dbStatus</code>\n";
    $p .= "🛠 Version: <code>$version</code>\n";
    $p .= "👑 Your Rank: <b>$myRank</b>";
    send($chatId, $p);
}

// ПРАВИЛА И ПРИВЕТСТВИЕ
if (strpos($text, '/set_rules') === 0 && $myRank >= 4) {
    $chatConf['rules'] = substr($text, 11);
    sb_req("POST", "conf_$chatId", ["id" => "conf_$chatId", "data" => $chatConf]);
    send($chatId, "✅ Правила чата обновлены.");
}

if ($text == '/rules') {
    $r = $chatConf['rules'] ?? "Правила еще не установлены.";
    send($chatId, "📜 <b>ПРАВИЛА ЧАТА:</b>\n\n$r");
}

if (strpos($text, '/set_welcome') === 0 && $myRank >= 4) {
    $chatConf['welcome'] = substr($text, 13);
    sb_req("POST", "conf_$chatId", ["id" => "conf_$chatId", "data" => $chatConf]);
    send($chatId, "✅ Приветствие обновлено. Используйте {name} для тега юзера.");
}

// СИСТЕМА ЗАЯВОК (SEND_SUPPORT)
if (strpos($text, '/send_support') === 0) {
    $reason = substr($text, 14);
    if (empty($reason)) {
        send($chatId, "❌ Опишите причину заявки после команды.");
    } else {
        $ticket = "🎫 <b>НОВАЯ ЗАЯВКА</b>\n";
        $ticket .= "👤 От: <code>$userId</code> ($name)\n";
        $ticket .= "📍 Чат: <code>$chatId</code>\n";
        $ticket .= "📝 Суть: $reason";
        
        // Отправка всем агентам и админу
        $all = sb_req("GET");
        foreach($all as $it) {
            $aid = str_replace('u_', '', $it['id']);
            if (($it['data']['is_agent'] ?? false) || $aid == $adminId) {
                send($aid, $ticket);
            }
        }
        send($chatId, "✅ Ваша заявка отправлена персоналу проекта.");
    }
}

// МОДЕРАЦИЯ (ОТВЕТОМ)
if ($reply && $myRank >= 1) {
    $tId = $reply['from']['id'];
    
    if ($text == '/kick') {
        file_get_contents($api . "/unbanChatMember?chat_id=$chatId&user_id=$tId");
        send($chatId, "👢 Пользователь кикнут.");
    }
    
    if ($text == '/ban' && $myRank >= 2) {
        file_get_contents($api . "/banChatMember?chat_id=$chatId&user_id=$tId");
        send($chatId, "🔨 Пользователь забанен.");
    }
    
    if ($text == '/unban' && $myRank >= 3) {
        file_get_contents($api . "/unbanChatMember?chat_id=$chatId&user_id=$tId&only_if_banned=true");
        send($chatId, "🔓 Разбанен.");
    }

    if ($text == '/unmute' && $myRank >= 1) {
        file_get_contents($api . "/restrictChatMember?chat_id=$chatId&user_id=$tId&permissions=".urlencode(json_encode(['can_send_messages'=>true,'can_send_media_messages'=>true,'can_send_other_messages'=>true,'can_add_web_page_previews'=>true])));
        send($chatId, "🔊 Ограничения сняты.");
    }
}

// ИНФО О ЮЗЕРЕ
if (strpos($text, '/info') === 0) {
    $targetId = $reply ? $reply['from']['id'] : $userId;
    $targetData = sb_req("GET", "u_$targetId")[0]['data'] ?? [];
    $tr = ($targetId == $adminId) ? 6 : ($targetData['ranks'][$chatId] ?? 0);
    $out = "👤 <b>USER INFO</b>\n";
    $out .= "ID: <code>$targetId</code>\n";
    $out .= "Rank: <b>$tr</b>\n";
    $out .= "Warns: <b>".($targetData['warns'][$chatId] ?? 0)."/3</b>\n";
    $out .= "Is Agent: " . (($targetData['is_agent'] ?? false) ? "Yes ✅" : "No ❌");
    send($chatId, $out);
}

// ОБНОВЛЕННЫЙ HELP
if (strpos($text, '/help') === 0) {
    $h = "📖 <b>GROBI COMMANDS</b>\n\n";
    $h .= "📍 <b>Main:</b> /rules, /info, /ping, /admin\n";
    $h .= "🛡 <b>Mod:</b> /del, /kick, /ban, /unban, /mute, /unmute, /warn, /unwarn\n";
    $h .= "🎫 <b>Support:</b> /send_support [текст], /agent, /support\n";
    $h .= "⚙️ <b>Config:</b> /set_rules, /set_welcome, /rank, /dc, /whitelist\n";
    $h .= "⛓ <b>Global:</b> /gg [id] [reason], /ungg [id], /spamlist";
    send($chatId, $h);
}

// СОХРАНЕНИЕ СТАТИСТИКИ
$uData['name'] = $name;
$uData['stats']['total'] = ($uData['stats']['total'] ?? 0) + 1;
sb_req("POST", "u_$userId", ["id" => "u_$userId", "data" => $uData]);
