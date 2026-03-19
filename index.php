<?php
// === НАСТРОЙКИ БОТА ===
ini_set('display_errors', 0);
$token = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0";
$adminId = 7640692963;
$api = "https://api.telegram.org/bot$token";

// === СОЗДАНИЕ ПАПОК АВТОМАТИЧЕСКИ ===
if (!is_dir('users')) mkdir('users', 0777, true);
if (!is_dir('chats')) mkdir('chats', 0777, true);

// === ПОЛУЧЕНИЕ ДАННЫХ ===
$content = file_get_contents("php://input");
$update = json_decode($content, true);

// Обработка сообщений и коллбэков (кнопок)
$msg = $update['message'] ?? null;
$cb = $update['callback_query'] ?? null;

if ($cb) {
    $chatId = $cb['message']['chat']['id'];
    $userId = $cb['from']['id'];
    $cbData = $cb['data'];
    $cbId = $cb['id'];
    
    if ($cbData == 'help') {
        file_get_contents($api . "/answerCallbackQuery?callback_query_id=$cbId&text=" . urlencode("Раздел помощи в разработке!"));
    }
    exit;
}

if (!$msg) exit;

$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$userName = $msg['from']['first_name'] ?? 'Без имени';
$text = $msg['text'] ?? '';
$reply = $msg['reply_to_message'] ?? null;

