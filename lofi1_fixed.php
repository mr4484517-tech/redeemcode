<?php

// ============================================================================
// CONFIGURATION
// ============================================================================
$bot_token = "8669683150:AAG5-0pzpXh03PJ_Eg9S-2S3PivHcRNTCQQ";
$admin_ids = [8674318569];

// ============================================================================
// PREMIUM CUSTOM EMOJIS (Strictly mapped from user-provided list to prevent API crashes)
// ============================================================================
define('EMOJI_ACCOUNT',     '6147565374289220368'); // ID 1
define('EMOJI_REFFER',      '6147464060305676048'); // ID 2
define('EMOJI_TUTORIAL',    '6147868521670907133'); // ID 3
define('EMOJI_BONUS',       '6147617184479711380'); // ID 4
define('EMOJI_REDEEM',      '6147902731085420231'); // ID 5
define('EMOJI_STATS',       '6147524086768604985'); // ID 6
define('EMOJI_SUPPORT',     '6147637448135414816'); // ID 7
define('EMOJI_SUCCESS',     '6147815573314082674'); // ID 8
define('EMOJI_CROSS',       '6147439566107186310'); // ID 9
define('EMOJI_PLUS',        '6235628846855492222'); // ID 10
define('EMOJI_MINUS',       '6235253239080555488'); // ID 11
define('EMOJI_BROADCAST',   '6237702328216982810'); // ID 12
define('EMOJI_FOLDER',      '6235252066554484059'); // ID 13
define('EMOJI_MONEY',       '6235646232883107337'); // ID 14
define('EMOJI_WALLET',      '6237742262822901946'); // ID 15
define('EMOJI_HOME',        '6235355429237430006'); // ID 16
define('EMOJI_WARNING',     '6235253239080555488'); // ID 17
define('EMOJI_ROCKET',      '6237702328216982810'); // ID 18
define('EMOJI_CELEBRATION', '6235628846855492222'); // ID 19
define('EMOJI_ID',          '6235252066554484059'); // ID 13
define('EMOJI_DNA',         '6147617184479711380'); // ID 4
define('EMOJI_THUMBSUP',    '6235302918967269680'); // ID 78

// ============================================================================
// WEBHOOK SETUP UTILITY (Checked FIRST before reading payloads)
// ============================================================================
if (isset($_GET['setup'])) {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $uri = explode('?', $_SERVER['REQUEST_URI'])[0];
    $webhook_url = "$protocol://$host$uri";
    
    // Explicitly configure allowed_updates array to retrieve chat join requests natively
    $api_url = "https://api.telegram.org/bot" . $bot_token . "/setWebhook?url=" . urlencode($webhook_url) . "&allowed_updates=" . urlencode(json_encode(["message", "callback_query", "chat_join_request", "chat_member"]));
    $response = file_get_contents($api_url);
    
    header('Content-Type: application/json');
    echo json_encode([
        "webhook_url" => $webhook_url,
        "response" => json_decode($response, true)
    ], JSON_PRETTY_PRINT);
    exit;
}

// ============================================================================
// DATABASE INITIALIZATION (SQLite)
// ============================================================================
$db_file = __DIR__ . '/bot_database.db';

try {
    $db = new PDO("sqlite:$db_file");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create Users Table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        user_id INTEGER PRIMARY KEY,
        username TEXT,
        first_name TEXT,
        balance REAL DEFAULT 2.00,
        referred_by INTEGER DEFAULT NULL,
        referred_rewarded INTEGER DEFAULT 0,
        last_daily_bonus INTEGER DEFAULT 0,
        state TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create Sponsor Channels Table
    $db->exec("CREATE TABLE IF NOT EXISTS channels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel_id TEXT UNIQUE,
        invite_link TEXT
    )");
    
    // Create Redeem Codes Table
    $db->exec("CREATE TABLE IF NOT EXISTS redeem_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE,
        value INTEGER,
        status TEXT DEFAULT 'available'
    )");
    
    // Create Settings Table
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    )");

    // Create Join Requests Tracking Table
    $db->exec("CREATE TABLE IF NOT EXISTS join_requests (
        user_id INTEGER,
        channel_id TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, channel_id)
    )");

    // Clean up blocked/dead legacy Imgur links automatically to prevent CURL timeouts
    $db->exec("DELETE FROM settings WHERE value LIKE '%imgur.com%'");

    // Ensure we have at least one sponsor channel by default
    $stmt = $db->query("SELECT COUNT(*) FROM channels");
    if ($stmt->fetchColumn() == 0) {
        $db->exec("INSERT INTO channels (channel_id, invite_link) VALUES ('@ExampleChannel', 'https://t.me/ExampleChannel')");
    }

} catch (PDOException $e) {
    error_log("Database initialization failed: " . $e->getMessage());
    exit("Database initialization failed.");
}

// ============================================================================
// HELPER FUNCTIONS & SMALL CAPS CONVERTER
// ============================================================================
function api_request($method, $parameters = []) {
    global $bot_token;
    $url = "https://api.telegram.org/bot" . $bot_token . "/" . $method;
    
    $ch = curl_init();
    $parameters_json = json_encode($parameters);
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters_json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    
    // Quick timeout constraints to prevent hanging on blocked assets
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("Curl error: " . curl_error($ch));
        return null;
    }
    curl_close($ch);
    return json_decode($response, true);
}

// Safe fail-safe image dispatcher with dynamic text fallback on asset block errors
function send_photo_safe($chat_id, $photo_url, $caption, $parse_mode = 'HTML', $reply_markup = null) {
    $res = api_request("sendPhoto", [
        "chat_id" => $chat_id,
        "photo" => $photo_url,
        "caption" => $caption,
        "parse_mode" => $parse_mode,
        "reply_markup" => $reply_markup
    ]);
    
    if (!$res || !isset($res['ok']) || !$res['ok']) {
        api_request("sendMessage", [
            "chat_id" => $chat_id,
            "text" => $caption,
            "parse_mode" => $parse_mode,
            "reply_markup" => $reply_markup
        ]);
    }
}

// Safe fail-safe video dispatcher with standard fallback on asset block errors
function send_video_safe($chat_id, $video_url, $caption, $parse_mode = 'HTML', $reply_markup = null) {
    $res = api_request("sendVideo", [
        "chat_id" => $chat_id,
        "video" => $video_url,
        "caption" => $caption,
        "parse_mode" => $parse_mode,
        "reply_markup" => $reply_markup
    ]);
    
    if (!$res || !isset($res['ok']) || !$res['ok']) {
        api_request("sendMessage", [
            "chat_id" => $chat_id,
            "text" => $caption . "\n\n🔗 " . $video_url,
            "parse_mode" => $parse_mode,
            "reply_markup" => $reply_markup
        ]);
    }
}

