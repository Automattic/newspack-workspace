# newspack-workspace
Newspack helper Repository for running local environments using Docker.

The main idea is to have all the dependecies we need to run the projects, and its tests, inside the container so we don't depend on anything in our local machine.
## Getting started

### Clone this repository

```BASH
git clone https://github.com/Automattic/newspack-workspace.git
```

### Set up your local vars

Make a copy of `default.env` to `.env`

```BASH
cp default.env .env
```

Edit the file and choose your own variables

You can change the password and username of your WordPress user.

Linux users might want to configure apache to run as the same user as the host machine. You can do that in this file

### Build the Docker image

You only need to run this the first time you set up your env.

```BASH
./build-image.sh
```

The default builds using PHP 8.3. You can also call `./build-image-82.sh` to build an image with PHP 8.2. It's a good idea to have both.

### Clone all repos

This will clone all Newspack repos inside the `repos` folder. Assumes your host machine is authenticated with GitHub. Default git protocol is SSH. Add `-h` or `--https` to clone using HTTPS instead.

```BASH
./clone-repos.sh
```

### Lauch the container and install WordPress

Now we are going to use the `n` script. (Tip: Create an alias in your `.bashrc` so you can call it from anywhere)

#### Launch the container
```BASH
n start
```

(`n start 8.2` will start the image with php 8.2 if you built it)

When you are done, you can stop the containers with `n stop`.

You can also stop and start in one command with `n restart` (or `n restart 8.2`).

At this point you should be able to see your site in `https://localhost`.

#### Install WordPress
```BASH
n install
```

#### Build the projects

The first time you set up your environment you might want to build all the projects. You can do this by:

```BASH
n ci-build all
```

## The N script

The `n` script will help you perform actions inside the containers. Some examples:

Build one specific project:

```BASH
n build theme # Builds the newspack-theme repo
n build block-theme # Builds the newspack-block-theme repo
n build newspack-plugin # Builds the main plugin
n build newsletters # You can also omit the 'newspack-' prefix from plugins
n build # Builds the project you are currently in
n ci-build # Runs npm ci --legacy-peer-deps and builds the project you are currently in
```

Watch and run tests on projects:

These commands will automatically run in the repo folder you are currently in

```BASH
n watch # Runs npm watch on the project you are currently in
n test-php # Runs phpunit tests on the project you are currently in
n test-js # Runs js tests on the project you are currently in
```

Run composer commands inside one of the projects

```BASH
n composer dump-autoload # Runs `composer dump-autload` inside the current repo
n composer update # Runs `composer update` inside the current repo
```

Run `npm` commands inside one of the projects

```BASH
n npm run release:archive # Runs `npm run release:archive` inside the current repo
```

Run WP CLI interactive shell

```BASH
n shell
```

Enter the container bash

```BASH
n sh # as the apache user, if USE_CUSTOM_APACHE_USER was set in your .env
n rsh # as root
```

Other commands:

* `n db`: Launches the MySQL interactive shell
* `n wp`: runs any arbitraty WP CLI command. e.g. `n wp option get blogname`
* `n tail`: Tails the apache error log file
* `n uninstall`: Uninstalls WordPress
* `jncp`, `jninit` & `secrets`: See Jurassic Ninja section below.
* `n secrets-import`: Import all your secrets from a `secrets.json` file (see details on the Jurassic Ninha section below)
* `n snapshot $name`: Creates a snapshot of the current site and gives it a name
* `n snapshot-load $name`: Drops the current site and override it with the data from a snapshot
* `n reset-site`: Drops the current site and creates a new one from scratch
* `n pull`: Pull every git repository inside `repos/`
* `n trunk` | `n alpha` | `n release` | `n branch $branch-name`: Set every git repository inside `repos/` to the specified git branch and rebuild assets
* `sites-add`, `sites-drop`, `sites-list`: See Additional Sites section below
* `n setup-newspack-network`: Sets up the connections of Newspack Network and Distributor plugins between all active sites
* `n cd-install`: Install the handy `ncd` function to your terminal. See section below.
* `n worktree add|list|remove`: Manage git worktrees for parallel development. See Isolated Environments section below.
* `n env create|up|down|destroy|list`: Manage isolated WordPress environments. See Isolated Environments section below.

## Navigating between projects (the `ncd` command)

Many of the `n` commands will act on the project you are currently in. For example, if you are in the main plugin folder, `n build` will build the plugin. If you are inside the folder of one of your additional sites, `n shell` will launch the WP Shell for that particular site.

But navigating between all these directories might become tiring, since you have to go to the `repos` folder, and then down to `additional-sites-html` and sometimes inside one particular plugin you want to debug.

To make navigating easier, use the `ncd` terminal command.

