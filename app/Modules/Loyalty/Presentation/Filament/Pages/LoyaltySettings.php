<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Filament\Pages;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * "Puan Ayarları" — the five numbers the whole programme runs on (ADR-082).
 *
 * **SETTINGS, NOT CONSTANTS, BECAUSE THEY CHANGE WITHOUT A RELEASE.** A double-points
 * weekend is a marketing decision; making it a deploy would mean it never happens.
 * Every write goes through `settings()`, which is already audited, so "who doubled
 * the rate on the 14th" is answerable.
 *
 * **A CHANGE IS NEVER RETROACTIVE.** Rates are read at event time and the rate that
 * produced a row is written into its `meta`, so lowering the rate tomorrow does not
 * quietly reduce what somebody earned today. Nothing here rewrites the ledger —
 * nothing can.
 *
 * **TWO KINDS OF NUMBER ON ONE SCREEN.** The earn fields are integer COUNTS: a
 * point is a thing a customer holds and half of one does not exist. The redeem
 * value is a DECIMAL rate in lira — money-adjacent, so ADR-005 applies and a float
 * would make what a point is worth depend on binary rounding.
 */
final class LoyaltySettings extends Page implements HasForms
{
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public array $data = [];

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?int $navigationSort = 60;

    protected static string $view = 'filament.loyalty.pages.settings';

    public static function canAccess(): bool
    {
        /*
        | **ADMIN OR FINANCE, AND THE PERMISSION DECIDES** rather than a role name
        | (non-negotiable #5). Finance is here because these five numbers are a
        | liability the business carries: every point granted is money it has
        | promised to accept later.
        */
        return current_actor()?->can('loyalty.settings.manage') ?? false;
    }

    public static function getNavigationGroup(): string
    {
        return __('nav.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('loyalty.settings.title');
    }

    public function getTitle(): string
    {
        return __('loyalty.settings.title');
    }

    public function getSubheading(): string
    {
        return __('loyalty.settings.subheading');
    }

    public function mount(): void
    {
        $this->getForm('form')?->fill([
            'enabled' => (bool) settings('loyalty.enabled', true),
            'signup' => (int) settings('loyalty.earn.signup', 100),
            'review' => (int) settings('loyalty.earn.review', 50),
            'purchase_rate' => (int) settings('loyalty.earn.purchase_rate', 1),
            'redeem_value' => (string) settings('loyalty.redeem.value', '0.05'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make(__('loyalty.settings.earning'))
                    ->description(__('loyalty.settings.earning_hint'))
                    ->schema([
                        Toggle::make('enabled')
                            ->label(__('loyalty.settings.enabled'))
                            ->helperText(__('loyalty.settings.enabled_hint')),

                        /*
                        | INTEGERS, MINIMUM ZERO. A negative earn rate would be a
                        | ledger row that takes points away and calls itself a
                        | reward; zero is the honest way to switch one earn off
                        | while leaving the others running.
                        */
                        TextInput::make('signup')
                            ->label(__('loyalty.settings.signup'))
                            ->numeric()->integer()->minValue(0)->required(),

                        TextInput::make('review')
                            ->label(__('loyalty.settings.review'))
                            ->numeric()->integer()->minValue(0)->required(),

                        TextInput::make('purchase_rate')
                            ->label(__('loyalty.settings.purchase_rate'))
                            ->helperText(__('loyalty.settings.purchase_rate_hint'))
                            ->numeric()->integer()->minValue(0)->required(),
                    ]),

                Section::make(__('loyalty.settings.value'))
                    ->description(__('loyalty.settings.value_hint'))
                    ->schema([
                        TextInput::make('redeem_value')
                            ->label(__('loyalty.settings.redeem_value'))
                            ->prefix('₺')
                            // A RATE, SO A DECIMAL. The step is a hundredth of a
                            // lira because that is the smallest money this
                            // platform has.
                            ->numeric()->minValue(0)->step('0.01')->required(),
                    ]),
            ]);
    }

    public function save(): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->getForm('form')?->getState() ?? [];

        settings()->set('loyalty.enabled', (bool) $data['enabled']);
        settings()->set('loyalty.earn.signup', max(0, (int) $data['signup']));
        settings()->set('loyalty.earn.review', max(0, (int) $data['review']));
        settings()->set('loyalty.earn.purchase_rate', max(0, (int) $data['purchase_rate']));
        settings()->set('loyalty.redeem.value', number_format(max(0, (float) $data['redeem_value']), 2, '.', ''));

        Notification::make()->title(__('loyalty.settings.saved'))->success()->send();
    }
}
