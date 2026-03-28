<?php
// === КОНФИГУРАЦИЯ ===
ini_set('display_errors', 0); // Ошибки PHP скрываем, чтобы не ломать вебхук
$token   = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0"; 
$adminId = 7640692963; // ТВОЙ_ЛИЧНЫЙ_ID (Сюда летят подробные системные ошибки)
$adminGroupId = "-1003812180726"; // ID ГРУППЫ АДМИНОВ (Сюда летят репорты и короткие ошибки)
$api     = "https://api.telegram.org/bot" . $token;

$geminiKey = "AIzaSyANstszxxWi1AYgZvAPpQc_gQsjuPjRbBc"; 

$dbFile    = 'database.json';

// === ФУНКЦИИ БАЗЫ ДАННЫХ ===
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
    if (count($db['chats'][$chatId]['history']) > 15) array_shift($db['chats'][$chatId]['history']); // Храним 15 сообщений для контекста
    saveDb($db);
}

// === TELEGRAM API И ЛОГИРОВАНИЕ ===
function tgApi($method, $data = []) {
    global $api;
    $ch = curl_init($api . '/' . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    $res = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        logBotError("Ошибка сети с Telegram API.", "cURL Error in $method: $error");
    }
    
    return json_decode($res, true);
}

// Функция для отправки ошибок (Разделение на личку и админ-чат)
function logBotError($shortMsg, $detailedMsg) {
    global $adminId, $adminGroupId;
    
    // Подробная ошибка в личку разработчику
    tgApi("sendMessage", [
        'chat_id' => $adminId,
        'text' => "🔴 <b>СИСТЕМНАЯ ОШИБКА:</b>\n\n<pre>".htmlspecialchars($detailedMsg)."</pre>",
        'parse_mode' => 'HTML'
    ]);
    
    // Короткая ошибка в чат админов
    if (!empty($adminGroupId)) {
        tgApi("sendMessage", [
            'chat_id' => $adminGroupId,
            'text' => "⚠️ <b>Внимание модераторам:</b> $shortMsg",
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

// Создание ссылки на сообщение
function getMessageLink($chatId, $msgId) {
    $cleanChatId = str_replace('-100', '', (string)$chatId);
    return "https://t.me/c/{$cleanChatId}/{$msgId}";
}

// Скачивание файла для ИИ
function getFileBase64($fileId, $token) {
    $fileInfo = tgApi("getFile", ['file_id' => $fileId]);
    if (!isset($fileInfo['result']['file_path'])) {
        logBotError("Не удалось получить информацию о файле (фото/стикер).", "getFile failed for file_id: $fileId. Response: " . json_encode($fileInfo));
        return null;
    }
    
    $filePath = $fileInfo['result']['file_path'];
    $fileUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";
    
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mime = "image/jpeg";
    if ($ext == 'webp') $mime = "image/webp";
    if ($ext == 'png') $mime = "image/png";

    $fileData = @file_get_contents($fileUrl);
    if (!$fileData) {
        logBotError("Ошибка скачивания медиа для анализа.", "file_get_contents failed for URL: $fileUrl");
        return null;
    }
    
    return ['mime' => $mime, 'data' => base64_encode($fileData)];
}

// === ИИ МОДЕРАТОР ===
function aiCheckMessage($chatId, $text, $geminiKey, $imageData = null, $contextText = "") {
    $db = getDb();
    $chatRules = $db['chats'][$chatId]['rules'] ?? "Соблюдайте адекватное общение. Без спама и порнографии.";

    $systemPrompt = "
    ### ПАМЯТКА ДЛЯ ИИ-МОДЕРАТОРА ###
    1. МАТ РАЗРЕШЕН: эмоции, связка слов (0% угрозы).
    2. МАТ ЗАПРЕЩЕН: прямое оскорбление личности, травля.
    3. АНАЛИЗ КАРТИНОК: Если прикреплено фото/стикер, читай текст на нем и анализируй визуальный посыл. NSFW и шок-контент - запрещены.
    4. ПРОЦЕНТЫ: 0% (чисто), 1-49% (серая зона, подозрительно), 50-100% (явное нарушение).
    
    ### КОДЕКС ЧАТА ###
    $chatRules
    
    Отвечай строго в JSON: {\"threat_percent\": 0-100, \"reason\": \"короткая причина\", \"ai_logic\": \"твой разбор ситуации\", \"suggested_action\": \"none/warn/mute/ban\"}";

    if ($contextText) {
        $systemPrompt .= "\n\n### КОНТЕКСТ ДИАЛОГА (последние сообщения) ###\n" . $contextText;
    }

    $parts = [["text" => "Контент для анализа: " . $text]];
    
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Обработка ошибок ИИ
    if ($httpCode != 200) {
        $shortErr = "Ошибка работы ИИ модератора.";
        if ($httpCode == 429 || strpos(strtolower($res), 'quota') !== false) {
            $shortErr = "🛑 Закончились токены или лимит запросов к Gemini API!";
        }
        logBotError($shortErr, "Gemini HTTP $httpCode\nResponse: $res\nPayload: " . json_encode($geminiData));
        
        // Возвращаем безопасный ответ, чтобы бот не падал
        return ['threat_percent' => 0, 'reason' => 'Ошибка API', 'ai_logic' => 'Не удалось связаться с сервером ИИ.', 'suggested_action' => 'none'];
    }

    $result = json_decode($res, true);
    $jsonText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    return json_decode($jsonText, true);
}

// === ТОЧКА ВХОДА (ОБРАБОТКА ДАННЫХ) ===
$update = json_decode(file_get_contents("php://input"), true);

// --- 1. ОБРАБОТКА КНОПОК АДМИНА (Callback Queries) ---
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $adminUser = $cb['from']['id'];
    $adminName = $cb['from']['username'] ?? $cb['from']['first_name'];
    $data = explode('|', $cb['data']); // Формат: action|targetId|chatId|msgId
    
    $action = $data[0] ?? '';
    $targetId = $data[1] ?? '';
    $targetChat = $data[2] ?? '';
    $msgIdToDelete = $data[3] ?? '';

    // Проверка прав нажавшего в исходном чате
    if (!isAdmin($targetChat, $adminUser)) {
        tgApi("answerCallbackQuery", ['callback_query_id' => $cb['id'], 'text' => "❌ У вас нет прав управлять этим чатом!", 'show_alert' => true]);
        exit;
    }

    $actionText = "";

    // Выполнение наказания
    if ($action == 'ban') {
        tgApi("deleteMessage", ['chat_id' => $targetChat, 'message_id' => $msgIdToDelete]);
        tgApi("banChatMember", ['chat_id' => $targetChat, 'user_id' => $targetId]);
        $actionText = "🔴 ВЫДАЛ БАН и удалил сообщение";
    } elseif ($action == 'mute') {
        tgApi("deleteMessage", ['chat_id' => $targetChat, 'message_id' => $msgIdToDelete]);
        tgApi("restrictChatMember", ['chat_id' => $targetChat, 'user_id' => $targetId, 'until_date' => time() + 3600, 'permissions' => json_encode(['can_send_messages' => false])]);
        $actionText = "🔇 ВЫДАЛ МУТ (1 час) и удалил сообщение";
    } elseif ($action == 'warn') {
        tgApi("sendMessage", [
            'chat_id' => $targetChat, 
            'reply_to_message_id' => $msgIdToDelete,
            'text' => "⚠️ <b>Официальное предупреждение от Администратора!</b>\nПожалуйста, соблюдайте правила чата.",
            'parse_mode' => 'HTML'
        ]);
        $actionText = "⚠️ ВЫДАЛ ПРЕДУПРЕЖДЕНИЕ (сообщение оставлено)";
    } elseif ($action == 'del') {
        tgApi("deleteMessage", ['chat_id' => $targetChat, 'message_id' => $msgIdToDelete]);
        $actionText = "🗑 УДАЛИЛ сообщение";
    } elseif ($action == 'cancel') {
        $actionText = "✅ ПРОИГНОРИРОВАЛ (Пометил как безопасное)";
    }

    // Изменяем сообщение с кнопками в админ-чате
    $originalText = $cb['message']['text'] ?? 'Информация о нарушении.';
    // Вырезаем старый заголовок и оставляем суть, либо просто дописываем сверху
    $newAdminText = "👮‍♂️ <b>Модератор @$adminName отреагировал!</b>\n<b>Решение:</b> $actionText\n\n〰️〰️〰️\n" . htmlspecialchars($originalText);

    tgApi("editMessageText", [
        'chat_id' => $cb['message']['chat']['id'], 
        'message_id' => $cb['message']['message_id'], 
        'text' => $newAdminText,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => []]) // Убираем кнопки
    ]);

    tgApi("answerCallbackQuery", ['callback_query_id' => $cb['id'], 'text' => "Решение применено!"]);
    exit;
}

// --- 2. ОСНОВНАЯ ОБРАБОТКА СООБЩЕНИЙ ---
$msg = $update['message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$msgId  = $msg['message_id'];
$userName = $msg['from']['username'] ?? $msg['from']['first_name'];
$targetMsg = $msg['reply_to_message'] ?? null;

// Инициализация чата в БД
$db = getDb();
if (!isset($db['chats'][$chatId])) {
    $db['chats'][$chatId] = ['rules' => '', 'is_active' => true, 'history' => []];
    saveDb($db);
}

// Запись текста в историю для контекста ИИ
$textRaw = $msg['text'] ?? $msg['caption'] ?? "";
if (!empty($textRaw) && strpos($textRaw, '/') !== 0) {
    addHistory($chatId, $userName, $textRaw);
}

// Установка правил админом
if (strpos($textRaw, '/set_rules') === 0 && isAdmin($chatId, $userId)) {
    $fullRules = trim(str_replace('/set_rules', '', $textRaw));
    $db['chats'][$chatId]['rules'] = $fullRules;
    saveDb($db);
    tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "✅ Кодекс чата обновлен!"]);
    exit;
}

// --- ФУНКЦИЯ ОТПРАВКИ РЕПОРТА В АДМИНКУ ---
function sendReportToAdmin($chatId, $targetId, $targetMsgId, $targetName, $targetText, $aiReason, $aiLogic, $threatPercent, $isManualReport = false) {
    global $adminGroupId;
    
    $btnDataBan  = "ban|{$targetId}|{$chatId}|{$targetMsgId}";
    $btnDataMute = "mute|{$targetId}|{$chatId}|{$targetMsgId}";
    $btnDataWarn = "warn|{$targetId}|{$chatId}|{$targetMsgId}";
    $btnDataCancel = "cancel|{$targetId}|{$chatId}|{$targetMsgId}";
    
    $keyboard = json_encode(['inline_keyboard' => [
        [['text' => '🚫 Бан', 'callback_data' => $btnDataBan], ['text' => '🔇 Мут (1ч)', 'callback_data' => $btnDataMute]],
        [['text' => '⚠️ Дать Варн', 'callback_data' => $btnDataWarn], ['text' => '✅ Оставить', 'callback_data' => $btnDataCancel]]
    ]]);

    $msgLink = getMessageLink($chatId, $targetMsgId);
    $header = $isManualReport ? "🚨 <b>ЖАЛОБА ОТ ПОЛЬЗОВАТЕЛЯ!</b>" : "🧐 <b>СЕРАЯ ЗОНА (Сомнения ИИ - {$threatPercent}%)</b>";

    $text = "$header\n\n"
          . "👤 <b>Пользователь:</b> $targetName\n"
          . "💬 <b>Контент:</b> <i>" . htmlspecialchars($targetText) . "</i>\n"
          . "🔗 <a href='$msgLink'>Перейти к сообщению</a>\n\n"
          . "🧠 <b>Анализ ИИ:</b>\n"
          . "Вердикт: <b>$aiReason</b>\n"
          . "Логика: <i>$aiLogic</i>\n\n"
          . "Выберите действие:";

    tgApi("sendMessage", [
        'chat_id' => !empty($adminGroupId) ? $adminGroupId : $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => $keyboard,
        'disable_web_page_preview' => true
    ]);
}

// --- РУЧНОЙ РЕПОРТ (/report) ---
if (trim($textRaw) == '/report' && $targetMsg) {
    tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msgId]); // Удаляем команду
    
    $targetId = $targetMsg['from']['id'];
    $targetName = $targetMsg['from']['first_name'];
    $targetText = $targetMsg['text'] ?? $targetMsg['caption'] ?? "[Медиа/Стикер]";
    
    $history = implode("\n", $db['chats'][$chatId]['history'] ?? []);
    
    // Анализируем
    $res = aiCheckMessage($chatId, "[ЖАЛОБА]: " . $targetText, $geminiKey, null, $history);
    
    sendReportToAdmin(
        $chatId, $targetId, $targetMsg['message_id'], $targetName, 
        $targetText, $res['reason'], $res['ai_logic'], $res['threat_percent'], true
    );
    exit;
}

