<?php

declare(strict_types=1);

namespace SenseCMS\Mastodon;

require_once __DIR__.'/MastodonClient.php';

return new class {
    private MastodonClient $client;

    public function __construct()
    {
        $this->client=new MastodonClient();
    }

    public function verify(array$credentials): array
    {
        return$this->client->verify($credentials);
    }

    public function publish(array$credentials,array$payload): array
    {
        return$this->client->publish($credentials,$payload);
    }
};
