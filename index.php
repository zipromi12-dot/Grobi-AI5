<?php
// === КОНФИГУРАЦИЯ ===
ini_set('display_errors', 0);
error_reporting(0);

$token = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0";
$adminId = 7640692963; 
$api = "https://api.telegram.org/bot$token";
$version = "2.6.1-FIXED";

// --- ЕДИНЫЙ ДВИЖОК БАЗЫ ДАННЫХ ---
function sb_req($method, $id = null, $data = null) {
    $sbUrl = "https://vqpurtindyaiwjgreqdt.supabase.co/rest/v1/bot_storage";
    $sbKey = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InZxcHVydGluZHlhaXdqZ3JlcWR0Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM5MjA2NzksImV4cCI6MjA4OTQ5NjY3OX0.pRR7P3quZ7cX5EYZmHOxnx4C1gp9gMQuoMzNFa-lwM4";
    
    $url = $id ? "$sbUrl?id=eq." . urlencode($id) : $sbUrl;
    if ($method == "GET") {
        $url .= ($id ? "&select=data" : "?select=id,data");
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $headers = ["apikey: $sbKey", "Authorization: Bearer $sbKey"];

    if ($method == "POST") {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $headers[] = "Content-Type: application/json";
        $headers[] = "Prefer: resolution=merge-duplicates";
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return json_decode($res, true);
}

function send($chatId, $text, $kb = null, $replyId = null) {
    global $api;
    $data = [
        'chat_id' => $chatId, 
        'text' => $text, 
        'parse_mode' => 'HTML', 
        'disable_web_page_preview' => true
    ];
    if ($kb) $data['reply_markup'] = json_encode($kb);
    if ($replyId) $data['reply_to_message_id'] = $replyId;
    
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
        ]
    ];
    $context  = stream_context_create($options);
    return file_get_contents($api . "/sendMessage", false, $context);
}

// === ОБРАБОТКА ОБНОВЛЕНИЯ ===
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) exit;

// 1. ПРИВЕТСТВИЕ
if (isset($update['message']['new_chat_members'])) {
    $cId = $update['message']['chat']['id'];
    $confRes = sb_req("GET", "conf_$cId");
    $conf = $confRes[0]['data'] ?? [];
    if (isset($conf['welcome']) && !empty($conf['welcome'])) {
        $user = $update['message']['new_chat_members'][0]['first_name'];
        $wText = str_replace("{name}", $user, $conf['welcome']);
        send($cId, $wText);
    }
    exit;
}

