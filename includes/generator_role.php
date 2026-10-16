<?php
// Part of admin/account-generator.php (expects $in and $mode). Not a page of its own.
if (!isset($in, $mode)) { http_response_code(404); exit; }
$roleChoices = $mode === 'single' ? ['student' => 'Student', 'teacher' => 'Teacher', 'parent' => 'Parent'] : ['student' => 'Student', 'teacher' => 'Teacher'];
?>
<fieldset class="form-group choice-group">
    <legend>Role</legend>
    <div class="choice-row">
        <?php foreach ($roleChoices as $value => $label): ?>
            <label class="choice"><input type="radio" name="role" value="<?= e($value) ?>" <?= $in['role'] === $value ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
        <?php endforeach; ?>
    </div>
</fieldset>
