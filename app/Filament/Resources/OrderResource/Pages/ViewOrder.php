<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\Pages;

use App\Domain\Fulfilment\FulfilmentService;
use App\Domain\Fulfilment\SupplierPaymentService;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Returns\RefundService;
use App\Filament\Resources\OrderResource;
use App\Models\Fulfilment;
use App\Models\Order;
use App\Models\Supplier;
use App\Support\Money\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;
use Throwable;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected string $view = 'filament.pages.view-order';

    protected function getHeaderActions(): array
    {
        return [
            $this->approveAction(),
            $this->rejectAction(),
            ActionGroup::make([
                $this->submitToSupplierAction(),
                $this->reconcileAction(),
                $this->paySupplierAction(),
            ])->label('Fulfilment')->icon('heroicon-o-truck')->button(),
            ActionGroup::make([
                $this->refundAction(),
                $this->cancelAction(),
            ])->label('Money')->icon('heroicon-o-banknotes')->button()->color('gray'),
        ];
    }

    /* ---------------------------------------------------------------- */

    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve order')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Order $record) => $record->approval_state === Order::APPROVAL_AWAITING
                || $record->approval_state === Order::APPROVAL_ON_HOLD)
            ->authorize(fn () => auth()->user()->can('orders.approve'))
            ->schema([
                Placeholder::make('what_this_does')
                    ->label('')
                    ->content(new HtmlString(
                        '<p class="text-sm">Approving marks this order ready to be sent to the supplier. '
                        .'<strong>It does not pay the supplier.</strong> Paying is a separate, separately '
                        .'authorised step, so approval alone never spends money.</p>'
                    )),

                Checkbox::make('also_submit')
                    ->label('Also submit the order to the supplier now')
                    ->helperText('Creates the supplier order. Still does not pay for it.')
                    ->default(false),

                // Money is never spent without a second, explicit consent.
                Checkbox::make('authorise_charge')
                    ->label('I also authorise paying the supplier for this order')
                    ->helperText('Leave this unticked unless you intend to spend money from the supplier balance now.')
                    ->default(false)
                    ->visible(fn () => auth()->user()->can('supplier_payments.authorise')),

                Textarea::make('note')->label('Note (optional)')->rows(2),
            ])
            ->action(function (Order $record, array $data): void {
                $states = app(OrderStateMachine::class);

                $states->transition(
                    $record,
                    'approval',
                    Order::APPROVAL_APPROVED,
                    'admin',
                    auth()->user(),
                    $data['note'] ?? 'Approved by administrator.',
                );

                $record->forceFill([
                    'approved_at' => now(),
                    'approved_by' => auth()->id(),
                    'approval_authorised_supplier_charge' => (bool) ($data['authorise_charge'] ?? false),
                    'hold_reason' => null,
                ])->save();

                Notification::make()->success()->title('Order approved')->send();

                if ($data['also_submit'] ?? false) {
                    $this->submitAllFulfilments($record, (bool) ($data['authorise_charge'] ?? false));
                }
            });
    }

    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Put on hold')
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->visible(fn (Order $record) => in_array($record->approval_state, [Order::APPROVAL_AWAITING, Order::APPROVAL_APPROVED], true))
            ->authorize(fn () => auth()->user()->can('orders.approve'))
            ->schema([Textarea::make('reason')->required()->rows(2)])
            ->action(function (Order $record, array $data): void {
                app(OrderStateMachine::class)->hold($record, $data['reason'], auth()->user());

                Notification::make()->warning()->title('Order placed on hold')->send();
            });
    }

    private function submitToSupplierAction(): Action
    {
        return Action::make('submit_supplier')
            ->label('Submit to supplier')
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn (Order $record) => $record->isFulfillable()
                && in_array($record->supplier_order_state, [Order::SUPPLIER_NOT_SUBMITTED, Order::SUPPLIER_REJECTED], true))
            ->authorize(fn () => auth()->user()->can('fulfilment.submit'))
            ->requiresConfirmation()
            ->modalHeading('Submit this order to the supplier')
            ->modalDescription('Stock, cost and carrier service are re-checked first. If something material has changed since the customer paid, the order is put on hold instead of being submitted. This does not pay the supplier.')
            ->action(fn (Order $record) => $this->submitAllFulfilments($record, false));
    }

    private function reconcileAction(): Action
    {
        return Action::make('reconcile')
            ->label('Reconcile with supplier')
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->visible(fn (Order $record) => $record->supplier_order_state === Order::SUPPLIER_RECONCILING)
            ->authorize(fn () => auth()->user()->can('fulfilment.submit'))
            ->requiresConfirmation()
            ->modalHeading('Reconcile before retrying')
            ->modalDescription('We did not get an answer from the supplier, so we do not know whether the order was created. This looks it up by our own reference and will not create a duplicate.')
            ->action(function (Order $record): void {
                $service = app(FulfilmentService::class);
                $messages = [];

                foreach ($record->fulfilments as $fulfilment) {
                    if ($fulfilment->needsReconciliation()) {
                        $messages[] = $service->reconcile($fulfilment)->message;
                    }
                }

                Notification::make()
                    ->title('Reconciliation finished')
                    ->body(implode(' ', $messages) ?: 'Nothing needed reconciling.')
                    ->success()
                    ->send();
            });
    }

    private function paySupplierAction(): Action
    {
        return Action::make('pay_supplier')
            ->label('Pay the supplier')
            ->icon('heroicon-o-credit-card')
            ->color('danger')
            ->visible(fn (Order $record) => $record->supplier_order_state === Order::SUPPLIER_CONFIRMED
                && $record->supplier_payment_state !== Order::SUPPLIER_PAY_PAID)
            ->authorize(fn () => auth()->user()->can('supplier_payments.authorise'))
            ->schema([
                Placeholder::make('warning')->label('')->content(new HtmlString(
                    '<p class="text-sm"><strong>This spends real money</strong> from your supplier account balance. '
                    .'It is separate from what the customer paid you — their payment went to your payment provider, '
                    .'not to the supplier.</p>'
                )),
                Checkbox::make('confirm')
                    ->label('I authorise this charge against the supplier balance')
                    ->accepted()
                    ->required(),
            ])
            ->action(function (Order $record): void {
                $service = app(SupplierPaymentService::class);
                $paid = 0;
                $errors = [];

                foreach ($record->fulfilments()->where('state', Fulfilment::STATE_CONFIRMED)->get() as $fulfilment) {
                    try {
                        $payment = $service->pay($fulfilment, auth()->user(), chargeAuthorised: true);
                        $payment->isSettled() ? $paid++ : $errors[] = $payment->last_error;
                    } catch (Throwable $e) {
                        $errors[] = $e->getMessage();
                    }
                }

                Notification::make()
                    ->title($errors === [] ? "Paid {$paid} fulfilment(s)" : 'Supplier payment needs attention')
                    ->body($errors === [] ? null : implode(' ', array_filter($errors)))
                    ->color($errors === [] ? 'success' : 'danger')
                    ->persistent($errors !== [])
                    ->send();
            });
    }

    private function refundAction(): Action
    {
        return Action::make('refund')
            ->label('Refund')
            ->icon('heroicon-o-receipt-refund')
            ->visible(fn (Order $record) => $record->isPaid() && $record->refundableMinor() > 0)
            ->authorize(fn () => auth()->user()->can('refunds.request'))
            ->schema([
                Placeholder::make('refundable')
                    ->label('Refundable balance')
                    ->content(fn (Order $record) => Money::ofMinor($record->refundableMinor(), $record->currency)->format()),

                TextInput::make('amount')
                    ->label('Amount to refund')
                    ->numeric()->prefix('$')->step(0.01)->required()
                    ->default(fn (Order $record) => number_format($record->refundableMinor() / 100, 2, '.', '')),

                Textarea::make('reason')->required()->rows(2),

                Checkbox::make('process_now')
                    ->label('Send this to the payment provider now')
                    ->helperText('Leave unticked to record the request only. A request is not a refund until the provider confirms it.')
                    ->default(false)
                    ->visible(fn () => auth()->user()->can('refunds.approve')),
            ])
            ->action(function (Order $record, array $data): void {
                $service = app(RefundService::class);
                $amount = Money::ofDecimalString((string) $data['amount'], $record->currency);

                try {
                    $refund = $service->request($record, $amount, $data['reason'], auth()->user());
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Could not record the refund')->body($e->getMessage())->send();

                    return;
                }

                if ($data['process_now'] ?? false) {
                    $refund = $service->process($refund, auth()->user());

                    Notification::make()
                        ->title($refund->isSettled() ? 'Refund completed' : 'Refund submitted, not yet settled')
                        ->body($refund->last_error)
                        ->color($refund->isSettled() ? 'success' : 'warning')
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Refund requested')
                    ->body('Recorded as a request. No money has moved yet.')
                    ->send();
            });
    }

    private function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel order')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Order $record) => ! $record->isCancelled())
            ->authorize(fn () => auth()->user()->can('orders.cancel'))
            ->schema([
                Placeholder::make('outlook')
                    ->label('Can this still be stopped?')
                    ->content(fn (Order $record) => app(RefundService::class)->cancellationOutlook($record)['message']),
                Textarea::make('reason')->required()->rows(2),
            ])
            ->action(function (Order $record, array $data): void {
                $outcome = app(RefundService::class)->cancel($record, $data['reason'], auth()->user());

                Notification::make()
                    ->title($outcome->cancelled ? 'Order cancelled' : 'Cancellation cannot be guaranteed')
                    ->body($outcome->message)
                    ->color($outcome->cancelled ? 'success' : 'warning')
                    ->persistent(! $outcome->cancelled)
                    ->send();
            });
    }

    private function submitAllFulfilments(Order $record, bool $chargeAuthorised): void
    {
        $service = app(FulfilmentService::class);
        $supplier = Supplier::where('code', 'cjdropshipping')->first();

        if ($supplier === null) {
            Notification::make()->danger()->title('No supplier is configured')->send();

            return;
        }

        $fulfilments = $record->fulfilments()->count() > 0
            ? $record->fulfilments()->get()->all()
            : $service->plan($record, $supplier);

        $summary = [];
        $problem = false;

        foreach ($fulfilments as $fulfilment) {
            $outcome = $service->submit($fulfilment, auth()->user());
            $summary[] = $fulfilment->internal_reference.': '.$outcome->message;
            $problem = $problem || $outcome->requiresAttention();
        }

        Notification::make()
            ->title($problem ? 'Submission needs attention' : 'Submitted to supplier')
            ->body(implode(' ', $summary) ?: 'Nothing to submit.')
            ->color($problem ? 'danger' : 'success')
            ->persistent($problem)
            ->send();
    }
}
