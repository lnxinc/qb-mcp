<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/register')
        ->where('registrationOpen', true));
});

test('registration screen reports when registration is closed', function () {
    User::factory()->create();

    $response = $this->get(route('register'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/register')
        ->where('registrationOpen', false)
        ->where('teamInvitation', null));
});

test('the first account can be created without an invitation', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'Test@Example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard.redirect'));

    $user = User::where('email', 'test@example.com')->firstOrFail();
    $team = $user->currentTeam;

    expect($team)->not->toBeNull()
        ->and($team->is_personal)->toBeTrue()
        ->and($user->ownsTeam($team))->toBeTrue();

    $this->get(route('dashboard.redirect'))
        ->assertRedirect(route('dashboard', ['current_team' => $team->slug]));
});

test('registration is closed once an account exists', function () {
    User::factory()->create();

    $response = $this->from(route('register'))->post(route('register.store'), [
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
    expect(User::count())->toBe(1);
});

test('registration can be opened by configuration', function () {
    config(['app.open_registration' => true]);
    User::factory()->create();

    $this->post(route('register.store'), [
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    expect(User::count())->toBe(2);
});

test('invited email addresses can register while registration is closed', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Laravel Team']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->get(route('register', ['invitation' => $invitation->code]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('registrationOpen', false)
            ->where('teamInvitation.code', $invitation->code)
            ->where('teamInvitation.teamName', 'Laravel Team'));

    $this->post(route('register.store'), [
        'name' => 'Invited User',
        'email' => 'Invited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $invitation->code,
    ]);

    $this->assertAuthenticated();

    $this->get(route('dashboard.redirect'))->assertRedirect();
    $this->get(route('dashboard', ['current_team' => auth()->user()->currentTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('pendingInvitations', 1)
            ->where('pendingInvitations.0.code', $invitation->code));
});

test('an invitation cannot be used to register a different email address', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->post(route('register.store'), [
        'name' => 'Someone Else',
        'email' => 'someone@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $invitation->code,
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('an expired invitation cannot be used to register', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->post(route('register.store'), [
        'name' => 'Invited User',
        'email' => 'invited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $invitation->code,
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});
