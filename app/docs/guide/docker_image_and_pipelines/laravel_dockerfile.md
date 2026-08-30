# Laravel Dockerfile — `build` Stage

The `build` stage takes the dependencies from `deps`, copies the Laravel application, and prepares it for production.

In simple terms:

> The `deps` stage provides PHP, Composer, and installed packages. The `build` stage adds your Laravel code and performs the final preparation needed before the application runs.

```dockerfile
FROM deps AS build

COPY . .

RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
 && php artisan package:discover --ansi
```

## 1. Start from `deps`

```dockerfile
FROM deps AS build
```

This creates a new Docker build stage named `build` using the previous `deps` stage as its starting point.

The `deps` stage already contains:

* PHP and required extensions
* Composer
* Installed Composer packages
* The `vendor/` directory created by `composer install`

So `build` inherits all of these files and tools.

### Simplified explanation

Think of `deps` as a prepared toolbox:

```text
deps
├── PHP
├── PHP extensions
├── Composer
└── vendor/
```

The `build` stage reuses that toolbox instead of starting over.

That is why `composer install` does not need to run again in this stage.

## 2. Copy the application

```dockerfile
COPY . .
```

This copies the Laravel project from your computer into the container.

The destination is usually:

```text
/var/www/html
```

After the copy, the container contains the complete Laravel application:

```text
/var/www/html
├── app/
├── bootstrap/
├── config/
├── database/
├── public/
├── resources/
├── routes/
├── storage/
├── artisan
├── composer.json
├── composer.lock
└── vendor/
```

### Simplified explanation

Before `COPY . .`, the image has the dependencies but not your application code.

After `COPY . .`, it has both:

```text
PHP + Composer + vendor/
              +
        Laravel source code
```

The `.dockerignore` file controls which files are not copied. For example, it may exclude:

```text
.git
node_modules
.env
```

## 3. Optimize Composer autoloading

```dockerfile
composer dump-autoload --optimize --no-dev --classmap-authoritative
```

This command does **not** install packages.

The packages are already installed in the `deps` stage. Instead, this command rebuilds Composer's autoloader.

The autoloader helps PHP locate classes such as:

```text
App\Models\User
App\Http\Controllers\UserController
```

Without an optimized autoloader, Composer may need to search for classes. With an optimized autoloader, Composer has a prepared map of where classes are located.

### Simplified explanation

This command creates a faster lookup system for PHP:

```text
Class name
    ↓
Composer autoloader
    ↓
Correct PHP file
```

### Options

#### `--optimize`

Creates a more efficient class map for production.

#### `--no-dev`

Excludes development-only packages from the production autoloader.

Examples of development packages may include:

* Testing tools
* Debugging tools
* Code formatters
* Development utilities

#### `--classmap-authoritative`

Tells Composer to use the generated class map as the main source of truth.

This can improve performance, but if you add new classes later, you may need to rebuild the autoloader again.

## 4. Discover Laravel packages

```dockerfile
php artisan package:discover --ansi
```

This command asks Laravel to inspect the installed Composer packages and find packages that integrate with Laravel.

For example, a package may provide:

* A service provider
* Configuration
* Commands
* Routes
* Other Laravel integration

Laravel records this information in cached files such as:

```text
bootstrap/cache/packages.php
bootstrap/cache/services.php
```

These files help Laravel load package information more efficiently when the application starts.

### Simplified explanation

Composer knows which packages are installed.

Laravel then asks:

> Which of these packages are Laravel packages, and how should I load them?

The `package:discover` command finds that information and prepares it for Laravel.

### `--ansi`

The `--ansi` option enables colored and formatted terminal output.

It does not change the application's behavior.

## What happens overall?

The build process looks like this:

```text
deps
  ↓
PHP + extensions + Composer + vendor/
  ↓
COPY .
  ↓
Full Laravel application
  ↓
composer dump-autoload
  ↓
Optimized Composer autoloader
  ↓
php artisan package:discover
  ↓
Laravel package information prepared
  ↓
Production-ready application
```

## Why are these commands needed?

The two commands prepare different parts of the application:

```dockerfile
composer dump-autoload --optimize --no-dev --classmap-authoritative
```

Prepares Composer and makes PHP class loading more efficient.

```dockerfile
php artisan package:discover --ansi
```

Prepares Laravel's package and service-provider information.

### Simplified explanation

```text
Composer preparation
        +
Laravel preparation
        =
Application ready to run
```

## Copying into the final runtime image

Later, the `runtime` stage can copy the prepared application from the `build` stage:

```dockerfile
COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html
```

This copies the application files, optimized autoloader, Laravel cache files, and installed dependencies into the final runtime image. `--chown=www-data:www-data` matters here — without it, the copied files would be owned by root, and the running app (which drops to `USER www-data` before serving requests) wouldn't be able to write to `storage/` or `bootstrap/cache/` at all.

The final runtime image does not need to repeat the build preparation commands.

## In one sentence

The `build` stage takes the Laravel source code and already-installed dependencies from `deps`, then prepares Composer and Laravel so the application is ready to run in production.
