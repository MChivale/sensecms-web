<?php
declare(strict_types=1);
$say=static fn(string $en,string $pl):string=>($interfaceLanguage??'en')==='pl'?$pl:$en;
$edit=null;
foreach ($scenarioRules as $item) if ($item['id']===($_GET['scenario']??'')) $edit=$item;
$base='/ai/knowledge?tab=scenarios&language='.rawurlencode($scenarioLocale);
$actions=[
    'source'=>$say('Answer from a source','Odpowiedź ze źródła'),
    'clarify'=>$say('Ask a clarifying question','Pytanie doprecyzowujące'),
    'contact'=>$say('Request email and queue for the team','Poproś o e-mail i przekaż zespołowi'),
    'human'=>$say('Hand over to the team','Przekaż rozmowę zespołowi'),
];
?>
<section class="card">
  <header class="card-header"><div>
    <h2 class="card-title"><?= $say('Local chat scenarios','Lokalne scenariusze czatu') ?></h2>
    <p><?= $say('Add several ways a visitor might ask the same question. A matching phrase runs the action without contacting an AI provider.','Dodaj różne sformułowania tego samego pytania. Rozpoznane pytanie uruchamia wybraną akcję bez połączenia z dostawcą AI.') ?></p>
  </div></header>
  <div class="card-body">
    <nav class="sensecms-section-tabs" aria-label="<?= $say('Scenario language','Język scenariuszy') ?>">
      <?php foreach ($languages as $language): ?><a href="/ai/knowledge?tab=scenarios&amp;language=<?= rawurlencode($language['locale']) ?>" <?= $scenarioLocale===$language['locale']?'aria-current="page" class="is-active"':'' ?>><?= $escape($language['native_name'] ?: $language['locale']) ?></a><?php endforeach; ?>
    </nav>
    <ul><?php foreach ($scenarioRules as $item): ?><li><a class="text-primary" href="<?= $escape($base.'&scenario='.$item['id']) ?>"><?= $escape($item['name']) ?></a> · <?= $escape($actions[$item['action']]) ?></li><?php endforeach; ?></ul>
    <a class="btn bg-default-150" href="<?= $escape($base) ?>"><?= $say('New scenario','Nowy scenariusz') ?></a>
  </div>
</section>
<section class="card sensecms-knowledge-editor">
  <div class="card-header"><h2 class="card-title"><?= $escape($edit['name']??$say('New scenario','Nowy scenariusz')) ?></h2></div>
  <form method="post" action="/ai/knowledge/scenarios" class="card-body">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
    <input type="hidden" name="id" value="<?= $escape($edit['id']??'') ?>">
    <input type="hidden" name="revision" value="<?= $escape($edit['revision']??'') ?>">
    <input type="hidden" name="locale" value="<?= $escape($scenarioLocale) ?>">
    <label><span><?= $say('Name shown when a visitor must choose','Nazwa widoczna przy wyborze sprawy') ?></span><input class="form-input" name="name" required maxlength="100" value="<?= $escape($edit['name']??'') ?>"></label>
    <label><span><?= $say('Example questions, one per line','Przykładowe pytania, po jednym w wierszu') ?></span><textarea class="form-input" name="questions" required rows="5"><?= $escape(implode("\n",$edit['questions']??[])) ?></textarea><small><?= $say('Use a shared phrase in two scenarios to ask which one the visitor means. Visitors can reply with a scenario name.','Dodaj tę samą frazę w dwóch scenariuszach, aby chatbot dopytał o wybór. Odwiedzający może odpowiedzieć nazwą scenariusza.') ?></small></label>
    <label><span><?= $say('Action','Akcja') ?></span><select class="form-input" name="action"><?php foreach ($actions as $key=>$label): ?><option value="<?= $key ?>" <?= ($edit['action']??'source')===$key?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
    <label><span><?= $say('Source for an answer','Źródło odpowiedzi') ?></span><select class="form-input" name="source"><option value=""><?= $say('Select a source','Wybierz źródło') ?></option><?php foreach ($scenarioSources as $doc): ?><option value="<?= (int)$doc['id'] ?>" <?= (int)($edit['source']??0)===(int)$doc['id']?'selected':'' ?>><?= $escape($doc['title']) ?></option><?php endforeach; ?></select><small><?= $say('Use a short approved manual answer or the current excerpt of a public page or post (up to 1,600 characters).','Wybierz krótką, zatwierdzoną odpowiedź ręczną albo aktualny opis publicznej strony lub wpisu (do 1600 znaków).') ?></small></label>
    <label><span><?= $say('Clarifying question or introduction to an email request','Pytanie doprecyzowujące lub wstęp do prośby o e-mail') ?></span><textarea class="form-input" name="text" rows="2" maxlength="1000"><?= $escape($edit['text']??'') ?></textarea></label>
    <label><span><?= $say('Last valid day (UTC), optional','Ostatni dzień ważności (UTC), opcjonalnie') ?></span><input class="form-input" type="date" name="until" value="<?= $escape($edit['until']??'') ?>"></label>
    <footer><button type="submit" class="btn bg-primary text-white"><?= $say('Save scenario','Zapisz scenariusz') ?></button></footer>
  </form>
  <?php if ($edit): ?><details class="card-body"><summary><?= $say('Remove this scenario','Usuń ten scenariusz') ?></summary>
    <form method="post" action="/ai/knowledge/scenarios"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="id" value="<?= $escape($edit['id']) ?>"><input type="hidden" name="revision" value="<?= $escape($edit['revision']) ?>"><input type="hidden" name="locale" value="<?= $escape($scenarioLocale) ?>"><button class="btn bg-default-150" name="operation" value="delete"><?= $say('Confirm removal','Potwierdź usunięcie') ?></button></form>
  </details><?php endif; ?>
</section>
