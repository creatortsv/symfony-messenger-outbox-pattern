# Symfony messenger outbox pattern

[![CI](https://github.com/creatortsv/symfony-messenger-outbox-pattern/actions/workflows/php.yml/badge.svg)](https://github.com/creatortsv/symfony-messenger-outbox-pattern/actions/workflows/php.yml)

This package is an extension for the [symfony/messenger](https://symfony.com/doc/current/components/messenger.html) component that implements the [Transactional outbox](https://microservices.io/patterns/data/transactional-outbox.html) pattern.
It provides the special middleware that navigates your original message to the message broker through the outbox transport

---
## Requirements
| PHP                                                                            | `>=8.2` |
|--------------------------------------------------------------------------------|---------|
| [symfony/contracts](https://symfony.com/doc/current/components/contracts.html) | `>=2.5` |
| [symfony/messenger](https://symfony.com/doc/current/components/messenger.html) | `>=6.4` |

## Installation

Install with composer
```shell
composer require creatortsv/symfony-messenger-outbox-pattern
```

## Configuration

This guide shows how the symfony messenger component should be configured

### Configure transports

```yaml
# config/packages/messenger.yaml

framework:
    messenger:
      
        transports:
        ### Your default async message transport for any purpose
            async: '%env(resolve:MESSENGER_TRANSPORT_DNS)%'
        ### Outbox transport, for example:
        ### outbox: '%env(resolve:MESSENGER_TRANSPORT_DNS)%'
        ### outbox: 'doctrine://%env(resolve:DATABASE_URL)%'
        ### outbox: 'doctrine://default?table_name=outbox&queue_name=custom'
            outbox: 'doctrine://default'
        ### Advance stored outbox transport
            stored: 

    ### routing: ...
```

### Configure message bus
```yaml
# config/packages/messenger.yaml

framework:
    messenger:
        buses:
        ### Configure message bus that will be used with middleware from this package
            event.bus:
            ### Default middlewares must be enabled
                default_middleware:
                    enabled: true
                    allow_no_handlers: true
          
                middleware:
                ### Add the middleware with configured outbox transport name or/and advanced names
                ### - Creatortsv\Messenger\Outbox\Middleware\SwitchToOutboxMiddleware: [ outbox, store, logs ]
                    - Creatortsv\Messenger\Outbox\Middleware\SwitchToOutboxMiddleware: [ outbox ]
    
    ### transports: ... Outbox transport configuration

    ### routing: ...
```

## Usage

```php
readonly class UserService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $eventBus,
    ) {
        // ...
    }
    
    public function register(User $user): void
    {
        $this->entityManager->wrapInTransaction(
            function () use ($user): void {
            /** Persist user in DB ... */
            
                $this->entityManager->flush();
                $this->eventBus->dispatch(new UserRegistered($user->id));
            },
        );
    }
}
```

## Run outbox consumer

Run consumer with outbox transport name
```shell
php bin/console messenger:consume [name]
```

Each message which is consumed by the worker will be automatically sent to the original transport, thanks to the `SwitchToOutboxMiddleware` class