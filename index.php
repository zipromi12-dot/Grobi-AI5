<?php
// === КОНФИГУРАЦИЯ ===
ini_set('display_errors', 0);
$token = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0";
$adminId = 7640692963; // Твой ID (Разработчик)
$api = "https://api.telegram.org/bot$token";
$supportChat = "@Grobi_Support";

// --- SUPABASE НАСТРОЙКИ ---
$sbUrl = "https://vqpurtindyaiwjgreqdt.supabase.co/rest/v1/bot_storage";
$sbKey = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InZxcHVydGluZHlhaXdqZ3JlcWR0Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM5MjA2NzksImV4cCI6MjA4OTQ5NjY3OX0.pRR7P3quZ7cX5EYZmHOxnx4C1gp9gMQuoMzNFa-lwM4";

// --- ФУНКЦИИ ОБЛАКА ---
function load_data($id) {
    global $sbUrl, $sbKey;
    $ch = curl_init("$sbUrl?id=eq.$id&select=data");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["apikey: $sbKey", "Authorization: Bearer $sbKey"]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res[0]['data'] ?? [];
}

function save_data($id, $data) {
    global $sbUrl, $sbKey;
    $ch = curl_init($sbUrl);
    $payload = json_encode(["id" => $id, "data" => $data]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["apikey: $sbKey", "Authorization: Bearer $sbKey", "Content-Type: application/json", "Prefer: resolution=merge-duplicates"]);
    curl_exec($ch);
    curl_close($ch);
}

function getAllAdmins() {
    global $sbUrl, $sbKey;
    $ch = curl_init("$sbUrl?select=id,data");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["apikey: $sbKey", "Authorization: Bearer $sbKey"]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res ?: [];
}

function send($chatId, $text, $kb = null, $replyId = null) {
    global $api;
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($kb) $data['reply_markup'] = json_encode($kb);
    if ($replyId) $data['reply_to_message_id'] = $replyId;
    return file_get_contents($api . "/sendMessage?" . http_build_query($data));
}

// === ОБРАБОТКА ОБНОВЛЕНИЙ ===
$update = json_decode(file_get_contents("php://input"), true);
$msg = $update['message'] ?? null;
$cb = $update['callback_query'] ?? null;

// Обработка кнопок
if ($cb) {
    $chatId = $cb['message']['chat']['id'];
    $data = $cb['data'];
    if (strpos($data, 'pin_') === 0) {
        $tId = str_replace(['pin_notify_', 'pin_silent_'], '', $data);
        $silent = (strpos($data, 'silent') !== false);
        file_get_contents($api . "/pinChatMessage?chat_id=$chatId&message_id=$tId&disable_notification=" . ($silent ? 'true' : 'false'));
        file_get_contents($api . "/answerCallbackQuery?callback_query_id=".$cb['id']."&text=Закреплено!");
        file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$cb['message']['message_id']);
    }
    exit;
}

if (!$msg) exit;
$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$text = $msg['text'] ?? '';
$reply = $msg['reply_to_message'] ?? null;

// --- ОПРЕДЕЛЕНИЕ РОЛИ ---
$uData = load_data("u_$userId");
$uData['name'] = $msg['from']['first_name'];

// Проверка на Владельца через Telegram API
$chatMember = json_decode(file_get_contents($api . "/getChatMember?chat_id=$chatId&user_id=$userId"), true);
$isCreator = ($chatMember['result']['status'] ?? '') === 'creator';

if ($userId == $adminId) {
    $myRank = 6; // Разработчик
    $rankName = "Разработчик 🛠";
} elseif ($isCreator) {
    $myRank = 5;
    $rankName = "Владелец чата 👑";
} else {
    $myRank = (int)($uData['ranks'][$chatId] ?? 0);
    $rankNames = [0=>"Пользователь", 1=>"Модератор", 2=>"Ст. Модератор", 3=>"Администратор", 4=>"Зам. Владельца", 5=>"Владелец"];
    $rankName = $rankNames[$myRank] ?? "Пользователь";
}

// Сохраняем стат
$uData['stats']['total'] = ($uData['stats']['total'] ?? 0) + 1;
save_data("u_$userId", $uData);

