# EET 2.0 — připnuté podklady rozhraní

Soubory jsou veřejné podklady Finanční správy a I.CA. Kód je čte jen z tohoto
adresáře, za běhu se nic nestahuje. Při nové verzi rozhraní je nahraďte a
aktualizujte otisky níže.

| Soubor | Původní URL | SHA-256 |
| --- | --- | --- |
| `EETXMLSchema.xsd` (verze 4.1) | https://eet.gov.cz/assets/cs/cmsmedia/pro-vyvojare/EETXMLSchema.xsd | `83597d08520cbfab7e51145df9a765d5ca22e2f9a38644a468d695657c7a032a` |
| `EETServiceSOAP.wsdl` (verze 4.1) | https://eet.gov.cz/assets/cs/cmsmedia/pro-vyvojare/EETServiceSOAP.wsdl | `df149c0bef4ac18c2a6e82116b6eb9bb17d29c055cb0821ac6d3885ec90faeb4` |
| `trust/ica-root-ca-rsa-05-2022.pem` | https://www.ica.cz/sites/default/files/download/2025/rca22_rsa.pem | `f72dc3232df2c9598f60c1bbc82f4bcc5acc80af73a60ed6eb3f6bd67ab473e2` |
| `trust/ica-public-ca-rsa-06-2022.pem` | https://www.ica.cz/sites/default/files/download/2025/pca22_rsa.pem | `db03fb10906d8cc357ef2d68f2f130d6a5381de3ca9a8cbf45216350ca695116` |

## Důvěra v podpis potvrzení

Potvrzovací zprávy produkce i playgroundu podepisuje správce daně certifikátem
vydaným `I.CA Public CA/RSA 06/2022` (dokumenty „Přístupové a provozní
informace“, kap. 3.3). Řetěz se ověřuje jen proti těmto dvěma certifikátům, ne
proti systémovému úložišti.

Otisky certifikátů (SHA-256 nad DER):

- `I.CA Root CA/RSA 05/2022`: `d279c01a12e8dd9a6230e459faa447ceb336998477338c2ee4135c96737418eb`
  (shodný otisk je i v úložišti důvěryhodných certifikátů Mozilla)
- `I.CA Public CA/RSA 06/2022`: `df5baf6d7e1a7d14e9911c5b8c676ec6ebcad9354a74f4ac7314e133e07a94de`
  (podepsaný kořenem výše, platný do 17. 6. 2032)
