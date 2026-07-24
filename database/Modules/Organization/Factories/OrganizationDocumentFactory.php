<?php

declare(strict_types=1);

namespace Database\Modules\Organization\Factories;

use App\Modules\Organization\Domain\Enums\OrganizationDocumentStatus;
use App\Modules\Organization\Domain\Enums\OrganizationDocumentType;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrganizationDocument>
 *
 * The file itself is attached in the test via the media library on a faked
 * private disk; the factory produces the metadata row.
 */
final class OrganizationDocumentFactory extends Factory
{
    protected $model = OrganizationDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'organization_id' => Organization::factory(),
            'type' => OrganizationDocumentType::TaxCertificate,
            'status' => OrganizationDocumentStatus::Pending,
            'reviewed_by' => null,
            'review_notes' => null,
        ];
    }

    public function type(OrganizationDocumentType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }
}
