<?php
// === КОНФИГУРАЦИЯ ===
ini_set('display_errors', 0);
$token   = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0";
$adminId = 7640692963; 
$api     = "https://api.telegram.org/bot" . $token;

$groqKey   = "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";
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

// ===================================================================
// AI МОДЕРАТОР С РАССУЖДЕНИЕМ
// ===================================================================
function aiCheckMessage($chatId, $text, $groqKey, $groqModel) {
    $db = getDb();
    $chatRules = $db['chats'][$chatId]['rules'] ?? null;

    // Если правила не установлены — бот не работает
    if (!$chatRules) {
        return ['no_rules' => true];
    }

    $systemPrompt = "Ты — ИИ-модератор. Твоя работа — сопоставлять текст с ПРАВИЛАМИ ЧАТА.
    
    ПРАВИЛА:
    \"$chatRules\"

    ВАЖНО: 
    - Маты разрешены, если они не являются частью оскорбления (буллинга) или грязи.
    - Технический сленг (анрег, рега и т.д.) — это НЕ нарушение.
    
    ОТВЕТЬ СТРОГО В JSON:
    {
        \"threat_percent\": (0-100),
        \"reason\": \"краткое пояснение нарушения\",
        \"ai_logic\": \"твои рассуждения: почему ты сомневаешься или почему считаешь это нарушением\",
        \"suggested_action\": \"warn/mute/ban/none\",
        \"mute_time\": (время в секундах, если это мут согласно правилам)
    }";

    $data = [
        "model" => $groqModel,
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user", "content" => "Текст сообщения: " . $text]
        ],
        "temperature" => 0.2,
        "response_format" => ["type" => "json_object"]
    ];

    $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $groqKey", "Content-Type: application/json"]);
    $res = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($res, true);
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
$text = $msg['text'] ?? '';
$msgId = $msg['message_id'];
$userName = $msg['from']['username'] ?? $msg['from']['first_name'];
$targetId = $msg['reply_to_message']['from']['id'] ?? null;

// --- КОМАНДЫ ---
if (strpos($text, '/') === 0) {
    $parts = explode(' ', $text);
    $cmd = strtolower($parts[0]);

    if ($cmd == '/set_rules' && isAdmin($chatId, $userId)) {
        $rules = trim(str_replace('/set_rules', '', $text));
        $db = getDb();
        $db['chats'][$chatId]['rules'] = $rules;
        saveDb($db);
        tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "✅ <b>Правила Кодекса приняты!</b> теперь я на страже.", 'parse_mode' => 'HTML']);
        exit;
    }
    
    // Ручные команды (реплаем)
    if ($targetId && isAdmin($chatId, $userId)) {
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
    exit;
}

// --- АВТО-ПРОВЕРКА ---
if (isAdmin($chatId, $userId)) exit;

$res = aiCheckMessage($chatId, $text, $groqKey, $groqModel);

// 1. Если правил нет
if (isset($res['no_rules'])) {
    // Бот молчит или может один раз напомнить админу (лучше молчать, чтобы не спамить)
    exit;
}

$threat = $res['threat_percent'] ?? 0;

// 2. Если угроза высокая (>= 50%) — действуем по правилам
if ($threat >= 50) {
    tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msgId]);
    
    $action = $res['suggested_action'] ?? 'warn';
    $reason = $res['reason'] ?? "Нарушение кодекса";
    
    if ($action == 'mute' && isset($res['mute_time'])) {
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

    tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "🛡 <b>Кодекс Хаты:</b> Сообщение @$userName $info", 'parse_mode' => 'HTML']);

} 
// 3. Если угроза средняя (от 1% до 49%) — рассуждаем и зовем админов
elseif ($threat > 0) {
    $logic = $res['ai_logic'] ?? "Нет пояснений";
    $reason = $res['reason'] ?? "Неоднозначно";
    
    tgApi("sendMessage", [
        'chat_id' => $chatId,
        'reply_to_message_id' => $msgId,
        'text' => "🧐 <b>ИИ в раздумьях (Угроза {$threat}%)</b>\n\n" .
                  "📌 <b>Вердикт:</b> {$reason}\n" .
                  "🧠 <b>Рассуждение:</b> <i>{$logic}</i>\n\n" .
                  "⚠️ Администрация, взгляните.",
        'parse_mode' => 'HTML'
    ]);
}
?>
