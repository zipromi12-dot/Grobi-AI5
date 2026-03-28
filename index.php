<?php
// ==========================================
// ⚙️ ОСНОВНЫЕ НАСТРОЙКИ 
// ==========================================
ini_set('display_errors', 0); // Скрываем ошибки PHP для стабильного вебхука

$token        = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0"; 
$adminId      = 7640692963; // ТВОЙ_ЛИЧНЫЙ_ID (сюда летят подробные логи ошибок)
$adminGroupId = "-1003812180726"; // ID ГРУППЫ АДМИНОВ (сюда летят репорты с кнопками)

$geminiKey    = "AIzaSyANstszxxWi1AYgZvAPpQc_gQsjuPjRbBc"; // Основной ИИ (Видит фото)
$groqKey      = "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";   // Резервный ИИ (Только текст)

$api          = "https://api.telegram.org/bot" . $token;
$dbFile       = 'database.json';

// ==========================================
// 🗄 БАЗА ДАННЫХ И ИСТОРИЯ
// ==========================================
function getDb() {
    global $dbFile;
    if (!file_exists($dbFile)) file_put_contents($dbFile, json_encode(['chats' => []]));
    $data = json_decode(file_get_contents($dbFile), true);
    return is_array($data) ? $data : ['chats' => []];
}

