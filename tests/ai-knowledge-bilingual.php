<?php

declare(strict_types=1);

$script=(string)file_get_contents(dirname(__DIR__).'/scripts/seed-ai-knowledge-bilingual.php');
if(!str_contains($script,"'en'=>['Sense CMS product and support overview'")||!str_contains($script,"'pl'=>['Sense CMS — informacje o produkcie i wsparciu'"))throw new RuntimeException('Bilingual RAG support sources are incomplete.');
if(!str_contains($script,'saveManual($id,$title,$body,$locale,true,$owner)')||str_contains($script,'AiProvider'))throw new RuntimeException('Bilingual RAG sources must be local and approved without a provider request.');
echo "Bilingual AI Knowledge checks passed: 2.\n";
