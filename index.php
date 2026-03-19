<?php
// === КОНФИГУРАЦИЯ ===
ini_set('display_errors', 0);
$token = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0";
$adminId = 7640692963; // Главный Разработчик
$api = "https://api.telegram.org/bot$token";

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

function getAllData() {
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

// === ОБРАБОТКА ===
$update = json_decode(file_get_contents("php://input"), true);
$msg = $update['message'] ?? null;
$cb = $update['callback_query'] ?? null;

// Кнопки закрепа
if ($cb) {
    $chatId = $cb['message']['chat']['id'];
    $data = $cb['data'];
    if (strpos($data, 'pin_') === 0) {
        $tId = str_replace(['pin_notify_', 'pin_silent_'], '', $data);
        $silent = (strpos($data, 'silent') !== false);
        file_get_contents($api . "/pinChatMessage?chat_id=$chatId&message_id=$tId&disable_notification=" . ($silent ? 'true' : 'false'));
        file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$cb['message']['message_id']);
    }
    exit;
}

if (!$msg) exit;
$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$text = $msg['text'] ?? '';
$reply = $msg['reply_to_message'] ?? null;

// --- ПРОВЕРКА РОЛИ ---
$uData = load_data("u_$userId");
$chatConfig = load_data("conf_$chatId");
$isDev = ($userId == $adminId);

// Проверка на Владельца чата в TG
$chatMember = json_decode(file_get_contents($api . "/getChatMember?chat_id=$chatId&user_id=$userId"), true);
$isCreator = ($chatMember['result']['status'] ?? '') === 'creator';

if ($isDev) { $myRank = 6; }
elseif ($isCreator) { $myRank = 5; }
else { $myRank = (int)($uData['ranks'][$chatId] ?? 0); }

// Команда доступа (DC)
function can($cmd, $myRank, $config) {
    $min = $config['dc'][$cmd] ?? 1; // По умолчанию ранг 1
    return ($myRank >= $min);
}

// --- СИСТЕМА ИГНОРА (STOP_USER) ---
$ignored = load_data("ign_$chatId");
if ($reply && isset($ignored[$reply['from']['id']]) && $ignored[$reply['from']['id']] == $userId) {
    file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$msg['message_id']);
    send($chatId, "🚫 <b>STOP-USER:</b> Сообщение удалено. Пользователю запрещено контактировать с вами.");
    exit;
}

// === КОМАНДЫ ===

// 1. /admin - Список с ячейками и звездами
if ($text == '/admin') {
    $all = getAllData();
    $ranks = [6 => [], 5 => [], 4 => [], 3 => [], 2 => [], 1 => []];
    foreach ($all as $item) {
        $uid = str_replace('u_', '', $item['id']);
        if (!is_numeric($uid)) continue;
        $r = ($uid == $adminId) ? 6 : ($item['data']['ranks'][$chatId] ?? 0);
        if ($r > 0) $ranks[$r][] = $item['data']['name'] . " (<code>$uid</code>)";
    }
    
    $out = "🛡 <b>Администрация чата</b>\n━━━━━━━━━━━━━━━\n";
    $names = [6=>"РАЗРАБОТЧИК", 5=>"ВЛАДЕЛЬЦЫ", 4=>"ЗАМЕСТИТЕЛИ", 3=>"АДМИНИСТРАЦИЯ", 2=>"СТ. МОДЕРАТОРЫ", 1=>"МОДЕРАТОРЫ"];
    foreach ($ranks as $lvl => $users) {
        if (empty($users)) continue;
        $stars = str_repeat("⭐", ($lvl > 5 ? 5 : $lvl));
        $out .= "\n<b>$stars " . $names[$lvl] . "</b>\n";
        foreach ($users as $u) $out .= "└ $u\n";
    }
    send($chatId, $out);
}

// 2. /dc [cmd] [rank] - Настройка доступа
if (preg_match('/^\/dc\s+(\w+)\s+(\d+)/', $text, $m) && $myRank >= 5) {
    $cmdName = $m[1]; $minR = (int)$m[2];
    $chatConfig['dc'][$cmdName] = $minR;
    save_data("conf_$chatId", $chatConfig);
    send($chatId, "⚙️ Команда <b>/$cmdName</b> теперь доступна с ранга <b>$minR</b>");
}

// 3. /support - Вызов сотрудника
if ($text == '/support') {
    $ticketId = rand(1000, 9999);
    send($adminId, "📩 <b>Новый тикет #$ticketId</b>\nОт: " . $msg['from']['first_name'] . "\nЧат: $chatId\nЮзер: <code>$userId</code>");
    send($chatId, "✅ Запрос отправлен. С вами свяжется агент поддержки в ЛС.");
}

