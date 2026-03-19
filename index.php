<?php
// === КОНФИГУРАЦИЯ И ЯДРО ===
ini_set('display_errors', 0);
$token = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0";
$adminId = 7640692963; // Твой ID (Разработчик)
$api = "https://api.telegram.org/bot$token";
$supportChat = "@Grobi_Support";

// Создание необходимых папок
foreach (['users', 'chats', 'ignored'] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0777, true);
}

$content = file_get_contents("php://input");
$update = json_decode($content, true);
$msg = $update['message'] ?? null;
$cb = $update['callback_query'] ?? null;

// --- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ---
function get_j($p, $d = []) { return file_exists($p) ? json_decode(file_get_contents($p), true) : $d; }
function save_j($p, $data) { file_put_contents($p, json_encode($data, JSON_UNESCAPED_UNICODE)); }

function send($chatId, $text, $kb = null, $replyId = null) {
    global $api;
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($kb) $data['reply_markup'] = json_encode($kb);
    if ($replyId) $data['reply_to_message_id'] = $replyId;
    return json_decode(file_get_contents($api . "/sendMessage?" . http_build_query($data)), true);
}

// --- ОБРАБОТКА КНОПОК (CALLBACK) ---
if ($cb) {
    $chatId = $cb['message']['chat']['id'];
    $userId = $cb['from']['id'];
    $data = $cb['data'];
    $msgId = $cb['message']['message_id'];

    if (strpos($data, 'pin_') === 0) {
        $targetId = str_replace(['pin_notify_', 'pin_silent_'], '', $data);
        $silent = (strpos($data, 'silent') !== false);
        file_get_contents($api . "/pinChatMessage?chat_id=$chatId&message_id=$targetId&disable_notification=" . ($silent ? 'true' : 'false'));
        file_get_contents($api . "/answerCallbackQuery?callback_query_id=" . $cb['id'] . "&text=Закреплено!");
        file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=$msgId");
    }

    if (strpos($data, 'st_') === 0) {
        $parts = explode('_', $data); // st_period_uid
        $period = $parts[1]; $tId = $parts[2];
        $tData = get_j("users/$tId.json");
        $val = $tData['stats'][$period] ?? 0;
        $periods = ['day'=>'день', 'week'=>'7 дней', 'month'=>'30 дней', 'total'=>'все время'];
        file_get_contents($api . "/editMessageText?" . http_build_query([
            'chat_id' => $chatId, 'message_id' => $msgId,
            'text' => "📊 Статистика <b>" . ($tData['name'] ?? 'Юзера') . "</b>\nПериод: <b>".$periods[$period]."</b>\nСообщений: <b>$val</b>",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text'=>'День','callback_data'=>"st_day_$tId"],['text'=>'7 дн','callback_data'=>"st_week_$tId"]],
                [['text'=>'30 дн','callback_data'=>"st_month_$tId"],['text'=>'Всего','callback_data'=>"st_total_$tId"]]
            ]])
        ]));
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
    if ($uId == $devId) return 6; // Разработчик
    $uData = get_j("users/$uId.json");
    if (isset($uData['ranks'][$cId])) return $uData['ranks'][$cId];
    
    // Проверка на создателя группы через API
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
$ignoredData = get_j("ignored/$chatId.json");
// Если кто-то отвечает тебе или упоминает тебя, а он в твоем стоп-списке
if ($reply && isset($ignoredData[$reply['from']['id']]) && $ignoredData[$reply['from']['id']] == $userId) {
    file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=" . $msg['message_id']);
    send($chatId, "⚠️ Сообщение удалено. Пользователю запрещено отвечать вам или тегать вас.");
    exit;
}

// Обновление статистики и ника
$uFile = "users/$userId.json";
$uData = get_j($uFile, ['name'=>$msg['from']['first_name'], 'stats'=>['day'=>0,'week'=>0,'month'=>0,'total'=>0]]);
$uData['name'] = $msg['from']['first_name'];
$uData['stats']['total']++; $uData['stats']['day']++; $uData['stats']['week']++; $uData['stats']['month']++;
save_j($uFile, $uData);

// --- КОМАНДЫ ---

