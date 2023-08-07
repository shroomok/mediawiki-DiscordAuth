# mediawiki-DiscordAuth
This MediaWiki extension allows users with predefined roles from specified Discord server access to your wiki.

Purpose: give access to some users of [Shroomok Discord Community](https://discord.com/invite/ngKhQDmymD) to publish articles on https://shroomok.com

## Dependencies

* [mediawiki-extensions-PluggableAuth](https://github.com/wikimedia/mediawiki-extensions-PluggableAuth)
* [mediawiki-extensions-WSOAuth](https://github.com/wikimedia/mediawiki-extensions-WSOAuth)
* [Discord Provider for OAuth 2.0 Client](https://github.com/wohali/oauth2-discord-new)
* [RestCord - Discord API](https://github.com/restcord/restcord)

## Create composer.local.json before installation
This extension has a dependency on particular version of one package which can be installed only from GitHub.

That's why you need to edit (or create) a composer.local.json at the root of your wiki folder

### Composer.local.json
```json
{
	"extra": {
		"merge-plugin": {
			"include": [
				"extensions/DiscordAuth/composer.json"
			]
		}
	}
}
```

## Installation
```
cd <path-to-wiki>/extensions
git clone git@github.com:wikimedia/mediawiki-extensions-PluggableAuth.git PluggableAuth
git clone git@github.com:wikimedia/mediawiki-extensions-WSOAuth.git WSOAuth
git clone git@github.com:shroomok/mediawiki-DiscordAuth.git DiscordAuth
cd <path-to-wiki>
composer update
php maintenance/update.php
```


### LocalSettings.php minimal setup
```php

wfLoadExtension( 'PluggableAuth' );
wfLoadExtension( 'WSOAuth' );
wfLoadExtension( 'DiscordAuth' );

$wgOAuthCustomAuthProviders = ['discord' => \DiscordAuth\AuthenticationProvider\DiscordAuth::class];
$wgPluggableAuth_EnableLocalLogin = true;
$wgPluggableAuth_Config['discordauth'] = [
    'plugin' => 'WSOAuth',
    'data' => [
        'type' => 'discord',
        'uri' => 'https://discord.com/oauth2/authorize',
        'clientId' => '<DISCORD APP CLIENT ID>',
        'clientSecret' => '<DISCORD APP CLIENT SECRET>',
        'redirectUri' => 'https://<YOUR-WIKI-ADDRESS>/index.php?title=Special:PluggableAuthLogin'
    ],
    'buttonLabelMessage' => 'discordauth-login-button-label'
];

$wgDiscordAuthBotToken = '<DISCORD BOT TOKEN>';
$wgDiscordGuildId = <YOUR DISCORD GUILD ID>; // you can copy this within Discord app interface
$wgDiscordApprovedRoles = ['<role name 1>', '<role name 2>']; // users only with the specified roles will be able to login
$wgDiscordCollectEmail = false; // Collect the user's email from Discord and use it when creating their wiki account
$wgPrependDiscordToWikiUsername = true; // Prepend "Discord" before usernames to distinguish them from locally created users (e.g. "Discord-User123" instead of User123)
```

### LocalSettings.php If you want to have a custom NS for Discord users and change Main Page layout to simplify ux

```php
$wgDiscordNS = ['id' => <NS_ID>, 'alias' => '<NS_ALIAS>'];
$wgDiscordToRegisterNS = true;
$wgDiscordShowUserContributionsOnMainPage = true;
```

### [Discord Documentation](https://discord.com/developers/docs/intro)