`ncd` will take you to the directory you want, no matter where you are at when you type it. When you type `ncd plugin`, it will:

* Look for an exact match in the repos folder: `... /repos/plugin`
* Look for a repo with the `newspack-` prefix: `... /repos/newspack-plugin`
* Look for an additional site with that name `... /additional-sites-html/plugin`
* Look for a plugin installed in the main site `... /html/wp-content/plugins/plugin`

So when you arrive at your home folder and want to go to the `newspack-newsletters` project, instead of typing something like `cd my-project/newspack-workspace/repos/newspack-newsletters`, all you need to do is `ncd newsletters`!

To start using it, you need to add it to your terminal by running `n cd-install` and then inform the loader file you want to add the script to, for example `.bashrc`, `.zshrc`, etc.

## Jurassic Ninja

There are some commands to help you work with Jurassic Ninja

### Initialize and Sets up a new JN Site
```BASH
n jninit user domain.jurassic.ninja
```

This command Sets up a new Jurassic Ninja site. It will
* Upload the newspack-plugin from your machine to JN
* Run `wp newspack setup`
* Copy your secrets to the JN site

**Setting up your Secrets**

If you want your secrets to be copied over to the JN site, create a `secrets.json` file inside the `bin` folder.

You can copy the `secrets.json.sample` file and manually edit it, or you can use `n secrets` to output the secrets you currently have on your local site.

If you want to directly copy the secrets into the file you can run `n secrets > bin/secrets.json`.

### Copy plugins to a JN Site
```BASH
n jncp user domain.jurassic.ninja
```

This command will allow you to copy one or many plugins from your local env to the JN site at once.

This is useful if you want to replace the plugin in the site with the custom branch you have checked out, or if you want to upload any other plugin you have.

## Mailhog

Mailhog is running by default so you can see all the emails that are sent from your WordPress site.

Visit http://localhost:8025 to see it.

## Adminer

Adminer is available at http://localhost:8088

Connect to Server `db`, user `root` and password defined in your local `.env` file.

## Cache

There are two layers of cache available in the Docker env, to better mimic production environments.

### Memcached

By default, Memcached plugin is enabled. If you want to disable it, simply delete the `html/wp-content/object-cache.php` file.

### Batcache

Batcache is also enabled by default. It relies on memcached to cache the end output of pages. It's enabled by placing the `advanced-cache.php` file in the `wp-content` folder and by defining the `WP_CACHE` constant to `true`.

## X-Debug

X-debug is configured by default. In order to use it:

- Set you browser extension to use the `DOCKERDEBUG` IDE Key.
- Configure your IDE to use the same IDE Key, listen to port 9003 and add the necessary path mappings.

Here's an example of a `launch.json` file for VSCode to be used for the `newspack-plugin` repo:

```
{
  "version": "0.2.0",
  "configurations": [
    {
      "name": "Newspack Docker",
      "type": "php",
      "request": "launch",
      "port": 9003,
      "log": false,
      "maxConnections": 1, // @see  https://github.com/xdebug/vscode-php-debug/issues/604
      "xdebugSettings": {
        "resolved_breakpoints": "0", // @see https://github.com/xdebug/vscode-php-debug/issues/629 and https://stackoverflow.com/a/69925257/3059883
        "max_data": 512,
        "show_hidden": 1,
        "max_children": 128
      },
      "pathMappings": {
        "/newspack-repos/newspack-plugin": "${workspaceRoot}"
      },
    }
  ]
}
```

## Isolated Environments (Git Worktrees)

When working on multiple features in parallel (e.g. multiple Claude Code agents), you can spin up isolated WordPress environments that each use a different branch of a plugin. This uses git worktrees to check out multiple branches simultaneously, and Docker volume mount overrides to route each environment to the correct code.

### Quick start

```BASH
# 1. Create a worktree for the branch you want to test
n worktree add newspack-plugin fix/my-feature

# 2. Create an environment pointing to that worktree
n env create my-feature --worktree newspack-plugin:fix/my-feature

# 3. Start it (--build installs deps and compiles assets)
n env up my-feature --build

# 4. Visit http://localhost:8081 — this site uses the worktree branch
#    Meanwhile http://localhost still uses the main repos/ checkout
```

### Worktree commands

```BASH
n worktree add <repo> <branch>      # Create a worktree from a repo in repos/
n worktree list [repo]              # List active worktrees (all repos or one)
n worktree remove <repo> <branch>   # Remove a worktree
```

### Environment commands

```BASH
n env create <name> --worktree <repo>:<branch> [--worktree ...] [--port <port>]
n env up <name> [--build]           # Start the environment (--build installs deps)
n env down <name>                   # Stop the environment
n env destroy <name>                # Stop and remove the environment
n env list                          # List all environments and their status
```

