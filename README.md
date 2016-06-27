ADT/OneSky
======

In your `config.local.neon` set:
```
parameters:
	oneSky:
		apiKey: ********************************
		apiSecret: ********************************
		projectId: 123456

extensions:
	onesky: ADT\OneSky\DI\OneSkyExtension

onesky:
	apiKey: %oneSky.apiKey%
	apiSecret: %oneSky.apiSecret%
	projectId: %oneSky.projectId%

```

To download all translations run:

```
php web/console.php adt:onesky -d
```