function saveDb($data) {
    global $dbFile;
    file_put_contents($dbFile, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function addHistory($chatId, $userName, $text) {
    if(empty($text)) return;
    $db = getDb();
    if (!isset($db['chats'][$chatId]['history'])) $db['chats'][$chatId]['history'] = [];
    $db['chats'][$chatId]['history'][] = "[$userName]: $text";
    if (count($db['chats'][$chatId]['history']) > 15) array_shift($db['chats'][$chatId]['history']);
    saveDb($db);
}

// ==========================================
// 📡 TELEGRAM API И ЛОГИРОВАНИЕ
// ==========================================
function tgApi($method, $data = []) {
    global $api;
    $ch = curl_init($api . '/' . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    $res = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) logBotError("Ошибка сети с Telegram.", "cURL Error ($method): $error");
    return json_decode($res, true);
}

// Логирование: Коротко в админку, подробно разработчику в ЛС
function logBotError($shortMsg, $detailedMsg) {
    global $adminId, $adminGroupId;
    tgApi("sendMessage", [
        'chat_id' => $adminId,
        'text' => "🔴 <b>СИСТЕМНАЯ ОШИБКА:</b>\n\n<pre>".htmlspecialchars($detailedMsg)."</pre>",
        'parse_mode' => 'HTML'
    ]);
    if (!empty($adminGroupId)) {
        tgApi("sendMessage", [
            'chat_id' => $adminGroupId,
            'text' => "⚠️ <b>Внимание:</b> $shortMsg",
            'parse_mode' => 'HTML'
        ]);
    }
}

function isAdmin($chatId, $userId) {
    global $adminId;
    if ($userId == $adminId) return true;
    $res = tgApi("getChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
    return in_array($res['result']['status'] ?? '', ['administrator', 'creator']);
}

function getMessageLink($chatId, $msgId) {
    $cleanChatId = str_replace('-100', '', (string)$chatId);
    return "https://t.me/c/{$cleanChatId}/{$msgId}";
}

// Скачивание файлов (фото/стикеры)
function getFileBase64($fileId, $token) {
    $fileInfo = tgApi("getFile", ['file_id' => $fileId]);
    if (!isset($fileInfo['result']['file_path'])) return null;
    $fileUrl = "https://api.telegram.org/file/bot{$token}/{$fileInfo['result']['file_path']}";
    $ext = strtolower(pathinfo($fileInfo['result']['file_path'], PATHINFO_EXTENSION));
    $mime = ($ext == 'webp') ? "image/webp" : (($ext == 'png') ? "image/png" : "image/jpeg");
    $fileData = @file_get_contents($fileUrl);
    return $fileData ? ['mime' => $mime, 'data' => base64_encode($fileData)] : null;
}

// ==========================================
// 🧠 ИСКУССТВЕННЫЙ ИНТЕЛЛЕКТ (Gemini + Groq)
// ==========================================
function aiCheckMessage($chatId, $text, $imageData = null, $contextText = "") {
    global $geminiKey, $groqKey;
    
    // 1. Пытаемся спросить основной ИИ (Gemini)
    $res = callGemini($chatId, $text, $geminiKey, $imageData, $contextText);
    $usedGroq = false;
    
    // 2. Если Gemini упал (404, лимиты, ошибка сети) -> переключаемся на Groq
    if (isset($res['error'])) {
        logBotError("Gemini недоступен, переключился на Groq", "Gemini Error: " . $res['error']);
        $res = callGroq($chatId, $text, $groqKey, $contextText);
        $usedGroq = true;
    }
    
    // 3. Если и Groq упал
    if (isset($res['error'])) {
        logBotError("ОБА ИИ УПАЛИ!", "Groq Error: " . $res['error']);
        return ['threat_percent' => 0, 'reason' => 'Системная ошибка ИИ', 'ai_logic' => 'AI unavailable', 'suggested_action' => 'none', 'used_groq' => false];
    }
    
    $res['used_groq'] = $usedGroq; // Флаг для админки
    return $res;
}

function callGemini($chatId, $text, $key, $imageData, $context) {
    $db = getDb();
    $chatRules = $db['chats'][$chatId]['rules'] ?? "Без спама, ЦП и прямого оскорбления.";
    
    $systemPrompt = "Ты ИИ модератор. Мат разрешен, если это эмоции. Запрещены прямые оскорбления, травля, NSFW. Кодекс чата: $chatRules. Отвечай строго JSON: {\"threat_percent\": 0-100, \"reason\": \"причина\", \"ai_logic\": \"логика\", \"suggested_action\": \"none/warn/mute/ban\"}";
    
    // Исправленный стабильный URL Gemini
    $url = "https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent?key=" . $key;
    
    $parts = [["text" => "Контент: $text.\nКонтекст переписки: $context"]];
    if ($imageData) {
        $parts[] = ["inline_data" => ["mime_type" => $imageData['mime'], "data" => $imageData['data']]];
    }

    $payload = [
        "contents" => [["parts" => $parts]],
        "systemInstruction" => ["parts" => [["text" => $systemPrompt]]],
        "generationConfig" => ["response_mime_type" => "application/json", "temperature" => 0.2]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200) return ['error' => "HTTP $httpCode: $response"];

    $result = json_decode($response, true);
    $json = $result['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    return json_decode($json, true);
}

function callGroq($chatId, $text, $key, $context) {
    $db = getDb();
    $chatRules = $db['chats'][$chatId]['rules'] ?? "Без спама и прямого оскорбления.";
    
    $systemPrompt = "You are a chat moderator. Chat rules: $chatRules. Context of dialogue: $context. Reply strictly in JSON format: {\"threat_percent\": 0-100, \"reason\": \"short reason in russian\", \"ai_logic\": \"logic in russian\", \"suggested_action\": \"none/warn/mute/ban\"}";
    
    $payload = [
        "model" => "llama3-70b-8192",
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user", "content" => "Analyze this message: $text"]
        ],
        "response_format" => ["type" => "json_object"]
    ];

    $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $key", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200) return ['error' => "HTTP $httpCode: $response"];

    $result = json_decode($response, true);
    $json = $result['choices'][0]['message']['content'] ?? '{}';
    return json_decode($json, true);
}

// ==========================================
// 🛡 ОТПРАВКА РЕПОРТА АДМИНАМ
// ==========================================
function sendReportToAdmin($chatId, $targetId, $targetMsgId, $targetName, $targetText, $aiResult, $isManualReport = false) {
    global $adminGroupId;
    
    $btnDataBan  = "ban|{$targetId}|{$chatId}|{$targetMsgId}";
    $btnDataMute = "mute|{$targetId}|{$chatId}|{$targetMsgId}";
    $btnDataWarn = "warn|{$targetId}|{$chatId}|{$targetMsgId}";
    $btnDataCancel = "cancel|{$targetId}|{$chatId}|{$targetMsgId}";
    
    $keyboard = json_encode(['inline_keyboard' => [
        [['text' => '🚫 Бан', 'callback_data' => $btnDataBan], ['text' => '🔇 Мут (1ч)', 'callback_data' => $btnDataMute]],
        [['text' => '⚠️ Варн', 'callback_data' => $btnDataWarn], ['text' => '✅ Оставить', 'callback_data' => $btnDataCancel]]
    ]]);

    $msgLink = getMessageLink($chatId, $targetMsgId);
    $header = $isManualReport ? "🚨 <b>ЖАЛОБА (REPORT)</b>" : "🧐 <b>СЕРАЯ ЗОНА ({$aiResult['threat_percent']}%)</b>";

    $aiProviderInfo = $aiResult['used_groq'] ? "\n\n🔄 <i>Проверено резервным ИИ (Groq)</i>" : "";

    $text = "$header\n\n"
          . "👤 <b>Пользователь:</b> $targetName\n"
          . "💬 <b>Текст/Медиа:</b> <i>" . htmlspecialchars($targetText) . "</i>\n"
          . "🔗 <a href='$msgLink'>Перейти к сообщению</a>\n\n"
          . "🧠 <b>Анализ ИИ:</b>\n"
          . "Вердикт: <b>{$aiResult['reason']}</b>\n"
          . "Логика: <i>{$aiResult['ai_logic']}</i>"
          . $aiProviderInfo;

    tgApi("sendMessage", [
        'chat_id' => !empty($adminGroupId) ? $adminGroupId : $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => $keyboard,
        'disable_web_page_preview' => true
    ]);
}

// ==========================================
// 🚀 ТОЧКА ВХОДА И ОБРАБОТКА ДАННЫХ
// ==========================================
$update = json_decode(file_get_contents("php://input"), true);

// --- 1. КНОПКИ (CALLBACK QUERIES) ---
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $adminUser = $cb['from']['id'];
    $adminName = $cb['from']['username'] ?? $cb['from']['first_name'];
    $data = explode('|', $cb['data']); 
    
    $action = $data[0] ?? '';
    $targetId = $data[1] ?? '';
    $targetChat = $data[2] ?? '';
    $msgIdToDelete = $data[3] ?? '';

    if (!isAdmin($targetChat, $adminUser)) {
        tgApi("answerCallbackQuery", ['callback_query_id' => $cb['id'], 'text' => "❌ У вас нет прав!", 'show_alert' => true]);
        exit;
    }

    $actionText = "";
    if ($action == 'ban') {
        tgApi("deleteMessage", ['chat_id' => $targetChat, 'message_id' => $msgIdToDelete]);
        tgApi("banChatMember", ['chat_id' => $targetChat, 'user_id' => $targetId]);
        $actionText = "🔴 ВЫДАЛ БАН и удалил сообщение";
    } elseif ($action == 'mute') {
        tgApi("deleteMessage", ['chat_id' => $targetChat, 'message_id' => $msgIdToDelete]);
        tgApi("restrictChatMember", ['chat_id' => $targetChat, 'user_id' => $targetId, 'until_date' => time() + 3600, 'permissions' => json_encode(['can_send_messages' => false])]);
        $actionText = "🔇 ВЫДАЛ МУТ (1 час) и удалил сообщение";
    } elseif ($action == 'warn') {
        tgApi("sendMessage", ['chat_id' => $targetChat, 'reply_to_message_id' => $msgIdToDelete, 'text' => "⚠️ <b>Официальное предупреждение от Администратора!</b>\nСоблюдайте правила чата.", 'parse_mode' => 'HTML']);
        $actionText = "⚠️ ВЫДАЛ ПРЕДУПРЕЖДЕНИЕ";
    } elseif ($action == 'cancel') {
        $actionText = "✅ ОСТАВИЛ (Проигнорировал)";
    }

    $originalText = $cb['message']['text'] ?? 'Информация о нарушении.';
    $newAdminText = "👮‍♂️ <b>Модератор @$adminName принял решение:</b>\n$actionText\n\n〰️〰️〰️\n" . htmlspecialchars($originalText);

    tgApi("editMessageText", [
        'chat_id' => $cb['message']['chat']['id'], 
        'message_id' => $cb['message']['message_id'], 
        'text' => $newAdminText,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => []]) // Убираем кнопки
    ]);

    tgApi("answerCallbackQuery", ['callback_query_id' => $cb['id'], 'text' => "Успешно применено!"]);
    exit;
}

