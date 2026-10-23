# 120. Členové a členské příspěvky

V podvojném účetnictví podle vyhlášky 504 otevřete **Účetnictví → Členské příspěvky spolku**. Evidence spojuje člena spolku s kontaktem, právním titulem členství a účetně doloženými předpisy příspěvků.

## 120.1 Založení a historie člena

Vyberte kontakt a vyplňte jedinečné členské číslo. Založení člena samo nevytvoří pohledávku ani účetní zápis. V detailu přidejte první revizi členství s datem účinnosti, odkazem na stanovy nebo rozhodnutí a doložením členského vztahu bez vzájemného protiplnění.

Změny stavu se zapisují jako další revize. Původní historie zůstává zachovaná. Budoucí pozastavení nebo ukončení nemění dnešní stav ani starší předpisy. Detail odlišuje dnešní stav od budoucích změn.

## 120.2 Předpis příspěvku

Zvolte **Nový předpis příspěvku**. Vyberte otevřené účetní období, účetní datum, splatnost a období, za které příspěvek náleží. Vyplňte jednoznačný identifikátor titulu, částku, pohledávkový a výnosový účet z rozvrhu, odkaz na titul a doložení částky.

Výslovně vyberte činnost, druh činnosti a daňový režim z účetních dimenzí firmy. Hodnoty se nepředvyplňují podle výnosového účtu. V náhledu zkontrolujte také ověřené členění; bez jeho shody s vaší volbou nelze předpis potvrdit.

Účetní období musí mít potvrzený právní profil spolku s hlavní nepodnikatelskou činností. Zkontrolujte také revizi členství platnou k účetnímu datu. Samotné číslo účtu ani označení organizace neprokazuje právní titul.

Zvolte **Zobrazit náhled**, zkontrolujte člena, období, částku a účty a použijte **Potvrdit a zapsat**. Potvrzení vytvoří pohledávku a navazující účetní doklad v jednom kroku. Změna formuláře vyžaduje nový náhled. Zápis vyžaduje přihlášenou relaci a oprávnění k zaúčtování.

Členský předpis není automatickým rozhodnutím o osvobození příjmu. Daňový režim doložte v daňovém posouzení příslušného období.

## 120.3 Úhrady a kontrola zůstatku

Pohledávky se zobrazují po stránkách. Přehled rozlišuje předepsanou částku a prokázaný otevřený zůstatek. Úhrada je doložená teprve přes skutečné párování s nativním bankovním nebo pokladním dokladem.

U neprokázané nebo nejednoznačné úhrady se částka nevydává za potvrzený dluh. Otevřete související účetní doklady a opravte párování ve společné evidenci plateb.

Kontrola vybraného účetního období porovnává členské pohledávky, potvrzené operace a pohledávkové i výnosové řádky účetního deníku. Případný rozdíl vyžaduje kontrolu původu dokladů.

## 120.4 Storno a opakování požadavku

Storno zahajte z konkrétní pohledávky. Zvolte účetní datum v otevřeném období a doložte důvod. Náhled vychází z původní potvrzené operace. Po výslovném potvrzení vznikne navazující storno; původní pohledávka i její historie zůstávají dohledatelné.

Při nejistém výsledku odeslání obnovte přehled nebo opakujte stejný zmrazený požadavek. Stejný požadavek se stejným identifikátorem nevytvoří druhý předpis. Před založením nového požadavku ověřte, zda se původní operace již nezapsala.

## 120.5 Čtení přes veřejné API a MCP

Kurátorované katalogy členských rolí a pohledávek jsou dostupné s oprávněním ke čtení účetnictví také přes API token a MCP. Seznamy i detaily používají stejnou obálku; stránka obsahuje nejvýše 100 záznamů a pokračovací kurzor je vázaný na firmu a filtry. Výstup neobsahuje jména, členská čísla, právní podklady ani interní otisky a aktéry.

U členských rolí zadejte rozhodné datum `as_of` a případně okamžik tehdejší znalosti `known_at`. Pokud jej vynecháte, připne jej první stránka. Později zaznamenaná revize tak nezmění navazující stránky téhož čtení. U pohledávek se čte aktuální účetní stav a doložená nativní bankovní nebo pokladní párování; historický okamžik úhrad se nepřijímá. Neprokázané úhrady a otevřené částky zůstávají `null`. Katalog sám nepotvrzuje osvobození příjmu ani oprávnění k finanční operaci.

Historické role vyžadují průkazný okamžik zaznamenání. Pokud jej starší záznam nemá doložený, časový katalog vrátí chybu místo odhadování časové zóny nebo vynechání člena. U pohledávky může být čas zaznamenání `null` se stavem `unproven`; ověření její částky a účetních podkladů tím zůstává samostatné. Původní časové údaje se při aktualizaci ani anonymizované kopii nepřepočítávají odhadem.
