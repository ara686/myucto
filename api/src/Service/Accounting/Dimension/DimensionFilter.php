<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

/**
 * Filtr sestav na hodnotu dimenze včetně celé její větve (podřízené hodnoty).
 *
 * Filtruje ŘÁDKY deníku, ne celé zápisy — výsledovka po projektu má obsahovat jen
 * náklady a výnosy projektu, ne protistranu 321/311 téhož zápisu. Předvaha s filtrem
 * proto záměrně nemusí být vyvážená.
 *
 * U typu Středisko se počítají i řádky, které hodnotu dimenze nemají, ale nesou
 * textový kód navázaného střediska (`journal_entry_lines.cost_center` — mzdy, starší
 * ruční zápisy). Řádek s dimenzí téhož typu rozhoduje dimenzí, ne textem, aby se
 * jeden řádek nezapočítal pod dvě hodnoty.
 *
 * Poddotazy se vážou jen přes id řádku (primární klíč vazby); firmu omezuje vnější
 * dotaz. Podmínka na supplier_id navíc svádí optimalizátor k indexu přes celou firmu.
 */
final class DimensionFilter
{
    /**
     * @param list<int> $valueIds hodnota a její podřízené
     * @param list<string> $costCenterCodes kódy středisek navázaných na tyto hodnoty
     * @param string|null $label popis pro hlavičku sestavy („Středisko: 100 Výroba")
     */
    public function __construct(
        public readonly int $typeId,
        public readonly int $valueId,
        public readonly array $valueIds,
        public readonly array $costCenterCodes = [],
        public readonly ?string $label = null,
    ) {}

    /**
     * Zdroj řádků deníku pro sestavu filtrovanou na hodnotu: odvozená tabulka se
     * sloupci `journal_entry_lines` (id, entry_id, supplier_id, account_id, side,
     * amount, signed_amount, cost_center, line_no) pod aliasem `$alias`, kterou volající dosadí místo
     * `journal_entry_lines {$alias}`. Obsahuje
     *   • řádky s jedinou hodnotou z větve v plné výši,
     *   • u Střediska řádky bez dimenze typu s textovým kódem navázaného střediska,
     *   • řádky s ROZPADEM mezi víc hodnot typu jen v dílu připadajícím na hodnoty
     *     z větve ({@see DimensionSplitAllocation}) — řádek 60 % A / 40 % B se do
     *     sestavy za A započte šedesáti procenty, ne celý ani vůbec.
     *
     * Díky tomu sedí součet sestav přes všechny hodnoty a „bez hodnoty" na sestavu
     * firmy na haléř a řádek hodnoty ve výsledovce po dimenzi na výsledovku
     * filtrovanou na tutéž hodnotu.
     *
     * Odvozená tabulka s UNION ALL se materializuje celá a datum zápisu z vnějšího
     * dotazu se do ní nepropíše. Volající proto předá interval dat zápisu, který jeho
     * sestava čte (`$from` null = od počátku historie, `$to` včetně), a každá větev jde
     * po indexu (supplier_id, entry_date) zápisu místo celého deníku firmy.
     *
     * @return array{0:string,1:list<int|string>}
     */
    public function lineSource(int $supplierId, string $alias, ?string $from, string $to): array
    {
        $cols = 'fl.id, fl.entry_id, fl.supplier_id, fl.account_id, fl.side, %1$s AS amount, (CASE WHEN fl.is_red_storno = 1 THEN -(%1$s) ELSE %1$s END) AS signed_amount, fl.cost_center, fl.line_no';
        $entryJoin = ' JOIN journal_entries fe ON fe.supplier_id = fl.supplier_id AND fe.id = fl.entry_id';
        $entrySql = ' AND fe.supplier_id = ?' . ($from !== null ? ' AND fe.entry_date >= ?' : '') . ' AND fe.entry_date <= ?';
        $entryParams = $from !== null ? [$supplierId, $from, $to] : [$supplierId, $to];
        $marks = implode(',', array_fill(0, count($this->valueIds), '?'));
        $sql = 'SELECT ' . sprintf($cols, 'fl.amount') . "
                  FROM journal_entry_line_dimensions fd
                  JOIN journal_entry_lines fl ON fl.id = fd.line_id{$entryJoin}
                 WHERE fd.dimension_type_id = ? AND fd.dimension_value_id IN ({$marks}) AND fl.supplier_id = ?{$entrySql}";
        $params = [$this->typeId, ...$this->valueIds, $supplierId, ...$entryParams];
        if ($this->costCenterCodes !== []) {
            [$ccSql, $ccParams] = $this->costCenterCondition('fl');
            $sql .= ' UNION ALL SELECT ' . sprintf($cols, 'fl.amount') . "
                  FROM journal_entry_lines fl{$entryJoin}
                 WHERE fl.supplier_id = ?{$entrySql} AND {$ccSql}";
            array_push($params, $supplierId, ...$entryParams, ...$ccParams);
        }
        [$shareSql, $shareParams] = DimensionSplitAllocation::lineShareSql($this->typeId, $this->valueIds, $supplierId, [$from, $to]);
        $sql .= ' UNION ALL SELECT ' . sprintf($cols, 'fs.amount') . "
                  FROM ({$shareSql}) fs
                  JOIN journal_entry_lines fl ON fl.id = fs.line_id
                 WHERE fl.supplier_id = ?";
        array_push($params, ...$shareParams);
        $params[] = $supplierId;
        return ["({$sql}) {$alias}", $params];
    }

