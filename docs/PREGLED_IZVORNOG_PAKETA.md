# Pregled izvornog Pleroma AI paketa

Datum pregleda: 2. oktobar 2026. Izvor: priloženi pleroma_ai_svi_fajlovi.zip i javni HTML stranice https://www.epsihijatar.net/ai-pomocnik/.

## Obuhvat čitanja

Popisano je svih 26 fajlova (1.634.279 raspakovanih bajtova). Pročitana su sva tri uputstva, pregledane zavisnosti, funkcije, API ulazi, povezivanje podataka, autentikacija, lokalno čuvanje i klinički AI promptovi. Za veliki index.html izdvojeni su skriptovi i statički rečnici. Ovo je razvojni pregled, ne izjava da je svaki klinički podatak, formula ili spoljašnja integracija nezavisno validirana.

Javni HTML uspešno je preuzet (HTTP 200). U odnosu na ZIP ima 13 dodatnih redova: ispravku zaglavlja za brisanje pacijenta i izmenu slanja prethodnih poruka AI razgovora. Serverski privatni fajlovi i produkciona baza nisu čitani niti menjani.

Svih šest samostalnih JavaScript modula i šest izdvojenih script blokova prošlo je Node proveru sintakse. To nije funkcionalni test stare produkcije.

## Konkretni nalazi

1. U ZIP-u nedostaju auth.php, config.php i openai_adapter.php, iako ih api.php zahteva. Nema baze pacijenata ni korisnikovih API ključeva. Paket sam za sebe nije mogao da se pokrene kao potpuna instalacija.
2. Glavni kartoni koriste tekstualni ID, dok pleroma_klinika.php kastuje pacijent_id u integer. Različiti nenumerički ID-jevi mogu postati isti broj; nova aplikacija koristi isti tekstualni ID kroz ceo tok.
3. Stari frontend čuva kartone, nacrte i pristupni token u localStorage. Nova verzija čuva kliničke zapise na serveru, koristi HttpOnly sesiju i nema nešifrovani offline karton.
4. Deo starog čuvanja prvo prijavi lokalni uspeh, a tek zatim pokušava server. Nova verzija čuvanje potvrđuje tek po uspehu servera.
5. Stari API direktno menja ceo karton bez proveravanja verzije. Nova verzija odbija prepisivanje kada je drugi uređaj već sačuvao izmenu.
6. U starom promptu za kompletan nalaz postoji zahtev da se popuni svako polje, uključujući pretpostavljeno negiranje alergija i izvođenje mentalnog statusa iz anamneze. Novi AI nacrt mora ostaviti nedostajuće podatke nepopunjenim i ne sme izmišljati nalaz.
7. Stari PWA pokušava registraciju service worker-a iz blob URL-a i predviđa keširanje svih GET odgovora. Novi paket ima stvarni sw.js sa izričitom listom javnih fajlova i isključenim privatnim API odgovorima.
8. Stari modul drugog čitaoca može neispravan AI JSON protumačiti kao prazan spisak nalaza. Nova AI greška/nepotpun odgovor nije potvrda ispravnosti nalaza.
9. U arhivi nema Google Calendar integracije. Novi paket sadrži OAuth tok i jednosmerno prenošenje termina na zahtev.

## Matrica funkcija

