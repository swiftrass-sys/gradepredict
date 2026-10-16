<?php
// Part of admin/account-generator.php (expects $in and $patterns). Not a page of its own.
if (!isset($in, $patterns)) { http_response_code(404); exit; }
?>
<fieldset class="form-group choice-group">
    <legend>Email style</legend>
    <div class="choice-row">
        <?php foreach ($patterns as $value => $example): ?>
            <label class="choice"><input type="radio" name="pattern" value="<?= e($value) ?>" <?= $in['pattern'] === $value ? 'checked' : '' ?>><span><?= e($example) ?></span></label>
        <?php endforeach; ?>
    </div>
</fieldset>
<div class="form-group">
    <label for="domain">Email domain</label>
    <div class="input-prefix"><span aria-hidden="true">@</span><input class="input" type="text" id="domain" name="domain" value="<?= e($in['domain']) ?>" autocapitalize="off" spellcheck="false" aria-label="Email domain"></div>
</div>