    /**
     * Samostatná podmínka (bez vedoucího AND) nad aliasem ZÁPISU deníku: zápis projde,
     * nese-li hodnotu (nebo podřízenou) aspoň jeden jeho řádek, ať jako jedinou hodnotu,
     * nebo v rozpadu. Stejná sémantika řádku jako {@see lineSource()}, jen povýšená na zápis, protože seznam deníku ukazuje celé zápisy.
     *
     * Hodnoty jdou přes nekorelovaný IN poddotaz: optimalizátor ho zmaterializuje
     * jednou z indexu idx_jeld_type_value a pro každý zápis firmy pak jen hledá v
     * dočasné tabulce, místo aby pro každý zápis procházel jeho řádky.
     *
     * @return array{0:string,1:list<int|string>}
     */
    public function entrySql(string $entryAlias): array
    {
        $marks = implode(',', array_fill(0, count($this->valueIds), '?'));
        $sql = "({$entryAlias}.id IN (SELECT jel_f.entry_id
                          FROM journal_entry_line_dimensions dim_f
                          JOIN journal_entry_lines jel_f ON jel_f.id = dim_f.line_id
                         WHERE dim_f.dimension_type_id = ? AND dim_f.dimension_value_id IN ({$marks}))
                  OR {$entryAlias}.id IN (SELECT jel_s.entry_id
                          FROM journal_entry_line_dimension_splits dim_s
                          JOIN journal_entry_lines jel_s ON jel_s.id = dim_s.line_id
                         WHERE dim_s.dimension_type_id = ? AND dim_s.dimension_value_id IN ({$marks}))";
        $params = [$this->typeId, ...$this->valueIds, $this->typeId, ...$this->valueIds];
        if ($this->costCenterCodes !== []) {
            [$lineSql, $lineParams] = $this->costCenterCondition('jel_c');
            $sql .= " OR EXISTS (SELECT 1 FROM journal_entry_lines jel_c
                                  WHERE jel_c.entry_id = {$entryAlias}.id AND {$lineSql})";
            array_push($params, ...$lineParams);
        }
        return [$sql . ')', $params];
    }

    /**
     * Řádek bez dimenze tohoto typu (ani jediné hodnoty, ani rozpadu), který nese
     * textový kód navázaného střediska.
     *
     * @return array{0:string,1:list<int|string>}
     */
    private function costCenterCondition(string $lineAlias): array
    {
        $ccMarks = implode(',', array_fill(0, count($this->costCenterCodes), '?'));
        return [
            "{$lineAlias}.cost_center IN ({$ccMarks})
              AND NOT EXISTS (SELECT 1 FROM journal_entry_line_dimensions dim_c
                   WHERE dim_c.line_id = {$lineAlias}.id
                     AND dim_c.dimension_type_id = ?)
              AND NOT EXISTS (SELECT 1 FROM journal_entry_line_dimension_splits dim_cs
                   WHERE dim_cs.line_id = {$lineAlias}.id
                     AND dim_cs.dimension_type_id = ?)",
            [...$this->costCenterCodes, $this->typeId, $this->typeId],
        ];
    }

    /** @return array{type_id:int, value_id:int, value_ids:list<int>, label:?string} */
    public function toArray(): array
    {
        return ['type_id' => $this->typeId, 'value_id' => $this->valueId, 'value_ids' => $this->valueIds, 'label' => $this->label];
    }
}
