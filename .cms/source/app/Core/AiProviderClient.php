<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class AiProviderClient
{
    public static function drivers():array
    {
        return[
            'openai-responses'=>['label'=>'OpenAI Responses API','default_url'=>'https://api.openai.com/v1','description'=>'OpenAI native Responses API.'],
            'openai-compatible'=>['label'=>'OpenAI-compatible API','default_url'=>'','description'=>'Compatible services using POST /chat/completions.'],
            'anthropic'=>['label'=>'Anthropic Messages API','default_url'=>'https://api.anthropic.com','description'=>'Claude models through the native Messages API.'],
            'google-gemini'=>['label'=>'Google Gemini API','default_url'=>'https://generativelanguage.googleapis.com/v1beta','description'=>'Gemini models through generateContent.'],
        ];
    }

    public static function driver(string$value):string{return$value==='openai'?'openai-compatible':(isset(self::drivers()[$value])?$value:'');}

    public function generate(array$provider,string$key,string$system,string$user,bool$json=true,int$maxTokens=2400):array
    {
        $driver=self::driver((string)($provider['driver']??''));if($driver==='')throw new RuntimeException('The configured AI driver is not supported.');
        $model=mb_substr(trim((string)($provider['default_model']??'')),0,150);if($model===''||!preg_match('/^[A-Za-z0-9._:\/-]+$/',$model))throw new RuntimeException('The AI model identifier is invalid.');
        $base=rtrim(trim((string)($provider['base_url']??'')),'/');if($base==='')$base=(string)(self::drivers()[$driver]['default_url']??'');
        $maxTokens=max(16,min(8000,$maxTokens));$headers=['Content-Type: application/json'];
        if($driver==='openai-responses'){$url=$base.'/responses';$headers[]='Authorization: Bearer '.$key;$payload=['model'=>$model,'instructions'=>$system,'input'=>$user,'max_output_tokens'=>$maxTokens];}
        elseif($driver==='openai-compatible'){$url=$base.'/chat/completions';$headers[]='Authorization: Bearer '.$key;$payload=['model'=>$model,'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]],'temperature'=>0.2,'max_tokens'=>$maxTokens];}
        elseif($driver==='anthropic'){$url=$base.'/v1/messages';$headers[]='x-api-key: '.$key;$headers[]='anthropic-version: 2023-06-01';$payload=['model'=>$model,'system'=>$system,'messages'=>[['role'=>'user','content'=>$user]],'max_tokens'=>$maxTokens,'temperature'=>0.2];}
        else{$url=$base.'/models/'.rawurlencode($model).':generateContent';$headers[]='x-goog-api-key: '.$key;$payload=['systemInstruction'=>['parts'=>[['text'=>$system]]],'contents'=>[['role'=>'user','parts'=>[['text'=>$user]]]],'generationConfig'=>['temperature'=>0.2,'maxOutputTokens'=>$maxTokens]+($json?['responseMimeType'=>'application/json']:[])];}
        $response=$this->request($url,$headers,$payload);$body=$response['body'];
        $text=match($driver){
            'openai-responses'=>$this->openAiResponseText($body),
            'openai-compatible'=>(string)($body['choices'][0]['message']['content']??''),
            'anthropic'=>(string)($body['content'][0]['text']??''),
            default=>(string)($body['candidates'][0]['content']['parts'][0]['text']??''),
        };
        $text=trim($text);if($text==='')throw new RuntimeException('The AI provider returned an empty response.');
        $usage=match($driver){
            'openai-responses'=>['input'=>(int)($body['usage']['input_tokens']??0),'output'=>(int)($body['usage']['output_tokens']??0)],
            'openai-compatible'=>['input'=>(int)($body['usage']['prompt_tokens']??0),'output'=>(int)($body['usage']['completion_tokens']??0)],
            'anthropic'=>['input'=>(int)($body['usage']['input_tokens']??0),'output'=>(int)($body['usage']['output_tokens']??0)],
            default=>['input'=>(int)($body['usageMetadata']['promptTokenCount']??0),'output'=>(int)($body['usageMetadata']['candidatesTokenCount']??0)],
        };
        return['text'=>$text,'usage'=>$usage];
    }

    private function request(string$url,array$headers,array$payload):array
    {
        $parts=parse_url($url);if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new RuntimeException('AI providers must use a valid HTTPS endpoint.');
        $host=strtolower((string)$parts['host']);$records=dns_get_record($host,DNS_A);$addresses=array_values(array_unique(array_filter(array_column($records?:[],'ip'))));if(!$addresses)throw new RuntimeException('The AI provider host could not be resolved.');
        foreach($addresses as$address)if(!filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('The AI provider endpoint cannot resolve to a private or reserved address.');
        $encoded=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(strlen($encoded)>262144)throw new RuntimeException('The AI request is too large.');
        $buffer='';$port=(int)($parts['port']??443);$handle=curl_init($url);if($handle===false)throw new RuntimeException('The AI provider request could not be initialized.');
        curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$encoded,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>55,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_RESOLVE=>[$host.':'.$port.':'.$addresses[0]],CURLOPT_USERAGENT=>'SenseCMS-AI/1.0',CURLOPT_WRITEFUNCTION=>static function($_handle,string$chunk)use(&$buffer):int{if(strlen($buffer)+strlen($chunk)>2097152)return 0;$buffer.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($handle);$code=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);$error=curl_error($handle);curl_close($handle);
        if($ok===false)throw new RuntimeException('The AI provider request failed'.($error!==''?': '.$error:'.'));
        if($code===429)throw new RuntimeException('The AI provider rate limit or quota was reached.',429);
        if($code<200||$code>=300)throw new RuntimeException('The AI provider rejected the request (HTTP '.$code.').');
        $body=json_decode($buffer,true);if(!is_array($body))throw new RuntimeException('The AI provider returned an invalid response.');return['body'=>$body,'code'=>$code];
    }

    private function openAiResponseText(array$body):string
    {
        if(is_string($body['output_text']??null))return$body['output_text'];$text='';foreach((array)($body['output']??[])as$item)foreach((array)($item['content']??[])as$part)if(($part['type']??'')==='output_text')$text.=(string)($part['text']??'');return$text;
    }
}
