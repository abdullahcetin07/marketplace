<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Resources\OrganizationResource\RelationManagers;

use App\Modules\Organization\Application\Actions\ReviewDocumentAction;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentStatus;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentType;
use App\Modules\Organization\Domain\Models\OrganizationDocument;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The admin's document review queue for one company — the UI twin of
 * `POST /admin/organizations/{organization}/documents/{document}/review`.
 *
 * STRICTLY PRESENTATION. Approve / request-revision / reject are three labels on
 * ONE module action, `ReviewDocumentAction`, which owns the decision, the audit
 * reason and the `OrganizationDocumentReviewed` event. This class decides
 * nothing; it only names the outcome.
 *
 * WHY A STREAMED DOWNLOAD AND NOT A SIGNED URL. The seller surface links to
 * `temporaryUrl()`, which is fine on S3 and IMPOSSIBLE on the local driver —
 * `Local` has no `temporaryUrl()` and throws. The private disk is configurable
 * (`marketplace.media.private_disk`), so the admin surface must work on whatever
 * it is set to today. Streaming the bytes through this authenticated,
 * permission-gated request is the one form of access that holds on every driver
 * and never mints a URL that outlives the check.
 *
 * THE GATE IS `OrganizationDocumentPolicy::review` — the `organization.review_documents`
 * permission, the same one the admin API requires. It is applied to the whole
 * relation manager, not just the buttons: an admin who may not review documents
 * does not get to browse them either.
 *
 * @see App\Modules\Organization\Presentation\Controllers\Api\Admin\OrganizationController::reviewDocument()
 * @see App\Modules\Organization\Presentation\Requests\Admin\ReviewDocumentRequest
 */
final class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('organization.document.plural');
    }

    /**
     * The whole surface is gated on the review permission.
     *
     * The policy answers about a DOCUMENT, and the ability is a cross-org admin
     * power that does not depend on which document it is asked about — so an
     * unsaved stub bound to this company is a truthful subject to ask with, and
     * avoids querying for a row that may not exist yet.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return current_actor()?->can('review', new OrganizationDocument([
            'organization_id' => $ownerRecord->getKey(),
        ])) === true;
    }

    /**
     * Filament makes relation managers read-only on a ViewRecord page by
     * default. Reviewing a document IS the reason an admin opens the company,
     * so the default is turned off deliberately — there is no Edit page to move
     * this to, and there should not be one: the admin never edits a document,
     * only decides about it.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label(__('organization.document.type'))
                    ->formatStateUsing(fn (OrganizationDocumentType $state): string => __("enums.OrganizationDocumentType.{$state->value}")),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('organization.document.status'))
                    ->badge()
                    ->color(fn (OrganizationDocumentStatus $state): string => match ($state) {
                        OrganizationDocumentStatus::Approved => 'success',
                        OrganizationDocumentStatus::Pending, OrganizationDocumentStatus::NeedsRevision => 'warning',
                        OrganizationDocumentStatus::Rejected => 'danger',
                    })
                    ->formatStateUsing(fn (OrganizationDocumentStatus $state): string => __("enums.OrganizationDocumentStatus.{$state->value}")),

                Tables\Columns\TextColumn::make('review_notes')
                    ->label(__('organization.document.review_notes'))
                    ->wrap()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('organization.document.uploaded_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([])
            ->actions([
                $this->downloadAction(),
                $this->reviewAction('approve', OrganizationDocumentStatus::Approved, 'heroicon-o-check-circle', 'success'),
                $this->reviewAction('request_revision', OrganizationDocumentStatus::NeedsRevision, 'heroicon-o-arrow-uturn-left', 'warning', notesRequired: true),
                $this->reviewAction('reject', OrganizationDocumentStatus::Rejected, 'heroicon-o-x-circle', 'danger', notesRequired: true),
            ])
            // Evidence under review is never deleted, and an admin does not
            // edit what a seller submitted.
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading(__('organization.document.admin_empty.heading'))
            ->emptyStateDescription(__('organization.document.admin_empty.description'))
            ->defaultSort('created_at', 'desc');
    }

    /**
     * The file itself, streamed from whatever disk holds it.
     *
     * The response is built from the media row's OWN disk rather than the config
     * value, so a file written before the setting changed still downloads.
     */
    private function downloadAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('download')
            ->label(__('organization.document.action.download'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (OrganizationDocument $record): bool => $record->file() !== null
                && $this->canReview($record))
            ->action(function (OrganizationDocument $record): ?StreamedResponse {
                // Re-checked at call time, not only at render time: visibility
                // is a UI affordance, this is the access decision.
                abort_unless($this->canReview($record), 403);

                $media = $record->file();

                if ($media === null) {
                    return null;
                }

                return Storage::disk($media->disk)->download(
                    $media->getPathRelativeToRoot(),
                    $media->file_name,
                );
            });
    }

    /**
     * One review outcome as a row action. All three are the same module action
     * with a different decision; the notes ride the audit context as the reason
     * and are what the seller reads on their own row.
     */
    private function reviewAction(
        string $name,
        OrganizationDocumentStatus $decision,
        string $icon,
        string $color,
        bool $notesRequired = false,
    ): Tables\Actions\Action {
        return Tables\Actions\Action::make($name)
            ->label(__("organization.document.action.{$name}"))
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->modalHeading(__("organization.document.action.{$name}"))
            ->form([
                Forms\Components\Textarea::make('notes')
                    ->label(__('organization.document.review.notes'))
                    ->helperText(__('organization.document.review.notes_hint'))
                    ->required($notesRequired)
                    ->maxLength(1000),
            ])
            // Already decided the same way is not a decision; the row still
            // offers the other two outcomes, so a review can be corrected.
            ->visible(fn (OrganizationDocument $record): bool => $record->status !== $decision
                && $this->canReview($record))
            ->action(function (OrganizationDocument $record, array $data) use ($decision): void {
                abort_unless($this->canReview($record), 403);

                app(ReviewDocumentAction::class)->run(
                    $record,
                    $decision,
                    $data['notes'] ?? null,
                    current_actor(),
                );

                Notification::make()
                    ->title(__("organization.document.review.{$decision->value}"))
                    ->success()
                    ->send();
            });
    }

    private function canReview(OrganizationDocument $document): bool
    {
        return current_actor()?->can('review', $document) === true;
    }
}
