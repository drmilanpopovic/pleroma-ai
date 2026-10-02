# Arhitektura i granice 1.0

## Tok podataka

Pregledač / Android PWA → HTTPS + HttpOnly sesija + CSRF → PHP API → AES-256-GCM šifrovani sadržaj → SQLite izvan web root-a.

AI zahtev: korisnik pregleda sadržaj → pseudonimizacija poznatih tekstualnih identifikatora → serverski API ključ → OpenAI Responses → nacrt → lekar bira prenos u dokument. Slike i PDF zahtevaju korisničku proveru; automatska anonimizacija priloga nije ugrađena.

Google zahtev: OAuth saglasnost → šifrovani tokeni na serveru → ručni prenos termina → Google događaj sa pseudonimizovanim nazivom. Integracija ne šalje pozivnice pacijentima i ne prenosi klinički tekst.

## Entiteti

| Entitet | Značenje |
|---|---|
| patients | Identitet, kontakt, alergije, razlog praćenja, arhiviranje |
| visits | Dokument pregleda sa nacrtom/potvrdom/dopunom |
| medications | Aktivna i ranija terapija |
| appointments | Vreme, trajanje, vrsta, status |
| measures | Rezultat instrumenta i napomena |
| labs | Laboratorijski nalaz i planirana kontrola |
| revisions | Prethodna šifrovana verzija izmenjenog zapisa |
| audit | Pristup i radnja bez sadržaja kliničkog teksta |

Nasumične oznake zapisa su tekst od 32 heksadecimalna znaka, ne brojevi; svaki klinički zapis proverava vezu sa postojećim pacijentom. Svaka izmena nosi verziju. Stara verzija pri paralelnom radu izaziva konflikt umesto tihog prepisivanja.

## Zaštite koje su ugrađene

Šifrovanje sadržaja na serveru i kopije posebnom lozinkom; ključevi izvan javnog frontenda; prepared SQL; ograničenja tipova/veličine; HTTPS; HttpOnly/SameSite sesija; CSRF; ograničenje pokušaja prijave i API upotrebe; Content Security Policy; bez keširanja privatnog API-ja; čuvanje izmena u transakcijama.

Šifrovani su sadržaji kartona, nalaza, istorijskih verzija i podešavanja. Opaque oznake, veze zapisa, verzije, vremena i dnevnik radnji ostaju metapodaci baze; ovo nije šifrovanje celog SQLite fajla. Kod jedne ordinacije API učitava skup kartona u aktivnu sesiju pregledača. Veće instalacije zahtevaju paginaciju, pretragu na serveru i posebno testiranje opterećenja.

Otvoreni pregledač i kompromitovan server mogu pristupiti otključanim podacima. Ovo nije tvrdnja o kompletnoj zakonskoj usklađenosti, nezavisnom penetracionom testu, medicinskoj sertifikaciji ili validaciji AI zaključaka.

## Kreativni dalji razvoj — nije uključeno kao gotova funkcija

1. Pregled pre dolaska: sažetak poslednjeg nalaza, odstupanja u terapiji i pitanja koja je lekar označio.
2. Kontrolisano poređenje: promena simptoma i skala između dva pregleda sa vezom na izvorni zapis.
3. Terapijski dnevnik: eksplicitni događaji uvođenja, titracije i obustave, uz potvrdu lekara.
4. Porodični/partnerski rad: odvojene beleške, saglasnosti i vidljivost po epizodi lečenja.
5. Višelekarska ordinacija: uloge, zasebne dozvole, dvofaktorska prijava i strogo razdvajanje pacijenata.
6. Kalendar u oba smera: pregled konflikta, potvrda promena i eksplicitno vezivanje spoljnog događaja uz karton.
7. Verifikovana klinička biblioteka: primarni izvori, verzije smernica, urednička provera i datum važenja.

Ove mogućnosti se uvode tek kroz konkretne testove i potvrđen klinički tok, bez automatskog menjanja terapije ili samostalnog prepisivanja dokumentacije.
