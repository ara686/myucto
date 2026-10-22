# Předání neziskovek pro 20. 10. 2026

## Co je aktivní a co je pouze archivované

Větev `neziskovky`. Poslední ověřený aktivní zdrojový stav je `84cab860366c6b94b31e705d1ff139652f6e2fdc`. Aktuální master `35309d6de3dc239f1df061ce774a000e73c2743a` je již začleněný commitem `5c1d1cd76`.

Závěrečný commit přidává toto předání a archivní merge strategií `ours`. Rodiče uchovávají veškerý rozpracovaný zdrojový kód, ale jejich obsah není aktivně začleněný. Před přidáním tohoto dokumentu byla ověřena úplná shoda aktivního stromu s `84cab8603`. Archivní merge není dokončení J6 ani potvrzení správnosti draftů. Jednotlivé checkpointy se částečně překrývají; nelze je postupně všechny naslepo cherry-pickovat.

Pushovat pouze `neziskovky`. Pomocné `work/` a `fix/` větve jsou pouze lokální. Žádný force push, release, tag ani připojení či publikace do produkce.

## Dnešní dokončené části

- `307c1cca3`: nativní cizoměnové úhrady EUR.
- `ff252adf6`: návrh společné alokace podle výnosů.
- `e9a85cecd` a `503eb24f4`: nativní DPPO 504, členství a doporučení převodu účetního rámce včetně UI a manuálu.
- `0b48e4848`: omezené čtení a ověřování archivního JSON. Samo neotevírá úplné klonování archivů.
- `10a37ada7` a `9cf43e586`: dávkové předpisy, historie a historické členské/vlastnické role.
- `d544ee1e5`: dávkový regresní koordinátor a vlastnictví podprocesů.
- `727cb29ce`: nezávislé porovnání salda, oddělené benchmarkové fáze a neúspěšný exit při nesplnění limitů.
- `44602911f`: stránkovaná metadata grantů a darů.
- `b8b571c18`, `f7558d18e`, `84cab8603`: oddělovač před Registrem SVJ a sjednocení vstupů, datumů, popisků, ikon a zalamování formulářů SVJ a dalších neziskovek.

## Skutečný stav E0/E1/E2

E0 hotové 5/5. E1 J1-J9 a S1-S9 mají implementace, celková akceptace však není uzavřená. Největší otevřená integrační část je J6: autoritativní stránkovaný výpočet, neměnný zdroj a historický replay. Zbývá řízené měření výkonu, finanční regrese rámce 500 a závěrečná úplná sada.

Vybrané E2 obsahuje DPPO 504, fondy a rozpouštění, výnosový návrh alokace, členství a řízený převod 500 na 504. B2 projekce skutečného použití grantů a darů není aktivně integrovaná. Úplná anonymizace historických finančních archivů musí zůstat uzavřená, dokud není doložený celý transformovaný graf. Celé E1/E2 tedy nejsou hotové.

## Checkpointy pro obnovení práce

Všechny níže uvedené commity jsou rodiči závěrečného archivního merge a budou dostupné přes historii `neziskovky` i po odstranění pomocných větví. Počet souborů označuje uloženou změnu daného stromu, nikoli vzájemně nezávislé části.

