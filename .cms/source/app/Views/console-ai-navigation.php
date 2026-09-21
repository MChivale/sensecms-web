<?php $aiActive=(string)($aiActive??'providers'); ?>
<nav class="sensecms-ai-navigation" aria-label="AI workspace">
    <a href="/ai" class="<?= $aiActive==='providers'?'is-active':'' ?>"><i data-lucide="cpu"></i><span><b>Providers</b><small>Models, credentials and budgets</small></span></a>
    <a href="/ai/knowledge" class="<?= $aiActive==='knowledge'?'is-active':'' ?>"><i data-lucide="brain-circuit"></i><span><b>Knowledge Base</b><small>RAG sources, indexing and training readiness</small></span></a>
    <a href="/conversations/configuration"><i data-lucide="bot-message-square"></i><span><b>Visitor assistant</b><small>Widget, routing and human handoff</small></span></a>
</nav>
