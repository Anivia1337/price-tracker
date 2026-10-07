<?php
/**
 * Price Tracker - Preis-Tracker fuer kanadische Shops.
 *
 *   GET  tracker.php                       -> Liste (frischt veraltete Eintraege nebenbei auf)
 *   POST tracker.php  aktion=neu&eingabe=  -> Produkt hinzufuegen (Best-Buy-SKU oder Produkt-URL)
 *   POST tracker.php  aktion=weg&id=       -> Produkt entfernen
 *   GET  tracker.php?abmelden=TOKEN        -> Preisalarm abbestellen (Link in jeder Mail)
 *   php tracker.php                        -> Cronjob: alle veralteten Eintraege nachholen
 *
 * Unterstuetzt nur Shops, deren Seiten der Server ohne Bot-Sperre lesen kann:
 * Best Buy Canada (JSON-API), Canada Computers (schema.org/Meta), Amazon.ca.
 * Newegg, Staples, Memory Express, Walmart und London Drugs liefern hinter
 * Cloudflare/PerimeterX nur eine Captcha-Seite - geprueft am 2026-10-01.
 * Abgerufen werden ausschliesslich diese festen Hosts, nie eine frei
 * eingegebene Adresse.
 *
 * Preisalarm: beim Hinzufuegen optional eine E-Mail-Adresse. Sie liegt nur in
 * data/abos.json und wird nie an den Browser zurueckgegeben. Faellt ein Preis,
 * geht von deals@fireduck.eu (IONOS) eine Mail raus. Zugangsdaten liegen
 * ausserhalb des Webroots in ZUGANG (siehe unten), fuer www-data lesbar:
 *   <?php return ['user' => 'deals@fireduck.eu', 'pass' => '...'];
 * Fehlt die Datei, nimmt das Formular keine Adressen an.
 */

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const DATEI    = __DIR__ . '/data/produkte.json';
const MAX      = 100;       // Obergrenze der Liste, oeffentlich beschreibbar
const ALTER    = 2 * 3600;  // Seitenaufruf holt nur nach, was der Cron offenbar verpasst hat; der Cron selbst prueft immer alles
const ABOS     = __DIR__ . '/data/abos.json';
const ZUGANG   = '/etc/deals/mail.php';
const SMTP     = 'ssl://smtp.ionos.de:465';
const SEITE    = 'https://anivia.ch/deals/tracker/';   // Adresse dieses Ordners fuer Links in Mails - fest, nicht aus dem Host-Header (der landete sonst gefaelscht in Mails)
const MAX_ABOS = 20;        // Adressen pro Produkt
const PRO_AUFRUF = 3;       // Rueckfall, falls der Cronjob (stuendlich "php tracker.php") nicht laeuft: pro Seitenaufruf hoechstens 3 nachholen

// ---- Speicher --------------------------------------------------------------

function lesen(string $datei = DATEI): array
{
    $d = is_readable($datei) ? json_decode((string) file_get_contents($datei), true) : null;
    return is_array($d) ? $d : [];
}

// Liest, veraendert und schreibt unter Sperre - zwei gleichzeitige Aufrufe
// ueberschreiben sich sonst gegenseitig.
function aendern(callable $fn, string $datei = DATEI)
{
    if (!is_dir(dirname($datei))) mkdir(dirname($datei), 0770, true);
    $h = fopen($datei, 'c+');
    flock($h, LOCK_EX);
    $d = json_decode((string) stream_get_contents($h), true);
    $d = is_array($d) ? $d : [];
    $ergebnis = $fn($d);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    return $ergebnis;
}

// ---- Abruf -----------------------------------------------------------------

function holen(string $url): string
{
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept-Language: en-CA,en;q=0.9'],
    ]);
    $body = (string) curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    if ($code === 404) throw new RuntimeException('Product not found at the shop');
    if ($code !== 200 || $body === '') throw new RuntimeException("Shop answered with HTTP $code");
    return $body;
}

function zahl($v): ?float
{
    if ($v === null || $v === '') return null;
    $v = str_replace([',', '$', ' '], '', (string) $v);
    return is_numeric($v) ? (float) $v : null;
}

