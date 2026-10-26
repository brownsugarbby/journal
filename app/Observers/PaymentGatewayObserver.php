<?php

namespace App\Observers;

use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Enums\PlGroup;
use App\Models\Category;
use App\Models\PaymentGateway;

class PaymentGatewayObserver
{
    /**
     * P&L fee lines sit where the sheet has them: after "Tagihan Mess" (150)
     * and before "Biaya ADM" (300).
     */
    private const FEE_CATEGORY_SORT_BASE = 160;

    public function created(PaymentGateway $gateway): void
    {
        $this->recordFees($gateway);

        // Next free slots after the existing PGA fee lines (not the gateway id,
        // which can be anything and would push lines past Biaya ADM).
        $sort = max(
            self::FEE_CATEGORY_SORT_BASE - 1,
            (int) Category::query()->whereNotNull('payment_gateway_id')->max('sort_order'),
        );

        foreach (['dp' => 'DP', 'wd' => 'WD'] as $direction => $label) {
            Category::query()->firstOrCreate(
                ['source_key' => $gateway->feeSourceKey($direction)],
                [
                    'code' => strtolower("pga_{$gateway->code}_{$direction}_fee"),
                    'name' => "Potongan {$label} PGA {$gateway->name}",
                    'pl_group' => PlGroup::BiayaTetap,
                    'type' => CategoryType::Expense,
                    'source' => CategorySource::Auto,
                    'payment_gateway_id' => $gateway->id,
                    'sort_order' => ++$sort,
                ],
            );
        }
    }

    public function updated(PaymentGateway $gateway): void
    {
        if ($gateway->wasChanged(PaymentGateway::FEE_FIELDS)) {
            $this->recordFees($gateway);
        }

        if ($gateway->wasChanged('name')) {
            foreach (['dp' => 'DP', 'wd' => 'WD'] as $direction => $label) {
                Category::query()
                    ->where('source_key', $gateway->feeSourceKey($direction))
                    ->first()
                    ?->update(['name' => "Potongan {$label} PGA {$gateway->name}"]);
            }
        }
    }

    private function recordFees(PaymentGateway $gateway): void
    {
        $gateway->feeHistories()->create([
            'effective_from' => now(),
            'created_by' => auth()->id(),
            ...$gateway->only(PaymentGateway::FEE_FIELDS),
        ]);
    }
}
