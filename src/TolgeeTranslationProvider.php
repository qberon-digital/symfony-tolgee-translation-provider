<?php

declare(strict_types=1);

namespace QberonDigital\Symfony\Translation\Tolgee;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Component\Translation\Dumper\JsonFileDumper;
use Symfony\Component\Translation\Exception\ProviderException;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Provider\ProviderInterface;
use Symfony\Component\Translation\TranslatorBag;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TolgeeTranslationProvider implements ProviderInterface
{
    private const TOLGEE_MAX_PAGE_SIZE = 2000;

    public function __construct(
        private HttpClientInterface $client,
        private ArrayLoader         $loader,
        private LoggerInterface     $logger,
        private JsonFileDumper      $jsonFileDumper,
        private string              $defaultLocale,
        private string              $endpoint,
    )
    {
    }

    public function __toString(): string
    {
        return sprintf('tolgee://%s', $this->endpoint);
    }

    public function write(TranslatorBagInterface $translatorBag): void
    {
        $imports = [];

        foreach ($translatorBag->getCatalogues() as $catalogue) {
            foreach ($catalogue->getDomains() as $domain) {
                if (empty($catalogue->all($domain))) {
                    continue;
                }

                $content = $this->jsonFileDumper->formatCatalogue(
                    $catalogue,
                    $domain,
                    ['default_locale' => $this->defaultLocale],
                );

                $imports[$domain][$catalogue->getLocale()] = $content;
            }
        }

        $this->deletePreviousImports();
        $responses = $this->importTranslations($imports);

        foreach ($responses as $response) {
            if ($response->getStatusCode() !== 200) {
                $this->logger->error(
                    sprintf('Unable to import translations to Tolgee: "%s".', $response->getContent(false))
                );

                if ($response->getStatusCode() >= 500) {
                    throw new ProviderException('Unable to import translations to Tolgee.', $response);
                }
            }
        }
    }

    public function read(array $domains, array $locales): TranslatorBag
    {
        $translations = $this->getTranslations();

        $translatorBag = new TranslatorBag();

        foreach ($translations as $namespace => $locales) {
            foreach ($locales as $locale => $translations) {
                $translationArray = [];

                foreach ($translations as $key => $translation) {
                    $translationArray[$key] = $translation['text'];
                }

                $translatorBag->addCatalogue(
                    catalogue: $this->loader->load(
                        resource: $translationArray,
                        locale: $locale,
                        domain: $namespace,
                    )
                );
            }
        }

        return $translatorBag;
    }

    public function delete(TranslatorBagInterface $translatorBag): void
    {
        $translations = $this->getTranslations();

        $idsToDelete = [];

        foreach ($translatorBag->getCatalogues() as $catalogue) {
            foreach ($catalogue->all() as $domain => $messages) {
                foreach ($messages as $key => $message) {
                    $idsToDelete[] = $translations[$domain][$catalogue->getLocale()][$key]['id'];
                }
            }
        }

        $response = $this->client->request(
            method: 'DELETE',
            url: 'keys',
            options: [
                'json' => $idsToDelete,
            ]
        );

        if ($response->getStatusCode() !== 200) {
            $this->logger->error(
                sprintf('Unable to delete translations keys from Tolgee: "%s".', $response->getContent(false))
            );

            if ($response->getStatusCode() >= 500) {
                throw new ProviderException('Unable to delete translations keys from Tolgee: "%s"', $response);
            }
        }
    }

    private function deletePreviousImports(): void
    {
        try {
            $this->client->request(method: 'DELETE', url: 'import');
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
    }

    private function importTranslations(array $translations): array
    {
        $responses = [];

        foreach ($translations as $namespace => $namespaceTranslation) {
            $files = [];
            foreach ($namespaceTranslation as $locale => $content) {
                $files[] = [
                    'files' => new DataPart(
                        body: $content,
                        filename: sprintf('%s.json', $locale),
                        contentType: 'application/json',
                    )
                ];
            }

            $formData = new FormDataPart($files);

            $response = $this->client->request(
                method: 'POST',
                url: 'import',
                options: [
                    'headers' => $formData->getPreparedHeaders()->toArray(),
                    'body' => $formData->bodyToIterable(),
                ],
            );

            $responses[] = $response;

            if ($response->getStatusCode() !== 200) {
                continue;
            }

            $responseBody = json_decode($response->getContent(), true);

            $imports = $responseBody['result']['_embedded']['languages'];

            foreach ($imports as $import) {
                if ($import['namespace'] !== null) {
                    continue;
                }

                $responses[] = $response;

                $response = $this->client->request(
                    method: 'PUT',
                    url: sprintf('import/result/files/%s/select-namespace', $import['importFileId']),
                    options: [
                        'json' => [
                            'namespace' => $namespace,
                        ]
                    ],
                );

                $responses[] = $response;
            }
        }

        return $responses;
    }

    private function getTranslations(array $translations = [], ?string $nextCursor = null): array
    {
        $response = $this->client->request(
            method: 'GET',
            url: 'translations',
            options: [
                'query' => [
                    'size' => self::TOLGEE_MAX_PAGE_SIZE,
                    'cursor' => $nextCursor,
                ]
            ]
        );

        $content = json_decode($response->getContent(), true);

        $trans = [];

        if (!isset($content['_embedded'])) {
            return $translations;
        }

        foreach ($content['_embedded']['keys'] as $key) {
            foreach ($key['translations'] as $locale => $translation) {
                $trans[$key['keyNamespace']][$locale][$key['keyName']] = [
                    'id' => $translation['id'],
                    'text' => $translation['text']
                ];
            }
        }

        $translations = array_merge_recursive($translations, $trans);

        if ($content['nextCursor'] === null) {
            return $translations;
        }

        return $this->getTranslations($translations, $content['nextCursor']);
    }
}
