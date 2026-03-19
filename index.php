<?php
// 1. ВКЛЮЧАЕМ ОТЛАДКУ (Вывод всех ошибок на экран и в файл)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// 2. ОСНОВНЫЕ НАСТРОЙКИ (Важно: сначала переменные, потом команды!)
$token = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0";
$adminId = 7640692963; 
$api = "https://api.telegram.org/bot$token";

// 3. ТЕСТ СВЯЗИ (Выполнится, когда ты откроешь страницу в браузере)
$testApi = @file_get_contents("$api/getMe");

if ($testApi === false) {
    echo "<h1>❌ Ошибка: Хостинг блокирует доступ к Telegram API!</h1>";
    echo "<p>Ваш сервер не может отправить запрос на api.telegram.org. Бот работать не будет.</p>";
    exit;
} else {
    // Если ты зашел через браузер, увидишь это:
    if (!file_get_contents("php://input")) {
        echo "<h1>✅ Связь с Telegram есть!</h1>";
        echo "<p>Ответ от Telegram: <b>" . htmlspecialchars($testApi) . "</b></p>";
        echo "<p>Пытаюсь отправить тестовое сообщение администратору...</p>";
        file_get_contents("$api/sendMessage?chat_id=$adminId&text=" . urlencode("Тест связи: Я работаю и вижу тебя!"));
    }
}

// 4. ПОЛУЧЕНИЕ ДАННЫХ ОТ WEBHOOK
$content = file_get_contents("php://input");
if (!$content) exit; // Если данных нет (просто зашли на сайт), останавливаемся здесь

// Логируем входящий запрос
file_put_contents(__DIR__ . '/raw_updates.txt', $content . PHP_EOL, FILE_APPEND);

$update = json_decode($content, true);
$msg = $update['message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$text = $msg['text'] ?? '';
$reply = $msg['reply_to_message'] ?? null;
$firstName = $msg['from']['first_name'] ?? 'Пользователь';

// --- ФУНКЦИИ (Работа с файлами) ---

function getUserRank($chatId, $userId, $adminId) {
    if ($userId == $adminId) return 5;
    $path = __DIR__ . "/users/{$userId}.json";
    if (!file_exists($path)) return 0;
    $data = json_decode(file_get_contents($path), true);
    return $data['ranks'][$chatId] ?? 0;
}

function setUserRank($chatId, $userId, $rank) {
    if (!is_dir(__DIR__ . '/users')) mkdir(__DIR__ . '/users', 0777, true);
    $path = __DIR__ . "/users/{$userId}.json";
    $data = file_exists($path) ? json_decode(file_get_contents($path), true) : ['ranks' => []];
    $data['ranks'][$chatId] = (int)$rank;
    file_put_contents($path, json_encode($data));
}

function getAccess($chatId, $cmd) {
    $path = __DIR__ . "/chats/{$chatId}.json";
    $defaults = ['mute' => 2, 'kick' => 3, 'ban' => 4];
    if (!file_exists($path)) return $defaults[$cmd] ?? 2;
    $config = json_decode(file_get_contents($path), true);
    return $config['access'][$cmd] ?? ($defaults[$cmd] ?? 2);
}

function send($chatId, $text) {
    global $api;
    file_get_contents("$api/sendMessage?chat_id=$chatId&text=" . urlencode($text));
}

// --- ОСНОВНАЯ ЛОГИКА ---

$myRank = getUserRank($chatId, $userId, $adminId);

// Команда /start
if ($text == '/start') {
    setUserRank($chatId, $userId, ($userId == $adminId ? 5 : 0));
    send($chatId, "Привет, $firstName! Идёт настройка...\nТвой ID: $userId\nТвой ранг: " . ($userId == $adminId ? "5 (Владелец)" : "0"));
    exit;
}

// Стоп-слова (удаление)
$stopWords = ['спам', 'реклама', 'казино'];
foreach ($stopWords as $sw) {
    if (mb_stripos($text, $sw) !== false && $myRank < 3) {
        file_get_contents("$api/deleteMessage?chat_id=$chatId&message_id=".$msg['message_id']);
        exit;
    }
}

// Команда /dc (Настройка доступа)
if (preg_match('/^\/dc (\w+) r(\d)/', $text, $matches)) {
    if ($myRank < 5) { send($chatId, "🚫 Нужен ранг 5!"); exit; }
    $cmdName = $matches[1];
    $reqRank = (int)$matches[2];
    
    if (!is_dir(__DIR__ . '/chats')) mkdir(__DIR__ . '/chats', 0777, true);
    $path = __DIR__ . "/chats/{$chatId}.json";
    $config = file_exists($path) ? json_decode(file_get_contents($path), true) : ['access' => []];
    $config['access'][$cmdName] = $reqRank;
    file_put_contents($path, json_encode($config));
    send($chatId, "✅ Настройка: команда /$cmdName доступна от r$reqRank");
}

// Выдача рангов /Admin или /Mod
if ($reply && preg_match('/^\/(Admin|Mod) (\d)/', $text, $matches)) {
    if ($myRank < 5) exit;
    $level = (int)$matches[2];
    $targetId = $reply['from']['id'];
    setUserRank($chatId, $targetId, $level);
    send($chatId, "👤 Ранг $level успешно выдан пользователю $targetId");
}

// Команды модерации: /mute, /kick, /ban
if ($reply && (strpos($text, '/kick') === 0 || strpos($text, '/ban') === 0 || strpos($text, '/mute') === 0)) {
    $cmd = str_replace('/', '', explode(' ', $text)[0]);
    $needed = getAccess($chatId, $cmd);
    
    if ($myRank >= $needed) {
        $targetId = $reply['from']['id'];
        if ($cmd == 'mute') {
            $until = time() + 3600;
            file_get_contents("$api/restrictChatMember?chat_id=$chatId&user_id=$targetId&until_date=$until&permissions=".json_encode(['can_send_messages' => false]));
        } elseif ($cmd == 'kick') {
            file_get_contents("$api/unbanChatMember?chat_id=$chatId&user_id=$targetId");
        } elseif ($cmd == 'ban') {
            file_get_contents("$api/kickChatMember?chat_id=$chatId&user_id=$targetId");
        }
        send($chatId, "✅ Операция $cmd выполнена (r$needed)");
    } else {
        send($chatId, "🚫 Твоего ранга ($myRank) недостаточно (нужен r$needed)");
    }
}
?>
