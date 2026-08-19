<?php
/**
 * @var \App\Core\View\ViewEngine $this
 */
echo $this->include('errors/layout', [
    'title'     => 'Could not be saved',
    'code'      => '422',
    'heading'   => 'Could not be saved',
    'message'   => 'Some of the submitted values were not accepted. Correct them and try again.',
    'reference' => $reference ?? null,
]);
