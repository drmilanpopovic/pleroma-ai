# Google kalendar — povezivanje i ponašanje

Verzija: 1.0 · Provereno 2. oktobra 2026.

Aplikacija šalje zakazivanja **iz Pleroma Ordinacije u Google kalendar**, kada lekar izabere **Prenesi izmene**. Isto povezivanje radi na računaru i Android PWA aplikaciji, jer su dozvole i prenos na privatnom serveru. Androidu nisu potrebni zaseban Google API ključ niti ugrađena lozinka.

## Šta se prenosi

| Google polje | Vrednost |
| --- | --- |
| Naslov | `Termin · PL-XXXXXXXX`, odnosno postojeća šifra uvezenog kartona `ST-XXXXXXXX` |
| Početak i kraj | Sačuvan termin, sa vremenskom zonom `Europe/Belgrade` |
| Opis | Opšti tekst da su klinički podaci u kartonu; bez sadržaja kartona |
| Vidljivost i zauzetost | `private`, zauzeto |
| Podsetnik | Google popup 30 minuta ranije |
| Privatna tehnička oznaka | Nasumični interni identifikator termina, za proveru kome događaj pripada |

Ime, telefon, adresa e-pošte, datum rođenja, dijagnoza, lekovi, anamneza, beleške, lokacija i vrsta pregleda ne šalju se u događaj. Pacijent se ne dodaje kao učesnik; slanje pozivnica nije uključeno. Šifra kartona je pseudonim, pa kalendar treba da ostane privatan i bez nepotrebnog deljenja.

## Prvo povezivanje

