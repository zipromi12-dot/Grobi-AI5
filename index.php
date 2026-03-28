<?php
// === КОНФИГУРАЦИЯ ===
ini_set('display_errors', 0);
$token   = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0"; // ЗАМЕНИТЬ НА СВОЙ
$adminId = 123456789; // ТВОЙ_ID_АДМИНА
$api     = "https://api.telegram.org/bot" . $token;

// Ключи API
$geminiKey = "AIzaSyANstszxxWi1AYgZvAPpQc_gQsjuPjRbBc"; // Основной ИИ
$groqKey   = "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";   // Запасной ИИ и Распознавание голоса

$groqModel = "llama-3.1-8b-instant";
$dbFile    = 'database.json';

// --- Функции БД ---
function getDb() {
    global $dbFile;
    if (!file_exists($dbFile)) file_put_contents($dbFile, json_encode(['chats' => []]));
    return json_decode(file_get_contents($dbFile), true);
}

function saveDb($data) {
    global $dbFile;
    file_put_contents($dbFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// --- API Telegram ---
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

// --- API Groq (Распознавание голоса) ---
function transcribeVoice($fileId, $token, $groqKey) {
    $fileInfo = tgApi("getFile", ['file_id' => $fileId]);
    if (!isset($fileInfo['result']['file_path'])) return "";
    
    $filePath = $fileInfo['result']['file_path'];
    $fileUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";
    
    $tmpFile = sys_get_temp_dir() . '/' . uniqid('voice_') . '.ogg';
    file_put_contents($tmpFile, file_get_contents($fileUrl));
    
    $ch = curl_init("https://api.groq.com/openai/v1/audio/transcriptions");
    $cFile = new CURLFile($tmpFile, 'audio/ogg', 'voice.ogg');
    $data = [
        'file' => $cFile,
        'model' => 'whisper-large-v3',
        'response_format' => 'json'
    ];
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $groqKey"]);
    $res = curl_exec($ch);
    curl_close($ch);
    
    unlink($tmpFile);
    $result = json_decode($res, true);
    return $result['text'] ?? "";
}

// ===================================================================
// AI МОДЕРАТОР С ПЕРЕКЛЮЧЕНИЕМ (GEMINI -> GROQ)
// ===================================================================
function aiCheckMessage($chatId, $text, $geminiKey, $groqKey, $groqModel) {
    $db = getDb();
    $chatRules = $db['chats'][$chatId]['rules'] ?? null;

    if (!$chatRules) {
        return ['no_rules' => true];
    }

    $systemPrompt = "Ты — строгий, но справедливый ИИ-модератор и судья чата. Твоя задача — анализировать сообщения пользователей (текст, расшифровку аудио, эмодзи стикеров) на строгое соответствие ПРАВИЛАМ ЧАТА.

    ПРАВИЛА ЧАТА:
    \"$chatRules\"

    ВАЖНЫЕ ИНСТРУКЦИИ:
    1. Если нарушение явное (>= 50% угрозы): ставь высокий процент, указывай причину и действие (warn/mute/ban).
    2. Если есть сомнения или ситуация неоднозначная (1-49% угрозы): бот не будет банить, но позовет админов. В 'ai_logic' проведи ГЛУБОКИЙ АНАЛИЗ.
    3. Если правила на это действие нет — это НЕ нарушение (угроза 0%).
    4. Маты разрешены, если они не являются частью оскорбления или буллинга.

    ОТВЕТЬ СТРОГО В JSON:
    {
        \"threat_percent\": (0-100),
        \"reason\": \"краткое пояснение нарушения\",
        \"ai_logic\": \"максимально подробные рассуждения\",
        \"suggested_action\": \"warn/mute/ban/none\",
        \"mute_time\": (время в секундах, если это мут согласно правилам, иначе 0)
    }";

    // --- ПОПЫТКА 1: GEMINI API ---
    $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $geminiKey;
    
    $geminiData = [
        "contents" => [
            ["parts" => [["text" => "Контент для анализа: " . $text]]]
        ],
        "systemInstruction" => [
            "parts" => [["text" => $systemPrompt]]
        ],
        "generationConfig" => [
            "response_mime_type" => "application/json",
            "temperature" => 0.2
        ]
    ];

    $ch = curl_init($geminiUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($geminiData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $geminiRes = curl_exec($ch);
    $geminiHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Если Gemini ответил успешно (код 200)
    if ($geminiHttpCode == 200) {
        $result = json_decode($geminiRes, true);
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $jsonText = $result['candidates'][0]['content']['parts'][0]['text'];
            $decoded = json_decode($jsonText, true);
            if ($decoded) return $decoded; // Успешно вернули JSON от Gemini
        }
    }

    // --- ПОПЫТКА 2: GROQ API (FALLBACK) ---
    // Сюда скрипт дойдет, только если Gemini выдал ошибку (например, 429 лимиты) или вернул кривой JSON

    $groqData = [
        "model" => $groqModel,
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user", "content" => "Контент для анализа: " . $text]
        ],
        "temperature" => 0.2,
        "response_format" => ["type" => "json_object"]
    ];

    $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($groqData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $groqKey", "Content-Type: application/json"]);
    
    $groqRes = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($groqRes, true);
    return json_decode($result['choices'][0]['message']['content'] ?? '{}', true);
}

// ===================================================================
// ОБРАБОТКА ВХОДЯЩИХ ДАННЫХ
// ===================================================================
$update = json_decode(file_get_contents("php://input"), true);
$msg = $update['message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$msgId  = $msg['message_id'];
$userName = $msg['from']['username'] ?? $msg['from']['first_name'];
$targetId = $msg['reply_to_message']['from']['id'] ?? null;

// Инициализация чата в БД
$db = getDb();
if (!isset($db['chats'][$chatId])) {
    $db['chats'][$chatId] = ['rules' => '', 'is_active' => true];
    saveDb($db);
}

// --- ИЗВЛЕЧЕНИЕ КОНТЕНТА ДЛЯ АНАЛИЗА ---
$contentToAnalyze = '';

if (isset($msg['text'])) {
    $contentToAnalyze = "[Текст]: " . $msg['text'];
} elseif (isset($msg['caption'])) {
    $contentToAnalyze = "[Медиа с подписью]: " . $msg['caption'];
} elseif (isset($msg['sticker'])) {
    $contentToAnalyze = "[Стикер]: подразумевает эмодзи " . ($msg['sticker']['emoji'] ?? 'неизвестно');
} elseif (isset($msg['voice'])) {
    $transcription = transcribeVoice($msg['voice']['file_id'], $token, $groqKey);
    $contentToAnalyze = "[Голосовое сообщение (расшифровка)]: " . ($transcription ?: "не удалось разобрать");
}

// --- КОМАНДЫ ---
if (strpos($contentToAnalyze, '[Текст]: /') === 0) {
    $text = $msg['text'];
    $parts = explode(' ', $text);
    $cmd = strtolower($parts[0]);

    if (isAdmin($chatId, $userId)) {
        // Настройка правил
        if ($cmd == '/set_rules') {
            $rules = trim(str_replace('/set_rules', '', $text));
            $db['chats'][$chatId]['rules'] = $rules;
            saveDb($db);
            tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "✅ <b>Правила Кодекса приняты!</b>", 'parse_mode' => 'HTML']);
            exit;
        }
        
        // Включение / Выключение бота
        if ($cmd == '/bot_on') {
            $db['chats'][$chatId]['is_active'] = true;
            saveDb($db);
            tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "👁 <b>ИИ-Модератор активирован.</b> Я слежу за порядком.", 'parse_mode' => 'HTML']);
            exit;
        }
        if ($cmd == '/bot_off') {
            $db['chats'][$chatId]['is_active'] = false;
            saveDb($db);
            tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "💤 <b>ИИ-Модератор деактивирован.</b> Ухожу в спящий режим.", 'parse_mode' => 'HTML']);
            exit;
        }

        // Ручные команды (реплаем)
        if ($targetId) {
            if ($cmd == '/mute') {
                $time = (int)($parts[1] ?? 60);
                tgApi("restrictChatMember", ['chat_id' => $chatId, 'user_id' => $targetId, 'until_date' => time() + ($time * 60), 'permissions' => json_encode(['can_send_messages' => false])]);
                tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "🔇 Мут на $time мин."]);
            }
            if ($cmd == '/ban') {
                tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $targetId]);
            }
            if ($cmd == '/unmute' || $cmd == '/unban') {
                tgApi("restrictChatMember", ['chat_id' => $chatId, 'user_id' => $targetId, 'permissions' => json_encode(['can_send_messages'=>true,'can_send_media_messages'=>true,'can_send_other_messages'=>true,'can_add_web_page_previews'=>true])]);
                tgApi("unbanChatMember", ['chat_id' => $chatId, 'user_id' => $targetId, 'only_if_banned' => true]);
            }
        }
    }
    exit;
}

