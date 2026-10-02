# Provera aplikacije

Testovi stvaraju privremene baze sa izmišljenim kartonima. Ne koriste postojeći `config.php`, stvarne pacijente ili API kredencijale. Privremeni podaci brišu se pri završetku testa.

Potrebni su PHP 8.2 ili noviji sa OpenSSL, PDO SQLite, cURL, mbstring i fileinfo, kao i Node.js 24. Iz korena projekta pokrenuti:

```sh
php tests/run.php
php tests/backend-security.php
php -d memory_limit=512M tests/restore-cli.php
php tests/ai.php
php tests/calendar.php
node tests/http.mjs
```

`run.php` proverava čuvanje kartona, revizije, sukob istovremenih izmena, zaključavanje pregleda, terapiju, termine, skale, laboratoriju, šifrovanje i vraćanje rezervne kopije. Dodatni PHP testovi proveravaju neispravne ulaze, uvoz ranijih podataka, AI ugovor i kalendarsku sinhronizaciju sa lažnim transportom. HTTP test proverava kompletan tok instalacija–prijava–karton–termin–rezervna kopija, CSRF, kolačiće, sesije i prenos kopije u drugu izolovanu instalaciju.

GitHub Actions konfiguracija pokreće iste testove na PHP 8.2, 8.3 i 8.4. Stvarni OAuth, dostupnost izabranog AI modela i cPanel/Android instalacija zahtevaju proveru u ciljnom okruženju.

## Rezervni lokalni runtime bez instaliranog PHP-a

Za razvojnu proveru dostupan je PHP-WASM 8.3, verzija paketa 3.1.56. Instalacija testnih zavisnosti je opciona i nije potrebna serveru aplikacije:

```sh
npm install --no-save @php-wasm/node@3.1.56 @php-wasm/universal@3.1.56
node tests/php-wasm.mjs tests/run.php
node tests/php-wasm.mjs tests/backend-security.php
node tests/php-wasm.mjs tests/restore-cli.php
node tests/php-wasm.mjs tests/ai.php
node tests/php-wasm.mjs tests/calendar.php
node tests/http.mjs --wasm
```

Ako su zavisnosti već instalirane izvan projekta, postaviti `PLEROMA_NODE_MODULES` na njihov `node_modules` direktorijum. WASM runtime koristi lokalnu loopback vezu; okruženje mora dozvoljavati lokalno slušanje. HTTP test i tada šalje stvarne HTTP zahteve lokalnom mostu, dok aplikacioni PHP izvršava WASM. Ova provera ne zamenjuje native PHP testove iz GitHub Actions.

## PWA i offline provera u pregledaču

```sh
npm install --no-save playwright@1.62.1
npx playwright install chromium
node tests/frontend.mjs
node tests/pwa.cjs
node tests/demo.mjs
```

Test pokreće stvarni service worker na privremenom loopback serveru sa izmišljenim API odgovorima. Proverava manifest i dimenzije ikona, aktivaciju worker-a, izolaciju javnog cache-a od pacijenata i otvaranje prijavnog ekrana bez veze. API, Google callback i URL-ovi sa dodatnim parametrima moraju ostati nedostupni offline. Ne zahteva PHP, bazu ili spoljne API ključeve.

Za postojeću Playwright instalaciju koristiti `PLEROMA_NODE_MODULES`; za postojeći Chromium binarni fajl `PLAYWRIGHT_CHROMIUM_EXECUTABLE`. Opciona promenljiva `PLEROMA_PWA_OUTPUT` zadaje direktorijum za JSON izveštaj i snimak offline ekrana. Provera koristi mobilni viewport u Chromium-u; instalaciju iz menija Chrome-a proveriti i na konkretnom Android uređaju.

## Privremeni vizuelni pregled

```sh
node tests/http-server.mjs --wasm
```

Server ispisuje lokalni URL i privremeni instalacioni kod. Ovaj kod pripada samo novoj testnoj bazi. Podaci se brišu kada se server zaustavi; koristiti isključivo izmišljene podatke. Podrazumevani port je 8787, promenljiv pomoću `PORT`. Ovaj server nije namenjen produkciji.
