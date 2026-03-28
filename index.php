<?php
// === КОНФИГУРАЦИЯ ===
ini_set('display_errors', 0);
$token   = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0"; 
$adminId = 123456789; // Замени на свой ID если нужно
$adminGroupId = "-1003812180726"; 
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

// Логирование в чат админов
function logToAdmin($message) {
    global $adminGroupId;
    if (empty($adminGroupId)) return;
    tgApi("sendMessage", [
        'chat_id' => $adminGroupId,
        'text' => "📝 <b>LOG:</b>\n" . $message,
        'parse_mode' => 'HTML'
    ]);
}

function isAdmin($chatId, $userId) {
    global $adminId;
    if ($userId == $adminId) return true;
    $res = tgApi("getChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
    return in_array($res['result']['status'] ?? '', ['administrator', 'creator']);
}

// Скачивание файла и конвертация в Base64
function getFileBase64($fileId, $token) {
    $fileInfo = tgApi("getFile", ['file_id' => $fileId]);
    if (!isset($fileInfo['result']['file_path'])) return null;
    
    $filePath = $fileInfo['result']['file_path'];
    $fileUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";
    
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mime = ($ext == 'webp') ? "image/webp" : (($ext == 'png') ? "image/png" : "image/jpeg");

    $fileData = @file_get_contents($fileUrl);
    if (!$fileData) return null;
    
    return ['mime' => $mime, 'data' => base64_encode($fileData)];
}

// === ИИ МОДЕРАТОР ===
function aiCheckMessage($chatId, $text, $geminiKey, $imageData = null, $contextText = "") {
    $db = getDb();
    $chatRules = $db['chats'][$chatId]['rules'] ?? "Правила не заданы.";

    $systemPrompt = "Ты — ИИ-модератор. 
    ПРАВИЛА: $chatRules
    ИНСТРУКЦИЯ:
    1. Мат для связки слов разрешен (0% угрозы).
    2. Оскорбления, буллинг, порно, реклама запрещены.
    3. Если есть картинка, анализируй текст и смысл на ней.
    Отвечай ТОЛЬКО JSON: {\"threat_percent\": 0-100, \"reason\": \"причина\", \"ai_logic\": \"разбор\", \"suggested_action\": \"none/mute/ban\", \"mute_time\": 0}";

    if ($contextText) {
        $systemPrompt .= "\n\nКОНТЕКСТ:\n" . $contextText;
    }

    $parts = [["text" => "Контент: " . $text]];
    if ($imageData) {
        $parts[] = ["inline_data" => ["mime_type" => $imageData['mime'], "data" => $imageData['data']]];
    }

    $data = [
        "contents" => [["parts" => $parts]],
        "systemInstruction" => ["parts" => [["text" => $systemPrompt]]],
        "generationConfig" => ["response_mime_type" => "application/json", "temperature" => 0.1]
    ];

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $geminiKey);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($res, true);
    $jsonText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '{"threat_percent": 0, "error": "no_response"}';
    return json_decode($jsonText, true);
}

// === ОБРАБОТКА ===
$update = json_decode(file_get_contents("php://input"), true);

// 1. Force Reply (Причина)
if (isset($update['message']['reply_to_message']) && $update['message']['reply_to_message']['from']['is_bot']) {
    $msg = $update['message'];
    if (strpos($msg['reply_to_message']['text'], 'Введите причину') !== false) {
        $db = getDb();
        $adminIdTask = $msg['from']['id'];
        if (isset($db['pending'][$adminIdTask])) {
            $task = $db['pending'][$adminIdTask];
            $reason = ($msg['text'] == 'пропустить') ? "Не указана" : $msg['text'];
            
            tgApi("deleteMessage", ['chat_id' => $task['chat_id'], 'message_id' => $task['msg_id']]);
            if ($task['action'] == 'ban') {
                tgApi("banChatMember", ['chat_id' => $task['chat_id'], 'user_id' => $task['target_id']]);
            } elseif ($task['action'] == 'mute') {
                tgApi("restrictChatMember", ['chat_id' => $task['chat_id'], 'user_id' => $task['target_id'], 'until_date' => time() + 3600]);
            }
            
            tgApi("sendMessage", ['chat_id' => $task['chat_id'], 'text' => "👮‍♂️ Администратор применил {$task['action']}.\nПричина: $reason"]);
            unset($db['pending'][$adminIdTask]);
            saveDb($db);
            exit;
        }
    }
}

// 2. Callback (Кнопки)
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $data = explode('|', $cb['data']);
    if (!isAdmin($data[2], $cb['from']['id'])) exit;

    if ($data[0] == 'cancel') {
        tgApi("editMessageText", ['chat_id' => $cb['message']['chat']['id'], 'message_id' => $cb['message']['message_id'], 'text' => "✅ Отменено админом."]);
        exit;
    }

    $db = getDb();
    $db['pending'][$cb['from']['id']] = ['action' => $data[0], 'target_id' => $data[1], 'chat_id' => $data[2], 'msg_id' => $data[3]];
    saveDb($db);

    tgApi("sendMessage", [
        'chat_id' => $cb['message']['chat']['id'],
        'text' => "✍️ <b>Введите причину наказания</b> или напишите 'пропустить':",
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['force_reply' => true])
    ]);
    tgApi("deleteMessage", ['chat_id' => $cb['message']['chat']['id'], 'message_id' => $cb['message']['message_id']]);
    exit;
}

