# Dnevni rad u ordinaciji

## Pacijent i pregled

1. „Pacijenti → Novi pacijent“: ime, datum rođenja ako je poznat, kontakt, razlog praćenja i važne alergije. Šifra se dodeljuje automatski.
2. Otvoriti karton. Kartice razdvajaju preglede, terapiju, rezultate i termine.
3. „Novi pregled“: uneti anamnezu i samo procenjene nalaze. Aktivna terapija može se preneti u nacrt; proveriti je za taj datum.
4. „Sačuvaj nacrt“ daje trajni zapis. Nesačuvan unos ostaje samo na otvorenom ekranu.
5. Posle provere označiti potvrdu i kliknuti „Potvrdi nalaz“. Potvrđen nalaz ostaje zaključan.
6. Za ispravku završene dokumentacije koristiti „Napravi dopunu“; original se ne menja.
7. „Štampaj / PDF“ otvara dijalog pregledača. Izabrati „Sačuvaj kao PDF“ za datoteku.

REBT beleške su izdvojeno polje i nisu automatski uključene u štampani specijalistički izveštaj. Štampani nalaz nema izmišljeni potpis niti tvrdi da je kvalifikovano elektronski potpisan.

## Terapija

Svaki lek ima poseban zapis: zaštićeni naziv, INN, jačina/oblik, doza/raspored, početak, završetak i napomena. Za obustavu upisati status i datum. Ako se menja doza, u napomeni navesti razlog; istorija ranijih verzija ostaje u bazi.

Lista aktivnih lekova i terapija u završenom pregledu nisu isti zapis: pregled predstavlja stanje tog datuma, a aktivna lista tekući plan. Promena liste ne prepravlja stari nalaz.

Nazivi u pretrazi preuzeti su iz prethodnog koda. Pre primene proveriti odgovarajući preparat, jačinu i važeće informacije.

## Termini

Termini se vezuju za karton. Prikaz koristi Europe/Belgrade bez obzira na vremensku zonu uređaja. Stanja su zakazano, završeno, otkazano i nije došao. Provera preklapanja obuhvata termine nove aplikacije; Google događaji koji nisu nastali ovde nisu deo te provere.

Iz sačuvanog termina može se preuzeti .ics. To je ručni prenos, ne trajna dvosmerna sinhronizacija. Ponovni uvoz fajla zavisi od ponašanja kalendarskog programa.

## Rezultati i nalazi

Beleže se ukupni rezultati već primenjenih skala. Pozitivna stavka rizika treba da bude zasebno označena i klinički procenjena; automatski skor nije potvrđena dijagnoza. Laboratorijski zapis sadrži datum, vrednost sa jedinicom, komentar i datum sledeće kontrole koji je zadao lekar.

## Uvoz stare aplikacije

U staroj Pleromi napraviti JSON izvoz pacijenata. Nova aplikacija najpre pokazuje broj kartona i pregleda. Posle potvrde prenosi ih, čuva izvorna polja i ne prepisuje postojeće kartone.

Godina rođenja iz stare baze ne pretvara se u izmišljeni datum rođenja. Čuva se kao izvorni podatak/napomena. Terapija iz starih izveštaja ostaje u njima; lekar potvrđuje šta je i dalje aktivno pre unošenja u novu aktivnu listu.

Novi zapis nije potvrđen samo zato što je uvezen: stari izveštaji se prikazuju kao nacrti za proveru. Uvoz nije povezan sa živom starom bazom; naknadne promene stare aplikacije ne stižu automatski.

## Kada je veza prekinuta

Ne zatvarati ekran sa nesačuvanim radom. Kada se veza vrati, pokušati čuvanje. Ako je drugi uređaj već izmenio isti zapis, osvežiti i uskladiti izmene. Aplikacija ne tvrdi da je rad sačuvan dok server nije potvrdio upis.

Pri zaključavanju/odjavi klinički podaci se uklanjaju iz prikaza. Izmišljena demonstracija ne čuva podatke nakon zatvaranja.
