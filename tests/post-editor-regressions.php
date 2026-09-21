<?php
declare(strict_types=1);

require dirname(__DIR__).'/.cms/source/bootstrap.php';

use App\Core\HtmlSanitizer;

$count=0;$check=static function(bool$ok,string$label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;echo"PASS $label\n";};
$clean=HtmlSanitizer::sanitize('<p>Story</p><audio autoplay controls src="/media/library/a.mp3"></audio><video autoplay src="https://cdn.example.test/v.mp4" poster="javascript:alert(1)"></video><script>alert(1)</script>');
$check(str_contains($clean,'<audio')&&str_contains($clean,'controls="controls"')&&str_contains($clean,'preload="metadata"'),'sanitizer preserves controlled audio');
$check(str_contains($clean,'<video')&&str_contains($clean,'https://cdn.example.test/v.mp4'),'sanitizer preserves HTTPS video');
$check(!str_contains($clean,'autoplay')&&!str_contains($clean,'javascript:')&&!str_contains($clean,'<script'),'sanitizer removes autoplay and executable content');
$migration=(string)file_get_contents(dirname(__DIR__).'/.cms/source/database/workspace/031_post_editor_media.sql');
$check(str_contains($migration,'audio_media_id')&&str_contains($migration,'video_media_id'),'migration stores independent audio and video');
$check(str_contains($migration,'post_tags')&&str_contains($migration,'post_tag_map'),'migration stores localized reusable tags');
$view=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Views/console-content-post-form.php');
$check(!str_contains($view,'Editorial guidance')&&strpos($view,'data-sidebar-section="publication"')<strpos($view,'data-sidebar-section="media"'),'post sidebar has the approved structure');
$check(!str_contains($view,'form method="dialog"')&&str_contains($view,'data-media-picker-close'),'media picker closes without submitting the post form');
$check(str_contains($view,'data-media-picker-search')&&str_contains($view,'data-media-picker-folders')&&str_contains($view,'data-media-folder-create'),'media picker exposes search, folders and creation controls');
$console=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Views/console.php');
$check(str_contains($console,'/assets/lib/quill/quill.js')&&!str_contains($console,'cdn.quilljs.com'),'post editor uses only the self-hosted Core Quill bundle');
$postEditor=(string)file_get_contents(dirname(__DIR__).'/.cms/source/public/theme/sensecms-post-editor.js');
$workflow=(string)file_get_contents(dirname(__DIR__).'/.cms/source/public/theme/sensecms-editor-workflow.js');
$management=(string)file_get_contents(dirname(__DIR__).'/.cms/source/public/theme/sensecms-content-management.js');
$style=(string)file_get_contents(dirname(__DIR__).'/.cms/source/public/theme/sensecms-content-management.css');
$check(str_contains($postEditor,'sensecms:content-ready')&&str_contains($postEditor,'postEditorBound'),'post editor rehydrates once after AJAX navigation');
$check(str_contains($workflow,'sensecms:content-ready')&&str_contains($workflow,'workflowBound'),'editorial workflow rehydrates once after AJAX navigation');
$check(str_contains($management,"postEditor().then")||str_contains($management,'.then(postEditor).then(pageBuilder).then(hydrate)')||str_contains($management,'.then(postEditor).then(hydrate)'),'content navigation loads page-specific editor assets before hydration');
$check(str_contains($style,'.ql-container{height:auto;min-height:330px'),'rich editor participates in layout without overlapping SEO fields');
$check(str_contains($postEditor,'sensecms-tag-chip')&&str_contains($postEditor,"event.key==='Enter'||event.key===','"),'post keywords become removable chips on comma or Enter');
$check(str_contains($postEditor,"value.addEventListener('change'")&&str_contains($postEditor,'tags=parse(value.value)'),'external reviewed proposals refresh visible keyword chips');
$check(str_contains($postEditor,'data-media-folder-save')&&str_contains($postEditor,'/trash'),'post media workspace supports upload, folders and protected trash actions');
$check(str_contains($style,'width:min(1180px')&&str_contains($style,'.sensecms-media-picker-workspace'),'post media workspace is large, centered and responsive');
$media=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Core/MediaLibrary.php');
$check(str_contains($media,'p.featured_media_id=? OR p.audio_media_id=? OR p.video_media_id=?'),'media usage protects every post media role');
$editor=(string)file_get_contents(dirname(__DIR__).'/.addons/social-publishing/assets/editor.js');
$check(str_contains($editor,'social-editor-provider-toggle')&&str_contains($editor,'data-media-distribution'),'social destinations use an accordion and shared media controls');
$check(str_contains($editor,'sensecms:content-ready')&&str_contains($editor,'socialEditorBound'),'social destinations rehydrate once on existing-post editing');
$repository=(string)file_get_contents(dirname(__DIR__).'/.addons/social-publishing/src/SocialRepository.php');
$check(str_contains($repository,"'whatsapp-publisher'")&&str_contains($repository,"'instagram-publisher'")&&str_contains($repository,"'threads-publisher'"),'planned destinations remain visible but inactive');
$theme=(string)file_get_contents(dirname(__DIR__).'/.themes/sensecms/views/post.php');
$check(str_contains($theme,'Listen to recording')&&str_contains($theme,'Odsłuchaj nagranie')&&str_contains($theme,'收听录音')&&str_contains($theme,'ស្តាប់សំឡេង')&&str_contains($theme,'data-post-player-dialog'),'public post exposes localized media player actions');
echo"$count post editor regression checks passed.\n";
