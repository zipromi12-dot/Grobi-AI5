<?php
// === КОНФИГУРАЦИЯ ===
ini_set('display_errors', 0);
$token   = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0"; 
$adminId = 123456789; // ТВОЙ_ЛИЧНЫЙ_ID
$adminGroupId = "-1003812180726"; // ID ГРУППЫ АДМИНОВ (или оставь пустым "", тогда бот будет кидать кнопки прямо в текущий чат)
$api     = "https://api.telegram.org/bot" . $token;

$geminiKey = "AIzaSyANstszxxWi1AYgZvAPpQc_gQsjuPjRbBc"; 
$groqKey   = "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";   

$dbFile    = 'database.json';

// === БАЗА ДАННЫХ ===
function getDb() {
    global $dbFile;
    if (!file_exists($dbFile)) file_put_contents($dbFile, json_encode(['chats' => [], 'pending' => []]));
    return json_decode(file_get_contents($dbFile), true);
}

function saveDb($data) {
    global $dbFile;
    file_put_contents($dbFile, json_encode($data, JSON_UNESCAPED_UNICODE));
}

// Запись истории (последние 25 сообщений)
function addHistory($chatId, $userName, $text) {
    if(empty($text)) return;
    $db = getDb();
    if (!isset($db['chats'][$chatId]['history'])) $db['chats'][$chatId]['history'] = [];
    $db['chats'][$chatId]['history'][] = "[$userName]: $text";
    // Оставляем только последние 25
    if (count($db['chats'][$chatId]['history']) > 25) array_shift($db['chats'][$chatId]['history']);
    saveDb($db);
}

