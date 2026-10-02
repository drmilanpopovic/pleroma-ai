# Stvarno izvršene provere — 2. oktobar 2026.

Testovi koriste isključivo izmišljene kartone, izolovane privremene baze i simulirane odgovore spoljašnjih servisa. Izvorni ZIP i živa aplikacija nisu menjani. Nisu korišćeni stvarni pacijenti, API ključevi ili Google nalozi.

## Lokalni rezultat

| Skup | Rezultat | Šta obuhvata |
|---|---:|---|
| `tests/run.php` | 51/51 | Evidencija, revizije, zaključan nalaz, termini, kopija i uvoz |
| `tests/backend-security.php` | 36/36 | Neispravni ulazi, integritet, transakcije, uvoz i Google mape |
| `tests/restore-cli.php` | 6/6 | Vraćanje šifrovane kopije veće od 12 MB, granice i neispravan fajl |
| `tests/ai.php` | 60/60 | Ugovor AI zahteva/odgovora, prilozi, identifikatori i nepotpun izlaz |
| `tests/calendar.php` | 22/22 | OAuth state, dozvole, prekidi, ponavljanje, otkazivanje i vlasništvo |
| `tests/http.mjs --wasm` | 51/51 | Stvarni lokalni HTTP, prijava, CSRF, dva uređaja, oporavak i odjava |
| `tests/frontend.mjs` | 29/29 | Obrasci, nacrti, kasni odgovori, AI pregled, vremenske zone i zaključavanje |
| `tests/pwa.cjs` | 11/11 | Aktivni service worker, ikone, 9 javnih fajlova u kešu i privatnost offline |
| `tests/demo.mjs` | 23/23 | Izrada samostalnog HTML-a, prikazi 320/412/768/1440 px, bez spoljnih zahteva |

Lokalni PHP runtime: **PHP 8.3.33 kroz PHP-WASM 3.1.56**, sa OpenSSL i SQLite. HTTP testovi šalju stvarne loopback zahteve; ovo lokalno nije native PHP proces. Browser provere: **Playwright 1.62.1 / Chromium**, uključujući mobilne dimenzije i browser vremensku zonu America/New_York radi provere termina u Europe/Belgrade. Nema neuhvaćenih JavaScript grešaka u navedenim završenim testovima.

Vizuelno je pregledano i 40 kombinacija ekrana i širina. Proverena je štampa nalaza bez navigacije. Android instalacija na fizičkom telefonu nije izvršena.

## GitHub

`.github/workflows/ci.yml` pokreće native PHP **8.2, 8.3 i 8.4** sa Node.js 24, kao i zaseban browser posao. Svaki PHP posao proverava sintaksu, osnovne/security/AI/Calendar/velike-backup testove i stvarni PHP HTTP server. Browser posao proverava interfejs, PWA i izgrađeni HTML.

Aktuelan status izvršavanja, tačan commit i logovi dostupni su u [GitHub Actions](https://github.com/drmilanpopovic/pleroma-ai/actions). Postojanje workflow datoteke samo po sebi nije potvrda uspešnog izvršavanja; rezultat treba čitati za odgovarajući commit.

## Pre probnog rada ordinacije

- Proveriti ciljni PHP hosting, HTTPS, privatne direktorijume, dozvole i isključenje CDN/WordPress keša.
- Proveriti OpenAI nalog, dostupni model, stvarnu transkripciju i izlaz modela na izmišljenim slučajevima. Fixture testovi ne mere klinički kvalitet modela.
- Podesiti Google OAuth i probati povezivanje/prenos/izmenu/otkazivanje u zasebnom probnom kalendaru. Nije izvršen stvarni Google poziv.
- Proveriti instalaciju i diktiranje na konkretnom Android telefonu i vraćanje kopije na drugom serveru.

Ovo nisu rezultati nezavisnog penetracionog testa, zakonske procene usklađenosti ili medicinske sertifikacije. Detaljne komande za ponavljanje nalaze se u `tests/README.md`.
