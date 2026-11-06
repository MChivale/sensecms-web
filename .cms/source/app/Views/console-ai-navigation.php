<?php $aiActive=(string)($aiActive??'providers'); ?>
<nav class="sensecms-ai-navigation sensecms-section-tabs" aria-label="<?= $escape($uiText('AI workspace')) ?>">
    <a href="/ai" class="<?= $aiActive==='providers'?'is-active':'' ?>" aria-current="<?= $aiActive==='providers'?'page':'false' ?>"><i data-lucide="cpu"></i><span><b><?= $escape($uiText('Providers')) ?></b><small><?= $escape($uiText('Models, credentials and budgets')) ?></small></span></a>
    <a href="/ai/knowledge" class="<?= $aiActive==='knowledge'?'is-active':'' ?>" aria-current="<?= $aiActive==='knowledge'?'page':'false' ?>"><i data-lucide="brain-circuit"></i><span><b><?= $escape($uiText('Knowledge Base')) ?></b><small><?= $escape($uiText('RAG sources, indexing and training readiness')) ?></small></span></a>
    <?php if($can('chat.manage')): ?><a href="/conversations/configuration?from=ai" class="<?= $aiActive==='assistant'?'is-active':'' ?>" aria-current="<?= $aiActive==='assistant'?'page':'false' ?>"><i data-lucide="bot-message-square"></i><span><b><?= $escape($uiText('Visitor assistant')) ?></b><small><?= $escape($uiText('Widget, routing and human handoff')) ?></small></span></a><?php endif; ?>
</nav>
