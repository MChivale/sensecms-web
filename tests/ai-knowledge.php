<?php

declare(strict_types=1);

$root=dirname(__DIR__);$checks=0;
$assert=static function(bool$condition,string$message)use(&$checks):void{if(!$condition)throw new RuntimeException('FAIL '.$message);$checks++;echo "PASS {$message}\n";};
$read=static fn(string$path):string=>(string)file_get_contents($root.'/'.$path);

$migration=$read('.cms/source/database/workspace/040_ai_knowledge_base.sql');
$assert(str_contains($migration,'ai_knowledge_enabled TINYINT(1) NOT NULL DEFAULT 1'),'pages and posts default to Knowledge Base inclusion');
$assert(str_contains($migration,'ai_training_datasets')&&str_contains($migration,'ai_training_examples')&&str_contains($migration,'ai_training_jobs'),'training data is versioned separately from RAG sources');
$assert(str_contains($migration,'ai_evaluation_cases'),'evaluation cases are represented in Core');
$assert(str_contains($migration,'storage_path')&&str_contains($migration,'checksum')&&str_contains($migration,'content_checksum')&&str_contains($migration,'index_status'),'knowledge documents retain private storage and index integrity metadata');

$service=$read('.cms/source/app/Core/AiKnowledgeBase.php');
$assert(str_contains($service,"storage/ai-knowledge")&&!str_contains($service,"/public/ai-knowledge"),'uploaded originals stay outside the public web root');
$assert(str_contains($service,'self::MAX_FILE_BYTES')&&str_contains($service,'new \\finfo(FILEINFO_MIME_TYPE)'),'file uploads are size and MIME validated');
$assert(str_contains($service,"'txt'")&&str_contains($service,"'pdf'")&&str_contains($service,"'rtf'")&&str_contains($service,"'doc'")&&str_contains($service,"'docx'"),'requested document formats have local extraction paths');
$assert(str_contains($service,"['pdftotext']")&&str_contains($service,"'antiword'")&&str_contains($service,"'soffice'"),'binary document extraction is local and explicit');
$assert(str_contains($service,"['bypass_shell'=>true]")&&str_contains($service,'proc_open($command'),'document conversion bypasses the command shell');
$assert(str_contains($service,"status='published'")&&str_contains($service,"visibility='public'")&&str_contains($service,'published_at<=NOW()'),'website sync excludes unpublished, private and future content');
$assert(str_contains($service,"source_type IN ('page','post')")&&str_contains($service,'ai_knowledge_enabled=1'),'website sources are synchronized and removable through inclusion settings');
$assert(str_contains($service,'DELETE FROM ai_knowledge_chunks')&&str_contains($service,"index_status=\"ready\""),'reindexing replaces stale chunks atomically');
$assert(str_contains($service,'Raw documents')===false,'Core service does not claim that raw documents are training examples');
$assert(str_contains($service,'A ready dataset needs at least 10 examples')&&str_contains($service,"['draft','ready']"),'training datasets require reviewed examples and reject edits to locked states');

$repository=$read('.cms/source/app/Core/AiRepository.php');
$assert(str_contains($repository,"d.status='published'")&&str_contains($repository,"d.index_status='ready'"),'visitor retrieval only reads approved ready sources');
$assert(str_contains($repository,'relevance DESC')&&str_contains($repository,'[Source: '),'retrieval ranks chunks and retains source attribution');

$workspace=$read('.cms/source/app/workspace.php');
$assert(str_contains($workspace,"'/ai/knowledge'")&&str_contains($workspace,'saveKnowledgeDocument')&&str_contains($workspace,'rebuildKnowledgeBase'),'Knowledge Base routes are registered in Core');
$assert(str_contains($workspace,"'/ai/knowledge/training/datasets'")&&str_contains($workspace,'knowledgeTrainingAction'),'training workspace routes are registered in Core');
$access=$read('.cms/source/app/Core/AccessControl.php');
$assert(str_contains($access,"str_starts_with(\$path,'/ai/knowledge')")&&str_contains($access,"return'ai.manage'"),'Knowledge Base routes require AI management permission');

$controller=$read('.cms/source/app/Http/DashboardController.php');
$assert(str_contains($controller,'function knowledgeBase')&&str_contains($controller,'function saveKnowledgeSource'),'Knowledge Base screen and source controls are wired');
$assert(str_contains($controller,"'ai_knowledge_enabled'=>isset(\$_POST['ai_knowledge_enabled'])"),'post inclusion is persisted from an explicit editor control');
$view=$read('.cms/source/app/Views/console-ai-knowledge.php');
$assert(str_contains($view,'Knowledge Base')&&str_contains($view,'RAG index')&&str_contains($view,'Training & evals'),'Knowledge Base exposes sources, RAG and training readiness');
$assert(str_contains($view,'No API request or charge has been made.')&&str_contains($view,'Start fine-tuning')&&str_contains($view,'disabled'),'paid fine-tuning remains explicitly disabled');
$assert(str_contains($view,'Curated examples')&&str_contains($view,'Evaluation cases')&&str_contains($view,'/ai/knowledge/training/examples'),'training data can be curated and evaluated without starting a paid job');
$assert(str_contains($view,'TXT, PDF, RTF, DOC or DOCX'),'upload UI documents every supported source format');
$post=$read('.cms/source/app/Views/console-content-post-form.php');
$assert(str_contains($post,'Include in Knowledge Base')&&str_contains($post,"!array_key_exists('ai_knowledge_enabled'"),'new posts are included by default and editors can opt out');
$js=$read('.cms/source/public/theme/sensecms-ai-knowledge.js');
$assert(str_contains($js,"'/ai/knowledge/sources'")&&str_contains($js,"'/ai/knowledge/rebuild'"),'source controls and rebuild use JSON requests');
$assert(str_contains($js,'Delete knowledge source')&&str_contains($js,'SenseCMSUI?.modal'),'permanent deletion uses the existing confirmation modal');

echo "AI Knowledge Base checks passed: {$checks}.\n";
