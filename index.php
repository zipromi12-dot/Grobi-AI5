<?php
// === КОНФИГУРАЦИЯ ===
ini_set('display_errors', 0);
error_reporting(0);

$token   = "8424479487:AAGxVxfmzN4E9sgeSYVlz4JOQUDyZ23E3s0";
$adminId = 7640692963;
$api     = "[api.telegram.org](https://api.telegram.org/bot$token)";
$version = "2.8.0";

// Groq API — самый быстрый бесплатный LLM (~200-400ms)
$groqKey   = "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";
$groqModel = "llama-3.1-8b-instant"; // Альтернатива: gemma2-9b-it

// ===================================================================
// AI МОДЕРАТОР
// ===================================================================

/**
 * Проверяет сообщение через Groq AI.
 * Возвращает массив: ['violation' => bool, 'reason' => string, 'severity' => 1-3]
 * severity: 1=предупреждение, 2=мут, 3=бан
 */
function aiCheckMessage($text, $rules, $groqKey, $groqModel) {
    if (mb_strlen($text) < 3) return ['violation' => false];

    $rulesBlock = $rules
        ? "Правила чата:\n$rules"
        : "Стандартные правила: без мата, оскорблений, угроз, спама, NSFW-контента, разжигания ненависти.";

    $systemPrompt = <<<PROMPT
Ты — AI-модератор Telegram-чата. Твоя задача — анализировать сообщения на нарушения.

$rulesBlock

Отвечай СТРОГО в формате JSON без markdown, без пояснений:
{"violation": true/false, "reason": "краткое описание нарушения на русском или пусто", "severity": 1-3}

severity:
1 = лёгкое нарушение (предупреждение/варн)
2 = среднее нарушение (мут 10 минут)
3 = грубое нарушение (бан: угрозы, NSFW, реклама)

Если нарушений нет — {"violation": false, "reason": "", "severity": 0}
Будь точным, не реагируй на безобидные сообщения.
PROMPT;

    $payload = [
        'model'       => $groqModel,
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => mb_substr($text, 0, 500)],
        ],
        'temperature' => 0.1,
        'max_tokens'  => 100,
    ];

    $ch = curl_init("[api.groq.com](https://api.groq.com/openai/v1/chat/completions)");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $groqKey",
            "Content-Type: application/json",
        ],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    if (!$res) return ['violation' => false];

    $data    = json_decode($res, true);
    $content = $data['choices'][0]['message']['content'] ?? '';

    // Убираем возможный markdown из ответа
    $content = preg_replace('/```json|```/i', '', $content);
    $content = trim($content);

    $result = json_decode($content, true);
    if (!is_array($result)) return ['violation' => false];

    return $result;
}

/**
 * Применяет наказание по уровню severity.
 */
function aiEnforce($chatId, $userId, $userName, $msgId, $severity, $reason, $tD) {
    global $api;

    // Удаляем сообщение всегда
    tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msgId]);

    if ($severity == 1) {
        // Варн
        $tD['warns'][$chatId] = ($tD['warns'][$chatId] ?? 0) + 1;
        sb_req("POST", "u_$userId", ["id" => "u_$userId", "data" => $tD]);

        if ($tD['warns'][$chatId] >= 3) {
            tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
            $tD['warns'][$chatId] = 0;
            sb_req("POST", "u_$userId", ["id" => "u_$userId", "data" => $tD]);
            send($chatId,
                "🤖 <b>AI-модератор:</b> <b>$userName</b> забанен (3 варна).\n" .
                "📌 Причина: " . htmlspecialchars($reason)
            );
        } else {
            $cnt = $tD['warns'][$chatId];
            send($chatId,
                "🤖 <b>AI-модератор:</b> ⚠️ <b>$userName</b>, варн <b>$cnt/3</b>.\n" .
                "📌 " . htmlspecialchars($reason)
            );
        }

    } elseif ($severity == 2) {
        // Мут на 10 минут
        muteUser($chatId, $userId);
        $tD['muted'][$chatId] = true;
        sb_req("POST", "u_$userId", ["id" => "u_$userId", "data" => $tD]);
        send($chatId,
            "🤖 <b>AI-модератор:</b> 🔇 <b>$userName</b> замьючен на 10 мин.\n" .
            "📌 " . htmlspecialchars($reason)
        );
        // Авто-размут через 10 минут (через Telegram until_date)
        $perms = json_encode([
            'can_send_messages'         => false,
            'can_send_media_messages'   => false,
            'can_send_other_messages'   => false,
            'can_add_web_page_previews' => false,
        ]);
        file_get_contents(
            "[api.telegram.org](https://api.telegram.org/bot)" . $GLOBALS['token'] .
            "/restrictChatMember?chat_id=$chatId&user_id=$userId" .
            "&until_date=" . (time() + 600) .
            "&permissions=" . urlencode($perms)
        );

    } elseif ($severity == 3) {
        // Бан
        tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
        send($chatId,
            "🤖 <b>AI-модератор:</b> 🔨 <b>$userName</b> забанен.\n" .
            "📌 " . htmlspecialchars($reason)
        );
    }
}