// Converts standard text to unicode small caps
function to_small_caps($text) {
    $map = [
        'a' => 'ᴀ', 'b' => 'ʙ', 'c' => 'ᴄ', 'd' => 'ᴅ', 'e' => 'ᴇ', 'f' => 'ꜰ', 'g' => 'ɢ', 'h' => 'ʜ', 'i' => 'ɪ', 'j' => 'ᴊ', 'k' => 'ᴋ', 'l' => 'ʟ', 'm' => 'ᴍ', 'n' => 'ɴ', 'o' => 'ᴏ', 'p' => 'ᴘ', 'q' => 'ǫ', 'r' => 'ʀ', 's' => 's', 't' => 'ᴛ', 'u' => 'ᴜ', 'v' => 'ᴠ', 'w' => 'ᴡ', 'x' => 'x', 'y' => 'ʏ', 'z' => 'ᴢ',
        'A' => 'ᴀ', 'B' => 'ʙ', 'C' => 'ᴄ', 'D' => 'ᴅ', 'E' => 'ᴇ', 'F' => 'ꜰ', 'G' => 'ɢ', 'H' => 'ʜ', 'I' => 'ɪ', 'J' => 'ᴊ', 'K' => 'ᴋ', 'L' => 'ʟ', 'M' => 'ᴍ', 'N' => 'ɴ', 'O' => 'ᴏ', 'P' => 'ᴘ', 'Q' => 'ǫ', 'R' => 'ʀ', 'S' => 's', 'T' => 'ᴛ', 'U' => 'ᴜ', 'V' => 'ᴠ', 'W' => 'ᴡ', 'X' => 'x', 'Y' => 'ʏ', 'Z' => 'ᴢ'
    ];
    return strtr($text, $map);
}

// Settings fetcher wrapped inside try-catch block to completely prevent DB write locks crashes
function get_setting($key, $default_value) {
    global $db;
    try {
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val === false) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
            $stmt->execute([$key, $default_value]);
            return $default_value;
        }
        return $val;
    } catch (Exception $e) {
        return $default_value; // Fallback to safe default on database write blocks/locked states
    }
}

function make_btn($text, $callback_or_url, $style = 'primary', $custom_emoji_id = null) {
    $btn = ['text' => $text];
    if (preg_match('/^(http|https|tg):\/\//', $callback_or_url)) {
        $btn['url'] = $callback_or_url;
    } else {
        $btn['callback_data'] = $callback_or_url;
    }
    if ($style) {
        $btn['style'] = $style;
    }
    if ($custom_emoji_id) {
        $btn['icon_custom_emoji_id'] = (string)$custom_emoji_id;
    }
    return $btn;
}

// Fetch dynamically from getMe API directly to resolve old cached username issues
function get_bot_username() {
    $me = api_request("getMe");
    if (isset($me['result']['username'])) {
        return $me['result']['username'];
    }
    return "RedeeCodeWay_Bot"; 
}

function get_unjoined_channels($user_id, $db) {
    global $admin_ids;
    // Admins bypass channel checks automatically for smooth testing operations
    if (in_array($user_id, $admin_ids)) {
        return [];
    }
    
    $stmt = $db->query("SELECT * FROM channels");
    $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $unjoined = [];
    foreach ($channels as $chan) {
        $chan_id = $chan['channel_id'];
        
        $stmt_req = $db->prepare("SELECT 1 FROM join_requests WHERE user_id = ? AND channel_id = ?");
        $stmt_req->execute([$user_id, $chan_id]);
        if ($stmt_req->fetch()) {
            continue; 
        }
        
        if ($chan_id === '@ExampleChannel') {
            $unjoined[] = $chan;
            continue;
        }
        
        $res = api_request("getChatMember", [
            "chat_id" => $chan_id,
            "user_id" => $user_id
        ]);
        
        if ($res && isset($res['result']['status'])) {
            $status = $res['result']['status'];
            if (in_array($status, ['creator', 'administrator', 'member'])) {
                $db->prepare("INSERT OR IGNORE INTO join_requests (user_id, channel_id) VALUES (?, ?)")->execute([$user_id, $chan_id]);
                continue;
            }
        }
        $unjoined[] = $chan;
    }
    return $unjoined;
}

function send_force_join($chat_id, $db) {
    $stmt = $db->query("SELECT * FROM channels");
    $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $keyboard = [];
    $row = [];
    foreach ($channels as $chan) {
        $row[] = make_btn("[JOIN]", $chan['invite_link'], 'primary', EMOJI_BROADCAST);
        if (count($row) === 2) {
            $keyboard[] = $row;
            $row = [];
        }
    }
    if (!empty($row)) {
        $keyboard[] = $row;
    }
    
    $keyboard[] = [
        make_btn(to_small_caps("»»» joined «««"), "check_joined", "success", EMOJI_SUCCESS)
    ];
    
    $force_image = get_setting('image_force_join', 'https://images.pexels.com/photos/1036808/pexels-photo-1036808.jpeg?auto=compress&cs=tinysrgb&h=400');
    $caption = "<tg-emoji emoji-id=\"" . EMOJI_WARNING . "\">💯</tg-emoji> <b>" . to_small_caps("In Order To Start The Bot You Must Have To Join Our Channel(s)") . "</b>";
    
    send_photo_safe($chat_id, $force_image, $caption, "HTML", json_encode(["inline_keyboard" => $keyboard]));
}

function send_unjoined_details($chat_id, $unjoined) {
    $links = [];
    $idx = 1;
    foreach ($unjoined as $chan) {
        $links[] = "🔗 <a href=\"{$chan['invite_link']}\">" . to_small_caps("Sponsor Channel") . " {$idx}</a>";
        $idx++;
    }
    
    $text = "<tg-emoji emoji-id=\"" . EMOJI_CROSS . "\">❌</tg-emoji> <b>" . to_small_caps("You have not joined these channels:") . "</b>\n\n" . 
           implode("\n", $links) . "\n\n" .
           to_small_caps("Please join remaining channels and click joined again.");
           
    $keyboard = [[
        make_btn(to_small_caps("»»» joined «««"), "check_joined", "success", EMOJI_SUCCESS)
    ]];
    
    api_request("sendMessage", [
        "chat_id" => $chat_id,
        "text" => $text,
        "parse_mode" => "HTML",
        "reply_markup" => json_encode(["inline_keyboard" => $keyboard])
    ]);
}