// --- 2. ОБРАБОТКА ТЕКСТА И МЕДИА ---
$msg = $update['message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$msgId  = $msg['message_id'];
$userName = $msg['from']['username'] ?? $msg['from']['first_name'];
$targetMsg = $msg['reply_to_message'] ?? null;
$textRaw = $msg['text'] ?? $msg['caption'] ?? "";

// Настройки чата и история
$db = getDb();
if (!isset($db['chats'][$chatId])) {
    $db['chats'][$chatId] = ['rules' => ''];
    saveDb($db);
}
if (!empty($textRaw) && strpos($textRaw, '/') !== 0) addHistory($chatId, $userName, $textRaw);

if (strpos($textRaw, '/set_rules') === 0 && isAdmin($chatId, $userId)) {
    $db['chats'][$chatId]['rules'] = trim(str_replace('/set_rules', '', $textRaw));
    saveDb($db);
    tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "✅ Кодекс чата обновлен!"]);
    exit;
}

if (trim($textRaw) == '/report' && $targetMsg) {
    tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msgId]); 
    $targetId = $targetMsg['from']['id'];
    $targetName = $targetMsg['from']['first_name'];
    $targetText = $targetMsg['text'] ?? $targetMsg['caption'] ?? "[Медиа/Стикер]";
    
    $history = implode("\n", $db['chats'][$chatId]['history'] ?? []);
    $res = aiCheckMessage($chatId, "[ЖАЛОБА]: " . $targetText, null, $history);
    
    sendReportToAdmin($chatId, $targetId, $targetMsg['message_id'], $targetName, $targetText, $res, true);
    exit;
}

