# Pleroma na Android telefonu i računaru

Ovo je responzivna veb aplikacija sa PWA instalacijom. Jedna serverska instalacija služi i telefonu i računaru; oba uređaja pristupaju istoj evidenciji. Aplikacija se dodaje na početni ekran i otvara u zasebnom prozoru. Paket ne sadrži izvorni Android APK i nije objavljen na Google Play prodavnici.

## Android

1. Postaviti serversku verziju na svoju HTTPS adresu prema glavnom uputstvu.
2. Na telefonu otvoriti tu adresu u Chrome pregledaču i prijaviti se.
3. U aplikaciji izabrati **Više → Instaliraj Pleromu**. Ako se ponuda ne prikaže, otvoriti Chrome meni **⋮ → Instaliraj aplikaciju** ili **Dodaj na početni ekran**; naziv zavisi od verzije pregledača.
4. Pokrenuti ikonu **Pleroma** sa početnog ekrana i prijaviti se ako je sesija zaključana.

Na uskom ekranu donja navigacija vodi na današnji pregled, pacijente, termine, AI pomoćnika i dodatna podešavanja. Kalendar prikazuje dnevni spisak termina. Formulari se slažu u jednu kolonu, a karton zadržava preglede, terapiju, merenja i zakazivanja.

## Računar

Otvoriti istu HTTPS adresu u Chrome ili Edge pregledaču. Koristiti dugme za instalaciju u aplikaciji ili mogućnost instalacije u meniju pregledača. Aplikacija je dostupna i kao obična veb stranica bez instaliranja.

Samostalni demonstracioni HTML prikazuje funkcije sa izmišljenim podacima. Ne predstavlja serversku bazu i nije namenjen vođenju stvarnih kartona.

## Veza, čuvanje i ažuriranje

- Internet je potreban za prijavu, čitanje i čuvanje kartona, AI funkcije i Google kalendar. Sačuvano stanje potvrđuje aplikacija nakon odgovora servera.
- Service worker čuva samo javni izgled i programske fajlove aplikacije. Ne čuva odgovore API-ja, kartone, snimke, priloge ili Google OAuth odgovore u Cache Storage. Nema reda za slanje medicinskih podataka u pozadini.
- Pri prekidu veze već otvoreni prikaz i nesačuvan unos mogu ostati u memoriji otvorenog prozora do zatvaranja ili zaključavanja. To nije rezervna kopija. Po povratku veze završiti čuvanje pre zatvaranja.
- Pre ažuriranja sačuvati rad i zatvoriti sve prozore aplikacije i njene kartice u pregledaču. Nova verzija service worker-a čeka da se postojeći prozori zatvore; ne prekida rad automatskim učitavanjem. Zatim ponovo otvoriti aplikaciju dok postoji veza.
- Brisanje ikone sa telefona ne briše kartone na serveru. Rezervne kopije se prave kroz podešavanja serverske aplikacije.

## Tehničke napomene za instalatera

- Javno korenje je direktorijum `public/`. `manifest.webmanifest`, `sw.js` i `assets/` moraju biti dostupni na istoj HTTPS adresi. Manifest i relativne putanje podržavaju i instalaciju u poddirektorijumu.
- Za svako novo izdanje javnih fajlova promeniti `VERSION` u `public/sw.js` i objaviti ceo skup fajlova zajedno. Cache je zaseban za adresu instalacije i verziju. Nepotpun skup fajlova sprečava aktiviranje novog worker-a.
- Manifest sadrži PNG ikone 192 × 192 i 512 × 512, uz posebnu maskable ikonu 512 × 512 za Android. Ikone su izvedene iz izvornog `assets/icon.svg`.
- Registracija i instalacija PWA zahtevaju siguran kontekst; lokalni `localhost` služi samo za razvoj. Otvaranje kroz `file://` služi demonstraciji, bez serverskih funkcija i service worker-a.
- HTTPS i instalirana ikona sami po sebi nisu kontrola pristupa bazi. Serversku konfiguraciju, privatni direktorijum i rezervne kopije podesiti prema glavnom uputstvu.

Dokumentacija pregledača, proverena 2. 10. 2026: [web.dev — manifest](https://web.dev/learn/pwa/web-app-manifest), [web.dev — ažuriranje](https://web.dev/learn/pwa/update/), [MDN — instalacija PWA](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable).
