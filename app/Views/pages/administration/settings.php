<?php
/**
 * Runtime settings.
 *
 * Each setting carries its own type and validation rule in the database, so
 * the control rendered here is chosen from the row rather than hardcoded, and
 * the rule a value is checked against travels with the value.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,list<array<string,mixed>>> $groups
 * @var bool $canUpdate
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'These values override the shipped configuration on every request. A change takes effect on the next page load.';
$this->stop();

$this->start('page_actions');
?>
<?php if ($canUpdate): ?>
    <button type="button" class="button button--primary" data-settings-save>
        <i class="fa-solid fa-check" aria-hidden="true"></i> Save changes
    </button>
<?php endif; ?>
<?php
$this->stop();
?>

<form class="settings" data-settings-form data-endpoint="<?= e(route('api.settings.update')) ?>">
    <?php foreach ($groups as $group => $settings): ?>
        <section class="card">
            <header class="card__header">
                <h2 class="card__title">
                    <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                    <?= e(ucfirst(str_replace(['_', '.'], ' ', (string) $group))) ?>
                </h2>
            </header>
            <div class="card__body">
                <div class="field-grid">
                    <?php foreach ($settings as $setting): ?>
                        <?php
                        $key      = (string) $setting['setting_key'];
                        $id       = 'setting-' . preg_replace('/[^a-z0-9]+/i', '-', $key);
                        $type     = (string) $setting['value_type'];
                        $editable = $canUpdate && (int) $setting['is_editable'] === 1;
                        $value    = (string) ($setting['value'] ?? '');
                        /** @var list<string>|null $options */
                        $options  = is_string($setting['options'] ?? null)
                            ? (json_decode((string) $setting['options'], true) ?: null)
                            : ($setting['options'] ?? null);
                        ?>
                        <div class="field field--<?= $type === 'text' || $type === 'json' ? 'full' : 'half' ?>">
                            <label class="field__label" for="<?= e($id) ?>">
                                <?= e((string) $setting['label']) ?>
                                <?php if ((int) $setting['requires_restart'] === 1): ?>
                                    <span class="badge badge--warning">Needs a restart</span>
                                <?php endif; ?>
                            </label>

                            <?php if ($type === 'boolean'): ?>
                                <label class="switch">
                                    <input type="checkbox" id="<?= e($id) ?>" name="settings[<?= e($key) ?>]" value="1"
                                           <?= in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true) ? 'checked' : '' ?>
                                           <?= $editable ? '' : 'disabled' ?>>
                                    <span class="switch__track" aria-hidden="true"><span class="switch__thumb"></span></span>
                                    <span class="switch__label">Enabled</span>
                                </label>

                            <?php elseif (is_array($options) && $options !== []): ?>
                                <select class="field__control" id="<?= e($id) ?>" name="settings[<?= e($key) ?>]"
                                        <?= $editable ? '' : 'disabled' ?>>
                                    <?php foreach ($options as $option): ?>
                                        <option value="<?= e((string) $option) ?>" <?= $value === (string) $option ? 'selected' : '' ?>>
                                            <?= e((string) $option) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif ($type === 'text' || $type === 'json'): ?>
                                <textarea class="field__control" id="<?= e($id) ?>" name="settings[<?= e($key) ?>]"
                                          rows="3" <?= $editable ? '' : 'disabled' ?>><?= e($value) ?></textarea>

                            <?php elseif ((int) $setting['is_sensitive'] === 1): ?>
                                <input class="field__control" type="password" id="<?= e($id) ?>"
                                       name="settings[<?= e($key) ?>]" autocomplete="new-password"
                                       placeholder="<?= ($setting['has_value'] ?? false) ? 'Set — leave empty to keep it' : 'Not set' ?>"
                                       <?= $editable ? '' : 'disabled' ?>>

                            <?php else: ?>
                                <input class="field__control"
                                       type="<?= $type === 'integer' || $type === 'float' ? 'number' : 'text' ?>"
                                       <?= $type === 'float' ? 'step="0.01"' : '' ?>
                                       id="<?= e($id) ?>" name="settings[<?= e($key) ?>]"
                                       value="<?= e($value) ?>" <?= $editable ? '' : 'disabled' ?>>
                            <?php endif; ?>

                            <p class="field__help">
                                <?= e((string) ($setting['description'] ?? '')) ?>
                                <?php if ($editable && (string) ($setting['default_value'] ?? '') !== ''): ?>
                                    <button type="button" class="link-button" data-reset-setting="<?= e($key) ?>">
                                        Reset to “<?= e((string) $setting['default_value']) ?>”
                                    </button>
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
</form>
