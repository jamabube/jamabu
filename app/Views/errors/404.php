<?php
/**
 * @var \App\Core\View\ViewEngine $this
 */
echo $this->include('errors/layout', [
    'title'     => 'Page not found',
    'code'      => '404',
    'heading'   => 'Page not found',
    'message'   => 'There is nothing at this address. It may have been moved or removed.',
    'reference' => $reference ?? null,
]);
