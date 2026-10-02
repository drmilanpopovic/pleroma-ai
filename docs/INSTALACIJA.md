# Instalacija na cPanel / PHP hosting

## Šta se postavlja

Frontend je HTML/CSS/JavaScript. PHP obrađuje prijavu, šifrovanu SQLite bazu i veze sa AI i Google servisima. Jedan HTML fajl bez servera može služiti kao demonstracija; nije zajednička baza telefona i računara.

Zahtevi: PHP 8.2 ili noviji; ekstenzije pdo_sqlite, openssl, curl, fileinfo; sesije; writable privatni direktorijum; HTTPS; mogućnost izlaznih HTTPS zahteva ka OpenAI/Google servisima. Za veću ordinaciju/više lekara predviđena je kasnija migracija i kontrola pristupa.

## Varijanta A — poseban subdomen

1. Napraviti privatni direktorijum /home/CPANEL_NALOG/pleroma-ordinacija.
2. Raspakovati projekat u njega.
3. Za subdomen, na primer ordinacija.epsihijatar.net, podesiti document root na /home/CPANEL_NALOG/pleroma-ordinacija/public. Ako hosting ograničava root, upotrebiti varijantu B.
4. U terminalu, iz korena projekta, pokrenuti php tools/init.php. Dobija se nov config.php; postojeći se ne prepisuje.
5. U config.php postaviti base_url na stvarnu HTTPS adresu bez završne kose crte.
6. data_dir mora biti van svih javnih web direktorijuma, npr. /home/CPANEL_NALOG/pleroma-private-data.
7. encryption_key sačuvati i van servera. Bez njega originalna baza ne može da se otključa.
8. Otvoriti adresu; u ekranu za prvo podešavanje uneti setup_token iz config.php, e-poštu i lozinku od najmanje 12 znakova. Nema fabričke lozinke.

## Varijanta B — postojeći domen /ordinacija/

Privatno:
- /home/CPANEL_NALOG/pleroma-private/app/
- /home/CPANEL_NALOG/pleroma-private/tools/
- /home/CPANEL_NALOG/pleroma-private/config.example.php
- /home/CPANEL_NALOG/pleroma-private/config.php

Javno:
- sadržaj public/ kopirati u /home/CPANEL_NALOG/public_html/ordinacija/
- ne kopirati privatne fajlove, tests/ i docs/ u javni folder.

U javnom direktorijumu napraviti _private_path.php sa ovim sadržajem, uz stvarnu putanju:

    <?php
    return '/home/CPANEL_NALOG/pleroma-private/app';

Ovaj fajl sadrži samo putanju, bez ključeva. base_url u privatnom config.php treba da bude https://www.epsihijatar.net/ordinacija. data_dir, ključevi i sesije ostaju van public_html.

API i Google callback koriste isti bootstrap. Postojeći /ai-pomocnik/ ostaje zasebna aplikacija; ne raspakivati novi paket preko njega.

## WordPress keš i CDN

Izuzeti celu putanju ordinacije iz WordPress/CDN keširanja i optimizacije JavaScript-a. Posebno ne keširati *.php odgovore, Cookie sesije ili privatne API pozive. Service worker nove aplikacije sme da čuva samo javni interfejs.

Na Apache-u uključiti AllowOverride za dostavljeni .htaccess. Ako hosting koristi Nginx, administrator treba da postavi ekvivalentna zaglavlja i privatni document root. Nemojte računati na .htaccess kao jedinu zaštitu baze: baza fizički mora biti van web root-a.

Kod reverse proxy HTTPS konfiguracije proslediti verifikovan HTTPS status na nivou web servera/PHP. Aplikacija ne veruje proizvoljnom X-Forwarded-Proto zaglavlju.

## Provera instalacije

- Neprijavljen poziv api.php?action=state mora biti odbijen.
- Otvoriti jedan izmišljeni karton na dva uređaja. Izmena stare verzije treba da traži osvežavanje, a ne da prepiše noviju.
- Zakazati dva preklopljena termina: drugi mora biti odbijen.
- Sačuvati nacrt; potvrditi nalaz; proveriti da završeni nalaz ne može direktno da se izmeni.
- Napraviti šifrovanu kopiju i probno je vratiti u odvojenu praznu bazu.
- Testirati štampu i instalaciju na konkretnom Android uređaju.
- Tek zatim podesiti AI API i Google OAuth i testirati ih na izmišljenim podacima.

Nije izvršen upis na korisnikov hosting u okviru pripreme ovog paketa.

## Održavanje

Čuvati baze i encryption_key odvojeno; kopiju preuzimati redovno i proveravati vraćanje. Šifrovanje sadržaja ne štiti od napadača koji istovremeno preuzme server i ključ. Redovno ažurirati PHP i hosting, pratiti disk i obezbediti administratorski oporavak naloga.

Konfiguracija API modela ne bira model samostalno. Uneti naziv modela koji je stvarno dostupan na korisnikovom API nalogu. AI funkcije imaju odvojenu API potrošnju.
