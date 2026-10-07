<?php
/**
 * Price Tracker - eigenstaendige Seite. Pfade werden aus dem Skriptpfad
 * abgeleitet, damit der Ordner unter jedem Unterpfad und auch ohne
 * Schraegstrich am Ende (/deals/tracker) funktioniert.
 */
$basis = htmlspecialchars(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/', ENT_QUOTES);
$v = fn($f) => @filemtime(__DIR__ . '/' . $f) ?: 1;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Price Tracker</title>
<meta name="description" content="Track prices from Best Buy Canada, Canada Computers and Amazon.ca.">
<meta name="color-scheme" content="light dark">
<link rel="stylesheet" href="<?= $basis ?>stil.css?v=<?= $v('stil.css') ?>">
<script src="<?= $basis ?>app.js?v=<?= $v('app.js') ?>" defer></script>
</head>
<body>

<svg class="sinnbilder" aria-hidden="true" focusable="false">
  <symbol id="s-marke" viewBox="0 0 24 24"><path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.5"/></symbol>
  <symbol id="s-thema" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 3v18a9 9 0 0 0 0-18z" fill="currentColor" stroke="none"/></symbol>
</svg>

<header class="kopf">
  <div class="marke">
    <span class="marke-zeichen"><svg class="sym"><use href="#s-marke"/></svg></span>
    <h1>Price Tracker</h1>
  </div>
  <div class="kopf-rechts">
    <button type="button" class="rundknopf" id="tracker-thema" title="Light or dark appearance"><svg class="sym"><use href="#s-thema"/></svg><span class="nurlesbar">Switch appearance</span></button>
  </div>
</header>

<main class="haupt">
<?php include __DIR__ . '/teil.php'; ?>
</main>

</body>
</html>
