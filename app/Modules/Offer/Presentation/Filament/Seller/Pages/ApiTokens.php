<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Filament\Seller\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * "API Anahtarları" — where a seller mints the token their system pushes with
 * (ADR-076).
 *
 * **THE PLAIN TOKEN IS SHOWN ONCE AND NEVER AGAIN**, because Sanctum stores only a
 * hash. That is not an inconvenience to design around — it is the property that
 * makes a leaked database useless for impersonating a merchant. The page says so
 * on screen rather than letting somebody discover it by closing the dialog.
 *
 * **NO NEW TABLE AND NO NEW PERMISSION** (spec §7). Sanctum's
 * `personal_access_tokens` already holds these, and the feed is gated by the offer
 * abilities the seller panel already checks — a token is a way to authenticate as
 * yourself, not a new right. A separate `offer.sync` ability, so bulk API access
 * could be revoked without removing panel access, is deliberately a later option.
 *
 * **REVOKING IS IMMEDIATE AND IS THE REASON TOKENS ARE NAMED.** A seller who
 * changes integrators needs to kill one key without breaking the others, and
 * "which of these three is the old ERP" is unanswerable if they are all called
 * "token".
 *
 * @see App\Modules\Offer\Presentation\Controllers\Api\Seller\OfferFeedController
 */
final class ApiTokens extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.offer.pages.api-tokens';

    public static function getNavigationGroup(): string
    {
        return __('nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('offer.tokens.title');
    }

    public function getTitle(): string
    {
        return __('offer.tokens.title');
    }

    public function getSubheading(): string
    {
        return __('offer.tokens.subheading');
    }

    /**
     * The seller's own tokens, newest first.
     *
     * SCOPED TO THE ACTOR BY CONSTRUCTION — `tokens()` is the relation on the
     * signed-in user, so there is no query here for a tenancy mistake to live in.
     *
     * @return Collection<int, PersonalAccessToken>
     */
    public function getTokens(): Collection
    {
        /** @var User $user */
        $user = Auth::user();

        /** @var Collection<int, PersonalAccessToken> $tokens */
        $tokens = $user->tokens()->latest('id')->get();

        return $tokens;
    }

    /**
     * Kill one key without touching the others.
     *
     * **SCOPED TO THE ACTOR'S OWN TOKENS**, by deleting through the relation
     * rather than by id alone: a posted id from another seller's account finds
     * nothing instead of revoking their integration.
     */
    public function revoke(int $tokenId): void
    {
        /** @var User $user */
        $user = Auth::user();

        $user->tokens()->whereKey($tokenId)->delete();

        Notification::make()
            ->title(__('offer.tokens.revoked'))
            ->success()
            ->send();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('offer.tokens.create'))
                ->icon('heroicon-o-plus')
                ->form([
                    TextInput::make('name')
                        ->label(__('offer.tokens.name'))
                        ->helperText(__('offer.tokens.name_hint'))
                        ->required()
                        ->maxLength(60),
                ])
                ->action(function (array $data): void {
                    /** @var User $user */
                    $user = Auth::user();

                    $token = $user->createToken((string) $data['name']);

                    /*
                    | PERSISTENT AND COPYABLE, because this is the only moment the
                    | plain text exists. A toast that fades would send the seller
                    | back to create a second token, and then a third.
                    */
                    Notification::make()
                        ->title(__('offer.tokens.created'))
                        ->body($token->plainTextToken)
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