1. Postaviti aplikaciju na HTTPS adresu i proveriti prijavu. Primer adrese: `https://www.epsihijatar.net/ordinacija/`.
2. U [Google Cloud Console](https://console.cloud.google.com/) izabrati projekat za ordinaciju i omogućiti **Google Calendar API**.
3. U Google Auth Platform podesiti naziv aplikacije, kontakt i publiku. Ako je projekat u režimu testiranja, dodati nalog lekara kao test korisnika. Google režim testiranja može ograničiti trajanje dozvola; kod isteka ponoviti povezivanje.
4. Napraviti OAuth klijenta tipa **Web application**. Registrovati potpuno istu povratnu adresu koju koristi instalacija, na primer:

   ```text
   https://www.epsihijatar.net/ordinacija/google-callback.php
   ```

5. U privatnu serversku konfiguraciju uneti `google_client_id` i `google_client_secret`. Tajna ne ide u HTML, JavaScript, GitHub niti poruku u četu.
6. Podesiti `google_calendar_id`. `primary` označava glavni kalendar izabranog Google naloga. Za poseban kalendar ordinacije upisati njegov ID iz podešavanja tog kalendara. Nalog mora imati pravo izmene događaja.
7. U aplikaciji otvoriti **Podešavanja → Google kalendar → Poveži nalog**, izabrati nameravani Google nalog i odobriti dozvole.
8. Prvo napraviti izmišljeni probni karton i termin. Izabrati **Prenesi izmene**, proveriti satnicu u Google kalendaru, zatim probni termin otkazati u aplikaciji i ponoviti prenos.

Traže se dve dozvole: `calendar.events` za događaje i `calendar.calendars.readonly` za čitanje identiteta ciljnog kalendara. Druga dozvola omogućava da se alias `primary` razreši na stvarni ID, kako povezivanje drugog naloga ne bi neprimetno preusmerilo postojeća zakazivanja. Aplikacija ne čita tuđe termine radi popunjavanja kartona.

## Zakazivanje, izmena i otkazivanje

| Radnja u aplikaciji | Sledeći prenos u Google |
| --- | --- |
| Nov termin | Kreira jedan događaj |
| Promena početka, trajanja ili statusa završenog pregleda | Ažurira postojeći događaj |
| Status „Otkazano” ili „Nije došao” | Briše povezani događaj; lokalna evidencija ostaje |
| Ponovno aktiviranje otkazanog termina | Kreira novi aktivni događaj; stari ostaje obrisan |
| Ponovni klik posle prekida veze | Pronalazi prethodno rezervisani ID i nastavlja bez namernog pravljenja duplikata |
| Promena na drugom uređaju tokom prenosa | Novija lokalna izmena ostaje označena za sledeći prenos |

Identifikator događaja čuva se u šifrovanoj bazi **pre prvog mrežnog zahteva**. On ne zavisi od verzije pregleda ili termina. Pre izmene i brisanja proverava se privatna oznaka događaja; nepovezan događaj se ne prepisuje.

Prenos obrađuje najviše 25 izmenjenih termina po pozivu i prekida započinjanje novih stavki posle približno 20 sekundi. Jedan već pokrenut mrežni poziv može trajati duže. Ako je ostalo neprenetih izmena, ponoviti dugme. Greške zadržavaju izmenu u redu za sledeći pokušaj. Aplikacija ne obećava prenos u pozadini i ne postavlja automatski posao.

## Vremenska zona

Unos i prikaz termina u aplikaciji koriste vreme Beograda, nezavisno od zone telefona. Server čuva tačan trenutak u UTC, a Google dobija taj trenutak i oznaku `Europe/Belgrade`. Tako se razlikuju letnje `UTC+02:00` i zimsko `UTC+01:00` vreme. Google na telefonu može prikazivati događaj u svojoj podešenoj zoni, što ne menja zakazani trenutak.

Kod prolećnog preskakanja sata nepostojeći lokalni sat se odbija. Kod jesenjeg vraćanja sata dvosmislen unos se takođe odbija; izabrati jednoznačan sat. Trajanje se računa u stvarno proteklim minutima.

## Granice ove verzije

- **Prenos je jednosmeran i ručan.** Promene koje napravite direktno u Google kalendaru ne menjaju Pleroma kartone ni termine. Izmene pravite u Pleromi; sledeća lokalna izmena može prepisati vreme i radni naslov Google događaja.
- Provera preklapanja obuhvata termine u ovoj aplikaciji. Postojeći privatni sastanci i događaji iz drugih aplikacija ne ulaze u tu proveru.
- Nema javnog samostalnog zakazivanja pacijenata, SMS podsetnika, pozivnica niti dvosmernog uvoza.
- Spoljno brisanje Google događaja otkriva se pri sledećem prenosu lokalno izmenjenog termina. Aplikacija ne obilazi sve već prenete događaje na svakom kliku.
- Promena Google naloga ili ciljnog kalendara nije automatska migracija. Ako mapa postojećeg termina pripada drugom stvarnom ID-u kalendara, prenos te stavke staje sa objašnjenjem; prethodni događaji se ne brišu. Za prebacivanje rada na drugi kalendar najpre treba planirati migraciju.
- Oznaka „Povezan” znači da je lokalno sačuvana dozvola. Pristup se potvrđuje tek pri mrežnom pozivu; dozvola može naknadno isteći ili biti opozvana.
- „Raskini vezu” opoziva Google dozvolu i uklanja tokene sa servera. Događaji koje ste već preneli ostaju u kalendaru. Ako želite njihovo uklanjanje, otkazati odgovarajuće termine i preneti izmene pre raskidanja veze.

## Rezervna kopija i povratak rada

Šifrovana kopija može da sadrži `calendarLinks`: identifikator termina, Google događaja, stvarnog kalendara i stanje `pending`, `active` ili `deleted`. To nisu pristupni tokeni. OAuth dozvole, API ključevi i Google client secret ne izvoze se u kopiju.

Posle vraćanja kopije ponovo povezati **isti Google nalog i isti ciljni kalendar**. Termini se označavaju za proveru/prenos, a vraćene mape omogućavaju nalaženje postojećih događaja. Mapa `pending` takođe je važna: prethodni pokušaj je možda uspeo u Google-u iako se odgovor izgubio.

Starije kopije bez `calendarLinks` mogu sačuvati raniji identifikator događaja. Pre njegove izmene proverava se oznaka aplikacije. Ako stara kopija nema ni mapu ni identifikator, a događaj već postoji u Google-u, automatsko prepoznavanje nije zagarantovano; prvo pregledati probni prenos i postojeće događaje. Kopija ne može da nadoknadi podatke izgubljene pre datuma njenog nastanka.

## Provera i razvoj

`tests/calendar.php` koristi izolovanu privremenu SQLite bazu, izmišljene podatke i eksplicitni CLI HTTP simulator. Ne kontaktira Google i ne pravi stvarne događaje. Proverava OAuth vezivanje za sesiju i jednokratnost, ograničenje poslatih podataka, letnje/zimsko vreme, ponavljanje posle izgubljenih odgovora, otkazivanje, ponovno zakazivanje, konflikt 409, obrisane događaje, proveru vlasništva, paralelnu lokalnu izmenu i promenu naloga.

```bash
php tests/calendar.php
```

Ako lokalno nije instaliran PHP, može se koristiti opcioni razvojni WASM runner opisan u uputstvu za testove. Simulator nije zamena za završnu proveru na stvarnom PHP/HTTPS serveru i Google projektu. Za produkciono povezivanje potrebni su stvarni OAuth podaci i saglasnost vlasnika naloga; oni nisu deo isporučenog paketa.

## Zvanični izvori

Pristupljeno 2. oktobra 2026:

- [Google OAuth 2.0 za serverske aplikacije](https://developers.google.com/identity/protocols/oauth2/web-server) — `state`, offline pristup, povratna adresa i čuvanje tajne na serveru.
- [Kreiranje Calendar događaja](https://developers.google.com/workspace/calendar/api/guides/create-events) — sopstveni ID događaja smanjuje mogućnost duplikata pri ponavljanju zahteva.
- [Calendar Events referenca](https://developers.google.com/workspace/calendar/api/v3/reference/events) — vremena, zone, privatne oznake i otkazani događaji.
- [Čitanje identiteta kalendara](https://developers.google.com/workspace/calendar/api/v3/reference/calendars/get) — razrešavanje `primary` i dozvola za metapodatke.
- [Calendar API greške](https://developers.google.com/workspace/calendar/api/guides/errors) — konflikt 409, brisanje i privremene greške.