$msg = $update['message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$text = $msg['text'] ?? '';
$reply = $msg['reply_to_message'] ?? null;
$name = $msg['from']['first_name'];

// --- ЗАГРУЗКА ДАННЫХ ЮЗЕРА И ЧАТА ---
$uDataRes = sb_req("GET", "u_$userId");
$uData = $uDataRes[0]['data'] ?? [];

$chatConfRes = sb_req("GET", "conf_$chatId");
$chatConf = $chatConfRes[0]['data'] ?? [];

$globalRes = sb_req("GET", "global_config");
$globalData = $globalRes[0]['data'] ?? [];

$isDev = ($userId == $adminId);

// Проверка на глобальный бан (Глобальная база спамеров)
if (isset($globalData['spammers'][$userId])) {
    file_get_contents($api . "/banChatMember?chat_id=$chatId&user_id=$userId");
    exit;
}

// ОПРЕДЕЛЕНИЕ РАНГА
$chatMember = json_decode(file_get_contents($api . "/getChatMember?chat_id=$chatId&user_id=$userId"), true);
$tgStatus = $chatMember['result']['status'] ?? '';

if ($isDev) $myRank = 6;
elseif ($tgStatus === 'creator') $myRank = 5;
else $myRank = (int)($uData['ranks'][$chatId] ?? 0);

// --- ЗАЩИТА ССЫЛОК (Для обычных юзеров) ---
if ($myRank < 1 && (preg_match('/(https?:\/\/[^\s]+)/', $text) || preg_match('/t\.me\//', $text))) {
    $isWhitelisted = false;
    if (isset($chatConf['whitelist'])) {
        foreach ($chatConf['whitelist'] as $wl) {
            if (strpos($text, $wl) !== false) { $isWhitelisted = true; break; }
        }
    }
    if (!$isWhitelisted) {
        file_get_contents($api . "/deleteMessage?chat_id=$chatId&message_id=".$msg['message_id']);
        send($chatId, "⚠️ <b>$name</b>, ссылки запрещены!");
        exit;
    }
}

// === КОМАНДЫ (ТОЧНОЕ СОВПАДЕНИЕ ИЛИ ПРЕФИКС) ===

$cmd = explode(' ', $text)[0];

switch($cmd) {
    case '/ping':
        $start = microtime(true);
        $dbStatus = (!empty($globalRes)) ? "Connected ✅" : "Error ❌";
        $latency = round((microtime(true) - $start) * 1000);
        $p = "📶 <b>СИСТЕМА GROBI</b>\n";
        $p .= "━━━━━━━━━━━━━━━\n";
        $p .= "🚀 Отклик: <code>{$latency}ms</code>\n";
        $p .= "🗄 База: <code>$dbStatus</code>\n";
        $p .= "🛠 Версия: <code>$version</code>\n";
        $p .= "👑 Ваш ранг: <b>$myRank</b>";
        send($chatId, $p);
        break;

    case '/help':
        $h = "📖 <b>СПРАВОЧНИК КОМАНД</b>\n\n";
        $h .= "👤 <b>Базовые:</b> /rules, /info, /ping, /admin\n";
        $h .= "🛡 <b>Мод:</b> /del, /kick, /ban, /unban, /mute, /unmute, /warn, /unwarn\n";
        $h .= "📂 <b>Списки:</b> /mutelist, /warnlist, /spamlist, /stop_list\n";
        $h .= "⚙️ <b>Настройка:</b> /set_rules, /set_welcome, /rank, /whitelist\n";
        $h .= "🎫 <b>Саппорт:</b> /send_support [текст], /agent\n";
        $h .= "⛓ <b>Global:</b> /gg [id] [причина]";
        send($chatId, $h);
        break;

    case '/rules':
        $r = $chatConf['rules'] ?? "Правила еще не установлены админом.";
        send($chatId, "📜 <b>ПРАВИЛА ЧАТА:</b>\n\n$r");
        break;

    case '/admin':
        $allData = sb_req("GET");
        $out = "🛡 <b>АДМИНИСТРАЦИЯ:</b>\n";
        $rNames = [6=>"РАЗРАБОТЧИК", 5=>"ВЛАДЕЛЬЦЫ", 4=>"ЗАМЕСТИТЕЛИ", 1=>"МОДЕРАТОРЫ"];
        $foundAny = false;
        foreach ([6,5,4,3,2,1] as $lvl) {
            $stars = str_repeat("⭐", ($lvl > 5 ? 5 : $lvl));
            $tmp = "";
            foreach ($allData as $it) {
                $rid = str_replace('u_', '', $it['id']);
                $curR = ($rid == $adminId) ? 6 : ($it['data']['ranks'][$chatId] ?? 0);
                if ($curR == $lvl) {
                    $tmp .= "└ " . ($it['data']['name'] ?? "Юзер") . " [<code>$rid</code>]\n";
                    $foundAny = true;
                }
            }
            if ($tmp) $out .= "\n<b>$stars " . ($rNames[$lvl] ?? "РАНГ $lvl") . "</b>\n" . $tmp;
        }
        send($chatId, $foundAny ? $out : "Админов нет.");
        break;

    case '/set_rules':
        if ($myRank < 4) { send($chatId, "❌ Недостаточно прав (нужен 4 ранг)."); break; }
        $chatConf['rules'] = substr($text, 11);
        sb_req("POST", "conf_$chatId", ["id" => "conf_$chatId", "data" => $chatConf]);
        send($chatId, "✅ Правила обновлены.");
        break;

    case '/set_welcome':
        if ($myRank < 4) { send($chatId, "❌ Недостаточно прав."); break; }
        $chatConf['welcome'] = substr($text, 13);
        sb_req("POST", "conf_$chatId", ["id" => "conf_$chatId", "data" => $chatConf]);
        send($chatId, "✅ Приветствие установлено.");
        break;

    case '/send_support':
        $reason = trim(substr($text, 14));
        if (!$reason) { send($chatId, "❌ Опишите проблему после команды."); break; }
        $ticket = "🎫 <b>НОВАЯ ЗАЯВКА #".rand(100,999)."</b>\nОт: <code>$userId</code> ($name)\nЧат: <code>$chatId</code>\nСуть: $reason";
        $allUsers = sb_req("GET");
        foreach($allUsers as $u) {
            $aid = str_replace('u_', '', $u['id']);
            if (($u['data']['is_agent'] ?? false) || $aid == $adminId) {
                send($aid, $ticket);
            }
        }
        send($chatId, "✅ Заявка отправлена персоналу.");
        break;

    case '/gg':
        if (!$isDev && !($uData['is_agent'] ?? false)) { send($chatId, "❌ Только для Агентов."); break; }
        $parts = explode(' ', $text, 3);
        $tId = $reply ? $reply['from']['id'] : ($parts[1] ?? null);
        $reason = $parts[2] ?? ($reply ? ($parts[1] ?? "Спам") : "Нарушение");
        if ($tId && is_numeric($tId)) {
            $globalData['spammers'][$tId] = ['reason' => $reason, 'by' => $userId];
            sb_req("POST", "global_config", ["id" => "global_config", "data" => $globalData]);
            file_get_contents($api . "/banChatMember?chat_id=$chatId&user_id=$tId");
            send($chatId, "⛓ <b>GLOBAL BAN:</b> Юзер <code>$tId</code> заблокирован везде.");
        } else {
            send($chatId, "❌ Укажите ID или ответьте на сообщение.");
        }
        break;

    case '/agent':
        if (!($uData['is_agent'] ?? false) && !$isDev) { send($chatId, "❌ Вы не агент."); break; }
        $num = $uData['agent_num'] ?? "DEV-01";
        send($chatId, "🛡 <b>АГЕНТ #$num</b>\nСотрудник на связи. Личность подтверждена.");
        break;

    case '/unmute':
    case '/unban':
    case '/kick':
    case '/ban':
    case '/mute':
    case '/warn':
        if ($myRank < 1) { send($chatId, "❌ Вы не модератор."); break; }
        if (!$reply) { send($chatId, "❌ Команда работает только ответом на сообщение."); break; }
        $tId = $reply['from']['id'];
        
        if ($cmd == '/kick') {
            file_get_contents($api . "/unbanChatMember?chat_id=$chatId&user_id=$tId");
            send($chatId, "👢 Пользователь кикнут.");
        } elseif ($cmd == '/ban') {
            file_get_contents($api . "/banChatMember?chat_id=$chatId&user_id=$tId");
            send($chatId, "🔨 Забанен.");
        } elseif ($cmd == '/unmute') {
            file_get_contents($api . "/restrictChatMember?chat_id=$chatId&user_id=$tId&permissions=".urlencode(json_encode(['can_send_messages'=>true,'can_send_media_messages'=>true,'can_send_other_messages'=>true,'can_add_web_page_previews'=>true])));
            send($chatId, "🔊 Мут снят.");
        } elseif ($cmd == '/unban') {
            file_get_contents($api . "/unbanChatMember?chat_id=$chatId&user_id=$tId&only_if_banned=true");
            send($chatId, "🔓 Разбанен.");
        } elseif ($cmd == '/warn') {
            $tRes = sb_req("GET", "u_$tId");
            $tD = $tRes[0]['data'] ?? [];
            $tD['warns'][$chatId] = ($tD['warns'][$chatId] ?? 0) + 1;
            if ($tD['warns'][$chatId] >= 3) {
                file_get_contents($api . "/banChatMember?chat_id=$chatId&user_id=$tId");
                $tD['warns'][$chatId] = 0;
                send($chatId, "🚫 Лимит 3/3! Бан.");
            } else {
                send($chatId, "⚠️ Варн: " . $tD['warns'][$chatId] . "/3");
            }
            sb_req("POST", "u_$tId", ["id" => "u_$tId", "data" => $tD]);
        }
        break;

    case '/info':
        $targetId = $reply ? $reply['from']['id'] : $userId;
        $tRes = sb_req("GET", "u_$targetId");
        $tD = $tRes[0]['data'] ?? [];
        $out = "👤 <b>ИНФОРМАЦИЯ:</b>\nID: <code>$targetId</code>\nВарны: " . ($tD['warns'][$chatId] ?? 0) . "/3\nАгент: " . (($tD['is_agent'] ?? false) ? "Да ✅" : "Нет ❌");
        send($chatId, $out);
        break;
}

// СОХРАНЕНИЕ СТАТИСТИКИ (Всегда в конце)
$uData['name'] = $name;
$uData['stats']['total'] = ($uData['stats']['total'] ?? 0) + 1;
sb_req("POST", "u_$userId", ["id" => "u_$userId", "data" => $uData]);
