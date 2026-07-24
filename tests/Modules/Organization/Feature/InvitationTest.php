<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\InvitationTokenizerContract;
use App\Models\Seller;
use App\Models\User;
use App\Modules\Organization\Application\Actions\AcceptInvitationAction;
use App\Modules\Organization\Application\Actions\InviteMemberAction;
use App\Modules\Organization\Application\Actions\ResendInvitationAction;
use App\Modules\Organization\Domain\DTOs\InviteMemberDTO;
use App\Modules\Organization\Domain\Enums\OrganizationMemberStatus;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Events\OrganizationMemberJoined;
use App\Modules\Organization\Domain\Exceptions\InvitationException;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationInvitation;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Organization\Infrastructure\Notifications\OrganizationInvitationNotification;
use App\Shared\Enums\InvitationStatus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Organization invitations (Phase 3, ADR-031)
|--------------------------------------------------------------------------
|
| Hashed out-of-band tokens; acceptance requires the authenticated, invited
| account; never creates a user; single-use; re-issue rotates the token.
*/

beforeEach(fn () => $this->seedPlatform());

/**
 * Create a pending invitation and return it with its raw token — the way to
 * exercise acceptance, since the raw token is never stored.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{0: OrganizationInvitation, 1: string}
 */
function issueInvitation(Organization $org, string $email, array $overrides = []): array
{
    $tokenizer = app(InvitationTokenizerContract::class);
    $raw = $tokenizer->generate();

    $invitation = OrganizationInvitation::factory()->for($org)->create(array_merge([
        'email' => mb_strtolower($email),
        'role' => OrganizationRole::Manager,
        'token_hash' => $tokenizer->hash($raw),
    ], $overrides));

    return [$invitation, $raw];
}

it('issues a pending invitation, mails it, and stores only the hash', function (): void {
    Notification::fake();
    $org = Organization::factory()->create();

    $invitation = app(InviteMemberAction::class)->run($org, new InviteMemberDTO(
        organizationId: $org->getKey(),
        email: 'invitee@example.test',
        role: OrganizationRole::Manager,
        invitedBy: $org->owner_id,
    ));

    expect($invitation->status)->toBe(InvitationStatus::Pending)
        // Only the hash exists — there is no raw-token column at all.
        ->and(strlen((string) $invitation->token_hash))->toBe(64);

    Notification::assertSentOnDemand(OrganizationInvitationNotification::class);
});

it('cancels a prior pending invitation when a new one is issued', function (): void {
    $org = Organization::factory()->create();
    [$first] = issueInvitation($org, 'dup@example.test');
    Notification::fake();

    app(InviteMemberAction::class)->run($org, new InviteMemberDTO(
        $org->getKey(), 'dup@example.test', OrganizationRole::Finance, $org->owner_id,
    ));

    expect($first->fresh()->status)->toBe(InvitationStatus::Cancelled);
});

it('refuses to invite the owner role', function (): void {
    $org = Organization::factory()->create();

    expect(fn () => app(InviteMemberAction::class)->run($org, new InviteMemberDTO(
        $org->getKey(), 'o@example.test', OrganizationRole::Owner, $org->owner_id,
    )))->toThrow(InvitationException::class);
});

it('lets the invited account accept and become a member', function (): void {
    $org = Organization::factory()->create();
    $user = Seller::factory()->create(['email' => 'joiner@example.test']);
    [$invitation, $raw] = issueInvitation($org, 'joiner@example.test', ['role' => OrganizationRole::Finance]);
    Event::fake([OrganizationMemberJoined::class]);

    $member = app(AcceptInvitationAction::class)->run($raw, $user);

    expect($member->role)->toBe(OrganizationRole::Finance)
        ->and($member->status)->toBe(OrganizationMemberStatus::Active)
        ->and($invitation->fresh()->status)->toBe(InvitationStatus::Accepted);

    Event::assertDispatched(OrganizationMemberJoined::class);
});

it('never creates a user on accept', function (): void {
    $org = Organization::factory()->create();
    $user = Seller::factory()->create(['email' => 'exists@example.test']);
    [, $raw] = issueInvitation($org, 'exists@example.test');

    $before = User::query()->count();
    app(AcceptInvitationAction::class)->run($raw, $user);

    expect(User::query()->count())->toBe($before);
});

it('refuses acceptance by an account with a different email', function (): void {
    $org = Organization::factory()->create();
    $wrongUser = Seller::factory()->create(['email' => 'someone-else@example.test']);
    [, $raw] = issueInvitation($org, 'intended@example.test');

    expect(fn () => app(AcceptInvitationAction::class)->run($raw, $wrongUser))
        ->toThrow(InvitationException::class);
});

it('refuses an expired invitation', function (): void {
    $org = Organization::factory()->create();
    $user = Seller::factory()->create(['email' => 'late@example.test']);
    [, $raw] = issueInvitation($org, 'late@example.test', ['expires_at' => now()->subDay()]);

    expect(fn () => app(AcceptInvitationAction::class)->run($raw, $user))
        ->toThrow(InvitationException::class);
});

it('is single-use', function (): void {
    $org = Organization::factory()->create();
    $user = Seller::factory()->create(['email' => 'once@example.test']);
    [, $raw] = issueInvitation($org, 'once@example.test');

    app(AcceptInvitationAction::class)->run($raw, $user);

    expect(fn () => app(AcceptInvitationAction::class)->run($raw, $user))
        ->toThrow(InvitationException::class);
});

it('rotates the token on resend so the old link stops working', function (): void {
    Notification::fake();
    $org = Organization::factory()->create();
    $user = Seller::factory()->create(['email' => 'resend@example.test']);
    [$invitation, $oldRaw] = issueInvitation($org, 'resend@example.test');

    app(ResendInvitationAction::class)->run($invitation);

    expect(fn () => app(AcceptInvitationAction::class)->run($oldRaw, $user))
        ->toThrow(InvitationException::class);
});

it('gates invitation management on the capability and isolates by organization', function (): void {
    $org = Organization::factory()->create();
    [$invitation] = issueInvitation($org, 'managed@example.test');

    $manager = Seller::factory()->create();
    OrganizationMember::factory()->for($org)->role(OrganizationRole::Manager)
        ->create(['user_id' => $manager->getKey()]);

    $viewer = Seller::factory()->create();
    OrganizationMember::factory()->for($org)->role(OrganizationRole::Viewer)
        ->create(['user_id' => $viewer->getKey()]);

    $outsider = Seller::factory()->create();
    OrganizationMember::factory()->for(Organization::factory()->create())->role(OrganizationRole::Manager)
        ->create(['user_id' => $outsider->getKey()]);

    expect($manager->can('cancel', $invitation))->toBeTrue()
        ->and($viewer->can('cancel', $invitation))->toBeFalse()
        ->and($outsider->can('cancel', $invitation))->toBeFalse();
});
