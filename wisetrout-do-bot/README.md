# Telegram bot

The DO Telegram bot allows users to subscribe to AI Dashboard issue updates. They then receive notifications directly in the chat bot. There are two types of subscriptions: getting updates instantly on issue creation/update or receiving summaries once a day. This directory contains the code for the behavior of the Telegram bot, written in Node.js. It contains all of the commands and menus that the user sees once they interact with the bot in Telegram.

## Usage
To make the bot work, several steps must be done:

### Create bot user
Create a new user with the telegram_bot role.

### Set up OAuth for the bot user
The bot uses OAuth to access the api and save user preferences. To set up Oauth, go to /admin/config/people/simple_oauth and set up the keys. After that you will need to create a consumer dedicated to the Telegram bot. Once this is done, you can fill up the DRUPAL_AUTH_TOKEN_CLIENT_ID, DRUPAL_AUTH_TOKEN_CLIENT_SECRET, DRUPAL_AUTH_TOKEN_SCOPE environment variables inside .env.

### Create bot in Telegram
If you do not have one already, you must create a bot token via the BotFather bot in Telegram. Save the bot token as BOT_TOKEN variable in .env.

### Restart project
The newly created environment variables will be passed to the bot and the bot will start working.
