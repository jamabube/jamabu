<?php
/**
 * @var \App\Core\View\ViewEngine $this
 */
echo $this->include('errors/layout', [
    'title'     => 'Sign in required',
    'code'      => '401',
    'heading'   => 'Sign in required',
    'message'   => 'Your session has ended. Please sign in again to continue.',
    'reference' => $reference ?? null,
]);