// --- АВТО-ПРОВЕРКА ---
// Если бот выключен или пишет админ / нет текста — выходим
if (!$db['chats'][$chatId]['is_active'] || isAdmin($chatId, $userId) || empty($contentToAnalyze)) exit;

// ВЫЗОВ ИИ (сначала пробуем Gemini, если ошибка - Groq)
$res = aiCheckMessage($chatId, $contentToAnalyze, $geminiKey, $groqKey, $groqModel);

// 1. Если правил нет
if (isset($res['no_rules'])) exit;

$threat = $res['threat_percent'] ?? 0;

// 2. Если угроза высокая (>= 50%) — действуем по правилам
if ($threat >= 50) {
    // Удаляем сообщение
    tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msgId]);
    
    $action = $res['suggested_action'] ?? 'warn';
    $reason = $res['reason'] ?? "Нарушение кодекса";
    
    // Применяем наказание
    if ($action == 'mute' && !empty($res['mute_time'])) {
        tgApi("restrictChatMember", [
            'chat_id' => $chatId, 
            'user_id' => $userId, 
            'until_date' => time() + (int)$res['mute_time'],
            'permissions' => json_encode(['can_send_messages' => false])
        ]);
        $info = "выдан МУТ. Причина: $reason";
    } elseif ($action == 'ban') {
        tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
        $info = "выдан БАН. Причина: $reason";
    } else {
        $info = "удалено. Причина: $reason";
    }

    // Отправляем отчет в чат
    tgApi("sendMessage", [
        'chat_id' => $chatId, 
        'text' => "🛡 <b>Кодекс Хаты:</b> Сообщение @$userName $info\n\n🧠 <b>Анализ ИИ:</b> <i>{$res['ai_logic']}</i>", 
        'parse_mode' => 'HTML'
    ]);
} 
// 3. Если угроза средняя (от 1% до 49%) — рассуждаем и зовем админов
elseif ($threat > 0 && $threat < 50) {
    $logic = $res['ai_logic'] ?? "Нет пояснений";
    $reason = $res['reason'] ?? "Неоднозначно";
    
    // Получаем список админов чата для упоминания (опционально, если хочешь тегать всех)
    // Здесь просто тегаем "admin" для примера, как было в твоем коде
    
    tgApi("sendMessage", [
        'chat_id' => $chatId,
        'reply_to_message_id' => $msgId,
        'text' => "🧐 <b>ИИ сомневается (Угроза {$threat}%)</b>\n\n" .
                  "📌 <b>Вердикт:</b> {$reason}\n" .
                  "⚖️ <b>Подробный разбор:</b> <i>{$logic}</i>\n\n" .
                  "⚠️ Администрация, взгляните. Правила задеты по касательной или это серая зона.",
        'parse_mode' => 'HTML'
    ]);
}
?>
