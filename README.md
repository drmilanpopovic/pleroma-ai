# Pleroma Ordinacija 1.0

Aplikacija za jednog lekara: kartoni pacijenata, istorija pregleda, anamneza, aktivna i ranija terapija, termini, REBT beleške, AI nacrti i instalacija na Android / desktop kao PWA.

**Status:** funkcionalna razvojna verzija za instalaciju i probni rad. Nije postavljena na korisnikov hosting. Stvarni OpenAI pozivi i Google OAuth povezivanje zahtevaju korisnikovu konfiguraciju i proveru. Tačni rezultati testova su u dokumentu TESTIRANJE.md.

## Brzi pregled

Otvorite public/index.html?demo=1 preko lokalnog servera. Demonstracija sadrži izmišljene podatke samo u memoriji. Nijedan njen unos nije trajno sačuvan.

Za samostalni HTML pregled: `node tools/build-demo.mjs`. Dobijeni `dist/Pleroma-pregled.html` otvara se direktno u pregledaču i ne poziva server. AI i Google nisu aktivni u demonstraciji.

Serverski režim zahteva PHP 8.2+, PDO SQLite, OpenSSL, cURL, fileinfo, sesije i HTTPS. Nema Composer, Node ili frontend build zavisnosti za sam rad aplikacije.

## Funkcije

- Kartoni sa pretragom po imenu, šifri, dijagnozi i aktivnom leku.
- Pregledi sa anamnezom, mentalnim statusom, dijagnozom, terapijom, procenom rizika, zaključkom i planom.
- Nacrt → lekarska potvrda → zaključan nalaz; dopuna je novi dokument.
- Istorija sačuvanih verzija i zaštita od istovremenog prepisivanja na telefonu i računaru.
- Aktivna/obustavljena terapija sa generičkim i zaštićenim nazivom, jačinom i rasporedom.
- Termini sa kontrolom preklapanja, statusima i izvozom .ics.
- Google kalendar: eksplicitan jednosmerni prenos termina, bez kliničkih podataka.
- Beleženje rezultata PHQ-9, GAD-7, HAM-A i MADRS; laboratorijski nalazi i datumi kontrola.
- AI pomoć za dokumentaciju, REBT formulaciju, sažetak, pismo, psihoedukaciju i radnu kliničku procenu.
- Diktiranje uz pregled transkripta; PDF/slika uz pregled sadržaja pre slanja.
- Šifrovana baza sadržaja, šifrovane rezervne kopije, evidencija radnji.
- Uvoz JSON izvoda postojeće Plerome uz očuvanje originalnih polja.

Preuzeta priručna baza sadrži 317 naziva dijagnoza i 520 stavki preparata iz korisnikovog izvornog koda. Nije ažurirani ili validirani registar lekova. Doziranje i odluke unosi lekar.

## Pokretanje

1. Postavite kompletan projekat izvan javnog web direktorijuma.
2. Pokrenite: php tools/init.php
3. U privatnom config.php unesite base_url i apsolutni data_dir izvan public_html.
4. Podesite javni document root na public/ ili upotrebite cPanel raspored iz docs/INSTALACIJA.md.
5. Otvorite HTTPS adresu, unesite instalacioni kod iz config.php i postavite svoju lozinku.
6. Proverite rad sa izmišljenim pacijentom i vraćanje rezervne kopije pre korišćenja stvarne dokumentacije.

Za lokalnu proveru: podesite base_url na http://127.0.0.1:8080, allow_local_http na true, pa pokrenite php -S 127.0.0.1:8080 -t public. HTTP izuzetak važi samo za loopback, ne za telefon preko lokalne mreže.

## Dokumentacija

- docs/INSTALACIJA.md — hosting, cPanel, privatne putanje i podešavanje.
- docs/KORISCENJE.md — dnevni rad, pregledi, terapija i prenos podataka.
- docs/ANDROID.md — instalacija i mobilni režim.
- docs/GOOGLE_KALENDAR.md — OAuth, sinhronizacija i ograničenja.
- docs/AI.md — AI ulaz, izlaz i potvrda.
- docs/PREGLED_IZVORNOG_PAKETA.md — pregled prethodne aplikacije i matrica funkcija.
- docs/ARHITEKTURA.md — tokovi podataka i granice prve verzije.
- docs/REZERVNE_KOPIJE.md — vraćanje kopija kroz interfejs ili administratorski CLI.
- TESTIRANJE.md — šta je stvarno provereno.

## Granice

PWA je instalabilna veb aplikacija, a ne posebno potpisan APK. Za kartone je potreban server i mreža; nema lokalne nešifrovane kopije kartona u pregledaču. Google sinhronizacija nije dvosmerna. Nema e-recepta, obračuna, SMS/e-mail slanja, višelekarskih uloga ili tvrdnje o sertifikaciji medicinskog softvera.

Stari automatski kalkulatori doza, nepouzdane interakcije, podrazumevani normalni nalazi i automatski rasporedi kliničkog monitoringa nisu preuzeti kao potvrđena klinička pravila. Njihovo stanje i put dalje su izričito navedeni u matrici funkcija.

Nikada ne stavljajte config.php, bazu, snimke, tokene ili rezervne kopije pacijenata u GitHub.