// Eingabe -> [shop, ref, url]. Wirft bei allem, was kein bekannter Shop ist.
function erkennen(string $in): array
{
    $in = trim($in);
    if (preg_match('/^\d{7,9}$/', $in)) return ['bestbuy', $in, "https://www.bestbuy.ca/en-ca/product/$in"];
    if (preg_match('/^B0[A-Z0-9]{8}$/i', $in)) return ['amazon', strtoupper($in), 'https://www.amazon.ca/dp/' . strtoupper($in)];

    $teile = parse_url($in);
    $host  = strtolower(preg_replace('/^www\./', '', $teile['host'] ?? ''));
    $pfad  = $teile['path'] ?? '';
    if ($host === 'bestbuy.ca' && preg_match('#/(\d{7,9})(?:\.aspx)?/?$#', $pfad, $m)) {
        return ['bestbuy', $m[1], "https://www.bestbuy.ca/en-ca/product/{$m[1]}"];
    }
    if ($host === 'amazon.ca' && preg_match('#/(?:dp|gp/product)/([A-Z0-9]{10})#i', $pfad, $m)) {
        return ['amazon', strtoupper($m[1]), 'https://www.amazon.ca/dp/' . strtoupper($m[1])];
    }
    if ($host === 'canadacomputers.com' && preg_match('#^/(en|fr)/[\w\-/]+\.html$#', $pfad)) {
        $url = "https://www.canadacomputers.com$pfad";
        return ['cc', $url, $url];
    }
    throw new InvalidArgumentException('Not recognised. Enter a Best Buy SKU, an Amazon ASIN or a product link from bestbuy.ca, canadacomputers.com or amazon.ca.');
}

// -> [titel, bild, preis, regulaer]
function abrufen(string $shop, string $ref): array
{
    if ($shop === 'bestbuy') {
        $d = json_decode(holen("https://www.bestbuy.ca/api/v2/json/product/$ref?lang=en-CA"), true);
        if (empty($d['name'])) throw new RuntimeException('SKU not found at Best Buy');
        $bild = str_replace('/55x55/', '/500x500/', (string) ($d['thumbnailImage'] ?? ''));
        return [$d['name'], $bild, zahl($d['salePrice'] ?? null), zahl($d['regularPrice'] ?? null)];
    }

    if ($shop === 'amazon') {
        $h = holen("https://www.amazon.ca/dp/$ref");
        if (stripos($h, 'captcha') !== false && stripos($h, 'productTitle') === false) throw new RuntimeException('Amazon is asking for a captcha');
        preg_match('#id="productTitle"[^>]*>\s*(.*?)\s*<#s', $h, $t);
        preg_match('#data-old-hires="([^"]+)|"landingImageUrl":"([^"]+)#', $h, $b);
        if (empty($t[1])) throw new RuntimeException('Product not found at Amazon');

        // Nur das Neu-Angebot der Buy-Box zaehlt. Die Seite enthaelt daneben
        // Gebraucht-Angebote und Preise fremder Produkte (Empfehlungen) -
        // der erste "priceAmount"/"a-text-price"-Treffer war deshalb oft ein
        // Gebrauchtpreis bzw. der Preis eines ganz anderen Artikels.
        $preis = null;
        if (preg_match('#twister-plus-buying-options-price-data">(.*?)</div>#s', $h, $j)) {
            foreach (json_decode(html_entity_decode($j[1]), true)['desktop_buybox_group_1'] ?? [] as $o) {
                if (($o['buyingOptionType'] ?? '') === 'NEW') { $preis = zahl($o['priceAmount'] ?? null); break; }
            }
        }
        if ($preis === null) throw new RuntimeException('No new offer on Amazon right now');

        // Streichpreis ("List Price") nur aus dem Preisblock des Artikels selbst
        $regulaer = null;
        if (($i = strpos($h, 'id="corePriceDisplay_desktop_feature_div"')) !== false
            && preg_match('#data-a-strike="true"[^>]*>\s*<span class="a-offscreen">\$?([\d,.]+)#', substr($h, $i, 20000), $r)) {
            $regulaer = zahl($r[1]);
        }
        return [html_entity_decode(trim($t[1])), ($b[1] ?? '') ?: ($b[2] ?? ''), $preis, $regulaer];
    }

    // Canada Computers: schema.org Product, Preis zusaetzlich als product:price-Meta
    $h = holen($ref);
    $name = $bild = null;
    preg_match_all('#<script[^>]*ld\+json[^>]*>(.*?)</script>#s', $h, $j);
    foreach ($j[1] as $block) {
        $d = json_decode($block, true);
        if (($d['@type'] ?? '') === 'Product') {
            $name = $d['name'] ?? null;
            $bild = is_array($d['image'] ?? null) ? $d['image'][0] : ($d['image'] ?? null);
        }
    }
    preg_match('#product:price:amount" content="([\d.]+)#', $h, $p);
    if (!$name) throw new RuntimeException('Product page has no product data');
    return [html_entity_decode($name), (string) $bild, zahl($p[1] ?? null), null];
}

