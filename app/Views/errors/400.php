<?php
/**
 * @var \App\Core\View\ViewEngine $this
 */
echo $this->include('errors/layout', [
    'title'     => 'Bad request',
    'code'      => '400',
    'heading'   => 'Bad request',
    'message'   => 'The request could not be understood. Check the address and try again.',
    'reference' => $reference ?? null,
]);