// Защита от проверок самих админов
if (isAdmin($chatId, $userId)) exit;

$contentToAnalyze = '';
$imgData = null;

if (isset($msg['text'])) {
    $contentToAnalyze = "[Текст]: " . $msg['text'];
} elseif (isset($msg['photo'])) {
    $fileId = end($msg['photo'])['file_id'];
    $imgData = getFileBase64($fileId, $token);
    $contentToAnalyze = "[Фото]: " . ($msg['caption'] ?? 'Опиши это фото и проверь на нарушения.');
} elseif (isset($msg['sticker'])) {
    $fileId = $msg['sticker']['file_id'];
    $emoji = $msg['sticker']['emoji'] ?? '';
    if (!$msg['sticker']['is_animated'] && !$msg['sticker']['is_video']) {
        $imgData = getFileBase64($fileId, $token);
    }
    $contentToAnalyze = "[Стикер]: Эмодзи $emoji. " . ($imgData ? "Прочитай текст и опиши." : "(Анимация. Суди по эмодзи и контексту)");
}

if (empty($contentToAnalyze)) exit;

$historyContext = implode("\n", $db['chats'][$chatId]['history'] ?? []);
$res = aiCheckMessage($chatId, $contentToAnalyze, $imgData, $historyContext);
$threat = $res['threat_percent'] ?? 0;

if ($threat >= 50) {
    tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msgId]);
    $action = $res['suggested_action'] ?? 'warn';
    $info = "сообщение удалено";
    if ($action == 'mute') {
        tgApi("restrictChatMember", ['chat_id' => $chatId, 'user_id' => $userId, 'until_date' => time() + 3600, 'permissions' => json_encode(['can_send_messages' => false])]);
        $info = "выдан МУТ (1 час)";
    } elseif ($action == 'ban') {
        tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
        $info = "выдан БАН";
    }
    
    $groqNotice = $res['used_groq'] ? "\n<i>(Сработал резервный ИИ Groq)</i>" : "";
    tgApi("sendMessage", [
        'chat_id' => $chatId, 
        'text' => "🛡 <b>ИИ Судья:</b> Пользователь @$userName нарушил правила — <b>$info</b>.\n\n🧠 Причина: <i>{$res['reason']}</i>$groqNotice", 
        'parse_mode' => 'HTML'
    ]);
} elseif ($threat > 0 && $threat < 50) {
    $targetText = $msg['text'] ?? $msg['caption'] ?? "[Медиа/Стикер]";
    sendReportToAdmin($chatId, $userId, $msgId, $userName, $targetText, $res);
}
?>
