<?php
/**
 * @var \App\Core\View\ViewEngine $this
 */
echo $this->include('errors/layout', [
    'title'     => 'Too many requests',
    'code'      => '429',
    'heading'   => 'Too many requests',
    'message'   => 'Too many requests have come from this workstation. Wait a moment and try again.',
    'reference' => $reference ?? null,
]);
