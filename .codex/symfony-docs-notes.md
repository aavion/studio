# Symfony documentation notes

> **Status:** Active  
> **Updated:** 2026-05-20  
> **Owner:** Codex  
> **Purpose:** Cache official Symfony documentation references used while drafting architecture and feature specifications.  

## References checked

- Symfony 8 AssetMapper: https://symfony.com/doc/8.0/frontend/asset_mapper.html
  - AssetMapper maps and versions files from `assets/`.
  - Importmap entrypoints can be used for page-specific JavaScript and CSS.
  - Tailwind use is delegated to `symfonycasts/tailwind-bundle`.
- Symfony 8 service tags: https://symfony.com/doc/8.0/service_container/tags.html
  - Tagged service collections can be prioritized.
  - `AutoconfigureTag` and `AutowireIterator` support Symfony-native extension collections.
- Symfony service decoration: https://symfony.com/doc/current/service_container/service_decoration.html
  - Service decoration is a native replacement/wrapping mechanism for selected services.
- Symfony 8 EventDispatcher: https://symfony.com/doc/8.0/event_dispatcher.html
  - Event listeners/subscribers are suitable for documented lifecycle and extension hooks.
- Symfony 8 Rate Limiter: https://symfony.com/doc/8.0/rate_limiter.html
  - Symfony rate limiters are useful for application-level limits, but edge/server protections are still needed for DoS scenarios.

## Drafting guidance derived from these references

- Prefer native Symfony extension mechanisms before custom registries.
- Use tagged services for additive extension points.
- Use service decoration, configuration, or resolvers for explicit replacement points.
- Use EventDispatcher for lifecycle hooks and Messenger for asynchronous side effects.
- Keep frontend feature specs aligned with AssetMapper, importmap entrypoints, Stimulus, and the Tailwind bundle already present in the project.
