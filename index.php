<?php
// === КОНФИГУРАЦИЯ БОТА ===
ini_set('display_errors', 0);
$token = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0";
$adminId = 7640692963; // Ты (Разработчик)
$api = "https://api.telegram.org/bot$token";
$supportChat = "@Grobi_Support";

// === SUPABASE (ОБЛАЧНАЯ ПАМЯТЬ) ===
$sbUrl = "https://vqpurtindyaiwjgreqdt.supabase.co/rest/v1/bot_storage";
$sbKey = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InZxcHVydGluZHlhaXdqZ3JlcWR0Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM5MjA2NzksImV4cCI6MjA4OTQ5NjY3OX0.pRR7P3quZ7cX5EYZmHOxnx4C1gp9gMQuoMzNFa-lwM4";

// --- ФУНКЦИИ БД ---
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: $sbKey", 
        "Authorization: Bearer $sbKey", 
        "Content-Type: application/json", 
        "Prefer: resolution=merge-duplicates"
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// --- ОТПРАВКА СООБЩЕНИЙ ---
function send($chatId, $text, $kb = null, $replyId = null) {
    global $api;
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($kb) $data['reply_markup'] = json_encode($kb);
    if ($replyId) $data['reply_to_message_id'] = $replyId;
    $url = $api . "/sendMessage?" . http_build_query($data);
    return json_decode(file_get_contents($url), true);
}

// === ОБРАБОТКА ВХОДЯЩИХ ДАННЫХ ===
$content = file_get_contents("php://input");
$update = json_decode($content, true);
$msg = $update['message'] ?? null;
$cb = $update['callback_query'] ?? null;

// Обработка нажатий на кнопки (Закреп)
if ($cb) {
    $chatId = $cb['message']['chat']['id'];
    $data = $cb['data'];
    $msgId = $cb['message']['message_id'];
    if (strpos($data, 'pin_') === 0) {
        $tId = str_replace(['pin_notify_', 'pin_silent_'], '', $data);
        $silent = (strpos($data, 'silent') !== false);
        file_get_contents($api . "/pinChatMessage?chat_id=$chatId&message_id=$tId&disable_notification=" . ($silent ? 'true' : 'false'));
        file_get_contents($api . "/answerCallbackQuery?callback_query_id=".$cb['id']."&text=Закреплено!");
        file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=$msgId");
    }
    exit;
}

if (!$msg) exit;
$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$text = $msg['text'] ?? '';
$reply = $msg['reply_to_message'] ?? null;

// --- СИСТЕМА РАНГОВ ---
function getRank($cId, $uId, $devId) {
    global $api;
    if ($uId == $devId) return 6; // Ранг: Разработчик
    $d = load_data("u_$uId");
    if (isset($d['ranks'][$cId])) return (int)$d['ranks'][$cId];
    
    // Проверка на владельца чата через API Telegram
    $admins = json_decode(file_get_contents($api . "/getChatAdministrators?chat_id=$cId"), true);
    if ($admins['ok']) {
        foreach ($admins['result'] as $a) {
            if ($a['user']['id'] == $uId && $a['status'] == 'creator') return 5;
        }
    }
    return 0;
}
$myRank = getRank($chatId, $userId, $adminId);

// --- ЗАЩИТА /STOP_USER ---
$ignored = load_data("ign_$chatId");
if ($reply && isset($ignored[$reply['from']['id']]) && $ignored[$reply['from']['id']] == $userId) {
    file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$msg['message_id']);
    send($chatId, "⚠️ Сообщение удалено: вам запрещено тегать этого пользователя.");
    exit;
}

// === КОМАНДЫ ===

