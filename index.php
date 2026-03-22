<?php
// === КОНФИГУРАЦИЯ ===
// Включаем отображение ошибок для отладки на Render
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Твои ключи
$token     = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0";
$adminId   = 7640692963;
// ИСПРАВЛЕНА ОШИБКА: Чистая ссылка на API без markdown
$api       = "https://api.telegram.org/bot" . $token; 
$version   = "2.8.0 (Fixed)";

// Groq API
$groqKey   = "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";
$groqModel = "llama-3.1-8b-то тоinstant";

// Supabase (заполни свои данные)
$sbUrl     = "ТВОЙ_SUPABASE_URL";
$sbKey     = "ТВОЙ_SUPABASE_KEY";

// ===================================================================
// БАЗОВЫЕ ФУНКЦИИ
// ===================================================================

/**
 * Надежная функция отправки запросов в Telegram через cURL
 */
function tgApi($method, $data = []) {
    global $api;
    $ch = curl_init($api . '/' . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    // Таймаут для Render, чтобы не висел бесконечно
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); 
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

/**
 * Функция отправки сообщений (упрощенная tgApi)
 */
function send($chatId, $text, $replyTo = null) {
    $data = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML'
    ];
    if ($replyTo) {
        $data['reply_to_message_id'] = $replyTo;
    }
    return tgApi("sendMessage", $data);
}

// ===================================================================
// AI МОДЕРАТОР
// ===================================================================

/**
 * Проверяет сообщение через Groq AI.
 */
function aiCheckMessage($text, $rules, $groqKey, $groqModel) {
    // Игнорируем совсем короткие сообщения (меньше 2 символов)
    if (mb_strlen(trim($text)) < 2) return ['violation' => false];

    $rulesBlock = $rules ? "Правила чата:\\n" . $rules : "Запрещен мат, спам, оскорбления и реклама.";
    
    $systemPrompt = "Ты — строгий AI-модератор.
$rulesBlock
Проанализируй текст: \"$text\"
Ответь СТРОГО в формате JSON, без лишних слов. Формат:
{\"violation\": true/false, \"reason\": \"причина на русском\", \"severity\": 1-3}
Где severity: 1 - предупреждение, 2 - мут, 3 - бан.";

    $data = [
        "model" => $groqModel,
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user", "content" => $text]
        ],
        "temperature" => 0.1
    ];

    $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $groqKey",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8); // Groq отвечает быстро
    
    $res = curl_exec($ch);
    curl_close($ch);

    if (!$res) return ['violation' => false];

    $resData = json_decode($res, true);
    $content = $resData['choices'][0]['message']['content'] ?? '';

    // ИСПРАВЛЕНА ОШИБКА: Ищем JSON даже если AI написал лишний текст
    if (preg_match('/\{.*\}/s', $content, $matches)) {
        $json = json_decode($matches[0], true);
        if (is_array($json) && isset($json['violation'])) {
            return $json;
        }
    }

    return ['violation' => false];
}

/**
 * Наказывает пользователя на основе вердикта AI
 */
