A library to monitor services health and report to consul


# Installation


### Add the Laravel package via composer

```
composer require tokenly/consul-health-daemon
```

### Add the Service Provider

Add the following to the `providers` array in your application config:

```
Tokenly\ConsulHealthDaemon\ServiceProvider\ConsulHealthDaemonServiceProvider::class
```


