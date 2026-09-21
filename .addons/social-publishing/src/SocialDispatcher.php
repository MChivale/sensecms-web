<?php

declare(strict_types=1);

namespace SenseCMS\Social;

use PDO;
use RuntimeException;
use Throwable;

final class SocialDispatcher
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly PDO $db, private readonly SocialIntegrationManager $integrations, private readonly SocialRepository $repository) {}

    public function run(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        $queued = $this->repository->queueDue($limit * 2);
        $this->db->exec("UPDATE social_deliveries SET status='failed',locked_at=NULL,last_error='Publishing destination is no longer connected.',updated_at=UTC_TIMESTAMP() WHERE connection_id IS NULL AND status IN ('pending','processing')");
        $this->db->exec("UPDATE social_deliveries SET status='failed',locked_at=NULL,last_error='Delivery worker timed out.',updated_at=UTC_TIMESTAMP() WHERE status='processing' AND locked_at IS NOT NULL AND locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)");
        $this->db->exec("UPDATE social_deliveries SET status='failed',locked_at=NULL,last_error='The social network did not finish processing within 24 hours.',updated_at=UTC_TIMESTAMP() WHERE status='processing' AND external_id IS NOT NULL AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)");
        $result = ['queued'=>$queued,'published'=>0,'failed'=>0];
        for($i=0;$i<min(10,$limit);$i++){$delivery=$this->claimProcessing();if(!$delivery)break;try{$runtime=$this->integrations->active((string)$delivery['plugin_slug'],(int)$delivery['connection_id']);$provider=$runtime['provider'];if(!method_exists($provider,'status'))throw new RuntimeException('The provider cannot inspect its pending publication.');$state=$provider->status($runtime['credentials'],(string)$delivery['external_id']);if(!is_array($state)||!in_array((string)($state['status']??''),['processing','published','failed'],true))throw new RuntimeException('The provider returned an invalid publication status.');if(is_array($state['credentials']??null))$this->integrations->updateCredentials((string)$delivery['plugin_slug'],(int)$delivery['connection_id'],$state['credentials']);$status=(string)$state['status'];$url=trim((string)($state['external_url']??''));if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||!str_starts_with(strtolower($url),'https://')))throw new RuntimeException('The provider returned an invalid publication URL.');if($status==='processing'){$this->db->prepare("UPDATE social_deliveries SET available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 SECOND),locked_at=NULL,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='processing'")->execute([$delivery['id']]);continue;}if($status==='failed'){$message=mb_substr(trim((string)($state['error']??''))?:'The social network rejected this publication.',0,300);$this->db->prepare("UPDATE social_deliveries SET status='failed',locked_at=NULL,last_error=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='processing'")->execute([$message,$delivery['id']]);$result['failed']++;continue;}$this->db->prepare("UPDATE social_deliveries SET status='published',external_url=?,last_error=NULL,locked_at=NULL,published_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND status='processing'")->execute([$url?:null,$delivery['id']]);$result['published']++;}catch(Throwable$error){$this->db->prepare("UPDATE social_deliveries SET available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),locked_at=NULL,last_error=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='processing'")->execute([$this->safeError($error),$delivery['id']]);error_log('Social publication status check failed: '.get_class($error));}}
        for ($i=0; $i<$limit; $i++) {
            $delivery = $this->claim();
            if (!$delivery) break;
            try {
                $runtime = $this->integrations->active((string)$delivery['plugin_slug'], (int)$delivery['connection_id']);
                $payload = json_decode((string)$delivery['payload'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) throw new RuntimeException('The delivery payload is invalid.');
                $published = $runtime['provider']->publish($runtime['credentials'], $payload);
                if (!is_array($published) || !preg_match('/^[A-Za-z0-9._:-]{1,255}$/D', (string)($published['external_id']??''))) throw new RuntimeException('The provider returned an invalid publication identifier.');
                if(is_array($published['credentials']??null))$this->integrations->updateCredentials((string)$delivery['plugin_slug'],(int)$delivery['connection_id'],$published['credentials']);
                $url = trim((string)($published['external_url']??''));
                if ($url !== '' && (!filter_var($url,FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url),'https://'))) throw new RuntimeException('The provider returned an invalid publication URL.');
                $providerStatus=(string)($published['status']??'published');if(!in_array($providerStatus,['processing','published'],true))throw new RuntimeException('The provider returned an invalid publication status.');
                if($providerStatus==='processing'){$this->db->prepare("UPDATE social_deliveries SET status='processing',external_id=?,external_url=?,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 SECOND),last_error=NULL,locked_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='processing'")->execute([(string)$published['external_id'],$url?:null,$delivery['id']]);}else{$this->db->prepare("UPDATE social_deliveries SET status='published',external_id=?,external_url=?,last_error=NULL,locked_at=NULL,published_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND status='processing'")->execute([(string)$published['external_id'],$url?:null,$delivery['id']]);$result['published']++;}
            } catch (Throwable $error) {
                $attempts = (int)$delivery['attempts'];
                $terminal = $attempts >= self::MAX_ATTEMPTS;
                $delay = min(3600, 30 * (2 ** max(0, $attempts - 1)));
                $message = $this->safeError($error);
                $this->db->prepare("UPDATE social_deliveries SET status=?,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),locked_at=NULL,last_error=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='processing'")->execute([$terminal?'failed':'pending',$delay,$message,$delivery['id']]);
                $result['failed']++;
                error_log('Social delivery failed: ' . get_class($error));
            }
        }
        return $result;
    }

    private function claim(): ?array
    {
        $this->db->beginTransaction();
        try {
            $row = $this->db->query("SELECT id,plugin_slug,connection_id,payload,attempts FROM social_deliveries WHERE status IN ('pending','failed') AND connection_id IS NOT NULL AND attempts<".self::MAX_ATTEMPTS." AND available_at<=UTC_TIMESTAMP() ORDER BY id LIMIT 1 FOR UPDATE")->fetch();
            if (!$row) { $this->db->commit(); return null; }
            $statement = $this->db->prepare("UPDATE social_deliveries SET status='processing',attempts=attempts+1,locked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND status IN ('pending','failed')");
            $statement->execute([$row['id']]);
            if (!$statement->rowCount()) { $this->db->rollBack(); return null; }
            $row['attempts'] = (int)$row['attempts'] + 1;
            $this->db->commit();
            return $row;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function claimProcessing():?array
    {
        $this->db->beginTransaction();try{$row=$this->db->query("SELECT id,plugin_slug,connection_id,external_id FROM social_deliveries WHERE status='processing' AND external_id IS NOT NULL AND locked_at IS NULL AND available_at<=UTC_TIMESTAMP() ORDER BY id LIMIT 1 FOR UPDATE")->fetch();if(!$row){$this->db->commit();return null;}$statement=$this->db->prepare("UPDATE social_deliveries SET locked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND status='processing' AND locked_at IS NULL");$statement->execute([$row['id']]);if(!$statement->rowCount()){$this->db->rollBack();return null;}$this->db->commit();return$row;}catch(Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    private function safeError(Throwable $error): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($error->getMessage())) ?: 'The provider rejected this delivery.';
        $message = preg_replace('/(?:access[_ -]?token|authorization|bearer|secret|key)\s*[:=]\s*[^\s,;]+/i', '[redacted]', $message) ?: 'The provider rejected this delivery.';
        return mb_substr($message, 0, 300);
    }
}
