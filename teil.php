<?php
/**
 * Der Tracker-Abschnitt: Formular, Liste, Kartenvorlage. Wird von index.php
 * eingebunden und kann genauso in eine andere Seite eingebunden werden
 * (dazu app.js laden und die Karten-Stile aus stil.css uebernehmen).
 */
?>
<svg class="sinnbilder" aria-hidden="true" focusable="false">
  <symbol id="s-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
  <symbol id="s-weg" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></symbol>
</svg>

<section class="bereich" aria-labelledby="t-ca">
  <div class="bereich-kopf">
    <h2 id="t-ca">Canada <span class="zahl" id="anzahl-ca"></span></h2>
    <p>Tracked prices from Best Buy Canada, Canada Computers and Amazon.ca &mdash; in CAD, checked every hour.</p>
  </div>
  <form id="neu" class="eingabe" autocomplete="off">
    <label class="nurlesbar" for="eingabe">SKU, ASIN or product link</label>
    <input id="eingabe" name="eingabe" required maxlength="500" placeholder="Best Buy SKU (e.g. 19837076) or product link">
    <label class="nurlesbar" for="email">Email for price alerts (optional)</label>
    <input id="email" name="email" type="email" maxlength="254" placeholder="Email for price drops (optional)">
    <button type="submit" class="knopf"><svg class="sym"><use href="#s-plus"/></svg><span>Track</span></button>
  </form>
  <p class="fein">Your email is only used for price-drop alerts on this product and is never shown on this page. Every email has an unsubscribe link.</p>
  <p class="meldung" id="meldung" role="status" hidden></p>
  <ul class="raster" id="liste" aria-live="polite"></ul>
  <p class="leer" id="leer" hidden>Nothing tracked yet. Enter a Best Buy SKU or a product link above.</p>
</section>

<template id="vorlage">
  <li class="karte produkt">
    <a class="bild" target="_blank" rel="noopener"><img alt="" loading="lazy" referrerpolicy="no-referrer"></a>
    <div class="info">
      <span class="shop"></span>
      <a class="titel" target="_blank" rel="noopener"></a>
      <span class="stand"></span>
    </div>
    <svg class="kurve" viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true"><polyline/></svg>
    <b class="jetzt"></b>
    <span class="diff"></span>
    <span class="tief"></span>
    <button type="button" class="weg" title="Remove from the list"><svg class="sym"><use href="#s-weg"/></svg><span class="nurlesbar">Remove</span></button>
  </li>
</template>
