<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use DOMDocument;
use DOMElement;

/**
 * Promítne vyrobené XML měsíčního hlášení zpět na atributy datového slovníku.
 *
 * Kontroly ČSSZ jsou formulované nad ID atributů (10023, 10286, …), ne nad
 * elementy. Promítnutí jde přes připnutý slovník verze podání (2026: 1.4.1.6,
 * 2027: 1.5.0.3, podle jmenného prostoru kořene), kde má každý atribut
 * `xsd_mapping` = tečkovou cestu uvnitř své části. Cesty jsou napříč slovníkem
 * unikátní (228 cest, žádná kolize), takže stačí najít nejbližší kořen části
 * a cestu k němu.
 *
 * Validuje se to, co se opravdu odesílá, ne mezistav v paměti. Kdyby projekce
 * četla normalizovaný dokument, chyba v serializéru by přes ni prošla.
 *
 * Fail-closed: list, jehož cesta ve slovníku není, je tvrdá chyba. Jinak by
 * překlep v názvu elementu jen tiše vypnul kontroly, které ten atribut hlídají.
 */
final class JmhzAttributeProjection
{
    public const PART_SUBMISSION = 'submission';
    public const PART_SUMMARY = 'summary';
    public const PART_PVPOJ = 'pvpoj';
    public const PART_FORM = 'form';

    /**
     * Dva technické elementy kořene, které nenesou vykazovaný údaj, a datový
     * slovník je proto nezná: `VENDOR` identifikuje odesílající software,
     * `SENDER` nese e-mail pro notifikaci, příznak protokolu do datové schránky
     * a verzi protokolu. Oba jsou v XSD čistě atributové, bez textového obsahu.
     *
     * Seznam je vědomě krátký a jmenný. Přeskakovat každý neznámý element by
     * z fail-closed promítnutí udělalo fail-open: překlep v názvu by kontrolu
     * nad tím atributem tiše vypnul.
     */
    private const IGNORED_ROOT_LEAVES = ['VENDOR', 'SENDER'];

    private JmhzAttributeScope $submission;

    private JmhzAttributeScope $summary;

    private JmhzAttributeScope $pvpoj;

    /** @var list<JmhzAttributeScope> */
    private array $forms = [];

    /** @var array<int, int> */
    private array $formOrdinals = [];

    /** @var array<int, JmhzAttributeScope> */
    private array $formsByOrdinal = [];

    /** Součást individualizované části (`formularOsoby`, ve verzi 2027 `formularZamestnani`). */
    private readonly string $formHolder;

    /** Obal součástí (`formulareOsob`, ve verzi 2027 `formulareZamestnani`). */
    private readonly string $formContainer;

    private readonly string $formNamespace;

    private readonly string $dictionaryVersion;

    /**
     * @param array<string, string> $pathToAttribute
     */
    private function __construct(
        private readonly array $pathToAttribute,
        JmhzSpecVersion $version = JmhzSpecVersion::V2026,
    ) {
        $this->submission = new JmhzAttributeScope(self::PART_SUBMISSION);
        $this->summary = new JmhzAttributeScope(self::PART_SUMMARY);
        $this->pvpoj = new JmhzAttributeScope(self::PART_PVPOJ);
        [$this->formHolder, $this->formContainer, $this->formNamespace, $this->dictionaryVersion] = match ($version) {
            JmhzSpecVersion::V2026 => ['formularOsoby', 'formulareOsob', JmhzSchemaCatalog::NS_FORM, '1.4.1.6'],
            JmhzSpecVersion::V2027 => [
                'formularZamestnani',
                'formulareZamestnani',
                JmhzSchemaCatalog::NS_FORM_2027,
                '1.5.0.3',
            ],
        };
    }