// Schreibt einen frischen Abruf in den Eintrag; Verlauf nur bei Preisaenderung.
// Gibt den alten Preis zurueck, wenn der neue darunter liegt.
function einpflegen(array &$e, array $r): ?float
{
    [$e['titel'], $e['bild'], $preis, $e['regulaer']] = $r;
    $e['geprueft'] = time();
    $e['fehler']   = null;
    if ($preis === null) return null;
    $letzter = end($e['verlauf']);
    if (!$letzter || $letzter[1] != $preis) $e['verlauf'][] = [time(), $preis];
    $e['preis'] = $preis;
    return $letzter && $preis < $letzter[1] ? (float) $letzter[1] : null;
}

// ---- Preisalarm ------------------------------------------------------------

function cad(?float $p): string
{
    return $p === null ? '-' : 'CA$ ' . number_format($p, 2, '.', "'");
}

// Betreff kuerzen und kodieren. Reines ASCII geht unveraendert raus; sonst in
// mehrere "encoded words" zu hoechstens 75 Zeichen (RFC 2047) - ein einziges
// langes Stueck zeigen manche Mailprogramme roh als =?UTF-8?B?...?= an.
function betreff(string $b): string
{
    if (preg_match('/^(.{89}).{2,}/us', $b, $m)) $b = rtrim($m[1]) . '…';   // ohne mbstring
    if (!preg_match('/[^\x20-\x7e]/', $b)) return $b;
    preg_match_all('/.{1,12}/us', $b, $teile);   // 12 Zeichen <= 48 Byte UTF-8 -> 64 Zeichen Base64
    return implode("\r\n ", array_map(fn($t) => '=?UTF-8?B?' . base64_encode($t) . '?=', $teile[0]));
}

// Eine Mail an genau eine Adresse - keine Sammelmail, damit niemand die
// Adressen der anderen sieht. Das Zertifikat von IONOS wird geprueft.
function mailen(string $an, string $betreff, string $text, string $token): void
{
    $z = require ZUGANG;
    $s = stream_socket_client(SMTP, $nr, $fehler, 20);
    if (!$s) throw new RuntimeException("Mail server unreachable: $fehler");
    stream_set_timeout($s, 20);
    $lesen = function (string $soll) use ($s) {
        $o = '';
        while (($l = fgets($s, 1000)) !== false) { $o .= $l; if (($l[3] ?? '') === ' ') break; }
        if (strncmp($o, $soll, 3) !== 0) throw new RuntimeException('Mail server: ' . trim($o));
    };
    $befehl = function (string $zeile, string $soll) use ($s, $lesen) { fwrite($s, "$zeile\r\n"); $lesen($soll); };

    $abmelden = SEITE . 'tracker.php?abmelden=' . $token;
    $text .= "\n\n--\nYou get this because this address was entered on the deals page.\nStop these emails: $abmelden\n";
    $kopf = "From: Deals <{$z['user']}>\r\nTo: <$an>\r\n"
          . 'Subject: ' . betreff($betreff) . "\r\n"
          . 'Date: ' . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . "@fireduck.eu>\r\n"
          . "List-Unsubscribe: <$abmelden>\r\nMIME-Version: 1.0\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";

    $lesen('220');
    $befehl('EHLO fireduck.eu', '250');
    $befehl('AUTH LOGIN', '334');
    $befehl(base64_encode($z['user']), '334');
    $befehl(base64_encode($z['pass']), '235');
    $befehl("MAIL FROM:<{$z['user']}>", '250');
    $befehl("RCPT TO:<$an>", '250');
    $befehl('DATA', '354');
    $befehl($kopf . "\r\n" . chunk_split(base64_encode($text)) . '.', '250');
    fwrite($s, "QUIT\r\n");
    fclose($s);
}

