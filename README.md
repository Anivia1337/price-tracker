# Price Tracker

Tracks product prices at Canadian online shops and Amazon.de and emails you
when a price drops. Paste a Best Buy SKU, an Amazon ASIN or a product link,
and the item stays on a shared list with its current price, the change since it was added,
the lowest price seen and a small price-history chart. Plain PHP and
JavaScript, no framework, no database.

Supported shops:

| Shop | Input | How the price is read |
|---|---|---|
| Best Buy Canada | SKU (`19837076`) or product link | Best Buy's public JSON product API |
| Canada Computers | product link | schema.org data + `product:price:amount` meta tag |
| Amazon.ca | ASIN (`B0…`) or product link | the *new* offer of the buy box only (no used offers, no prices of recommended products); with the multi-row buy box the regular new price, plus a Prime-only deal as extra info |
| Amazon.de | product link | same as Amazon.ca, prices in EUR (the currency cookie is set, otherwise Amazon shows a Swiss server CHF) |

Newegg.ca, Staples.ca, Memory Express, Walmart.ca, London Drugs, Visions,
Mindfactory and Geizhals answer server requests with a bot check (Cloudflare, PerimeterX or
their own), so they are not supported. The tracker only ever fetches these fixed shop hosts — never a URL
taken as-is from the input.

## How it works

- `tracker.php` is the whole backend: it lists, adds and removes products
  (JSON in `data/produkte.json`) and fetches prices.
- Run it from cron every hour; the CLI run refreshes every product. Page views
  only refresh products the cron has missed for over 2 hours (3 per view), so
  the list still moves if cron stops.
- The price history only records changes. If a shop can't be read, the last
  known price stays and is marked as such.
- **Price alerts:** when adding a product, an email address can be given. It
  is stored only in `data/abos.json` and never sent to the browser, so nobody
  sees other people's addresses. On a price drop every subscriber gets their
  own email. Each email has an unsubscribe link; it opens a confirmation page,
  so mail scanners that pre-open links don't unsubscribe anyone.
- There is no login: anyone with the URL can add and remove products. The list
  is capped at 100 products and 20 alert addresses per product.

## Setup

Requires PHP 8.1+ with curl and OpenSSL.

1. Put the folder on a PHP web server (Apache: `.htaccess` sets the CSP and
   blocks `data/`; on other servers block `data/` yourself).
2. Make `data/` writable for the web server user.
3. Set `SEITE` in `tracker.php` to the public URL of the folder — it is used
   for the links in emails.
4. Run the price check every hour as the web server user:

   ```
   7 * * * * php /path/to/tracker/tracker.php >/dev/null 2>&1
   ```

5. For email alerts, create the credentials file named in `ZUGANG`
   (default `/etc/deals/mail.php`, outside the web root) and make it readable
   for the web server user only:

   ```php
   <?php return ['user' => 'alerts@example.com', 'pass' => '...'];
   ```

   The SMTP server is set in `SMTP` (default IONOS, `ssl://smtp.ionos.de:465`).
   Without this file the form doesn't accept email addresses.

## Files

| File | Purpose |
|---|---|
| `tracker.php` | API, price fetching, cron job, email alerts, unsubscribe page |
| `index.php` | Standalone page |
| `teil.php` | The tracker section (form, list, card template) — include it in another page together with `app.js` |
| `app.js` | Loads and renders the list, adds/removes products |
| `stil.css` | Styles (light/dark via `light-dark()`) |

## License

MIT
