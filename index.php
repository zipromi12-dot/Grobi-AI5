<?php
// ==========================================
// ⚙️ КОНФИГУРАЦИЯ
// ==========================================
ini_set('display_errors', 0); 

$token        = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0"; 
$adminId      = 123456789; // ТВОЙ ЛИЧНЫЙ ID (для полных логов)
$adminGroupId = "-1003812180726"; // ID ГРУППЫ АДМИНОВ (для репортов)

$geminiKey    = "AIzaSyANstszxxWi1AYgZvAPpQc_gQsjuPjRbBc"; 
$groqKey      = "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";

$api          = "https://api.telegram.org/bot" . $token;
$dbFile       = 'database.json';

// ==========================================
// 🗄 РАБОТА С БД И ИСТОРИЕЙ
// ==========================================
function getDb() {
    global $dbFile;
    if (!file_exists($dbFile)) file_put_contents($dbFile, json_encode(['chats' => []]));
    $res = json_decode(file_get_contents($dbFile), true);
    return is_array($res) ? $res : ['chats' => []];
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
// 📡 ТЕЛЕГРАМ API И ЛОГИ
// ==========================================
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

function logBotError($shortMsg, $detailedMsg) {
    global $adminId, $adminGroupId;
    // Подробный лог тебе в личку
    tgApi("sendMessage", [
        'chat_id' => $adminId,
        'text' => "🔴 <b>СИСТЕМНАЯ ОШИБКА:</b>\n\n<pre>".htmlspecialchars($detailedMsg)."</pre>",
        'parse_mode' => 'HTML'
    ]);
    // Короткое уведомление админам
    if (!empty($adminGroupId)) {
        tgApi("sendMessage", ['chat_id' => $adminGroupId, 'text' => "⚠️ <b>Система:</b> $shortMsg", 'parse_mode' => 'HTML']);
    }
}

function isAdmin($chatId, $userId) {
    global $adminId;
    if ($userId == $adminId) return true;
    $res = tgApi("getChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
    return in_array($res['result']['status'] ?? '', ['administrator', 'creator']);
}

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
// 🧠 НЕУБИВАЕМЫЙ ИИ (Gemini + Groq)
// ==========================================
function aiCheckMessage($chatId, $text, $imageData = null, $contextText = "") {
    global $geminiKey, $groqKey;
    
    // 1. Пытаемся Gemini
    $res = callGemini($chatId, $text, $geminiKey, $imageData, $contextText);
    $usedGroq = false;
    
    // 2. Если Gemini выдал ошибку (любую) -> Groq
    if (isset($res['error'])) {
        logBotError("Gemini Error", $res['error']);
        $res = callGroq($chatId, $text, $groqKey, $contextText);
        $usedGroq = true;
    }
    
    // 3. Если и Groq упал
    if (isset($res['error'])) {
        logBotError("ОБА ИИ УПАЛИ", "Groq Error: " . $res['error']);
        return ['threat_percent' => 0, 'reason' => 'AI_OFF', 'ai_logic' => 'Offline', 'suggested_action' => 'none', 'used_groq' => false];
    }
    
    $res['used_groq'] = $usedGroq;
    return $res;
}

function callGemini($chatId, $text, $key, $imageData, $context) {
    // Используем v1beta, но в упрощенном формате без systemInstruction
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $key;
    
    $prompt = "Ты ИИ Модератор. ПРАВИЛА: мат разрешен если не оскорбление, NSFW/ЦП запрещено. \n"
            . "Контекст диалога:\n$context \n\n"
            . "Проанализируй сообщение: $text \n\n"
            . "Ответь ТОЛЬКО в формате JSON: {\"threat_percent\": 0-100, \"reason\": \"причина\", \"ai_logic\": \"логика\", \"suggested_action\": \"none/warn/mute/ban\"}";

    $parts = [["text" => $prompt]];
    if ($imageData) {
        $parts[] = ["inline_data" => ["mime_type" => $imageData['mime'], "data" => $imageData['data']]];
    }

    $payload = [
        "contents" => [["parts" => $parts]],
        "generationConfig" => ["temperature" => 0.1]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200) return ['error' => "Gemini HTTP $httpCode: $response"];

    $result = json_decode($response, true);
    $rawText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    $cleanJson = trim(str_replace(['```json', '```'], '', $rawText));
    $parsed = json_decode($cleanJson, true);
    
    return is_array($parsed) ? $parsed : ['error' => "Invalid JSON from Gemini: $rawText"];
}

function callGroq($chatId, $text, $key, $context) {
    // Новая модель llama-3.3-70b-versatile
    $url = "https://api.groq.com/openai/v1/chat/completions";
    $payload = [
        "model" => "llama-3.3-70b-versatile",
        "messages" => [
            ["role" => "system", "content" => "You are a chat moderator. Reply ONLY JSON: {\"threat_percent\": 0-100, \"reason\": \"ru_text\", \"ai_logic\": \"ru_text\", \"suggested_action\": \"none\"}"],
            ["role" => "user", "content" => "Context: $context\n\nAnalyze: $text"]
        ],
        "response_format" => ["type" => "json_object"]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $key", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200) return ['error' => "Groq HTTP $httpCode: $response"];

    $result = json_decode($response, true);
    $json = $result['choices'][0]['message']['content'] ?? '{}';
    return json_decode($json, true);
}

// ==========================================
// 🛡 ОТПРАВКА РЕПОРТА АДМИНАМ
// ==========================================
function sendReportToAdmin($chatId, $targetId, $targetMsgId, $targetName, $targetText, $aiRes, $isManual = false) {
    global $adminGroupId;
    
    $keyboard = json_encode(['inline_keyboard' => [
        [['text' => '🚫 Бан', 'callback_data' => "ban|$targetId|$chatId|$targetMsgId"], ['text' => '🔇 Мут', 'callback_data' => "mute|$targetId|$chatId|$targetMsgId"]],
        [['text' => '⚠️ Варн', 'callback_data' => "warn|$targetId|$chatId|$targetMsgId"], ['text' => '✅ Оставить', 'callback_data' => "cancel|$targetId|$chatId|$targetMsgId"]]
    ]]);

    $msgLink = "https://t.me/c/" . str_replace('-100', '', $chatId) . "/$targetMsgId";
    $header = $isManual ? "🚨 <b>ЖАЛОБА</b>" : "🧐 <b>СЕРАЯ ЗОНА ({$aiRes['threat_percent']}%)</b>";
    $provider = $aiRes['used_groq'] ? "\n\n🔄 <i>Использован резервный ИИ (Groq)</i>" : "";

    $text = "$header\n\n"
          . "👤 <b>От:</b> $targetName\n"
          . "💬 <b>Текст:</b> <i>" . htmlspecialchars(mb_substr($targetText, 0, 300)) . "</i>\n"
          . "🔗 <a href='$msgLink'>К сообщению</a>\n\n"
          . "🧠 <b>Анализ:</b>\n"
          . "Вердикт: <b>{$aiRes['reason']}</b>\n"
          . "Логика: <i>{$aiRes['ai_logic']}</i>" . $provider;

    tgApi("sendMessage", [
        'chat_id' => !empty($adminGroupId) ? $adminGroupId : $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => $keyboard,
        'disable_web_page_preview' => true
    ]);
}

// ==========================================
// 🚀 ТОЧКА ВХОДА
// ==========================================
$update = json_decode(file_get_contents("php://input"), true);

// 1. ОБРАБОТКА КНОПОК
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $adminUser = $cb['from']['id'];
    $adminName = $cb['from']['username'] ?? $cb['from']['first_name'];
    $data = explode('|', $cb['data']);
    
    if (count($data) < 4) exit;
    list($action, $tId, $tChat, $tMsgId) = $data;

    if (!isAdmin($tChat, $adminUser)) {
        tgApi("answerCallbackQuery", ['callback_query_id' => $cb['id'], 'text' => "❌ Нет прав!", 'show_alert' => true]);
        exit;
    }

    $resText = "";
    if ($action == 'ban') {
        tgApi("deleteMessage", ['chat_id' => $tChat, 'message_id' => $tMsgId]);
        tgApi("banChatMember", ['chat_id' => $tChat, 'user_id' => $tId]);
        $resText = "🔴 БАН";
    } elseif ($action == 'mute') {
        tgApi("deleteMessage", ['chat_id' => $tChat, 'message_id' => $tMsgId]);
        tgApi("restrictChatMember", ['chat_id' => $tChat, 'user_id' => $tId, 'until_date' => time()+3600, 'permissions' => json_encode(['can_send_messages'=>false])]);
        $resText = "🔇 МУТ (1ч)";
    } elseif ($action == 'warn') {
        tgApi("sendMessage", ['chat_id' => $tChat, 'reply_to_message_id' => $tMsgId, 'text' => "⚠️ <b>Предупреждение от админа!</b>", 'parse_mode' => 'HTML']);
        $resText = "⚠️ ВАРН";
    } elseif ($action == 'cancel') {
        $resText = "✅ ОСТАВЛЕНО";
    }

    $oldText = $cb['message']['text'];
    tgApi("editMessageText", [
        'chat_id' => $cb['message']['chat']['id'],
        'message_id' => $cb['message']['message_id'],
        'text' => "👮‍♂️ <b>Админ @$adminName:</b> $resText\n\n$oldText",
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => []])
    ]);
    tgApi("answerCallbackQuery", ['callback_query_id' => $cb['id'], 'text' => "Готово"]);
    exit;
}

