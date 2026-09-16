<?php
/*
This bot is a very simple budget thing.
With /start you tell it how much money you have, then you send
messages to it like "-200 food" and it records it as a transaction.
It can tell you how much money you have and calculates how
much you can spend daily so you do not go under zero.

It is made for Forints so it works with int, you have to
rewrite it to float if you have fancy currency with cents.

For me paycheque day is the 6th so it is hardcoded in it,
you have to change that too so it works for you

*/
function log_error(string $what): void
{
    @file_put_contents(__DIR__ . '/bot-error.log',
        date('Y-m-d H:i:s') . '  ' . $what . "\n", FILE_APPEND);
}
set_exception_handler(function ($e) {
    log_error('EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(200); echo 'ok'; exit;
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        log_error('FATAL: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
        if (!headers_sent()) { http_response_code(200); }
    }
});

// ============================== CONFIG ======================================

$BOT_TOKEN = ''; // botfather will provide it

$WEBHOOK_SECRET = ''; // come up with something and register the hook

$CURRENCY = 'HUF'; // it is made for Forints so it deals with ints. You need to 
                   // adjust the code to use float

// Uncomment /start in the router switch at the bottom so you can create your 
// database and opening amount then comment it back so it doesn't clear everything 
//on start. I needed that for debug reasons and left it in

// ============================ END OF CONFIG =================================

const DB_FILE    = __DIR__ . '/konyveles.sqlite';
const STATE_FILE = __DIR__ . '/state.json';
const OWNER_FILE = __DIR__ . '/owner.txt';

function bot_token(): string
{
    global $BOT_TOKEN;
    if ($BOT_TOKEN !== '') { return trim($BOT_TOKEN); }
    if (is_readable(__DIR__ . '/token.txt')) {
        return trim((string) file_get_contents(__DIR__ . '/token.txt'));
    }
    log_error('no token: set $BOT_TOKEN at the top of bot.php or create token.txt');
    http_response_code(200);
    echo 'no token: set $BOT_TOKEN at the top of bot.php, or create token.txt next to it';
    exit;
}

function tg(string $method, array $params)
{
    $url = 'https://api.telegram.org/bot' . bot_token() . '/' . $method;
    $body = http_build_query($params);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 20,
        ]);
        $r = curl_exec($ch);
        if ($r === false) { log_error('curl: ' . curl_error($ch)); }
        curl_close($ch);
        return $r;
    }
    if (ini_get('allow_url_fopen')) {
        return @file_get_contents($url, false, stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 20,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
        ]]));
    }
    log_error('no curl and allow_url_fopen is off - this host cannot reach Telegram');
    return false;
}

