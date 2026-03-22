<?php
// === КОНФИГУРАЦИЯ ===
ini_set('display_errors', 1);
error_reporting(E_ALL);

$token   = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0";
$adminId = 7640692963; // Твой ID (Абсолютный админ, бот игнорирует его нарушения)
$api     = "https://api.telegram.org/bot" . $token;

// Groq API
$groqKey   = "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";
$groqModel = "llama-3.1-8b-instant";

// ===================================================================
// БАЗА ДАННЫХ (Хранение правил чатов и предупреждений)
// ===================================================================
$dbFile = 'database.json';

function getDb() {
    global $dbFile;
    if (!file_exists($dbFile)) {
        file_put_contents($dbFile, json_encode(['chats' => []]));
    }
    return json_decode(file_get_contents($dbFile), true);
}

function saveDb($data) {
    global $dbFile;
    file_put_contents($dbFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function getChatRules($chatId) {
    $db = getDb();
    return $db['chats'][$chatId]['rules'] ?? "Специфичных правил нет. Следуйте базовым правилам адекватного общения.";
}

function setChatRules($chatId, $rules) {
    $db = getDb();
    $db['chats'][$chatId]['rules'] = $rules;
    saveDb($db);
}

// ===================================================================
// ОСНОВНЫЕ ФУНКЦИИ TELEGRAM
// ===================================================================
function tgApi($method, $data = []) {
    global $api;
    $ch = curl_init($api . '/' . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// Проверка, является ли пользователь админом в чате
function isAdmin($chatId, $userId) {
    global $adminId;
    if ($userId == $adminId) return true;
    
    $admins = tgApi("getChatAdministrators", ['chat_id' => $chatId]);
    if (isset($admins['ok']) && $admins['ok']) {
        foreach ($admins['result'] as $admin) {
            if ($admin['user']['id'] == $userId) return true;
        }
    }
    return false;
}

// Тегнуть всех админов
function pingAdmins($chatId, $msgId, $reason, $recommendation, $threat) {
    $admins = tgApi("getChatAdministrators", ['chat_id' => $chatId]);
    $tags = "";
    if (isset($admins['ok']) && $admins['ok']) {
        foreach ($admins['result'] as $admin) {
            if (!$admin['user']['is_bot'] && isset($admin['user']['username'])) {
                $tags .= "@" . $admin['user']['username'] . " ";
            }
        }
    }
    
    $text = "🛡 <b>Внимание Администрации!</b>\n"
          . "ИИ сомневается, но заметил подозрительное сообщение.\n\n"
          . "📊 <b>Угроза:</b> {$threat}%\n"
          . "📌 <b>Причина:</b> {$reason}\n"
          . "💡 <b>Рекомендация ИИ:</b> {$recommendation}\n\n"
          . "👤 Позовите админов: " . $tags;

    tgApi("sendMessage", [
        'chat_id' => $chatId,
        'reply_to_message_id' => $msgId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ]);
}

// ===================================================================
// AI МОДЕРАТОР
// ===================================================================
function aiCheckMessage($chatId, $text, $groqKey, $groqModel) {
    if (mb_strlen(trim($text)) < 2) return ['threat_percent' => 0];

    $chatRules = getChatRules($chatId);

    $systemPrompt = "Ты — строгий и беспристрастный ИИ-модератор Telegram-чата.
    
    ПРАВИЛА ЧАТА:
    $chatRules
    
    БАЗОВЫЕ ПРАВИЛА (действуют всегда):
    1. ЖЕСТКИЙ ЗАПРЕТ НА МАТ. Любое матерное слово, скрытый мат (звездочки), мат для связки слов или эмоций — это нарушение.
    2. Запрещены оскорбления, токсичность, буллинг.
    3. Запрещены спам, реклама, шок-контент, порнография, угрозы.

    Твоя задача — проанализировать сообщение и оценить уровень угрозы (threat_percent) от 0 до 100.
    - 0%: Абсолютно чистое сообщение.
    - 10-49%: Мелкие нарушения, грубость, подозрение на спам, спорная ситуация.
    - 50-100%: Явный мат, оскорбления, реклама, угрозы (Требует автоматического наказания).

    ОТВЕЧАЙ СТРОГО В JSON ФОРМАТЕ, без лишнего текста:
    {
        \"threat_percent\": число от 0 до 100,
        \"reason\": \"короткая причина на русском\",
        \"severity\": 1 (варн), 2 (мут) или 3 (бан),
        \"suggested_action\": \"строка: warn, mute или ban\"
    }";

    $data = [
        "model" => $groqModel,
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user", "content" => "Текст: " . $text]
        ],
        "temperature" => 0.1,
        "response_format" => ["type" => "json_object"] // Принудительный JSON
    ];

    $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $groqKey", "Content-Type: application/json"]);
    
    $res = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($res, true);
    if (isset($json['choices'][0]['message']['content'])) {
        $content = $json['choices'][0]['message']['content'];
        return json_decode($content, true);
    }
    
    return ['threat_percent' => 0];
}

function enforcePunishment($chatId, $userId, $userName, $msgId, $reason, $severity, $action) {
    // Удаляем сообщение
    tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msgId]);

    $db = getDb();
    if (!isset($db['chats'][$chatId]['users'][$userId])) {
        $db['chats'][$chatId]['users'][$userId] = ['warns' => 0];
    }

    if ($action == 'ban' || $severity == 3) {
        tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
        tgApi("sendMessage", [
            'chat_id' => $chatId, 
            'text' => "⛔ @$userName <b>ЗАБЛОКИРОВАН (Auto-Ban).</b>\n📌 Причина: $reason",
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    // Добавляем варн
    $db['chats'][$chatId]['users'][$userId]['warns'] += 1;
    $warns = $db['chats'][$chatId]['users'][$userId]['warns'];
    saveDb($db);

    if ($warns == 1) {
        tgApi("sendMessage", [
            'chat_id' => $chatId, 
            'text' => "⚠️ @$userName, сообщение удалено.\n📌 Причина: $reason\n❗️ Предупреждение <b>1 из 3</b>.",
            'parse_mode' => 'HTML'
        ]);
    } elseif ($warns == 2) {
        $until = time() + 3600; // Мут на час
        tgApi("restrictChatMember", [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'until_date' => $until,
            'permissions' => json_encode(['can_send_messages' => false])
        ]);
        tgApi("sendMessage", [
            'chat_id' => $chatId, 
            'text' => "🔇 @$userName получает <b>МУТ НА 1 ЧАС</b>.\n📌 Причина: $reason\n❗️ Предупреждение <b>2 из 3</b>.",
            'parse_mode' => 'HTML'
        ]);
    } else {
        tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
        tgApi("sendMessage", [
            'chat_id' => $chatId, 
            'text' => "⛔ @$userName заблокирован.\n📌 Причина: Лимит нарушений (3/3).",
            'parse_mode' => 'HTML'
        ]);
        $db['chats'][$chatId]['users'][$userId]['warns'] = 0;
        saveDb($db);
    }
}

// ===================================================================
// ОБРАБОТКА ОБНОВЛЕНИЙ
// ===================================================================
$update = json_decode(file_get_contents("php://input"), true);
if (!$update || !isset($update['message'])) {
    http_response_code(200);
    echo "OK";
    exit;
}

$msg = $update['message'];
$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$text = $msg['text'] ?? '';
$msgId = $msg['message_id'];
$userName = $msg['from']['username'] ?? $msg['from']['first_name'];

// Цель для админ-команд (если реплай)
$targetId = $msg['reply_to_message']['from']['id'] ?? null;
$targetName = $msg['reply_to_message']['from']['username'] ?? $msg['reply_to_message']['from']['first_name'] ?? 'User';

// ===================================================================
// КОМАНДЫ АДМИНИСТРАТОРА
// ===================================================================
if (strpos($text, '/') === 0) {
    $parts = explode(' ', $text);
    $cmd = strtolower($parts[0]);

    // Просмотр правил (доступно всем)
    if ($cmd == '/rules') {
        $rules = getChatRules($chatId);
        tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "📜 <b>Правила чата:</b>\n\n$rules", 'parse_mode' => 'HTML']);
        exit;
    }

    // Все что ниже - только для админов
    if (isAdmin($chatId, $userId)) {
        
        // --- Настройка правил ---
        if ($cmd == '/set_rules') {
            $newRules = trim(mb_substr($text, 10));
            if ($newRules == "") {
                tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "❌ Укажите правила после команды.\nПример: /set_rules 1. Без спама 2. Без ссылок"]);
            } else {
                setChatRules($chatId, $newRules);
                tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "✅ Правила чата успешно обновлены!"]);
            }
            exit;
        }

        // --- Тест ИИ ---
        if ($cmd == '/testai') {
            $testText = trim(mb_substr($text, 7)) ?: "тест";
            $res = aiCheckMessage($chatId, $testText, $groqKey, $groqModel);
            tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "🤖 <b>Тест ИИ:</b>\n<pre>" . print_r($res, true) . "</pre>", 'parse_mode' => 'HTML']);
            exit;
        }

        // Для команд ниже нужен $targetId (реплай)
        if (!$targetId && in_array($cmd, ['/ban', '/kick', '/mute', '/unmute', '/warn', '/unwarn'])) {
            tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "❌ Сделайте Reply (ответ) на сообщение пользователя!"]);
            exit;
        }

        switch ($cmd) {
            case '/ban':
                tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $targetId]);
                tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "🔨 @$targetName забанен администратором."]);
                break;

            case '/unban':
                tgApi("unbanChatMember", ['chat_id' => $chatId, 'user_id' => $targetId, 'only_if_banned' => true]);
                tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "🕊 @$targetName разбанен."]);
                break;

            case '/kick':
                tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $targetId]);
                tgApi("unbanChatMember", ['chat_id' => $chatId, 'user_id' => $targetId]);
                tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "👢 @$targetName кикнут из чата (может вернуться)."]);
                break;

            case '/mute':
                $minutes = isset($parts[1]) ? (int)$parts[1] : 60;
                $until = time() + ($minutes * 60);
                tgApi("restrictChatMember", [
                    'chat_id' => $chatId,
                    'user_id' => $targetId,
                    'until_date' => $until,
                    'permissions' => json_encode(['can_send_messages' => false])
                ]);
                tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "🔇 @$targetName отправлен в мут на $minutes минут."]);
                break;

            case '/unmute':
                tgApi("restrictChatMember", [
                    'chat_id' => $chatId,
                    'user_id' => $targetId,
                    'permissions' => json_encode([
                        'can_send_messages' => true,
                        'can_send_media_messages' => true,
                        'can_send_other_messages' => true,
                        'can_add_web_page_previews' => true
                    ])
                ]);
                tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "🔊 Мут с @$targetName снят."]);
                break;

            case '/warn':
                $db = getDb();
                if (!isset($db['chats'][$chatId]['users'][$targetId])) $db['chats'][$chatId]['users'][$targetId] = ['warns' => 0];
                $db['chats'][$chatId]['users'][$targetId]['warns'] += 1;
                $warns = $db['chats'][$chatId]['users'][$targetId]['warns'];
                saveDb($db);
                tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "⚠️ @$targetName получил предупреждение ($warns/3) от администратора."]);
                if ($warns >= 3) {
                     tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $targetId]);
                     tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "⛔ @$targetName забанен за 3 предупреждения."]);
                     $db['chats'][$chatId]['users'][$targetId]['warns'] = 0;
                     saveDb($db);
                }
                break;

            case '/unwarn':
                $db = getDb();
                $db['chats'][$chatId]['users'][$targetId]['warns'] = 0;
                saveDb($db);
                tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "✅ Предупреждения @$targetName сброшены до 0."]);
                break;
        }
    }
    exit;
}

// ===================================================================
// АВТОМАТИЧЕСКАЯ AI-ПРОВЕРКА (Если это не команда)
// ===================================================================

// Игнорируем админов
if (isAdmin($chatId, $userId)) {
    http_response_code(200);
    echo "OK";
    exit;
}

// Проверка через Groq
$verdict = aiCheckMessage($chatId, $text, $groqKey, $groqModel);
$threat = $verdict['threat_percent'] ?? 0;

if ($threat >= 50) {
    // Явная угроза -> Автоматическое наказание
    $reason = $verdict['reason'] ?? "Грубое нарушение правил";
    $severity = $verdict['severity'] ?? 1;
    $action = $verdict['suggested_action'] ?? 'warn';
    
    enforcePunishment($chatId, $userId, $userName, $msgId, $reason, $severity, $action);

} elseif ($threat > 0 && $threat < 50) {
    // Сомнительная ситуация -> Пингуем админов
    $reason = $verdict['reason'] ?? "Подозрительное поведение";
    $action = $verdict['suggested_action'] ?? 'Проверить вручную';
    
    pingAdmins($chatId, $msgId, $reason, $action, $threat);
}

// Завершаем скрипт корректно
http_response_code(200);
echo "OK";
?>
