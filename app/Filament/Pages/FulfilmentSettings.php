<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Settings\SettingsRepository;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Automation and fulfilment controls.
 *
 * Every automation switch here starts OFF and is described in terms of
 * what it will actually cause to happen, including which ones spend money.
 */
class FulfilmentSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Fulfilment & automation';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.simple-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('system.manage'), 403);

        $s = app(SettingsRepository::class);

        $this->form->fill([
            'guest_enabled' => $s->boolean('checkout.guest_enabled', true),
            'auto_approve' => $s->boolean('fulfilment.auto_approve'),
            'auto_submit' => $s->boolean('fulfilment.auto_submit'),
            'auto_pay_supplier' => $s->boolean('fulfilment.auto_pay_supplier'),
            'auto_approve_daily_limit' => $s->integer('fulfilment.auto_approve_daily_limit'),
            'auto_approve_order_value_limit' => $s->integer('fulfilment.auto_approve_order_value_limit_minor') / 100,
            'cost_increase_tolerance_bp' => $s->integer('fulfilment.cost_increase_tolerance_bp', 1000),
            'stock_buffer' => $s->integer('inventory.stock_buffer', 1),
            'packaging_default' => $s->string('packaging.default_choice', 'standard'),
            'packaging_shortage_policy' => $s->string('packaging.shortage_policy', 'fallback_standard'),
            'handling_min_days' => $s->integer('shipping.handling_min_days', 1),
            'handling_max_days' => $s->integer('shipping.handling_max_days', 3),
            'pre_destination_message' => $s->string('shipping.pre_destination_message'),
            'estimate_unavailable_message' => $s->string('shipping.estimate_unavailable_message'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([

            Section::make('Checkout')->schema([
                Toggle::make('guest_enabled')
                    ->label('Allow guest checkout')
                    ->helperText('When off, shoppers must sign in or register. Guest checkout still collects the details needed to fulfil and contact.'),
            ]),

            Section::make('Automation')
                ->description('All of these start off. Turn them on one at a time, and only once you trust the preceding step.')
                ->schema([
                    Placeholder::make('money_warning')->label('')->content(new HtmlString(
                        '<div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">'
                        .'<p><strong>Approving is not paying.</strong> Approval lets an order be sent to the supplier. '
                        .'Paying the supplier spends money from your supplier balance and is a separate switch. '
                        .'Customer payments go to your payment provider — they do not flow to the supplier by themselves.</p></div>'
                    ))->columnSpanFull(),

                    Toggle::make('auto_approve')
                        ->label('Approve paid orders automatically')
                        ->helperText('Skips the manual approval queue. Does not place or pay for anything on its own.')
                        ->live(),

                    Toggle::make('auto_submit')
                        ->label('Submit approved orders to the supplier automatically')
                        ->helperText('Creates the supplier order. Stock, cost and service are still re-checked first, and an order is held if something material changed.')
                        ->live(),

                    Toggle::make('auto_pay_supplier')
                        ->label('Pay the supplier automatically')
                        ->helperText('SPENDS MONEY without a human. Only turn this on once the two switches above have run cleanly for a while.')
                        ->live(),

                    TextInput::make('auto_approve_daily_limit')->numeric()->minValue(0)
                        ->label('Daily automatic order limit')
                        ->helperText('0 means no automatic orders. Anything above the limit waits for a human.'),

                    TextInput::make('auto_approve_order_value_limit')->numeric()->prefix('$')->step(0.01)
                        ->label('Per-order value limit for automation')
                        ->helperText('Orders above this always wait for a human, whatever the switches say.'),

                    TextInput::make('cost_increase_tolerance_bp')->numeric()->minValue(0)->suffix('basis points')
                        ->label('Cost increase tolerance')
                        ->helperText('1000 = 10%. If supplier cost rises by more than this after the customer paid, the order is held instead of submitted.'),
                ])->columns(2),

            Section::make('Inventory')->schema([
                TextInput::make('stock_buffer')->numeric()->minValue(0)
                    ->label('Stock safety buffer')
                    ->helperText('Units held back per warehouse so a stale reading does not oversell. Applies only to warehouses that reported a number — unknown stock is still treated as zero available.'),
            ]),

            Section::make('Packaging')->schema([
                Select::make('packaging_default')->options([
                    'standard' => 'Standard supplier packaging',
                    'branded_box' => 'Branded box where available',
                ])->label('Default packaging for new orders'),

                Select::make('packaging_shortage_policy')->options([
                    'fallback_standard' => 'Ship in standard packaging instead',
                    'hold_order' => 'Hold the order until branded packaging is available',
                ])->label('If branded packaging runs out')
                    ->helperText('Whatever actually shipped is recorded on the fulfilment either way.'),
            ])->columns(2),

            Section::make('Delivery wording')
                ->description('Used where the carrier gives us nothing. Never used to override something the carrier did tell us.')
                ->schema([
                    TextInput::make('handling_min_days')->numeric()->label('Handling time, minimum (business days)'),
                    TextInput::make('handling_max_days')->numeric()->label('Handling time, maximum (business days)'),
                    TextInput::make('pre_destination_message')->columnSpanFull()
                        ->label('Shown before a shopper enters an address'),
                    TextInput::make('estimate_unavailable_message')->columnSpanFull()
                        ->label('Shown when the carrier publishes no estimate'),
                ])->columns(2),
        ]);
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('system.manage'), 403);

        $data = $this->form->getState();
        $s = app(SettingsRepository::class);

        $s->setMany(['checkout.guest_enabled' => (bool) $data['guest_enabled']], 'checkout');

        $s->setMany([
            'fulfilment.auto_approve' => (bool) $data['auto_approve'],
            'fulfilment.auto_submit' => (bool) $data['auto_submit'],
            'fulfilment.auto_pay_supplier' => (bool) $data['auto_pay_supplier'],
            'fulfilment.auto_approve_daily_limit' => (int) $data['auto_approve_daily_limit'],
            'fulfilment.auto_approve_order_value_limit_minor' => (int) round((float) $data['auto_approve_order_value_limit'] * 100),
            'fulfilment.cost_increase_tolerance_bp' => (int) $data['cost_increase_tolerance_bp'],
        ], 'fulfilment');

        $s->setMany(['inventory.stock_buffer' => (int) $data['stock_buffer']], 'inventory');

        $s->setMany([
            'packaging.default_choice' => $data['packaging_default'],
            'packaging.shortage_policy' => $data['packaging_shortage_policy'],
        ], 'packaging');

        $s->setMany([
            'shipping.handling_min_days' => (int) $data['handling_min_days'],
            'shipping.handling_max_days' => (int) $data['handling_max_days'],
            'shipping.pre_destination_message' => $data['pre_destination_message'],
            'shipping.estimate_unavailable_message' => $data['estimate_unavailable_message'],
        ], 'shipping');

        $warnings = [];

        if ($data['auto_pay_supplier'] && ! $data['auto_submit']) {
            $warnings[] = 'Automatic supplier payment is on but automatic submission is off, so nothing will be paid automatically.';
        }

        if ($data['auto_pay_supplier']) {
            $warnings[] = 'Automatic supplier payment now spends money without a human approving each order.';
        }

        Notification::make()
            ->title('Settings saved')
            ->body($warnings === [] ? null : implode(' ', $warnings))
            ->color($warnings === [] ? 'success' : 'warning')
            ->persistent($warnings !== [])
            ->send();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('system.manage') ?? false;
    }
}