function say(int $chat, string $text): void
{
    tg('sendMessage', ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML']);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) { return $pdo; }
    if (!extension_loaded('pdo_sqlite')) {
        log_error('pdo_sqlite is not installed on this host - the bot cannot store anything');
        throw new RuntimeException('pdo_sqlite missing');
    }
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');   // survives a crash mid-write
    $pdo->exec('CREATE TABLE IF NOT EXISTS tx (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        ts       TEXT    NOT NULL,
        amount   INTEGER NOT NULL,   -- whole forints
        descr    TEXT    NOT NULL,
        running  INTEGER NOT NULL    -- whole forints
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS tx_ts ON tx(ts)');
    @chmod(DB_FILE, 0600);
    return $pdo;
}

function tx_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM tx')->fetchColumn();
}

function total(): int
{
    $v = db()->query('SELECT running FROM tx ORDER BY id DESC LIMIT 1')->fetchColumn();
    return $v === false ? 0 : (int) $v;
}

function today_total(): int
{
    $today = date('Y-m-d');
    $st = db()->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM tx WHERE substr(ts, 1, 10) = ?
    ");
    $st->execute([$today]);
    return (int) $st->fetchColumn();
}

function add_tx(int $cents, string $descr): int
{
    $run = total() + $cents;
    $st = db()->prepare('INSERT INTO tx (ts, amount, descr, running) VALUES (?,?,?,?)');
    $st->execute([date('Y-m-d H:i:s'), $cents, $descr, $run]);
    return $run;
}

function reset_book(int $cents): void
{
    db()->exec('DELETE FROM tx');
    db()->exec('DELETE FROM sqlite_sequence WHERE name=\'tx\'');
    add_tx($cents, 'Kezdő egyenleg');
}

function last_rows(int $n): array
{
    $st = db()->prepare('SELECT * FROM tx ORDER BY id DESC LIMIT ?');
    $st->bindValue(1, $n, PDO::PARAM_INT);
    $st->execute();
    return array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
}

function rows_on(string $day): array
{
    $st = db()->prepare('SELECT * FROM tx WHERE ts LIKE ? ORDER BY id');
    $st->execute([$day . '%']);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function remove_last(): ?array
{
    $r = db()->query('SELECT * FROM tx ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$r) { return null; }
    db()->prepare('DELETE FROM tx WHERE id=?')->execute([$r['id']]);
    return $r;
}

function recompute(): void
{
    $run = 0;
    $sel = db()->query('SELECT id, amount FROM tx ORDER BY id');
    $upd = db()->prepare('UPDATE tx SET running=? WHERE id=?');
    foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $run += (int) $r['amount'];
        $upd->execute([$run, $r['id']]);
    }
}

function export_csv(): string
{
    $f = tempnam(sys_get_temp_dir(), 'kv') . '.csv';
    $fh = fopen($f, 'w');
    fputcsv($fh, ['datetime', 'amount', 'description', 'running_total'], ',', '"', '\\');
    foreach (db()->query('SELECT * FROM tx ORDER BY id') as $r) {
        fputcsv($fh, [$r['ts'], $r['amount'], $r['descr'], $r['running']], ',', '"', '\\');
    }
    fclose($fh);
    return $f;
}

function state(): array
{
    if (!is_file(STATE_FILE)) { return []; }
    $d = json_decode((string) file_get_contents(STATE_FILE), true);
    return is_array($d) ? $d : [];
}

function state_save(array $s): void
{
    $tmp = STATE_FILE . '.tmp';
    file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    rename($tmp, STATE_FILE);
    @chmod(STATE_FILE, 0600);
}

function parse_amount(string $s): ?float
{
    $s = trim($s);
    if ($s === '' || !preg_match('/^[+-]?[\d][\d \x{00A0}.,]*$/u', $s)) { return null; }
    $sign = 1.0;
    if ($s[0] === '-') { $sign = -1.0; $s = substr($s, 1); }
    elseif ($s[0] === '+') { $s = substr($s, 1); }
    $s = str_replace([' ', "\xc2\xa0"], '', $s);
   
    if (preg_match('/^(.*)[.,](\d{1,2})$/', $s, $m) && $m[1] !== '') {
        $whole = preg_replace('/[.,]/', '', $m[1]);
        if ($whole === '' || !preg_match('/^\d+$/', $whole)) { return null; }
        return $sign * ((float) $whole + ((float) $m[2]) / (strlen($m[2]) === 1 ? 10 : 100));
    }
    $digits = preg_replace('/[.,]/', '', $s);
    if ($digits === '' || !preg_match('/^\d+$/', $digits)) { return null; }
    return $sign * (float) $digits;
}

function parse_entry(string $text): ?array
{
    if (!preg_match('/^([+-]?[\d][\d \x{00A0}.,]*?)\s+(\D.*)$/u', trim($text), $m)) { return null; }
    $amt = parse_amount($m[1]);
    if ($amt === null) { return null; }
    $desc = trim($m[2]);
    return $desc === '' ? null : [$amt, $desc];
}

function money(int $ft, string $cur): string
{
    return ($ft < 0 ? '-' : '') . number_format(abs($ft), 0, ',', ' ') . ' ' . $cur;
}

function to_ft(float $amount): int
{
    return (int) round($amount);
}

$raw = file_get_contents('php://input');
$update = json_decode((string) $raw, true);

if ($WEBHOOK_SECRET !== '') {
    $got = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals($WEBHOOK_SECRET, $got)) { http_response_code(200); echo 'ok'; exit; }
}

$msg = $update['message'] ?? $update['edited_message'] ?? null;
if (!$msg || !isset($msg['chat']['id'])) { http_response_code(200); echo 'ok'; exit; }

$chat = (int) $msg['chat']['id'];
$from = (int) ($msg['from']['id'] ?? 0);
$text = trim((string) ($msg['text'] ?? ''));

$owner = is_file(OWNER_FILE) ? (int) trim((string) file_get_contents(OWNER_FILE)) : 0;
if ($owner === 0) {
    if (strtolower(strtok($text, ' ')) === '/start') {
        file_put_contents(OWNER_FILE, (string) $from);
        @chmod(OWNER_FILE, 0600);
        $owner = $from;
    } else {
        say($chat, 'This bot is not set up yet.');
        http_response_code(200); echo 'ok'; exit;
    }
} elseif ($from !== $owner) {
    say($chat, 'This is a private bot.');
    http_response_code(200); echo 'ok'; exit;
}

$st = state();
$cmd = strtolower(strtok($text, ' '));

if (($st['await'] ?? '') === 'balance' && $cmd !== '/start' && $cmd !== '/cancel') {
    $amt = parse_amount($text);
    if ($amt === null) {
        say($chat, 'Numbers only, please. For example: <b>125000</b>');
    } else {
        reset_book(to_ft($amt));
        unset($st['await']); state_save($st);
        say($chat, 'Starting balance set: <b>' . money(total(), $CURRENCY) . "</b>\n\n"
            . "Now send transactions like:\n<code>-1200 food</code>\n<code>5000 wage</code>");
    }
    http_response_code(200); echo 'ok'; exit;
}

if (($st['await'] ?? '') === 'date' && $cmd !== '/cancel') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
        say($chat, 'Date format is <b>YYYY-MM-DD</b>, for example 2027-12-24.');
    } else {
        unset($st['await']); state_save($st);
        $hit = rows_on($text);
        if (!$hit) {
            say($chat, "No transactions on <b>$text</b>.");
        } else {
            $out = "<b>$text</b>\n"; $day = 0;
            foreach ($hit as $r) {
                $out .= substr($r['ts'], 11, 5) . '  ' . money((int) $r['amount'], $CURRENCY)
                     . '  ' . htmlspecialchars($r['descr']) . "\n";
                $day += (int) $r['amount'];
            }
            $out .= "\nDay total: <b>" . money($day, $CURRENCY) . '</b>';
            say($chat, $out);
        }
    }
    http_response_code(200); echo 'ok'; exit;
}