// --- АВТОМАТИЧЕСКАЯ ПРОВЕРКА КОНТЕНТА (Текст + Зрение) ---
// Не проверяем админов
if (!$db['chats'][$chatId]['is_active'] || isAdmin($chatId, $userId)) exit;

$contentToAnalyze = '';
$imgData = null;

if (isset($msg['text'])) {
    $contentToAnalyze = "[Текст]: " . $msg['text'];
} elseif (isset($msg['photo'])) {
    $fileId = end($msg['photo'])['file_id'];
    $imgData = getFileBase64($fileId, $token);
    $contentToAnalyze = "[Фото]: " . ($msg['caption'] ?? 'Без подписи. Опиши фото.');
} elseif (isset($msg['sticker'])) {
    $fileId = $msg['sticker']['file_id'];
    $emoji = $msg['sticker']['emoji'] ?? '';
    if (!$msg['sticker']['is_animated'] && !$msg['sticker']['is_video']) {
        $imgData = getFileBase64($fileId, $token);
    }
    $contentToAnalyze = "[Стикер]: Эмодзи $emoji. Прочитай текст на картинке.";
}

if (empty($contentToAnalyze)) exit;

// ВЫЗОВ ИИ (со зрением)
$res = aiCheckMessage($chatId, $contentToAnalyze, $geminiKey, $imgData);
$threat = $res['threat_percent'] ?? 0;