// Menu layout updated exactly in user-requested format & style
function send_main_menu($chat_id, $text = "") {
    if (empty($text)) {
        $text = "<tg-emoji emoji-id=\"" . EMOJI_HOME . "\">🏡</tg-emoji> <b>" . to_small_caps("Welcome To Main Menu Of The Bot") . "</b>";
    }
    
    $keyboard = [
        [
            ["text" => "👤 Aᴄᴄᴏᴜɴᴛ", "style" => "success"],
            ["text" => "🧑‍🤝‍🧑 Rᴇꜰꜰᴇʀ", "style" => "success"]
        ],
        [
            ["text" => "🔰 Tᴜᴛᴏʀɪᴀʟ", "style" => "danger"],
            ["text" => "🎁 Bᴏɴᴜꜱ", "style" => "danger"]
        ],
        [
            ["text" => "⚡ Rᴇᴅᴇᴇᴍ Cᴏᴅᴇ", "style" => "primary"]
        ],
        [
            ["text" => "👀 Sᴛᴀᴛꜱ", "style" => "success"],
            ["text" => "📞 Sᴜᴘᴘᴏʀᴛ", "style" => "success"]
        ]
    ];
    
    api_request("sendMessage", [
        "chat_id" => $chat_id,
        "text" => $text,
        "parse_mode" => "HTML",
        "reply_markup" => json_encode([
            "keyboard" => $keyboard,
            "resize_keyboard" => true
        ])
    ]);
}

function send_admin_panel($chat_id) {
    $text = "👑 <b>Play Store Redeem Code Bot - Admin Control Panel</b>\n\nConfigure sponsor channels, execute broadcasts, adjust points, and load voucher pools.";
    $keyboard = [
        [
            make_btn("📢 Broadcast", "admin_broadcast", "primary"),
            make_btn("📢 Sponsor Channels", "admin_channels", "primary")
        ],
        [
            make_btn("➕ Add Code", "admin_add_code_select", "success"),
            make_btn("🪙 Adjust Points", "admin_adjust_points_start", "success")
        ],
        [
            make_btn("🖼️ Edit Bot Images", "admin_images_menu", "primary"),
            make_btn("⚙️ Edit Metrics", "admin_settings_menu", "primary")
        ]
    ];
    api_request("sendMessage", [
        "chat_id" => $chat_id,
        "text" => $text,
        "parse_mode" => "HTML",
        "reply_markup" => json_encode(["inline_keyboard" => $keyboard])
    ]);
}

// ============================================================================
// WEBHOOK PAYLOAD PROCESSOR
// ============================================================================
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    exit;
}

$base_members = (int)get_setting('base_members', '344168');
$base_10_codes = (int)get_setting('base_10_codes', '44280');
$base_30_codes = (int)get_setting('base_30_codes', '13587');

// Handle Private Channel Pending Join Requests Updates (Approved with high-end premium alignment)
if (isset($update['chat_join_request'])) {
    $event = $update['chat_join_request'];
    $u_id = $event['from']['id'];
    $c_id = $event['chat']['id'];
    $u_fname = isset($event['from']['first_name']) ? $event['from']['first_name'] : 'User';
    $c_title = isset($event['chat']['title']) ? $event['chat']['title'] : 'Sponsor Channel';
    
    $stmt_join = $db->prepare("INSERT OR IGNORE INTO join_requests (user_id, channel_id) VALUES (?, ?)");
    $stmt_join->execute([$u_id, $c_id]);
    
    $bot_user = get_bot_username();
    $start_link = "https://t.me/{$bot_user}?start=user{$u_id}";
    
    // Exact match of reference image text with premium emojis
    $msg_text = "<tg-emoji emoji-id=\"" . EMOJI_THUMBSUP . "\">👍</tg-emoji> <b>Hello {$u_fname}!</b>\n\n" .
                "<tg-emoji emoji-id=\"" . EMOJI_WALLET . "\">🔥</tg-emoji> Aapne <b>{$c_title} {<tg-emoji emoji-id=\"" . EMOJI_DNA . "\">🧬</tg-emoji>}</b> join karne ki request bheji hai.\n\n" .
                "<tg-emoji emoji-id=\"" . EMOJI_SUCCESS . "\">✅</tg-emoji> <b>Request approve karne ke liye niche gaye button par click karke bot ko start karein!</b>";
                
    api_request("sendMessage", [
        "chat_id" => $u_id,
        "text" => $msg_text,
        "parse_mode" => "HTML",
        "reply_markup" => json_encode([
            "inline_keyboard" => [[
                make_btn("✅ 🚀 Start Bot & Verify", $start_link, "success", EMOJI_ROCKET)
            ]]
        ])
    ]);
    exit;
}