// Nach einem Abruf: allen Abonnenten der guenstiger gewordenen Produkte schreiben.
function alarmieren(array $gefallen): void
{
    if (!$gefallen || !is_readable(ZUGANG)) return;
    foreach (lesen(ABOS) as $a) {
        if (!isset($gefallen[$a['id']])) continue;
        [$e, $alt] = $gefallen[$a['id']];
        try {
            mailen($a['email'], 'Price drop: ' . $e['titel'],
                "{$e['titel']}\n\nNow " . cad($e['preis']) . ' (was ' . cad($alt) . ")\n{$e['url']}\n\nAll tracked prices: " . SEITE,
                $a['token']);
        } catch (Throwable $t) {
            error_log('deals: Preisalarm an ' . $a['email'] . ' gescheitert: ' . $t->getMessage());
        }
    }
}

// Adresse fuer ein Produkt eintragen und bestaetigen. -> Hinweistext fuer den Browser
function abonnieren(array $e, string $email): string
{
    $token = bin2hex(random_bytes(16));
    $neu = aendern(function (&$d) use ($e, $email, $token) {
        $hier = array_filter($d, fn($a) => $a['id'] === $e['id']);
        foreach ($hier as $a) if ($a['email'] === $email) return 'schon';
        if (count($hier) >= MAX_ABOS) return 'voll';
        $d[] = ['id' => $e['id'], 'email' => $email, 'token' => $token, 'seit' => time()];
        return 'neu';
    }, ABOS);
    if ($neu === 'voll') return 'Too many alerts on this product already - no email will be sent.';
    if ($neu === 'schon') return 'That address already gets alerts for this product.';
    try {
        mailen($email, 'Price alert set: ' . $e['titel'],
            "You'll get an email when the price of this product drops.\n\n{$e['titel']}\nCurrently " . cad($e['preis']) . "\n{$e['url']}",
            $token);
    } catch (Throwable $t) {
        error_log('deals: Bestaetigung an ' . $email . ' gescheitert: ' . $t->getMessage());
        return 'Alert saved, but the confirmation email could not be sent.';
    }
    return "We'll email you when the price drops. A confirmation is on its way.";
}

// ---- Anfragen --------------------------------------------------------------

// Antwort schon ausliefern, das Skript laeuft danach weiter - der
// Mailversand soll den Seitenaufbau nicht aufhalten.
function antwort_vorab(array $d): void
{
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    $GLOBALS['schon_geantwortet'] = true;
}

