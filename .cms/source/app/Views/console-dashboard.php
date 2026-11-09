<?php
declare(strict_types=1);

$stats = is_array($stats ?? null) ? $stats : [];
$pages = is_array($stats['pages'] ?? null) ? $stats['pages'] : [];
$posts = is_array($stats['posts'] ?? null) ? $stats['posts'] : [];
$recentContent = is_array($stats['recent_content'] ?? null) ? $stats['recent_content'] : [];
$scheduledContent = is_array($stats['scheduled_content'] ?? null) ? $stats['scheduled_content'] : [];
$workflowItems = is_array($dashboardWorkflow ?? null) ? $dashboardWorkflow : [];
$facilityItems = is_array($dashboardFacilities ?? null) ? $dashboardFacilities : [];
$mediaSummary = is_array($dashboardMedia ?? null) ? $dashboardMedia : [];
$surveySummary = is_array($dashboardSurveys ?? null) ? $dashboardSurveys : [];
$aiSummary = is_array($dashboardAi ?? null) ? $dashboardAi : [];
$contentTotal = max(0, (int) ($stats['content_total'] ?? 0));
$publishedTotal = max(0, (int) ($stats['published_total'] ?? 0));
$publishedCoverage = $contentTotal > 0 ? (int) round(($publishedTotal / $contentTotal) * 100) : 0;
$attentionTotal = (int) ($liveChatUnread ?? 0) + (int) ($formUnread ?? 0);
$firstName = trim((string) ($user['name'] ?? 'SenseCMS user'));
$firstName = preg_split('/\s+/u', $firstName, 2)[0] ?? 'there';
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$formatDate = static function (?string $value) use ($uiText, $interfaceLanguage): string {
    if (!$value) return $uiText('Not scheduled');
    $timestamp = strtotime($value);
    if (!$timestamp) return $uiText('Date unavailable');
    $today = strtotime('today');
    if ($timestamp >= $today && $timestamp < strtotime('+1 day', $today)) return $uiText('Today') . ' · ' . date('H:i', $timestamp);
    if ($timestamp >= strtotime('-1 day', $today) && $timestamp < $today) return $uiText('Yesterday') . ' · ' . date('H:i', $timestamp);
    return date($interfaceLanguage === 'pl' ? 'd.m.Y · H:i' : 'd M Y · H:i', $timestamp);
};
$formatBytes = static function (int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format($bytes / 1073741824, 1) . ' GB';
};
$statusLabel = static fn(string $status): string => match ($status) {
    'published' => 'Published', 'scheduled' => 'Scheduled', 'private' => 'Private',
    'in_review' => 'In review', 'changes_requested' => 'Changes requested', 'approved' => 'Approved',
    default => 'Draft',
};
$contentUrl = static fn(array $item): string => '/content/' . (($item['entity_type'] ?? '') === 'post' ? 'posts' : 'pages') . '/' . (int) ($item['id'] ?? 0) . '/edit';
?>
<section class="sensecms-dashboard" aria-label="<?= $escape($uiText('SenseCMS workspace overview')) ?>">
    <header class="sensecms-dashboard-hero">
        <div class="sensecms-dashboard-hero-copy">
            <h2><?= $escape($uiText($greeting)) ?>, <?= $escape($firstName) ?>.</h2>
            <p><?php if ($interfaceLanguage === 'pl'): ?>Aktywne placówki: <?= (int) ($stats['facilities'] ?? 0) ?> · Języki: <?= (int) ($stats['languages'] ?? 0) ?><?php else: ?><?= (int) ($stats['facilities'] ?? 0) ?> active <?= (int) ($stats['facilities'] ?? 0) === 1 ? 'facility' : 'facilities' ?> · <?= (int) ($stats['languages'] ?? 0) ?> languages<?php endif; ?></p>
        </div>
        <?php if ($can('content.pages.edit')): ?><a class="sensecms-dashboard-primary-action" href="/content/pages/new"><i data-lucide="plus"></i><?= $escape($uiText('Create content')) ?></a><?php endif; ?>
    </header>

    <nav class="sensecms-dashboard-quick-actions" aria-label="<?= $escape($uiText('Quick actions')) ?>">
        <?php if ($can('content.pages.edit')): ?><a href="/content/builder"><i data-lucide="panels-top-left"></i><span><strong><?= $escape($uiText('Page Builder')) ?></strong><small><?= $escape($uiText('Compose a page')) ?></small></span></a><?php endif; ?>
        <?php if ($can('content.media.manage')): ?><a href="/content/media"><i data-lucide="images"></i><span><strong><?= $escape($uiText('Media Library')) ?></strong><small><?= $escape($uiText('Browse your assets')) ?></small></span></a><?php endif; ?>
        <?php if ($can('content.seo.manage')): ?><a href="/seo"><i data-lucide="search-check"></i><span><strong><?= $escape($uiText('Review SEO')) ?></strong><small><?= $escape($uiText('Search readiness')) ?></small></span></a><?php endif; ?>
        <?php if ($can('appearance.manage')): ?><a href="/appearance"><i data-lucide="palette"></i><span><strong><?= $escape($uiText('Appearance')) ?></strong><small><?= $escape($uiText('Shape the brand')) ?></small></span></a><?php endif; ?>
    </nav>


    <div class="sensecms-dashboard-kpis">
        <?php if ($can('content.pages.view') || $can('content.posts.view')): ?>
            <a class="sensecms-dashboard-kpi" href="/content/pages">
                <span class="sensecms-dashboard-kpi-icon is-blue"><i data-lucide="globe-2"></i></span>
                <span><small><?= $escape($uiText('Live content')) ?></small><strong><?= $publishedTotal ?></strong><em><?= $escape($uiText('{count}% of active content', ['{count}' => (string) ($publishedCoverage)])) ?></em></span>
                <i data-lucide="arrow-up-right" class="sensecms-dashboard-kpi-arrow"></i>
            </a>
        <?php endif; ?>
        <?php if ($can('content.workflow.view')): ?>
            <a class="sensecms-dashboard-kpi" href="/content/workflow">
                <span class="sensecms-dashboard-kpi-icon is-amber"><i data-lucide="git-pull-request-arrow"></i></span>
                <span><small><?= $escape($uiText('Editorial attention')) ?></small><strong><?= (int) ($dashboardWorkflowCount ?? 0) ?></strong><em><?= $escape($uiText(($dashboardWorkflowCount ?? 0) ? 'Items need a decision' : 'Review queue is clear')) ?></em></span>
                <i data-lucide="arrow-up-right" class="sensecms-dashboard-kpi-arrow"></i>
            </a>
        <?php endif; ?>
        <?php if ($can('chat.view') || $can('forms.view')): ?>
            <a class="sensecms-dashboard-kpi" href="<?= $can('chat.view') ? '/conversations' : '/forms/submissions' ?>">
                <span class="sensecms-dashboard-kpi-icon is-rose"><i data-lucide="message-circle-more"></i></span>
                <span><small><?= $escape($uiText($can('chat.view') ? 'Unread live chat' : 'Unread form submissions')) ?></small><strong><?= (int) ($can('chat.view') ? ($liveChatUnread ?? 0) : ($formUnread ?? 0)) ?></strong><em><?= $escape($uiText($can('chat.view') ? 'Open conversations' : 'Open form inbox')) ?></em></span>
                <i data-lucide="arrow-up-right" class="sensecms-dashboard-kpi-arrow"></i>
            </a>
        <?php endif; ?>
        <?php if ($can('content.media.manage')): ?>
            <a class="sensecms-dashboard-kpi" href="/content/media">
                <span class="sensecms-dashboard-kpi-icon is-violet"><i data-lucide="images"></i></span>
                <span><small><?= $escape($uiText('Media library')) ?></small><strong><?= (int) ($mediaSummary['total'] ?? 0) ?></strong><em><?= $escape($uiText('{size} securely stored', ['{size}' => $formatBytes((int) ($mediaSummary['bytes'] ?? 0))])) ?></em></span>
                <i data-lucide="arrow-up-right" class="sensecms-dashboard-kpi-arrow"></i>
            </a>
        <?php endif; ?>
    </div>

    <div class="sensecms-dashboard-grid">
        <?php if ($can('content.pages.view') || $can('content.posts.view')): ?>
            <section class="sensecms-dashboard-panel sensecms-dashboard-recent">
                <header><div><h3><?= $escape($uiText('Latest content activity')) ?></h3></div></header>
                <?php if ($recentContent): ?><div class="sensecms-dashboard-recent-list"><?php foreach ($recentContent as $item): ?><a href="<?= $escape($contentUrl($item)) ?>"><span class="sensecms-dashboard-recent-kind"><i data-lucide="<?= ($item['entity_type'] ?? '') === 'post' ? 'newspaper' : 'file-text' ?>"></i></span><span><strong><?= $escape($item['title'] ?? $uiText('Untitled content')) ?></strong><small><?= $escape($item['facility_name'] ?? $uiText('Facility')) ?> · <?= $escape($formatDate($item['updated_at'] ?? null)) ?></small></span><em class="is-<?= $escape((string) ($item['status'] ?? 'draft')) ?>"><?= $escape($uiText($statusLabel((string) ($item['status'] ?? 'draft')))) ?></em></a><?php endforeach; ?></div><?php else: ?><div class="sensecms-dashboard-empty is-compact"><span><i data-lucide="file-clock"></i></span><div><strong><?= $escape($uiText('No recent content')) ?></strong><p><?= $escape($uiText('New edits will appear here.')) ?></p></div></div><?php endif; ?>
            </section>


        <?php endif; ?>
        <?php if ($can('content.workflow.view')): ?>
            <section class="sensecms-dashboard-panel sensecms-dashboard-workflow">
                <header><div><h3><?= $escape($uiText('Work requiring attention')) ?></h3></div><a href="/content/workflow"><?= $escape($uiText('Open workflow')) ?><i data-lucide="arrow-right"></i></a></header>
                <?php if ($workflowItems): ?>
                    <div class="sensecms-dashboard-list">
                        <?php foreach ($workflowItems as $item): ?>
                            <a href="<?= $escape($contentUrl($item)) ?>" class="sensecms-dashboard-list-row">
                                <span class="sensecms-dashboard-list-icon"><i data-lucide="<?= ($item['entity_type'] ?? '') === 'post' ? 'newspaper' : 'file-text' ?>"></i></span>
                                <span class="sensecms-dashboard-list-copy"><strong><?= $escape($item['title'] ?? $uiText('Untitled content')) ?></strong><small><?= $escape($item['facility_name'] ?? $uiText('Facility')) ?> · <?= $escape($item['assigned_name'] ?? $uiText('Review team')) ?></small></span>
                                <span class="sensecms-dashboard-status is-<?= $escape(str_replace('_', '-', (string) ($item['workflow_state'] ?? 'draft'))) ?>"><?= $escape($uiText($statusLabel((string) ($item['workflow_state'] ?? 'draft')))) ?></span>
                                <i data-lucide="chevron-right" class="sensecms-dashboard-row-arrow"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="sensecms-dashboard-empty is-compact"><span><i data-lucide="circle-check-big"></i></span><div><strong><?= $escape($uiText('Editorial queue is clear')) ?></strong><p><?= $escape($uiText('There are no reviews or publication decisions waiting for you.')) ?></p></div></div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($can('chat.view') || $can('forms.view') || $can('surveys.view')): ?>
        <aside class="sensecms-dashboard-panel sensecms-dashboard-communication">
            <header><div><h3><?= $escape($uiText('Your community')) ?></h3></div><?php if ($can('chat.view') || $can('forms.view')): ?><span class="sensecms-dashboard-attention <?= $attentionTotal ? 'has-items' : '' ?>"><?= $escape($uiText('{count} unread', ['{count}' => (string) ($attentionTotal)])) ?></span><?php endif; ?></header>
            <div class="sensecms-dashboard-channel-list">
                <?php if ($can('chat.view')): ?><a href="/conversations"><span class="is-chat"><i data-lucide="messages-square"></i></span><div><strong><?= $escape($uiText('Live chat')) ?></strong><small><?= $escape($can('ai.manage') ? $uiText('{count} active conversations', ['{count}' => (int) ($aiSummary['open_chats'] ?? 0)]) : $uiText('Open conversations')) ?></small></div><b aria-label="<?= $escape($uiText('{count} unread', ['{count}' => (string) ($liveChatUnread ?? 0)])) ?>"><?= (int) ($liveChatUnread ?? 0) ?></b><i data-lucide="chevron-right"></i></a><?php endif; ?>
                <?php if ($can('forms.view')): ?><a href="/forms/submissions"><span class="is-form"><i data-lucide="inbox"></i></span><div><strong><?= $escape($uiText('Form inbox')) ?></strong><small><?= $escape($uiText('{count} retained submissions', ['{count}' => (string) ($stats['forms']['all'] ?? 0)])) ?></small></div><b><?= (int) ($formUnread ?? 0) ?></b><i data-lucide="chevron-right"></i></a><?php endif; ?>
                <?php if ($can('surveys.view')): ?><a href="/surveys"><span class="is-survey"><i data-lucide="clipboard-check"></i></span><div><strong><?= $escape($uiText('Professional Surveys')) ?></strong><small><?= $escape($uiText('{count} completed responses', ['{count}' => (string) ($surveySummary['responses'] ?? 0)])) ?><?= $can('surveys.responses') ? ' · ' . $escape($uiText('{count} in the last 24 hours', ['{count}' => (int) ($surveyUnread ?? 0)])) : '' ?></small></div><b aria-hidden="true"><?= $can('surveys.responses') ? (int) ($surveyUnread ?? 0) : '–' ?></b><i data-lucide="chevron-right"></i></a><?php endif; ?>
            </div>
        </aside>
        <?php endif; ?>



        <?php if ($can('content.pages.view') || $can('content.posts.view')): ?>
            <section class="sensecms-dashboard-panel sensecms-dashboard-content-health">
                <header><div><h3><?= $escape($uiText('Publishing balance')) ?></h3></div><span class="sensecms-dashboard-score" role="progressbar" aria-label="<?= $escape($uiText('Publishing balance')) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $publishedCoverage ?>"><?= $publishedCoverage ?>%</span></header>
                <div class="sensecms-dashboard-bars">
                    <?php foreach ([['Published',$publishedTotal,'is-published'],['Drafts',(int)($stats['draft_total']??0),'is-draft'],['Scheduled',(int)($stats['scheduled_total']??0),'is-scheduled']] as [$label,$value,$class]): $width=$contentTotal?max(3,(int)round($value/$contentTotal*100)):0; ?>
                        <div><span><strong><?= $escape($uiText($label)) ?></strong><b><?= $value ?></b></span><i><em class="<?= $class ?>" style="width:<?= $width ?>%"></em></i></div>
                    <?php endforeach; ?>
                </div>
                <div class="sensecms-dashboard-content-split"><span><i data-lucide="files"></i><small><?= $escape($uiText('Pages')) ?></small><strong><?= array_sum(array_intersect_key($pages, array_flip(['draft','published','private','scheduled']))) ?></strong></span><span><i data-lucide="newspaper"></i><small><?= $escape($uiText('Posts')) ?></small><strong><?= array_sum(array_intersect_key($posts, array_flip(['draft','published','scheduled']))) ?></strong></span><span><i data-lucide="calendar-clock"></i><small><?= $escape($uiText('Scheduled content')) ?></small><strong><?= (int) ($stats['scheduled_total'] ?? 0) ?></strong></span></div>
            </section>
        <?php endif; ?>

        <?php if ($can('facilities.view')): ?>
            <section class="sensecms-dashboard-panel sensecms-dashboard-facilities">
                <header><div><h3><?= $escape($uiText('Your facility network')) ?></h3></div><a href="/content/facilities"><?= $escape($uiText('Manage')) ?><i data-lucide="arrow-right"></i></a></header>
                <div class="sensecms-dashboard-facility-list">
                    <?php foreach (array_slice($facilityItems, 0, 4) as $facility): ?><a href="/content/facilities?edit=<?= (int) $facility['id'] ?>"><span class="sensecms-dashboard-facility-marker"><i data-lucide="building-2"></i></span><span><strong><?= $escape($facility['name'] ?? $uiText('Facility')) ?></strong><small><?= $escape($facility['city_name'] ?? $facility['city_slug'] ?? '') ?> · <?= $escape($uiText('{count} pages', ['{count}' => (string) ($facility['page_count'] ?? 0)])) ?> · <?= $escape($uiText('{count} posts', ['{count}' => (string) ($facility['post_count'] ?? 0)])) ?></small></span><em class="<?= ($facility['status'] ?? '') === 'active' ? 'is-active' : '' ?>"><?= $escape($uiText(ucfirst((string) ($facility['status'] ?? 'draft')))) ?></em></a><?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <aside class="sensecms-dashboard-panel sensecms-dashboard-readiness">
            <header><div><h3><?= $escape($uiText('Sense CMS installation')) ?></h3></div></header>
            <div class="sensecms-dashboard-readiness-list">
                <div><span><i data-lucide="languages"></i><?= $escape($uiText('Active languages')) ?></span><strong><?= (int) ($stats['languages'] ?? 0) ?></strong></div>
                <div><span><i data-lucide="panels-top-left"></i><?= $escape($uiText('Active theme')) ?></span><strong><?= (int) ($stats['themes'] ?? 0) ?></strong></div>
                <div><span><i data-lucide="blocks"></i><?= $escape($uiText('Active extensions')) ?></span><strong><?= (int) ($stats['plugins'] ?? 0) ?></strong></div>
                <div><span><i data-lucide="package-check"></i><?= $escape($uiText('Package updates')) ?></span><strong class="<?= (int) ($packageUpdates ?? 0) ? 'is-warning' : 'is-ok' ?>"><?= $escape($uiText('{count} available', ['{count}' => (string) ($packageUpdates ?? 0)])) ?></strong></div>
                <?php if ($can('system.manage')): ?><div><span><i data-lucide="badge-check"></i><?= $escape($uiText('License')) ?></span><a href="/license"><?= $escape($uiText('Review license')) ?></a></div><?php endif; ?>
            </div>
        </aside>
    </div>

</section>