| Původní lokální větev | Checkpoint | Souborů |
|---|---|---:|
| `work/nno-root-checkpoint-20261019` | `eb227facfa3250d8c7d530f0789793c92b4b306d` | 109 |
| `work/nno-dppo504-20261019` | `b33501517f19a8206276cce14791cdbe5700307b` | 27 |
| `work/nno-financial-clone-20261019` | `e3f9fbd18c707f5934fcdcb7f66a27729ce5c504` | 245 |
| `work/nno-funding-b2-20261019` | `3fd280c38e0acb8020a07d17a7007ce8d419b4ce` | 76 |
| `work/nno-funding-catalog-20261019` | `c8ce1774f1a603db2959e834ddc6d8ae1eaa05a2` | 8 |
| `work/nno-fx-payments-20261019` | `65dea294a7c7324f8813accc12c2c3b159cf6da0` | 15 |
| `work/nno-fx-workflow-20261019` | `55749d1d9cfcbc30f01f0cc4ce824fe66947be9f` | 20 |
| `work/nno-issuance-perf-20261019` | `43b50f2b4cbdce58644782ce9fcb14c79dc09742` | 15 |
| `work/nno-j6-frontend-20261019` | `fe522a3c72526ed59865bb2c36ecc4fcc4b4468e` | 11 |
| `work/nno-j6-stream-20261019` | `bf714a3083cd0373f1c98679127a7761213fc40f` | 137 |
| `work/nno-members-20261019` | `1837671f76429cbf16fb0aa82946be7002df0ba1` | 24 |
| `work/nno-property-benchmark-acceptance-20261019` | `5039e7a71807f07b3de78f08ccc3dbfe46de6dce` | 10 |
| `work/nno-read-catalog-20261019` | `c9eee03e4be9f0d87e178a686ca0dc9c36d7f0b5` | 50 |
| `work/nno-revenue-ui-20261019` | `b8b84d69cba55cc3552fa243b3904f84e752b020` | 32 |
| `work/regression-parity-harness-20261019` | `721f2e5c80512f5307efe55d4079a3abba5ecfeb` | 12 |

Kořenový checkpoint `eb227facfa3250d8c7d530f0789793c92b4b306d` je výchozí stav dosavadní root integrace J6. Nezačínat od začátku. Založit lokální pracovní větev nad aktuální `neziskovky`, po načtení tohoto předání lze obnovit draft příkazem `git cherry-pick --no-commit eb227facfa3250d8c7d530f0789793c92b4b306d`. Nejde o schválení k okamžitému commitu nebo pushi. Zkontrolovat celý výsledný diff a pokračovat v níže uvedené integraci. Ostatní checkpointy používat jako zdroj konkrétních delta souborů a porovnávat proti jejich prvnímu rodiči, nikoli přepisovat celý aktivní strom jejich starší základnou.

## Nejbližší kroky zítra

1. Nejprve fetch a integrace případného nového masteru. Přečíslovat jen naše dosud nenasazené migrace, nikdy migrace masteru. Aktivní stav končí 2069, root draft obsahuje migrace do 2076; J6 agent má další vlastní 2076-2078, které kolidují. Použít vždy nejbližší volná čísla, opravit odkazy a přegenerovat snapshot z čisté DB přes oficiální migrační nástroj.
2. Obnovit root J6 draft a porovnat s checkpointem `work/nno-j6-stream-20261019`. Root již obsahuje runtime union, HTTP v2.1, sdílený výpočet, UTC opravu, cache chybějících stránek, cheap read a funding compatibility. Doplnit withdrawal, bounded PDF/XLSX, source materializer a explicitní převod legacy sidecarů. Agentův finální legacy balík opravuje skutečný drift `activity input record_hash` při historickém replay; root draft tuto opravu ještě nemá. Zachovat stabilní assessment ID, raw SHA a CAS. Legacy konverze musí být explicitní operace vlastníka, nikoli zápis při GET.
3. Dokončit produkční HTTP lifecycle, period summary, vazbu history cursor na head, exporty, PDF/XLSX, OpenAPI, MCP, UI a manuál. Veřejné účetní čtení zůstává READ, pouze draft rodina je session-only. Nepoužít odvolaný HTTP kontrakt v1.
4. Funding B2 checkpoint obsahuje immutable J6 purpose/usage projekci a ochranu nadměrného historického JSON. Integrovat až nad sjednoceným J6 read owner. Samostatně ověřit regresi B1 metadat.
5. Financial clone checkpoint obsahuje bounded legacy page-transform hook a šest typovaných historických členských hash map s vazbou tenant/kind/stable ID. Historické fakty se nesmějí znovu zachytávat z živého stavu. `AllFinancialArchiveCloneCoordinator` zůstává fail-closed, dokud nejsou produkčně propojené a doložené všechny části capture/rebase grafu. Historické `axis.code` zachovat byteově, anonymizuje se název.
6. Znovu posoudit draft závislosti `pdo_sqlite`: používá se pro dočasné indexy velkých archivů. Změna Composeru, obou Dockerfiles a diagnostiky je jen v root checkpointu, není aktivní součástí posledního ověřeného stromu. Nejde o změnu provozní MariaDB.
7. Postupovat po sekcích: cílené testy měněných oblastí, vlastní review a nativní Codex review před každým hotovým commitem. Kompletní sadu až po celkové integraci; účetní/daňové změny musí projít auditní bránou. Sdílený daňový fix držet odděleně od feature.