You can override multiple repos in a single environment:

```BASH
n worktree add newspack-plugin fix/my-feature
n worktree add newspack-blocks fix/my-feature
n env create my-feature \
  --worktree newspack-plugin:fix/my-feature \
  --worktree newspack-blocks:fix/my-feature \
  --port 8081  # optional — auto-assigns from 8081 if omitted
```

### How it works

Each environment is a lightweight Apache/PHP container sharing the same database as the main site. The existing symlinks in `wp-content/plugins/` point to `/newspack-repos/<plugin>` inside the container. By mounting a worktree directory on top of `/newspack-repos/<plugin>`, Docker's mount specificity routes the environment to the correct branch — no symlink changes needed.

If `--port` is omitted, a port is automatically assigned starting from 8081, skipping any ports already used by other environments.

### Cleanup

```BASH
n env destroy my-feature
n worktree remove newspack-plugin fix/my-feature
```

## Newspack Manager

This Docker environment will launch two sites by default. One is the site you will be working on to develop all plugins, and the other is the one that will run the Newspack Manager Client.

In order to be able to access the Newspack Manager site, there are a few additional steps:

Setup the manager by installing WordPress, creating the key pairs, adding the constants to both sites and activate the plugins. All this is done by:
```BASH
n setup-manager
```

Configure the `manager.com` domain to point to your localhost by adding it to the `hosts` file.

In your favorite text editor, open the `/etc/hosts` file and add a line with `127.0.0.1 manager.com`. Or run the following command:

```BASH
echo "127.0.0.1 manager.com" | sudo tee -a /etc/hosts
```

If you haven't done it yet, build the Manager Client plugin:
```BASH
n build manager-client
```
(Note that you can also use `watch` here while developing)

That's it!

Now visit `manager.com/wp-admin`, go to Newspack Manager, and add the URL for you other site there.

### Note about the site domain when running CLI commands

By default, the docker environment provides a dynamic site url, so you can access the site either via localhost or a tunneled domain, required for some actions. This is useful because it allows you to run your site without the tunnel when you don't need it.

Because of that, when running commands via CLI, the returned site url is localhost. This will create issues when communicating with the manager, as the keys are tied to the site domain.

Use the NEWSPACK_DOCKER_SITE_URL_CLI_OVERRIDE to override the site url for CLI commands.

In your dev site (not the manager.com instance), add the following
```
define( 'NEWSPACK_DOCKER_SITE_URL_CLI_OVERRIDE', 'https://my-domain.my-tunnel.com' );
```

## Additional Sites

If you need to run a couple of additional sites, we got you covered.

You can have a number of additional sites running under `you-name-it.local`. They will live in their own local domain, such as `site1.local` and `another-site.local`.

`n sites-add $site_name` will launch a new site. The site will come with Newpack already initialized and all the plugins linked. Your secrets will also be copied. It's basically the same result as running `n reset-site` for your main site.

* `n sites-list` - Lists the current existing sites
* `n sites-drop $sitename` - Will completely erase the site and its database

### Interacting with the sites via command line

You can use the same `n` commands to interact with these sites. If you run the `n` script from inside a site's folder, it will interact with this specific site. If run it from anywhere else, it will interact with the main site.

The sites live under `additional-sites-html` folder. `cd` into one of the sites folder to interact with them.

## Newspack Network and Distributor

If you want to play with Newspack's Federated sites features, there's a handy comment that will set everything up for you.

* First, create as many sites you like with `n sites-add` (see docs above)
* Run `n setup-newspack-network`
* That's it!
* Whenever you add a new site, you can run this command again and it will get added to the network

This command will:
* Make sure Newspack Network and Distributor plugins are enable in all sites
* Register the Nodes in the Newspack Network Hub (this will be your main site)
* Configure the each Node with the Hub's key
* Set up Distributor external connections between all sites

## SSL

A certificate will be generated, so HTTPS is available immediately. However, the CA (Certificate Authority) certificate is installed on the Docker machine, so a browser will warn about a `ERR_CERT_AUTHORITY_INVALID` error. To make the certificate recognisable on the host machine, run the following commands (for macOS):

- `$ docker exec newspack_dev bash -c 'eval cat $(mkcert -CAROOT)/rootCA.pem' > rootCA.pem` to copy the CA certificate from the Docker machine
- `$ sudo security add-trusted-cert -d -r trustRoot -k "/Library/Keychains/System.keychain" ./rootCA.pem` to trust this CA certificate (import to KeyChain). Alternatively, double-click on the .pem file.
- remove the `rootCA.pem`, it's no longer needed
