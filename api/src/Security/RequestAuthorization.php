<?php

declare(strict_types=1);

namespace MyInvoice\Security;

use Psr\Http\Message\ServerRequestInterface as Request;

final class RequestAuthorization
{
    private static ?PermissionCatalog $catalog = null;
    private static ?PermissionChecker $checker = null;

    /**
     * Je požadavek z přihlášené browserové relace?
     *
     * ⚠️ Chybějící atribut je „NE". `SessionLockMiddleware` ho u zamčené relace
     * na veřejných cestách odstraní, takže požadavek bez metody je fakticky
     * anonymní — a ten přes session-only bránu projít nesmí. Podmínka opsaná
     * jako `=== 'bearer'` tohle nepokrývala a anonymní požadavek pouštěla dál;
     * brány se proto píšou `!isSessionAuth()`, ne `isBearerAuth()`.
     */
    public static function isSessionAuth(Request $request): bool
    {
        return $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD) === 'session';
    }

    /**
     * Je požadavek ověřený API tokenem?
     *
     * Pro místa, která podle kanálu jen MĚNÍ CHOVÁNÍ (přeskočení CSRF, užší tělo
     * požadavku, logování), ne pro brány. Na bránu patří {@see isSessionAuth()}.
     */
    public static function isBearerAuth(Request $request): bool
    {
        return $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD) === 'bearer';
    }

    /**
     * Smí tenhle token vidět v Dokumentech evidenci mzdových PODÁNÍ?
     *
     * ⚠️ Jediná výjimka z pravidla, že mzdová evidence je session-only, a týká
     * se VÝHRADNĚ podání — doručenek, protokolů ČSSZ, odpovědí pojišťoven.
     * Zdravotní údaje, exekuce, insolvence, cizinecká povolení a výplatní pásky
     * zůstávají zavřené i s tímhle příznakem: jsou to zvláštní kategorie
     * osobních údajů a dlouhodobé tajemství v konfiguráku integrace se na ně
     * nehodí. Schopnost se zapíná výslovně při vytváření tokenu, které je samo
     * chráněné relací, heslem a MFA.
     */
    public static function tokenAllowsPayrollSubmissionDocs(Request $request): bool
    {
        $token = $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_API_TOKEN);

        return is_array($token) && (int) ($token['allow_payroll_submission_docs'] ?? 0) === 1;
    }

    /**
     * Název způsobu ověření pro VÝPIS, ne pro rozhodování.
     *
     * Používá ho `/api/auth/api-me`, které o sobě říká, čím je požadavek
     * ověřený. Na bránu patří {@see isSessionAuth()} — porovnávat tenhle
     * řetězec ručně je přesně to, čemu se tu odvyká.
     */
    public static function authMethod(Request $request, string $default = 'session'): string
    {
        $method = $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, $default);

        return is_string($method) && $method !== '' ? $method : $default;
    }

    public static function effectiveRole(Request $request): EffectiveRole
    {
        $role = $request->getAttribute('auth.effective_role');
        if ($role instanceof EffectiveRole) return $role;

        $user = (array) $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, []);
        $legacy = is_string($user['role'] ?? null) ? $user['role'] : '';
        if ($legacy === 'admin') {
            return new EffectiveRole(0, 'Superadmin', 'superadmin', true, [], 'superadmin');
        }
        if (!in_array($legacy, ['accountant', 'readonly', 'client'], true)) {
            return EffectiveRole::denied();
        }
        $catalog = self::$catalog ??= new PermissionCatalog();
        return new EffectiveRole(
            0,
            $legacy,
            $legacy === 'client' ? 'client' : 'staff',
            true,
            $catalog->legacyPreset($legacy),
            $legacy,
        );
    }

    public static function isSuperadmin(Request $request): bool
    {
        return self::effectiveRole($request)->isSuperadmin();
    }

    public static function isCompanyAdmin(Request $request): bool
    {
        return self::effectiveRole($request)->isCompanyAdmin();
    }

    public static function canCreateSupplier(Request $request): bool
    {
        return self::effectiveRole($request)->canCreateSupplier();
    }

    public static function isClientType(Request $request): bool
    {
        return self::effectiveRole($request)->isClientType();
    }

    public static function allowsClientDocumentPortal(Request $request, AccessLevel $minimum = AccessLevel::READ): bool
    {
        return (self::isClientType($request) || self::isSuperadmin($request))
            && self::allows($request, 'documents.submit', $minimum);
    }

    /**
     * Úprava vystavené faktury (?force=1) včetně jejího výkazu: správce firmy, nebo interní
     * role s právem `invoices.edit_issued`. Uzavřené účetní období dál přebíjí jen správce
     * ({@see \MyInvoice\Http\GuardsDocumentLock}) a skladová pojistka platí pro všechny.
     */
    public static function canEditIssuedInvoice(Request $request): bool
    {
        return self::isCompanyAdmin($request)
            || (!self::isClientType($request) && self::allows($request, 'invoices.edit_issued', AccessLevel::WRITE));
    }

    /**
     * Finanční úprava zaúčtované vystavené faktury přepíše (nebo stornuje a znovu založí)
     * zápis v deníku. Kromě práva upravit vystavenou fakturu k ní neadmin potřebuje i právo
     * zaúčtovat (`accounting.journal.post`), jinak by úpravou faktury účtoval bez něj.
     */
    public static function canRepostIssuedInvoice(Request $request): bool
    {
        return self::isCompanyAdmin($request)
            || (self::canEditIssuedInvoice($request)
                && self::allows($request, 'accounting.journal.post', AccessLevel::WRITE));
    }

    /**
     * Úzká oprava DPH zařazení přijaté faktury (nárok na odpočet, klasifikace hlavičky,
     * položek a rozpisu) i u přijatého nebo zaúčtovaného dokladu, bez storna: správce firmy,
     * nebo interní role s právem `purchase_invoices.vat_treatment` (výchozí účetní ho má).
     * Klient ho nedostane ani poškozeným řádkem matice.
     */
    public static function canChangePurchaseVatTreatment(Request $request): bool
    {
        return self::isCompanyAdmin($request)
            || (!self::isClientType($request) && self::allows($request, 'purchase_invoices.vat_treatment', AccessLevel::WRITE));
    }

    /**
     * Oprava DPH zařazení zaúčtované přijaté faktury přepíše její zápis v deníku. Neadmin
     * k ní kromě práva na opravu potřebuje i právo zaúčtovat, stejně jako u vystavené
     * faktury ({@see canRepostIssuedInvoice()}).
     */
    public static function canRepostPurchaseVatTreatment(Request $request): bool
    {
        return self::isCompanyAdmin($request)
            || (self::canChangePurchaseVatTreatment($request)
                && self::allows($request, 'accounting.journal.post', AccessLevel::WRITE));
    }

    /**
     * Částky, data a položky vystavené faktury v období, za které je podané přiznání k DPH,
     * kontrolní hlášení nebo OSS přiznání, mění jen správce firmy. Delegovaná role s právem
     * upravit vystavenou fakturu ho nepřebije: podané se opravuje opravným tvrzením.
     * Totéž platí pro změnu přijaté faktury, která se promítne do evidence DPH (vynucená
     * úprava, členění DPH položek, oprava DPH zařazení).
     */
    public static function canChangeFiledVatPeriod(Request $request): bool
    {
        return self::isCompanyAdmin($request);
    }

    /**
     * Akceptace nativního výpočtu DPPO 504 odblokuje finalizaci, XML a podání. Jde o
     * rozhodnutí správce firmy, nikoli delegované účetní role.
     */
    public static function canAcceptNonprofitDppoCalculation(Request $request): bool
    {
        return self::isCompanyAdmin($request);
    }

    /**
     * Správa automatického stavu tachometru (připojení účtu u zdroje, přiřazení aut, načtení):
     * patří k práci s knihou jízd, ne k administraci integrací.
     */
    public static function canManageOdometerSources(Request $request): bool
    {
        return self::allows($request, 'logbook.write', AccessLevel::WRITE);
    }

    public static function allows(
        Request $request,
        string $permission,
        AccessLevel $minimum = AccessLevel::READ,
    ): bool {
        self::$catalog ??= new PermissionCatalog();
        self::$checker ??= new PermissionChecker(self::$catalog);
        return self::$checker->allows(self::effectiveRole($request), $permission, $minimum);
    }
}