| Oblast | Stara aplikacija | Nova Ordinacija 1.0 |
|---|---|---|
| Pacijenti i kartoni | JSON reports unutar pacijenta | Zasebni povezani entiteti, arhiva, pretraga |
| Anamneza / mentalni status / zaključak | Forma + AI popuna | Struktura pregleda, AI nacrt sa potvrdom |
| Terapija | Lista u izveštaju | Aktivna/ranija lista + istorijski tekst nalaza |
| Potvrda i ispravka | Izmena postojećeg | Zaključavanje potvrđenog nalaza i dopune |
| Istorija verzija | Dodatak / SQL trigeri | Ugrađena šifrovana istorija izmena |
| Termini | Ograničeno praćenje kontrola | Nedeljni raspored, statusi, preklapanja, .ics |
| Google kalendar | Nije nađen | OAuth, prenos na zahtev; nije dvosmerno |
| Android | Inline PWA pokušaj | Manifest, stvarni SW, PNG ikone, responsivan UI |
| Diktat | Whisper dodatak | Snimak/transkript uz pregled i potvrdu |
| Slika/PDF | AI prilog | AI prilog uz proveru formata i slanja |
| Skale | Puni upitnici i automatska tumačenja | Evidencija rezultata četiri instrumenta; formulari nisu preneti |
| Laboratorija | Kalkulatori + automatski podsetnici | Beleženje nalaza i ručno određenih kontrola |
| Nazivi dijagnoza / lekova | Hardkodirani rečnici | Pretraživih 317 dijagnoza i 520 preparata; status izvora vidljiv |
| Interakcije, kalkulatori doza, RFZO/ALIMS pravila | Ugrađeno u izvoru | Nisu preneti kao validirani klinički alati |
| REBT i pisma | AI radnje / šabloni | REBT polje i zadaci za AI nacrt |
| PubMed / spoljni izvori | Alati u starom API-ju | Direktne veze; AI ne tvrdi da je pretražio web |
| AI razgovorna memorija | Server/localStorage istorija | Nije trajna automatska memorija; dokumenti i korisnički poslati kontekst |
| Streaming / rezervni AI provajder | Posebni dodaci | Nije uključeno u 1.0 |
| Više korisnika | Administrativni interfejs zavisan od auth.php | Jedan lekar; višekorisnički model nije uključen |
| Monitoring / Cron / mejl upozorenja | Odvojene skripte | Dnevnik radnji; automatski Cron/mejl monitoring nije uključen |
| Rezervne kopije | Opciona enkripcija | Šifrovana kopija posebnom lozinkom i vraćanje u praznu bazu |

Prva verzija pokriva novi svakodnevni tok ordinacije. Nije predstavljena kao potpuna klinički validirana kopija svih alata ogromnog izvornog HTML-a. Original ostaje sačuvan u korisnikovom prilogu, a stare dodatne funkcije treba preneti pojedinačno uz proveru izvora, formula i konkretnog toka.

## Popis pregledanih fajlova

| Fajl | Uloga |
|---|---|
| index.html | Glavni interfejs, rečnici, forme, upitnici, kalkulatori |
| api.php | Pacijenti, AI zadaci, memorija, statistika |
| ai_failover.php | Rezervni AI provajder |
| backup.php | Kopija baze |
| lekovi_brendovi.php | INN i zaštićeni nazivi |
| log_client_error.php | Evidencija frontend grešaka |
| monitor.php | Provera rada i upozorenja |
| pleroma_klinika.php | Rezultati skala i laboratorija |
| pleroma_lib.php | Zajednička baza, tokeni, tajne i pomoćne funkcije |
| proveri.php | AI drugi čitalac |
| search.php | Pretraga teksta |
| stream.php | Striming AI teksta |
| transcribe.php | Glasovna transkripcija |
| upgrade.php | Nadogradnje SQLite šeme |
| pleroma_diktat.js | Kontrole snimanja |
| pleroma_lekovi.js | Graditelj terapije |
| pleroma_monitoring.js | Pravila kliničkih kontrola |
| pleroma_provera.js | Interfejs drugog čitaoca |
| pleroma_skale.js | Četiri skale i istorija |
| pleroma_unapredjenja.js | Token, greške, nacrti, tema i striming |
| pleroma_klinika.css | Stil kliničkih modula |
| pleroma_lekovi.css | Stil terapije |
| pleroma_unapredjenja.css | Stil dodataka |
| UPUTSTVO_KLINICKI_MODULI.md | Uputstvo za skale, diktat i kontrole |
| UPUTSTVO_LEKOVI.md | Brendovi i terapijski red |
| UPUTSTVO_UNAPREDJENJA.md | Backup, verzije, pretraga, monitoring |

## Primarni tehnički izvori

Pristupljeno 2.10.2026:
- OpenAI Responses / tekst: https://developers.openai.com/api/docs/guides/text
- OpenAI transkripcija: https://developers.openai.com/api/docs/guides/speech-to-text
- Google web-server OAuth: https://developers.google.com/identity/protocols/oauth2/web-server
- Google Calendar Events: https://developers.google.com/workspace/calendar/api/v3/reference/events/insert
- MDN instalabilni PWA: https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable

Ovi izvori potvrđuju tehničke obrasce, ne validiraju klinički sadržaj modela ili izvorne rečnike.
