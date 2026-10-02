# AI pomoćnik i diktiranje

Verzija dokumenta: 1.0 · pregled koda 2. oktobra 2026.

## Namena

AI sloj pomaže lekaru da uredi beleške, sastavi nacrt anamneze ili kompletnog izveštaja, sažme dokumentaciju, pripremi REBT formulaciju, pismo kolegi, psihoedukativni tekst, pitanja za diferencijalnu procenu i radni plan. Drugi čitalac izdvaja nedoslednosti za proveru. Nijedna od ovih funkcija ne propisuje terapiju niti sama potvrđuje ili potpisuje nalaz.

U pregledanom originalnom `api.php`, prompt za automatski kompletan izveštaj tražio je popunjavanje svih polja, podrazumevano negiranje alergija i mentalni status izveden iz kratke anamneze. To nije preuzeto. U novoj verziji nedostajući podatak ostaje označen kao nedostajući; ne pretvara se u uredan nalaz, procenu niskog rizika ili negiranje simptoma.

## Tok rada i podataka

1. Lekar bira zadatak i proverava tekst za slanje. Izbor kartona omogućava uklanjanje poznatih identifikatora; ne dodaje druge zapise iz baze u AI zahtev.
2. Server traži izričitu potvrdu slanja i odbija nepostojeći/arhivirani izabrani karton. Prosleđuje samo pregledan tekst i najviše jedan izabrani prilog.
3. Pomoćna pseudonimizacija uklanja poznato ime i delove imena, kontakt, šifru kartona, deo zapisa datuma rođenja, poznato mesto, e-poštu i JMBG. Nije potpuna anonimizacija: padeži, nadimci, adrese, porodične okolnosti i retki podaci mogu ostati. Lekar zato pregleda sadržaj pre slanja.
4. PDF/slika zahteva posebnu potvrdu da su identifikatori uklonjeni. Binarni prilog se ne anonimizuje. Za skenirani dokument treba pripremiti stvarno redigovanu kopiju; vizuelno prekrivanje imena ne mora ukloniti tekst u PDF sloju.
5. Zahtev ide server–OpenAI preko HTTPS; ključ ostaje na serveru. Odgovor je tekstualni nacrt. UI ga prikazuje kao tekst, bez izvršavanja HTML-a ili komandi iz odgovora.
6. Lekar proverava predlog i bira da li ga prenosi u nacrt. Potvrđivanje nalaza ostaje zasebna radnja. Nepotpun odgovor je trajno označen i nema dugme za prenos u klinička polja.

Nema automatskog slanja cele istorije, identifikatora korisnika, pretrage interneta, tool-calling izvršavanja, memorije razgovora niti rezervnog AI provajdera. Uputstva pronađena u tekstu/prilogu tretiraju se kao nepouzdan sadržaj. Model nema pristup čitanju drugih kartona ili upisu u bazu.

## Server i obrada grešaka

- PHP 8.2+; cURL, Fileinfo i standardna podrška za proveru dimenzija slike.
- `openai_api_key` i `openai_model` su privatna konfiguracija. Model mora podržavati Responses API; za PDF/slike potrebna je podrška za odgovarajući ulaz. Dostupnost konkretnog modela zavisi od naloga.
- `store: false`, jedan sinhroni zahtev, najviše 4.500 izlaznih tokena. Nema automatskih ponavljanja koja bi neopaženo uvećavala trošak.
- Klinički tekst je ograničen na 60.000 bajtova. Prilog: do 5 MB, provereni MIME za PDF/JPEG/PNG/WebP; slike do 40 megapiksela i do 16.000 piksela po dimenziji. Naziv priloga se ne šalje; PDF dobija neutralan naziv.
- Mrežni sloj prihvata samo odobrene HTTPS endpoint-e; ne prati preusmeravanja, proverava TLS i ograničava odgovor na 3 MB. Sadržaj odgovora i ključevi ne ulaze u log greške.
- `failed`, `cancelled`, `queued`, `in_progress`, odbijanje modela, prazan tekst i neispravna struktura odbijaju se. Delimičan odgovor nosi `incomplete: true`, `canApply: false` i vidljivu oznaku u samom tekstu.
- Evidentiraju se radnja slanja, zadatak i ishod, bez sadržaja beležaka ili priloga. AI operacija sama ne menja karton.

## Diktat

Snima se lekarov diktat uz potvrdu slanja OpenAI servisu. Diktat nije lokalno prepoznavanje govora. Originalni zvuk može sadržati identifikatore i ne prolazi tekstualnu pseudonimizaciju pre slanja. Koristiti izraze poput „pacijent“, bez imena i kontakta.

Frontend završava snimanje posle pet minuta; server ograničava upload na 12 MB i prihvata stvarni WebM, MP3, MP4 ili WAV. Audio se ne smešta u aplikacionu bazu; PHP upload koristi privremeni fajl tokom obrade zahteva. Transkript zahteva proveru, posebno imena lekova, doza, jedinica i negacija. Tekst nije dokaz da je iskaz pravilno prepoznat. Kada nema dovoljno razumljivog govora, nastaviti ručno.

## Čuvanje kod provajdera

`store: false` isključuje aplikaciono čuvanje Responses objekta; nije obećanje da spoljni provajder ništa ne zadržava. Dokumentacija OpenAI odvojeno opisuje evidenciju zloupotreba, keširanje, izuzetke za priloge i dostupnost Zero Data Retention. Pre realnih pacijenata proveriti ugovor, podatke projekta, region i odgovarajući osnov obrade. Ova aplikacija ne menja podešavanja zadržavanja ili region API naloga.

## Provera i ograničenja

`php tests/ai.php` pokreće 60 offline provera: minimizacija ulaza, očuvanje doza/negacija, granice instrukcija, izdvojena potvrda priloga, MIME/veličina, zabrana neželjenih endpoint-a, parsiranje završenih/nepotpunih/odbijenih/neispravnih odgovora. Testovi proveravaju aplikacione granice i ne dokazuju kliničku tačnost modela.

Nije izvršen nijedan pravi OpenAI zahtev: ključ nije dostavljen, API potrošnja nije napravljena. Pre upotrebe sa pacijentima treba uraditi mali probni skup izmišljenih beležaka: nedostajuće alergije/status/rizik, kontradiktorne negacije, nejasna doza, loš diktat, različiti identiteti u prilogu i ubačene instrukcije. Proveriti da ništa ne ulazi u karton bez lekara. Model/version promene zahtevaju ponovnu proveru tog skupa.

## Provereni tehnički izvori

- OpenAI, [Responses API](https://developers.openai.com/api/docs/guides/migrate-to-responses), pregled 2.10.2026: format odgovora i `store` opcija.
- OpenAI, [File inputs](https://developers.openai.com/api/docs/guides/file-inputs), pregled 2.10.2026: PDF ulaz kao base64 `input_file`; podrška ulaznog tipa zavisi od modela. Lokalni limit aplikacije namerno je manji od API limita.
- OpenAI, [File transcription](https://developers.openai.com/api/docs/guides/speech-to-text), pregled 2.10.2026: audio transkripcija, podržani formati i ograničenja datoteke.
- OpenAI, [Data controls](https://developers.openai.com/api/docs/guides/your-data), pregled 2.10.2026: razlika između aplikacionog čuvanja, evidencije zloupotreba i posebnih kontrola zadržavanja.