function aiEnforce($chatId, $userId, $messageId, $severity, $reason, $userName) {
    // 1. Удаляем плохое сообщение
    tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $messageId]);

    // 2. Применяем наказание
    if ($severity == 1) {
        send($chatId, "⚠️ @$userName, вы получили предупреждение.\n📌 Причина: $reason");
    
    } elseif ($severity == 2) {
        // ИСПРАВЛЕНА ОШИБКА: Делаем мут на 60 секунд через встроенную функцию Telegram (until_date)
        // Telegram требует чтобы мут был минимум на 30 секунд.
        $until = time() + 60; 
        tgApi("restrictChatMember", [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'until_date' => $until,
            'permissions' => json_encode(['can_send_messages' => false])
        ]);
        send($chatId, "🔇 @$userName, вы получили мут на 1 минуту.\n📌 Причина: $reason");
    
    } elseif ($severity >= 3) {
        tgApi("banChatMember", [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
        send($chatId, "⛔ @$userName был заблокирован.\n📌 Причина: $reason");
    }
}


// ===================================================================
// ОБРАБОТКА ВХОДЯЩИХ ДАННЫХ (WEBHOOK)
// ===================================================================

$update = json_decode(file_get_contents("php://input"), true);

if (!$update || !isset($update['message'])) {
    // Выходим, если это не сообщение
    exit;
}

$message   = $update['message'];
$chatId    = $message['chat']['id'];
$messageId = $message['message_id'];
$text      = $message['text'] ?? '';
$user      = $message['from'];
$userId    = $user['id'];
$userName  = $user['username'] ?? $user['first_name'];

// Проверка: является ли пользователь админом или разработчиком
$isDev = ($userId == $adminId);

// ===================================================================
// ЛОГИКА AI-МОДЕРАЦИИ (Для всех сообщений, кроме команд)
// ===================================================================

// ВНИМАНИЕ: Если хочешь протестировать бота СВОИМ аккаунтом,
// временно закомментируй строку "if ($isDev) { ... }" ниже.
if ($text && strpos($text, '/') !== 0) {
    
    // Пропускаем админа (убери эту проверку для тестов со своего аккаунта)
    // if ($isDev) { exit; } 

    // Вызываем AI
    $aiResult = aiCheckMessage($text, "", $groqKey, $groqModel);

    // Если AI нашел нарушение
    if (isset($aiResult['violation']) && $aiResult['violation'] === true) {
        $severity = $aiResult['severity'] ?? 1;
        $reason = $aiResult['reason'] ?? "Нарушение правил";
        
        // Наказываем
        aiEnforce($chatId, $userId, $messageId, $severity, $reason, $userName);
        exit; // Прерываем скрипт, чтобы не обрабатывать дальше
    }
}

// ===================================================================
// ОБРАБОТКА КОМАНД
// ===================================================================

if (strpos($text, '/') === 0) {
    $parts = explode(' ', $text);
    $cmd = strtolower($parts[0]);

    switch ($cmd) {
        case '/start':
            send($chatId, "👋 Привет! Я AI-модератор (v$version).\nЯ слежу за порядком в чате. Просто добавьте меня в группу и дайте права администратора.");
            break;

        case '/gg': // Глобальный бан (Только для админа)
            if (!$isDev) { 
                send($chatId, "❌ Только для разработчика."); 
                break; 
            }
            
            // Если ответ на сообщение
            if (isset($message['reply_to_message'])) {
                $tId = $message['reply_to_message']['from']['id'];
                $reason = implode(' ', array_slice($parts, 1)) ?: "Глобальное нарушение";
            } else {
                // Если указан ID
                $tId = $parts[1] ?? null;
                $reason = implode(' ', array_slice($parts, 2)) ?: "Глобальное нарушение";
            }

            if ($tId && is_numeric($tId)) {
                // Баним в чате
                tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $tId]);
                send($chatId, "⛓ <b>GLOBAL BAN</b>\nЮзер <code>$tId</code> заблокирован глобально.\n📌 " . htmlspecialchars($reason));
            } else {
                send($chatId, "❌ Укажите ID или ответьте на сообщение: /gg [id] [причина]");
            }
            break;

        case '/unglobalban':
            if (!$isDev) { 
                send($chatId, "❌ Только для разработчика."); 
                break; 
            }
            $tId = $parts[1] ?? null;
            if ($tId && is_numeric($tId)) {
                // Снимаем бан
                tgApi("unbanChatMember", ['chat_id' => $chatId, 'user_id' => $tId, 'only_if_banned' => true]);
                send($chatId, "✅ Бан снят с <code>$tId</code>.");
            } else {
                send($chatId, "❌ Укажите ID: /unglobalban [id]");
            }
            break;
            
        case '/testai':
            // Команда для проверки, жив ли бот
            if (!$isDev) break;
            send($chatId, "⏳ Отправляю тестовый запрос к AI...");
            $res = aiCheckMessage("Ты дурак и урод!", "", $groqKey, $groqModel);
            send($chatId, "🤖 Ответ AI: \n<pre>" . print_r($res, true) . "</pre>");
            break;

        default:
            break;
    }
}

// Успешное завершение работы
http_response_code(200);
echo "OK";
?>
