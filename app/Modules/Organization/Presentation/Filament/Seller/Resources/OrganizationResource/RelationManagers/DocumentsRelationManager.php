<?php

declare(strict_types=1);

namespace App\Modules\Organization\Presentation\Filament\Seller\Resources\OrganizationResource\RelationManagers;

use App\Modules\Organization\Application\Actions\UploadDocumentAction;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentStatus;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentType;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationDocument;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * The company's KYC / legal documents.
 *
 * PRIVATE BY CONSTRUCTION. The upload is handed to `UploadDocumentAction`, which
 * puts the file in the `documents` media collection — bound to the private disk
 * by `App\Shared\Traits\HasMedia` — and starts the row at `Pending`. The file is
 * therefore never publicly readable and the table links to it only through the
 * model's short-lived signed URL.
 *
 * WHY `storeFiles(false)`: Filament would otherwise persist the upload itself
 * and hand the action a path string. The action's contract is an `UploadedFile`
 * that the media library moves to the private disk, so letting Filament store
 * it first would write the document to a second, wrong location and leave it
 * there. Livewire's temporary upload already IS an `UploadedFile`.
 *
 * Reviewing a document — approve, reject, ask for a revision — is an ADMIN
 * decision and lives in the admin panel. A seller uploads and reads status.
 *
 * @see App\Modules\Organization\Presentation\Controllers\Api\DocumentController
 */
final class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('organization.document.plural');
    }

    /**
     * Filament makes relation managers read-only on a ViewRecord page by
     * default, on the assumption that "view" means "look, do not touch". Here
     * the company page IS the onboarding hub — uploading a document is the
     * point of visiting it — so the default is turned off deliberately rather
     * than by moving the manager to the Edit page, where a seller would have to
     * enter an edit form to attach a file.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Upload only. Type and file mirror `UploadDocumentRequest` — the same
     * accepted formats and the same 10 MB cap the API enforces.
     */
    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')
                ->label(__('organization.document.type'))
                ->options(fn (): array => collect(OrganizationDocumentType::cases())
                    ->mapWithKeys(fn (OrganizationDocumentType $type): array => [
                        $type->value => __("enums.OrganizationDocumentType.{$type->value}"),
                    ])
                    ->all())
                ->required(),

            Forms\Components\FileUpload::make('file')
                ->label(__('organization.document.file'))
                ->required()
                ->storeFiles(false)
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(10240)
                ->helperText(__('organization.document.file_hint')),
        ]);
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
                        OrganizationDocumentStatus::Pending => 'warning',
                        OrganizationDocumentStatus::NeedsRevision => 'warning',
                        OrganizationDocumentStatus::Rejected => 'danger',
                    })
                    ->formatStateUsing(fn (OrganizationDocumentStatus $state): string => __("enums.OrganizationDocumentStatus.{$state->value}")),

                // The admin's explanation of what to fix — the whole point of
                // showing the seller a `needs_revision` row.
                Tables\Columns\TextColumn::make('review_notes')
                    ->label(__('organization.document.review_notes'))
                    ->wrap()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('organization.document.uploaded_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('organization.document.action.upload'))
                    ->modalHeading(__('organization.document.action.upload'))
                    ->using(function (array $data): Model {
                        /** @var Organization $organization */
                        $organization = $this->getOwnerRecord();
                        /** @var UploadedFile $file */
                        $file = $data['file'];

                        return app(UploadDocumentAction::class)->run(
                            $organization,
                            OrganizationDocumentType::from((string) $data['type']),
                            $file,
                        );
                    }),
            ])
            ->actions([
                /*
                | A private document is reached by a short-lived signed URL and
                | nothing else — never a stored public link. The model owns the
                | TTL; the row simply hides the button when no file is attached.
                */
                Tables\Actions\Action::make('download')
                    ->label(__('organization.document.action.view'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (OrganizationDocument $record): bool => $record->file() !== null)
                    ->url(fn (OrganizationDocument $record): ?string => $record->temporaryUrl(), shouldOpenInNewTab: true),
            ])
            // A seller does not delete evidence an admin is reviewing, and does
            // not edit a submitted document — a correction is a new upload.
            ->bulkActions([])
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading(__('organization.document.empty.heading'))
            ->emptyStateDescription(__('organization.document.empty.description'))
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Uploading is gated on the ORGANIZATION's manageKyc capability, exactly as
     * `UploadDocumentRequest::authorize()` gates the API.
     *
     * Filament would otherwise ask `OrganizationDocumentPolicy` for a `create`
     * ability. That policy deliberately has none: a document is not created in
     * its own right, it is created against a company, and the decision belongs
     * to the company's capability matrix — not to a per-document rule.
     */
    protected function canCreate(): bool
    {
        return auth()->user()?->can('manageKyc', $this->getOwnerRecord()) === true;
    }
}