// 3. Сообщения
$msg = $update['message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$textRaw = $msg['text'] ?? $msg['caption'] ?? "";

if (!empty($textRaw) && $textRaw[0] != '/') addHistory($chatId, $msg['from']['first_name'], $textRaw);

// Команды
if ($textRaw == '/set_rules' || strpos($textRaw, '/set_rules ') === 0) {
    if (isAdmin($chatId, $userId)) {
        $rules = trim(str_replace('/set_rules', '', $textRaw));
        $db = getDb(); $db['chats'][$chatId]['rules'] = $rules; saveDb($db);
        tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "✅ Правила обновлены."]);
    }
    exit;
}

// Report
if (trim($textRaw) == '/report' && isset($msg['reply_to_message'])) {
    $target = $msg['reply_to_message'];
    $history = implode("\n", getDb()['chats'][$chatId]['history'] ?? []);
    logToAdmin("🚀 Вызван /report пользователем {$msg['from']['first_name']}");
    
    $res = aiCheckMessage($chatId, "Жалоба на: " . ($target['text'] ?? "медиа"), $geminiKey, null, $history);
    
    $kb = json_encode(['inline_keyboard' => [
        [['text' => '🔇 Мут', 'callback_data' => "mute|{$target['from']['id']}|$chatId|{$target['message_id']}"], ['text' => '🚫 Бан', 'callback_data' => "ban|{$target['from']['id']}|$chatId|{$target['message_id']}"]],
        [['text' => '✅ Оставить', 'callback_data' => "cancel|0|$chatId|0"]]
    ]]);

    tgApi("sendMessage", [
        'chat_id' => !empty($adminGroupId) ? $adminGroupId : $chatId,
        'text' => "🚨 <b>ЖАЛОБА!</b>\nОт: {$target['from']['first_name']}\nВердикт ИИ: {$res['threat_percent']}%\nЛогика: {$res['ai_logic']}",
        'parse_mode' => 'HTML',
        'reply_markup' => $kb
    ]);
    exit;
}

// Авто-проверка
if (isAdmin($chatId, $userId)) exit;

$imgData = null;
$type = "текст";

if (isset($msg['photo'])) {
    $type = "фото";
    logToAdmin("🔍 Вижу фото, скачиваю...");
    $imgData = getFileBase64(end($msg['photo'])['file_id'], $token);
} elseif (isset($msg['sticker'])) {
    $type = "стикер";
    if (!$msg['sticker']['is_animated']) {
        logToAdmin("🔍 Вижу статический стикер, скачиваю...");
        $imgData = getFileBase64($msg['sticker']['file_id'], $token);
    }
} elseif (isset($msg['entities'])) {
    foreach ($msg['entities'] as $e) {
        if ($e['type'] == 'custom_emoji') {
            logToAdmin("🔍 Вижу кастомный эмодзи, проверяю...");
            $info = tgApi("getCustomEmojiStickers", ['custom_emoji_ids' => json_encode([$e['custom_emoji_id']])]);
            $imgData = getFileBase64($info['result'][0]['file_id'] ?? '', $token);
        }
    }
}

$res = aiCheckMessage($chatId, $textRaw, $geminiKey, $imgData);
$threat = $res['threat_percent'] ?? 0;

if ($threat >= 50) {
    tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msg['message_id']]);
    tgApi("sendMessage", [
        'chat_id' => $chatId,
        'text' => "🛡 <b>ИИ Судья:</b> Сообщение @{$msg['from']['username']} удалено ({$threat}%).\nПричина: {$res['reason']}",
        'parse_mode' => 'HTML'
    ]);
    logToAdmin("🚫 Авто-удаление ($type): {$res['reason']} ({$threat}%)");
} elseif ($threat > 0) {
    logToAdmin("⚠️ Подозрение ($type): {$threat}%.\nЛогика: {$res['ai_logic']}");
}
