<?php

declare(strict_types=1);
namespace App\Http;

use App\Core\{Auth, LicenseException, LicenseService, CmsRepository, AccessControl};

final class LicenseController extends Controller
{
    public function __construct(private readonly Auth $auth, private readonly LicenseService $license, private readonly array $config, private readonly CmsRepository $cms) {}

    public function upload(): never
    {
        if (!$this->auth->check()) $this->redirect('/login');
        if (!(($this->auth->user())['owner'] ?? false)) $this->result(false, 'Owner access is required.', null, 403);
        if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) $this->result(false, 'Refresh your session before retrying.', null, 419);
        if (($_SESSION['license_attempt_at'] ?? 0) > time() - 10) $this->result(false, 'Wait before retrying license validation.', null, 429);
        $_SESSION['license_attempt_at'] = time();
        try {
            $this->license->install(trim((string) ($_POST['license_key'] ?? '')), $this->config['base_url']);
            $this->auth->audit($this->auth->id() ?? 0, 'license.updated');
            $this->result(true, 'License validated and stored securely.', '/license');
        } catch (LicenseException $error) { $this->result(false, $error->getMessage(), null, 422); }
    }
}