// === БАЗА ДАННЫХ (JSON) ===
function get_json($path, $default = []) {
    return file_exists($path) ? json_decode(file_get_contents($path), true) : $default;
}
function save_json($path, $data) {
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// === СТАТИСТИКА И ПОЛЬЗОВАТЕЛИ ===
$uFile = "users/$userId.json";
$uData = get_json($uFile, ['name' => $userName, 'ranks' => [], 'stats' => ['total'=>0, 'day'=>0, 'week'=>0, 'month'=>0]]);
$uData['name'] = $userName; // Обновляем ник

$today = date('Y-md');
$thisWeek = date('Y-W');
$thisMonth = date('Y-m');

if (($uData['last_day'] ?? '') != $today) { $uData['stats']['day'] = 0; $uData['last_day'] = $today; }
if (($uData['last_week'] ?? '') != $thisWeek) { $uData['stats']['week'] = 0; $uData['last_week'] = $thisWeek; }
if (($uData['last_month'] ?? '') != $thisMonth) { $uData['stats']['month'] = 0; $uData['last_month'] = $thisMonth; }

$uData['stats']['total']++; $uData['stats']['day']++; $uData['stats']['week']++; $uData['stats']['month']++;
save_json($uFile, $uData);

// === НАСТРОЙКИ ЧАТА ===
$cFile = "chats/$chatId.json";
$chatConfig = get_json($cFile, [
    'welcome' => "Привет, [username]! Добро пожаловать!",
    'rules' => "Правила пока не установлены.",
    'warns' => [], // Хранилище предупреждений
    'commands' => ['info' => 0, 'rules' => 0] // Мин. ранг для команд
]);

// Получить ранг
function getRank($cId, $uId, $ownerId) {
    if ($uId == $ownerId) return 5;
    $d = get_json("users/$uId.json");
    return $d['ranks'][$cId] ?? 0;
}
$myRank = getRank($chatId, $userId, $adminId);

// === ФУНКЦИИ API ===
function send($chatId, $text, $keyboard = null) {
    global $api;
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $data['reply_markup'] = json_encode($keyboard);
    file_get_contents($api . "/sendMessage?" . http_build_query($data));
}

function tg_action($action, $chatId, $userId, $until = 0) {
    global $api;
    $url = "$api/$action?chat_id=$chatId&user_id=$userId";
    if ($until > 0) $url .= "&until_date=$until";
    if ($action == 'restrictChatMember') {
        $url .= "&permissions=" . urlencode(json_encode(['can_send_messages' => false]));
    }
    file_get_contents($url);
}

// Парсинг времени (10m, 2h, 1d)
function parseTime($str) {
    $val = (int)$str;
    if (strpos($str, 'm')) return time() + ($val * 60);
    if (strpos($str, 'h')) return time() + ($val * 3600);
    if (strpos($str, 'd')) return time() + ($val * 86400);
    return 0; // Навсегда
}

// Проверка прав на команду
function checkAccess($cmd, $myRank, $chatConfig) {
    $req = $chatConfig['commands'][$cmd] ?? 3; // По умолчанию модерские команды от 3 ранга
    return ($myRank >= $req || $myRank == 5);
}

// === ЛОГИКА КОМАНД ===

// ПРИВЕТСТВИЕ НОВИЧКОВ
if (isset($msg['new_chat_members'])) {
    foreach ($msg['new_chat_members'] as $member) {
        if ($chatConfig['welcome'] !== false) {
            $txt = str_replace("[username]", "<a href='tg://user?id=".$member['id']."'>".$member['first_name']."</a>", $chatConfig['welcome']);
            send($chatId, $txt);
        }
    }
}

// ИНФО (/info)
if (strpos($text, '/info') === 0 && checkAccess('info', $myRank, $chatConfig)) {
    $tId = $reply ? $reply['from']['id'] : $userId;
    $tData = get_json("users/$tId.json");
    $tR = getRank($chatId, $tId, $adminId);
    $warns = $chatConfig['warns'][$tId] ?? 0;
    
    $out = "ℹ️ <b>Информация о пользователе:</b>\n";
    $out .= "👤 Ник: " . ($tData['name'] ?? "Неизвестно") . "\n";
    $out .= "🆔 ID: <code>$tId</code>\n";
    $out .= "👑 Ранг в чате: <b>$tR</b>\n";
    $out .= "⚠️ Варны: <b>$warns/3</b>\n\n";
    $out .= "📩 <b>Сообщения:</b>\n";
    $out .= "Сегодня: " . ($tData['stats']['day'] ?? 0) . "\n";
    $out .= "Неделя: " . ($tData['stats']['week'] ?? 0) . "\n";
    $out .= "Месяц: " . ($tData['stats']['month'] ?? 0) . "\n";
    $out .= "Всего: " . ($tData['stats']['total'] ?? 0);
    send($chatId, $out);
}

// РАНГИ (/rank и /unrank)
if (preg_match('/^\/rank\s?(\d+)?/', $text, $match) && $reply) {
    $tId = $reply['from']['id'];
    $tName = $reply['from']['first_name'];
    $oldR = getRank($chatId, $tId, $adminId);
    
    if (isset($match[1]) && $myRank == 5) {
        $newR = (int)$match[1];
        if ($newR >= 0 && $newR <= 5) {
            $tData = get_json("users/$tId.json");
            $tData['ranks'][$chatId] = $newR;
            save_json("users/$tId.json", $tData);
            $icon = ($newR > $oldR) ? "📈 Повышение" : "📉 Понижение";
            send($chatId, "$icon!\n$tName теперь имеет ранг $newR.");
        }
    } else {
        send($chatId, "Ранг $tName: $oldR");
    }
}

if ($text == '/unrank' && $myRank == 5 && $reply) {
    $tId = $reply['from']['id'];
    $tData = get_json("users/$tId.json");
    unset($tData['ranks'][$chatId]);
    save_json("users/$tId.json", $tData);
    send($chatId, "❌ Все ранги сняты с пользователя.");
}

// МОДЕРАЦИЯ (/mute, /ban, /kick, /warn)
if ($reply && checkAccess('mod', $myRank, $chatConfig)) {
    $tId = $reply['from']['id'];
    $tName = $reply['from']['first_name'];
    $args = explode(' ', $text);
    $cmd = $args[0];
    $timeStr = $args[1] ?? '0';
    $reason = implode(' ', array_slice($args, 2)) ?: 'Не указана';
    $until = parseTime($timeStr);

    if ($cmd == '/mute') {
        tg_action('restrictChatMember', $chatId, $tId, $until);
        send($chatId, "🔇 <b>$tName</b> получил мут.\n⏳ Время: $timeStr\n📝 Причина: $reason");
    }
    elseif ($cmd == '/unmute') {
        global $api;
        file_get_contents("$api/restrictChatMember?chat_id=$chatId&user_id=$tId&permissions=".urlencode(json_encode(['can_send_messages'=>true])));
        send($chatId, "🔊 <b>$tName</b> размучен.");
    }
    elseif ($cmd == '/ban') {
        tg_action('banChatMember', $chatId, $tId, $until);
        send($chatId, "🔨 <b>$tName</b> забанен!\n📝 Причина: $reason");
    }
    elseif ($cmd == '/unban') {
        tg_action('unbanChatMember', $chatId, $tId);
        send($chatId, "🕊 <b>$tName</b> разбанен.");
    }
    elseif ($cmd == '/kick') {
        tg_action('banChatMember', $chatId, $tId);
        tg_action('unbanChatMember', $chatId, $tId);
        send($chatId, "👢 <b>$tName</b> кикнут из чата.");
    }
    elseif ($cmd == '/warn') {
        $chatConfig['warns'][$tId] = ($chatConfig['warns'][$tId] ?? 0) + 1;
        $w = $chatConfig['warns'][$tId];
        save_json($cFile, $chatConfig);
        send($chatId, "⚠️ <b>$tName</b> получил предупреждение ($w/3).\n📝 Причина: $reason");
        
        if ($w >= 3) { // Авто-мут на сутки за 3 варна
            tg_action('restrictChatMember', $chatId, $tId, time() + 86400);
            $chatConfig['warns'][$tId] = 0;
            save_json($cFile, $chatConfig);
            send($chatId, "🔇 <b>$tName</b> получил мут на 1 день за 3 предупреждения.");
        }
    }
    elseif ($cmd == '/unwarn') {
        $chatConfig['warns'][$tId] = max(0, ($chatConfig['warns'][$tId] ?? 0) - 1);
        save_json($cFile, $chatConfig);
        send($chatId, "✅ Варн снят. Текущие варны: {$chatConfig['warns'][$tId]}/3");
    }
}

// НАСТРОЙКИ ЧАТА (/set_welcome, /del_welcome, /set_rules, /rules, /dc)
if ($myRank >= 4) {
    if (strpos($text, '/set_welcome') === 0) {
        $chatConfig['welcome'] = trim(substr($text, 12)) ?: "Привет, [username]!";
        save_json($cFile, $chatConfig);
        send($chatId, "✅ Приветствие установлено.");
    }
    elseif ($text == '/del_welcome') {
        $chatConfig['welcome'] = false;
        save_json($cFile, $chatConfig);
        send($chatId, "🗑 Приветствие отключено.");
    }
    elseif (strpos($text, '/set_rules') === 0) {
        $chatConfig['rules'] = trim(substr($text, 10)) ?: "Правила не заданы.";
        save_json($cFile, $chatConfig);
        send($chatId, "✅ Правила чата обновлены!");
    }
    elseif (preg_match('/^\/dc\s+(\w+)\s+(\d+)$/', $text, $m) && $myRank == 5) {
        $chatConfig['commands'][$m[1]] = (int)$m[2];
        save_json($cFile, $chatConfig);
        send($chatId, "⚙️ Доступ к /{$m[1]} теперь от {$m[2]} ранга.");
    }
}

if ($text == '/rules' || strpos($text, '/rules@') === 0) {
    send($chatId, "📜 <b>Правила чата:</b>\n\n" . $chatConfig['rules']);
}

// ИНТЕРАКТИВНОЕ МЕНЮ (/menu)
if ($text == '/menu') {
    $kb = [
        'inline_keyboard' => [
            [['text' => 'ℹ️ Мой профиль', 'callback_data' => 'profile']],
            [['text' => '❓ Помощь', 'callback_data' => 'help'], ['text' => '📜 Правила', 'callback_data' => 'rules']]
        ]
    ];
    send($chatId, "⚙️ <b>Главное меню бота</b>\nВыберите действие ниже:", $kb);
}
