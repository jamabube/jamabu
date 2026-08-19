<?php
/**
 * @var \App\Core\View\ViewEngine $this
 */
echo $this->include('errors/layout', [
    'title'     => 'Temporarily unavailable',
    'code'      => '503',
    'heading'   => 'Temporarily unavailable',
    'message'   => 'The system is undergoing maintenance. It will be back shortly.',
    'reference' => $reference ?? null,
]);
