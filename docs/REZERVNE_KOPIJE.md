# Rezervne kopije i vraćanje podataka

Rezervnu kopiju preuzmite u aplikaciji, u odeljku **Podešavanja → Rezervna kopija**. Unesite posebnu lozinku od 12–200 bajtova. Razmaci na početku i kraju lozinke su značajni. Kopija je JSON datoteka sa nastavkom `.pleroma`; njen sadržaj je šifrovan AES-256-GCM ključem izvedenim iz lozinke pomoću PBKDF2-SHA256 sa 600.000 iteracija i slučajnom solju.

Kopija sadrži kartone, preglede, terapiju, zakazivanja, rezultate skala, laboratorijske zapise, prethodne verzije, dnevnik radnji i podatke potrebne za nastavak prenosa termina u Google kalendar. Originalni podaci uvezeni iz prethodne Plerome ostaju sačuvani. Lozinka naloga, OpenAI ključ, Google OAuth tajna i pristupni/refresh tokeni **nisu** deo kopije. Privatni `config.php` i njegov `encryption_key` čuvajte zasebno: ključ je potreban za postojeću serversku bazu, dok se `.pleroma` kopija može vratiti pod novim ključem uz lozinku kopije.

Čuvajte kopiju i lozinku odvojeno, izvan računara/servera na kome je ordinacija. Povremeno proverite vraćanje u izdvojenu probnu instalaciju. Nijedna automatizovana pozadinska kopija nije uključena ovim paketom.

## Vraćanje kroz aplikaciju

Web obrazac prihvata kopiju do **10.000.000 bajtova (10 MB)**. Serversko ograničenje celog JSON zahteva je 12.000.000 bajtova, što ostavlja prostor za strukturu zahteva i lozinku. Velike kopije se i dalje mogu preuzeti, a vraćaju se administratorskim CLI postupkom ispod.

1. Pripremite odvojenu instalaciju ili praznu kliničku bazu i završite početno podešavanje naloga.
2. Otvorite **Podešavanja → Vrati kopiju u praznu bazu**.
3. Izaberite `.pleroma` datoteku i unesite njenu lozinku.
4. Posle vraćanja proverite nekoliko kartona, broj pregleda, terapiju, prethodne verzije i termine.

Vraćanje odbija postojeće kliničke zapise ili revizije; ne prepisuje aktivnu evidenciju. Proveravaju se šifrovanje, šema, jedinstveni ID, verzije, pripadnost pacijentu i povezane istorijske verzije. Upis se izvršava u jednoj transakciji. Bazu koja već sadrži podatke prvo sačuvajte kao zasebnu kopiju i izaberite novo odredište; nemojte brisati postojeću bazu samo da biste oslobodili mesto za ovaj postupak.

## Administratorski CLI za velike kopije

`tools/restore.php` radi isključivo iz komandne linije i koristi istu funkciju za proveru i transakcijsko vraćanje kao web aplikacija. Prihvata `.pleroma` kopije do **256 MiB (268.435.456 bajtova)**. Ne prikazuje lozinku, imena ili klinički sadržaj; ispisuje samo uspeh/broj zapisa ili opis greške. Koristite sistemskog korisnika koji poseduje privatni direktorijum aplikacije. Ograničite pristup rezervnoj kopiji i držite je izvan `public_html`.

Za standardnu instalaciju, iz korena projekta:

```bash
php tools/restore.php /home/korisnik/privatne-kopije/ordinacija.pleroma
```

Lozinka se unosi skriveno preko TTY terminala, kada su dostupni `exec` i sistemski `stty`. Ako skriveni unos nije moguć, alat ga odbija i upućuje na sledeći postupak; lozinka se ne ispisuje otvoreno kao zamena.

Ako je konfiguracija van podrazumevane lokacije ili je kopija velika:

```bash
PLEROMA_CONFIG=/home/korisnik/pleroma-private/config.php php -d memory_limit=1G tools/restore.php /home/korisnik/privatne-kopije/ordinacija.pleroma
```

Kopija se obrađuje u memoriji. Potrošnja zavisi od broja i veličine zapisa; kod najvećih kopija može biti potrebno više od 1 GiB. CLI granica od 256 MiB nije obećanje da će svaka konfiguracija PHP memorije moći da obradi takvu datoteku. Nedostatak memorije ne opravdava brisanje odredišne baze.

Za PHP okruženje bez `exec`, koristite Bash skriveni unos i prosledite lozinku standardnim ulazom. Lozinka se ne unosi u komandnu liniju, istoriju komandi ili promenljive okruženja:

```bash
read -r -s -p 'Lozinka kopije: ' PLEROMA_RESTORE_SECRET
printf '\n'
printf '%s\n' "$PLEROMA_RESTORE_SECRET" | php tools/restore.php /home/korisnik/privatne-kopije/ordinacija.pleroma --password-stdin
unset PLEROMA_RESTORE_SECRET
```

Ne uključujte shell `set -x` / prikaz izvršenih komandi tokom unosa tajni. Za automatizovani oporavak upravljač tajnama može proslediti jedan red lozinke na stdin uz `--password-stdin`. Nemojte upisivati lozinku direktno u komandu ili trajni skript.

Izlazni kod je `0` pri uspehu, `1` pri neuspešnom vraćanju i `2` pri pogrešnoj upotrebi komande. `--help` prikazuje kratko uputstvo. Odredište se ponovo proverava u transakciji, pa ni paralelni zahtev koji je u međuvremenu popunio bazu neće biti prepisan.

## Google kalendar posle vraćanja

Kopija čuva mapu oznaka događaja, konkretan ID kalendara i fazu `pending`, `active` ili `deleted`, bez OAuth tokena. Faza `pending` se čuva i kada je Google možda prihvatio zahtev, a aplikacija nije dobila odgovor. Time se izbegava ponovno kreiranje istog događaja posle prekida veze.

Posle vraćanja termini zahtevaju novu proveru/prenos. Ponovo povežite Google nalog ako instalacija nema aktivnu dozvolu. Prenos potvrđuje konkretan ID kalendara i oznaku vlasništva događaja; drugačiji nalog/odredište blokira postojeću mapu umesto da je neprimetno prebaci. Starije kopije bez mape zadržavaju postojeći ID događaja, koji se proverava pre izmene. Pregledajte poruke prenosa pre ponovnog zakazivanja istih termina. Vraćanje ne uklanja proizvoljne događaje iz Google kalendara.

## Šta je šifrovano na serveru

Šifrovani su sadržaji kliničkih zapisa, prethodnih verzija i podešavanja, uključujući integracione tokene. SQLite datoteka kao celina nije SQLCipher baza. Vrsta zapisa, nasumični ID, veza sa nasumičnim ID pacijenta, broj verzije, vreme upisa i tehnički dnevnik radnji postoje kao čitljivi metapodaci u privatnoj SQLite datoteci. Zato je i uz šifrovanje obavezno da `data_dir` bude izvan javnog web direktorijuma, sa dozvolama `0700`, a baza `0600`.

Preuzeta `.pleroma` kopija šifruje i navedene metapodatke; izvan šifrovanog dela ostaju oznaka formata, so i broj PBKDF2 iteracija. Instalaciona provera, testovi sintetičkih podataka i ovaj postupak ne zamenjuju proveru stvarnog hostinga, pristupa i oporavka pre rada sa pravim kartonima.