// === TELEGRAM API ===
function tgApi($method, $data = []) {
    global $api;
    $ch = curl_init($api . '/' . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function isAdmin($chatId, $userId) {
    global $adminId;
    if ($userId == $adminId) return true;
    $res = tgApi("getChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
    return in_array($res['result']['status'] ?? '', ['administrator', 'creator']);
}

// Скачивание файла и конвертация в Base64 для ИИ-зрения
function getFileBase64($fileId, $token) {
    $fileInfo = tgApi("getFile", ['file_id' => $fileId]);
    if (!isset($fileInfo['result']['file_path'])) return null;
    
    $filePath = $fileInfo['result']['file_path'];
    $fileUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";
    
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mime = "image/jpeg";
    if ($ext == 'webp') $mime = "image/webp";
    if ($ext == 'png') $mime = "image/png";

    $fileData = file_get_contents($fileUrl);
    if (!$fileData) return null;
    
    return ['mime' => $mime, 'data' => base64_encode($fileData)];
}

// === ИИ МОДЕРАТОР ===
function aiCheckMessage($chatId, $text, $geminiKey, $imageData = null, $contextText = "") {
    $db = getDb();
    $chatRules = $db['chats'][$chatId]['rules'] ?? "Правила не заданы.";

    $systemPrompt = "
    ### ПАМЯТКА ДЛЯ ИИ-МОДЕРАТОРА ###
    1. МАТ РАЗРЕШЕН: эмоции, связка слов (0% угрозы).
    2. МАТ ЗАПРЕЩЕН: оскорбление личности, гнёт.
    3. АНАЛИЗ КАРТИНОК: Если прикреплено фото/стикер, читай текст на нем и анализируй визуальный посыл.
    4. ПРОЦЕНТЫ: 0% (чисто), 1-49% (серая зона, подозрительно), 50-100% (нарушение).
    
    ### КОДЕКС ЧАТА ###
    $chatRules
    
    Отвечай строго в JSON: {\"threat_percent\": 0-100, \"reason\": \"коротко\", \"ai_logic\": \"разбор\", \"suggested_action\": \"none/warn/mute/ban\", \"mute_time\": 0}";

    if ($contextText) {
        $systemPrompt .= "\n\n### КОНТЕКСТ ДИАЛОГА (последние сообщения) ###\n" . $contextText;
    }

    $parts = [["text" => "Контент для анализа: " . $text]];
    
    // Если есть картинка/стикер - добавляем глаза ИИ
    if ($imageData) {
        $parts[] = [
            "inline_data" => [
                "mime_type" => $imageData['mime'],
                "data" => $imageData['data']
            ]
        ];
    }

    $geminiData = [
        "contents" => [["parts" => $parts]],
        "systemInstruction" => ["parts" => [["text" => $systemPrompt]]],
        "generationConfig" => ["response_mime_type" => "application/json", "temperature" => 0.2]
    ];

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $geminiKey);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($geminiData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($res, true);
    $jsonText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    return json_decode($jsonText, true);
}

// === ОБРАБОТКА ДАННЫХ ===
$update = json_decode(file_get_contents("php://input"), true);

// --- 1. ОБРАБОТКА ВВОДА ПРИЧИНЫ (Force Reply) ---
if (isset($update['message']['reply_to_message']) && $update['message']['reply_to_message']['from']['is_bot']) {
    $msg = $update['message'];
    $adminUser = $msg['from']['id'];
    $adminName = $msg['from']['username'] ?? $msg['from']['first_name'];
    $replyText = $msg['reply_to_message']['text'];
    
    if (strpos($replyText, 'Введите причину наказания') !== false) {
        $db = getDb();
        if (isset($db['pending'][$adminUser])) {
            $task = $db['pending'][$adminUser];
            $reason = trim($msg['text']);
            if (mb_strtolower($reason) == 'пропустить') $reason = "Причина не указана (решение админа).";

            $targetId = $task['target_id'];
            $targetChat = $task['chat_id'];
            $action = $task['action'];
            $msgIdToDelete = $task['msg_id'];

            // Применяем наказание
            tgApi("deleteMessage", ['chat_id' => $targetChat, 'message_id' => $msgIdToDelete]);
            
            if ($action == 'ban') {
                tgApi("banChatMember", ['chat_id' => $targetChat, 'user_id' => $targetId]);
                $actText = "выдал БАН";
            } elseif ($action == 'mute') {
                tgApi("restrictChatMember", ['chat_id' => $targetChat, 'user_id' => $targetId, 'until_date' => time() + 3600, 'permissions' => json_encode(['can_send_messages' => false])]);
                $actText = "выдал МУТ на 1 час";
            } else {
                $actText = "УДАЛИЛ сообщение";
            }

            // Оповещение
            tgApi("sendMessage", [
                'chat_id' => $targetChat, 
                'text' => "👮‍♂️ Администратор @$adminName $actText.\n📝 <b>Причина:</b> $reason", 
                'parse_mode' => 'HTML'
            ]);

            // Удаляем запрос причины и очищаем память
            tgApi("deleteMessage", ['chat_id' => $msg['chat']['id'], 'message_id' => $msg['message_id']]);
            tgApi("deleteMessage", ['chat_id' => $msg['chat']['id'], 'message_id' => $msg['reply_to_message']['message_id']]);
            
            unset($db['pending'][$adminUser]);
            saveDb($db);
            exit;
        }
    }
}

// --- 2. ОБРАБОТКА КНОПОК АДМИНА (Callback Queries) ---
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $adminUser = $cb['from']['id'];
    $adminName = $cb['from']['username'] ?? $cb['from']['first_name'];
    $data = explode('|', $cb['data']); // Формат: action|targetId|chatId|msgId
    $action = $data[0];

    if (!isAdmin($data[2], $adminUser)) {
        tgApi("answerCallbackQuery", ['callback_query_id' => $cb['id'], 'text' => "❌ У вас нет прав!", 'show_alert' => true]);
        exit;
    }

    if ($action == 'cancel') {
        tgApi("editMessageText", [
            'chat_id' => $cb['message']['chat']['id'], 
            'message_id' => $cb['message']['message_id'], 
            'text' => "✅ Администратор @$adminName отметил ситуацию как безопасную."
        ]);
        exit;
    }

    // Сохраняем действие и просим причину
    $db = getDb();
    $db['pending'][$adminUser] = [
        'action' => $action, 'target_id' => $data[1], 'chat_id' => $data[2], 'msg_id' => $data[3]
    ];
    saveDb($db);

    $forceReply = json_encode(['force_reply' => true, 'selective' => true]);
    tgApi("sendMessage", [
        'chat_id' => $cb['message']['chat']['id'],
        'text' => "👮‍♂️ @$adminName, вы выбрали действие: $action.\n✍️ <b>Введите причину наказания</b> (ответом на это сообщение) или напишите «пропустить»:",
        'parse_mode' => 'HTML',
        'reply_markup' => $forceReply
    ]);
    
    tgApi("answerCallbackQuery", ['callback_query_id' => $cb['id']]);
    // Удаляем изначальное сообщение с кнопками
    tgApi("deleteMessage", ['chat_id' => $cb['message']['chat']['id'], 'message_id' => $cb['message']['message_id']]);
    exit;
}

// --- 3. ОСНОВНАЯ ОБРАБОТКА СООБЩЕНИЙ ---
$msg = $update['message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$msgId  = $msg['message_id'];
$userName = $msg['from']['username'] ?? $msg['from']['first_name'];
$targetMsg = $msg['reply_to_message'] ?? null;

// Инициализация чата
$db = getDb();
if (!isset($db['chats'][$chatId])) {
    $db['chats'][$chatId] = ['rules' => '', 'is_active' => true, 'history' => []];
    saveDb($db);
}

// Запись в контекст
$textRaw = $msg['text'] ?? $msg['caption'] ?? "";
if (!empty($textRaw) && strpos($textRaw, '/') !== 0) {
    addHistory($chatId, $userName, $textRaw);
}

// Команды Админа
if (strpos($textRaw, '/') === 0 && isAdmin($chatId, $userId)) {
    $parts = explode(' ', $textRaw);
    $cmd = strtolower($parts[0]);

    if ($cmd == '/set_rules') {
        $fullRules = trim(str_replace('/set_rules', '', $textRaw));
        $db['chats'][$chatId]['rules'] = $fullRules;
        saveDb($db);
        tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "✅ Кодекс внедрен!"]);
        exit;
    }
}

// --- КОМАНДА /report ---
if (trim($textRaw) == '/report' && $targetMsg) {
    tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msgId]); // Удаляем сам репорт
    
    $targetId = $targetMsg['from']['id'];
    $targetText = $targetMsg['text'] ?? $targetMsg['caption'] ?? "Медиа/Стикер";
    
    // Собираем историю
    $history = implode("\n", $db['chats'][$chatId]['history'] ?? []);
    
    // Отправляем ИИ на анализ контекста
    $res = aiCheckMessage($chatId, "[РЕПОРТ НА СООБЩЕНИЕ]: " . $targetText, $geminiKey, null, $history);
    
    // Формируем кнопки
    $btnDataMute = "mute|{$targetId}|{$chatId}|{$targetMsg['message_id']}";
    $btnDataBan  = "ban|{$targetId}|{$chatId}|{$targetMsg['message_id']}";
    $btnDataDel  = "del|{$targetId}|{$chatId}|{$targetMsg['message_id']}";
    $btnDataCancel = "cancel|{$targetId}|{$chatId}|{$targetMsg['message_id']}";
    
    $keyboard = json_encode(['inline_keyboard' => [
        [['text' => '🔇 Мут (1ч)', 'callback_data' => $btnDataMute], ['text' => '🚫 Бан', 'callback_data' => $btnDataBan]],
        [['text' => '🗑 Удалить СМС', 'callback_data' => $btnDataDel], ['text' => '✅ Оставить', 'callback_data' => $btnDataCancel]]
    ]]);

    $alertChatId = !empty($adminGroupId) ? $adminGroupId : $chatId;
    $mention = empty($adminGroupId) ? "@admin " : "";

    tgApi("sendMessage", [
        'chat_id' => $alertChatId,
        'text' => "🚨 $mention<b>ЖАЛОБА ОТ ПОЛЬЗОВАТЕЛЯ!</b>\n\n<b>Подозреваемый:</b> {$targetMsg['from']['first_name']}\n<b>Сообщение:</b> <i>$targetText</i>\n\n🧠 <b>Мнение ИИ (с учетом контекста):</b>\n{$res['ai_logic']}\nУгроза: {$res['threat_percent']}%",
        'parse_mode' => 'HTML',
        'reply_markup' => $keyboard
    ]);
    exit;
}

