<?php
declare(strict_types=1);
$formUid = (string) ($block['uid'] ?? '');
if (!preg_match('/^[a-f0-9-]{36}$/D', $formUid)) return;
$captcha = (bool) ($block['shared']['captcha'] ?? true);
?>
<section class="contact-form-card">
<span class="eyebrow"><?= $e($data['eyebrow'] ?? 'START THE CONVERSATION') ?></span><h2><?= $e($data['title'] ?? 'Send us a message') ?></h2><p><?= $e($data['text'] ?? '') ?></p>
<form action="/api/forms/<?= $e($formUid) ?>/submit" method="post" data-contact-form>
<input type="hidden" name="csrf" value="<?= $e($formCsrf) ?>"><input type="hidden" name="locale" value="<?= $e($locale) ?>">
<div class="form-trap" aria-hidden="true"><label>Leave this field empty<input name="website" tabindex="-1" autocomplete="off"></label></div>
<div class="contact-fields">
<?php foreach ((array) ($data['fields'] ?? []) as $field): $key=(string)($field['key']??''); if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/D',$key)) continue; $type=(string)($field['type']??'text'); $id='field-'.$formUid.'-'.$key; $required=!empty($field['required']); ?>
<div class="contact-field<?= in_array($type,['textarea','checkbox'],true) ? ' field-wide' : '' ?>">
<?php if ($type === 'checkbox'): ?><label class="contact-consent"><input type="checkbox" id="<?= $id ?>" name="fields[<?= $key ?>]" value="1"<?= $required?' required':'' ?>><span><?= $e($field['label']) ?><?= $required?' *':'' ?></span></label>
<?php else: ?><label for="<?= $id ?>"><?= $e($field['label']) ?><?= $required?' <span aria-hidden="true">*</span>':'' ?></label>
<?php if ($type === 'textarea'): ?><textarea id="<?= $id ?>" name="fields[<?= $key ?>]" rows="6" maxlength="5000" placeholder="<?= $e($field['placeholder']??'') ?>"<?= $required?' required':'' ?>></textarea>
<?php elseif ($type === 'select'): ?><select id="<?= $id ?>" name="fields[<?= $key ?>]"<?= $required?' required':'' ?>><option value="">Choose a topic</option><?php foreach (array_filter(array_map('trim',preg_split('/\R/u',(string)($field['options']??''))?:[])) as $option): ?><option><?= $e($option) ?></option><?php endforeach; ?></select>
<?php else: ?><input id="<?= $id ?>" name="fields[<?= $key ?>]" type="<?= in_array($type,['email','tel'],true)?$type:'text' ?>" maxlength="<?= $type==='email'?190:500 ?>" autocomplete="<?= $type==='email'?'email':($key==='name'?'name':($type==='tel'?'tel':'off')) ?>" placeholder="<?= $e($field['placeholder']??'') ?>"<?= $required?' required':'' ?>><?php endif; endif; ?></div>
<?php endforeach; ?>
</div>
<?php if ($captcha): ?><div class="contact-captcha"><div><label for="captcha-<?= $formUid ?>">Security code <span aria-hidden="true">*</span></label><div class="captcha-image"><img src="/captcha/forms/<?= $formUid ?>.png" width="180" height="52" alt="Sense CMS security code; enter the characters shown" data-captcha-image><button type="button" data-captcha-refresh aria-label="Generate a new security code">↻</button></div></div><div><label for="captcha-<?= $formUid ?>">Enter the code</label><input id="captcha-<?= $formUid ?>" name="captcha" maxlength="10" required autocomplete="off" spellcheck="false" autocapitalize="characters" aria-describedby="captcha-help-<?= $formUid ?>"></div></div><p class="form-note" id="captcha-help-<?= $formUid ?>">Protected by Sense CMS CAPTCHA. Can’t read the code? Generate a new one or contact us by email.</p><?php endif; ?>
<p class="form-note"><?= $e($data['privacy_text'] ?? '') ?></p><div class="contact-submit"><button class="button" type="submit"><?= $e($data['submit_label']??'Send message') ?> <span aria-hidden="true">↗</span></button><span>Fields marked * are required.</span></div>
<p class="form-result" role="status" aria-live="polite" tabindex="-1" hidden></p><noscript><p>Please enable JavaScript to submit this form, or email info@SenseCMS.com.</p></noscript>
</form></section>
