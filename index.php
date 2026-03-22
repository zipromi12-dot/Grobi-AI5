<?php
// === КОНФИГУРАЦИЯ ===
ini_set('display_errors', 1);
error_reporting(E_ALL);

$token   = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0";
$adminId = 7640692963; // Твой ID (тебя бот наказывать не будет)
$api     = "https://api.telegram.org/bot" . $token;

// Groq API (Ключ и Модель)
$groqKey   = "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";
$groqModel = "llama-3.1-8b-instant";

// ===================================================================
// БАЗА ДАННЫХ (Локальный JSON файл для хранения предупреждений)
// ===================================================================

$dbFile = 'database.json';

// Функция получения базы
function getDb() {
    global $dbFile;
    if (!file_exists($dbFile)) {
        file_put_contents($dbFile, json_encode(['users' => []]));
    }
    return json_decode(file_get_contents($dbFile), true);
}

// Функция сохранения в базу
function saveDb($data) {
    global $dbFile;
    file_put_contents($dbFile, json_encode($data, JSON_PRETTY_PRINT));
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

// ===================================================================
// AI МОДЕРАТОР
// ===================================================================

function aiCheckMessage($text, $groqKey, $groqModel) {
    // Игнорируем совсем короткие сообщения
    if (mb_strlen(trim($text)) < 2) return ['violation' => false];

    // Промпт для ИИ
    $systemPrompt = "Ты — автоматическая система безопасности чата. 
    Твоя цель: выявлять нарушения правил.
    КАТЕГОРИИ НАРУШЕНИЙ:
    1. Мат, нецензурная лексика, скрытый мат (например: п*здец, х**).
    2. Оскорбления участников, агрессия, токсичность.
    3. Реклама, ссылки на другие каналы, спам.
    4. Угрозы физической расправой.

    ОПРЕДЕЛЕНИЕ ТЯЖЕСТИ (severity):
    1 - Легкое нарушение (мат, спам, токсичность).
    3 - Жесткое нарушение (прямые угрозы, шок-контент, массированная реклама).

    ОТВЕЧАЙ СТРОГО В ФОРМАТЕ JSON:
    {\"violation\": true/false, \"reason\": \"короткая причина на русском\", \"severity\": 1 или 3}";

    $data = [
        "model" => $groqModel,
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user", "content" => "Текст сообщения: " . $text]
        ],
        "temperature" => 0 // Чтобы бот не фантазировал
    ];

    $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $groqKey", "Content-Type: application/json"]);
    
    $res = curl_exec($ch);
    curl_close($ch);

    // Умный поиск JSON в ответе ИИ
    if (preg_match('/\{.*\}/s', $res, $matches)) {
        $json = json_decode($matches[0], true);
        if (isset($json['choices'][0]['message']['content'])) {
            $content = $json['choices'][0]['message']['content'];
            if (preg_match('/\{.*\}/s', $content, $m)) {
                return json_decode($m[0], true);
            }
        }
    }
    return ['violation' => false];
}

// Функция выдачи наказаний с учетом истории пользователя
function enforcePunishment($chatId, $userId, $userName, $messageId, $reason, $severity) {
    // 1. Сразу удаляем плохое сообщение
    tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $messageId]);

    // 2. Получаем базу данных
    $db = getDb();
    if (!isset($db['users'][$userId])) {
        $db['users'][$userId] = ['warns' => 0];
    }

    // Если ИИ решил, что это жесть (severity 3) — баним мгновенно
    if ($severity == 3) {
        tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
        tgApi("sendMessage", [
            'chat_id' => $chatId, 
            'text' => "⛔ @$userName <b>ЗАБЛОКИРОВАН НАВСЕГДА.</b>\n📌 Причина: Грубое нарушение ($reason)",
            'parse_mode' => 'HTML'
        ]);
        return;
    }

    // Иначе добавляем +1 предупреждение (severity 1)
    $db['users'][$userId]['warns'] += 1;
    $warns = $db['users'][$userId]['warns'];
    saveDb($db);

    // ЭСКАЛАЦИЯ НАКАЗАНИЙ:
    if ($warns == 1) {
        // Первый раз — просто предупреждение
        tgApi("sendMessage", [
            'chat_id' => $chatId, 
            'text' => "⚠️ @$userName, ваше сообщение удалено.\n📌 Причина: $reason\n❗️ Это ваше <b>1-е предупреждение (из 3)</b>.",
            'parse_mode' => 'HTML'
        ]);

    } elseif ($warns == 2) {
        // Второй раз — Мут на 1 час
        $until = time() + 3600; // Мут на 1 час (3600 секунд)
        tgApi("restrictChatMember", [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'until_date' => $until,
            'permissions' => json_encode(['can_send_messages' => false])
        ]);
        tgApi("sendMessage", [
            'chat_id' => $chatId, 
            'text' => "🔇 @$userName, вы получаете <b>МУТ НА 1 ЧАС</b>.\n📌 Причина: $reason\n❗️ Предупреждений: <b>2 из 3</b>. Следующее нарушение приведет к бану.",
            'parse_mode' => 'HTML'
        ]);

    } elseif ($warns >= 3) {
        // Третий раз — БАН
        tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
        tgApi("sendMessage", [
            'chat_id' => $chatId, 
            'text' => "⛔ @$userName заблокирован в чате.\n📌 Причина: Лимит нарушений превышен (3/3).",
            'parse_mode' => 'HTML'
        ]);
        
        // Сбрасываем счетчик после бана (на случай если админ потом разбанит)
        $db['users'][$userId]['warns'] = 0;
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

// ===================================================================
// КОМАНДЫ ДЛЯ АДМИНА
// ===================================================================
if (strpos($text, '/') === 0) {
    $parts = explode(' ', $text);
    $cmd = strtolower($parts[0]);

    if ($cmd == '/testai' && $userId == $adminId) {
        tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "⏳ Тестирую ИИ..."]);
        $testRes = aiCheckMessage("Ты дурак и урод!", $groqKey, $groqModel);
        tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "🤖 Ответ ИИ: \n<pre>" . print_r($testRes, true) . "</pre>", 'parse_mode' => 'HTML']);
        exit;
    }

    if ($cmd == '/unwarn' && $userId == $adminId) {
        // Прощаем пользователя (сбрасываем варны)
        $targetId = null;
        if (isset($msg['reply_to_message'])) {
            $targetId = $msg['reply_to_message']['from']['id'];
        } elseif (isset($parts[1]) && is_numeric($parts[1])) {
            $targetId = $parts[1];
        }

        if ($targetId) {
            $db = getDb();
            $db['users'][$targetId]['warns'] = 0;
            saveDb($db);
            tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "✅ Предупреждения пользователя сброшены до 0."]);
        } else {
            tgApi("sendMessage", ['chat_id' => $chatId, 'text' => "❌ Ответьте на сообщение пользователя или укажите ID: /unwarn [id]"]);
        }
        exit;
    }
    
    // Если это любая другая команда, просто выходим, ИИ команды не проверяет
    exit;
}

// ===================================================================
// АВТОМАТИЧЕСКАЯ AI-ПРОВЕРКА
// ===================================================================

// Игнорируем админа (тебя)
if ($userId == $adminId) {
    exit;
}

// Отправляем сообщение в нейросеть
$verdict = aiCheckMessage($text, $groqKey, $groqModel);

// Если ИИ нашел нарушение
if (isset($verdict['violation']) && $verdict['violation'] === true) {
    $reason = $verdict['reason'] ?? "Нарушение правил";
    $severity = $verdict['severity'] ?? 1;

    // Запускаем систему наказаний
    enforcePunishment($chatId, $userId, $userName, $messageId, $reason, $severity);
}

// Завершаем скрипт корректно
http_response_code(200);
echo "OK";
?>
