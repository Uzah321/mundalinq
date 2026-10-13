<?php

namespace App\Services\Settlement;

use App\Enums\NotificationCategory;
use App\Enums\PayoutMethod;
use App\Exceptions\BusinessRuleException;
use App\Models\Farm;
use App\Models\FarmPayoutAccount;
use App\Models\Payout;
use App\Models\User;
use App\Notifications\MarketplaceAlert;
use App\Services\AuditLogger;
use App\Support\Market;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Where farms are paid, and the payouts administrators record after sending the money. Changing
 * the payout account alerts every owner and pauses payouts for a while: the usual way to steal
 * a seller's earnings is to quietly change where they go.
 */
class PayoutService
{
    public function __construct(private SettlementService $settlement, private AuditLogger $audit) {}

    public function saveAccount(Farm $farm, User $owner, string $provider, string $accountName, string $accountNumber): FarmPayoutAccount
    {
        [$account, $previousLastFour, $changed] = DB::transaction(function () use ($farm, $owner, $provider, $accountName, $accountNumber) {
            $account = FarmPayoutAccount::where('farm_id', $farm->id)->lockForUpdate()->first() ?? new FarmPayoutAccount;
            $previousLastFour = $account->exists ? $account->lastFour() : null;

            $account->forceFill([
                'farm_id' => $farm->id,
                'method' => PayoutMethod::MobileMoney,
                'provider' => $provider,
                'account_name' => $accountName,
                'account_number' => $accountNumber,
            ]);

            $changed = $account->isDirty();

            if ($changed) {
                $account->forceFill(['updated_by' => $owner->id])->save();

                $this->audit->record($owner, 'farm.payout_account_changed', $farm, [
                    'provider' => $provider,
                    'previous_last4' => $previousLastFour,
                    'last4' => $account->lastFour(),
                ]);
            }

            return [$account, $previousLastFour, $changed];
        });

        if ($changed) {
            $alert = new MarketplaceAlert(
                NotificationCategory::Account,
                __('Payout account changed'),
                __('Payouts for :farm will now go to :provider ending :last4:previous. If you did not make this change, contact :app support now.', [
                    'farm' => $farm->name,
                    'provider' => $provider,
                    'last4' => $account->lastFour(),
                    'previous' => $previousLastFour ? ' '.__('(was ending :last4)', ['last4' => $previousLastFour]) : '',
                    'app' => config('app.name'),
                ]),
                ['farm_id' => $farm->id],
                sms: true,
            );

            foreach ($farm->owners()->get() as $farmOwner) {
                $farmOwner->notify($alert);
            }
        }

        return $account;
    }

    /**
     * Record money already sent to the farm's payout account. Never more than the available balance.
     */
    public function record(Farm $farm, User $admin, int $amountMinor, string $reference, ?string $note): Payout
    {
        $payout = DB::transaction(function () use ($farm, $admin, $amountMinor, $reference, $note) {
            // One payout at a time per farm, so two cannot both pass the balance check.
            $farm = Farm::withTrashed()->whereKey($farm->id)->lockForUpdate()->firstOrFail();
            $account = $farm->payoutAccount()->first()
                ?? throw new BusinessRuleException(__('This farm has not set up a payout account yet.'));

            if ($account->isOnHold()) {
                throw new BusinessRuleException(__('The payout account was changed recently. Payouts resume :time.', [
                    'time' => $account->updated_at->copy()->addHours(config('mundalinq.payouts.account_change_hold_hours'))->timezone(Market::timezone())->format('H:i, j M'),
                ]));
            }

            $available = $this->settlement->balances($farm)['available_minor'];

            if ($amountMinor <= 0 || $amountMinor > $available) {
                throw new BusinessRuleException(__('You can pay out at most :amount.', ['amount' => Money::display(max(0, $available), Market::currency())]), 422);
            }

            $payout = Payout::forceCreate([
                'farm_id' => $farm->id,
                'amount_minor' => $amountMinor,
                'currency' => Market::currency(),
                'method' => $account->method,
                'provider' => $account->provider,
                'account_name' => $account->account_name,
                'account_number_last4' => $account->lastFour(),
                'reference' => $reference,
                'note' => $note,
                'paid_by' => $admin->id,
                'paid_at' => now(),
            ]);

            $this->settlement->recordPayout($payout);
            $this->audit->record($admin, 'payout.recorded', $payout, ['farm_id' => $farm->id, 'amount_minor' => $amountMinor, 'reference' => $reference]);

            return $payout;
        });

        $alert = new MarketplaceAlert(
            NotificationCategory::Payouts,
            __('Payout sent'),
            __(':amount has been sent to your :provider account ending :last4 (reference :reference).', [
                'amount' => Money::display($payout->amount_minor, $payout->currency),
                'provider' => $payout->provider,
                'last4' => $payout->account_number_last4,
                'reference' => $payout->reference,
            ]),
            ['farm_id' => $payout->farm_id, 'payout_id' => $payout->id],
            sms: true,
        );

        foreach ($farm->owners()->get() as $owner) {
            $owner->notify($alert);
        }

        return $payout;
    }
}
