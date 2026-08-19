<?php
/**
 * @var \App\Core\View\ViewEngine $this
 */
echo $this->include('errors/layout', [
    'title'     => 'Security token expired',
    'code'      => '419',
    'heading'   => 'Security token expired',
    'message'   => 'The page was open too long and its security token expired. Reload and try again.',
    'reference' => $reference ?? null,
]);