// Abmeldelink aus der Mail. GET zeigt nur einen Knopf, erst das POST loescht:
// Virenscanner (Outlook Safe Links & Co.) rufen Links aus Mails vorab auf und
// wuerden sonst jeden Empfaenger unbemerkt abmelden.
function abmeldeseite(string $token): void
{
    $abo = null;
    foreach (lesen(ABOS) as $a) if (hash_equals($a['token'], $token)) $abo = $a;
    $titel = '';
    foreach (lesen() as $e) if ($abo && $e['id'] === $abo['id']) $titel = $e['titel'];

    $fertig = false;
    if ($abo && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        aendern(function (&$d) use ($token) { $d = array_values(array_filter($d, fn($a) => !hash_equals($a['token'], $token))); }, ABOS);
        $fertig = true;
    }

    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    if ($fertig) {
        [$kopf, $text, $knopf] = ['Alerts stopped', 'You won’t get any more price alerts for <b>' . $h($titel) . '</b>.', ''];
    } elseif ($abo) {
        [$kopf, $text, $knopf] = ['Stop price alerts?', 'Emails to <b>' . $h($abo['email']) . '</b> about <b>' . $h($titel) . '</b> will stop.',
            '<form method="post"><button type="submit" class="knopf">Stop alerts</button></form>'];
    } else {
        [$kopf, $text, $knopf] = ['Nothing to stop', 'This link is no longer active &mdash; the alert was already stopped or the product was removed.', ''];
    }
    $v = @filemtime(__DIR__ . '/stil.css') ?: 1;
    $basis = $h(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/');

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="color-scheme" content="light dark">
<title>Price alerts</title>
<link rel="stylesheet" href="{$basis}stil.css?v={$v}">
</head>
<body>
<header class="kopf">
  <a class="marke" href="{$basis}">
    <span class="marke-zeichen"><svg class="sym" viewBox="0 0 24 24"><path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg></span>
    <h1>Price Tracker</h1>
  </a>
</header>
<main class="haupt">
  <section class="hinweiskarte">
    <h2>{$kopf}</h2>
    <p>{$text}</p>
    {$knopf}
    <p><a href="{$basis}">Back to the price list</a></p>
  </section>
</main>
</body>
</html>
HTML;
    exit;
}

function antwort(array $d, int $code = 200): void
{
    if (!empty($GLOBALS['schon_geantwortet'])) exit;
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (isset($_GET['abmelden'])) {
        abmeldeseite((string) $_GET['abmelden']);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $aktion = $_POST['aktion'] ?? '';

        if ($aktion === 'neu') {
            [$shop, $ref, $url] = erkennen(substr((string) ($_POST['eingabe'] ?? ''), 0, 500));
            $email = trim((string) ($_POST['email'] ?? ''));
            if ($email !== '') {
                if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('That email address does not look right.');
                if (!is_readable(ZUGANG)) throw new InvalidArgumentException('Email alerts are not set up yet - leave the email field empty.');
                $email = strtolower($email);
            }
            $id = substr(sha1("$shop|$ref"), 0, 12);
            foreach (lesen() as $e) {
                if ($e['id'] !== $id) continue;
                if ($email === '') antwort(['fehler' => 'Already on the list.'], 409);
                antwort(['hinweis' => 'Already on the list. ' . abonnieren($e, $email)]);
            }

            $e = ['id' => $id, 'shop' => $shop, 'ref' => $ref, 'url' => $url, 'verlauf' => [], 'preis' => null, 'hinzu' => time()];
            einpflegen($e, abrufen($shop, $ref));   // schlaegt fehl -> nichts gespeichert

            $ok = aendern(function (&$d) use ($e) {
                if (count($d) >= MAX) return false;
                $d = array_values(array_filter($d, fn($x) => $x['id'] !== $e['id']));
                array_unshift($d, $e);
                return true;
            });
            if (!$ok) antwort(['fehler' => 'The list is full (' . MAX . ' items).'], 409);
            antwort(['eintrag' => $e, 'hinweis' => $email === '' ? '' : abonnieren($e, $email)]);
        }

        if ($aktion === 'weg') {
            $id = (string) ($_POST['id'] ?? '');
            aendern(function (&$d) use ($id) { $d = array_values(array_filter($d, fn($x) => $x['id'] !== $id)); });
            aendern(function (&$d) use ($id) { $d = array_values(array_filter($d, fn($a) => $a['id'] !== $id)); }, ABOS);
            antwort(['ok' => true]);
        }

        antwort(['fehler' => 'Unknown action'], 400);
    }

    // GET (oder CLI): die aeltesten veralteten Eintraege nachholen, ohne Sperre
    // waehrend der Abrufe, danach unter Sperre einpflegen.
    $liste = lesen();
    usort($liste, fn($a, $b) => ($a['geprueft'] ?? 0) <=> ($b['geprueft'] ?? 0));
    $frisch = [];
    foreach (array_slice($liste, 0, PHP_SAPI === 'cli' ? MAX : PRO_AUFRUF) as $e) {
        if (PHP_SAPI !== 'cli' && time() - ($e['geprueft'] ?? 0) < ALTER) break;
        try { $frisch[$e['id']] = abrufen($e['shop'], $e['ref']); }
        catch (Throwable $t) { $frisch[$e['id']] = $t->getMessage(); }
    }
    if ($frisch) {
        $gefallen = aendern(function (&$d) use ($frisch) {
            $gefallen = [];
            foreach ($d as &$e) {
                if (!isset($frisch[$e['id']])) continue;
                if (is_array($frisch[$e['id']])) {
                    $alt = einpflegen($e, $frisch[$e['id']]);
                    if ($alt !== null) $gefallen[$e['id']] = [$e, $alt];
                } else { $e['fehler'] = $frisch[$e['id']]; $e['geprueft'] = time(); }
            }
            return $gefallen;
        });
        if ($gefallen) {
            if (PHP_SAPI !== 'cli') antwort_vorab(['produkte' => lesen(), 'jetzt' => time()]);
            alarmieren($gefallen);
        }
    }
    antwort(['produkte' => lesen(), 'jetzt' => time()]);
} catch (InvalidArgumentException $x) {
    antwort(['fehler' => $x->getMessage()], 400);
} catch (Throwable $x) {
    antwort(['fehler' => $x->getMessage()], 502);
}
