<?php
$enabledProviders=count(array_filter($providers,static fn(array$provider):bool=>(bool)$provider['enabled']));
$budgetFields=static function(array$limits)use($escape):void{
    $values=array_replace(['daily_requests'=>0,'monthly_requests'=>0,'daily_tokens'=>0,'monthly_tokens'=>0,'daily_cost_usd'=>'0','monthly_cost_usd'=>'0','input_cost_per_million'=>'0','output_cost_per_million'=>'0','warning_percent'=>80],$limits);
    $fields=[
        ['daily_request_limit','Daily requests','daily_requests','1'],
        ['monthly_request_limit','Monthly requests','monthly_requests','1'],
        ['daily_token_limit','Daily tokens','daily_tokens','1'],
        ['monthly_token_limit','Monthly tokens','monthly_tokens','1'],
        ['daily_cost_limit','Daily budget (USD)','daily_cost_usd','0.01'],
        ['monthly_cost_limit','Monthly budget (USD)','monthly_cost_usd','0.01'],
        ['input_cost_per_million','Input price / 1M tokens','input_cost_per_million','0.000001'],
        ['output_cost_per_million','Output price / 1M tokens','output_cost_per_million','0.000001'],
    ]; ?>
    <fieldset class="sensecms-ai-budget">
        <legend><i data-lucide="gauge"></i><span>Usage guardrails<small>Zero means unlimited. Pricing is entered manually from the provider's current price list.</small></span></legend>
        <div class="sensecms-ai-budget-fields">
            <?php foreach($fields as[$name,$label,$key,$step]): ?><label><span><?= $escape($label) ?></span><input class="form-input" type="number" name="<?= $escape($name) ?>" min="0" step="<?= $step ?>" value="<?= $escape((string)$values[$key]) ?>"></label><?php endforeach; ?>
            <label><span>Warning threshold (%)</span><input class="form-input" type="number" name="warning_percent" min="50" max="100" step="1" value="<?= (int)$values['warning_percent'] ?>"></label>
        </div>
        <p><i data-lucide="shield-check"></i>Core reserves the maximum requested tokens before a call and blocks it when a configured request, token or cost budget would be exceeded.</p>
    </fieldset>
<?php };
$sectionHero=['overline'=>'ENGAGEMENT · ASSISTED SUPPORT','title'=>'AI & Live Support','description'=>'Configure multiple AI providers for the Core assistant and Page Builder while keeping every generated change under human review.','icon'=>'bot-message-square','status'=>$enabledProviders.' active '.($enabledProviders===1?'provider':'providers'),'status_detail'=>'Provider-neutral Core orchestration','status_icon'=>$enabledProviders?'circle-check':'headphones'];
?>
<section class="sensecms-unified-workspace">
    <?php require __DIR__.'/console-section-hero.php'; ?>
    <?php $aiActive='providers';require __DIR__.'/console-ai-navigation.php'; ?>
    <div class="sensecms-ai-summary"><?php foreach($ai as$label=>$value): ?><article><span><?= $escape(ucwords(str_replace('_',' ',$label))) ?></span><strong><?= $escape((string)$value) ?></strong></article><?php endforeach; ?></div>
    <section class="card sensecms-ai-provider-workspace">
        <div class="card-header"><div><h6 class="card-title">AI providers</h6><p class="mt-1 text-sm font-normal text-default-500">Credentials are encrypted. Usage metadata never contains prompts or generated content.</p></div><a href="/conversations" class="btn btn-sm bg-primary text-white"><i data-lucide="messages-square"></i>Open live chat</a></div>
        <div class="sensecms-ai-provider-list">
            <?php foreach($providers as$provider):
                $purposes=(array)($provider['options']['purposes']??[]);
                $usage=(array)($provider['usage']??[]);
                $limits=(array)($usage['limits']??[]);
                $ratios=(array)($usage['ratios']??[]);
                $highest=$ratios?max($ratios):0;
            ?>
                <article class="sensecms-ai-provider-card<?= !empty($usage['warning'])?' has-budget-warning':'' ?>">
                    <header><span class="sensecms-ai-provider-icon"><i data-lucide="cpu"></i></span><div><strong><?= $escape($provider['name']) ?></strong><small><?= $escape(($aiDrivers[\App\Core\AiProviderClient::driver((string)$provider['driver'])]['label']??$provider['driver']).' · '.$provider['default_model']) ?></small></div><em class="<?= !empty($provider['enabled'])?'is-enabled':'' ?>"><?= !empty($provider['enabled'])?'Enabled':'Disabled' ?></em></header>
                    <div class="sensecms-ai-usage">
                        <div><span>Today</span><strong><?= number_format((int)($usage['today']['requests']??0)) ?> requests · <?= number_format((int)($usage['today']['tokens']??0)) ?> tokens</strong><small>$<?= number_format((float)($usage['today']['cost']??0),4) ?> estimated</small></div>
                        <div><span>This month</span><strong><?= number_format((int)($usage['month']['requests']??0)) ?> requests · <?= number_format((int)($usage['month']['tokens']??0)) ?> tokens</strong><small>$<?= number_format((float)($usage['month']['cost']??0),4) ?> estimated</small></div>
                        <div class="sensecms-ai-budget-state"><span>Highest configured budget</span><strong><?= $ratios?number_format((float)$highest,1).'%':'No hard budget' ?></strong><div role="progressbar" aria-label="Highest configured AI budget usage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round($highest) ?>"><i style="width:<?= min(100,(float)$highest) ?>%"></i></div></div>
                    </div>
                    <form method="post" action="/ai/providers">
                        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$provider['id'] ?>">
                        <div class="sensecms-ai-provider-fields">
                            <label><span>Name</span><input class="form-input" name="name" maxlength="120" required value="<?= $escape($provider['name']) ?>"></label>
                            <label><span>Slug</span><input class="form-input" name="slug" maxlength="80" required pattern="[a-z][a-z0-9-]+" value="<?= $escape($provider['slug']) ?>"></label>
                            <label><span>Driver</span><select class="form-input" name="driver" required><?php foreach($aiDrivers as$key=>$driver): ?><option value="<?= $escape($key) ?>" <?= \App\Core\AiProviderClient::driver((string)$provider['driver'])===$key?'selected':'' ?>><?= $escape($driver['label']) ?></option><?php endforeach; ?></select></label>
                            <label><span>Model</span><input class="form-input" name="default_model" maxlength="150" required value="<?= $escape($provider['default_model']) ?>"></label>
                            <label class="is-wide"><span>Base API URL</span><input class="form-input" type="url" name="base_url" maxlength="500" required value="<?= $escape($provider['base_url']) ?>"></label>
                            <label><span>API key</span><input class="form-input" type="password" name="api_key" autocomplete="new-password" placeholder="<?= !empty($provider['configured'])?'Stored securely · leave blank to keep':'Enter API key' ?>"></label>
                            <label><span>Priority</span><input class="form-input" type="number" name="priority" min="1" max="999" value="<?= (int)$provider['priority'] ?>"></label>
                        </div>
                        <?php $budgetFields($limits); ?>
                        <div class="sensecms-ai-provider-options"><label><input type="checkbox" name="purposes[]" value="builder" <?= !$purposes||in_array('builder',$purposes,true)?'checked':'' ?>>Page Builder</label><label><input type="checkbox" name="purposes[]" value="posts" <?= in_array('posts',$purposes,true)?'checked':'' ?>>Posts</label><label><input type="checkbox" name="purposes[]" value="chat" <?= !$purposes||in_array('chat',$purposes,true)?'checked':'' ?>>Visitor assistant</label><label><input type="checkbox" name="enabled" value="1" <?= !empty($provider['enabled'])?'checked':'' ?>>Enabled</label></div>
                        <footer><span><i data-lucide="<?= !empty($provider['verified_at'])?'badge-check':'shield-alert' ?>"></i><?= !empty($provider['verified_at'])?'Verified '.$escape($provider['verified_at']):(!empty($provider['last_error'])?$escape($provider['last_error']):'Not verified') ?></span><button class="btn bg-primary text-white" type="submit"><i data-lucide="save"></i>Save provider</button></footer>
                    </form>
                    <form method="post" action="/ai/providers/<?= (int)$provider['id'] ?>/verify" class="sensecms-ai-provider-verify"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><button class="btn bg-default-150" type="submit" <?= empty($provider['configured'])?'disabled':'' ?>><i data-lucide="activity"></i>Test connection</button></form>
                </article>
            <?php endforeach; ?>
            <article class="sensecms-ai-provider-card is-new">
                <header><span class="sensecms-ai-provider-icon"><i data-lucide="plus"></i></span><div><strong>Add provider</strong><small>OpenAI, compatible APIs, Anthropic or Google Gemini</small></div></header>
                <form method="post" action="/ai/providers">
                    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
                    <div class="sensecms-ai-provider-fields">
                        <label><span>Name</span><input class="form-input" name="name" maxlength="120" required placeholder="Provider name"></label>
                        <label><span>Slug</span><input class="form-input" name="slug" maxlength="80" required pattern="[a-z][a-z0-9-]+" placeholder="provider-name"></label>
                        <label><span>Driver</span><select class="form-input" name="driver" required><?php foreach($aiDrivers as$key=>$driver): ?><option value="<?= $escape($key) ?>"><?= $escape($driver['label']) ?></option><?php endforeach; ?></select></label>
                        <label><span>Model</span><input class="form-input" name="default_model" maxlength="150" required placeholder="Model identifier"></label>
                        <label class="is-wide"><span>Base API URL</span><input class="form-input" type="url" name="base_url" maxlength="500" placeholder="Uses the selected driver's official default"></label>
                        <label><span>API key</span><input class="form-input" type="password" name="api_key" autocomplete="new-password" placeholder="Optional until the provider is enabled"></label>
                        <label><span>Priority</span><input class="form-input" type="number" name="priority" min="1" max="999" value="100"></label>
                    </div>
                    <?php $budgetFields(['daily_requests'=>100,'monthly_requests'=>2000,'daily_tokens'=>250000,'monthly_tokens'=>5000000,'warning_percent'=>80]); ?>
                    <div class="sensecms-ai-provider-options"><label><input type="checkbox" name="purposes[]" value="builder" checked>Page Builder</label><label><input type="checkbox" name="purposes[]" value="posts">Posts</label><label><input type="checkbox" name="purposes[]" value="chat">Visitor assistant</label><label><input type="checkbox" name="enabled" value="1">Enabled after saving</label></div>
                    <footer><span><i data-lucide="lock-keyhole"></i>You may save a disabled provider without a key and complete it later.</span><button class="btn bg-primary text-white" type="submit"><i data-lucide="plus"></i>Add provider</button></footer>
                </form>
            </article>
        </div>
    </section>
</section>
