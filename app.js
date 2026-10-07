// Price Tracker - holt die Liste von tracker.php (neben diesem Skript) und
// zeichnet sie in #liste. Laeuft eigenstaendig (index.php) oder eingebunden
// in eine andere Seite (teil.php + dieses Skript).
(function () {
  "use strict";

  const $ = (id) => document.getElementById(id);
  const API = new URL("tracker.php", document.currentScript.src).pathname;

  // Hell/Dunkel nur auf der eigenen Seite - eingebunden macht das die Gastseite
  const thema = $("tracker-thema");
  if (thema) {
    try { const t = localStorage.getItem("deals-thema"); if (t) document.documentElement.dataset.theme = t; } catch (e) {}
    thema.addEventListener("click", () => {
      const dunkel = matchMedia("(prefers-color-scheme: dark)").matches;
      const jetzt = document.documentElement.dataset.theme || (dunkel ? "dark" : "light");
      const neu = jetzt === "dark" ? "light" : "dark";
      document.documentElement.dataset.theme = neu;
      try { localStorage.setItem("deals-thema", neu); } catch (e) {}
    });
  }

  const liste = $("liste");
  if (!liste) return;

  const leer = $("leer"), meldung = $("meldung"), form = $("neu"), anzahl = $("anzahl-ca");
  const vorlage = $("vorlage").content.firstElementChild;
  const SHOPS = { bestbuy: "Best Buy", cc: "Canada Computers", amazon: "Amazon.ca", amazon_de: "Amazon.de" };
  const LAND  = { bestbuy: "ca", cc: "ca", amazon: "ca", amazon_de: "de" };
  // Eintraege ohne "waehrung" sind aus der Zeit, als es nur kanadische Shops gab
  const formate = {};
  const geld = (e) => formate[e.waehrung || "CAD"] ||= new Intl.NumberFormat("en-CH", { style: "currency", currency: e.waehrung || "CAD" });
  const rel = new Intl.RelativeTimeFormat("en", { numeric: "auto" });

  function vor(ts) {
    const s = Math.round(ts - Date.now() / 1000);
    if (s > -3600) return rel.format(Math.round(s / 60), "minute");
    if (s > -86400) return rel.format(Math.round(s / 3600), "hour");
    return rel.format(Math.round(s / 86400), "day");
  }

  function kurve(poly, verlauf) {
    const p = verlauf.map((v) => v[1]);
    if (p.length === 1) p.push(p[0]);
    const min = Math.min(...p), max = Math.max(...p), span = max - min || 1;
    poly.setAttribute("points", p.map((v, i) =>
      (i / (p.length - 1) * 100).toFixed(1) + "," + (28 - (v - min) / span * 24).toFixed(1)).join(" "));
  }

  function zeile(e) {
    const li = vorlage.cloneNode(true);
    const cad = geld(e);
    const q = (s) => li.querySelector(s);
    li.dataset.land = LAND[e.shop] || "";
    li.dataset.search = ((e.titel || "") + " " + (SHOPS[e.shop] || "") + " " + e.ref).toLowerCase();
    q(".bild").href = q(".titel").href = e.url;
    if (e.bild) q("img").src = e.bild; else q("img").remove();
    q(".shop").textContent = SHOPS[e.shop] || e.shop;
    q(".titel").textContent = e.titel || e.ref;
    q(".stand").textContent = e.fehler ? e.fehler + " · showing last known price" : "checked " + vor(e.geprueft);
    if (e.fehler) { q(".stand").classList.add("fehler"); li.classList.add("veraltet"); }

    const v = e.verlauf || [];
    q(".jetzt").textContent = e.preis == null ? "–" : cad.format(e.preis);
    if (v.length) {
      const erster = v[0][1], d = e.preis - erster;
      const tief = Math.min(...v.map((x) => x[1]));
      q(".tief").textContent = "Lowest " + cad.format(tief);
      q(".diff").textContent = d === 0 ? "no change"
        : (d < 0 ? "▼ " : "▲ ") + cad.format(Math.abs(d)) + " (" + Math.round(Math.abs(d) / erster * 100) + "%)";
      q(".diff").classList.add(d < 0 ? "runter" : d > 0 ? "rauf" : "gleich");
      if (e.preis <= tief && v.length > 1) li.classList.add("tiefststand");
    }
    if (e.regulaer && e.preis && e.regulaer > e.preis) {
      const s = document.createElement("s");
      s.className = "regulaer";
      s.textContent = cad.format(e.regulaer);
      q(".jetzt").append(" ", s);
    }
    kurve(q("polyline"), v.length ? v : [[0, 0]]);
    q(".weg").addEventListener("click", () => entfernen(e, li));
    return li;
  }

  // Gastseite (z. B. die Laenderauswahl auf /deals) ueber Aenderungen informieren
  function melde(land) {
    document.dispatchEvent(new CustomEvent("tracker-geladen", { detail: { land } }));
  }

  function zaehlen() {
    leer.hidden = liste.children.length > 0;
    anzahl.textContent = liste.children.length || "";
  }

  function melden(text, fehler) {
    meldung.textContent = text;
    meldung.classList.toggle("fehler", !!fehler);
    meldung.hidden = !text;
  }

  async function api(daten) {
    const r = await fetch(API, daten ? { method: "POST", body: new URLSearchParams(daten) } : {});
    const j = await r.json().catch(() => ({ fehler: "Server did not answer (" + r.status + ")" }));
    if (!r.ok || j.fehler) throw new Error(j.fehler || r.status);
    return j;
  }

  form.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    const knopf = form.querySelector("button");
    knopf.disabled = true;
    melden("Fetching price …");
    try {
      const { eintrag, hinweis } = await api({ aktion: "neu", eingabe: form.eingabe.value, email: form.email.value });
      if (eintrag) { liste.prepend(zeile(eintrag)); zaehlen(); melde(LAND[eintrag.shop]); }
      form.reset();
      melden(hinweis || "");
    } catch (x) { melden(x.message, true); }
    knopf.disabled = false;
  });

  async function entfernen(e, li) {
    if (!confirm("Remove “" + (e.titel || e.ref) + "” from the list?")) return;
    try { await api({ aktion: "weg", id: e.id }); li.remove(); zaehlen(); melde(); }
    catch (x) { melden(x.message, true); }
  }

  api().then((j) => {
    liste.replaceChildren(...j.produkte.map(zeile));
    zaehlen();
    melde();
  }).catch((x) => melden(x.message, true));
})();