// ===================================================================
// ДВИЖОК БАЗЫ ДАННЫХ (Supabase)
// ===================================================================
function sb_req($method, $id = null, $data = null) {
    $sbUrl = "[vqpurtindyaiwjgreqdt.supabase.co](https://vqpurtindyaiwjgreqdt.supabase.co/rest/v1/bot_storage)";
    $sbKey = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InZxcHVydGluZHlhaXdqZ3JlcWR0Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM5MjA2NzksImV4cCI6MjA4OTQ5NjY3OX0.pRR7P3quZ7cX5EYZmHOxnx4C1gp9gMQuoMzNFa-lwM4";

    if ($method === "GET") {
        $url = $id
            ? $sbUrl . "?id=eq." . urlencode($id) . "&select=data"
            : $sbUrl . "?select=id,data";
    } else {
        $url = $id ? $sbUrl . "?id=eq." . urlencode($id) : $sbUrl;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $headers = [
        "apikey: $sbKey",
        "Authorization: Bearer $sbKey"
    ];

    if ($method === "POST") {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $headers[] = "Content-Type: application/json";
        $headers[] = "Prefer: resolution=merge-duplicates";
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    curl_close($ch);

    return json_decode($res, true);
}

// ===================================================================
// ОТПРАВКА СООБЩЕНИЙ
// ===================================================================
function send($chatId, $text, $kb = null, $replyId = null) {
    global $api;
    $data = [
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($kb)      $data['reply_markup']        = json_encode($kb);
    if ($replyId) $data['reply_to_message_id'] = $replyId;

    $opts = ['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($data),
    ]];
    return file_get_contents($api . "/sendMessage", false, stream_context_create($opts));
}

function editMsg($chatId, $msgId, $text, $kb = null) {
    global $api;
    $data = [
        'chat_id'                  => $chatId,
        'message_id'               => $msgId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($kb) $data['reply_markup'] = json_encode($kb);

    $opts = ['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($data),
    ]];
    return file_get_contents($api . "/editMessageText", false, stream_context_create($opts));
}

function answerCbq($cbqId, $text = '', $alert = false) {
    global $api;
    $data = ['callback_query_id' => $cbqId, 'text' => $text, 'show_alert' => $alert];
    $opts = ['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($data),
    ]];
    file_get_contents($api . "/answerCallbackQuery", false, stream_context_create($opts));
}

function tgApi($endpoint, $params = []) {
    global $api;
    $url = $api . "/" . $endpoint . "?" . http_build_query($params);
    return json_decode(file_get_contents($url), true);
}

// ===================================================================
// ИНЛАЙН-КЛАВИАТУРЫ
// ===================================================================
function kb_main_menu() {
    return ['inline_keyboard' => [
        [
            ['text' => '📖 Помощь',          'callback_data' => 'menu_help'],
            ['text' => '📜 Правила',          'callback_data' => 'menu_rules'],
        ],
        [
            ['text' => '🛡 Админы',           'callback_data' => 'menu_admins'],
            ['text' => 'ℹ️ Обо мне',         'callback_data' => 'menu_info_self'],
        ],
        [
            ['text' => '📊 Статистика чата',  'callback_data' => 'menu_stats'],
            ['text' => '🤖 AI-модератор',     'callback_data' => 'menu_ai_status'],
        ],
    ]];
}

function kb_mod_menu() {
    return ['inline_keyboard' => [
        [
            ['text' => '📋 Список мутов',  'callback_data' => 'list_mutes'],
            ['text' => '⚠️ Список варнов', 'callback_data' => 'list_warns'],
        ],
        [
            ['text' => '🚫 Спам-лист',     'callback_data' => 'list_spam'],
            ['text' => '🔴 Стоп-лист',     'callback_data' => 'list_stop'],
        ],
        [['text' => '« Назад',             'callback_data' => 'menu_back']],
    ]];
}

function kb_back() {
    return ['inline_keyboard' => [
        [['text' => '« Назад в меню', 'callback_data' => 'menu_back']]
    ]];
}

// ===================================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ===================================================================
function getAllChatMembersFromDB($chatId) {
    $allData = sb_req("GET");
    $members = [];
    foreach ($allData as $row) {
        if (strpos($row['id'], 'u_') !== 0) continue;
        $uid  = str_replace('u_', '', $row['id']);
        $data = $row['data'] ?? [];
        if (
            isset($data['ranks'][$chatId]) ||
            isset($data['warns'][$chatId]) ||
            isset($data['muted'][$chatId])
        ) {
            $members[] = ['id' => $uid, 'name' => $data['name'] ?? "Пользователь"];
        }
    }
    return $members;
}

function muteUser($chatId, $userId) {
    global $api;
    $perms = json_encode([
        'can_send_messages'         => false,
        'can_send_media_messages'   => false,
        'can_send_other_messages'   => false,
        'can_add_web_page_previews' => false,
    ]);
    return file_get_contents(
        $api . "/restrictChatMember?chat_id=$chatId&user_id=$userId&permissions=" . urlencode($perms)
    );
}

function unmuteUser($chatId, $userId) {
    global $api;
    $perms = json_encode([
        'can_send_messages'         => true,
        'can_send_media_messages'   => true,
        'can_send_other_messages'   => true,
        'can_add_web_page_previews' => true,
    ]);
    return file_get_contents(
        $api . "/restrictChatMember?chat_id=$chatId&user_id=$userId&permissions=" . urlencode($perms)
    );
}

// ===================================================================
// ОБРАБОТКА ОБНОВЛЕНИЯ
// ===================================================================
$content = file_get_contents("php://input");
$update  = json_decode($content, true);
if (!$update) exit;

// ----------------------------------------------------------------
// CALLBACK QUERY
// ----------------------------------------------------------------
if (isset($update['callback_query'])) {
    $cbq    = $update['callback_query'];
    $cbqId  = $cbq['id'];
    $cbData = $cbq['data'];
    $cbMsg  = $cbq['message'];
    $chatId = $cbMsg['chat']['id'];
    $msgId  = $cbMsg['message_id'];
    $userId = $cbq['from']['id'];
    $name   = $cbq['from']['first_name'];

    $uDataRes    = sb_req("GET", "u_$userId");
    $uData       = $uDataRes[0]['data'] ?? [];
    $chatConfRes = sb_req("GET", "conf_$chatId");
    $chatConf    = $chatConfRes[0]['data'] ?? [];
    $globalRes   = sb_req("GET", "global_config");
    $globalData  = $globalRes[0]['data'] ?? [];

    $isDev = ($userId == $adminId);
    $chatMemberInfo = tgApi("getChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
    $tgStatus       = $chatMemberInfo['result']['status'] ?? '';
    if ($isDev) $myRank = 6;
    elseif ($tgStatus === 'creator') $myRank = 5;
    else $myRank = (int)($uData['ranks'][$chatId] ?? 0);

    switch ($cbData) {

        case 'menu_back':
        case 'menu_main':
            answerCbq($cbqId);
            editMsg($chatId, $msgId,
                "🤖 <b>GROBI BOT v{$GLOBALS['version']}</b>\n\nВыберите раздел:",
                kb_main_menu()
            );
            break;

        // --- НОВЫЙ: статус AI-модератора ---
        case 'menu_ai_status':
            answerCbq($cbqId);
            $aiEnabled = $chatConf['ai_mod'] ?? true;
            $aiMode    = $chatConf['ai_mode'] ?? 'auto'; // auto / warn_only / log_only
            $modeNames = [
                'auto'      => 'Авто (варн/мут/бан)',
                'warn_only' => 'Только варны',
                'log_only'  => 'Только лог (без действий)',
            ];
            $out  = "🤖 <b>AI-МОДЕРАТОР</b>\n\n";
            $out .= "Статус: " . ($aiEnabled ? "Включён ✅" : "Выключен ❌") . "\n";
            $out .= "Режим: <b>" . ($modeNames[$aiMode] ?? $aiMode) . "</b>\n";
            $out .= "Модель: <b>llama-3.1-8b-instant</b> (Groq)\n\n";
            $out .= "Управление (ранг 4+):\n";
            $out .= "/ai_on — включить\n";
            $out .= "/ai_off — выключить\n";
            $out .= "/ai_mode auto|warn_only|log_only — режим";
            editMsg($chatId, $msgId, $out, kb_back());
            break;

        case 'menu_help':
            answerCbq($cbqId);
            $h  = "📖 <b>СПРАВОЧНИК КОМАНД</b>\n\n";
            $h .= "👤 <b>Базовые:</b>\n/start, /rules, /info, /ping, /admin, /all\n\n";
            $h .= "🛡 <b>Модераторские (ответом):</b>\n/del, /kick, /ban, /unban, /mute, /unmute, /warn, /unwarn\n\n";
            $h .= "⚙️ <b>Настройка (ранг 4+):</b>\n/set_rules, /set_welcome, /rank, /whitelist, /stop_add, /stop_remove\n\n";
            $h .= "🤖 <b>AI-модератор (ранг 4+):</b>\n/ai_on, /ai_off, /ai_mode\n\n";
            $h .= "📂 <b>Списки:</b>\n/mutelist, /warnlist, /spamlist, /stop_list\n\n";
            $h .= "🎫 <b>Поддержка:</b>\n/send_support, /agent\n\n";
            $h .= "⛓ <b>Global (агенты):</b>\n/gg, /unglobalban";
            editMsg($chatId, $msgId, $h, kb_back());
            break;

        case 'menu_rules':
            answerCbq($cbqId);
            $r = $chatConf['rules'] ?? "Правила ещё не установлены.";
            editMsg($chatId, $msgId, "📜 <b>ПРАВИЛА ЧАТА:</b>\n\n$r", kb_back());
            break;

        case 'menu_admins':
            answerCbq($cbqId);
            $allData  = sb_req("GET");
            $out      = "🛡 <b>АДМИНИСТРАЦИЯ:</b>\n";
            $rNames   = [6=>"РАЗРАБОТЧИК",5=>"ВЛАДЕЛЕЦ",4=>"ЗАМЕСТИТЕЛЬ",3=>"СТАРШИЙ МОД",2=>"МОДЕРАТОР",1=>"МОД-СТАЖЁР"];
            $foundAny = false;
            foreach ([6,5,4,3,2,1] as $lvl) {
                $stars = str_repeat("⭐", min($lvl, 5));
                $tmp   = "";
                foreach ($allData as $it) {
                    $rid  = str_replace('u_', '', $it['id']);
                    $curR = ($rid == $adminId) ? 6 : ($it['data']['ranks'][$chatId] ?? 0);
                    if ($curR == $lvl) {
                        $tmp     .= "└ " . htmlspecialchars($it['data']['name'] ?? "Юзер") . " [<code>$rid</code>]\n";
                        $foundAny = true;
                    }
                }
                if ($tmp) $out .= "\n<b>$stars " . ($rNames[$lvl] ?? "РАНГ $lvl") . "</b>\n" . $tmp;
            }
            editMsg($chatId, $msgId, $foundAny ? $out : "Список администраторов пуст.", kb_back());
            break;

        case 'menu_info_self':
            answerCbq($cbqId);
            $tRes = sb_req("GET", "u_$userId");
            $tD   = $tRes[0]['data'] ?? [];
            $out  = "👤 <b>ПРОФИЛЬ:</b>\n";
            $out .= "Имя: <b>" . htmlspecialchars($name) . "</b>\n";
            $out .= "ID: <code>$userId</code>\n";
            $out .= "Ранг: <b>$myRank</b>\n";
            $out .= "Варны: <b>" . ($tD['warns'][$chatId] ?? 0) . "/3</b>\n";
            $out .= "Сообщений: <b>" . ($tD['stats']['total'] ?? 0) . "</b>\n";
            $out .= "Агент: " . (($tD['is_agent'] ?? false) ? "Да ✅" : "Нет ❌");
            editMsg($chatId, $msgId, $out, kb_back());
            break;

        case 'menu_stats':
            answerCbq($cbqId);
            $allData = sb_req("GET");
            $total = $warned = $muted = 0;
            foreach ($allData as $row) {
                if (strpos($row['id'], 'u_') !== 0) continue;
                $d     = $row['data'] ?? [];
                $total += $d['stats']['total'] ?? 0;
                if (!empty($d['warns'][$chatId])) $warned++;
                if (!empty($d['muted'][$chatId])) $muted++;
            }
            $out  = "📊 <b>СТАТИСТИКА ЧАТА</b>\n\n";
            $out .= "💬 Всего сообщений: <b>$total</b>\n";
            $out .= "⚠️ Юзеров с варнами: <b>$warned</b>\n";
            $out .= "🔇 Замьюченных: <b>$muted</b>";
            editMsg($chatId, $msgId, $out, kb_back());
            break;

        case 'list_mutes':
            answerCbq($cbqId);
            if ($myRank < 1) { answerCbq($cbqId, "❌ Нет доступа.", true); break; }
            $allData = sb_req("GET");
            $out = "🔇 <b>СПИСОК МУТОВ:</b>\n";
            $found = false;
            foreach ($allData as $row) {
                if (strpos($row['id'], 'u_') !== 0) continue;
                $d   = $row['data'] ?? [];
                $rid = str_replace('u_', '', $row['id']);
                if (!empty($d['muted'][$chatId])) {
                    $out  .= "└ " . htmlspecialchars($d['name'] ?? "?") . " [<code>$rid</code>]\n";
                    $found = true;
                }
            }
            editMsg($chatId, $msgId, $found ? $out : "Список мутов пуст.", kb_back());
            break;

        case 'list_warns':
            answerCbq($cbqId);
            if ($myRank < 1) { answerCbq($cbqId, "❌ Нет доступа.", true); break; }
            $allData = sb_req("GET");
            $out = "⚠️ <b>СПИСОК ВАРНОВ:</b>\n";
            $found = false;
            foreach ($allData as $row) {
                if (strpos($row['id'], 'u_') !== 0) continue;
                $d   = $row['data'] ?? [];
                $rid = str_replace('u_', '', $row['id']);
                if (!empty($d['warns'][$chatId])) {
                    $cnt   = $d['warns'][$chatId];
                    $out  .= "└ " . htmlspecialchars($d['name'] ?? "?") . " [<code>$rid</code>] — $cnt/3\n";
                    $found = true;
                }
            }
            editMsg($chatId, $msgId, $found ? $out : "Список варнов пуст.", kb_back());
            break;

        case 'list_spam':
            answerCbq($cbqId);
            if ($myRank < 1) { answerCbq($cbqId, "❌ Нет доступа.", true); break; }
            $spammers = $globalData['spammers'] ?? [];
            $out = "🚫 <b>ГЛОБАЛЬНЫЙ СПАМ-ЛИСТ:</b>\n";
            if (empty($spammers)) { editMsg($chatId, $msgId, "Список пуст.", kb_back()); break; }
            foreach ($spammers as $sid => $info) {
                $out .= "└ <code>$sid</code> — " . htmlspecialchars($info['reason'] ?? "?") . "\n";
            }
            editMsg($chatId, $msgId, $out, kb_back());
            break;

        case 'list_stop':
            answerCbq($cbqId);
            if ($myRank < 1) { answerCbq($cbqId, "❌ Нет доступа.", true); break; }
            $stopList = $chatConf['stop_list'] ?? [];
            $out = "🔴 <b>СТОП-ЛИСТ (слова/фразы):</b>\n";
            if (empty($stopList)) { editMsg($chatId, $msgId, "Стоп-лист пуст.", kb_back()); break; }
            foreach ($stopList as $word) {
                $out .= "└ <code>" . htmlspecialchars($word) . "</code>\n";
            }
            editMsg($chatId, $msgId, $out, kb_back());
            break;
    }
    exit;
}

// ----------------------------------------------------------------
// ПРИВЕТСТВИЕ НОВЫХ УЧАСТНИКОВ
// ----------------------------------------------------------------
if (isset($update['message']['new_chat_members'])) {
    $cId     = $update['message']['chat']['id'];
    $confRes = sb_req("GET", "conf_$cId");
    $conf    = $confRes[0]['data'] ?? [];
    if (!empty($conf['welcome'])) {
        $user  = $update['message']['new_chat_members'][0]['first_name'];
        $wText = str_replace("{name}", htmlspecialchars($user), $conf['welcome']);
        send($cId, $wText);
    }
    exit;
}

$msg = $update['message'] ?? null;
if (!$msg) exit;

$chatId = $msg['chat']['id'];
$userId = $msg['from']['id'];
$text   = trim($msg['text'] ?? '');
$reply  = $msg['reply_to_message'] ?? null;
$name   = $msg['from']['first_name'];
$msgId  = $msg['message_id'];

// --- ЗАГРУЗКА ДАННЫХ ---
$uDataRes    = sb_req("GET", "u_$userId");
$uData       = $uDataRes[0]['data'] ?? [];
$chatConfRes = sb_req("GET", "conf_$chatId");
$chatConf    = $chatConfRes[0]['data'] ?? [];
$globalRes   = sb_req("GET", "global_config");
$globalData  = $globalRes[0]['data'] ?? [];

$isDev = ($userId == $adminId);

// --- ГЛОБАЛЬНЫЙ БАН ---
if (isset($globalData['spammers'][$userId])) {
    tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
    exit;
}

// --- ОПРЕДЕЛЕНИЕ РАНГА ---
$chatMemberInfo = tgApi("getChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
$tgStatus       = $chatMemberInfo['result']['status'] ?? '';
if ($isDev) $myRank = 6;
elseif ($tgStatus === 'creator') $myRank = 5;
else $myRank = (int)($uData['ranks'][$chatId] ?? 0);

// --- СТОП-ЛИСТ ---
if ($myRank < 1 && !empty($chatConf['stop_list']) && $text) {
    foreach ($chatConf['stop_list'] as $badWord) {
        if (stripos($text, $badWord) !== false) {
            tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msgId]);
            send($chatId, "⛔ <b>" . htmlspecialchars($name) . "</b>, запрещённое слово удалено.");
            exit;
        }
    }
}

// --- ЗАЩИТА ССЫЛОК ---
if ($myRank < 1 && $text && (preg_match('/(https?:\/\/[^\s]+)/i', $text) || preg_match('/t\.me\//i', $text))) {
    $isWhitelisted = false;
    foreach ($chatConf['whitelist'] ?? [] as $wl) {
        if (strpos($text, $wl) !== false) { $isWhitelisted = true; break; }
    }
    if (!$isWhitelisted) {
        tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msgId]);
        send($chatId, "⚠️ <b>" . htmlspecialchars($name) . "</b>, ссылки запрещены для обычных участников!");
        exit;
    }
}