    /**
     * Verze se pozná podle jmenného prostoru kořene ({@see JmhzSpecVersion::fromDocumentNamespace()}),
     * aby se XML 2027 nikdy nepromítlo přes slovník 2026 a naopak. Neznámý
     * jmenný prostor zůstává u slovníku 2026 jako dosud.
     */
    public static function fromXml(string $xml, ?string $resourceRoot = null): self
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || !$dom->documentElement instanceof DOMElement) {
            throw new JmhzXmlException(
                'jmhz_projection_xml_unreadable',
                'XML měsíčního hlášení nelze načíst pro promítnutí na atributy.',
            );
        }
        $version = JmhzSpecVersion::fromDocumentNamespace(
            (string) $dom->documentElement->namespaceURI,
        ) ?? JmhzSpecVersion::V2026;
        $projection = new self(self::dictionaryPaths($resourceRoot, $version), $version);
        $projection->walk($dom->documentElement, $dom->documentElement, '', []);
        $projection->sortForms();

        return $projection;
    }

    public function submission(): JmhzAttributeScope
    {
        return $this->submission;
    }

    public function summary(): JmhzAttributeScope
    {
        return $this->summary;
    }

    public function pvpoj(): JmhzAttributeScope
    {
        return $this->pvpoj;
    }

    /** @return list<JmhzAttributeScope> */
    public function forms(): array
    {
        return $this->forms;
    }

    public function has(string $attributeId): bool
    {
        if ($this->submission->has($attributeId)
            || $this->summary->has($attributeId)
            || $this->pvpoj->has($attributeId)
        ) {
            return true;
        }
        foreach ($this->forms as $form) {
            if ($form->has($attributeId)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function presentAttributeIds(): array
    {
        $ids = [];
        foreach ([$this->submission, $this->summary, $this->pvpoj] as $scope) {
            foreach ($scope->attributeIds() as $attributeId) {
                $ids[$attributeId] = true;
            }
        }
        foreach ($this->forms as $scope) {
            foreach ($scope->attributeIds() as $attributeId) {
                $ids[$attributeId] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * Rekurzivní průchod. `$root` je nejbližší kořen části, `$prefix` cesta
     * k němu a `$counters` pořadí opakovaných sourozenců na dané úrovni.
     *
     * @param array<string, int> $counters
     */
    private function walk(
        DOMElement $node,
        DOMElement $root,
        string $prefix,
        array $counters,
    ): void {
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }
        if ($children === []) {
            $this->record($node, $root, $prefix, $counters);

            return;
        }
        $seen = [];
        foreach ($children as $child) {
            $name = $child->localName ?? '';
            $seen[$name] = ($seen[$name] ?? 0) + 1;
            if ($name === $this->formHolder) {
                $this->formOrdinals[spl_object_id($child)] = $seen[$name] - 1;
            }
            [$childRoot, $childPrefix] = $this->descend($child, $root, $prefix, $name);
            $childCounters = $counters;
            $childCounters[$childPrefix] = $seen[$name];
            $this->walk($child, $childRoot, $childPrefix, $childCounters);
        }
    }

    /**
     * Určí, jestli dítě zakládá novou část podání. Nejbližší kořen vyhrává —
     * `hlavicka` uvnitř `formularOsoby` patří součásti, `hlavicka` pod kořenem
     * dokumentu metadatové hlavičce.
     *
     * @return array{0:DOMElement,1:string}
     */
    private function descend(
        DOMElement $child,
        DOMElement $root,
        string $prefix,
        string $name,
    ): array {
        if (in_array($name, ['souhrn', 'PVPOJ', $this->formHolder], true)) {
            return [$child, ''];
        }
        // Součást individualizované části je `xs:choice` z osmi typů formuláře.
        // Kořenem cest slovníku je vždy ten zvolený typ, ne jeho jméno — proto
        // se rozpoznává podle umístění a jmenného prostoru, ne podle výčtu.
        // Ve verzi 2027 je ve jmenném prostoru formuláře i hlavička součásti
        // (form.xsd 1.5.0.3, `formularHlavickaType`), proto se vylučuje jménem.
        if (($root->localName ?? '') === $this->formHolder
            && $child->parentNode === $root
            && $child->namespaceURI === $this->formNamespace
            && $name !== 'hlavicka'
        ) {
            $this->formScope($child)->noteBody($name);

            return [$child, ''];
        }
        if ($name === $this->formContainer) {
            return [$root, $prefix];
        }

        return [$root, $prefix === '' ? $name : $prefix . '.' . $name];
    }

    /** @param array<string, int> $counters */
    private function record(
        DOMElement $leaf,
        DOMElement $root,
        string $path,
        array $counters,
    ): void {
        if ($path === '' || in_array($path, self::IGNORED_ROOT_LEAVES, true)) {
            return;
        }
        // Prázdný kontejner (`<eldpSeznam/>`, `<zalohaNaDan/>`) vypadá jako list,
        // ale žádnou hodnotu nenese. Přeskakuje se jen tehdy, když je opravdu
        // prázdný A jeho cesta ve slovníku není — vypsaná hodnota na neznámé
        // cestě dál padá, jinak by překlep v názvu tiše vypnul kontroly.
        $attributeId = $this->pathToAttributeOrNull($path);
        if ($attributeId === null) {
            if (trim($leaf->textContent) === '') {
                return;
            }

            throw new JmhzXmlException(
                'jmhz_projection_path_unknown',
                "Element {$path} nemá ve slovníku JMHZ {$this->dictionaryVersion} protějšek.",
            );
        }
        $this->scopeFor($root)->add(new JmhzAttributeOccurrence(
            $attributeId,
            $leaf->textContent,
            self::groupKey(self::parentPath($path), $counters),
            $path,
        ));
    }

    /**
     * Slovník zapisuje cesty vůči části podání, ale ne vždy od jejího kořene:
     * `hlavicka.mesic` prefix nese, `formularePocetVBaliku` ne, přestože oba
     * elementy leží uvnitř téže hlavičky. Bere se proto nejdelší přípona cesty,
     * která je ve slovníku celou cestou. Cesty jsou napříč slovníkem unikátní,
     * takže delší shoda vždy vyhrává nad kratší a záměna dvou stejnojmenných
     * listů s různým rodičem (10371 vs 10482) nehrozí.
     */
    private function pathToAttributeOrNull(string $path): ?string
    {
        /*
         * Formulář odloženého příjmu nese ELDP po obdobích
         * (`pojisteni.eldpObdobi.obdobi.eldpSeznam…`), slovník ale atributy
         * ELDP vede jedinou cestou `pojisteni.eldpSeznam…`. Období je jen obal
         * se svým měsícem a rokem (10537/10538); atribut uvnitř je tentýž.
         */
        if (str_contains($path, 'eldpObdobi.obdobi.eldpSeznam.')) {
            $path = str_replace('eldpObdobi.obdobi.eldpSeznam.', 'eldpSeznam.', $path);
        }
        $segments = explode('.', $path);
        for ($offset = 0; $offset < count($segments); ++$offset) {
            $candidate = implode('.', array_slice($segments, $offset));
            $attributeId = $this->pathToAttribute[$candidate] ?? null;
            if ($attributeId !== null) {
                return $attributeId;
            }
        }

        return null;
    }

    private function scopeFor(DOMElement $root): JmhzAttributeScope
    {
        $name = $root->localName ?? '';
        if ($name === 'souhrn') {
            return $this->summary;
        }
        if ($name === 'PVPOJ') {
            return $this->pvpoj;
        }
        $parent = $root->parentNode;
        if ($name === $this->formHolder
            || ($parent instanceof DOMElement && ($parent->localName ?? '') === $this->formHolder)
        ) {
            return $this->formScope($root);
        }

        return $this->submission;
    }

    /**
     * Součást individualizované části je jeden `formularOsoby`. Jeho hlavička
     * i vlastní formulář musí spadnout do téhož rozsahu, jinak by kontrola
     * nad primárním PPV neviděla identifikaci osoby.
     */
    private function formScope(DOMElement $root): JmhzAttributeScope
    {
        $holder = $root;
        while ($holder instanceof DOMElement && ($holder->localName ?? '') !== $this->formHolder) {
            $parent = $holder->parentNode;
            $holder = $parent instanceof DOMElement ? $parent : null;
        }
        if (!$holder instanceof DOMElement) {
            throw new JmhzXmlException(
                'jmhz_projection_form_unbound',
                'Formulář osoby nemá v podání nadřazenou součást.',
            );
        }
        $holderId = spl_object_id($holder);
        $ordinal = $this->formOrdinals[$holderId] ?? null;
        if ($ordinal === null) {
            $ordinal = 0;
            for ($node = $holder->previousSibling; $node !== null; $node = $node->previousSibling) {
                if ($node instanceof DOMElement && ($node->localName ?? '') === $this->formHolder) {
                    ++$ordinal;
                }
            }
            $this->formOrdinals[$holderId] = $ordinal;
        }
        if (isset($this->formsByOrdinal[$ordinal])) {
            return $this->formsByOrdinal[$ordinal];
        }
        $scope = new JmhzAttributeScope(self::PART_FORM, $ordinal);
        $this->formsByOrdinal[$ordinal] = $scope;
        $this->forms[] = $scope;

        return $scope;
    }

    private function sortForms(): void
    {
        usort(
            $this->forms,
            static fn (JmhzAttributeScope $a, JmhzAttributeScope $b): int
                => $a->ordinal <=> $b->ordinal,
        );
    }

    private static function parentPath(string $path): string
    {
        $position = strrpos($path, '.');

        return $position === false ? '' : substr($path, 0, $position);
    }

    /** @param array<string, int> $counters */
    private static function groupKey(string $parentPath, array $counters): string
    {
        if ($parentPath === '') {
            return '';
        }
        $segments = explode('.', $parentPath);
        $key = '';
        $walked = '';
        foreach ($segments as $segment) {
            $walked = $walked === '' ? $segment : $walked . '.' . $segment;
            $ordinal = $counters[$walked] ?? 1;
            $key .= ($key === '' ? '' : '.') . $segment . '[' . $ordinal . ']';
        }

        return $key;
    }

    /**
     * Cesty z připnutého slovníku. Manifest se ověřuje stejným katalogem, jaký
     * používá zbytek modulu, takže projekce nemůže běžet nad jiným slovníkem,
     * než proti kterému se stavělo XML.
     *
     * @return array<string, string>
     */
    private static function dictionaryPaths(?string $resourceRoot, JmhzSpecVersion $version): array
    {
        $root = $resourceRoot ?? dirname(__DIR__, 5) . '/resources/payroll/jmhz';
        $manifest = $version === JmhzSpecVersion::V2026
            ? (new JmhzSpecPackageCatalog($root))->load(
                JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
            )
            : (new JmhzSpecPackageCatalog($root))->load(
                JmhzSpecPackageCatalog::PACKAGE_KEY_2027,
                JmhzSpecPackageCatalog::MANIFEST_SHA256_2027,
            );
        $paths = [];
        foreach ($manifest['payload']['dictionary_attributes'] ?? [] as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $mapping = $attribute['xsd_mapping'] ?? null;
            $attributeId = $attribute['attribute_id'] ?? null;
            if (!is_string($mapping) || !is_string($attributeId)) {
                continue;
            }
            $path = preg_replace('/\s*\(ID \d+\)$/D', '', $mapping);
            if (!is_string($path) || $path === '') {
                continue;
            }
            if (isset($paths[$path])) {
                throw new JmhzXmlException(
                    'jmhz_projection_path_ambiguous',
                    "Cesta {$path} patří ve slovníku JMHZ více atributům.",
                );
            }
            $paths[$path] = $attributeId;
        }

        return $paths;
    }
}
