Tolgee Translation Provider
============================

Provides Tolgee integration for Symfony Translation.

Tolgee compatibility
-----------
The current implementation is only compatible with Tolgee API v2.

How to enable tolgee translation provider
-----------

Add `tolgee` provider to your translation yaml config.

`config/translation.yaml`
```yaml
framework:
    translator:
        providers:
            tolgee:
                dsn: 'tolgee://<PROJECT_ID>:<API_TOKEN>@<HOST>:<PORT>'
```

Tag `QberonDigital\Symfony\Translation\Tolgee\TolgeeProviderFactory::class` with `translation.provider_factory`. 