switch ($cmd) {
    case '/start':
        // $st['await'] = 'balance'; state_save($st);
        // $n = tx_count();
        // say($chat, "Let's start the books.\n\nSend your <b>starting balance</b> - numbers only.\n"
        //    . "For example: <code>125000</code>"
        //    . ($n ? "\n\n⚠️ This will replace the $n transactions already recorded. /cancel to keep them." : ''));
        break;

    case '/cancel':
        unset($st['await']); state_save($st);
        say($chat, 'Cancelled.');
        break;

    case '/total':
        say($chat, 'Running total: <b>' . money(total(), $CURRENCY) . '</b>');
        break;

    case '/ten':
        $r = last_rows(10);
        if (!$r) { say($chat, 'Nothing recorded yet. /start to begin.'); break; }
        $out = '<b>Last ' . count($r) . "</b>\n";
        foreach ($r as $x) {
            $out .= substr($x['ts'], 0, 16) . '  ' . money((int) $x['amount'], $CURRENCY)
                 . '  ' . htmlspecialchars($x['descr']) . '  → ' . money((int) $x['running'], $CURRENCY) . "\n";
        }
        say($chat, $out);
        break;

    case '/date':
        $st['await'] = 'date'; state_save($st);
        say($chat, 'Which day? Send it as <b>YYYY-MM-DD</b>.');
        break;
		
    case '/today':
		    say($chat, 'Today total: <b>'. today_total() .'</b>.');
		    break;
		
    case '/removelast':
        if (tx_count() <= 1) { say($chat, 'Nothing to remove.'); break; }
        $gone = remove_last();
        say($chat, 'Removed: ' . money((int) $gone['amount'], $CURRENCY) . ' ' . htmlspecialchars($gone['descr'])
            . "\nRunning total: <b>" . money(total(), $CURRENCY) . '</b>');
        break;

    case '/export':
        if (!tx_count()) { say($chat, 'Nothing recorded yet.'); break; }
        $f = export_csv();
        $ch = curl_init('https://api.telegram.org/bot' . bot_token() . '/sendDocument');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['chat_id' => $chat,
                'document' => new CURLFile($f, 'text/csv', 'konyveles.csv')]]);
        curl_exec($ch); curl_close($ch);
        @unlink($f);
        break;

    case '/check':
        $before = total();
        recompute();
        $after = total();
        say($chat, $before === $after
            ? 'Books check out. ' . tx_count() . " rows, running total <b>" . money($after, $CURRENCY) . '</b>'
            : "Running totals were wrong and I have rebuilt them.\nWas " . money($before, $CURRENCY)
              . ', now <b>' . money($after, $CURRENCY) . '</b>');
        break;

    case '/help':
    		say($chat, "Send a transaction as <code>amount description</code>:\n"
    			. "<code>-1200 food</code>\n<code>5000 wage</code>\n\n"
    			. "/total - running total\n/ten - last ten\n/date - one day's entries\n"
    			. "/today - running total today\n"
    			. "/removelast - delete the newest\n/export - the whole book as CSV\n"
    			. "/check - verify the running totals\n/start - begin again (wipes)\n/cancel - cancel a question\n"
    			. "/budget - you can spend this much today so you do not go under zero until next paycheque");
		break;
		
	case '/budget':
        $now = time();
        $today = (int) date('d');
        $month = (int) date('n');
        $year = (int) date('Y');
        
        
        if ($today <= 6) {
            $days_until_sixth = 6 - $today;
        } else {
            $next_month = $month + 1;
            $next_year = $year;
            if ($next_month > 12) { $next_month = 1; $next_year++; }
            
            $sixth_timestamp = mktime(0, 0, 0, $next_month, 6, $next_year);
            $days_until_sixth = (int) ceil(($sixth_timestamp - $now) / 86400);
        }
        
        if ($days_until_sixth <= 0) { $days_until_sixth = 1; }
        
        $available = total();
        $safe_daily = intdiv($available, $days_until_sixth) + today_total();
        
        $out = sprintf(
            "Current balance: <b>%s</b>\n" .
            "Days until 6th: <b>%d</b>\n" .
            "Today you can spend this much: <b>%s</b>\n\n" .
            "(This ensures you stay above zero until income arrives)",
            money($available, $CURRENCY),
            $days_until_sixth,
            money($safe_daily, $CURRENCY)
        );
        
        // Warn if already negative
        if ($available < 0) {
            $out .= "\nYou are currently negative.";
        }
        
        say($chat, $out);
        break;
	case '/monthbalance':
		$m = date('Y-m');
		$pdo = db();
		$stmt = $pdo->prepare("
			SELECT 
				SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as inc,
				SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) as exp
			FROM tx WHERE substr(ts,1,7) = ?
		");
		$months = ['', 'January', 'February', 'March', 'April', 'May', 'Jun',
           'July', 'Augustut', 'September', 'Oktober', 'Nobember', 'December'];
		
		$stmt->execute([$m]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$inc = (int) ($row['inc'] ?? 0);
		$exp = (int) ($row['exp'] ?? 0);
		$net = $inc + $exp;
		
		$out = sprintf(
			"<b>%s | %s</b>\n\n" .
			"Income:\n	<b>%s</b>\n\n" .
			"Expenses:\n	<b>%s</b>\n\n" .
			"Net:\n	<b>%s</b>\n\n" .
			"Outflow rate: <b>%d%%</b>",
			$months[intval(date('n'))],
			$CURRENCY,
			money($inc, $CURRENCY),
			money($exp, $CURRENCY),
			money($net, $CURRENCY),
			$inc > 0 ? abs(intdiv($exp * 100, $inc)) : 0
		);
		say($chat, $out);
		break;
    default:
        if ($text === '' || $text[0] === '/') { say($chat, 'Invalid command. /help'); break; }
        if (!tx_count()) { say($chat, 'Send /start first to set your opening balance.'); break; }
        $p = parse_entry($text);
        if ($p === null) {
            say($chat, "Invalid value.\nUse <code>amount [space] description</code>, e.g. <code>-1200 food</code>");
            break;
        }
        [$amt, $desc] = $p;
        $run = add_tx(to_ft($amt), $desc);
        $daily = today_total();
        say($chat, 'Recorded <b>' . money(to_ft($amt), $CURRENCY) . '</b> - ' . htmlspecialchars($desc)
            . "\nToday: <b>" . money($daily, $CURRENCY) . '</b>  |  Balance: <b>' . money($run, $CURRENCY) . '</b>');
}

http_response_code(200);
echo 'ok';