// === КОМАНДЫ ===

// 1. ПИНГ
if ($text == '/ping') {
    $load = sys_getloadavg();
    $status = round((100 - ($load[0] * 10)), 2);
    $res = "📶 <b>Статус:</b> " . ($status > 100 ? 100 : $status) . "%\n";
    $res .= "👑 Твой ранг: <b>$rankName</b>\n";
    $res .= "🧠 Память: " . round(memory_get_usage()/1024/1024, 2) . " MB";
    send($chatId, $res);
}

// 2. СПИСОК АДМИНОВ
if ($text == '/admin') {
    $all = getAllAdmins();
    $list = "👑 <b>Администрация чата:</b>\n\n";
    $found = false;
    foreach ($all as $item) {
        $uid = str_replace('u_', '', $item['id']);
        if (!is_numeric($uid)) continue;
        $r = ($uid == $adminId) ? 6 : ($item['data']['ranks'][$chatId] ?? 0);
        
        if ($r > 0) {
            $label = ($r == 6) ? "Разработчик" : "Ранг $r";
            $list .= "• " . ($item['data']['name'] ?? 'Юзер') . " [<code>$uid</code>] — <b>$label</b>\n";
            $found = true;
        }
    }
    if (!$found) $list .= "<i>Админы еще не назначены.</i>";
    send($chatId, $list);
}

// 3. ХЕЛП
if ($text == '/help') {
    $h = "📖 <b>Команды бота:</b>\n\n";
    $h .= "👤 <b>Для всех:</b>\n/info — Статистика сообщений\n/admin — Список модераторов\n/ping — Состояние бота\n\n";
    $h .= "🛡 <b>Для админов (1+):</b>\n/del — Удалить (в ответ)\n/pin — Закрепить (в ответ)\n/stop_user — Игнор (в ответ)\n\n";
    $h .= "👑 <b>Для владельца:</b>\n/rank [1-5] — Выдать права (в ответ)\n\n";
    $h .= "🆘 Поддержка: $supportChat";
    send($chatId, $h);
}

// 4. УДАЛЕНИЕ
if ($text == '/del' && $reply && $myRank >= 1) {
    file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$reply['message_id']);
    file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$msg['message_id']);
}

// 5. ЗАКРЕП
if ($text == '/pin' && $reply && $myRank >= 1) {
    $kb = ['inline_keyboard' => [[
        ['text' => '🔔 С уведомлением', 'callback_data' => 'pin_notify_'.$reply['message_id']],
        ['text' => '🔕 Тихо', 'callback_data' => 'pin_silent_'.$reply['message_id']]
    ]]];
    send($chatId, "📌 Как закрепим сообщение?", $kb, $msg['message_id']);
}

// 6. СТОП-ЮЗЕР
if ($text == '/stop_user' && $reply && $myRank >= 1) {
    $ign = load_data("ign_$chatId");
    $ign[$reply['from']['id']] = $userId;
    save_data("ign_$chatId", $ign);
    send($chatId, "🚫 Пользователю запрещено беспокоить вас.");
}

// 7. ИНФО
if (strpos($text, '/info') === 0) {
    $tId = $reply ? $reply['from']['id'] : $userId;
    $tData = load_data("u_$tId");
    $total = $tData['stats']['total'] ?? 0;
    send($chatId, "📊 <b>Инфо: " . ($reply ? $reply['from']['first_name'] : $msg['from']['first_name']) . "</b>\nID: <code>$tId</code>\nСообщений: <b>$total</b>");
}

// 8. ВЫДАЧА РАНГА
if (preg_match('/^\/rank\s+(\d+)/', $text, $m) && $reply && $myRank >= 5) {
    $tId = $reply['from']['id'];
    $newR = (int)$m[1];
    $tData = load_data("u_$tId");
    $tData['ranks'][$chatId] = $newR;
    $tData['name'] = $reply['from']['first_name'];
    save_data("u_$tId", $tData);
    send($chatId, "✅ Пользователю <b>".$tData['name']."</b> выдан ранг $newR");
}
