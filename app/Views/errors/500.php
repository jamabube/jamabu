<?php
/**
 * @var \App\Core\View\ViewEngine $this
 */
echo $this->include('errors/layout', [
    'title'     => 'Something went wrong',
    'code'      => '500',
    'heading'   => 'Something went wrong',
    'message'   => 'An internal error prevented this request from completing. It has been recorded.',
    'reference' => $reference ?? null,
]);