// 4. /set_support - Назначить агента (Только Разработчик)
if ($text == '/set_support' && $reply && $isDev) {
    $targetId = $reply['from']['id'];
    $tData = load_data("u_$targetId");
    $tData['is_agent'] = true;
    save_data("u_$targetId", $tData);
    send($chatId, "🛠 Пользователь назначен Агентом Поддержки.");
}

// 5. /agent - Проверка сотрудника
if ($text == '/agent') {
    if ($uData['is_agent'] || $isDev) {
        send($chatId, "🛡 <b>ОФИЦИАЛЬНЫЙ ОТВЕТ:</b>\nДанный пользователь является верифицированным сотрудником <b>Grobi Support</b>.");
    }
}

// 6. МОДЕРАЦИЯ (Mute, Ban, Warn)
if ($reply && $myRank >= 1) {
    $targetId = $reply['from']['id'];
    if ($text == '/del' && can('del', $myRank, $chatConfig)) {
        file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$reply['message_id']);
    }
    if (strpos($text, '/mute') === 0 && can('mute', $myRank, $chatConfig)) {
        preg_match('/\/mute\s+(\d+)/', $text, $mt);
        $time = (isset($mt[1]) ? time() + ($mt[1] * 60) : time() + 3600);
        file_get_contents($api . "/restrictChatMember?chat_id=$chatId&user_id=$targetId&until_date=$time&permissions=".urlencode(json_encode(['can_send_messages'=>false])));
        send($chatId, "🔇 Пользователь замучен на " . ($mt[1] ?? 60) . " мин.");
    }
    if ($text == '/unmute' && can('mute', $myRank, $chatConfig)) {
        file_get_contents($api . "/restrictChatMember?chat_id=$chatId&user_id=$targetId&permissions=".urlencode(json_encode(['can_send_messages'=>true,'can_send_media_messages'=>true,'can_send_polls'=>true,'can_send_other_messages'=>true,'can_add_web_page_previews'=>true])));
        send($chatId, "🔊 Голос возвращен.");
    }
    if ($text == '/ban' && can('ban', $myRank, $chatConfig)) {
        file_get_contents($api . "/banChatMember?chat_id=$chatId&user_id=$targetId");
        send($chatId, "🔨 <b>BAN:</b> Пользователь изгнан навсегда.");
    }
    if ($text == '/warn' && can('warn', $myRank, $chatConfig)) {
        $tData = load_data("u_$targetId");
        $tData['warns'][$chatId] = ($tData['warns'][$chatId] ?? 0) + 1;
        if ($tData['warns'][$chatId] >= 3) {
            file_get_contents($api . "/banChatMember?chat_id=$chatId&user_id=$targetId");
            $tData['warns'][$chatId] = 0;
            send($chatId, "🚫 Лимит варнов 3/3! Пользователь забанен.");
        } else {
            send($chatId, "⚠️ Предупреждение пользователю! (" . $tData['warns'][$chatId] . "/3)");
        }
        save_data("u_$targetId", $tData);
    }
}

// 7. /rank
if (preg_match('/^\/rank\s+(\d+)/', $text, $m) && $reply && $myRank >= 5) {
    $tId = $reply['from']['id'];
    $newR = (int)$m[1];
    $tData = load_data("u_$tId");
    $tData['ranks'][$chatId] = $newR;
    $tData['name'] = $reply['from']['first_name'];
    save_data("u_$tId", $tData);
    send($chatId, "✅ Установлен ранг <b>$newR</b> для " . $tData['name']);
}

// 8. /stop_user
if ($text == '/stop_user' && $reply) {
    $ignored[$reply['from']['id']] = $userId;
    save_data("ign_$chatId", $ignored);
    send($chatId, "🛑 <b>STOP:</b> Теперь сообщения этого юзера вам будут удаляться автоматически.");
}

// 9. /ping
if ($text == '/ping') {
    $load = sys_getloadavg();
    $status = round((100 - ($load[0] * 10)), 2);
    send($chatId, "📶 <b>Статус:</b> " . ($status > 100 ? 100 : $status) . "%\nНагрузка: {$load[0]}%\nТвой ранг: <b>$myRank</b>");
}

// Статистика
$uData['stats']['total'] = ($uData['stats']['total'] ?? 0) + 1;
save_data("u_$userId", $uData);
