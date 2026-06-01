## DO bot custom module
This module creates the neccessary endpoints and hooks to be used with the DO Telegram bot.

### Functionality
This module has endpoints for saving user Telegram preferences and hooks to notify users about new/updated issues and new modules.

### Usage

#### Bot token

In order for the module to work, it must receive the bot token. This is the token you receive at Telegram bot creation (via BotFather bot). Save the token inside web\sites\default\settings.php, towards the bottom:

```
$settings['tgbot_token'] = '...'; // your token here
```
This token must be the same as the one inside "wisetrout-do-bot\.env".

#### Cron setup
Run cron regularly (once a day) to send out daily summaries to Telegram subscribers.