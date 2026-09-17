<?php

declare(strict_types=1);

namespace SenseCMS\Social;

use App\Core\ExtensionContext;
use RuntimeException;

final class SocialController
{
    private SocialIntegrationManager $integrations;
    private SocialRepository $repository;

    public function __construct(private readonly ExtensionContext $context, private readonly string $root)
    {
        $this->integrations = new SocialIntegrationManager($context->db, $context->root, (string)($context->config['secrets_key']??''));
        $this->repository = new SocialRepository($context->db, $this->integrations, (string)($context->config['base_url']??''));
    }

    public function page(): never
    {
        $this->context->access->assert('social.view');
        $this->context->dashboard->extensionPage('Social Publishing', $this->root . '/views/social.php', [
            'socialPublishing'=>$this->repository->overview(),
            'socialCanPublish'=>$this->context->access->allows('social.publish'),
            'socialCanManage'=>$this->context->access->allows('social.settings.manage'),
            'csrf'=>$this->context->auth->csrf(),
            'extensionActive'=>'/social-publishing',
            'extensionStyles'=>['/extension-assets/addon/social-publishing/social.css?v=0.2.0'],
        ]);
    }

    public function editor(): never
    {
        $this->context->access->assert('social.publish');
        $postId=max(0,(int)($_GET['post_id']??0));
        if ($postId) {
            $statement=$this->context->db->prepare('SELECT facility_id FROM posts WHERE id=?');$statement->execute([$postId]);$facility=$statement->fetchColumn();
            if (!$facility) $this->json(false,'The post was not found.',null,404);
            $this->context->access->assert('content.posts.edit',(int)$facility);
        }
        $this->json(true,'Social publishing destinations loaded.',$this->repository->editorState($postId));
    }

    public function deliveries(): never
    {
        $this->context->access->assert('social.view');
        $postId=max(0,(int)($_GET['post_id']??0));
        $this->json(true,'Social delivery history loaded.',['deliveries'=>$this->repository->deliveries(100,$postId?:null)]);
    }

    public function retry(int $id): never
    {
        $this->context->access->assert('social.publish');
        if (!$this->context->auth->verifyCsrf($_POST['csrf']??null)) $this->json(false,'Your session token is invalid. Refresh and try again.',null,419);
        if (!$this->repository->retry($id)) $this->json(false,'Only failed deliveries can be retried.',null,409);
        $this->json(true,'The delivery was queued for another attempt.',['id'=>$id]);
    }

    private function json(bool $ok,string $message,?array $data=null,int $status=200): never
    {
        http_response_code($status);header('Cache-Control: no-store');header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>$ok,'message'=>$message,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;
    }
}