if (isset($update['callback_query'])) {
    $callback_query = $update['callback_query'];
    $callback_data = $callback_query['data'];
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];
    $user_id = $callback_query['from']['id'];
    
    $is_admin = in_array($user_id, $admin_ids);
    
    if ($callback_data === "check_joined") {
        $db->prepare("INSERT OR IGNORE INTO join_requests (user_id, channel_id) VALUES (?, '@ExampleChannel')")->execute([$user_id]);
        $unjoined = get_unjoined_channels($user_id, $db);
        
        if (empty($unjoined)) {
            $stmt = $db->prepare("SELECT referred_by, referred_rewarded FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $usr = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($usr && $usr['referred_by'] && !$usr['referred_rewarded']) {
                $ref_id = $usr['referred_by'];
                $db->prepare("UPDATE users SET balance = balance + 5.00 WHERE user_id = ?")->execute([$ref_id]);
                $db->prepare("UPDATE users SET referred_rewarded = 1 WHERE user_id = ?")->execute([$user_id]);
                
                api_request("sendMessage", [
                    "chat_id" => $ref_id,
                    "text" => "<tg-emoji emoji-id=\"" . EMOJI_CELEBRATION . "\">🎉</tg-emoji> <b>" . to_small_caps("New Referral Registered!") . "</b>\n\n+5.00 " . to_small_caps("Points credited to your account."),
                    "parse_mode" => "HTML"
                ]);
            }
            
            api_request("deleteMessage", ["chat_id" => $chat_id, "message_id" => $message_id]);
            send_main_menu($chat_id);
            
            api_request("answerCallbackQuery", [
                "callback_query_id" => $callback_query['id'],
                "text" => "Verified successfully!",
                "show_alert" => false
            ]);
        } else {
            api_request("deleteMessage", ["chat_id" => $chat_id, "message_id" => $message_id]);
            send_unjoined_details($chat_id, $unjoined);
            
            api_request("answerCallbackQuery", [
                "callback_query_id" => $callback_query['id'],
                "text" => "You haven't joined all sponsor channels yet!",
                "show_alert" => true
            ]);
        }
    }
    
    elseif ($callback_data === "claim_daily_bonus") {
        $stmt = $db->prepare("SELECT last_daily_bonus FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $last_bonus = (int)$stmt->fetchColumn();
        
        $now = time();
        $cooldown = 86400;
        
        if ($now - $last_bonus >= $cooldown) {
            $db->prepare("UPDATE users SET balance = balance + 1.00, last_daily_bonus = ? WHERE user_id = ?")->execute([$now, $user_id]);
            api_request("answerCallbackQuery", [
                "callback_query_id" => $callback_query['id'],
                "text" => "Daily Bonus claimed successfully! +1.00 Point credited.",
                "show_alert" => true
            ]);
        } else {
            $remaining = $cooldown - ($now - $last_bonus);
            $hours = floor($remaining / 3600);
            $minutes = floor(($remaining % 3600) / 60);
            api_request("answerCallbackQuery", [
                "callback_query_id" => $callback_query['id'],
                "text" => "Cooldown Active! You can claim again in {$hours}h {$minutes}m.",
                "show_alert" => true
            ]);
        }
    }
    
    elseif ($callback_data === "tasks_bonus") {
        api_request("answerCallbackQuery", [
            "callback_query_id" => $callback_query['id'],
            "text" => "Tasks list is currently empty. Check back later!",
            "show_alert" => true
        ]);
    }
    
    elseif ($callback_data === "redeem_10" || $callback_data === "redeem_30") {
        $value = ($callback_data === "redeem_10") ? 10 : 30;
        $points_required = ($value === 10) ? 15 : 35;
        
        $stmt = $db->prepare("SELECT balance FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user_balance = (float)$stmt->fetchColumn();
        
        if ($user_balance < $points_required) {
            api_request("answerCallbackQuery", [
                "callback_query_id" => $callback_query['id'],
                "text" => "Insufficient balance! You need $points_required Points to redeem a ₹$value code.",
                "show_alert" => true
            ]);
        } else {
            $stmt_code = $db->prepare("SELECT * FROM redeem_codes WHERE value = ? AND status = 'available' LIMIT 1");
            $stmt_code->execute([$value]);
            $code_row = $stmt_code->fetch(PDO::FETCH_ASSOC);
            
            if ($code_row) {
                $db->prepare("UPDATE users SET balance = balance - ? WHERE user_id = ?")->execute([$points_required, $user_id]);
                $db->prepare("UPDATE redeem_codes SET status = 'claimed' WHERE id = ?")->execute([$code_row['id']]);
                
                $metric_key = ($value === 10) ? 'base_10_codes' : 'base_30_codes';
                $db->prepare("UPDATE settings SET value = value + 1 WHERE key = ?")->execute([$metric_key]);
                
                api_request("sendMessage", [
                    "chat_id" => $chat_id,
                    "text" => "<tg-emoji emoji-id=\"5431320092286377312\">🎉</tg-emoji> <b>" . to_small_caps("CONGRATULATIONS!") . "</b>\n\n" . to_small_caps("You have successfully redeemed a") . " ₹{$value} " . to_small_caps("Google Play Redeem Code!") . "\n\n🔑 <b>" . to_small_caps("Code") . " :</b> <code>{$code_row['code']}</code>\n\n<i>" . to_small_caps("Copy the code above and redeem it directly on the Google Play Store.") . "</i>",
                    "parse_mode" => "HTML"
                ]);
            } else {
                api_request("answerCallbackQuery", [
                    "callback_query_id" => $callback_query['id'],
                    "text" => "Out of stock! Admin has been notified to reload the ₹$value code pool.",
                    "show_alert" => true
                ]);
                
                foreach ($admin_ids as $adm) {
                    api_request("sendMessage", [
                        "chat_id" => $adm,
                        "text" => "⚠️ <b>ALERT:</b> Redeem code pool for ₹$value has exhausted. Please load more codes immediately.",
                        "parse_mode" => "HTML"
                    ]);
                }
            }
        }
    }
    
    if ($is_admin) {
        if ($callback_data === "admin_menu") {
            send_admin_panel($chat_id);
            
        } elseif ($callback_data === "admin_broadcast") {
            $db->prepare("UPDATE users SET state = 'admin_broadcast_msg' WHERE user_id = ?")->execute([$user_id]);
            api_request("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "📢 <b>Ready for Broadcast</b>\n\nSend any text message, photo, document, or forward any message. This layout will be cloned exactly to all subscribers.",
                "parse_mode" => "HTML"
            ]);
            
        } elseif ($callback_data === "admin_channels") {
            $stmt = $db->query("SELECT * FROM channels");
            $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $text = "📢 <b>Sponsor Channels List:</b>\n\n";
            $keyboard = [];
            foreach ($channels as $chan) {
                $text .= "• <code>{$chan['channel_id']}</code> -> <a href=\"{$chan['invite_link']}\">Link</a>\n";
                $keyboard[] = [
                    make_btn("❌ Remove " . $chan['channel_id'], "admin_del_chan_" . $chan['id'], "danger")
                ];
            }
            $keyboard[] = [make_btn("➕ Add Channel", "admin_add_channel_start", "success")];
            $keyboard[] = [make_btn("🔙 Back to Panel", "admin_menu", "primary")];
            
            api_request("sendMessage", [
                "chat_id" => $chat_id,
                "text" => $text,
                "parse_mode" => "HTML",
                "reply_markup" => json_encode(["inline_keyboard" => $keyboard])
            ]);
            
        } elseif (strpos($callback_data, "admin_del_chan_") === 0) {
            $chan_db_id = (int)str_replace("admin_del_chan_", "", $callback_data);
            $db->prepare("DELETE FROM channels WHERE id = ?")->execute([$chan_db_id]);
            api_request("answerCallbackQuery", ["callback_query_id" => $callback_query['id'], "text" => "Channel removed.", "show_alert" => false]);
            api_request("deleteMessage", ["chat_id" => $chat_id, "message_id" => $message_id]);
            send_admin_panel($chat_id);
            
        } elseif ($callback_data === "admin_add_channel_start") {
            $db->prepare("UPDATE users SET state = 'admin_add_chan_id' WHERE user_id = ?")->execute([$user_id]);
            api_request("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "➕ <b>Step 1:</b> Enter Channel ID or `@username`:\n\nExample: <code>-1001234567890</code> or <code>@SponsorChannel</code>",
                "parse_mode" => "HTML"
            ]);
            
        } elseif ($callback_data === "admin_add_code_select") {
            $keyboard = [
                [
                    make_btn("₹10 Codes Pool", "admin_load_10", "success"),
                    make_btn("₹30 Codes Pool", "admin_load_30", "success")
                ],
                [make_btn("🔙 Back to Panel", "admin_menu", "primary")]
            ];
            api_request("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "Select the target voucher code value to load codes:",
                "reply_markup" => json_encode(["inline_keyboard" => $keyboard])
            ]);
            
        } elseif ($callback_data === "admin_load_10" || $callback_data === "admin_load_30") {
            $val = ($callback_data === "admin_load_10") ? 10 : 30;
            $db->prepare("UPDATE users SET state = 'admin_adding_codes_$val' WHERE user_id = ?")->execute([$user_id]);
            api_request("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "🔑 <b>Load ₹$val Codes:</b>\n\nSend codes to add to the bot database. You can input multiple codes separated by commas or lines.",
                "parse_mode" => "HTML"
            ]);
            
        } elseif ($callback_data === "admin_adjust_points_start") {
            $db->prepare("UPDATE users SET state = 'admin_points_user_id' WHERE user_id = ?")->execute([$user_id]);
            api_request("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "Enter target User Telegram ID to add points:",
                "parse_mode" => "HTML"
            ]);
            
        } elseif ($callback_data === "admin_settings_menu") {
            $db->prepare("UPDATE users SET state = 'admin_editing_settings' WHERE user_id = ?")->execute([$user_id]);
            $settings_text = "⚙️ <b>Configure Settings Parameters:</b>\n\n" .
                             "Send the setting key followed by value to edit.\n" .
                             "Example: <code>base_members 344168</code>\n" .
                             "Example: <code>base_10_codes 44280</code>\n\n" .
                             "<b>Current Metrics:</b>\n" .
                             "• Base Members: <code>$base_members</code>\n" .
                             "• Distributed ₹10: <code>$base_10_codes</code>\n" .
                             "• Distributed ₹30: <code>$base_30_codes</code>";
            api_request("sendMessage", [
                "chat_id" => $chat_id,
                "text" => $settings_text,
                "parse_mode" => "HTML",
                "reply_markup" => json_encode(["inline_keyboard" => [[make_btn("🔙 Back to Panel", "admin_menu", "primary")]]])
            ]);
            
        } elseif ($callback_data === "admin_images_menu") {
            $keyboard = [
                [
                    make_btn("Force Join Image", "admin_edit_img_force_join", "success"),
                    make_btn("Account Image", "admin_edit_img_account", "success")
                ],
                [
                    make_btn("Refer Image", "admin_edit_img_refer", "success"),
                    make_btn("Tutorial Video", "admin_edit_vid_tutorial", "success")
                ],
                [
                    make_btn("Bonus Image", "admin_edit_img_bonus", "success"),
                    make_btn("Stats Image", "admin_edit_img_stats", "success")
                ],
                [make_btn("🔙 Back to Panel", "admin_menu", "primary")]
            ];
            
            api_request("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "🖼️ <b>Edit Bot Image Sources</b>\n\nSelect an option below to update its image source. You can either **send a photo/video directly** to the bot (recommended), or paste a **Telegram channel post link / direct URL** as text.",
                "parse_mode" => "HTML",
                "reply_markup" => json_encode(["inline_keyboard" => $keyboard])
            ]);
            exit;
            
        } elseif (strpos($callback_data, "admin_edit_img_") === 0) {
            $target = str_replace("admin_edit_img_", "", $callback_data);
            $state_val = "admin_awaiting_img_" . $target;
            
            $db->prepare("UPDATE users SET state = ? WHERE user_id = ?")->execute([$state_val, $user_id]);
            
            $name_map = [
                'force_join' => 'Force Join Screen',
                'account' => 'Account Details',
                'refer' => 'Refer Megaphone',
                'bonus' => 'Claim Bonus Splash',
                'stats' => 'Live Statistics Graph'
            ];
            $display_name = isset($name_map[$target]) ? $name_map[$target] : 'Selected';
            
            api_request("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "🖼️ <b>Ready to update {$display_name} Image</b>\n\n👉 Send a **photo directly** to the bot, or paste a **Telegram channel post link / direct image URL** as text.",
                "parse_mode" => "HTML"
            ]);
            exit;
            
        } elseif (strpos($callback_data, "admin_edit_vid_") === 0) {
            $target = str_replace("admin_edit_vid_", "", $callback_data);
            $state_val = "admin_awaiting_vid_" . $target;
            
            $db->prepare("UPDATE users SET state = ? WHERE user_id = ?")->execute([$state_val, $user_id]);
            
            api_request("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "📹 <b>Ready to update Tutorial Video</b>\n\n👉 Send a **video directly** to the bot, or paste a **Telegram channel post link / direct video URL** as text.",
                "parse_mode" => "HTML"
            ]);
            exit;
        }
    }
    
    @api_request("answerCallbackQuery", ["callback_query_id" => $callback_query['id']]);
    exit;
}