## Co je a není ověřené

- Dokončené UI: navigace 32 testů, SVJ/J6 frontend 190 testů, další neziskovkové formuláře 211 testů; tehdejší buildy a nativní review prošly. Následná živá vizuální kontrola nebyla dokončená kvůli odpojenému rozšíření Edge. Zítra ověřit vzhled v Edge; nepřepínat na Chrome.
- Root J6 draft: cílený HTTP tok 4 testy / 125 asercí a po opravě kompatibility společná cílená sada 50 / 263 prošly. Předchozích 160 testů mělo jednu chybu a dvě selhání; celá tato sada po všech deltách nebyla zeleně zopakovaná.
- Poslední široký AuditCore: 18138 testů / 189533 asercí, jedna chyba a sedm selhání. Jednotlivé produktové nálezy byly cíleně opravené, finální široký zelený běh chybí.
- Katalogy 225 / 1911; izolovaný opakovaný NodeBridge test 1 / 30 prošel. Výkonové změny 160 / 1005 prošly. Benchmarkový výběr měl dvě chyby na úmyslně poškozených syntetických dávkách; na čisté kopii prošly oba dotčené testy 2 / 33 a další cílený výběr 24 / 972.
- Kontrolovaný benchmark 400 jednotek, 120 měsíců a 50 SVJ není uzavřený. Příprava 48000 měsíčních předpisů pod souběžnou zátěží není akceptace. Převzatá desetiletá historie má 4000 ročních rollup pohledávek; tyto dva scénáře nesměšovat.
- Regresní baseline zůstává commit `499eb4dd1fbde6e981c99b10cb10c511fc4fc94f` a lokální ref `refs/archive/myucto-regression-baseline-20261017`. Plné otisky obnovených dvojic se před migracemi shodovaly včetně system-versioned historie. Dávkový capture byl při ukončení práce zastavený; částečné výsledky nejsou důkaz finanční parity. Zítra vytvořit nové izolované kopie a zopakovat celý protokol. Shodné chyby ani nulové numerické pokrytí nejsou úspěšná parita.
- Závěrečný build aktivního frontendu a nativní Codex review předání prošly. Při archivaci prošla syntaxe 85 PHP souborů root draftu a 571 PHP souborů ostatních checkpointů. To není funkční akceptace checkpointů. Původní drafty byly zachované včetně případných whitespace nálezů.

## Ukončení a úklid

Vlastní capture procesy a podprocesy byly ukončené. Přesné doklady vlastnictví databází byly zachované před odstraněním worktrees. Odstraněno 29 doloženě vlastních lokálních databází, další 4 přesné kandidátní názvy již neexistovaly; všech 33 názvů bylo po operaci ověřeno jako nepřítomných. Pracovní `myucto`, cizí `myucto_test` a další nedoložené databáze se nemazaly. Dřívější incident s nechtěnými migracemi do cizí testovací DB se nesmí řešit dalším zásahem do ní.

Soukromé původní dumpy a finanční důkazy zůstávají mimo Git. Do repa nepatří jejich obsah, konfigurace ani přístupové údaje. Zítra založit nové izolované testovací DB s přesným creation receiptem a před testem ověřit skutečnou DB i classpath; nepoužívat automaticky pracovní konfiguraci.

Všech zbývajících 14 vlastních dočasných worktrees bylo po ověření čistoty a uložení zdrojů odstraněno. Jejich lokální větve a root checkpoint větev se po závěrečném commitu odstraní s ověřením ancestry. Ve vzdáleném origin nebyly při závěrečném fetchi žádné pomocné `work/` nebo `fix/` větve. Dokončení fyzického úklidu potvrzuje závěrečná zpráva tohoto předání.
