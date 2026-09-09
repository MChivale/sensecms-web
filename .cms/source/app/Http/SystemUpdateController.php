<?php
declare(strict_types=1);
namespace App\Http;
use App\Core\ExtensionContext;
use App\Core\SystemUpdate;
use RuntimeException;
use Throwable;

final class SystemUpdateController
{
    public function __construct(private readonly ExtensionContext $context){}
    public function handle(string $method): never
    {
        $c=$this->context;
        if(!$c->auth->check()){header('Location: /login',true,302);exit;}
        $c->access->assert('system.manage');
        $service=new SystemUpdate($c->db,$c->root,$c->config);
        header('Cache-Control: no-store');
        if($method==='GET'&&!isset($_GET['status']))$c->dashboard->systemUpdate($service->status());
        header('Content-Type: application/json; charset=utf-8');
        try{
            if($method==='POST'){
                if($c->access->isDemoUser())throw new RuntimeException('Demo mode is read only. Updates cannot be installed.',403);
                if(!$c->auth->verifyCsrf($_POST['csrf']??null))throw new RuntimeException('Your session expired. Refresh and try again.',419);
                if(($_POST['action']??'')==='check')$service->requestCheck();
                elseif(($_POST['action']??'')==='install'){$c->access->assert('extensions.manage');$service->requestInstall((string)($_POST['version']??''),(int)$c->auth->id());}
                else throw new RuntimeException('Unknown update action.');
            }
            $state=$service->status();unset($state['products']);echo json_encode(['ok'=>true,'data'=>$state,'message'=>$method==='POST'?'Request queued. The worker will process it within a minute.':'Update status loaded.']);
        }catch(Throwable $error){$safe=$error instanceof RuntimeException&&!$error instanceof \PDOException;http_response_code(in_array($error->getCode(),[403,419],true)?$error->getCode():422);echo json_encode(['ok'=>false,'message'=>$safe?$error->getMessage():'Update service requires review.']);}
        exit;
    }
}