if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $username = $message['from']['username'] ?? 'NoUsername';
    $first_name = $message['from']['first_name'] ?? 'User';
    $text = $message['text'] ?? '';
    
    $is_admin = in_array($user_id, $admin_ids);
    
    // --- USER REGISTRY (MUST RUN FIRST AT THE START OF MESSAGE UPDATE) ---
    $stmt_user = $db->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt_user->execute([$user_id]);
    $user = $stmt_user->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $referrer_id = null;
        if (strpos($text, '/start') === 0) {
            $parts = explode(' ', $text);
            if (isset($parts[1]) && strpos($parts[1], 'user') === 0) {
                $referrer_id = (int)str_replace('user', '', $parts[1]);
            }
        }
        
        // Strict explicit start points default override to 2.00 points instead of using legacy database schemas
        $stmt_ins = $db->prepare("INSERT INTO users (user_id, username, first_name, balance, referred_by) VALUES (?, ?, ?, 2.00, ?)");
        $stmt_ins->execute([$user_id, $username, $first_name, $referrer_id]);
        
        $small_notif = to_small_caps("New User Joined!");
        $small_name = to_small_caps("Name :");
        $small_uname = to_small_caps("Username :");
        $small_uid = to_small_caps("User ID :");
        $small_date = to_small_caps("Date :");
        $small_fname = to_small_caps($first_name);
        
        $admin_notify_text = "<tg-emoji emoji-id=\"" . EMOJI_CELEBRATION . "\">🎉</tg-emoji> <b>{$small_notif}</b>\n\n" .
                             "👤 <b>{$small_name}</b> {$small_fname}\n" .
                             "🌐 <b>{$small_uname}</b> @{$username}\n" .
                             "🆔 <b>{$small_uid}</b> <code>{$user_id}</code>\n" .
                             "📅 <b>{$small_date}</b> " . date("Y-m-d H:i:s");
                             
        foreach ($admin_ids as $adm) {
            api_request("sendMessage", [
                "chat_id" => $adm,
                "text" => $admin_notify_text,
                "parse_mode" => "HTML"
            ]);
        }
        
        $stmt_user->execute([$user_id]);
        $user = $stmt_user->fetch(PDO::FETCH_ASSOC);
    } else {
        // If user already exists but clicks a start referral link, update referral metadata
        if (strpos($text, '/start') === 0) {
            $parts = explode(' ', $text);
            if (isset($parts[1]) && strpos($parts[1], 'user') === 0) {
                $referrer_id = (int)str_replace('user', '', $parts[1]);
                if ($referrer_id !== $user_id) {
                    $db->prepare("UPDATE users SET referred_by = ? WHERE user_id = ? AND referred_by IS NULL AND referred_rewarded = 0")->execute([$referrer_id, $user_id]);
                }
            }
        }
    }
    
    if ($text === '/admin' && $is_admin) {
        $db->prepare("UPDATE users SET state = NULL WHERE user_id = ?")->execute([$user_id]);
        send_admin_panel($chat_id);
        exit;
    }
    
    if ($is_admin && !empty($user['state'])) {
        $state = $user['state'];
        
        // Dynamic Live Admin Image/Video Setting capture block
        $is_updating_image = false;
        $is_updating_video = false;
        $setting_key = '';
        $image_name = '';
        
        if ($state === 'admin_awaiting_img_force_join') {
            $setting_key = 'image_force_join'; $image_name = 'Force Join'; $is_updating_image = true;
        } elseif ($state === 'admin_awaiting_img_account') {
            $setting_key = 'image_account'; $image_name = 'Account'; $is_updating_image = true;
        } elseif ($state === 'admin_awaiting_img_refer') {
            $setting_key = 'image_refer'; $image_name = 'Refer'; $is_updating_image = true;
        } elseif ($state === 'admin_awaiting_img_bonus') {
            $setting_key = 'image_bonus'; $image_name = 'Bonus'; $is_updating_image = true;
        } elseif ($state === 'admin_awaiting_img_stats') {
            $setting_key = 'image_stats'; $image_name = 'Stats'; $is_updating_image = true;
        } elseif ($state === 'admin_awaiting_vid_tutorial') {
            $setting_key = 'video_tutorial'; $image_name = 'Tutorial Video'; $is_updating_video = true;
        }
        
        if ($is_updating_image) {
            $db->prepare("UPDATE users SET state = NULL WHERE user_id = ?")->execute([$user_id]);
            
            $new_value = '';
            if (isset($message['photo'])) {
                // Auto capture file_id of directly uploaded photo file
                $new_value = $message['photo'][count($message['photo']) - 1]['file_id'];
            } else {
                $new_value = trim($text);
            }
            
            if (!empty($new_value)) {
                $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute([$setting_key, $new_value]);
                api_request("sendMessage", [
                    "chat_id" => $chat_id,
                    "text" => "✅ <b>{$image_name} Image successfully updated!</b>\n\nValue saved: <code>" . htmlspecialchars($new_value) . "</code>",
                    "parse_mode" => "HTML"
                ]);
            } else {
                api_request("sendMessage", ["chat_id" => $chat_id, "text" => "❌ Invalid input. Please send a photo or direct image URL as text."]);
            }
            send_admin_panel($chat_id);
            exit;
        }
        
        if ($is_updating_video) {
            $db->prepare("UPDATE users SET state = NULL WHERE user_id = ?")->execute([$user_id]);
            
            $new_value = '';
            if (isset($message['video'])) {
                $new_value = $message['video']['file_id'];
            } elseif (isset($message['document'])) {
                $new_value = $message['document']['file_id'];
            } else {
                $new_value = trim($text);
            }
            
            if (!empty($new_value)) {
                $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute([$setting_key, $new_value]);
                api_request("sendMessage", [
                    "chat_id" => $chat_id,
                    "text" => "✅ <b>{$image_name} successfully updated!</b>\n\nValue saved: <code>" . htmlspecialchars($new_value) . "</code>",
                    "parse_mode" => "HTML"
                ]);
            } else {
                api_request("sendMessage", ["chat_id" => $chat_id, "text" => "❌ Invalid input. Please send a video or direct URL as text."]);
            }
            send_admin_panel($chat_id);
            exit;
        }
        
        if ($state === 'admin_broadcast_msg') {
            $db->prepare("UPDATE users SET state = NULL WHERE user_id = ?")->execute([$user_id]);
            
            $stmt_all = $db->query("SELECT user_id FROM users");
            $all_users = $stmt_all->fetchAll(PDO::FETCH_COLUMN);
            
            $sent = 0; $failed = 0;
            foreach ($all_users as $target_id) {
                $res = api_request("copyMessage", [
                    "chat_id" => $target_id,
                    "from_chat_id" => $chat_id,
                    "message_id" => $message['message_id']
                ]);
                if ($res && isset($res['ok']) && $res['ok'] == true) {
                    $sent++;
                } else {
                    $failed++;
                }
                usleep(35000);
            }
            api_request("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "✅ <b>Broadcast Completed!</b>\n\n• Sent: <code>$sent</code>\n• Failed: <code>$failed</code>",
                "parse_mode" => "HTML"
            ]);
            send_admin_panel($chat_id);
            exit;
        }
        
        elseif ($state === 'admin_add_chan_id') {
            $db->prepare("UPDATE users SET state = 'admin_add_chan_link' WHERE user_id = ?")->execute([$user_id]);
            $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('tmp_chan_id', ?)")->execute([$text]);
            api_request("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "🔗 <b>Step 2:</b> Enter Channel Invite Link:\n\nExample: <code>https://t.me/+XXXXXXXX</code>",
                "parse_mode" => "HTML"
            ]);
            exit;
        }
        
        elseif ($state === 'admin_add_chan_link') {
            $db->prepare("UPDATE users SET state = NULL WHERE user_id = ?")->execute([$user_id]);
            $chan_id = get_setting('tmp_chan_id', '');
            
            if ($chan_id) {
                // Auto-delete standard placeholder channel on configuring first sponsor channel
                $db->prepare("DELETE FROM channels WHERE channel_id = '@ExampleChannel'")->execute();
                
                $db->prepare("INSERT OR REPLACE INTO channels (channel_id, invite_link) VALUES (?, ?)")->execute([$chan_id, $text]);
                api_request("sendMessage", [
                    "chat_id" => $chat_id,
                    "text" => "✅ <b>Sponsor Channel Configured!</b>\n\n• Channel: <code>$chan_id</code>\n• Invite Link: $text",
                    "parse_mode" => "HTML"
                ]);
            }
            send_admin_panel($chat_id);
            exit;
        }
        
        elseif (strpos($state, 'admin_adding_codes_') === 0) {
            $val = (int)str_replace('admin_adding_codes_', '', $state);
            $db->prepare("UPDATE users SET state = NULL WHERE user_id = ?")->execute([$user_id]);
            
            $codes = preg_split('/[\r\n,]+/', $text);
            $loaded = 0;
            foreach ($codes as $code) {
                $code = trim($code);
                if (empty($code)) continue;
                try {
                    $db->prepare("INSERT INTO redeem_codes (code, value) VALUES (?, ?)")->execute([$code, $val]);
                    $loaded++;
                } catch (Exception $e) {}
            }
            api_request("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "✅ Loaded <code>$loaded</code> codes to ₹$val pool.",
                "parse_mode" => "HTML"
            ]);
            send_admin_panel($chat_id);
            exit;
        }
        
        elseif ($state === 'admin_points_user_id') {
            $db->prepare("UPDATE users SET state = 'admin_points_amt' WHERE user_id = ?")->execute([$user_id]);
            $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('tmp_pts_id', ?)")->execute([$text]);
            api_request("sendMessage", ["chat_id" => $chat_id, "text" => "Now enter points balance amount to add (e.g. 5.00 or -2.00):"]);
            exit;
        }
        
        elseif ($state === 'admin_points_amt') {
            $db->prepare("UPDATE users SET state = NULL WHERE user_id = ?")->execute([$user_id]);
            $target = (int)get_setting('tmp_pts_id', '0');
            $amt = (float)$text;
            
            $db->prepare("UPDATE users SET balance = balance + ? WHERE user_id = ?")->execute([$amt, $target]);
            api_request("sendMessage", ["chat_id" => $chat_id, "text" => "✅ Added $amt Points to user ID $target."]);
            api_request("sendMessage", [
                "chat_id" => $target,
                "text" => "🎉 <b>Balance Credited!</b>\n\nAdmin has adjusted your balance by +$amt Points.",
                "parse_mode" => "HTML"
            ]);
            send_admin_panel($chat_id);
            exit;
        }
        
        elseif ($state === 'admin_editing_settings') {
            $db->prepare("UPDATE users SET state = NULL WHERE user_id = ?")->execute([$user_id]);
            $parts = explode(' ', $text, 2);
            if (count($parts) === 2) {
                $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute([$parts[0], $parts[1]]);
                api_request("sendMessage", ["chat_id" => $chat_id, "text" => "✅ Setting [{$parts[0]}] updated to: {$parts[1]}"]);
            } else {
                api_request("sendMessage", ["chat_id" => $chat_id, "text" => "❌ Invalid settings format."]);
            }
            send_admin_panel($chat_id);
            exit;
        }
    }
    
    // User Support State Processing
    if (!empty($user['state']) && $user['state'] === 'awaiting_support_msg') {
        $db->prepare("UPDATE users SET state = NULL WHERE user_id = ?")->execute([$user_id]);
        
        $small_msg = to_small_caps("Support message sent to admin. He'll reply soon.");
        $small_caption = to_small_caps("Message :");
        
        api_request("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "<tg-emoji emoji-id=\"" . EMOJI_SUCCESS . "\">📞</tg-emoji> <b>{$small_msg}</b>\n\n👉 <b>{$small_caption}</b> <code>$text</code>",
            "parse_mode" => "HTML"
        ]);
        
        foreach ($admin_ids as $adm) {
            api_request("sendMessage", [
                "chat_id" => $adm,
                "text" => "⚠️ <b>NEW SUPPORT MESSAGE!</b>\n\n👤 From: {$first_name} (@{$username})\n🆔 User ID: <code>$user_id</code>\n💬 Message: {$text}\n\n<i>To reply, send: <code>/reply {$user_id} [message]</code></i>",
                "parse_mode" => "HTML"
            ]);
        }
        
        send_main_menu($chat_id);
        exit;
    }
    
    if ($is_admin && strpos($text, '/reply ') === 0) {
        $parts = explode(' ', $text, 3);
        if (count($parts) === 3) {
            $target = (int)$parts[1];
            $msg = trim($parts[2]);
            api_request("sendMessage", [
                "chat_id" => $target,
                "text" => "📞 <b>Support Team Reply:</b>\n\n$msg",
                "parse_mode" => "HTML"
            ]);
            api_request("sendMessage", ["chat_id" => $chat_id, "text" => "✅ Reply successfully dispatched to $target."]);
        } else {
            api_request("sendMessage", ["chat_id" => $chat_id, "text" => "❌ Correct reply syntax: `/reply [user_id] [message]`"]);
        }
        exit;
    }
    
    // Command Checks
    if (strpos($text, '/start') === 0) {
        $unjoined = get_unjoined_channels($user_id, $db);
        if (empty($unjoined)) {
            send_main_menu($chat_id);
        } else {
            send_force_join($chat_id, $db);
        }
        exit;
    }
    
    // --- USER REPLY KEYBOARD TRIGGERS ---
    // Detect by readable words; custom/premium emoji are not reliable in message text.
    $text_clean = trim($text);
    $text_upper = strtoupper($text_clean);

    $is_account_trigger  = (stripos($text_clean, 'account') !== false || mb_stripos($text_clean, 'ᴀᴄᴄᴏᴜɴᴛ') !== false);
    $is_refer_trigger    = (stripos($text_clean, 'refer') !== false || stripos($text_clean, 'reffer') !== false || mb_stripos($text_clean, 'ʀᴇꜰꜰᴇʀ') !== false);
    $is_tutorial_trigger = (stripos($text_clean, 'tutorial') !== false || mb_stripos($text_clean, 'ᴛᴜᴛᴏʀɪᴀʟ') !== false);
    $is_bonus_trigger    = (stripos($text_clean, 'bonus') !== false || mb_stripos($text_clean, 'ʙᴏɴᴜꜱ') !== false);
    $is_redeem_trigger   = (stripos($text_clean, 'redeem') !== false || mb_stripos($text_clean, 'ʀᴇᴅᴇᴇᴍ') !== false);
    $is_stats_trigger    = (stripos($text_clean, 'stats') !== false || mb_stripos($text_clean, 'ꜱᴛᴀᴛꜱ') !== false);
    $is_support_trigger  = (stripos($text_clean, 'support') !== false || mb_stripos($text_clean, 'ꜱᴜᴘᴘᴏʀᴛ') !== false);

    if ($is_account_trigger) {
        $unjoined = get_unjoined_channels($user_id, $db);
        if (!empty($unjoined)) {
            send_unjoined_details($chat_id, $unjoined);
            exit;
        }
        $avatar = get_setting('image_account', 'https://images.pexels.com/photos/220453/pexels-photo-220453.jpeg?auto=compress&cs=tinysrgb&h=400');
        $bal = sprintf("%.2f", (float)$user['balance']);
        
        $s_name = to_small_caps("Name :");
        $s_uid = to_small_caps("User Id :");
        $s_bal = to_small_caps("Balance :");
        $s_pts = to_small_caps("Points");
        $s_first_name = to_small_caps($first_name);
        
        $cap = "<tg-emoji emoji-id=\"" . EMOJI_ACCOUNT . "\">👤</tg-emoji> <b>{$s_name}</b> ✎ {$s_first_name} ✎\n" .
               "<tg-emoji emoji-id=\"" . EMOJI_ID . "\">🆔</tg-emoji> <b>{$s_uid}</b> <code>{$user_id}</code>\n" .
               "<tg-emoji emoji-id=\"" . EMOJI_WALLET . "\">🔥</tg-emoji> <b>{$s_bal}</b> <code>{$bal}</code> {$s_pts}";
               
        send_photo_safe($chat_id, $avatar, $cap, "HTML");
        exit;
    }
    
    if ($is_refer_trigger) {
        $unjoined = get_unjoined_channels($user_id, $db);
        if (!empty($unjoined)) {
            send_unjoined_details($chat_id, $unjoined);
            exit;
        }
        $ref_img = get_setting('image_refer', 'https://images.pexels.com/photos/7598022/pexels-photo-7598022.jpeg?auto=compress&cs=tinysrgb&h=400');
        $bot_user = get_bot_username();
        $ref_link = "https://t.me/{$bot_user}?start=user{$user_id}";
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE referred_by = ? AND referred_rewarded = 1");
        $stmt->execute([$user_id]);
        $reffered_count = $stmt->fetchColumn();
        
        $s_cap = "<tg-emoji emoji-id=\"" . EMOJI_REFFER . "\">📢</tg-emoji> <b>" . to_small_caps("Reffer Link To Share Link With Your Friends:") . "</b> <tg-emoji emoji-id=\"" . EMOJI_CELEBRATION . "\">💖</tg-emoji>\n" .
                 "{$ref_link}\n\n" .
                 "👤 <b>" . to_small_caps("Per Reffer:") . "</b> 5 " . to_small_caps("Points") . "\n" .
                 "🤝 <b>" . to_small_caps("Total Reffered:") . "</b> {$reffered_count}";
               
        $kb = [[
            make_btn(to_small_caps("Share To Friends"), "https://t.me/share/url?url=" . urlencode($ref_link) . "&text=" . urlencode("Get free Google Play Redeem Codes! 🎁⚡"), "primary", EMOJI_ROCKET)
        ]];
        
        send_photo_safe($chat_id, $ref_img, $s_cap, "HTML", json_encode(["inline_keyboard" => $kb]));
        exit;
    }
    
    if ($is_tutorial_trigger) {
        $unjoined = get_unjoined_channels($user_id, $db);
        if (!empty($unjoined)) {
            send_unjoined_details($chat_id, $unjoined);
            exit;
        }
        $tut_vid = get_setting('video_tutorial', 'https://www.w3schools.com/html/mov_bbb.mp4');
        $cap = "<b>" . to_small_caps("How To Get Redeem Code Tutorial") . "</b> <tg-emoji emoji-id=\"6147868521670907133\">🔰</tg-emoji>";
        
        send_video_safe($chat_id, $tut_vid, $cap, "HTML");
        exit;
    }
    
    if ($is_bonus_trigger) {
        $unjoined = get_unjoined_channels($user_id, $db);
        if (!empty($unjoined)) {
            send_unjoined_details($chat_id, $unjoined);
            exit;
        }
        $bonus_img = get_setting('image_bonus', 'https://images.pexels.com/photos/6185664/pexels-photo-6185664.jpeg?auto=compress&cs=tinysrgb&h=400');
        
        $kb = [[
            make_btn(to_small_caps("Daily Bonus"), "claim_daily_bonus", "danger", EMOJI_CELEBRATION),
            make_btn(to_small_caps("Tasks"), "tasks_bonus", "danger", EMOJI_FOLDER)
        ]];
        
        $cap = "<b>" . to_small_caps("Claim Your Bonus Here") . "</b> <tg-emoji emoji-id=\"6147439566107186310\">😉</tg-emoji>";
        
        send_photo_safe($chat_id, $bonus_img, $cap, "HTML", json_encode(["inline_keyboard" => $kb]));
        exit;
    }
    
    if ($is_redeem_trigger) {
        $unjoined = get_unjoined_channels($user_id, $db);
        if (!empty($unjoined)) {
            send_unjoined_details($chat_id, $unjoined);
            exit;
        }
        $bal = (int)$user['balance'];
        
        $text_redeem = "<tg-emoji emoji-id=\"6235646232883107337\">💸</tg-emoji> <b>" . to_small_caps("You Can Exchange Your Point to Many Reeedem Code") . "</b>\n\n" .
                       "<tg-emoji emoji-id=\"6237742262822901946\">🦖</tg-emoji> <b>" . to_small_caps("Your Balance") . "</b> <code>{$bal}</code> " . to_small_caps("Points") . "\n\n" .
                       "🎯 <b>" . to_small_caps("Exchange Point to ~") . "</b>\n" .
                       "🔴 ₹10 " . to_small_caps("Reedem Code") . " [ 15 " . to_small_caps("POINT") . " ]\n" .
                       "🔴 ₹30 " . to_small_caps("Reedem Code") . " [ 35 " . to_small_caps("POINT") . " ]";
                       
        $kb = [[
            make_btn(to_small_caps("10₹ Code ✅"), "redeem_10", "success", EMOJI_SUCCESS),
            make_btn(to_small_caps("30₹ Code ✅"), "redeem_30", "success", EMOJI_SUCCESS)
        ]];
        
        api_request("sendMessage", [
            "chat_id" => $chat_id,
            "text" => $text_redeem,
            "parse_mode" => "HTML",
            "reply_markup" => json_encode(["inline_keyboard" => $kb])
        ]);
        exit;
    }
    
    if ($is_stats_trigger) {
        $unjoined = get_unjoined_channels($user_id, $db);
        if (!empty($unjoined)) {
            send_unjoined_details($chat_id, $unjoined);
            exit;
        }
        $stats_img = get_setting('image_stats', 'https://images.pexels.com/photos/590022/pexels-photo-590022.jpeg?auto=compress&cs=tinysrgb&h=400');
        $bot_user = get_bot_username();
        
        $stmt_users = $db->query("SELECT COUNT(*) FROM users");
        $registered_users = (int)$stmt_users->fetchColumn();
        $total_members_count = $base_members + $registered_users;
        
        $text_stats = "<tg-emoji emoji-id=\"" . EMOJI_STATS . "\">📊</tg-emoji> <b>" . to_small_caps("Bot Live Statatics") . "</b>\n\n" .
                      "🦊 <b>" . to_small_caps("Bot :") . "</b> @{$bot_user}\n" .
                      "👥 <b>" . to_small_caps("Total Members :") . "</b> <code>{$total_members_count}</code>\n\n" .
                      "<b>" . to_small_caps("TOTAL REDEEM CODE DISTRIBUTED :") . "</b>\n" .
                      "🔴 ₹10 " . to_small_caps("Code :") . " <code>{$base_10_codes}</code>\n" .
                      "🔴 ₹30 " . to_small_caps("Code :") . " <code>{$base_30_codes}</code>";
                      
        send_photo_safe($chat_id, $stats_img, $text_stats, "HTML");
        exit;
    }
    
    if ($is_support_trigger) {
        $db->prepare("UPDATE users SET state = 'awaiting_support_msg' WHERE user_id = ?")->execute([$user_id]);
        
        $s_text = to_small_caps("Hey How Can We Help You ?");
        api_request("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "<tg-emoji emoji-id=\"" . EMOJI_SUPPORT . "\">📞</tg-emoji> <b>{$s_text}</b>",
            "parse_mode" => "HTML"
        ]);
        exit;
    }
}