// --- АВТО-ПРОВЕРКА КОНТЕНТА (Текст + Зрение) ---
if (!$db['chats'][$chatId]['is_active'] || isAdmin($chatId, $userId)) exit;

$contentToAnalyze = '';
$imgData = null;

if (isset($msg['text'])) {
    $contentToAnalyze = "[Текст]: " . $msg['text'];
} elseif (isset($msg['photo'])) {
    // Берем фото максимального качества (последнее в массиве)
    $fileId = end($msg['photo'])['file_id'];
    $imgData = getFileBase64($fileId, $token);
    $contentToAnalyze = "[Фото с подписью]: " . ($msg['caption'] ?? 'без подписи');
} elseif (isset($msg['sticker'])) {
    $fileId = $msg['sticker']['file_id'];
    $emoji = $msg['sticker']['emoji'] ?? '';
    // Пытаемся получить картинку стикера (работает для webp/статичных)
    if (!$msg['sticker']['is_animated'] && !$msg['sticker']['is_video']) {
        $imgData = getFileBase64($fileId, $token);
    }
    $contentToAnalyze = "[Стикер]: Эмодзи $emoji. Прочитай текст на картинке.";
}

if (empty($contentToAnalyze)) exit;

// ВЫЗОВ ИИ (со зрением)
$res = aiCheckMessage($chatId, $contentToAnalyze, $geminiKey, $imgData);
$threat = $res['threat_percent'] ?? 0;