// 2. ОБРАБОТКА СООБЩЕНИЙ
$msg = $update['message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$userName = $msg['from']['first_name'];
$text = $msg['text'] ?? $msg['caption'] ?? "";

if (!empty($text) && strpos($text, '/') !== 0) addHistory($chatId, $userName, $text);

// Ручной репорт
if (trim($text) == '/report' && isset($msg['reply_to_message'])) {
    tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msg['message_id']]);
    $tMsg = $msg['reply_to_message'];
    $res = aiCheckMessage($chatId, "[REPORT]: " . ($tMsg['text'] ?? "Медиа"), null, "Жалоба пользователя.");
    sendReportToAdmin($chatId, $tMsg['from']['id'], $tMsg['message_id'], $tMsg['from']['first_name'], ($tMsg['text'] ?? "Медиа"), $res, true);
    exit;
}

// Авто-модерация (кроме админов)
if (isAdmin($chatId, $userId)) exit;

$img = null;
$type = "[Текст]";
if (isset($msg['photo'])) {
    $img = getFileBase64(end($msg['photo'])['file_id'], $token);
    $type = "[Фото]";
} elseif (isset($msg['sticker'])) {
    if (!$msg['sticker']['is_animated'] && !$msg['sticker']['is_video']) {
        $img = getFileBase64($msg['sticker']['file_id'], $token);
    }
    $type = "[Стикер " . ($msg['sticker']['emoji'] ?? "") . "]";
}

if (empty($text) && !$img) exit;

$db = getDb();
$hist = implode("\n", $db['chats'][$chatId]['history'] ?? []);
$ai = aiCheckMessage($chatId, "$type: $text", $img, $hist);

$threat = $ai['threat_percent'] ?? 0;
if ($threat >= 50) {
    tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msg['message_id']]);
    $act = $ai['suggested_action'] ?? 'del';
    $status = "удалено";
    if ($act == 'ban') { tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $userId]); $status = "забанен"; }
    if ($act == 'mute') { tgApi("restrictChatMember", ['chat_id' => $chatId, 'user_id' => $userId, 'until_date' => time()+3600, 'permissions' => json_encode(['can_send_messages'=>false])]); $status = "в муте (1ч)"; }
    
    $provider = $ai['used_groq'] ? "\n<i>(Backup AI)</i>" : "";
    tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "🛡 <b>ИИ:</b> @$userName $status.\nПричина: {$ai['reason']}$provider", 'parse_mode' => 'HTML']);
} elseif ($threat > 0) {
    sendReportToAdmin($chatId, $userId, $msg['message_id'], $userName, $text, $ai);
}
?>
