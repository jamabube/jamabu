<?php
/**
 * @var \App\Core\View\ViewEngine $this
 */
echo $this->include('errors/layout', [
    'title'     => 'Not permitted',
    'code'      => '403',
    'heading'   => 'Not permitted',
    'message'   => 'Your role does not include access to this page. If you need it, ask an administrator.',
    'reference' => $reference ?? null,
]);
