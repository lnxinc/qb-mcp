<?php

use App\Models\User;

test('the home page sends guests to the login screen', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('the home page sends authenticated users to their dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('dashboard.redirect'));

    $this->get(route('dashboard.redirect'))
        ->assertRedirect(route('dashboard', ['current_team' => $user->currentTeam->slug]));
});
