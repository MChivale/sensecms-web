<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\CmsRepository;
use App\Core\FacilityRepository;

final class PublicSearchController extends Controller
{
    public function __construct(
        private readonly CmsRepository $cms,
        private readonly FacilityRepository $facilities,
        private readonly array $config,
    ) {}

    public function search(): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');

        $locale = strtolower(trim((string) ($_GET['locale'] ?? '')));
        $query = preg_replace('/\s+/u', ' ', trim((string) ($_GET['q'] ?? ''))) ?? '';
        $available = array_column($this->cms->languages(), 'locale');
        if (!in_array($locale, $available, true)) $this->json(false, [], 'Language not found.', 404);
        $minimumLength = preg_match('/[\x{1780}-\x{17ff}\x{3400}-\x{9fff}]/u', $query) === 1 ? 2 : 3;
        if (mb_strlen($query) < $minimumLength) $this->json(true, [], null);
        if (mb_strlen($query) > 80) $this->json(false, [], 'The search phrase is too long.', 422);

        $facilityId=max(0,(int)($_GET['facility']??0));$facility=$facilityId?$this->facilities->activeById($facilityId,$locale,$this->config['default_locale']):null;if(!$facility&&preg_match('#/[a-z]{2,5}/facilities/([a-z0-9-]+)/([a-z0-9-]+)#',(string)($_SERVER['HTTP_REFERER']??''),$route))$facility=$this->facilities->resolve($route[1],$route[2],$locale,$this->config['default_locale']);$facility??=$this->facilities->primary($locale,$this->config['default_locale']);if(!$facility)$this->json(false,[],'Facility not found.',404);if(!$facility['search_enabled'])$this->json(true,[],null);
        $results = $this->cms->publicSearch($locale, $this->config['default_locale'], $query,12,$facility);
        usort($results, static fn(array $left, array $right): int => ($right['score'] ?? 0) <=> ($left['score'] ?? 0));
        $unique = [];
        $titles = [];
        foreach ($results as $result) {
            $url = (string) ($result['url'] ?? '');
            $titleKey = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) ($result['title'] ?? ''))) ?? '';
            if ($url === '' || isset($unique[$url]) || ($titleKey !== '' && isset($titles[$titleKey]))) continue;
            unset($result['score']);
            $unique[$url] = $result;
            if ($titleKey !== '') $titles[$titleKey] = true;
            if (count($unique) === 8) break;
        }
        $this->json(true, array_values($unique), null);
    }

    private function json(bool $ok, array $results, ?string $message, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode(['ok' => $ok, 'data' => ['results' => $results], 'meta' => ['count' => count($results), 'limit' => 8], 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
