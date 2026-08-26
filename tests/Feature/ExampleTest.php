<?php

it('redirects the root to the authenticated application', function () {
    $response = $this->get('/');

    $response->assertRedirect('/dashboard');
});
