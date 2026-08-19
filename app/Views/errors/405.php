<?php
/**
 * @var \App\Core\View\ViewEngine $this
 */
echo $this->include('errors/layout', [
    'title'     => 'Method not allowed',
    'code'      => '405',
    'heading'   => 'Method not allowed',
    'message'   => 'That action is not supported at this address.',
    'reference' => $reference ?? null,
]);