// ===================================================================
// AI-МОДЕРАЦИЯ (проверяем до обработки команд)
// Пропускаем: команды, модераторов (ранг 1+), пустые сообщения
// ===================================================================
if (
    $text &&                                    // есть текст
    $text[0] !== '/' &&                         // не команда
    $myRank < 1 &&                              // не модератор
    !$isDev &&                                  // не разраб
    ($chatConf['ai_mod'] ?? true) &&            // AI включён
    mb_strlen($text) >= 3                       // не слишком короткое
) {
    $aiMode   = $chatConf['ai_mode'] ?? 'auto';
    $aiResult = aiCheckMessage($text, $chatConf['rules'] ?? '', $groqKey, $groqModel);

    if (!empty($aiResult['violation']) && $aiResult['violation'] === true) {
        $severity = (int)($aiResult['severity'] ?? 1);
        $reason   = $aiResult['reason'] ?? 'Нарушение правил';

        if ($aiMode === 'log_only') {
            // Только логируем — шлём уведомление разрабу
            send($adminId,
                "🤖 <b>AI-лог</b> [чат $chatId]\n" .
                "Юзер: <b>" . htmlspecialchars($name) . "</b> (<code>$userId</code>)\n" .
                "Severity: $severity\n" .
                "Причина: " . htmlspecialchars($reason) . "\n" .
                "Текст: <i>" . htmlspecialchars(mb_substr($text, 0, 200)) . "</i>"
            );
        } elseif ($aiMode === 'warn_only') {
            // Только варн, независимо от severity
            tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msgId]);
            $uData['warns'][$chatId] = ($uData['warns'][$chatId] ?? 0) + 1;
            sb_req("POST", "u_$userId", ["id" => "u_$userId", "data" => $uData]);
            $cnt = $uData['warns'][$chatId];
            if ($cnt >= 3) {
                tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
                $uData['warns'][$chatId] = 0;
                sb_req("POST", "u_$userId", ["id" => "u_$userId", "data" => $uData]);
                send($chatId, "🤖 <b>AI-модератор:</b> <b>" . htmlspecialchars($name) . "</b> забанен (3 варна).\n📌 " . htmlspecialchars($reason));
            } else {
                send($chatId, "🤖 <b>AI-модератор:</b> ⚠️ <b>" . htmlspecialchars($name) . "</b>, варн <b>$cnt/3</b>.\n📌 " . htmlspecialchars($reason));
            }
        } else {
            // auto — полная логика по severity
            aiEnforce($chatId, $userId, htmlspecialchars($name), $msgId, $severity, $reason, $uData);
        }

        // Обновляем статистику и выходим
        $uData['name']           = $name;
        $uData['stats']['total'] = ($uData['stats']['total'] ?? 0) + 1;
        sb_req("POST", "u_$userId", ["id" => "u_$userId", "data" => $uData]);
        exit;
    }
}

