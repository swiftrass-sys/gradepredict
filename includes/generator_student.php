<?php
// Part of admin/account-generator.php (expects $in, $programs, $majors, $sections). Not a page of its own.
if (!isset($in, $programs, $majors, $sections)) { http_response_code(404); exit; }
?>
<div id="studentFields" <?= $in['role'] === 'student' ? '' : 'hidden' ?>>
    <div class="form-group">
        <label for="section_id">Section <span class="label-quiet">(optional)</span></label>
        <select class="input" id="section_id" name="section_id">
            <option value="">No section</option>
            <?php foreach ($sections as $sec): ?>
                <option value="<?= (int) $sec['id'] ?>" <?= (string) $in['section_id'] === (string) $sec['id'] ? 'selected' : '' ?>><?= e($sec['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="field-hint">A section sets the program, major and year for you.</div>
    </div>
    <div class="form-group">
        <label for="program_id">Program</label>
        <select class="input" id="program_id" name="program_id">
            <option value="">Choose a program</option>
            <?php foreach ($programs as $p): ?>
                <option value="<?= (int) $p['id'] ?>" data-years="<?= program_year_count((int) $p['duration_months']) ?>" <?= (string) $in['program_id'] === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['code'] . ' — ' . $p['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row-2">
        <div class="form-group">
            <label for="major_id">Major <span class="label-quiet">(if any)</span></label>
            <select class="input" id="major_id" name="major_id">
                <option value="">No major</option>
                <?php foreach ($majors as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" data-program="<?= (int) $m['program_id'] ?>" <?= (string) $in['major_id'] === (string) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="year_level">Year level</label>
            <input class="input" type="number" id="year_level" name="year_level" min="1" max="6" data-years-from="program_id" value="<?= e($in['year_level']) ?>">
        </div>
    </div>
</div>
