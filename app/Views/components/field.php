<?php
/**
 * One labelled form control.
 *
 * @var string      $name
 * @var string      $label
 * @var string|null $type      text | email | number | date | datetime-local | password | textarea | select | checkbox
 * @var mixed       $value
 * @var bool|null   $required
 * @var string|null $help
 * @var string|null $placeholder
 * @var list<array{value:string,label:string}>|null $options
 * @var array<string,list<string>>|null $errors
 * @var array<string,string>|null $attributes
 * @var string|null $columns   grid span: full | half | third
 */
$type     = $type ?? 'text';
$id       = 'field-' . preg_replace('/[^a-z0-9]+/i', '-', $name);
$errors   = $errors ?? [];
$messages = $errors[$name] ?? [];
$value    = old($name, $value ?? '');
$extra    = \App\Core\Support\Html::attributes($attributes ?? []);
$invalid  = $messages !== [] ? ' is-invalid' : '';
?>
<div class="field field--<?= e($columns ?? 'half') ?>">
    <?php if ($type !== 'checkbox'): ?>
        <label class="field__label" for="<?= e($id) ?>">
            <?= e($label) ?><?= ($required ?? false) ? '<span class="field__required" aria-hidden="true">*</span>' : '' ?>
        </label>
    <?php endif; ?>

    <?php if ($type === 'textarea'): ?>
        <textarea class="field__control<?= $invalid ?>" id="<?= e($id) ?>" name="<?= e($name) ?>"
                  rows="<?= e((string) ($rows ?? 3)) ?>"
                  placeholder="<?= e((string) ($placeholder ?? '')) ?>"
                  <?= ($required ?? false) ? 'required' : '' ?><?= $extra ?>><?= e((string) $value) ?></textarea>

    <?php elseif ($type === 'select'): ?>
        <select class="field__control<?= $invalid ?>" id="<?= e($id) ?>" name="<?= e($name) ?>"
                <?= ($required ?? false) ? 'required' : '' ?><?= $extra ?>>
            <?php if (!($required ?? false) || ($placeholder ?? '') !== ''): ?>
                <option value=""><?= e((string) ($placeholder ?? 'Not set')) ?></option>
            <?php endif; ?>
            <?php foreach ((array) ($options ?? []) as $option): ?>
                <?php $optionValue = (string) ($option['value'] ?? ''); ?>
                <option value="<?= e($optionValue) ?>" <?= (string) $value === $optionValue ? 'selected' : '' ?>>
                    <?= e((string) ($option['label'] ?? $optionValue)) ?>
                </option>
            <?php endforeach; ?>
        </select>

    <?php elseif ($type === 'checkbox'): ?>
        <label class="field__check">
            <input type="checkbox" id="<?= e($id) ?>" name="<?= e($name) ?>" value="1"
                   <?= (bool) $value ? 'checked' : '' ?><?= $extra ?>>
            <span><?= e($label) ?></span>
        </label>

    <?php else: ?>
        <input class="field__control<?= $invalid ?>" type="<?= e($type) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>"
               value="<?= e((string) $value) ?>"
               placeholder="<?= e((string) ($placeholder ?? '')) ?>"
               <?= ($required ?? false) ? 'required' : '' ?><?= $extra ?>>
    <?php endif; ?>

    <?php if ($messages !== []): ?>
        <p class="field__error"><?= e((string) $messages[0]) ?></p>
    <?php elseif (isset($help) && $help !== ''): ?>
        <p class="field__help"><?= e((string) $help) ?></p>
    <?php endif; ?>
</div>