// ===================================================================
// КОМАНДЫ
// ===================================================================
if (empty($text) || $text[0] !== '/') {
    $uData['name']           = $name;
    $uData['stats']['total'] = ($uData['stats']['total'] ?? 0) + 1;
    sb_req("POST", "u_$userId", ["id" => "u_$userId", "data" => $uData]);
    exit;
}

$parts = explode(' ', $text);
$cmd   = strtolower(explode('@', $parts[0])[0]);

switch ($cmd) {

    case '/start':
        send($chatId,
            "👋 <b>Привет, " . htmlspecialchars($name) . "!</b>\n\n🤖 Я <b>GROBI Bot v{$version}</b> — модератор этого чата.\nВыберите раздел:",
            kb_main_menu()
        );
        break;

    case '/menu':
        send($chatId, "🤖 <b>GROBI Bot v{$version}</b>\nВыберите раздел:", kb_main_menu());
        break;

    case '/ping':
        $start    = microtime(true);
        $dbStatus = !empty($globalRes) ? "Connected ✅" : "Error ❌";
        $latency  = round((microtime(true) - $start) * 1000);
        $p  = "📶 <b>СИСТЕМА GROBI</b>\n";
        $p .= "━━━━━━━━━━━━━━━\n";
        $p .= "🚀 Отклик: <code>{$latency}ms</code>\n";
        $p .= "🗄 База: <code>$dbStatus</code>\n";
        $p .= "🛠 Версия: <code>$version</code>\n";
        $p .= "👑 Ваш ранг: <b>$myRank</b>\n";
        $p .= "🤖 AI-мод: " . (($chatConf['ai_mod'] ?? true) ? "Вкл ✅" : "Выкл ❌");
        send($chatId, $p);
        break;

    case '/help':
        $h  = "📖 <b>СПРАВОЧНИК КОМАНД</b>\n\n";
        $h .= "👤 <b>Базовые:</b>\n/start, /rules, /info, /ping, /admin, /all\n\n";
        $h .= "🛡 <b>Модераторские (ответом на сообщение):</b>\n/del, /kick, /ban, /unban, /mute, /unmute, /warn, /unwarn\n\n";
        $h .= "⚙️ <b>Настройка чата (ранг 4+):</b>\n/set_rules, /set_welcome, /rank, /whitelist, /stop_add, /stop_remove\n\n";
        $h .= "🤖 <b>AI-модератор (ранг 4+):</b>\n/ai_on — включить\n/ai_off — выключить\n/ai_mode [auto|warn_only|log_only]\n\n";
        $h .= "📂 <b>Списки:</b>\n/mutelist, /warnlist, /spamlist, /stop_list\n\n";
        $h .= "🎫 <b>Поддержка:</b>\n/send_support [текст], /agent\n\n";
        $h .= "⛓ <b>Global (агенты):</b>\n/gg [id] [причина], /unglobalban [id]";
        send($chatId, $h, kb_back());
        break;

    case '/rules':
        $r = $chatConf['rules'] ?? "Правила ещё не установлены администратором.";
        send($chatId, "📜 <b>ПРАВИЛА ЧАТА:</b>\n\n$r", kb_back());
        break;

    case '/admin':
        $allData  = sb_req("GET");
        $out      = "🛡 <b>АДМИНИСТРАЦИЯ:</b>\n";
        $rNames   = [6=>"РАЗРАБОТЧИК",5=>"ВЛАДЕЛЕЦ",4=>"ЗАМЕСТИТЕЛЬ",3=>"СТАРШИЙ МОД",2=>"МОДЕРАТОР",1=>"МОД-СТАЖЁР"];
        $foundAny = false;
        foreach ([6,5,4,3,2,1] as $lvl) {
            $stars = str_repeat("⭐", min($lvl, 5));
            $tmp   = "";
            foreach ($allData as $it) {
                $rid  = str_replace('u_', '', $it['id']);
                $curR = ($rid == $adminId) ? 6 : ($it['data']['ranks'][$chatId] ?? 0);
                if ($curR == $lvl) {
                    $tmp     .= "└ " . htmlspecialchars($it['data']['name'] ?? "Юзер") . " [<code>$rid</code>]\n";
                    $foundAny = true;
                }
            }
            if ($tmp) $out .= "\n<b>$stars " . ($rNames[$lvl] ?? "РАНГ $lvl") . "</b>\n" . $tmp;
        }
        send($chatId, $foundAny ? $out : "Список администраторов пуст.");
        break;

    case '/info':
        $targetId = $reply ? $reply['from']['id'] : $userId;
        $tRes     = sb_req("GET", "u_$targetId");
        $tD       = $tRes[0]['data'] ?? [];
        $tName    = $reply ? ($reply['from']['first_name'] ?? "?") : $name;

        $tMember = tgApi("getChatMember", ['chat_id' => $chatId, 'user_id' => $targetId]);
        $tStatus = $tMember['result']['status'] ?? '—';
        $tIsDev  = ($targetId == $adminId);
        if ($tIsDev) $tRank = 6;
        elseif ($tStatus === 'creator') $tRank = 5;
        else $tRank = (int)($tD['ranks'][$chatId] ?? 0);

        $out  = "👤 <b>ИНФОРМАЦИЯ О ПОЛЬЗОВАТЕЛЕ</b>\n";
        $out .= "━━━━━━━━━━━━━━━\n";
        $out .= "Имя: <b>" . htmlspecialchars($tName) . "</b>\n";
        $out .= "ID: <code>$targetId</code>\n";
        $out .= "Ранг: <b>$tRank</b>\n";
        $out .= "Статус TG: <b>$tStatus</b>\n";
        $out .= "Варны: <b>" . ($tD['warns'][$chatId] ?? 0) . "/3</b>\n";
        $out .= "Мут: " . (!empty($tD['muted'][$chatId]) ? "Да 🔇" : "Нет ✅") . "\n";
        $out .= "Сообщений: <b>" . ($tD['stats']['total'] ?? 0) . "</b>\n";
        $out .= "Агент: " . (($tD['is_agent'] ?? false) ? "Да ✅" : "Нет ❌");
        send($chatId, $out);
        break;

    case '/all':
        if ($myRank < 2) { send($chatId, "❌ Нужен ранг 2+ для использования /all."); break; }
        $members   = getAllChatMembersFromDB($chatId);
        $mentioned = [];
        foreach ($members as $m) {
            $mentioned[] = "<a href='tg://user?id={$m['id']}'>" . htmlspecialchars($m['name']) . "</a>";
        }
        $reason = trim(implode(' ', array_slice($parts, 1)));
        $allMsg  = "📢 <b>ОБЩИЙ СБОР!</b>\n";
        if ($reason) $allMsg .= "📌 Причина: $reason\n";
        $allMsg .= "━━━━━━━━━━━━━━━\n";

        if (empty($mentioned)) {
            $allMsg .= "В базе нет участников для упоминания.\n";
            $allMsg .= "<i>(Участники появляются в базе после первого сообщения)</i>";
            send($chatId, $allMsg);
        } else {
            send($chatId, $allMsg);
            foreach (array_chunk($mentioned, 30) as $chunk) {
                send($chatId, implode(' ', $chunk));
            }
        }
        break;

    // ---------------------------------------------------------------
    // AI-МОДЕРАТОР — управление
    // ---------------------------------------------------------------
    case '/ai_on':
        if ($myRank < 4) { send($chatId, "❌ Недостаточно прав (ранг 4+)."); break; }
        $chatConf['ai_mod'] = true;
        sb_req("POST", "conf_$chatId", ["id" => "conf_$chatId", "data" => $chatConf]);
        send($chatId, "🤖 AI-модератор <b>включён</b> ✅");
        break;

    case '/ai_off':
        if ($myRank < 4) { send($chatId, "❌ Недостаточно прав (ранг 4+)."); break; }
        $chatConf['ai_mod'] = false;
        sb_req("POST", "conf_$chatId", ["id" => "conf_$chatId", "data" => $chatConf]);
        send($chatId, "🤖 AI-модератор <b>выключен</b> ❌");
        break;

    case '/ai_mode':
        if ($myRank < 4) { send($chatId, "❌ Недостаточно прав (ранг 4+)."); break; }
        $mode = $parts[1] ?? '';
        $allowed = ['auto', 'warn_only', 'log_only'];
        if (!in_array($mode, $allowed)) {
            send($chatId, "❌ Режимы: <code>auto</code> | <code>warn_only</code> | <code>log_only</code>");
            break;
        }
        $chatConf['ai_mode'] = $mode;
        sb_req("POST", "conf_$chatId", ["id" => "conf_$chatId", "data" => $chatConf]);
        $modeNames = ['auto'=>'Авто (варн/мут/бан)','warn_only'=>'Только варны','log_only'=>'Только лог'];
        send($chatId, "🤖 Режим AI-модератора: <b>" . $modeNames[$mode] . "</b>");
        break;

    case '/set_rules':
        if ($myRank < 4) { send($chatId, "❌ Недостаточно прав (нужен ранг 4+)."); break; }
        $newRules = trim(substr($text, strlen('/set_rules')));
        if (!$newRules) { send($chatId, "❌ Введите текст правил после команды."); break; }
        $chatConf['rules'] = $newRules;
        sb_req("POST", "conf_$chatId", ["id" => "conf_$chatId", "data" => $chatConf]);
        send($chatId, "✅ Правила обновлены. AI-модератор теперь использует их для проверки.");
        break;

    case '/set_welcome':
        if ($myRank < 4) { send($chatId, "❌ Недостаточно прав."); break; }
        $newWelcome = trim(substr($text, strlen('/set_welcome')));
        if (!$newWelcome) { send($chatId, "❌ Введите текст приветствия. Используйте {name} для имени."); break; }
        $chatConf['welcome'] = $newWelcome;
        sb_req("POST", "conf_$chatId", ["id" => "conf_$chatId", "data" => $chatConf]);
        send($chatId, "✅ Приветствие установлено.\nПример: " . str_replace('{name}', htmlspecialchars($name), $newWelcome));
        break;

    case '/rank':
        if ($myRank < 4) { send($chatId, "❌ Недостаточно прав (нужен ранг 4+)."); break; }
        if ($reply) {
            $tId    = $reply['from']['id'];
            $newRnk = (int)($parts[1] ?? -1);
        } else {
            $tId    = $parts[1] ?? null;
            $newRnk = (int)($parts[2] ?? -1);
        }
        if (!$tId || !is_numeric($tId) || $newRnk < 0 || $newRnk > 5) {
            send($chatId, "❌ Использование: /rank [id] [0-5] или ответом: /rank [0-5]");
            break;
        }
        if ($newRnk >= $myRank && !$isDev) {
            send($chatId, "❌ Нельзя выдать ранг равный или выше своего.");
            break;
        }
        $tRes = sb_req("GET", "u_$tId");
        $tD   = $tRes[0]['data'] ?? [];
        $tD['ranks'][$chatId] = $newRnk;
        sb_req("POST", "u_$tId", ["id" => "u_$tId", "data" => $tD]);
        send($chatId, "✅ Ранг <code>$tId</code> изменён на <b>$newRnk</b>.");
        break;

    case '/whitelist':
        if ($myRank < 4) { send($chatId, "❌ Недостаточно прав."); break; }
        $domain = $parts[1] ?? '';
        if (!$domain) { send($chatId, "❌ Укажите домен: /whitelist example.com"); break; }
        $chatConf['whitelist']   = $chatConf['whitelist'] ?? [];
        $chatConf['whitelist'][] = $domain;
        $chatConf['whitelist']   = array_unique($chatConf['whitelist']);
        sb_req("POST", "conf_$chatId", ["id" => "conf_$chatId", "data" => $chatConf]);
        send($chatId, "✅ <code>$domain</code> добавлен в белый список.");
        break;

    case '/stop_add':
        if ($myRank < 4) { send($chatId, "❌ Недостаточно прав."); break; }
        $word = mb_strtolower(trim($parts[1] ?? ''));
        if (!$word) { send($chatId, "❌ Укажите слово: /stop_add [слово]"); break; }
        $chatConf['stop_list']   = $chatConf['stop_list'] ?? [];
        $chatConf['stop_list'][] = $word;
        $chatConf['stop_list']   = array_unique($chatConf['stop_list']);
        sb_req("POST", "conf_$chatId", ["id" => "conf_$chatId", "data" => $chatConf]);
        send($chatId, "✅ Слово <code>" . htmlspecialchars($word) . "</code> добавлено в стоп-лист.");
        break;

    case '/stop_remove':
        if ($myRank < 4) { send($chatId, "❌ Недостаточно прав."); break; }
        $word = mb_strtolower(trim($parts[1] ?? ''));
        if (!$word) { send($chatId, "❌ Укажите слово: /stop_remove [слово]"); break; }
        $chatConf['stop_list'] = array_values(
            array_filter($chatConf['stop_list'] ?? [], fn($w) => $w !== $word)
        );
        sb_req("POST", "conf_$chatId", ["id" => "conf_$chatId", "data" => $chatConf]);
        send($chatId, "✅ Слово <code>" . htmlspecialchars($word) . "</code> удалено из стоп-листа.");
        break;

    case '/stop_list':
        if ($myRank < 1) { send($chatId, "❌ Нет доступа."); break; }
        $stopList = $chatConf['stop_list'] ?? [];
        if (empty($stopList)) { send($chatId, "🔴 Стоп-лист пуст."); break; }
        $out = "🔴 <b>СТОП-ЛИСТ:</b>\n";
        foreach ($stopList as $w) $out .= "└ <code>" . htmlspecialchars($w) . "</code>\n";
        send($chatId, $out);
        break;

    case '/mutelist':
        if ($myRank < 1) { send($chatId, "❌ Нет доступа."); break; }
        $allData = sb_req("GET");
        $out = "🔇 <b>СПИСОК МУТОВ:</b>\n";
        $found = false;
        foreach ($allData as $row) {
            if (strpos($row['id'], 'u_') !== 0) continue;
            $d   = $row['data'] ?? [];
            $rid = str_replace('u_', '', $row['id']);
            if (!empty($d['muted'][$chatId])) {
                $out  .= "└ " . htmlspecialchars($d['name'] ?? "?") . " [<code>$rid</code>]\n";
                $found = true;
            }
        }
        send($chatId, $found ? $out : "Список мутов пуст.");
        break;

    case '/warnlist':
        if ($myRank < 1) { send($chatId, "❌ Нет доступа."); break; }
        $allData = sb_req("GET");
        $out = "⚠️ <b>СПИСОК ВАРНОВ:</b>\n";
        $found = false;
        foreach ($allData as $row) {
            if (strpos($row['id'], 'u_') !== 0) continue;
            $d   = $row['data'] ?? [];
            $rid = str_replace('u_', '', $row['id']);
            if (!empty($d['warns'][$chatId])) {
                $cnt   = $d['warns'][$chatId];
                $out  .= "└ " . htmlspecialchars($d['name'] ?? "?") . " [<code>$rid</code>] — $cnt/3\n";
                $found = true;
            }
        }
        send($chatId, $found ? $out : "Список варнов пуст.");
        break;

    case '/spamlist':
        if ($myRank < 1) { send($chatId, "❌ Нет доступа."); break; }
        $spammers = $globalData['spammers'] ?? [];
        if (empty($spammers)) { send($chatId, "🚫 Глобальный спам-лист пуст."); break; }
        $out = "🚫 <b>ГЛОБАЛЬНЫЙ СПАМ-ЛИСТ:</b>\n";
        foreach ($spammers as $sid => $info) {
            $out .= "└ <code>$sid</code> — " . htmlspecialchars($info['reason'] ?? "?") . "\n";
        }
        send($chatId, $out);
        break;

    case '/del':
        if ($myRank < 1) { send($chatId, "❌ Вы не модератор."); break; }
        if (!$reply) { send($chatId, "❌ Ответьте на сообщение командой /del."); break; }
        tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $reply['message_id']]);
        tgApi("deleteMessage", ['chat_id' => $chatId, 'message_id' => $msgId]);
        break;

    case '/kick':
    case '/ban':
    case '/unban':
    case '/mute':
    case '/unmute':
    case '/warn':
    case '/unwarn':
        if ($myRank < 1) { send($chatId, "❌ Вы не модератор."); break; }
        if (!$reply) { send($chatId, "❌ Используйте команду ответом на сообщение."); break; }
        $tId   = $reply['from']['id'];
        $tName = htmlspecialchars($reply['from']['first_name'] ?? "Пользователь");

        if ($tId == $userId) { send($chatId, "❌ Нельзя применить к себе."); break; }

        $tRes  = sb_req("GET", "u_$tId");
        $tD    = $tRes[0]['data'] ?? [];
        $tRank = ($tId == $adminId) ? 6 : (int)($tD['ranks'][$chatId] ?? 0);
        if ($tRank >= $myRank && !$isDev) {
            send($chatId, "❌ Нельзя применить к пользователю равного или более высокого ранга.");
            break;
        }

        $reason = trim(implode(' ', array_slice($parts, 1)));

        if ($cmd === '/kick') {
            tgApi("banChatMember",   ['chat_id' => $chatId, 'user_id' => $tId]);
            tgApi("unbanChatMember", ['chat_id' => $chatId, 'user_id' => $tId]);
            send($chatId, "👢 <b>$tName</b> выгнан." . ($reason ? "\n📌 $reason" : ""));

        } elseif ($cmd === '/ban') {
            tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $tId]);
            send($chatId, "🔨 <b>$tName</b> забанен." . ($reason ? "\n📌 $reason" : ""));

        } elseif ($cmd === '/unban') {
            tgApi("unbanChatMember", ['chat_id' => $chatId, 'user_id' => $tId, 'only_if_banned' => true]);
            send($chatId, "🔓 <b>$tName</b> разбанен.");

        } elseif ($cmd === '/mute') {
            $timeArg   = $parts[1] ?? '';
            $untilDate = 0;
            if (preg_match('/^(\d+)(m|h|d)$/', $timeArg, $tm)) {
                $multi     = ['m' => 60, 'h' => 3600, 'd' => 86400];
                $untilDate = time() + $tm[1] * $multi[$tm[2]];
            }
            muteUser($chatId, $tId);
            $tD['muted'][$chatId] = true;
            sb_req("POST", "u_$tId", ["id" => "u_$tId", "data" => $tD]);
            $timeStr = $untilDate ? date("d.m.Y H:i", $untilDate) : "навсегда";
            send($chatId, "🔇 <b>$tName</b> замьючен ($timeStr)." . ($reason ? "\n📌 $reason" : ""));

        } elseif ($cmd === '/unmute') {
            unmuteUser($chatId, $tId);
            unset($tD['muted'][$chatId]);
            sb_req("POST", "u_$tId", ["id" => "u_$tId", "data" => $tD]);
            send($chatId, "🔊 <b>$tName</b> размьючен.");

        } elseif ($cmd === '/warn') {
            $tD['warns'][$chatId] = ($tD['warns'][$chatId] ?? 0) + 1;
            sb_req("POST", "u_$tId", ["id" => "u_$tId", "data" => $tD]);
            if ($tD['warns'][$chatId] >= 3) {
                tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $tId]);
                $tD['warns'][$chatId] = 0;
                sb_req("POST", "u_$tId", ["id" => "u_$tId", "data" => $tD]);
                send($chatId, "🚫 <b>$tName</b> — варн 3/3. Автобан.");
            } else {
                $cnt = $tD['warns'][$chatId];
                send($chatId, "⚠️ <b>$tName</b> получил варн <b>$cnt/3</b>." . ($reason ? "\n📌 $reason" : ""));
            }

        } elseif ($cmd === '/unwarn') {
            if (($tD['warns'][$chatId] ?? 0) > 0) {
                $tD['warns'][$chatId]--;
                sb_req("POST", "u_$tId", ["id" => "u_$tId", "data" => $tD]);
                send($chatId, "✅ Варн снят с <b>$tName</b>. Сейчас: " . $tD['warns'][$chatId] . "/3");
            } else {
                send($chatId, "ℹ️ У <b>$tName</b> нет варнов.");
            }
        }
        break;

    case '/send_support':
        $reason = trim(implode(' ', array_slice($parts, 1)));
        if (!$reason) { send($chatId, "❌ Опишите проблему: /send_support [текст]"); break; }
        $ticket  = "🎫 <b>НОВАЯ ЗАЯВКА #" . rand(100, 999) . "</b>\n";
        $ticket .= "От: <code>$userId</code> (" . htmlspecialchars($name) . ")\n";
        $ticket .= "Чат: <code>$chatId</code>\n";
        $ticket .= "Суть: " . htmlspecialchars($reason);
        $allUsers = sb_req("GET");
        foreach ($allUsers as $u) {
            $aid = str_replace('u_', '', $u['id']);
            if (($u['data']['is_agent'] ?? false) || $aid == $adminId) {
                send($aid, $ticket);
            }
        }
        send($chatId, "✅ Заявка отправлена. Агент свяжется с вами.");
        break;

    case '/agent':
        if (!($uData['is_agent'] ?? false) && !$isDev) { send($chatId, "❌ Вы не агент поддержки."); break; }
        $num = $uData['agent_num'] ?? "DEV-01";
        send($chatId, "🛡 <b>АГЕНТ #$num</b>\nСотрудник на связи. Личность подтверждена.");
        break;

    case '/gg':
        if (!$isDev && !($uData['is_agent'] ?? false)) { send($chatId, "❌ Только для агентов."); break; }
        $tId    = $reply ? $reply['from']['id'] : ($parts[1] ?? null);
        $reason = $reply ? ($parts[1] ?? "Нарушение правил") : ($parts[2] ?? "Нарушение правил");
        if ($tId && is_numeric($tId)) {
            $globalData['spammers'][$tId] = ['reason' => $reason, 'by' => $userId, 'date' => date('d.m.Y')];
            sb_req("POST", "global_config", ["id" => "global_config", "data" => $globalData]);
            tgApi("banChatMember", ['chat_id' => $chatId, 'user_id' => $tId]);
            send($chatId, "⛓ <b>GLOBAL BAN</b>\nЮзер <code>$tId</code> заблокирован глобально.\n📌 " . htmlspecialchars($reason));
        } else {
            send($chatId, "❌ Укажите ID или ответьте на сообщение: /gg [id] [причина]");
        }
        break;

    case '/unglobalban':
        if (!$isDev && !($uData['is_agent'] ?? false)) { send($chatId, "❌ Только для агентов."); break; }
        $tId = $parts[1] ?? null;
        if ($tId && is_numeric($tId)) {
            unset($globalData['spammers'][$tId]);
            sb_req("POST", "global_config", ["id" => "global_config", "data" => $globalData]);
            send($chatId, "✅ Глобальный бан снят с <code>$tId</code>.");
        } else {
            send($chatId, "❌ Укажите ID: /unglobalban [id]");
        }
        break;

    default:
        break;
}

// ===================================================================
// СОХРАНЕНИЕ СТАТИСТИКИ
// ===================================================================
$uData['name']           = $name;
$uData['stats']['total'] = ($uData['stats']['total'] ?? 0) + 1;
sb_req("POST", "u_$userId", ["id" => "u_$userId", "data" => $uData]);