// 1. ЯВНОЕ НАРУШЕНИЕ (>= 50%) - Бот удаляет сам
if ($threat >= 50) {
    tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msgId]);
    $action = $res['suggested_action'] ?? 'warn';
    
    if ($action == 'mute') {
        tgApi("restrictChatMember", ['chat_id' => $chatId, 'user_id' => $userId, 'until_date' => time() + 3600, 'permissions' => json_encode(['can_send_messages' => false])]);
        $info = "выдан МУТ (1 час)";
    } elseif ($action == 'ban') {
        tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
        $info = "выдан БАН";
    } else {
        $info = "сообщение удалено";
    }

    tgApi("sendMessage", [
        'chat_id' => $chatId, 
        'text' => "🛡 <b>ИИ Судья:</b> Контент пользователя @$userName нарушил правила — <b>$info</b>.\n\n🧠 Причина: <i>{$res['reason']}</i>", 
        'parse_mode' => 'HTML'
    ]);
} 
// 2. СЕРАЯ ЗОНА (1 - 49%) - Бот отправляет на суд админам в админ-чат
elseif ($threat > 0 && $threat < 50) {
    $targetText = $msg['text'] ?? $msg['caption'] ?? "[Медиафайл/Стикер]";
    sendReportToAdmin(
        $chatId, $userId, $msgId, $userName, 
        $targetText, $res['reason'], $res['ai_logic'], $threat
    );
}
?>
