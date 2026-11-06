<?php $callbackLabel=static fn(string $en,string $pl):string=>($interfaceLanguage??'en')==='pl'?$pl:$en; ?>
<section class="card-body" aria-labelledby="callback-templates-heading">
    <h3 id="callback-templates-heading"><?= $callbackLabel('AI Chatbot · callback requests','AI Chatbot · prośby o kontakt') ?></h3>
    <p><?= $callbackLabel('After a visitor leaves their email address, they see a chat reply and receive a thank-you email. To insert your site name, use','Po podaniu adresu klient zobaczy potwierdzenie w czacie i otrzyma e-mail z podziękowaniem. Aby wstawić nazwę swojej witryny, użyj') ?> <code>{{site_name}}</code>.</p>
    <label><span><?= $callbackLabel('Fallback team for unassigned conversations','Zespół dla rozmów bez opiekuna i przypisanego zespołu') ?></span><select class="form-input" name="templates[callback_routing][team_id]">
        <option value="0"><?= $callbackLabel('Select a team','Wybierz zespół') ?></option>
        <?php foreach ($callbackTeams as $team): ?><option value="<?= (int)$team['id'] ?>" <?= (int)$emailTemplates['callback_routing']['team_id']===(int)$team['id']?'selected':'' ?>><?= $escape($team['name']) ?></option><?php endforeach; ?>
    </select></label>
    <p><?= $callbackLabel('The operator or team receives an inbox notification and an internal email. Calendar 1.0.1 or later adds an all-day task on the request date. Without an assigned operator or team, the request waits for the fallback team.','Opiekun lub zespół otrzyma powiadomienie w panelu i wewnętrzny e-mail. Kalendarz od wersji 1.0.1 zapisze całodniowe zadanie na dzień zgłoszenia. Rozmowa bez przypisania poczeka na wybór zespołu domyślnego.') ?></p>
    <?php foreach (['pl'=>'Polski','en'=>'English','km'=>'ភាសាខ្មែរ','zh'=>'中文'] as $locale=>$language):
        $slug='chat_callback_'.$locale; $template=$emailTemplates[$slug]; ?>
        <details><summary><?= $escape($language) ?></summary>
            <div class="sensecms-email-template-grid">
                <fieldset><legend><?= $escape($uiText('AI Chatbot')) ?></legend><label><span><?= $callbackLabel('Thank-you reply in the conversation','Podziękowanie w rozmowie') ?></span><textarea class="form-input" name="templates[<?= $slug ?>][chat]" maxlength="1000" rows="4" required><?= $escape($template['chat']) ?></textarea></label></fieldset>
                <fieldset><legend><?= $callbackLabel('Confirmation email','E-mail do klienta') ?></legend>
                    <?php foreach (['subject'=>$callbackLabel('Subject','Temat'),'heading'=>$callbackLabel('Heading','Nagłówek'),'body'=>$callbackLabel('Message','Treść'),'button_label'=>$callbackLabel('Website button','Przycisk strony'),'footer'=>$callbackLabel('Footer note','Stopka')] as $key=>$label): ?>
                        <label><span><?= $label ?></span><textarea class="form-input" name="templates[<?= $slug ?>][<?= $key ?>]" rows="<?= $key==='body'?7:2 ?>" maxlength="<?= \App\Core\ChatCallbackTemplates::LIMITS[$key] ?>" <?= $key!=='footer'?'required':'' ?>><?= $escape($template[$key]) ?></textarea></label>
                    <?php endforeach; ?>
                </fieldset>
                <fieldset><legend><?= $callbackLabel('Internal team email','Wewnętrzny e-mail do zespołu') ?></legend>
                    <label><span><?= $callbackLabel('Subject','Temat') ?></span><input class="form-input" name="templates[<?= $slug ?>][team_subject]" maxlength="180" required value="<?= $escape($template['team_subject']) ?>"></label>
                    <label><span><?= $callbackLabel('Message','Treść') ?></span><textarea class="form-input" name="templates[<?= $slug ?>][team_body]" rows="4" maxlength="5000" required><?= $escape($template['team_body']) ?></textarea></label>
                </fieldset>
            </div>
        </details>
    <?php endforeach; ?>
</section>
