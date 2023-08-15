<?php

declare(strict_types=1);

namespace QberonDigital\Symfony\Translation\Tolgee;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Component\Translation\Dumper\JsonFileDumper;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Provider\AbstractProviderFactory;
use Symfony\Component\Translation\Provider\Dsn;
use Symfony\Component\Translation\Provider\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TolgeeProviderFactory extends AbstractProviderFactory
{
    public function __construct(
        readonly private HttpClientInterface $client,
        readonly private ArrayLoader         $loader,
        readonly private JsonFileDumper      $jsonFileDumper,
        readonly private LoggerInterface     $logger,
        readonly private string              $defaultLocale,
    )
    {
    }

    protected function getSupportedSchemes(): array
    {
        return ['tolgee'];
    }

    public function create(Dsn $dsn): ProviderInterface
    {
        $endpoint = $dsn->getHost();
        $endpoint .= $dsn->getPort() ? ':' . $dsn->getPort() : '';

        $client = ScopingHttpClient::forBaseUri(
            client: $this->client,
            baseUri: sprintf('https://%s/v2/projects/%s', $endpoint, $this->getUser($dsn)),
            defaultOptions: [
                'headers' => [
                    'X-API-Key' => $dsn->getPassword(),
                ]
            ],
        );

        return new TolgeeTranslationProvider(
            client: $client,
            loader: $this->loader,
            logger: $this->logger,
            jsonFileDumper: $this->jsonFileDumper,
            defaultLocale: $this->defaultLocale,
            endpoint: $endpoint,
        );
    }
}