// 1. /ping — Статус системы
if ($text == '/ping' || strpos($text, '/ping@') === 0) {
    $load = sys_getloadavg();
    $mem = round(memory_get_usage() / 1024 / 1024, 2);
    $status = round((100 - ($load[0] * 10)), 2);
    if ($status > 100) $status = 100; if ($status < 5) $status = 5;
    
    $p = "📶 <b>Статус бота:</b>\n━━━━━━━━━━━━━━━\n";
    $p .= "✅ Работа: <b>Стабильно</b>\n";
    $p .= "⚙️ Нагрузка CPU: <b>{$load[0]}%</b>\n";
    $p .= "🧠 Память: <b>$mem MB</b>\n";
    $p .= "📊 Общий статус: <b>$status%</b>";
    send($chatId, $p);
}

// 2. /help — Справка
if ($text == '/help' || strpos($text, '/help@') === 0) {
    $h = "📖 <b>Справка Grobi Bot:</b>\n\n";
    $h .= "👤 <b>Общие:</b>\n";
    $h .= "/info — твоя статистика\n";
    $h .= "/admin — список администрации\n";
    $h .= "/rules — правила чата\n\n";
    $h .= "🛡 <b>Модерация (Ранг 1+):</b>\n";
    $h .= "/del — удалить сообщение (reply)\n";
    $h .= "/pin — закрепить (reply)\n";
    $h .= "/stop_user — запретить отвечать вам (reply)\n\n";
    $h .= "🆘 <b>Поддержка:</b> $supportChat";
    send($chatId, $h);
}

// 3. /admin — Список админов из базы
if ($text == '/admin') {
    // В облаке мы не можем просто "сканировать папку", поэтому выводим текущего юзера и владельца
    $list = "👑 <b>Администрация:</b>\n";
    $list .= "• " . $msg['from']['first_name'] . " — ранг <b>$myRank</b>\n";
    $list .= "\n<i>Полный список доступен в панели управления Supabase.</i>";
    send($chatId, $list);
}

// 4. /stop_user
if ($text == '/stop_user' && $reply) {
    $ignored[$reply['from']['id']] = $userId;
    save_data("ign_$chatId", $ignored);
    send($chatId, "🚫 Пользователю <b>".$reply['from']['first_name']."</b> запрещено отвечать вам.");
}

// 5. /del
if ($text == '/del' && $reply && $myRank >= 1) {
    file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$reply['message_id']);
    file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$msg['message_id']);
}

// 6. /pin
if ($text == '/pin' && $reply && $myRank >= 1) {
    $kb = ['inline_keyboard' => [[
        ['text' => '🔔 Уведомить', 'callback_data' => 'pin_notify_'.$reply['message_id']],
        ['text' => '🔕 Тихо', 'callback_data' => 'pin_silent_'.$reply['message_id']]
    ]]];
    send($chatId, "📌 Выберите способ закрепа:", $kb, $msg['message_id']);
}

// 7. /rank [число]
if (preg_match('/^\/rank\s+(\d+)/', $text, $m) && $reply && $myRank >= 5) {
    $tId = $reply['from']['id'];
    $newR = (int)$m[1];
    $tData = load_data("u_$tId");
    $tData['ranks'][$chatId] = $newR;
    $tData['name'] = $reply['from']['first_name'];
    save_data("u_$tId", $tData);
    send($chatId, "✅ Пользователю <b>".$tData['name']."</b> выдан ранг <b>$newR</b>.");
}

// 8. /info
if ($text == '/info' || strpos($text, '/info') === 0) {
    $tId = $reply ? $reply['from']['id'] : $userId;
    $tData = load_data("u_$tId");
    $r = getRank($chatId, $tId, $adminId);
    $name = $reply ? $reply['from']['first_name'] : $msg['from']['first_name'];
    
    $i = "📊 <b>Инфо: $name</b>\n";
    $i .= "🆔 ID: <code>$tId</code>\n";
    $i .= "👑 Ранг: <b>$r</b>\n";
    $i .= "💬 Всего сообщений: <b>" . ($tData['stats']['total'] ?? 0) . "</b>";
    send($chatId, $i);
}

// Сбор статистики сообщений
$uData = load_data("u_$userId");
$uData['stats']['total'] = ($uData['stats']['total'] ?? 0) + 1;
save_data("u_$userId", $uData);
