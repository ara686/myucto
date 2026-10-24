<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

/**
 * Vstup politiky evidence. Klientské tvrzení „evidovat / neevidovat" sem záměrně
 * nevede: povinnost určuje server z okolností platby a profilu firmy.
 */
final class EetPolicyInput
{
    public const CHANNELS = ['cash', 'card_terminal', 'qr_in_person', 'bank_transfer', 'card_online', 'cash_on_delivery', 'other'];
    public const CONTACT_CONTEXTS = ['in_person', 'on_premises', 'in_vehicle', 'remote', 'unknown'];
    public const BUSINESS_PURPOSES = ['sale', 'order_deposit', 'credit_topup', 'credit_draw', 'security_deposit', 'other'];
    public const ORIGINS = ['invoice_payment', 'cash_document', 'quick_sale', 'payment_intent', 'correction', 'bank_reconciliation', 'api'];
    public const KINDS = ['payment', 'refund', 'intent'];

    /**
     * @param array<string,mixed> $settings řádek `eet_settings` firmy (EetSettingsService::get)
     */
    public function __construct(
        public readonly array $settings,
        public readonly \DateTimeImmutable $at,
        public readonly ?string $channel,
        public readonly string $contactContext,
        public readonly string $businessPurpose = 'sale',
        public readonly string $currency = 'CZK',
        public readonly string $amount = '0.00',
        public readonly string $origin = 'api',
        public readonly string $kind = 'payment',
        public readonly ?string $delegatingEic = null,
    ) {}
}