// 1. ЯВНОЕ НАРУШЕНИЕ (>= 50%)
if ($threat >= 50) {
    tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msgId]);
    $action = $res['suggested_action'] ?? 'warn';
    $reason = $res['reason'] ?? "Нарушение";
    
    if ($action == 'mute' && !empty($res['mute_time'])) {
        tgApi("restrictChatMember", ['chat_id' => $chatId, 'user_id' => $userId, 'until_date' => time() + (int)$res['mute_time'], 'permissions' => json_encode(['can_send_messages' => false])]);
        $info = "выдан МУТ";
    } elseif ($action == 'ban') {
        tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
        $info = "выдан БАН";
    } else {
        $info = "удалено";
    }

    tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "🛡 <b>ИИ Судья:</b> Сообщение @$userName $info.\nПричина: $reason\n\n🧠 Логика: <i>{$res['ai_logic']}</i>", 'parse_mode' => 'HTML']);
} 
// 2. СЕРАЯ ЗОНА И СОМНЕНИЯ (1 - 49%) - КНОПКИ ДЛЯ АДМИНА
elseif ($threat > 0 && $threat < 50) {
    $btnDataMute = "mute|{$userId}|{$chatId}|{$msgId}";
    $btnDataBan  = "ban|{$userId}|{$chatId}|{$msgId}";
    $btnDataDel  = "del|{$userId}|{$chatId}|{$msgId}";
    $btnDataCancel = "cancel|{$userId}|{$chatId}|{$msgId}";
    
    $keyboard = json_encode(['inline_keyboard' => [
        [['text' => '🔇 Мут', 'callback_data' => $btnDataMute], ['text' => '🚫 Бан', 'callback_data' => $btnDataBan]],
        [['text' => '🗑 Удалить СМС', 'callback_data' => $btnDataDel], ['text' => '✅ Оставить', 'callback_data' => $btnDataCancel]]
    ]]);

    $alertChatId = !empty($adminGroupId) ? $adminGroupId : $chatId;
    $mention = empty($adminGroupId) ? "@admin " : "";

    tgApi("sendMessage", [
        'chat_id' => $alertChatId,
        'reply_to_message_id' => empty($adminGroupId) ? $msgId : null,
        'text' => "🧐 $mention<b>СЕРАЯ ЗОНА ({$threat}%)</b>\n\n<b>От:</b> @$userName\n<b>Вердикт:</b> {$res['reason']}\n<b>Логика:</b> <i>{$res['ai_logic']}</i>\n\nЧто делаем?",
        'parse_mode' => 'HTML',
        'reply_markup' => $keyboard
    ]);
}
?>