// 1. /help
if ($text == '/help' || strpos($text, '/help@') === 0) {
    $h = "📖 <b>Справка Grobi Bot:</b>\n\n";
    $h .= "👤 <b>Доступные всем:</b>\n";
    $h .= "• /info — твоя статистика или юзера (в ответ)\n";
    $h .= "• /admin — список администрации чата\n";
    $h .= "• /ping — статус и нагрузка системы\n";
    $h .= "• /rules — правила этого чата\n\n";
    $h .= "🛡 <b>Модерация (Ранг 1+):</b>\n";
    $h .= "• /del — удалить сообщение (в ответ)\n";
    $h .= "• /pin — закрепить сообщение (выбор кнопок)\n";
    $h .= "• /stop_user — (в ответ) запретить юзеру отвечать вам\n\n";
    $h .= "🆘 <b>Поддержка:</b> $supportChat";
    send($chatId, $h);
}

// 2. /ping (Нагрузка системы)
if ($text == '/ping') {
    $load = sys_getloadavg();
    $mem = round(memory_get_usage() / 1024 / 1024, 2);
    $status = (100 - ($load[0] * 10)); // Примерный расчет %
    if ($status > 100) $status = 100;
    
    $p = "📶 <b>Статус бота:</b>\n";
    $p .= "━━━━━━━━━━━━━━━\n";
    $p .= "✅ Работа: <b>Стабильно</b>\n";
    $p .= "⚙️ Нагрузка CPU: <b>$load[0]%</b>\n";
    $p .= "🧠 Память: <b>$mem MB</b>\n";
    $p .= "📊 Общий статус: <b>$status%</b>\n";
    send($chatId, $p);
}

// 3. /admin (Список админов)
if ($text == '/admin') {
    $files = scandir('users');
    $list = "👑 <b>Администрация чата:</b>\n\n";
    foreach ($files as $f) {
        if ($f == '.' || $f == '..') continue;
        $uid = str_replace('.json', '', $f);
        $r = getRank($chatId, $uid, $adminId);
        if ($r > 0) {
            $d = get_j("users/$f");
            $label = ($r == 6) ? "Владелец (Разработчик)" : ($r == 5 ? "Владелец" : "Ранг $r");
            $list .= "• " . $d['name'] . " — <b>$label</b>\n";
        }
    }
    send($chatId, $list);
}

// 4. /stop_user (Запрет на ответы)
if ($text == '/stop_user' && $reply) {
    $targetId = $reply['from']['id'];
    $ignoredData[$targetId] = $userId; // targetId не может отвечать на userId
    save_j("ignored/$chatId.json", $ignoredData);
    send($chatId, "🚫 Пользователю <b>".$reply['from']['first_name']."</b> запрещено тегать вас и отвечать на ваши сообщения.");
}

// 5. /del (Удаление)
if ($text == '/del' && $reply && $myRank >= 1) {
    file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=" . $reply['message_id']);
    file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=" . $msg['message_id']);
}

// 6. /pin (Закреп)
if ($text == '/pin' && $reply && $myRank >= 1) {
    $kb = ['inline_keyboard' => [[
        ['text' => '🔔 С уведомлением', 'callback_data' => 'pin_notify_' . $reply['message_id']],
        ['text' => '🔕 Без уведомления', 'callback_data' => 'pin_silent_' . $reply['message_id']]
    ]]];
    send($chatId, "📌 Выберите тип закрепа:", $kb, $msg['message_id']);
}

// 7. /info
if (strpos($text, '/info') === 0) {
    $tId = $reply ? $reply['from']['id'] : $userId;
    $tName = $reply ? $reply['from']['first_name'] : $msg['from']['first_name'];
    $kb = ['inline_keyboard' => [
        [['text'=>'День','callback_data'=>"st_day_$tId"],['text'=>'7 дн','callback_data'=>"st_week_$tId"]],
        [['text'=>'30 дн','callback_data'=>"st_month_$tId"],['text'=>'Всего','callback_data'=>"st_total_$tId"]]
    ]];
    send($chatId, "📊 Статистика для <b>$tName</b>. Выберите период:", $kb);
}

// 8. /rank (выдача)
if (preg_match('/^\/rank\s+(\d+)/', $text, $m) && $reply && $myRank >= 5) {
    $tId = $reply['from']['id'];
    $newR = (int)$m[1];
    $tData = get_j("users/$tId.json");
    $tData['ranks'][$chatId] = $newR;
    save_j("users/$tId.json", $tData);
    send($chatId, "✅ Пользователю <b>".$reply['from']['first_name']."</b> установлен ранг <b>$newR</b>.");
}
