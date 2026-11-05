<?php

test('the home page redirects to the client list', function () {
    $this->get('/')->assertRedirect('/clients');
});
